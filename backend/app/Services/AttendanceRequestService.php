<?php

namespace App\Services;

use App\Enums\AttendanceRequestStatus;
use App\Enums\AttendanceRequestType;
use App\Enums\AttendanceStatus;
use App\Enums\CheckInStatus;
use App\Enums\CheckOutStatus;
use App\Models\Attendance;
use App\Models\AttendanceDay;
use App\Models\AttendanceRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class AttendanceRequestService
{
    public function __construct(private readonly DailyAttendanceService $days) {}

    public function approve(AttendanceRequest $request, array $review, int $reviewerId): AttendanceRequest
    {
        return DB::transaction(function () use ($request, $review, $reviewerId): AttendanceRequest {
            $request = AttendanceRequest::query()->lockForUpdate()->findOrFail($request->id);
            abort_unless($request->status === AttendanceRequestStatus::Pending, 409, 'Permohonan ini sudah diproses.');
            $this->guardApprovedOverlap($request);

            $request->forceFill([
                'status' => AttendanceRequestStatus::Approved,
                'reviewed_by' => $reviewerId,
                'review_note' => $review['review_note'] ?? null,
                'approved_check_in_at' => $review['approved_check_in_at'] ?? $request->proposed_check_in_at,
                'approved_check_out_at' => $review['approved_check_out_at'] ?? $request->proposed_check_out_at,
                'reviewed_at' => now(),
            ])->save();

            $this->applyApprovedRequest($request);

            return $request->fresh(['member.user', 'reviewer']);
        });
    }

    public function reject(AttendanceRequest $request, array $review, int $reviewerId): AttendanceRequest
    {
        return DB::transaction(function () use ($request, $review, $reviewerId): AttendanceRequest {
            $request = AttendanceRequest::query()->lockForUpdate()->findOrFail($request->id);
            abort_unless($request->status === AttendanceRequestStatus::Pending, 409, 'Permohonan ini sudah diproses.');

            $request->forceFill([
                'status' => AttendanceRequestStatus::Rejected,
                'reviewed_by' => $reviewerId,
                'review_note' => $review['review_note'] ?? null,
                'reviewed_at' => now(),
            ])->save();

            return $request->fresh(['member.user', 'reviewer']);
        });
    }

    public function applyApprovedForDay(AttendanceDay $day): void
    {
        if (! $day->is_working_day) {
            return;
        }

        AttendanceRequest::query()
            ->where('status', AttendanceRequestStatus::Approved->value)
            ->where('date_from', '<=', $day->attendance_date)
            ->where('date_to', '>=', $day->attendance_date)
            ->whereNotIn('type', [
                AttendanceRequestType::MissedCheckIn->value,
                AttendanceRequestType::MissedCheckOut->value,
                AttendanceRequestType::TimeCorrection->value,
            ])
            ->each(fn (AttendanceRequest $request) => $this->applyAbsenceDay($request, $day));
    }

    private function applyApprovedRequest(AttendanceRequest $request): void
    {
        if ($request->type->isTimeCorrection()) {
            $this->applyTimeCorrection($request);

            return;
        }

        $today = CarbonImmutable::today(config('app.timezone'));
        for ($date = CarbonImmutable::parse($request->date_from, config('app.timezone')); $date->lte($request->date_to) && $date->lte($today); $date = $date->addDay()) {
            $day = $this->days->forDate($date);
            if ($day->is_working_day) {
                $this->applyAbsenceDay($request, $day);
            }
        }
    }

    private function applyTimeCorrection(AttendanceRequest $request): void
    {
        $day = $this->days->forDate($request->date_from);
        abort_unless($day->is_working_day, 422, 'Koreksi waktu hanya dapat diterapkan pada hari kerja.');
        $attendance = Attendance::query()
            ->where('member_id', $request->member_id)
            ->where('attendance_day_id', $day->id)
            ->lockForUpdate()
            ->firstOrFail();
        if ($attendance->attendance_request_id && $attendance->attendance_request_id !== $request->id) {
            throw new ConflictHttpException('Kehadiran pada tanggal ini sudah berasal dari permohonan lain.');
        }
        if (in_array($attendance->status, [AttendanceStatus::Permission, AttendanceStatus::Leave, AttendanceStatus::Sick, AttendanceStatus::OfficialDuty], true)) {
            throw new ConflictHttpException('Tanggal ini sudah memiliki status ketidakhadiran yang disetujui.');
        }

        $checkIn = $request->approved_check_in_at;
        $checkOut = $request->approved_check_out_at;
        if ($request->type === AttendanceRequestType::MissedCheckIn && $attendance->check_in_at) {
            throw new ConflictHttpException('Check-in pada tanggal ini sudah tercatat.');
        }
        if ($request->type === AttendanceRequestType::MissedCheckOut && $attendance->check_out_at) {
            throw new ConflictHttpException('Check-out pada tanggal ini sudah tercatat.');
        }

        $attendance->forceFill([
            'attendance_request_id' => $request->id,
            'status' => AttendanceStatus::Present,
            'check_in_at' => $checkIn ?? $attendance->check_in_at,
            'check_in_status' => $checkIn
                ? ($checkIn->lte($day->check_in_target_at) ? CheckInStatus::OnTime : CheckInStatus::Late)
                : $attendance->check_in_status,
            'check_out_at' => $checkOut ?? $attendance->check_out_at,
            'check_out_status' => $checkOut
                ? ($checkOut->lt($day->check_out_target_at) ? CheckOutStatus::Early : CheckOutStatus::OnTime)
                : $attendance->check_out_status,
            'source' => 'approved_request',
            'note' => $request->reason,
            'adjustment_reason' => 'Permohonan anggota disetujui',
            'updated_by' => $request->reviewed_by,
        ])->save();
    }

    private function applyAbsenceDay(AttendanceRequest $request, AttendanceDay $day): void
    {
        $attendance = $this->days->ensureAttendance($request->member_id, $day, true);
        if ($attendance->attendance_request_id && $attendance->attendance_request_id !== $request->id) {
            throw new ConflictHttpException('Terdapat kehadiran atau permohonan lain pada '.$day->attendance_date->format('d/m/Y').'.');
        }

        if ($attendance->attendance_request_id === $request->id
            && $request->type->supportsPartialAbsence()
            && $attendance->check_in_at
            && $attendance->check_out_at) {
            return;
        }

        if ($attendance->status === AttendanceStatus::Present) {
            $this->applyPartialAbsenceDay($request, $day, $attendance);

            return;
        }

        $attendance->forceFill([
            'attendance_request_id' => $request->id,
            'status' => $request->type->attendanceStatus(),
            'check_in_at' => null,
            'check_in_status' => null,
            'check_in_device_id' => null,
            'check_out_at' => null,
            'check_out_status' => null,
            'check_out_device_id' => null,
            'source' => 'approved_request',
            'note' => $request->type === AttendanceRequestType::Other
                ? trim(($request->other_label ?: 'Lainnya').': '.$request->reason)
                : $request->reason,
            'adjustment_reason' => 'Permohonan anggota disetujui',
            'updated_by' => $request->reviewed_by,
        ])->save();
    }

    private function applyPartialAbsenceDay(AttendanceRequest $request, AttendanceDay $day, Attendance $attendance): void
    {
        if (! $request->type->supportsPartialAbsence()) {
            throw new ConflictHttpException('Terdapat kehadiran pada '.$day->attendance_date->format('d/m/Y').'.');
        }

        if (! $attendance->check_in_at) {
            throw new ConflictHttpException('Check-in belum tercatat untuk menerapkan izin/sakit/dinas sebagian hari.');
        }

        if ($attendance->check_out_at) {
            throw new ConflictHttpException('Check-out pada tanggal ini sudah tercatat.');
        }

        $partialStart = $request->approved_check_out_at;
        if (! $partialStart) {
            throw ValidationException::withMessages([
                'approved_check_out_at' => 'Waktu mulai izin/sakit/dinas wajib diisi karena anggota sudah check-in.',
            ]);
        }

        $partialStart = CarbonImmutable::instance($partialStart)->setTimezone(config('app.timezone'));
        $attendanceDate = $day->attendance_date->format('Y-m-d');
        if ($partialStart->toDateString() !== $attendanceDate) {
            throw ValidationException::withMessages([
                'approved_check_out_at' => 'Waktu mulai izin/sakit/dinas harus berada pada tanggal absensi.',
            ]);
        }

        if ($partialStart->lt(CarbonImmutable::instance($attendance->check_in_at)->setTimezone(config('app.timezone')))) {
            throw ValidationException::withMessages([
                'approved_check_out_at' => 'Waktu mulai izin/sakit/dinas tidak boleh sebelum check-in.',
            ]);
        }

        $attendance->forceFill([
            'attendance_request_id' => $request->id,
            'status' => $request->type->attendanceStatus(),
            'check_out_at' => $partialStart,
            'check_out_status' => $partialStart->lt($day->check_out_target_at) ? CheckOutStatus::Early : CheckOutStatus::OnTime,
            'check_out_device_id' => null,
            'source' => 'mixed',
            'note' => $request->reason,
            'adjustment_reason' => 'Permohonan anggota disetujui setelah check-in',
            'updated_by' => $request->reviewed_by,
        ])->save();
    }

    private function guardApprovedOverlap(AttendanceRequest $request): void
    {
        $overlap = AttendanceRequest::query()
            ->where('member_id', $request->member_id)
            ->where('id', '!=', $request->id)
            ->where('status', AttendanceRequestStatus::Approved->value)
            ->whereDate('date_from', '<=', $request->date_to)
            ->whereDate('date_to', '>=', $request->date_from)
            ->exists();
        if ($overlap) {
            throw new ConflictHttpException('Rentang tanggal sudah memiliki permohonan yang disetujui.');
        }
    }
}
