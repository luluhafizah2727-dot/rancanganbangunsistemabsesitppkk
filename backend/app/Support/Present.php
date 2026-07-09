<?php

namespace App\Support;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\AttendanceDay;
use App\Models\AttendanceDevice;
use App\Models\AttendanceException;
use App\Models\AttendanceRequest;
use App\Models\AttendanceRequestReviewer;
use App\Models\AttendanceWeeklySchedule;
use App\Models\Member;
use App\Models\MemberDevice;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

class Present
{
    public static function user(User $user): array
    {
        $user->loadMissing('member');
        $roles = $user->getRoleNames()->values();
        $isSuperAdmin = $roles->contains('super_admin');
        $isOperator = $roles->contains('operator');
        $canReviewAttendanceRequests = $isSuperAdmin
            || ($isOperator && AttendanceRequestReviewer::query()->where('user_id', $user->id)->exists());

        return [
            'id' => $user->public_id,
            'login_id' => $user->login_id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'avatar_url' => self::avatarUrl($user),
            'status' => $user->status->value,
            'roles' => $roles,
            'must_change_password' => $user->must_change_password,
            'receive_wa_notifications' => $user->receive_wa_notifications ?? $isSuperAdmin,
            'can_review_attendance_requests' => $canReviewAttendanceRequests,
            'member' => $user->member ? self::member($user->member, false) : null,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
        ];
    }

