<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\AttendanceRequestReviewer;
use App\Models\User;
use App\Services\AttendanceRequestReviewerService;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceRequestReviewerController extends Controller
{
    public function index(Request $request, AttendanceRequestReviewerService $reviewers): JsonResponse
    {
        $authorized = AttendanceRequestReviewer::query()->pluck('user_id')->all();
        $operators = User::role('operator')
            ->orderBy('name')
            ->get();

        return ApiResponse::success([
            'can_review' => $reviewers->canReview($request->user()),
            'operators' => $operators->map(fn (User $user) => [
                'id' => $user->public_id,
                'login_id' => $user->login_id,
                'name' => $user->name,
                'phone' => $user->phone,
                'status' => $user->status->value,
                'receive_wa_notifications' => $reviewers->wantsWhatsApp($user),
                'authorized' => in_array($user->id, $authorized, true),
                'can_be_authorized' => $user->status === UserStatus::Active,
            ])->values(),
        ]);
    }

    public function update(Request $request, AttendanceRequestReviewerService $reviewers, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'operator_ids' => ['array'],
            'operator_ids.*' => ['string', 'exists:users,public_id'],
        ]);
        $ids = User::role('operator')
            ->whereIn('public_id', $data['operator_ids'] ?? [])
            ->pluck('id')
            ->all();

        $before = AttendanceRequestReviewer::query()->pluck('user_id')->all();
        $reviewers->syncOperators($ids, $request->user());
        $after = AttendanceRequestReviewer::query()->pluck('user_id')->all();
        $audit->log('attendance_request_reviewers.updated', null, ['before' => $before, 'after' => $after]);

        return $this->index($request, $reviewers);
    }
}
