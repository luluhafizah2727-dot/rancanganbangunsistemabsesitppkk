<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function login(Request $request, AuditLogger $audit): JsonResponse
    {
        $credentials = $request->validate([
            'login_id' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        if (! Auth::guard('web')->attempt(['login_id' => $credentials['login_id'], 'password' => $credentials['password']], $credentials['remember'] ?? false)) {
            return ApiResponse::error('ID pengguna atau password salah.', 'invalid_credentials', 422, [
                'login_id' => ['ID pengguna atau password salah.'],
            ]);
        }

        /** @var User $user */
        $user = Auth::guard('web')->user();
        if ($user->status !== UserStatus::Active) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
            }

            return ApiResponse::error(
                $user->status === UserStatus::Pending ? 'Akun masih menunggu persetujuan admin.' : 'Akun tidak aktif.',
                'account_not_active',
                403,
            );
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
        $user->forceFill(['last_login_at' => now()])->save();
        $audit->log('auth.login', $user);

        return ApiResponse::success(Present::user($user));
    }

    public function logout(Request $request, AuditLogger $audit): JsonResponse
    {
        $audit->log('auth.logout', $request->user());
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return ApiResponse::success(['message' => 'Berhasil keluar.']);
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(Present::user($request->user()));
    }

    public function register(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'member_number' => ['required', 'string', 'max:50', 'unique:users,login_id', 'unique:members,member_number'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'position' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:100'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = DB::transaction(function () use ($data): User {
            $user = User::query()->create([
                'login_id' => $data['member_number'],
                'name' => $data['name'],
                'phone' => $data['phone'],
                'status' => UserStatus::Pending,
                'registration_source' => 'self',
                'must_change_password' => false,
                'password' => Hash::make($data['password']),
            ]);
            $user->assignRole('member');
            Member::query()->create([
                'user_id' => $user->id,
                'member_number' => $data['member_number'],
                'position' => $data['position'] ?? null,
                'department' => $data['department'] ?? null,
            ]);

            return $user;
        });

        $audit->log('member.self_registered', $user);

        return ApiResponse::success([
            'message' => 'Pendaftaran diterima dan menunggu persetujuan admin.',
            'status' => 'pending',
        ], status: 201);
    }

    public function changePassword(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8), 'different:current_password'],
        ]);

        $request->user()->update(['password' => Hash::make($data['password']), 'must_change_password' => false]);
        $audit->log('auth.password_changed', $request->user());

        return ApiResponse::success(['message' => 'Password berhasil diperbarui.']);
    }
}
