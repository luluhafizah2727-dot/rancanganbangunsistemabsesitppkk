<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppNotificationSetting;
use App\Services\AttendanceRequestNotificationService;
use App\Services\AuditLogger;
use App\Services\WhatsAppGatewayClient;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class WhatsAppNotificationSettingController extends Controller
{
    public function show(): JsonResponse
    {
        return ApiResponse::success($this->present(WhatsAppNotificationSetting::current()));
    }

    public function update(Request $request, AuditLogger $audit, WhatsAppGatewayClient $gateway): JsonResponse
    {
        $setting = WhatsAppNotificationSetting::current();
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'send_url' => ['nullable', 'url', 'max:1000', Rule::requiredIf(fn () => $request->boolean('enabled') && ! $setting->configured() && ! filled($request->input('send_url')))],
            'status_url' => ['nullable', 'url', 'max:1000'],
            'auth_mode' => ['required', Rule::in(['none', 'basic', 'header', 'jwt'])],
            'auth_username' => ['nullable', 'string', 'max:255'],
            'auth_password' => ['nullable', 'string', 'max:255'],
            'auth_header_name' => ['nullable', 'string', 'max:100'],
            'auth_header_value' => ['nullable', 'string', 'max:1000'],
            'auth_bearer_token' => ['nullable', 'string', 'max:1000'],
            'footer' => ['nullable', 'string', 'max:100'],
            'public_base_url' => ['nullable', 'url', 'max:255', Rule::requiredIf(fn () => $request->boolean('enabled'))],
        ]);

        $updates = collect($data)->except(['send_url', 'status_url'])->all();
        if (filled($data['send_url'] ?? null)) {
            $updates['send_url'] = rtrim($data['send_url'], '/');
            $updates['status_url'] = filled($data['status_url'] ?? null)
                ? rtrim($data['status_url'], '/')
                : $gateway->deriveStatusUrl($data['send_url']);
        } elseif (filled($data['status_url'] ?? null)) {
            $updates['status_url'] = rtrim($data['status_url'], '/');
        }

        $before = $this->present($setting);
        $setting->update([
            ...$updates,
            'footer' => $data['footer'] ?: 'Absensi TP PKK Balangan',
            'public_base_url' => filled($data['public_base_url'] ?? null) ? rtrim($data['public_base_url'], '/') : null,
        ]);
        $audit->log('whatsapp_notification_settings.updated', $setting, [
            'before' => $before,
            'after' => $this->present($setting->fresh()),
        ]);

        return ApiResponse::success($this->present($setting->fresh()));
    }

    public function test(Request $request, AttendanceRequestNotificationService $notifications): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30', function (string $attribute, mixed $value, \Closure $fail) use ($notifications): void {
                $phone = $notifications->normalizePhone((string) $value);
                if (! $phone || ! preg_match('/^62\d{8,15}$/', $phone)) {
                    $fail('Nomor tujuan harus memakai format WhatsApp Indonesia yang valid, contoh 628123456789.');
                }
            }],
        ]);

        $delivery = $notifications->sendTest($data['phone']);

        return ApiResponse::success([
            'status' => $delivery->status,
            'message' => match ($delivery->status) {
                'sent' => 'Pesan test berhasil dikirim.',
                'skipped' => $delivery->error ?: 'Pesan test dilewati.',
                'failed' => $delivery->error ?: 'Pesan test gagal dikirim.',
                default => 'Pesan test diproses.',
            },
            'delivery' => [
                'event' => $delivery->event,
                'destination' => $delivery->destination,
                'response' => $delivery->response,
                'error' => $delivery->error,
            ],
        ]);
    }

    private function present(WhatsAppNotificationSetting $setting): array
    {
        return [
            'enabled' => $setting->enabled,
            'send_url' => null,
            'send_url_configured' => filled($setting->send_url),
            'send_url_preview' => $this->maskUrl($setting->send_url),
            'status_url' => null,
            'status_url_configured' => filled($setting->status_url),
            'status_url_preview' => $this->maskUrl($setting->status_url),
            'auth_mode' => $setting->auth_mode,
            'auth_username' => $setting->auth_username,
            'auth_password_configured' => filled($setting->auth_password),
            'auth_header_name' => $setting->auth_header_name,
            'auth_header_value_configured' => filled($setting->auth_header_value),
            'auth_bearer_token_configured' => filled($setting->auth_bearer_token),
            'footer' => $setting->footer,
            'public_base_url' => $setting->public_base_url,
            'configured' => $setting->configured(),
            'enabled_and_configured' => $setting->enabledAndConfigured(),
            'sensitive_fields' => Arr::where([
                'auth_password' => filled($setting->auth_password),
                'auth_header_value' => filled($setting->auth_header_value),
                'auth_bearer_token' => filled($setting->auth_bearer_token),
            ], fn (bool $configured) => $configured),
        ];
    }

    private function maskUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $parts = parse_url($url);
        $host = $parts['host'] ?? null;
        if (! $host) {
            return 'Endpoint tersimpan';
        }

        return ($parts['scheme'] ?? 'https').'://'.$host.'/ext/••••/wa'.(str_ends_with($url, '/status') ? '/status' : '');
    }
}
