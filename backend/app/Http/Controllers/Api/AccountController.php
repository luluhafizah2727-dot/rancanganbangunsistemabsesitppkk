<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use App\Support\Present;
use App\Support\Search;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $accounts = User::query()
            ->with('member', 'roles')
            ->when($request->string('search')->toString(), function ($query, string $search): void {
                $query->where(function ($inner) use ($search): void {
                    Search::contains($inner, 'login_id', $search)
                        ->orWhere(fn ($nested) => Search::contains($nested, 'name', $search))
                        ->orWhere(fn ($nested) => Search::contains($nested, 'email', $search));
                });
            })
            ->when($request->string('role')->toString(), fn ($query, string $role) => $query->role($role))
            ->when($request->string('status')->toString(), fn ($query, string $status) => $query->where('status', $status))
            ->orderBy('name')
            ->paginate(min($request->integer('per_page', 50), 100));

        return ApiResponse::success(
            collect($accounts->items())->map(fn (User $account) => Present::account($account))->values(),
            ['current_page' => $accounts->currentPage(), 'last_page' => $accounts->lastPage(), 'total' => $accounts->total()],
            ['next' => $accounts->nextPageUrl(), 'prev' => $accounts->previousPageUrl()],
        );
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'login_id' => ['required', 'string', 'max:100', 'unique:users,login_id'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', Rule::in(['super_admin', 'operator'])],
            'password' => ['nullable', 'confirmed', Password::min(8)],
        ]);

        $temporaryPassword = $data['password'] ?? Str::password(14);
        $account = DB::transaction(function () use ($data, $temporaryPassword): User {
            $account = User::query()->create([
                'login_id' => $data['login_id'],
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'status' => UserStatus::Active,
                'registration_source' => 'admin',
                'must_change_password' => true,
                'approved_at' => now(),
                'approved_by' => request()->user()->id,
                'password' => Hash::make($temporaryPassword),
            ]);
            $account->assignRole($data['role']);

            return $account;
        });
        $audit->log('account.created', $account, ['role' => $data['role']]);

        return ApiResponse::success([
            'account' => Present::account($account->fresh('roles', 'member')),
            'temporary_password' => ! empty($data['password']) ? null : $temporaryPassword,
        ], status: 201);
    }

    public function show(User $account): JsonResponse
    {
        return ApiResponse::success(Present::account($account->load('roles', 'member')));
    }

    public function update(Request $request, User $account, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'login_id' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('users', 'login_id')->ignore($account->id)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($account->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'status' => ['sometimes', Rule::enum(UserStatus::class)],
            'role' => ['sometimes', Rule::in(['super_admin', 'operator', 'member'])],
        ]);

        abort_if($account->hasRole('member') && isset($data['login_id']) && $data['login_id'] !== $account->login_id, 409, 'ID pengguna anggota diubah dari menu Anggota.');
        abort_if($account->hasRole('member') && isset($data['role']) && $data['role'] !== 'member', 409, 'Role anggota tidak dapat diubah dari menu akun.');
        abort_if(! $account->hasRole('member') && isset($data['role']) && $data['role'] === 'member', 409, 'Gunakan menu Anggota untuk membuat akun anggota.');

        $before = Present::account($account->load('roles', 'member'));
        DB::transaction(function () use ($account, $data): void {
            $nextRole = $data['role'] ?? $account->getRoleNames()->first();
            $nextStatus = isset($data['status']) ? UserStatus::from($data['status']) : $account->status;
            $this->guardLastSuperAdmin($account, $nextRole, $nextStatus);

            $account->update(collect($data)->only(['login_id', 'name', 'email', 'phone', 'status'])->all());
            if (isset($data['role'])) {
                $account->syncRoles([$data['role']]);
            }
            if (isset($data['status']) && $data['status'] !== UserStatus::Active->value) {
                $account->tokens()->delete();
            }
        });
        $audit->log('account.updated', $account, [
            'before' => $before,
            'after' => Present::account($account->fresh('roles', 'member')),
        ]);

        return ApiResponse::success(Present::account($account->fresh('roles', 'member')));
    }

    public function resetPassword(User $account, AuditLogger $audit): JsonResponse
    {
        $temporaryPassword = Str::password(14);
        $account->update(['password' => Hash::make($temporaryPassword), 'must_change_password' => true]);
        $account->tokens()->delete();
        $audit->log('account.password_reset', $account);

        return ApiResponse::success(['temporary_password' => $temporaryPassword]);
    }

    public function toggleStatus(User $account, AuditLogger $audit): JsonResponse
    {
        $nextStatus = $account->status === UserStatus::Suspended ? UserStatus::Active : UserStatus::Suspended;
        $this->guardLastSuperAdmin($account, $account->getRoleNames()->first(), $nextStatus);
        $before = Present::account($account->load('roles', 'member'));
        $account->update(['status' => $nextStatus]);
        $account->tokens()->delete();
        $audit->log('account.status_changed', $account, [
            'before' => $before,
            'after' => Present::account($account->fresh('roles', 'member')),
        ]);

        return ApiResponse::success(Present::account($account->fresh('roles', 'member')));
    }

    private function guardLastSuperAdmin(User $account, ?string $nextRole, UserStatus $nextStatus): void
    {
        if (! $account->hasRole('super_admin')) {
            return;
        }

        $willRemainActiveSuperAdmin = $nextRole === 'super_admin' && $nextStatus === UserStatus::Active;
        if ($willRemainActiveSuperAdmin) {
            return;
        }

        $activeSuperAdmins = User::role('super_admin')->where('status', UserStatus::Active)->whereKeyNot($account->id)->count();
        abort_if($activeSuperAdmins < 1, 409, 'Minimal satu Super Admin aktif harus tersedia.');
    }
}
