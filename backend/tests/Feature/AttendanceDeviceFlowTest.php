<?php

use App\Enums\AttendanceDeviceStatus;
use App\Enums\AttendancePhase;
use App\Enums\AttendanceStatus;
use App\Enums\UserStatus;
use App\Models\AppSetting;
use App\Models\Attendance;
use App\Models\AttendanceDevice;
use App\Models\AttendanceScan;
use App\Models\AttendanceWeeklySchedule;
use App\Models\Member;
use App\Models\User;
use App\Services\AttendanceDeviceCredentialService;
use App\Services\DailyAttendanceService;
use App\Services\MemberDeviceBindingService;
use App\Services\QrTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Role::findOrCreate('super_admin');
    Role::findOrCreate('operator');
    Role::findOrCreate('member');
    AppSetting::setValue(MemberDeviceBindingService::SETTING_KEY, MemberDeviceBindingService::MODE_AUDIT);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('activates exactly one device record with a one-time long-lived credential', function (): void {
    $admin = deviceStaff('super_admin', 'admin-device');
    $device = AttendanceDevice::query()->create(['code' => 'GAWAI-010', 'name' => 'Gawai Test', 'status' => 'pending']);
    $code = $this->actingAs($admin)->postJson("/api/v1/attendance-devices/{$device->public_id}/activation-code")
        ->assertCreated()->json('data.activation_code');

    Auth::guard('web')->logout();
    $this->app['auth']->forgetGuards();
    $this->postJson('/api/v1/attendance-devices/activate', [
        'activation_code' => $code,
        'fingerprint' => 'browser-test',
        'screen' => ['width' => 1366, 'height' => 768],
        'timezone' => 'Asia/Makassar',
    ])->assertCreated()->assertCookie('attendance_device_token')->assertJsonPath('data.registered', true);

    expect($device->fresh()->activated_by)->toBe($admin->id)
        ->and(now()->diffInDays($device->fresh()->credential_expires_at))->toBeGreaterThanOrEqual(399);

    $this->postJson('/api/v1/attendance-devices/activate', ['activation_code' => $code])->assertStatus(422);
});

it('returns a public activation state and a daily context for registered devices', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-22 08:05', config('app.timezone')));
    [, $device] = deviceFixture(AttendancePhase::CheckIn);
    $credentialRequest = Request::create('/api/v1/attendance-device/context');
    $credentialRequest->cookies->set('attendance_device_token', 'credential-check_in');
    expect(app(AttendanceDeviceCredentialService::class)->resolve($credentialRequest)?->is($device))->toBeTrue();

    $this->getJson('/api/v1/attendance-device/context')->assertOk()->assertJsonPath('data.registered', false);
    $this->call('GET', '/api/v1/attendance-device/context', cookies: [
        'attendance_device_token' => 'credential-check_in',
    ], server: ['HTTP_ACCEPT' => 'application/json'])
        ->assertOk()->assertJsonPath('data.registered', true)
        ->assertJsonPath('data.device.id', $device->public_id)
        ->assertJsonPath('data.current_phase', 'check_in')
        ->assertJsonPath('data.attendance_day.attendance_date', '2026-06-22');
});

it('records attendance once and returns idempotent duplicate scans', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-22 08:05', config('app.timezone')));
    [$member, $device] = deviceFixture(AttendancePhase::CheckIn);
    $token = app(QrTokenService::class)->rotate($device, true)['token'];

    $this->actingAs($member->user)->postJson('/api/v1/attendance/scans', ['token' => $token])
        ->assertOk()->assertJsonPath('data.phase', 'check_in')->assertJsonPath('data.already_recorded', false)
        ->assertJsonPath('data.attendance.check_in_status', 'late');
    $this->actingAs($member->user)->postJson('/api/v1/attendance/scans', ['token' => $token])
        ->assertOk()->assertJsonPath('data.already_recorded', true);

    expect(Attendance::query()->where('status', AttendanceStatus::Present->value)->count())->toBe(1)
        ->and(AttendanceScan::query()->where('accepted', true)->count())->toBe(2);
});

