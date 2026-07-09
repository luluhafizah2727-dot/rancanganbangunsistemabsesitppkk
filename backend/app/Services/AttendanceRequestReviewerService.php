<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Models\AttendanceRequestReviewer;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AttendanceRequestReviewerService
{
    public function canReview(User $user): bool
    {
        if ($user->status !== UserStatus::Active) {
            return false;
        }

        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $user->hasRole('operator')
            && AttendanceRequestReviewer::query()->where('user_id', $user->id)->exists();
    }

    /**
     * @return Collection<int, User>
     */
    public function notificationRecipients(): Collection
    {
        $superAdmins = User::role('super_admin')
            ->where('status', UserStatus::Active)
            ->get()
            ->filter(fn (User $user) => $this->wantsWhatsApp($user));

        $operators = User::role('operator')
            ->where('status', UserStatus::Active)
            ->whereIn('id', AttendanceRequestReviewer::query()->select('user_id'))
            ->get()
            ->filter(fn (User $user) => $this->wantsWhatsApp($user));

        return $superAdmins->merge($operators)->unique('id')->values();
    }

    public function wantsWhatsApp(User $user): bool
    {
        if ($user->receive_wa_notifications !== null) {
            return (bool) $user->receive_wa_notifications;
        }

        return $user->hasRole('super_admin');
    }

    /**
     * @param array<int> $operatorIds
     */
    public function syncOperators(array $operatorIds, User $actor): void
    {
        $ids = User::role('operator')
            ->where('status', UserStatus::Active)
            ->whereIn('id', $operatorIds)
            ->pluck('id')
            ->all();

        DB::transaction(function () use ($ids, $actor): void {
            $stale = AttendanceRequestReviewer::query();
            if ($ids !== []) {
                $stale->whereNotIn('user_id', $ids);
            }
            $stale->delete();
            foreach ($ids as $id) {
                AttendanceRequestReviewer::query()->updateOrCreate(
                    ['user_id' => $id],
                    ['enabled_by' => $actor->id, 'enabled_at' => now()],
                );
            }
        });
    }
}
