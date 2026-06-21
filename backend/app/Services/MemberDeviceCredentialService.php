<?php

namespace App\Services;

use App\Models\MemberDevice;
use App\Support\CookieSecurity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

class MemberDeviceCredentialService
{
    public const COOKIE_NAME = 'member_device_token';

    public const LIFETIME_DAYS = 400;

    public function resolve(Request $request): ?MemberDevice
    {
        $plain = $request->cookie(self::COOKIE_NAME);
        if (! is_string($plain) || $plain === '') {
            return null;
        }

        return MemberDevice::query()
            ->where('credential_hash', hash('sha256', $plain))
            ->with('member.user', 'reviewer')
            ->first();
    }

    public function issue(MemberDevice $device): SymfonyCookie
    {
        $plain = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $device->forceFill(['credential_hash' => hash('sha256', $plain)])->save();

        return Cookie::make(
            self::COOKIE_NAME,
            $plain,
            self::LIFETIME_DAYS * 24 * 60,
            '/',
            config('session.domain'),
            CookieSecurity::forRequest(request()),
            true,
            false,
            'Strict',
        );
    }
}
