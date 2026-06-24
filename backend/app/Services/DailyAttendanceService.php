<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\UserStatus;
use App\Models\Attendance;
use App\Models\AttendanceDay;
use App\Models\AttendanceDevice;
use App\Models\AttendanceException;
use App\Models\AttendanceWeeklySchedule;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DailyAttendanceService
{
    public function forDate(CarbonInterface|string|null $value = null): AttendanceDay
    {
        $date = $value instanceof CarbonInterface
            ? CarbonImmutable::instance($value)->setTimezone(config('app.timezone'))->startOfDay()
            : CarbonImmutable::parse($value ?? 'today', config('app.timezone'))->startOfDay();
        $dateString = $date->toDateString();

        $day = AttendanceDay::query()->whereDate('attendance_date', $dateString)->first();
        if (! $day) {
            $day = Cache::lock("attendance-day:{$dateString}", 5)->block(3, function () use ($date, $dateString): AttendanceDay {
                return AttendanceDay::query()->whereDate('attendance_date', $dateString)->first()
                    ?? AttendanceDay::query()->create($this->attributesFor($date));
            });
        }

        $this->syncMembers($day);
        app(AttendanceRequestService::class)->applyApprovedForDay($day);
        $this->refreshStatuses($day);

        return $day->fresh();
    }

    public function phaseAt(AttendanceDay $day, ?CarbonInterface $value = null): ?string
    {
        if (! $day->is_working_day) {
            return null;
        }

        $now = $value ? CarbonImmutable::instance($value) : CarbonImmutable::now();
        if ($day->check_in_opens_at && $now->betweenIncluded($day->check_in_opens_at, $day->check_in_closes_at)) {
            return 'check_in';
        }
        if ($day->check_out_opens_at && $now->betweenIncluded($day->check_out_opens_at, $day->check_out_closes_at)) {
            return 'check_out';
        }

        return null;
    }

    public function nextWorkingDay(CarbonInterface|string|null $from = null): ?AttendanceDay
    {
        $date = $from instanceof CarbonInterface
            ? CarbonImmutable::instance($from)->setTimezone(config('app.timezone'))->startOfDay()
            : CarbonImmutable::parse($from ?? 'today', config('app.timezone'))->startOfDay();

        for ($offset = 0; $offset <= 31; $offset++) {
            $day = $this->forDate($date->addDays($offset));
            if ($day->is_working_day && $day->check_out_closes_at?->isFuture()) {
                return $day;
            }
        }

        return null;
    }

    public function refreshStatuses(?AttendanceDay $day = null): void
    {
        $days = $day
            ? collect([$day])
            : AttendanceDay::query()->where('attendance_date', '<=', today(config('app.timezone')))->get();

        foreach ($days as $attendanceDay) {
            if (! $attendanceDay->is_working_day) {
                $attendanceDay->update(['status' => 'holiday']);

                continue;
            }

            if ($attendanceDay->check_in_closes_at?->isPast()) {
                Attendance::query()
                    ->where('attendance_day_id', $attendanceDay->id)
                    ->where('status', AttendanceStatus::Pending->value)
                    ->update(['status' => AttendanceStatus::Absent->value, 'updated_at' => now()]);
            }

            $status = $attendanceDay->check_out_closes_at?->isPast()
                ? 'closed'
                : ($attendanceDay->check_in_opens_at?->isPast() ? 'open' : 'scheduled');
            if ($attendanceDay->status !== $status) {
                $attendanceDay->update(['status' => $status]);
            }
        }
    }

    public function ensureAttendance(Member|int $member, AttendanceDay $day, bool $lock = false): Attendance
    {
        $memberId = $member instanceof Member ? $member->id : $member;
        $query = fn () => Attendance::query()
            ->where('member_id', $memberId)
            ->where('attendance_day_id', $day->id)
            ->when($lock, fn ($builder) => $builder->lockForUpdate());

        $attendance = $query()->first();
        if ($attendance) {
            return $attendance;
        }

        DB::table('attendances')->insertOrIgnore([
            'public_id' => (string) str()->ulid(),
            'member_id' => $memberId,
            'attendance_day_id' => $day->id,
            'active_key' => 1,
            'status' => AttendanceStatus::Pending->value,
            'source' => 'system',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $query()->firstOrFail();
    }

    public function clearFutureSnapshots(): void
    {
        AttendanceDay::query()
            ->where('attendance_date', '>', today(config('app.timezone')))
            ->whereDoesntHave('attendances', fn ($query) => $query->where('status', '!=', AttendanceStatus::Pending->value))
            ->delete();
    }

    public function syncTodayFromWeeklySchedule(int $weekday): ?AttendanceDay
    {
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        if ($today->dayOfWeekIso !== $weekday) {
            return null;
        }

        if (AttendanceException::query()->whereDate('attendance_date', $today->toDateString())->exists()) {
            return null;
        }

        return $this->syncDateIfToday($today);
    }

    public function syncDateIfToday(CarbonInterface|string $value): ?AttendanceDay
    {
        $date = $value instanceof CarbonInterface
            ? CarbonImmutable::instance($value)->setTimezone(config('app.timezone'))->startOfDay()
            : CarbonImmutable::parse($value, config('app.timezone'))->startOfDay();
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        if (! $date->isSameDay($today)) {
            return null;
        }

        $attributes = $this->attributesFor($today);
        $day = AttendanceDay::query()->whereDate('attendance_date', $today->toDateString())->first();
        if ($day) {
            $day->forceFill($attributes)->save();
        } else {
            $day = AttendanceDay::query()->create($attributes);
        }

        $this->syncMembers($day);
        app(AttendanceRequestService::class)->applyApprovedForDay($day);
        $this->refreshStatuses($day);

        return $day->fresh();
    }

    public function deviceAllowedForDay(AttendanceDevice $device, AttendanceDay $day): bool
    {
        if ($day->source !== 'exception') {
            return true;
        }

        $snapshot = $day->schedule_snapshot ?? [];
        if (($snapshot['device_scope'] ?? 'all') !== 'selected') {
            return true;
        }

        $allowedIds = array_map('intval', $snapshot['attendance_device_ids'] ?? []);

        return in_array($device->id, $allowedIds, true);
    }

    private function syncMembers(AttendanceDay $day): void
    {
        if (! $day->is_working_day) {
            return;
        }

        $memberIds = Member::query()
            ->whereHas('user', fn ($query) => $query->where('status', UserStatus::Active->value))
            ->pluck('id');
        $existing = Attendance::withTrashed()
            ->where('attendance_day_id', $day->id)
            ->whereIn('member_id', $memberIds)
            ->pluck('member_id');
        $status = $day->check_in_closes_at?->isPast()
            ? AttendanceStatus::Absent->value
            : AttendanceStatus::Pending->value;
        $now = now();
        $rows = $memberIds->diff($existing)->map(fn (int $memberId) => [
            'public_id' => (string) str()->ulid(),
            'member_id' => $memberId,
            'attendance_day_id' => $day->id,
            'active_key' => 1,
            'status' => $status,
            'source' => 'system',
            'created_at' => $now,
            'updated_at' => $now,
        ])->values()->all();

        if ($rows !== []) {
            DB::table('attendances')->insertOrIgnore($rows);
        }
    }

    private function attributesFor(CarbonImmutable $date): array
    {
        $exception = AttendanceException::query()->with('devices')->whereDate('attendance_date', $date->toDateString())->first();
        $rule = $exception ?? AttendanceWeeklySchedule::query()->where('weekday', $date->dayOfWeekIso)->first();
        $working = (bool) ($rule?->is_working_day ?? false);
        $exceptionDevices = $exception?->devices ?? collect();
        $snapshot = [
            'source_id' => $rule?->public_id,
            'weekday' => $date->dayOfWeekIso,
            'check_in_time' => $rule?->check_in_time,
            'check_in_before_minutes' => (int) ($rule?->check_in_before_minutes ?? 30),
            'check_in_after_minutes' => (int) ($rule?->check_in_after_minutes ?? 30),
            'check_out_time' => $rule?->check_out_time,
            'check_out_before_minutes' => (int) ($rule?->check_out_before_minutes ?? 30),
            'check_out_after_minutes' => (int) ($rule?->check_out_after_minutes ?? 30),
            'device_scope' => $exception && $working && $exceptionDevices->isNotEmpty() ? 'selected' : 'all',
            'attendance_device_ids' => $exception && $working
                ? $exceptionDevices->pluck('id')->map(fn ($id) => (int) $id)->values()->all()
                : [],
            'attendance_device_public_ids' => $exception && $working
                ? $exceptionDevices->pluck('public_id')->values()->all()
                : [],
        ];

        $attributes = [
            'attendance_date' => $date->toDateString(),
            'is_working_day' => $working,
            'source' => $exception ? 'exception' : 'weekly',
            'status' => $working ? 'scheduled' : 'holiday',
            'note' => $exception?->note,
            'schedule_snapshot' => $snapshot,
            'check_in_target_at' => null,
            'check_in_opens_at' => null,
            'check_in_closes_at' => null,
            'check_out_target_at' => null,
            'check_out_opens_at' => null,
            'check_out_closes_at' => null,
        ];

        if (! $working) {
            return $attributes;
        }

        $checkIn = $this->atTime($date, (string) $rule->check_in_time);
        $checkOut = $this->atTime($date, (string) $rule->check_out_time);

        return [
            ...$attributes,
            'check_in_target_at' => $checkIn,
            'check_in_opens_at' => $checkIn->subMinutes($snapshot['check_in_before_minutes']),
            'check_in_closes_at' => $checkIn->addMinutes($snapshot['check_in_after_minutes']),
            'check_out_target_at' => $checkOut,
            'check_out_opens_at' => $checkOut->subMinutes($snapshot['check_out_before_minutes']),
            'check_out_closes_at' => $checkOut->addMinutes($snapshot['check_out_after_minutes']),
        ];
    }

    private function atTime(CarbonImmutable $date, string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($date->toDateString().' '.$time, config('app.timezone'));
    }
}
