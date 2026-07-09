<?php

namespace App\Services;

use App\Enums\AttendanceRequestStatus;
use App\Models\AttendanceRequest;
use App\Models\AttendanceRequestActionToken;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Models\WhatsAppNotificationSetting;
use App\Support\Present;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

class AttendanceRequestNotificationService
{
    public function __construct(
        private readonly AttendanceRequestReviewerService $reviewers,
        private readonly WhatsAppGatewayClient $gateway,
    ) {}

    public function notifySubmitted(AttendanceRequest $request): void
    {
        $request = $request->fresh(['member.user', 'reviewer']) ?? $request;
        $this->sendApplicantMessage($request, 'attendance_request.submitted.member', $this->memberSubmittedText($request));

        foreach ($this->reviewers->notificationRecipients() as $recipient) {
            $action = $this->createActionToken($request, $recipient);
            $this->sendReviewerMessage($request, $recipient, $action);
        }
    }

    public function notifyReviewed(AttendanceRequest $request): void
    {
        $request = $request->fresh(['member.user', 'reviewer']) ?? $request;
        $this->sendApplicantMessage($request, 'attendance_request.reviewed.member', $this->memberReviewedText($request));
    }

    public function sendTest(string $phone): NotificationDelivery
    {
        $setting = WhatsAppNotificationSetting::current();
        $payload = [
            'action' => 'send',
            'to' => $this->normalizePhone($phone),
            'interactive' => [
                'type' => 'template',
                'text' => "Tes notifikasi WhatsApp Absensi TP PKK Balangan.\n\nJika pesan ini diterima, gateway sudah dapat dipakai.",
                'footer' => $setting->footer ?: 'Absensi TP PKK Balangan',
                'buttons' => [
                    ['type' => 'quick', 'text' => 'Tes diterima', 'id' => 'wa_test_ok'],
                ],
            ],
        ];

        return $this->sendPayload('whatsapp.test', null, null, $payload['to'], $payload, ignoreEnabled: true);
    }