it('creates a missing daily attendance row only once during check-in', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-22 08:05', config('app.timezone')));
    [$member, $device] = deviceFixture(AttendancePhase::CheckIn);
    Attendance::query()
        ->where('member_id', $member->id)
        ->whereHas('day', fn ($query) => $query->whereDate('attendance_date', '2026-06-22'))
        ->forceDelete();
    $token = app(QrTokenService::class)->rotate($device, true)['token'];

    $this->actingAs($member->user)->postJson('/api/v1/attendance/scans', ['token' => $token])
        ->assertOk()->assertJsonPath('data.already_recorded', false);
    $this->actingAs($member->user)->postJson('/api/v1/attendance/scans', ['token' => $token])
        ->assertOk()->assertJsonPath('data.already_recorded', true);

    expect(Attendance::query()->where('member_id', $member->id)->count())->toBe(1)
        ->and(AttendanceScan::query()->where('member_id', $member->id)->where('accepted', true)->count())->toBe(2);
});

it('rejects checkout before checkin and keeps a rejected scan row', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-22 16:05', config('app.timezone')));
    [$member, $device] = deviceFixture(AttendancePhase::CheckOut);
    $token = app(QrTokenService::class)->rotate($device, true)['token'];

    $this->actingAs($member->user)->postJson('/api/v1/attendance/scans', ['token' => $token])
        ->assertStatus(422)->assertJsonValidationErrors('token');

    expect(Attendance::query()->where('member_id', $member->id)->firstOrFail()->check_out_at)->toBeNull()
        ->and(AttendanceScan::query()->where('accepted', false)->where('reason', 'check_in_required')->count())->toBe(1);
});

it('revokes a device and immediately rejects its credential', function (): void {
    $admin = deviceStaff('super_admin', 'revoke-admin');
    [, $device] = deviceFixture(AttendancePhase::CheckIn);

    $this->actingAs($admin)->postJson("/api/v1/attendance-devices/{$device->public_id}/revoke")->assertOk();
    $this->call('POST', '/api/v1/attendance-device/heartbeat', cookies: [
        'attendance_device_token' => 'credential-check_in',
    ], server: ['HTTP_ACCEPT' => 'application/json'])->assertUnauthorized();
});

function deviceFixture(AttendancePhase $phase): array
{
    $admin = deviceStaff('super_admin', 'admin-'.$phase->value);
    $member = deviceMember('22034077'.($phase === AttendancePhase::CheckIn ? '1' : '2'));
    AttendanceWeeklySchedule::query()->updateOrCreate(['weekday' => 1], [
        'is_working_day' => true, 'check_in_time' => '08:00', 'check_in_before_minutes' => 30,
        'check_in_after_minutes' => 30, 'check_out_time' => '16:00', 'check_out_before_minutes' => 30,
        'check_out_after_minutes' => 30,
    ]);
    $device = AttendanceDevice::query()->create([
        'code' => 'GAWAI-'.$phase->value,
        'name' => 'Gawai '.$phase->value,
        'credential_hash' => hash('sha256', 'credential-'.$phase->value),
        'credential_rotated_at' => now(),
        'credential_expires_at' => now()->addDays(400),
        'status' => AttendanceDeviceStatus::Active,
        'activated_at' => now(),
        'activated_by' => $admin->id,
    ]);
    app(DailyAttendanceService::class)->forDate();

    return [$member, $device];
}

function deviceStaff(string $role, string $loginId): User
{
    $user = User::query()->create(['login_id' => $loginId, 'name' => str($role)->replace('_', ' ')->title(), 'status' => UserStatus::Active, 'registration_source' => 'test', 'must_change_password' => false, 'approved_at' => now(), 'password' => Hash::make('Password12345')]);
    $user->assignRole($role);

    return $user;
}

function deviceMember(string $memberNumber): Member
{
    $user = User::query()->create(['login_id' => $memberNumber, 'name' => 'Siti Test', 'status' => UserStatus::Active, 'registration_source' => 'test', 'must_change_password' => false, 'approved_at' => now(), 'password' => Hash::make('Password12345')]);
    $user->assignRole('member');

    return Member::query()->create(['user_id' => $user->id, 'member_number' => $memberNumber, 'position' => 'Anggota', 'department' => 'TP PKK']);
}
