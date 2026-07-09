<?php

namespace App\Http\Controllers\Api;

use App\Enums\AttendanceRequestStatus;
use App\Enums\AttendanceRequestType;
use App\Http\Controllers\Controller;
use App\Models\AttendanceRequest;
use App\Services\AttendanceRequestNotificationService;
use App\Services\AttendanceRequestReviewerService;
use App\Services\AttendanceRequestService;
use App\Services\AuditLogger;
use App\Services\DailyAttendanceService;
use App\Support\ApiResponse;
use App\Support\Present;
use App\Support\Search;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceRequestController extends Controller
{
    public function memberIndex(Request $request): JsonResponse
    {
        $requests = AttendanceRequest::query()->with('member.user', 'reviewer')
            ->where('member_id', $request->user()->member->id)
            ->latest()->paginate(min($request->integer('per_page', 20), 100));

        return ApiResponse::success(
            collect($requests->items())->map(fn (AttendanceRequest $item) => Present::attendanceRequest($item))->values(),
            ['current_page' => $requests->currentPage(), 'last_page' => $requests->lastPage(), 'total' => $requests->total()],
        );
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $requests = AttendanceRequest::query()->with('member.user', 'reviewer')
            ->when($request->string('status')->toString(), fn ($query, string $status) => $query->where('status', $status))
            ->when($request->string('search')->toString(), function ($query, string $search): void {
                $query->whereHas('member', fn ($member) => $member
                    ->where(fn ($memberQuery) => Search::contains($memberQuery, 'member_number', $search))
                    ->orWhereHas('user', fn ($user) => Search::contains($user, 'name', $search)));
            })->latest()->paginate(min($request->integer('per_page', 50), 100));

        return ApiResponse::success(
            collect($requests->items())->map(fn (AttendanceRequest $item) => Present::attendanceRequest($item))->values(),
            ['current_page' => $requests->currentPage(), 'last_page' => $requests->lastPage(), 'total' => $requests->total()],
        );
    }

    public function show(Request $request, AttendanceRequest $attendanceRequest): JsonResponse
    {
        $this->authorizeAccess($request, $attendanceRequest);

        return ApiResponse::success(Present::attendanceRequest($attendanceRequest->load('member.user', 'reviewer')));
    }

    public function store(Request $request, DailyAttendanceService $days, AuditLogger $audit, AttendanceRequestNotificationService $notifications): JsonResponse
    {
        $member = $request->user()->member;
        abort_unless($member, 403, 'Profil anggota tidak ditemukan.');
        $data = $request->validate([
            'type' => ['required', new Enum(AttendanceRequestType::class)],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'proposed_check_in_at' => ['nullable', 'date'],
            'proposed_check_out_at' => ['nullable', 'date', 'after_or_equal:proposed_check_in_at'],
            'other_label' => ['nullable', 'string', 'max:100', Rule::requiredIf(fn () => $request->input('type') === 'other')],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'attachment' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
        ]);
        $type = AttendanceRequestType::from($data['type']);
        $from = CarbonImmutable::parse($data['date_from'], config('app.timezone'))->startOfDay();
        $to = CarbonImmutable::parse($data['date_to'], config('app.timezone'))->startOfDay();
        $this->validateWindow($type, $from, $to, $data, $days);

        $overlap = AttendanceRequest::query()->where('member_id', $member->id)
            ->where('status', AttendanceRequestStatus::Pending->value)
            ->whereDate('date_from', '<=', $to)->whereDate('date_to', '>=', $from)->exists();
        abort_if($overlap, 409, 'Sudah ada permohonan menunggu pada tanggal yang sama.');

        $item = DB::transaction(function () use ($request, $member, $data): AttendanceRequest {
            $attachment = $request->file('attachment');
            $path = $attachment?->store('attendance-requests/'.$member->public_id, 'local');

            return AttendanceRequest::query()->create([
                ...collect($data)->except('attachment')->all(),
                'member_id' => $member->id,
                'attachment_path' => $path,
                'attachment_name' => $attachment?->getClientOriginalName(),
                'attachment_mime' => $attachment?->getMimeType(),
                'attachment_size' => $attachment?->getSize(),
                'status' => AttendanceRequestStatus::Pending,
            ]);
        });
        $audit->log('attendance_request.submitted', $item, ['type' => $item->type->value, 'date_from' => $item->date_from->toDateString(), 'date_to' => $item->date_to->toDateString()]);
        $notifications->notifySubmitted($item);

        return ApiResponse::success(Present::attendanceRequest($item->load('member.user')), status: 201);
    }

    public function cancel(Request $request, AttendanceRequest $attendanceRequest, AuditLogger $audit): JsonResponse
    {
        abort_unless($attendanceRequest->member_id === $request->user()->member?->id, 404);
        abort_unless($attendanceRequest->status === AttendanceRequestStatus::Pending, 409, 'Hanya permohonan menunggu yang dapat dibatalkan.');
        $attendanceRequest->update(['status' => AttendanceRequestStatus::Cancelled, 'cancelled_at' => now()]);
        $audit->log('attendance_request.cancelled', $attendanceRequest);

        return ApiResponse::success(Present::attendanceRequest($attendanceRequest->fresh(['member.user'])));
    }

    public function approve(
        Request $request,
        AttendanceRequest $attendanceRequest,
        AttendanceRequestService $service,
        AttendanceRequestReviewerService $reviewers,
        AttendanceRequestNotificationService $notifications,
        AuditLogger $audit,
    ): JsonResponse
    {
        abort_unless($reviewers->canReview($request->user()), 403, 'Anda tidak berwenang meninjau permohonan.');
        $data = $request->validate([
            'review_note' => ['nullable', 'string', 'max:1000'],
            'approved_check_in_at' => ['nullable', 'date'],
            'approved_check_out_at' => ['nullable', 'date', 'after_or_equal:approved_check_in_at'],
        ]);
        $before = Present::attendanceRequest($attendanceRequest->load('member.user', 'reviewer'));
        $approved = $service->approve($attendanceRequest, $data, $request->user()->id);
        $audit->log('attendance_request.approved', $approved, ['before' => $before, 'after' => Present::attendanceRequest($approved)]);
        $notifications->notifyReviewed($approved);

        return ApiResponse::success(Present::attendanceRequest($approved));
    }

    public function reject(
        Request $request,
        AttendanceRequest $attendanceRequest,
        AttendanceRequestService $service,
        AttendanceRequestReviewerService $reviewers,
        AttendanceRequestNotificationService $notifications,
        AuditLogger $audit,
    ): JsonResponse
    {
        abort_unless($reviewers->canReview($request->user()), 403, 'Anda tidak berwenang meninjau permohonan.');
        $data = $request->validate(['review_note' => ['required', 'string', 'min:5', 'max:1000']]);
        $rejected = $service->reject($attendanceRequest, $data, $request->user()->id);
        $audit->log('attendance_request.rejected', $rejected, ['reason' => $data['review_note']]);
        $notifications->notifyReviewed($rejected);

        return ApiResponse::success(Present::attendanceRequest($rejected));
    }

    public function attachment(Request $request, AttendanceRequest $attendanceRequest): StreamedResponse
    {
        $this->authorizeAccess($request, $attendanceRequest);
        abort_unless($attendanceRequest->attachment_path && Storage::disk('local')->exists($attendanceRequest->attachment_path), 404, 'Lampiran tidak ditemukan.');

        return Storage::disk('local')->download($attendanceRequest->attachment_path, $attendanceRequest->attachment_name);
    }

    private function validateWindow(AttendanceRequestType $type, CarbonImmutable $from, CarbonImmutable $to, array $data, DailyAttendanceService $days): void
    {
        $today = CarbonImmutable::today(config('app.timezone'));
        if ($from->lt($today->subDays(30)) || $to->gt($today->addDays(90)) || $from->diffInDays($to) > 30) {
            throw ValidationException::withMessages(['date_from' => 'Tanggal harus berada dalam 30 hari terakhir hingga 90 hari ke depan, maksimal 31 hari.']);
        }
        if (! $type->isTimeCorrection()) {
            if ($type->supportsPartialAbsence() && ! empty($data['proposed_check_out_at'])) {
                if (! $from->equalTo($to)) {
                    throw ValidationException::withMessages(['date_to' => 'Izin/sakit/dinas sebagian hari hanya dapat diajukan untuk satu tanggal.']);
                }
                $partialStart = CarbonImmutable::parse($data['proposed_check_out_at'])->setTimezone(config('app.timezone'));
                if ($partialStart->toDateString() !== $from->toDateString()) {
                    throw ValidationException::withMessages(['proposed_check_out_at' => 'Waktu mulai izin/sakit/dinas harus berada pada tanggal permohonan.']);
                }
            }

            return;
        }
        if (! $from->equalTo($to) || $from->isFuture()) {
            throw ValidationException::withMessages(['date_to' => 'Koreksi waktu hanya dapat diajukan untuk satu tanggal hari ini atau sebelumnya.']);
        }
        if ($type === AttendanceRequestType::MissedCheckIn && empty($data['proposed_check_in_at'])) {
            throw ValidationException::withMessages(['proposed_check_in_at' => 'Waktu check-in yang diminta wajib diisi.']);
        }
        if ($type === AttendanceRequestType::MissedCheckOut && empty($data['proposed_check_out_at'])) {
            throw ValidationException::withMessages(['proposed_check_out_at' => 'Waktu check-out yang diminta wajib diisi.']);
        }
        if ($type === AttendanceRequestType::TimeCorrection && empty($data['proposed_check_in_at']) && empty($data['proposed_check_out_at'])) {
            throw ValidationException::withMessages(['proposed_check_in_at' => 'Isi minimal salah satu waktu yang ingin dikoreksi.']);
        }
        $day = $days->forDate($from);
        if (($type === AttendanceRequestType::MissedCheckIn && $day->check_in_closes_at?->isFuture())
            || ($type === AttendanceRequestType::MissedCheckOut && $day->check_out_closes_at?->isFuture())) {
            throw ValidationException::withMessages(['date_from' => 'Fase absensi terkait masih aktif atau belum dimulai.']);
        }
    }

    private function authorizeAccess(Request $request, AttendanceRequest $attendanceRequest): void
    {
        $isStaff = $request->user()->hasAnyRole(['super_admin', 'operator']);
        abort_unless($isStaff || $attendanceRequest->member_id === $request->user()->member?->id, 404);
    }
}
