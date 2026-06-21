<?php

use App\Enums\AttendanceStatus;
use App\Enums\UserStatus;
use App\Models\Attendance;
use App\Models\AttendanceRequest;
use App\Models\AttendanceWeeklySchedule;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\User;
use App\Services\DailyAttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Role::findOrCreate('super_admin');
    Role::findOrCreate('operator');
    Role::findOrCreate('member');
    requestSchedule(1);
    requestSchedule(2);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('lets a member submit a private request and only a super admin approve it', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-22 18:00', config('app.timezone')));
    [$memberUser, $member] = requestMember('220350001');
    $operator = requestUser('operator', 'request-operator');
    $admin = requestUser('super_admin', 'request-admin');

    $response = $this->actingAs($memberUser)->post('/api/v1/attendance-requests', [
        'type' => 'sick',
        'date_from' => '2026-06-22',
        'date_to' => '2026-06-22',
        'reason' => 'Tidak dapat hadir karena sedang sakit.',
        'attachment' => UploadedFile::fake()->create('surat-sakit.pdf', 120, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.has_attachment', true);
    $publicId = $response->json('data.id');

    $this->actingAs($operator)->postJson("/api/v1/admin/attendance-requests/{$publicId}/approve")->assertForbidden();
    $this->actingAs($admin)->postJson("/api/v1/admin/attendance-requests/{$publicId}/approve", [
        'review_note' => 'Surat telah diperiksa.',
    ])->assertOk()->assertJsonPath('data.status', 'approved');

    $day = app(DailyAttendanceService::class)->forDate('2026-06-22');
    expect(Attendance::query()->where('member_id', $member->id)->where('attendance_day_id', $day->id)->firstOrFail()->status)->toBe(AttendanceStatus::Sick)
        ->and(AuditLog::query()->whereIn('action', ['attendance_request.submitted', 'attendance_request.approved'])->count())->toBe(2);
});

it('prevents overlapping pending requests and lets members cancel their own request', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-22 18:00', config('app.timezone')));
    [$memberUser] = requestMember('220350002');
    $payload = ['type' => 'leave', 'date_from' => '2026-06-22', 'date_to' => '2026-06-24', 'reason' => 'Mengajukan cuti untuk keperluan keluarga.'];
    $publicId = $this->actingAs($memberUser)->postJson('/api/v1/attendance-requests', $payload)->assertCreated()->json('data.id');
    $this->actingAs($memberUser)->postJson('/api/v1/attendance-requests', [
        ...$payload, 'type' => 'permission', 'date_from' => '2026-06-23', 'date_to' => '2026-06-23',
    ])->assertStatus(409);

    $this->actingAs($memberUser)->deleteJson("/api/v1/attendance-requests/{$publicId}")
        ->assertOk()->assertJsonPath('data.status', 'cancelled');
});

it('applies an approved missed checkin with the requested time', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-22 18:00', config('app.timezone')));
    [$memberUser, $member] = requestMember('220350003');
    $admin = requestUser('super_admin', 'time-admin');
    $publicId = $this->actingAs($memberUser)->postJson('/api/v1/attendance-requests', [
        'type' => 'missed_check_in',
        'date_from' => '2026-06-22',
        'date_to' => '2026-06-22',
        'proposed_check_in_at' => '2026-06-22T08:12:00+08:00',
        'reason' => 'QR tidak dapat dipindai ketika tiba di kantor.',
    ])->assertCreated()->json('data.id');

    $this->actingAs($admin)->postJson("/api/v1/admin/attendance-requests/{$publicId}/approve")->assertOk();
    $attendance = Attendance::query()->where('member_id', $member->id)->firstOrFail();
    expect($attendance->status)->toBe(AttendanceStatus::Present)
        ->and($attendance->check_in_status?->value)->toBe('late')
        ->and($attendance->source)->toBe('approved_request');
});

it('applies approved future leave when the attendance day is materialized', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-22 18:00', config('app.timezone')));
    [$memberUser, $member] = requestMember('220350004');
    $admin = requestUser('super_admin', 'future-admin');
    $publicId = $this->actingAs($memberUser)->postJson('/api/v1/attendance-requests', [
        'type' => 'official_duty',
        'date_from' => '2026-06-23',
        'date_to' => '2026-06-23',
        'reason' => 'Mengikuti tugas kedinasan di luar kantor.',
    ])->assertCreated()->json('data.id');
    $this->actingAs($admin)->postJson("/api/v1/admin/attendance-requests/{$publicId}/approve")->assertOk();
    expect(AttendanceRequest::query()->firstOrFail()->status->value)->toBe('approved');

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-23 07:00', config('app.timezone')));
    $day = app(DailyAttendanceService::class)->forDate();
    expect(Attendance::query()->where('member_id', $member->id)->where('attendance_day_id', $day->id)->firstOrFail()->status)->toBe(AttendanceStatus::OfficialDuty);
});

function requestSchedule(int $weekday): void
{
    AttendanceWeeklySchedule::query()->create([
        'weekday' => $weekday, 'is_working_day' => true, 'check_in_time' => '08:00',
        'check_in_before_minutes' => 30, 'check_in_after_minutes' => 30, 'check_out_time' => '16:00',
        'check_out_before_minutes' => 30, 'check_out_after_minutes' => 30,
    ]);
}

function requestUser(string $role, string $loginId): User
{
    $user = User::query()->create(['login_id' => $loginId, 'name' => str($role)->replace('_', ' ')->title(), 'status' => UserStatus::Active, 'registration_source' => 'test', 'must_change_password' => false, 'approved_at' => now(), 'password' => Hash::make('Password12345')]);
    $user->assignRole($role);

    return $user;
}

function requestMember(string $number): array
{
    $user = requestUser('member', $number);
    $member = Member::query()->create(['user_id' => $user->id, 'member_number' => $number, 'position' => 'Anggota']);

    return [$user, $member];
}
