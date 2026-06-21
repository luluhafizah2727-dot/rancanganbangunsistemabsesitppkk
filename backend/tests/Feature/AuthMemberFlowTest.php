<?php

use App\Enums\UserStatus;
use App\Models\Member;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Role::findOrCreate('super_admin');
    Role::findOrCreate('operator');
    Role::findOrCreate('member');
});

it('keeps self registrations pending until super admin approval', function (): void {
    $this->postJson('/api/v1/registrations', [
        'member_number' => '220340501',
        'name' => 'Anggota Baru',
        'phone' => '081234567890',
        'position' => 'Anggota',
        'department' => 'Pokja I',
        'password' => 'MemberBaru123',
        'password_confirmation' => 'MemberBaru123',
    ])->assertCreated()
        ->assertJsonPath('data.status', 'pending');

    $this->postJson('/api/v1/auth/login', [
        'login_id' => '220340501',
        'password' => 'MemberBaru123',
    ])->assertForbidden()
        ->assertJsonPath('code', 'account_not_active');

    $admin = staff('super_admin');
    $member = Member::query()->where('member_number', '220340501')->firstOrFail();

    $this->actingAs($admin)
        ->postJson("/api/v1/members/{$member->public_id}/approve")
        ->assertOk()
        ->assertJsonPath('data.user.status', 'active');

    $this->postJson('/api/v1/auth/login', [
        'login_id' => '220340501',
        'password' => 'MemberBaru123',
    ])->assertOk()
        ->assertJsonPath('data.login_id', '220340501');
});

it('enforces role access for super admin only resources', function (): void {
    $operator = staff('operator', ['login_id' => 'operator']);

    $this->actingAs($operator)
        ->postJson('/api/v1/attendance-devices', [
            'code' => 'KIOSK-099',
            'name' => 'Kiosk Cadangan',
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');
});

it('lets members update contact details and profile photos but not official data', function (): void {
    Storage::fake('public');
    $user = staff('member', ['login_id' => '220340601', 'name' => 'Siti Profil']);
    $member = Member::query()->create([
        'user_id' => $user->id,
        'member_number' => '220340601',
        'position' => 'Anggota',
        'department' => 'Pokja I',
    ]);

    $this->actingAs($user)
        ->putJson('/api/v1/profile', [
            'email' => 'siti@example.test',
            'phone' => '081200000001',
            'address' => 'Paringin',
        ])
        ->assertOk()
        ->assertJsonPath('data.member.address', 'Paringin');

    $this->actingAs($user)
        ->putJson('/api/v1/profile', ['name' => 'Nama Baru'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');

    $this->actingAs($user)
        ->post('/api/v1/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 300, 300),
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.id', $user->public_id);

    $path = $user->fresh()->avatar_path;
    expect($path)->not->toBeNull()
        ->and($member->fresh()->address)->toBe('Paringin');
    Storage::disk('public')->assertExists($path);
});

it('archives members and blocks their existing authenticated session', function (): void {
    $admin = staff('super_admin', ['login_id' => 'admin-archive']);
    $user = staff('member', ['login_id' => '220340602', 'name' => 'Anggota Arsip']);
    $member = Member::query()->create([
        'user_id' => $user->id,
        'member_number' => '220340602',
        'position' => 'Anggota',
    ]);

    $this->actingAs($admin)
        ->putJson("/api/v1/members/{$member->public_id}", [
            'member_number' => '220340603',
            'name' => 'Anggota Diperbarui',
        ])
        ->assertOk()
        ->assertJsonPath('data.member_number', '220340603');

    $this->actingAs($admin)
        ->deleteJson("/api/v1/members/{$member->public_id}")
        ->assertOk();

    $this->assertSoftDeleted('members', ['id' => $member->id]);
    expect($user->fresh()->status)->toBe(UserStatus::Suspended)
        ->and($user->fresh()->login_id)->toBe('220340603');

    $this->actingAs($user->refresh())
        ->getJson('/api/v1/auth/me')
        ->assertForbidden()
        ->assertJsonPath('code', 'account_not_active');
});

function staff(string $role, array $attributes = []): User
{
    $user = User::query()->create([
        'login_id' => $attributes['login_id'] ?? $role,
        'name' => $attributes['name'] ?? str($role)->replace('_', ' ')->title(),
        'status' => UserStatus::Active,
        'registration_source' => 'test',
        'must_change_password' => false,
        'approved_at' => now(),
        'password' => Hash::make('Password12345'),
    ]);
    $user->assignRole($role);

    return $user;
}
