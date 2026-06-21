<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\Member;
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

class MemberController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $members = Member::query()
            ->with('user')
            ->when($request->string('search')->toString(), function ($query, string $search) {
                $query->where(function ($inner) use ($search): void {
                    Search::contains($inner, 'member_number', $search)
                        ->orWhereHas('user', fn ($user) => Search::contains($user, 'name', $search));
                });
            })
            ->when($request->string('status')->toString(), fn ($query, string $status) => $query->whereHas('user', fn ($user) => $user->where('status', $status)))
            ->latest()
            ->paginate(min($request->integer('per_page', 20), 100));

        return ApiResponse::success(
            collect($members->items())->map(fn (Member $member) => Present::member($member))->values(),
            ['current_page' => $members->currentPage(), 'last_page' => $members->lastPage(), 'total' => $members->total()],
            ['next' => $members->nextPageUrl(), 'prev' => $members->previousPageUrl()],
        );
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'member_number' => ['required', 'string', 'max:50', 'unique:users,login_id', 'unique:members,member_number'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'position' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:1000'],
        ]);

        $temporaryPassword = Str::password(14);
        $member = DB::transaction(function () use ($data, $temporaryPassword, $request): Member {
            $user = User::query()->create([
                'login_id' => $data['member_number'],
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'status' => UserStatus::Active,
                'registration_source' => 'admin',
                'must_change_password' => true,
                'approved_at' => now(),
                'approved_by' => $request->user()->id,
                'password' => Hash::make($temporaryPassword),
            ]);
            $user->assignRole('member');

            return Member::query()->create([
                'user_id' => $user->id,
                'member_number' => $data['member_number'],
                'position' => $data['position'] ?? null,
                'department' => $data['department'] ?? null,
                'address' => $data['address'] ?? null,
            ]);
        });

        $audit->log('member.created', $member);

        return ApiResponse::success([
            'member' => Present::member($member),
            'temporary_password' => $temporaryPassword,
        ], status: 201);
    }

    public function show(Member $member): JsonResponse
    {
        return ApiResponse::success(Present::member($member));
    }

    public function update(Request $request, Member $member, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'member_number' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('users', 'login_id')->ignore($member->user_id),
                Rule::unique('members', 'member_number')->ignore($member->id),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email,'.$member->user_id],
            'phone' => ['nullable', 'string', 'max:30'],
            'position' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($data, $member): void {
            $userData = collect($data)->only(['name', 'email', 'phone'])->all();
            if (array_key_exists('member_number', $data)) {
                $userData['login_id'] = $data['member_number'];
            }

            $member->user->update($userData);
            $member->update(collect($data)->only(['member_number', 'position', 'department', 'address'])->all());
        });
        $audit->log('member.updated', $member, ['fields' => array_keys($data)]);

        return ApiResponse::success(Present::member($member->fresh('user')));
    }

    public function approve(Member $member, Request $request, AuditLogger $audit): JsonResponse
    {
        abort_unless($member->user->status === UserStatus::Pending, 409, 'Akun tidak sedang menunggu persetujuan.');
        $member->user->update([
            'status' => UserStatus::Active,
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
        ]);
        $audit->log('member.approved', $member);

        return ApiResponse::success(Present::member($member->fresh('user')));
    }

    public function reject(Member $member, AuditLogger $audit): JsonResponse
    {
        abort_unless($member->user->status === UserStatus::Pending, 409, 'Akun tidak sedang menunggu persetujuan.');
        $member->user->update(['status' => UserStatus::Rejected]);
        $audit->log('member.rejected', $member);

        return ApiResponse::success(Present::member($member->fresh('user')));
    }

    public function toggleStatus(Member $member, AuditLogger $audit): JsonResponse
    {
        $status = $member->user->status === UserStatus::Suspended ? UserStatus::Active : UserStatus::Suspended;
        $member->user->update(['status' => $status]);
        $member->user->tokens()->delete();
        $audit->log('member.status_changed', $member, ['status' => $status->value]);

        return ApiResponse::success(Present::member($member->fresh('user')));
    }

    public function resetPassword(Member $member, AuditLogger $audit): JsonResponse
    {
        $temporaryPassword = Str::password(14);
        $member->user->update(['password' => Hash::make($temporaryPassword), 'must_change_password' => true]);
        $audit->log('member.password_reset', $member);

        return ApiResponse::success(['temporary_password' => $temporaryPassword]);
    }

    public function destroy(Member $member, AuditLogger $audit): JsonResponse
    {
        DB::transaction(function () use ($member): void {
            $member->user->update(['status' => UserStatus::Suspended]);
            $member->user->tokens()->delete();
            $member->delete();
        });
        $audit->log('member.archived', $member, [
            'member_number' => $member->member_number,
            'name' => $member->user->name,
        ]);

        return ApiResponse::success(['message' => 'Anggota berhasil dihapus dari daftar aktif.']);
    }
}
