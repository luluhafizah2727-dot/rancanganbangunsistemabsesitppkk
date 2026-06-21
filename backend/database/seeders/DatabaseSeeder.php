<?php

namespace Database\Seeders;

use App\Enums\AttendanceStatus;
use App\Enums\CheckInStatus;
use App\Enums\UserStatus;
use App\Models\AppSetting;
use App\Models\Attendance;
use App\Models\AttendanceDevice;
use App\Models\AttendanceWeeklySchedule;
use App\Models\Member;
use App\Models\User;
use App\Services\DailyAttendanceService;
use App\Services\MemberDeviceBindingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'members.manage', 'schedules.manage', 'devices.manage', 'attendance.manage',
            'attendance.view', 'reports.view', 'audit.view', 'accounts.manage', 'member_devices.review', 'security.manage',
        ];
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $superAdminRole = Role::findOrCreate('super_admin', 'web');
        $operatorRole = Role::findOrCreate('operator', 'web');
        Role::findOrCreate('member', 'web');
        $superAdminRole->syncPermissions($permissions);
        $operatorRole->syncPermissions(['attendance.view', 'reports.view']);

        $seedAdminPassword = config('app.seed_admin_password') ?: 'ChangeMe123!';
        $admin = User::query()->updateOrCreate(['login_id' => config('app.seed_admin_login_id', 'admin') ?: 'admin'], [
            'name' => 'Super Admin',
            'email' => 'admin@tppkkbalangan.local',
            'status' => UserStatus::Active,
            'registration_source' => 'seed',
            'must_change_password' => true,
            'approved_at' => now(),
            'password' => Hash::make($seedAdminPassword),
        ]);
        $admin->syncRoles([$superAdminRole]);

        $operator = User::query()->updateOrCreate(['login_id' => 'operator'], [
            'name' => 'Operator Absensi',
            'status' => UserStatus::Active,
            'registration_source' => 'seed',
            'must_change_password' => false,
            'approved_at' => now(),
            'password' => Hash::make('Operator123!'),
        ]);
        $operator->syncRoles([$operatorRole]);

        $memberRows = [
            ['220340096', 'Siti Aminah', 'Ketua'],
            ['220340097', 'Nurul Hidayah', 'Sekretaris'],
            ['220340098', 'Hj. Marlina', 'Bendahara'],
            ['220340099', 'Rahmawati', 'Anggota'],
            ['220340100', 'Yuliana', 'Anggota'],
            ['220340101', 'Dewi Lestari', 'Anggota'],
            ['220340102', 'Rina Safitri', 'Anggota'],
            ['220340103', 'Aisyah Rahmah', 'Anggota'],
        ];
        $members = collect($memberRows)->map(function (array $row) {
            $user = User::query()->updateOrCreate(['login_id' => $row[0]], [
                'name' => $row[1],
                'status' => UserStatus::Active,
                'registration_source' => 'seed',
                'must_change_password' => false,
                'approved_at' => now(),
                'password' => Hash::make('MemberDemo123!'),
            ]);
            $user->syncRoles(['member']);

            $member = Member::withTrashed()->updateOrCreate(['member_number' => $row[0]], [
                'user_id' => $user->id,
                'position' => $row[2],
                'department' => 'TP PKK Kabupaten Balangan',
            ]);
            if ($member->trashed()) {
                $member->restore();
            }

            return $member;
        });

        foreach (range(1, 7) as $weekday) {
            AttendanceWeeklySchedule::query()->updateOrCreate(['weekday' => $weekday], [
                'is_working_day' => $weekday <= 5,
                'check_in_time' => $weekday <= 5 ? '08:00' : null,
                'check_in_before_minutes' => 30,
                'check_in_after_minutes' => 30,
                'check_out_time' => $weekday <= 5 ? '16:00' : null,
                'check_out_before_minutes' => 30,
                'check_out_after_minutes' => 30,
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]);
        }

        AttendanceDevice::query()->updateOrCreate(['code' => 'GAWAI-001'], [
            'name' => 'Gawai Aula Utama',
            'location' => 'Aula TP PKK Kabupaten Balangan',
            'status' => 'pending',
        ]);

        AppSetting::setValue(MemberDeviceBindingService::SETTING_KEY, MemberDeviceBindingService::MODE_APPROVAL);

        $demoDate = CarbonImmutable::today(config('app.timezone'));
        while ($demoDate->isWeekend()) {
            $demoDate = $demoDate->subDay();
        }
        $day = app(DailyAttendanceService::class)->forDate($demoDate);
        foreach ($members->take(5) as $index => $member) {
            $checkIn = $demoDate->setTime(8, 5 + ($index * 3));
            Attendance::query()->updateOrCreate([
                'member_id' => $member->id,
                'attendance_day_id' => $day->id,
            ], [
                'status' => AttendanceStatus::Present,
                'check_in_at' => $checkIn,
                'check_in_status' => CheckInStatus::Late,
                'source' => 'seed',
            ]);
        }
    }
}
