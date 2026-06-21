<?php

use App\Enums\AttendanceDeviceStatus;
use App\Enums\UserStatus;
use App\Models\AppSetting;
use App\Models\AttendanceDevice;
use App\Models\AttendanceWeeklySchedule;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\MemberDevice;
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
    Role::findOrCreate('operator');
    Role::findOrCreate('member');
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('manages staff accounts while keeping at least one active super admin', function (): void {
    $admin = accountFlowUser('super_admin', 'accounts-admin');

    $response = $this->actingAs($admin)->postJson('/api/v1/accounts', [
        'login_id' => 'operator-new',
        'name' => 'Operator Baru',
        'role' => 'operator',
    ])->assertCreated()
        ->assertJsonPath('data.account.roles.0', 'operator');

    $operatorId = $response->json('data.account.id');
    expect($response->json('data.temporary_password'))->toBeString();

    $this->actingAs($admin)->putJson("/api/v1/accounts/{$operatorId}", [
        'login_id' => 'operator-renamed',
        'name' => 'Operator Baru',
        'role' => 'operator',
    ])->assertOk()
        ->assertJsonPath('data.login_id', 'operator-renamed');

    expect(User::query()->where('login_id', 'operator-renamed')->exists())->toBeTrue();

    $this->actingAs($admin)->putJson("/api/v1/accounts/{$admin->public_id}", [
        'role' => 'operator',
    ])->assertStatus(409);

    $this->actingAs($admin)->postJson("/api/v1/accounts/{$operatorId}/reset-password")
        ->assertOk()
        ->assertJsonStructure(['data' => ['temporary_password']]);

    expect(AuditLog::query()->whereIn('action', ['account.created', 'account.updated', 'account.password_reset'])->count())->toBe(3);
});

it('requires approval for a member device before scanning when strict mode is active', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-22 08:05', config('app.timezone')));
    $admin = accountFlowUser('super_admin', 'device-approval-admin');
    [$memberUser] = accountFlowMember('220360001');
    $token = accountFlowQrToken();

    $this->actingAs($memberUser)->postJson('/api/v1/attendance/scans', ['token' => $token])
        ->assertForbidden()
        ->assertJsonPath('code', 'member_device_required');

    $request = $this->actingAs($memberUser)->postJson('/api/v1/member-devices', [
        'label' => 'Ponsel pribadi',
        'fingerprint' => 'browser-a',
    ])->assertCreated()
        ->assertCookie('member_device_token');
    $cookie = collect($request->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === 'member_device_token')
        ?->getValue();
    $device = MemberDevice::query()->firstOrFail();

    $this->actingAs($memberUser)
        ->call('POST', '/api/v1/attendance/scans', ['token' => $token], cookies: ['member_device_token' => $cookie], server: ['HTTP_ACCEPT' => 'application/json'])
        ->assertForbidden()
        ->assertJsonPath('code', 'member_device_pending');

    $this->actingAs($admin)->postJson("/api/v1/member-devices/{$device->public_id}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    $this->actingAs($memberUser)
        ->call('POST', '/api/v1/attendance/scans', ['token' => $token], cookies: ['member_device_token' => $cookie], server: ['HTTP_ACCEPT' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.phase', 'check_in');
});

it('can switch member device binding to audit mode and still records new devices', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-22 08:05', config('app.timezone')));
    $admin = accountFlowUser('super_admin', 'device-audit-admin');
    [$memberUser] = accountFlowMember('220360002');
    AppSetting::setValue(MemberDeviceBindingService::SETTING_KEY, MemberDeviceBindingService::MODE_AUDIT);
    $token = accountFlowQrToken('GAWAI-AUDIT');

    $this->actingAs($memberUser)->postJson('/api/v1/attendance/scans', ['token' => $token])
        ->assertOk()
        ->assertCookie('member_device_token');

    expect(MemberDevice::query()->where('member_id', $memberUser->member->id)->where('status', 'pending')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'member_device.audit_recorded')->exists())->toBeTrue();

    $this->actingAs($admin)->putJson('/api/v1/security-settings/member-device-binding', [
        'mode' => 'approval_required',
    ])->assertOk()->assertJsonPath('data.mode', 'approval_required');
});

function accountFlowUser(string $role, string $loginId): User
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

function accountFlowMember(string $memberNumber): array
{
    $user = accountFlowUser('member', $memberNumber);
    $member = Member::query()->create([
        'user_id' => $user->id,
        'member_number' => $memberNumber,
        'position' => 'Anggota',
        'department' => 'TP PKK',
    ]);

    return [$user->load('member'), $member];
}

function accountFlowQrToken(string $code = 'GAWAI-STRICT'): string
{
    AttendanceWeeklySchedule::query()->updateOrCreate(['weekday' => 1], [
        'is_working_day' => true,
        'check_in_time' => '08:00',
        'check_in_before_minutes' => 30,
        'check_in_after_minutes' => 30,
        'check_out_time' => '16:00',
        'check_out_before_minutes' => 30,
        'check_out_after_minutes' => 30,
    ]);
    $admin = accountFlowUser('super_admin', 'admin-'.$code);
    $device = AttendanceDevice::query()->create([
        'code' => $code,
        'name' => 'Gawai '.$code,
        'credential_hash' => hash('sha256', 'credential-'.$code),
        'credential_rotated_at' => now(),
        'credential_expires_at' => now()->addDays(400),
        'status' => AttendanceDeviceStatus::Active,
        'activated_at' => now(),
        'activated_by' => $admin->id,
    ]);
    app(DailyAttendanceService::class)->forDate();

    return app(QrTokenService::class)->rotate($device, true)['token'];
}
