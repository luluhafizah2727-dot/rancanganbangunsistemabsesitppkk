<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        $isMember = $user->hasRole('member');
        $data = $request->validate([
            'name' => $isMember ? ['prohibited'] : ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => $isMember ? ['nullable', 'string', 'max:1000'] : ['prohibited'],
        ]);

        DB::transaction(function () use ($data, $user, $isMember): void {
            $user->update(collect($data)->only(['name', 'email', 'phone'])->all());
            if ($isMember && $user->member && array_key_exists('address', $data)) {
                $user->member->update(['address' => $data['address']]);
            }
        });

        $audit->log('profile.updated', $user, ['fields' => array_keys($data)]);

        return ApiResponse::success(Present::user($user->fresh('member')));
    }

    public function avatar(Request $request, AuditLogger $audit): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $user = $request->user();
        $oldPath = $user->avatar_path;
        $path = $request->file('avatar')->storePublicly('avatars', 'public');
        $user->update(['avatar_path' => $path]);
        if ($oldPath) {
            Storage::disk('public')->delete($oldPath);
        }

        $audit->log('profile.avatar_updated', $user);

        return ApiResponse::success(Present::user($user->fresh('member')));
    }

    public function destroyAvatar(Request $request, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->update(['avatar_path' => null]);
            $audit->log('profile.avatar_removed', $user);
        }

        return ApiResponse::success(Present::user($user->fresh('member')));
    }
}
