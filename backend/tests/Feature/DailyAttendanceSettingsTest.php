<?php

use App\Enums\AttendanceStatus;
use App\Enums\UserStatus;
use App\Models\Attendance;
use App\Models\AttendanceWeeklySchedule;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\User;
use App\Services\DailyAttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Role::findOrCreate('super_admin');
    Role::findOrCreate('member');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('uses a date exception before the weekly schedule', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-24 07:00', config('app.timezone')));
    $admin = dailyUser('super_admin', 'daily-admin');
    AttendanceWeeklySchedule::query()->create([
        'weekday' => 3,
        'is_working_day' => true,
        'check_in_time' => '08:00',
        'check_in_before_minutes' => 30,
        'check_in_after_minutes' => 30,
        'check_out_time' => '16:00',
        'check_out_before_minutes' => 30,
        'check_out_after_minutes' => 30,
    ]);

    $this->actingAs($admin)->postJson('/api/v1/attendance-exceptions', [
        'attendance_date' => '2026-06-24',
        'is_working_day' => false,
        'check_in_before_minutes' => 30,
        'check_in_after_minutes' => 30,
        'check_out_before_minutes' => 30,
        'check_out_after_minutes' => 30,
        'note' => 'Libur organisasi',
    ])->assertCreated();

    $day = app(DailyAttendanceService::class)->forDate();
    expect($day->is_working_day)->toBeFalse()
        ->and($day->source)->toBe('exception')
        ->and(AuditLog::query()->where('action', 'attendance_exception.created')->exists())->toBeTrue();
});

it('applies weekly schedule changes to today immediately when no date exception exists', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-22 08:05', config('app.timezone')));
    $admin = dailyUser('super_admin', 'schedule-admin');
    AttendanceWeeklySchedule::query()->create([
        'weekday' => 1,
        'is_working_day' => true,
        'check_in_time' => '10:00',
        'check_in_before_minutes' => 30,
        'check_in_after_minutes' => 30,
        'check_out_time' => '16:00',
        'check_out_before_minutes' => 30,
        'check_out_after_minutes' => 30,
    ]);

    $day = app(DailyAttendanceService::class)->forDate();
    expect(app(DailyAttendanceService::class)->phaseAt($day))->toBeNull();

    $this->actingAs($admin)->putJson('/api/v1/attendance-settings/weekly/1', [
        'is_working_day' => true,
        'check_in_time' => '08:00',
        'check_in_before_minutes' => 30,
        'check_in_after_minutes' => 30,
        'check_out_time' => '16:00',
        'check_out_before_minutes' => 30,
        'check_out_after_minutes' => 30,
    ])->assertOk();

    $day = app(DailyAttendanceService::class)->forDate()->fresh();
    expect(app(DailyAttendanceService::class)->phaseAt($day))->toBe('check_in')
        ->and($day->check_in_target_at->timezone(config('app.timezone'))->format('H:i'))->toBe('08:00')
        ->and($day->source)->toBe('weekly');
});

it('lets super admins correct and reset daily attendance with reasons in the log', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-22 09:00', config('app.timezone')));
    $admin = dailyUser('super_admin', 'manual-admin');
    $memberUser = dailyUser('member', '220349001');
    $member = Member::query()->create(['user_id' => $memberUser->id, 'member_number' => '220349001', 'position' => 'Anggota']);
    AttendanceWeeklySchedule::query()->create([
        'weekday' => 1,
        'is_working_day' => true,
        'check_in_time' => '08:00',
        'check_in_before_minutes' => 30,
        'check_in_after_minutes' => 30,
        'check_out_time' => '16:00',
        'check_out_before_minutes' => 30,
        'check_out_after_minutes' => 30,
    ]);
    $day = app(DailyAttendanceService::class)->forDate();
    $attendance = Attendance::query()->where('member_id', $member->id)->where('attendance_day_id', $day->id)->firstOrFail();

    $this->actingAs($admin)->postJson('/api/v1/attendances', [
        'member_id' => $member->public_id,
        'attendance_date' => '2026-06-22',
        'status' => 'permission',
        'note' => 'Surat izin diterima',
        'reason' => 'Koreksi dari admin',
    ])->assertOk()->assertJsonPath('data.status', 'permission');

    $this->actingAs($admin)->putJson("/api/v1/attendances/{$attendance->public_id}", [
        'status' => 'present',
        'check_in_at' => '2026-06-22T08:05:00+08:00',
        'reason' => 'Anggota ternyata hadir',
    ])->assertOk()->assertJsonPath('data.check_in_status', 'late');

    $this->actingAs($admin)->deleteJson("/api/v1/attendances/{$attendance->public_id}", [
        'reason' => 'Batalkan koreksi terakhir',
    ])->assertOk()->assertJsonPath('data.status', 'absent');

    expect($attendance->fresh()->status)->toBe(AttendanceStatus::Absent)
        ->and(AuditLog::query()->whereIn('action', ['attendance.manual_saved', 'attendance.manual_updated', 'attendance.manual_reset'])->count())->toBe(3);
});

function dailyUser(string $role, string $loginId): User
{
    $user = User::query()->create([
        'login_id' => $loginId,
        'name' => str($role)->replace('_', ' ')->title(),
        'status' => UserStatus::Active,
        'registration_source' => 'test',
        'must_change_password' => false,
        'approved_at' => now(),
        'password' => Hash::make('Password12345'),
    ]);
    $user->assignRole($role);

    return $user;
}
