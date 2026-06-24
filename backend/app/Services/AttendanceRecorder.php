<?php

namespace App\Services;

use App\Enums\AttendanceDeviceStatus;
use App\Enums\AttendancePhase;
use App\Enums\AttendanceStatus;
use App\Enums\CheckInStatus;
use App\Enums\CheckOutStatus;
use App\Events\AttendanceRecorded;
use App\Models\AttendanceDay;
use App\Models\AttendanceDevice;
use App\Models\AttendanceScan;
use App\Models\Member;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceRecorder
{
    public function __construct(
        private readonly QrTokenService $tokens,
        private readonly DailyAttendanceService $days,
    ) {}

    public function record(Member $member, string $token, Request $request): array
    {
        $tokenHash = hash('sha256', $token);
        $metadata = $this->tokens->resolve($token);

        if (! is_array($metadata)) {
            $this->reject($member, $tokenHash, $request, 'invalid_or_expired');
            throw ValidationException::withMessages(['token' => 'QR tidak valid atau sudah kedaluwarsa.']);
        }

        if (($metadata['expires_at_timestamp'] + QrTokenService::GRACE_SECONDS) < now()->timestamp) {
            $this->reject($member, $tokenHash, $request, 'expired', $metadata);
            throw ValidationException::withMessages(['token' => 'QR sudah kedaluwarsa. Pindai kode terbaru.']);
        }

        $day = AttendanceDay::query()->find($metadata['attendance_day_id']);
        $device = AttendanceDevice::query()->find($metadata['device_id']);
        $phase = $metadata['phase'] ?? null;

        if (! $day || $this->days->phaseAt($day) !== $phase) {
            $this->reject($member, $tokenHash, $request, 'outside_attendance_window', $metadata);
            throw ValidationException::withMessages(['token' => 'Waktu absensi untuk QR ini sudah berakhir.']);
        }

        if (! $device || $device->status !== AttendanceDeviceStatus::Active) {
            $this->reject($member, $tokenHash, $request, 'device_revoked', $metadata);
            throw ValidationException::withMessages(['token' => 'Gawai sudah tidak aktif.']);
        }

        if (! $this->days->deviceAllowedForDay($device, $day)) {
            $this->reject($member, $tokenHash, $request, 'device_not_allowed_for_day', $metadata);
            throw ValidationException::withMessages(['token' => 'Gawai ini tidak diizinkan untuk jadwal khusus hari ini.']);
        }

        $rejectedCheckout = false;
        $rejectedApprovedRequest = false;

        try {
            return DB::transaction(function () use ($member, $day, $device, $phase, $tokenHash, $request, &$rejectedCheckout, &$rejectedApprovedRequest): array {
                $attendance = $this->days->ensureAttendance($member, $day, true);

                if (in_array($attendance->status, [AttendanceStatus::Permission, AttendanceStatus::Leave, AttendanceStatus::Sick, AttendanceStatus::OfficialDuty], true)) {
                    $rejectedApprovedRequest = true;
                    throw ValidationException::withMessages(['token' => 'Tanggal ini sudah memiliki permohonan ketidakhadiran yang disetujui.']);
                }

                $isCheckIn = $phase === AttendancePhase::CheckIn->value;
                $column = $isCheckIn ? 'check_in_at' : 'check_out_at';
                $statusColumn = $isCheckIn ? 'check_in_status' : 'check_out_status';
                $deviceColumn = $isCheckIn ? 'check_in_device_id' : 'check_out_device_id';

                if (! $isCheckIn && ! $attendance->check_in_at) {
                    $rejectedCheckout = true;
                    throw ValidationException::withMessages(['token' => 'Check-in harus tercatat sebelum check-out.']);
                }

                $alreadyRecorded = $attendance->{$column} !== null;
                if (! $alreadyRecorded) {
                    $timing = $isCheckIn
                        ? (now()->lte($day->check_in_target_at) ? CheckInStatus::OnTime : CheckInStatus::Late)
                        : (now()->lt($day->check_out_target_at) ? CheckOutStatus::Early : CheckOutStatus::OnTime);
                    $attendance->forceFill([
                        'status' => AttendanceStatus::Present,
                        $column => now(),
                        $statusColumn => $timing,
                        $deviceColumn => $device->id,
                        'source' => $attendance->source === 'manual' ? 'mixed' : 'qr',
                    ])->save();
                }

                $this->createScan($member, $day, $device, $phase, $tokenHash, $request, true, $alreadyRecorded ? 'already_recorded' : 'recorded');
                $attendance->load('member.user', 'day');

                if (! $alreadyRecorded) {
                    broadcast(new AttendanceRecorded($attendance, $phase));
                }

                return [
                    'attendance' => $attendance,
                    'phase' => $phase,
                    'recorded_at' => $attendance->{$column}->toIso8601String(),
                    'already_recorded' => $alreadyRecorded,
                ];
            });
        } catch (ValidationException $exception) {
            if ($rejectedCheckout) {
                $this->createScan($member, $day, $device, $phase, $tokenHash, $request, false, 'check_in_required');
            }
            if ($rejectedApprovedRequest) {
                $this->createScan($member, $day, $device, $phase, $tokenHash, $request, false, 'approved_request_exists');
            }

            throw $exception;
        }
    }

    private function reject(Member $member, string $tokenHash, Request $request, string $reason, array $metadata = []): void
    {
        AttendanceScan::query()->create([
            'member_id' => $member->id,
            'attendance_day_id' => $metadata['attendance_day_id'] ?? null,
            'attendance_device_id' => $metadata['device_id'] ?? null,
            'phase' => $metadata['phase'] ?? null,
            'token_hash' => $tokenHash,
            'accepted' => false,
            'reason' => $reason,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'scanned_at' => now(),
        ]);
    }

    private function createScan(Member $member, AttendanceDay $day, AttendanceDevice $device, string $phase, string $tokenHash, Request $request, bool $accepted, string $reason): void
    {
        AttendanceScan::query()->create([
            'member_id' => $member->id,
            'attendance_day_id' => $day->id,
            'attendance_device_id' => $device->id,
            'phase' => $phase,
            'token_hash' => $tokenHash,
            'accepted' => $accepted,
            'reason' => $reason,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'scanned_at' => now(),
        ]);
    }
}
