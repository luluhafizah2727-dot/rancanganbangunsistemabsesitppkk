<?php

namespace App\Services;

use App\Enums\AttendanceDeviceStatus;
use App\Models\AttendanceDevice;
use Illuminate\Cookie\CookieJar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Cookie;

class AttendanceDeviceCredentialService
{
    public const COOKIE_DAYS = 400;

    public const ROTATE_AFTER_DAYS = 30;

    public const PREVIOUS_GRACE_MINUTES = 5;

    public const COOKIE_NAME = 'attendance_device_token';

    public const LEGACY_COOKIE_NAME = 'kiosk_device_token';

    public function resolve(Request $request): ?AttendanceDevice
    {
        $credential = $request->cookie(self::COOKIE_NAME) ?: $request->cookie(self::LEGACY_COOKIE_NAME);
        if (! is_string($credential) || $credential === '') {
            return null;
        }

        $hash = hash('sha256', $credential);

        return AttendanceDevice::query()
            ->where('status', AttendanceDeviceStatus::Active->value)
            ->where(function ($query) use ($hash): void {
                $query->where(function ($current) use ($hash): void {
                    $current->where('credential_hash', $hash)
                        ->where(fn ($expiry) => $expiry->whereNull('credential_expires_at')->orWhere('credential_expires_at', '>', now()));
                })->orWhere(function ($previous) use ($hash): void {
                    $previous->where('previous_credential_hash', $hash)
                        ->where('previous_credential_expires_at', '>', now());
                });
            })
            ->first();
    }

    public function issue(AttendanceDevice $device): array
    {
        $plain = bin2hex(random_bytes(32));
        $device->forceFill([
            'credential_hash' => hash('sha256', $plain),
            'previous_credential_hash' => null,
            'previous_credential_expires_at' => null,
            'credential_rotated_at' => now(),
            'credential_expires_at' => now()->addDays(self::COOKIE_DAYS),
        ])->save();

        return [$plain, $this->cookie($plain)];
    }

    public function renew(AttendanceDevice $device, string $currentPlain): Cookie
    {
        $usingPrevious = ! $device->credential_hash || ! hash_equals($device->credential_hash, hash('sha256', $currentPlain));
        if ($usingPrevious || ! $device->credential_rotated_at || $device->credential_rotated_at->lte(now()->subDays(self::ROTATE_AFTER_DAYS))) {
            return DB::transaction(function () use ($device): Cookie {
                $locked = AttendanceDevice::query()->lockForUpdate()->findOrFail($device->id);
                $newPlain = bin2hex(random_bytes(32));
                $locked->forceFill([
                    'previous_credential_hash' => $locked->credential_hash,
                    'previous_credential_expires_at' => now()->addMinutes(self::PREVIOUS_GRACE_MINUTES),
                    'credential_hash' => hash('sha256', $newPlain),
                    'credential_rotated_at' => now(),
                    'credential_expires_at' => now()->addDays(self::COOKIE_DAYS),
                ])->save();

                return $this->cookie($newPlain);
            });
        }

        $device->forceFill(['credential_expires_at' => now()->addDays(self::COOKIE_DAYS)])->save();

        return $this->cookie($currentPlain);
    }

    public function cookie(string $plain): Cookie
    {
        return app(CookieJar::class)->make(
            self::COOKIE_NAME,
            $plain,
            60 * 24 * self::COOKIE_DAYS,
            '/',
            config('session.domain'),
            (bool) config('session.secure'),
            true,
            false,
            'strict',
        );
    }
}
