<?php

use App\Enums\AttendanceStatus;
use App\Enums\UserStatus;
use App\Models\Attendance;
use App\Models\AttendanceRequestActionToken;
use App\Models\AttendanceRequestReviewer;
use App\Models\AttendanceWeeklySchedule;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Models\WhatsAppNotificationSetting;
use App\Services\DailyAttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Role::findOrCreate('super_admin');
    Role::findOrCreate('operator');
    Role::findOrCreate('member');
    waSchedule(1);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-22 10:00', config('app.timezone')));
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('notifies the applicant and authorized reviewers when a request is submitted', function (): void {
    Http::fake(['https://gateway.test/*' => Http::response(['ok' => true], 200)]);
    WhatsAppNotificationSetting::query()->create([
        'enabled' => true,
        'send_url' => 'https://gateway.test/ext/secret/wa',
        'status_url' => 'https://gateway.test/ext/secret/wa/status',
        'public_base_url' => 'https://absensi.example.test',
    ]);
    $admin = waRequestUser('super_admin', 'wa-admin', '628111111111');
    [$memberUser] = waRequestMember('220360001', '628222222222');

    $this->actingAs($memberUser)->postJson('/api/v1/attendance-requests', [
        'type' => 'sick',
        'date_from' => '2026-06-22',
        'date_to' => '2026-06-22',
        'reason' => 'Tidak dapat hadir karena sedang sakit.',
    ])->assertCreated();

    expect(NotificationDelivery::query()->where('status', 'sent')->count())->toBe(2)
        ->and(AttendanceRequestActionToken::query()->where('user_id', $admin->id)->count())->toBe(1);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->url() === 'https://gateway.test/ext/secret/wa'
        && data_get($request->data(), 'to') === '628111111111'
        && data_get($request->data(), 'interactive.buttons.0.type') === 'url'
        && data_get($request->data(), 'interactive.buttons.2.type') === 'copy');
});

it('keeps the request flow alive and logs skipped deliveries when WhatsApp is disabled', function (): void {
    Http::fake();
    WhatsAppNotificationSetting::query()->create([
        'enabled' => false,
        'send_url' => 'https://gateway.test/ext/secret/wa',
        'public_base_url' => 'https://absensi.example.test',
    ]);
    waRequestUser('super_admin', 'wa-disabled-admin', '628111111111');
    [$memberUser] = waRequestMember('220360002', '628222222222');

    $this->actingAs($memberUser)->postJson('/api/v1/attendance-requests', [
        'type' => 'leave',
        'date_from' => '2026-06-22',
        'date_to' => '2026-06-22',
        'reason' => 'Mengajukan cuti untuk keperluan keluarga.',
    ])->assertCreated();

    expect(NotificationDelivery::query()->where('status', 'skipped')->count())->toBe(2);
    Http::assertNothingSent();
});

it('allows only configured operators to review attendance requests', function (): void {
    WhatsAppNotificationSetting::query()->create(['enabled' => false]);
    [$memberUser, $member] = waRequestMember('220360003', '628222222222');
    $operator = waRequestUser('operator', 'wa-operator', '628333333333');
    $admin = waRequestUser('super_admin', 'wa-admin-reviewer', '628111111111');
    $publicId = $this->actingAs($memberUser)->postJson('/api/v1/attendance-requests', [
        'type' => 'sick',
        'date_from' => '2026-06-22',
        'date_to' => '2026-06-22',
        'reason' => 'Tidak dapat hadir karena sedang sakit.',
    ])->assertCreated()->json('data.id');

    $this->actingAs($operator)->postJson("/api/v1/admin/attendance-requests/{$publicId}/approve")->assertForbidden();

    $this->actingAs($admin)->putJson('/api/v1/admin/attendance-request-reviewers', [
        'operator_ids' => [$operator->public_id],
    ])->assertOk()->assertJsonPath('data.operators.0.authorized', true);

    $this->actingAs($operator)->postJson("/api/v1/admin/attendance-requests/{$publicId}/approve")->assertOk();
    $day = app(DailyAttendanceService::class)->forDate('2026-06-22');
    expect(Attendance::query()->where('member_id', $member->id)->where('attendance_day_id', $day->id)->firstOrFail()->status)->toBe(AttendanceStatus::Sick);
});

