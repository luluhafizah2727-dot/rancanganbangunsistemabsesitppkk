<?php

namespace App\Services;

use App\Enums\AttendanceDeviceStatus;
use App\Enums\AttendanceStatus;
use App\Events\QrRotated;
use App\Models\Attendance;
use App\Models\AttendanceDay;
use App\Models\AttendanceDevice;
use App\Support\Present;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class QrTokenService
{
    public const LIFETIME_SECONDS = 10;

    public const GRACE_SECONDS = 2;

    public function __construct(private readonly DailyAttendanceService $days) {}

    public function rotateAll(): int
    {
        $count = 0;

        AttendanceDevice::query()
            ->where('status', AttendanceDeviceStatus::Active->value)
            ->chunkById(100, function ($devices) use (&$count): void {
                foreach ($devices as $device) {
                    if ($this->activeDayFor($device)) {
                        $count += $this->rotate($device) ? 1 : 0;
                    }
                }
            });

        return $count;
    }

    public function currentOrRotate(AttendanceDevice $device): ?array
    {
        $day = $this->activeDayFor($device);
        if (! $day) {
            Cache::forget($this->currentKey($device));

            return null;
        }

        $phase = $this->days->phaseAt($day);
        $current = Cache::get($this->currentKey($device));
        if (is_array($current)
            && ($current['expires_at_timestamp'] ?? 0) > now()->timestamp
            && ($current['phase'] ?? null) === $phase
            && ($current['attendance_day_id'] ?? null) === $day->id) {
            return $current;
        }

        return $this->rotate($device, true);
    }

    public function rotate(AttendanceDevice $device, bool $force = false): ?array
    {
        $day = $this->activeDayFor($device);
        $phase = $day ? $this->days->phaseAt($day) : null;
        if (! $day || ! $phase) {
            Cache::forget($this->currentKey($device));

            return null;
        }

        $slot = intdiv(now()->timestamp, self::LIFETIME_SECONDS);
        $lock = Cache::lock("qr:rotate:{$device->id}:{$slot}", self::LIFETIME_SECONDS - 1);

        return $lock->get(function () use ($device, $day, $phase, $force): ?array {
            if (! $force) {
                $current = Cache::get($this->currentKey($device));
                if (is_array($current)
                    && ($current['expires_at_timestamp'] ?? 0) > now()->timestamp
                    && ($current['phase'] ?? null) === $phase) {
                    return $current;
                }
            }

            $issuedAt = now();
            $expiresAt = $issuedAt->copy()->addSeconds(self::LIFETIME_SECONDS);
            $token = Str::password(43, letters: true, numbers: true, symbols: false, spaces: false);
            $hash = hash('sha256', $token);
            $metadata = [
                'token' => $token,
                'attendance_day_id' => $day->id,
                'attendance_day_public_id' => $day->public_id,
                'attendance_date' => $day->attendance_date->format('Y-m-d'),
                'day' => Present::day($day),
                'device_id' => $device->id,
                'device_public_id' => $device->public_id,
                'device' => [
                    'id' => $device->public_id,
                    'code' => $device->code,
                    'name' => $device->name,
                    'location' => $device->location,
                ],
                'phase' => $phase,
                'attendance_summary' => $this->summaryFor($day),
                'issued_at' => $issuedAt->toIso8601String(),
                'expires_at' => $expiresAt->toIso8601String(),
                'expires_at_timestamp' => $expiresAt->timestamp,
                'server_time' => $issuedAt->toIso8601String(),
            ];

            Cache::put("qr:token:{$hash}", collect($metadata)->except('token')->all(), self::LIFETIME_SECONDS + self::GRACE_SECONDS);
            Cache::put($this->currentKey($device), $metadata, self::LIFETIME_SECONDS + self::GRACE_SECONDS);
            broadcast(new QrRotated($device, $metadata));

            return $metadata;
        }) ?: Cache::get($this->currentKey($device));
    }

    public function resolve(string $token): ?array
    {
        return Cache::get('qr:token:'.hash('sha256', $token));
    }

    public function activeDayFor(AttendanceDevice $device): ?AttendanceDay
    {
        if ($device->status !== AttendanceDeviceStatus::Active) {
            return null;
        }

        $day = $this->days->forDate();
        if (! $this->days->deviceAllowedForDay($device, $day)) {
            Cache::forget($this->currentKey($device));

            return null;
        }

        return $this->days->phaseAt($day) ? $day : null;
    }

    public function dayForDisplay(): AttendanceDay
    {
        return $this->days->forDate();
    }

    public function summaryFor(AttendanceDay $day): array
    {
        $counts = Attendance::query()
            ->where('attendance_day_id', $day->id)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'expected' => $counts->sum(),
            'present' => (int) ($counts['present'] ?? 0),
            'permission' => (int) ($counts['permission'] ?? 0),
            'leave' => (int) ($counts['leave'] ?? 0),
            'sick' => (int) ($counts['sick'] ?? 0),
            'official_duty' => (int) ($counts['official_duty'] ?? 0),
            'absent' => (int) ($counts['absent'] ?? 0),
            'pending' => (int) ($counts['pending'] ?? 0),
            'partial_absence' => Attendance::query()
                ->where('attendance_day_id', $day->id)
                ->whereIn('status', [AttendanceStatus::Permission->value, AttendanceStatus::Sick->value, AttendanceStatus::OfficialDuty->value])
                ->whereNotNull('check_in_at')
                ->whereNotNull('check_out_at')
                ->count(),
            'checked_out' => Attendance::query()->where('attendance_day_id', $day->id)->whereNotNull('check_out_at')->count(),
        ];
    }

    private function currentKey(AttendanceDevice $device): string
    {
        return "qr:current:{$device->id}";
    }
}
