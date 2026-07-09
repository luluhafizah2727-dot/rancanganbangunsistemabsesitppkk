<?php

namespace App\Services;

use App\Models\WhatsAppNotificationSetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WhatsAppGatewayClient
{
    public function send(WhatsAppNotificationSetting $setting, array $payload): array
    {
        $response = $this->request($setting)
            ->post($setting->send_url, $payload)
            ->throw();

        return $response->json() ?? ['body' => $response->body()];
    }

    public function status(WhatsAppNotificationSetting $setting): array
    {
        $url = $setting->status_url ?: $this->deriveStatusUrl((string) $setting->send_url);
        $response = $this->request($setting)
            ->get($url)
            ->throw();

        return $response->json() ?? ['body' => $response->body()];
    }

    public function deriveStatusUrl(string $sendUrl): string
    {
        $sendUrl = rtrim($sendUrl, '/');

        return Str::endsWith($sendUrl, '/wa') ? $sendUrl.'/status' : $sendUrl.'/status';
    }

    private function request(WhatsAppNotificationSetting $setting): PendingRequest
    {
        $request = Http::timeout(8)->acceptJson()->asJson();

        return match ($setting->auth_mode) {
            'basic' => $request->withBasicAuth((string) $setting->auth_username, (string) $setting->auth_password),
            'header' => $setting->auth_header_name && $setting->auth_header_value
                ? $request->withHeader($setting->auth_header_name, $setting->auth_header_value)
                : $request,
            'jwt' => $setting->auth_bearer_token
                ? $request->withToken($setting->auth_bearer_token)
                : $request,
            default => $request,
        };
    }
}