    public function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '0')) {
            return '62'.substr($digits, 1);
        }

        if (str_starts_with($digits, '8')) {
            return '62'.$digits;
        }

        return str_starts_with($digits, '62') ? $digits : null;
    }

    private function sendApplicantMessage(AttendanceRequest $request, string $event, string $text): void
    {
        $user = $request->member->user;
        if (! $user) {
            return;
        }

        $phone = $this->normalizePhone($user->phone);
        $payload = ['action' => 'send', 'to' => $phone, 'text' => $text];
        $this->sendPayload($event, $request, $user, $phone, $payload);
    }

    private function sendReviewerMessage(AttendanceRequest $request, User $recipient, array $action): void
    {
        $phone = $this->normalizePhone($recipient->phone);
        $payload = [
            'action' => 'send',
            'to' => $phone,
            'interactive' => [
                'type' => 'template',
                'text' => $this->reviewerText($request, $recipient, $action['expires_at']),
                'footer' => WhatsAppNotificationSetting::current()->footer ?: 'Absensi TP PKK Balangan',
                'buttons' => [
                    ['type' => 'url', 'text' => 'Setujui', 'url' => $action['approve_url']],
                    ['type' => 'url', 'text' => 'Tolak', 'url' => $action['reject_url']],
                    ['type' => 'copy', 'text' => 'Copy kode', 'copyCode' => $action['code']],
                ],
            ],
        ];

        $this->sendPayload('attendance_request.submitted.reviewer', $request, $recipient, $phone, $payload);
    }

    private function createActionToken(AttendanceRequest $request, User $recipient): array
    {
        $plainToken = Str::random(48);
        $code = (string) random_int(100000, 999999);
        $expiresAt = now()->addMinutes(60);

        AttendanceRequestActionToken::query()->create([
            'attendance_request_id' => $request->id,
            'user_id' => $recipient->id,
            'token_hash' => hash('sha256', $plainToken),
            'code_hash' => Hash::make($code),
            'expires_at' => $expiresAt,
        ]);

        $base = rtrim((string) WhatsAppNotificationSetting::current()->public_base_url, '/');

        return [
            'token' => $plainToken,
            'code' => $code,
            'approve_url' => $base.'/public/attendance-requests/'.$plainToken.'?action=approve',
            'reject_url' => $base.'/public/attendance-requests/'.$plainToken.'?action=reject',
            'expires_at' => $expiresAt,
        ];
    }

    private function sendPayload(string $event, ?AttendanceRequest $request, ?User $recipient, ?string $phone, array $payload, bool $ignoreEnabled = false): NotificationDelivery
    {
        $setting = WhatsAppNotificationSetting::current();
        $delivery = NotificationDelivery::query()->create([
            'attendance_request_id' => $request?->id,
            'recipient_user_id' => $recipient?->id,
            'event' => $event,
            'channel' => 'whatsapp',
            'destination' => $phone,
            'status' => 'queued',
            'payload' => $payload,
        ]);

        if (! $ignoreEnabled && ! $setting->enabled) {
            $delivery->update(['status' => 'skipped', 'error' => 'Notifikasi WhatsApp nonaktif.']);

            return $delivery->fresh();
        }

        if (! $setting->configured()) {
            $delivery->update(['status' => 'skipped', 'error' => 'Endpoint WhatsApp belum dikonfigurasi.']);

            return $delivery->fresh();
        }

        if (! $phone) {
            $delivery->update(['status' => 'skipped', 'error' => 'Nomor WhatsApp penerima belum valid.']);

            return $delivery->fresh();
        }

        try {
            $response = $this->gateway->send($setting, $payload);
            $delivery->update(['status' => 'sent', 'response' => $response, 'sent_at' => now()]);
        } catch (Throwable $throwable) {
            report($throwable);
            $delivery->update(['status' => 'failed', 'error' => $throwable->getMessage()]);
        }

        return $delivery->fresh();
    }

    private function memberSubmittedText(AttendanceRequest $request): string
    {
        return "Permohonan absensi Anda sudah diterima dan menunggu review.\n\n".$this->requestSummary($request);
    }

    private function memberReviewedText(AttendanceRequest $request): string
    {
        $status = $request->status === AttendanceRequestStatus::Approved ? 'DISETUJUI' : 'DITOLAK';
        $reviewer = $request->reviewer?->name ?: 'Admin';
        $note = $request->review_note ? "\nCatatan: {$request->review_note}" : '';

        return "Permohonan absensi Anda {$status} oleh {$reviewer}.\n\n".$this->requestSummary($request).$note;
    }

    private function reviewerText(AttendanceRequest $request, User $recipient, CarbonInterface $expiresAt): string
    {
        $expiry = $expiresAt->timezone(config('app.timezone'))->format('H.i');

        return "Ada permohonan absensi yang perlu ditindaklanjuti.\n\n"
            .$this->requestSummary($request)
            ."\n\nPenerima: {$recipient->name}"
            ."\nKode berlaku sampai {$expiry} WITA. Buka link approve/reject lalu tempel kode konfirmasi.";
    }

    private function requestSummary(AttendanceRequest $request): string
    {
        $payload = Present::attendanceRequest($request);
        $member = $payload['member']['user']['name'] ?? 'Anggota';
        $number = $payload['member']['member_number'] ?? '-';
        $type = $this->typeLabel($payload['type'], $payload['other_label'] ?? null);
        $date = $payload['date_from'] === $payload['date_to'] ? $payload['date_from'] : $payload['date_from'].' s.d. '.$payload['date_to'];
        $reason = Str::limit((string) $payload['reason'], 180);
        $attendance = $payload['attendance_context']['presence_summary']['label']
            ?? $this->attendanceContextLabel($payload['attendance_context'] ?? null);
        $attachment = $payload['has_attachment'] ? "\nLampiran: ada" : '';

        return "Anggota: {$member} ({$number})\nJenis: {$type}\nTanggal: {$date}\nAlasan: {$reason}"
            .($attendance ? "\nKonteks: {$attendance}" : '')
            .$attachment;
    }

    private function attendanceContextLabel(?array $context): ?string
    {
        if (! $context) {
            return null;
        }

        if (! empty($context['check_in_at']) && empty($context['check_out_at'])) {
            return 'Sudah check-in, belum checkout';
        }

        if (! empty($context['check_in_at']) && ! empty($context['check_out_at'])) {
            return 'Sudah check-in dan checkout';
        }

        return null;
    }

    private function typeLabel(string $type, ?string $otherLabel): string
    {
        return match ($type) {
            'missed_check_in' => 'Check-in terlewat',
            'missed_check_out' => 'Check-out terlewat',
            'time_correction' => 'Koreksi waktu',
            'permission' => 'Izin',
            'leave' => 'Cuti',
            'sick' => 'Sakit',
            'official_duty' => 'Dinas',
            'other' => $otherLabel ? 'Lainnya: '.$otherLabel : 'Lainnya',
            default => $type,
        };
    }
}