    public static function account(User $user): array
    {
        $user->loadMissing('member', 'roles');

        return [
            ...self::user($user),
            'registration_source' => $user->registration_source,
            'approved_at' => $user->approved_at?->toIso8601String(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    public static function member(Member $member, bool $includeUser = true): array
    {
        if ($includeUser) {
            $member->loadMissing('user');
        }

        return [
            'id' => $member->public_id,
            'member_number' => $member->member_number,
            'position' => $member->position,
            'department' => $member->department,
            'address' => $member->address,
            'user' => $includeUser ? [
                'id' => $member->user->public_id,
                'name' => $member->user->name,
                'email' => $member->user->email,
                'phone' => $member->user->phone,
                'avatar_url' => self::avatarUrl($member->user),
                'status' => $member->user->status->value,
                'must_change_password' => $member->user->must_change_password,
            ] : null,
        ];
    }

    public static function weeklySchedule(AttendanceWeeklySchedule $schedule): array
    {
        return [
            'id' => $schedule->public_id,
            'weekday' => $schedule->weekday,
            'is_working_day' => $schedule->is_working_day,
            'check_in_time' => $schedule->check_in_time,
            'check_in_before_minutes' => $schedule->check_in_before_minutes,
            'check_in_after_minutes' => $schedule->check_in_after_minutes,
            'check_out_time' => $schedule->check_out_time,
            'check_out_before_minutes' => $schedule->check_out_before_minutes,
            'check_out_after_minutes' => $schedule->check_out_after_minutes,
        ];
    }

    public static function exception(AttendanceException $exception): array
    {
        $exception->loadMissing('devices');

        return [
            'id' => $exception->public_id,
            'attendance_date' => $exception->attendance_date->format('Y-m-d'),
            'is_working_day' => $exception->is_working_day,
            'check_in_time' => $exception->check_in_time,
            'check_in_before_minutes' => $exception->check_in_before_minutes,
            'check_in_after_minutes' => $exception->check_in_after_minutes,
            'check_out_time' => $exception->check_out_time,
            'check_out_before_minutes' => $exception->check_out_before_minutes,
            'check_out_after_minutes' => $exception->check_out_after_minutes,
            'note' => $exception->note,
            'device_ids' => $exception->devices->pluck('public_id')->values()->all(),
            'devices' => $exception->devices
                ->sortBy('name')
                ->map(fn (AttendanceDevice $device) => self::device($device))
                ->values()
                ->all(),
        ];
    }

    public static function day(AttendanceDay $day): array
    {
        return [
            'id' => $day->public_id,
            'attendance_date' => $day->attendance_date->format('Y-m-d'),
            'is_working_day' => $day->is_working_day,
            'source' => $day->source,
            'status' => $day->status,
            'check_in_target_at' => $day->check_in_target_at?->toIso8601String(),
            'check_in_opens_at' => $day->check_in_opens_at?->toIso8601String(),
            'check_in_closes_at' => $day->check_in_closes_at?->toIso8601String(),
            'check_out_target_at' => $day->check_out_target_at?->toIso8601String(),
            'check_out_opens_at' => $day->check_out_opens_at?->toIso8601String(),
            'check_out_closes_at' => $day->check_out_closes_at?->toIso8601String(),
            'note' => $day->note,
        ];
    }

    public static function device(AttendanceDevice $device): array
    {
        return [
            'id' => $device->public_id,
            'code' => $device->code,
            'name' => $device->name,
            'location' => $device->location,
            'status' => $device->status->value,
            'ip_allowlist' => $device->ip_allowlist ?? [],
            'last_ip' => $device->last_ip,
            'timezone' => $device->timezone,
            'activated_at' => $device->activated_at?->toIso8601String(),
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            'revoked_at' => $device->revoked_at?->toIso8601String(),
            'credential_expires_at' => $device->credential_expires_at?->toIso8601String(),
        ];
    }

    public static function attendance(Attendance $attendance): array
    {
        $attendance->loadMissing('member.user', 'day', 'checkInDevice', 'checkOutDevice', 'request.reviewer');
        $presenceSummary = self::presenceSummary($attendance);

        return [
            'id' => $attendance->public_id,
            'member' => self::member($attendance->member),
            'day' => self::day($attendance->day),
            'status' => $attendance->status->value,
            'check_in_at' => $attendance->check_in_at?->toIso8601String(),
            'check_in_status' => $attendance->check_in_status?->value,
            'check_out_at' => $attendance->check_out_at?->toIso8601String(),
            'check_out_status' => $attendance->check_out_status?->value,
            'source' => $attendance->source,
            'note' => $attendance->note,
            'adjustment_reason' => $attendance->adjustment_reason,
            'check_in_device' => $attendance->checkInDevice?->name,
            'check_out_device' => $attendance->checkOutDevice?->name,
            'presence_summary' => $presenceSummary,
            'attendance_request' => $attendance->request ? self::attendanceRequestSummary($attendance->request) : null,
        ];
    }

    public static function attendanceRequest(AttendanceRequest $request): array
    {
        $request->loadMissing('member.user', 'reviewer');

        return [
            'id' => $request->public_id,
            'member' => self::member($request->member),
            'type' => $request->type->value,
            'date_from' => $request->date_from->format('Y-m-d'),
            'date_to' => $request->date_to->format('Y-m-d'),
            'proposed_check_in_at' => $request->proposed_check_in_at?->toIso8601String(),
            'proposed_check_out_at' => $request->proposed_check_out_at?->toIso8601String(),
            'approved_check_in_at' => $request->approved_check_in_at?->toIso8601String(),
            'approved_check_out_at' => $request->approved_check_out_at?->toIso8601String(),
            'other_label' => $request->other_label,
            'reason' => $request->reason,
            'has_attachment' => (bool) $request->attachment_path,
            'attachment_name' => $request->attachment_name,
            'attachment_size' => $request->attachment_size,
            'status' => $request->status->value,
            'review_note' => $request->review_note,
            'reviewer' => $request->reviewer ? [
                'id' => $request->reviewer->public_id,
                'name' => $request->reviewer->name,
            ] : null,
            'reviewed_at' => $request->reviewed_at?->toIso8601String(),
            'cancelled_at' => $request->cancelled_at?->toIso8601String(),
            'created_at' => $request->created_at?->toIso8601String(),
            'attendance_context' => self::attendanceRequestContext($request),
        ];
    }

    public static function memberDevice(MemberDevice $device): array
    {
        $device->loadMissing('member.user', 'reviewer');

        return [
            'id' => $device->public_id,
            'member' => self::member($device->member),
            'label' => $device->label,
            'status' => $device->status->value,
            'user_agent' => $device->user_agent,
            'ip_address' => $device->ip_address,
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            'reviewer' => $device->reviewer ? [
                'id' => $device->reviewer->public_id,
                'name' => $device->reviewer->name,
            ] : null,
            'reviewed_at' => $device->reviewed_at?->toIso8601String(),
            'review_note' => $device->review_note,
            'revoked_at' => $device->revoked_at?->toIso8601String(),
            'created_at' => $device->created_at?->toIso8601String(),
        ];
    }

    private static function avatarUrl(User $user): ?string
    {
        return $user->avatar_path
            ? Storage::disk('public')->url($user->avatar_path)
            : null;
    }

    private static function attendanceRequestContext(AttendanceRequest $request): ?array
    {
        if (! $request->date_from->isSameDay($request->date_to)) {
            return null;
        }

        $day = AttendanceDay::query()->whereDate('attendance_date', $request->date_from->format('Y-m-d'))->first();
        if (! $day) {
            return null;
        }

        $attendance = Attendance::query()
            ->with('day')
            ->where('member_id', $request->member_id)
            ->where('attendance_day_id', $day->id)
            ->first();

        if (! $attendance) {
            return null;
        }

        return [
            'id' => $attendance->public_id,
            'status' => $attendance->status->value,
            'check_in_at' => $attendance->check_in_at?->toIso8601String(),
            'check_out_at' => $attendance->check_out_at?->toIso8601String(),
            'presence_summary' => self::presenceSummary($attendance),
        ];
    }

    private static function attendanceRequestSummary(AttendanceRequest $request): array
    {
        $request->loadMissing('reviewer');

        return [
            'id' => $request->public_id,
            'type' => $request->type->value,
            'status' => $request->status->value,
            'reason' => $request->reason,
            'review_note' => $request->review_note,
            'approved_check_in_at' => $request->approved_check_in_at?->toIso8601String(),
            'approved_check_out_at' => $request->approved_check_out_at?->toIso8601String(),
            'reviewer' => $request->reviewer ? [
                'id' => $request->reviewer->public_id,
                'name' => $request->reviewer->name,
            ] : null,
            'reviewed_at' => $request->reviewed_at?->toIso8601String(),
        ];
    }

    private static function presenceSummary(Attendance $attendance): array
    {
        $partial = in_array($attendance->status, [AttendanceStatus::Permission, AttendanceStatus::Sick, AttendanceStatus::OfficialDuty], true)
            && $attendance->check_in_at
            && $attendance->check_out_at;
        $minutes = $partial
            ? max(0, (int) $attendance->check_in_at->diffInMinutes($attendance->check_out_at, false))
            : null;
        $startedAtLabel = $partial ? $attendance->check_in_at->copy()->timezone(config('app.timezone'))->format('H.i') : null;
        $endedAtLabel = $partial ? $attendance->check_out_at->copy()->timezone(config('app.timezone'))->format('H.i') : null;

        return [
            'is_partial_absence' => $partial,
            'label' => $partial
                ? self::statusLabel($attendance->status).' · sempat hadir '.$startedAtLabel.'–'.$endedAtLabel.' ('.self::durationLabel($minutes).')'
                : null,
            'duration_minutes' => $minutes,
            'duration_label' => $minutes !== null ? self::durationLabel($minutes) : null,
            'started_at' => $partial ? $attendance->check_in_at->toIso8601String() : null,
            'ended_at' => $partial ? $attendance->check_out_at->toIso8601String() : null,
        ];
    }

    private static function statusLabel(AttendanceStatus $status): string
    {
        return match ($status) {
            AttendanceStatus::Permission => 'Izin',
            AttendanceStatus::Sick => 'Sakit',
            AttendanceStatus::OfficialDuty => 'Dinas',
            AttendanceStatus::Leave => 'Cuti',
            AttendanceStatus::Absent => 'Alpa',
            AttendanceStatus::Present => 'Hadir',
            AttendanceStatus::Pending => 'Belum hadir',
        };
    }

    private static function durationLabel(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $remaining = $minutes % 60;

        return $hours > 0 ? $hours.'j'.str_pad((string) $remaining, 2, '0', STR_PAD_LEFT) : $remaining.'m';
    }
}