it('approves through public WhatsApp link only with the recipient code and records the reviewer', function (): void {
    Http::fake(['https://gateway.test/*' => Http::response(['ok' => true], 200)]);
    WhatsAppNotificationSetting::query()->create([
        'enabled' => true,
        'send_url' => 'https://gateway.test/ext/secret/wa',
        'public_base_url' => 'https://absensi.example.test',
    ]);
    $admin = waRequestUser('super_admin', 'wa-public-admin', '628111111111');
    [$memberUser, $member] = waRequestMember('220360004', '628222222222');

    $this->actingAs($memberUser)->postJson('/api/v1/attendance-requests', [
        'type' => 'permission',
        'date_from' => '2026-06-22',
        'date_to' => '2026-06-22',
        'reason' => 'Izin tidak dapat mengikuti kegiatan karena urusan keluarga.',
    ])->assertCreated();
    $delivery = NotificationDelivery::query()->where('recipient_user_id', $admin->id)->firstOrFail();
    $approveUrl = data_get($delivery->payload, 'interactive.buttons.0.url');
    $code = data_get($delivery->payload, 'interactive.buttons.2.copyCode');
    preg_match('#/public/attendance-requests/([^?]+)#', $approveUrl, $matches);
    $token = $matches[1];

    $this->postJson("/api/v1/public/attendance-request-actions/{$token}/confirm", [
        'action' => 'approve',
        'code' => '000000',
    ])->assertStatus(422);

    $this->postJson("/api/v1/public/attendance-request-actions/{$token}/confirm", [
        'action' => 'approve',
        'code' => $code,
        'review_note' => 'Disetujui via WhatsApp.',
    ])->assertOk()->assertJsonPath('data.reviewer.name', $admin->name);

    $this->postJson("/api/v1/public/attendance-request-actions/{$token}/confirm", [
        'action' => 'approve',
        'code' => $code,
    ])->assertStatus(409);

    $day = app(DailyAttendanceService::class)->forDate('2026-06-22');
    expect(Attendance::query()->where('member_id', $member->id)->where('attendance_day_id', $day->id)->firstOrFail()->status)->toBe(AttendanceStatus::Permission)
        ->and(AuditLog::query()->where('action', 'attendance_request.approved_public')->where('actor_id', $admin->id)->exists())->toBeTrue();
});

it('sends a WhatsApp gateway test message even before notifications are enabled', function (): void {
    Http::fake(['https://gateway.test/*' => Http::response(['ok' => true], 200)]);
    WhatsAppNotificationSetting::query()->create([
        'enabled' => false,
        'send_url' => 'https://gateway.test/ext/secret/wa',
        'public_base_url' => 'https://absensi.example.test',
    ]);
    $admin = waRequestUser('super_admin', 'wa-test-admin', '628111111111');

    $this->actingAs($admin)->postJson('/api/v1/admin/settings/whatsapp/test', [
        'phone' => '081234567890',
    ])->assertOk()->assertJsonPath('data.status', 'sent');

    Http::assertSent(fn ($request) => data_get($request->data(), 'to') === '6281234567890');
});

function waSchedule(int $weekday): void
{
    AttendanceWeeklySchedule::query()->create([
        'weekday' => $weekday,
        'is_working_day' => true,
        'check_in_time' => '08:00',
        'check_in_before_minutes' => 30,
        'check_in_after_minutes' => 30,
        'check_out_time' => '16:00',
        'check_out_before_minutes' => 30,
        'check_out_after_minutes' => 30,
    ]);
}

function waRequestUser(string $role, string $loginId, ?string $phone = null): User
{
    $user = User::query()->create([
        'login_id' => $loginId,
        'name' => str($role)->replace('_', ' ')->title()->toString(),
        'phone' => $phone,
        'status' => UserStatus::Active,
        'registration_source' => 'test',
        'must_change_password' => false,
        'approved_at' => now(),
        'password' => Hash::make('Password12345'),
    ]);
    $user->assignRole($role);

    return $user;
}

function waRequestMember(string $number, ?string $phone = null): array
{
    $user = waRequestUser('member', $number, $phone);
    $member = Member::query()->create(['user_id' => $user->id, 'member_number' => $number, 'position' => 'Anggota']);

    return [$user, $member];
}
