<?php

use App\Enums\AttendanceStatus;
use App\Enums\UserStatus;
use App\Models\Attendance;
use App\Models\AttendanceWeeklySchedule;
use App\Models\Member;
use App\Models\MemberImport;
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
});

it('previews imports and reports duplicate member numbers without overwriting data', function (): void {
    $admin = importStaff('super_admin', 'admin-import');
    $csv = UploadedFile::fake()->createWithContent('members.csv', implode("\n", [
        'member_number,name,position,department,phone',
        '220340801,Siti Import,Ketua,Pokja I,081111111111',
        '220340801,Siti Duplikat,Anggota,Pokja II,082222222222',
    ]));

    $response = $this->actingAs($admin)
        ->post('/api/v1/member-imports/preview', ['file' => $csv], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.valid_rows', 1)
        ->assertJsonPath('data.failed_rows', 1);

    $import = MemberImport::query()->where('public_id', $response->json('data.import_id'))->firstOrFail();

    $this->actingAs($admin)
        ->postJson("/api/v1/member-imports/{$import->public_id}/confirm")
        ->assertOk()
        ->assertJsonPath('data.created', 1)
        ->assertJsonPath('data.failed', 1);

    expect(Member::query()->where('member_number', '220340801')->count())->toBe(1);
});

it('generates daily report data and xlsx downloads', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-22 09:00', config('app.timezone')));
    $operator = importStaff('operator', 'operator-report');
    $member = importMember('220340901');
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
    Attendance::query()->where('member_id', $member->id)->where('attendance_day_id', $day->id)->update([
        'status' => AttendanceStatus::Present,
        'check_in_at' => now()->subMinutes(10),
    ]);
    $member->delete();

    $this->actingAs($operator)
        ->getJson('/api/v1/reports/attendance?date_from=2026-06-22&date_to=2026-06-22')
        ->assertOk()
        ->assertJsonPath('data.summary.present', 1)
        ->assertJsonPath('data.attendances.0.member.member_number', '220340901');

    $this->actingAs($operator)
        ->get('/api/v1/reports/attendance/xlsx?date_from=2026-06-22&date_to=2026-06-22', ['Accept' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
        ->assertOk()
        ->assertHeader('content-disposition');

    CarbonImmutable::setTestNow();
});

function importStaff(string $role, string $loginId): User
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

function importMember(string $memberNumber): Member
{
    $user = User::query()->create([
        'login_id' => $memberNumber,
        'name' => 'Rahma Test',
        'status' => UserStatus::Active,
        'registration_source' => 'test',
        'must_change_password' => false,
        'approved_at' => now(),
        'password' => Hash::make('Password12345'),
    ]);
    $user->assignRole('member');

    return Member::query()->create([
        'user_id' => $user->id,
        'member_number' => $memberNumber,
        'position' => 'Anggota',
        'department' => 'TP PKK',
    ]);
}
