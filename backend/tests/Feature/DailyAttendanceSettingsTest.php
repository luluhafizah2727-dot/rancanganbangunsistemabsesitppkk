<?php

use App\Enums\AttendanceDeviceStatus;
use App\Enums\AttendanceStatus;
use App\Enums\UserStatus;
use App\Models\Attendance;
use App\Models\AttendanceDevice;
use App\Models\AttendanceScan;
use App\Models\AttendanceWeeklySchedule;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\User;
use App\Services\DailyAttendanceService;
use App\Services\MemberDeviceBindingService;
use App\Services\QrTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Role::findOrCreate('super_admin');
    Role::findOrCreate('member');
    AppSetting::setValue(MemberDeviceBindingService::SETTING_KEY, MemberDeviceBindingService::MODE_AUDIT);
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

it('requires selected devices for working date exceptions and ignores devices for holidays', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-24 07:00', config('app.timezone')));
    $admin = dailyUser('super_admin', 'exception-device-admin');

    $this->actingAs($admin)->postJson('/api/v1/attendance-exceptions', workingExceptionPayload('2026-06-24'))
        ->assertStatus(422)
        ->assertJsonValidationErrors('attendance_device_ids');

    $this->actingAs($admin)->postJson('/api/v1/attendance-exceptions', [
        'attendance_date' => '2026-06-25',
        'is_working_day' => false,
        'check_in_before_minutes' => 30,
        'check_in_after_minutes' => 30,
        'check_out_before_minutes' => 30,
        'check_out_after_minutes' => 30,
        'attendance_device_ids' => [],
        'note' => 'Libur organisasi',
    ])->assertCreated()->assertJsonPath('data.device_ids', []);
});

it('limits a working date exception to selected devices and rejects stale scans from others', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-24 08:05', config('app.timezone')));
    $admin = dailyUser('super_admin', 'scope-admin');
    $memberUser = dailyUser('member', 'scope-member');
    Member::query()->create(['user_id' => $memberUser->id, 'member_number' => '260624001', 'position' => 'Anggota']);
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
    $selected = dailyDevice($admin, 'EVENT-01', 'Gawai Aula', 'credential-selected');
    $unselected = dailyDevice($admin, 'EVENT-02', 'Gawai Kantor', 'credential-unselected');
    $staleToken = app(QrTokenService::class)->rotate($unselected, true)['token'];

    $this->actingAs($admin)->postJson('/api/v1/attendance-exceptions', workingExceptionPayload('2026-06-24', [$selected->public_id]))
        ->assertCreated()
        ->assertJsonPath('data.device_ids.0', $selected->public_id)
        ->assertJsonPath('data.devices.0.name', 'Gawai Aula');

    $day = app(DailyAttendanceService::class)->forDate()->fresh();
    expect($day->source)->toBe('exception')
        ->and($day->schedule_snapshot['device_scope'])->toBe('selected')
        ->and($day->schedule_snapshot['attendance_device_public_ids'])->toBe([$selected->public_id])
        ->and(app(QrTokenService::class)->rotate($selected, true))->not->toBeNull()
        ->and(app(QrTokenService::class)->rotate($unselected, true))->toBeNull();

    $this->call('GET', '/api/v1/attendance-device/context', cookies: [
        'attendance_device_token' => 'credential-selected',
    ], server: ['HTTP_ACCEPT' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.qr.device.id', $selected->public_id);

    $this->call('GET', '/api/v1/attendance-device/context', cookies: [
        'attendance_device_token' => 'credential-unselected',
    ], server: ['HTTP_ACCEPT' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.qr', null)
        ->assertJsonPath('data.qr_unavailable_reason', 'Gawai ini tidak diizinkan untuk jadwal khusus hari ini.');

    $this->actingAs($memberUser)->postJson('/api/v1/attendance/scans', ['token' => $staleToken])
        ->assertStatus(422)
        ->assertJsonValidationErrors('token');

    expect(AttendanceScan::query()->where('accepted', false)->where('reason', 'device_not_allowed_for_day')->count())->toBe(1);
});

it('updates today exception device scope immediately', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-24 08:05', config('app.timezone')));
    $admin = dailyUser('super_admin', 'scope-update-admin');
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
    $first = dailyDevice($admin, 'EVENT-03', 'Gawai Pertama', 'credential-first');
    $second = dailyDevice($admin, 'EVENT-04', 'Gawai Kedua', 'credential-second');

    $exceptionId = $this->actingAs($admin)->postJson('/api/v1/attendance-exceptions', workingExceptionPayload('2026-06-24', [$first->public_id]))
        ->assertCreated()
        ->json('data.id');
    expect(app(QrTokenService::class)->rotate($first, true))->not->toBeNull()
        ->and(app(QrTokenService::class)->rotate($second, true))->toBeNull();

    $this->actingAs($admin)->putJson("/api/v1/attendance-exceptions/{$exceptionId}", workingExceptionPayload('2026-06-24', [$second->public_id], 'Agenda dipindah ke ruang kedua'))
        ->assertOk()
        ->assertJsonPath('data.device_ids.0', $second->public_id);

    $day = app(DailyAttendanceService::class)->forDate()->fresh();
    expect($day->schedule_snapshot['attendance_device_public_ids'])->toBe([$second->public_id])
        ->and(app(QrTokenService::class)->rotate($first, true))->toBeNull()
        ->and(app(QrTokenService::class)->rotate($second, true))->not->toBeNull();
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

/**
 * @param  array<int, string>  $devicePublicIds
 * @return array<string, mixed>
 */
function workingExceptionPayload(string $date, array $devicePublicIds = [], string $note = 'Agenda khusus'): array
{
    return [
        'attendance_date' => $date,
        'is_working_day' => true,
        'check_in_time' => '08:00',
        'check_in_before_minutes' => 30,
        'check_in_after_minutes' => 30,
        'check_out_time' => '16:00',
        'check_out_before_minutes' => 30,
        'check_out_after_minutes' => 30,
        'attendance_device_ids' => $devicePublicIds,
        'note' => $note,
    ];
}

function dailyDevice(User $admin, string $code, string $name, string $credential): AttendanceDevice
{
    return AttendanceDevice::query()->create([
        'code' => $code,
        'name' => $name,
        'credential_hash' => hash('sha256', $credential),
        'credential_rotated_at' => now(),
        'credential_expires_at' => now()->addDays(400),
        'status' => AttendanceDeviceStatus::Active,
        'activated_at' => now(),
        'activated_by' => $admin->id,
    ]);
}
