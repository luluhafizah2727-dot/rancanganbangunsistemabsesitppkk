<?php

namespace App\Http\Controllers\Api;

use App\Enums\AttendanceStatus;
use App\Enums\CheckInStatus;
use App\Enums\CheckOutStatus;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Member;
use App\Services\AttendanceRecorder;
use App\Services\AuditLogger;
use App\Services\DailyAttendanceService;
use App\Services\MemberDeviceBindingService;
use App\Support\ApiResponse;
use App\Support\Present;
use App\Support\Search;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    public function scan(Request $request, AttendanceRecorder $recorder, MemberDeviceBindingService $memberDevices): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'min:32', 'max:100']]);
        abort_unless($request->user()->member, 403, 'Hanya anggota yang dapat mencatat kehadiran.');
        $deviceAccess = $memberDevices->authorizeScan($request, $request->user()->member);
        if (! $deviceAccess['allowed']) {
            return ApiResponse::error($deviceAccess['message'], $deviceAccess['code'], 403);
        }

        $result = $recorder->record($request->user()->member, $data['token'], $request);

        $response = ApiResponse::success([
            'attendance' => Present::attendance($result['attendance']),
            'phase' => $result['phase'],
            'recorded_at' => $result['recorded_at'],
            'already_recorded' => $result['already_recorded'],
            'message' => $result['already_recorded']
                ? 'Kehadiran sudah tercatat sebelumnya.'
                : ($result['phase'] === 'check_in' ? 'Check-in berhasil.' : 'Check-out berhasil.'),
        ]);

        return $deviceAccess['cookie'] ? $response->withCookie($deviceAccess['cookie']) : $response;
    }

    public function history(Request $request, DailyAttendanceService $days): JsonResponse
    {
        abort_unless($request->user()->member, 403, 'Profil anggota tidak ditemukan.');
        $days->forDate();
        $attendances = Attendance::query()
            ->with('day', 'member.user', 'checkInDevice', 'checkOutDevice')
            ->where('member_id', $request->user()->member->id)
            ->whereHas('day', fn ($query) => $query->where('attendance_date', '<=', today(config('app.timezone'))))
            ->latest('attendance_day_id')
            ->paginate(min($request->integer('per_page', 20), 100));

        return ApiResponse::success(
            collect($attendances->items())->map(fn (Attendance $attendance) => Present::attendance($attendance))->values(),
            ['current_page' => $attendances->currentPage(), 'last_page' => $attendances->lastPage(), 'total' => $attendances->total()],
        );
    }

    public function today(Request $request, DailyAttendanceService $days): JsonResponse
    {
        abort_unless($request->user()->member, 403, 'Profil anggota tidak ditemukan.');
        $day = $days->forDate();
        $attendance = Attendance::query()
            ->with('day', 'member.user')
            ->where('attendance_day_id', $day->id)
            ->where('member_id', $request->user()->member->id)
            ->first();

        return ApiResponse::success([
            'day' => Present::day($day),
            'current_phase' => $days->phaseAt($day),
            'attendance' => $attendance ? Present::attendance($attendance) : null,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function index(Request $request, DailyAttendanceService $days): JsonResponse
    {
        $day = $days->forDate($request->string('date')->toString() ?: null);
        $attendances = Attendance::query()
            ->with('day', 'member.user', 'checkInDevice', 'checkOutDevice')
            ->where('attendance_day_id', $day->id)
            ->when($request->string('status')->toString(), fn ($query, string $status) => $query->where('status', $status))
            ->when($request->string('search')->toString(), function ($query, string $search): void {
                $query->whereHas('member', function ($member) use ($search): void {
                    Search::contains($member, 'member_number', $search)
                        ->orWhereHas('user', fn ($user) => Search::contains($user, 'name', $search));
                });
            })
            ->get()
            ->sortBy(fn (Attendance $attendance) => $attendance->member->user?->name)
            ->values();

        return ApiResponse::success([
            'day' => Present::day($day),
            'current_phase' => $days->phaseAt($day),
            'attendances' => $attendances->map(fn (Attendance $attendance) => Present::attendance($attendance))->values(),
        ]);
    }

    public function store(Request $request, DailyAttendanceService $days, AuditLogger $audit): JsonResponse
    {
        $data = $this->validateManual($request);
        $member = Member::query()->where('public_id', $data['member_id'])->firstOrFail();
        $day = $days->forDate($data['attendance_date']);

        return DB::transaction(function () use ($days, $member, $day, $data, $request, $audit): JsonResponse {
            $hadAttendance = Attendance::query()
                ->where('member_id', $member->id)
                ->where('attendance_day_id', $day->id)
                ->exists();
            $attendance = $days->ensureAttendance($member, $day, true);
            $before = $hadAttendance ? Present::attendance($attendance) : null;
            $this->fillManual($attendance, $day, $data, $request->user()->id);
            $audit->log('attendance.manual_saved', $attendance, [
                'reason' => $data['reason'],
                'before' => $before,
                'after' => Present::attendance($attendance->fresh()),
            ]);

            return ApiResponse::success(Present::attendance($attendance->fresh()), status: $before ? 200 : 201);
        });
    }

    public function update(Request $request, Attendance $attendance, AuditLogger $audit): JsonResponse
    {
        $data = $this->validateManual($request, false);
        $before = Present::attendance($attendance);
        $this->fillManual($attendance, $attendance->day, $data, $request->user()->id);
        $audit->log('attendance.manual_updated', $attendance, [
            'reason' => $data['reason'],
            'before' => $before,
            'after' => Present::attendance($attendance->fresh()),
        ]);

        return ApiResponse::success(Present::attendance($attendance->fresh()));
    }

    public function destroy(Request $request, Attendance $attendance, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $before = Present::attendance($attendance);
        $status = $attendance->day->check_in_closes_at?->isPast()
            ? AttendanceStatus::Absent
            : AttendanceStatus::Pending;
        $attendance->forceFill([
            'status' => $status,
            'check_in_at' => null,
            'check_in_status' => null,
            'check_in_device_id' => null,
            'check_out_at' => null,
            'check_out_status' => null,
            'check_out_device_id' => null,
            'source' => 'manual',
            'note' => null,
            'adjustment_reason' => $data['reason'],
            'updated_by' => $request->user()->id,
        ])->save();
        $audit->log('attendance.manual_reset', $attendance, [
            'reason' => $data['reason'],
            'before' => $before,
            'after' => Present::attendance($attendance->fresh()),
        ]);

        return ApiResponse::success(Present::attendance($attendance->fresh()));
    }

    private function validateManual(Request $request, bool $includeIdentity = true): array
    {
        $rules = [
            'status' => ['required', Rule::in(['present', 'permission', 'leave', 'sick', 'official_duty', 'absent'])],
            'check_in_at' => ['nullable', 'date'],
            'check_out_at' => ['nullable', 'date', 'after_or_equal:check_in_at'],
            'note' => ['nullable', 'string', 'max:1000'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
        if ($includeIdentity) {
            $rules = [
                'member_id' => ['required', 'string', 'exists:members,public_id'],
                'attendance_date' => ['required', 'date_format:Y-m-d'],
                ...$rules,
            ];
        }
        $data = $request->validate($rules);

        if ($data['status'] === 'present' && empty($data['check_in_at'])) {
            throw ValidationException::withMessages(['check_in_at' => 'Waktu check-in wajib diisi untuk status hadir.']);
        }

        return $data;
    }

    private function fillManual(Attendance $attendance, $day, array $data, int $actorId): void
    {
        $checkIn = ! empty($data['check_in_at']) ? CarbonImmutable::parse($data['check_in_at'])->setTimezone(config('app.timezone')) : null;
        $checkOut = ! empty($data['check_out_at']) ? CarbonImmutable::parse($data['check_out_at'])->setTimezone(config('app.timezone')) : null;
        foreach ([$checkIn, $checkOut] as $time) {
            if ($time && $time->setTimezone(config('app.timezone'))->toDateString() !== $day->attendance_date->format('Y-m-d')) {
                throw ValidationException::withMessages(['check_in_at' => 'Waktu harus berada pada tanggal absensi yang dipilih.']);
            }
        }

        $attendance->forceFill([
            'status' => $data['status'],
            'check_in_at' => $data['status'] === 'present' ? $checkIn : null,
            'check_in_status' => $checkIn ? ($checkIn->lte($day->check_in_target_at) ? CheckInStatus::OnTime : CheckInStatus::Late) : null,
            'check_in_device_id' => null,
            'check_out_at' => $data['status'] === 'present' ? $checkOut : null,
            'check_out_status' => $checkOut ? ($checkOut->lt($day->check_out_target_at) ? CheckOutStatus::Early : CheckOutStatus::OnTime) : null,
            'check_out_device_id' => null,
            'source' => 'manual',
            'note' => $data['note'] ?? null,
            'adjustment_reason' => $data['reason'],
            'created_by' => $attendance->exists ? $attendance->created_by : $actorId,
            'updated_by' => $actorId,
        ])->save();
    }
}
