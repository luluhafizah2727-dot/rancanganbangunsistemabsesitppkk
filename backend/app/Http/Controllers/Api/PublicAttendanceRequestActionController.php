<?php

namespace App\Http\Controllers\Api;

use App\Enums\AttendanceRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\AttendanceRequestActionToken;
use App\Services\AttendanceRequestNotificationService;
use App\Services\AttendanceRequestReviewerService;
use App\Services\AttendanceRequestService;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PublicAttendanceRequestActionController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $actionToken = $this->findToken($token);
        $request = $actionToken->attendanceRequest->load('member.user', 'reviewer');

        return ApiResponse::success([
            'token_valid' => $this->isUsable($actionToken),
            'expires_at' => $actionToken->expires_at?->toIso8601String(),
            'request' => Present::attendanceRequest($request),
            'reviewer_hint' => [
                'name' => $actionToken->user->name,
                'login_id' => $actionToken->user->login_id,
            ],
        ]);
    }

    public function confirm(
        Request $request,
        string $token,
        AttendanceRequestService $service,
        AttendanceRequestReviewerService $reviewers,
        AttendanceRequestNotificationService $notifications,
        AuditLogger $audit,
    ): JsonResponse {
        $data = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject'])],
            'code' => ['required', 'string', 'min:4', 'max:20'],
            'review_note' => [$request->input('action') === 'reject' ? 'required' : 'nullable', 'string', $request->input('action') === 'reject' ? 'min:5' : 'max:1000', 'max:1000'],
            'approved_check_in_at' => ['nullable', 'date'],
            'approved_check_out_at' => ['nullable', 'date', 'after_or_equal:approved_check_in_at'],
        ]);

        $processed = DB::transaction(function () use ($token, $data, $request, $service, $reviewers, $audit) {
            $actionToken = $this->findToken($token, true);
            abort_unless($this->isUsable($actionToken), 409, 'Link konfirmasi sudah kedaluwarsa, sudah dipakai, atau permohonan telah diproses.');
            abort_unless(Hash::check($data['code'], $actionToken->code_hash), 422, 'Kode konfirmasi tidak sesuai.');
            abort_unless($reviewers->canReview($actionToken->user), 403, 'User penerima kode tidak lagi berwenang meninjau permohonan.');

            $attendanceRequest = $actionToken->attendanceRequest;
            $review = [
                'review_note' => $data['review_note'] ?? null,
                'approved_check_in_at' => $data['approved_check_in_at'] ?? null,
                'approved_check_out_at' => $data['approved_check_out_at'] ?? null,
            ];
            $before = Present::attendanceRequest($attendanceRequest->load('member.user', 'reviewer'));
            $result = $data['action'] === 'approve'
                ? $service->approve($attendanceRequest, $review, $actionToken->user_id)
                : $service->reject($attendanceRequest, $review, $actionToken->user_id);

            $actionToken->update([
                'used_at' => now(),
                'used_action' => $data['action'],
                'used_ip' => $request->ip(),
                'used_user_agent' => $request->userAgent(),
            ]);
            $audit->logAs($actionToken->user_id, 'attendance_request.'.$data['action'].'d_public', $result, [
                'before' => $before,
                'after' => Present::attendanceRequest($result),
                'token_id' => $actionToken->id,
                'channel' => 'whatsapp_public_link',
                'ip_address' => $request->ip(),
            ]);

            return $result;
        });

        $notifications->notifyReviewed($processed);

        return ApiResponse::success(Present::attendanceRequest($processed));
    }

    private function findToken(string $token, bool $lock = false): AttendanceRequestActionToken
    {
        $query = AttendanceRequestActionToken::query()
            ->with('attendanceRequest.member.user', 'attendanceRequest.reviewer', 'user')
            ->where('token_hash', hash('sha256', $token));
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? throw new NotFoundHttpException('Link konfirmasi tidak ditemukan.');
    }

    private function isUsable(AttendanceRequestActionToken $token): bool
    {
        return ! $token->used_at
            && $token->expires_at?->isFuture()
            && $token->attendanceRequest->status === AttendanceRequestStatus::Pending;
    }
}
