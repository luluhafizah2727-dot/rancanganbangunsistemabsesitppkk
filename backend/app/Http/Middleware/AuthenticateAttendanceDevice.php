<?php

namespace App\Http\Middleware;

use App\Services\AttendanceDeviceCredentialService;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAttendanceDevice
{
    public function __construct(private readonly AttendanceDeviceCredentialService $credentials) {}

    public function handle(Request $request, Closure $next): Response
    {
        $device = $this->credentials->resolve($request);
        if (! $device) {
            return ApiResponse::error('Gawai belum diaktivasi atau telah dicabut.', 'attendance_device_unauthorized', 401);
        }

        $allowlist = $device->ip_allowlist ?? [];
        if ($allowlist !== [] && ! in_array($request->ip(), $allowlist, true)) {
            return ApiResponse::error('Alamat IP gawai tidak diizinkan.', 'attendance_device_ip_denied', 403);
        }

        $request->attributes->set('attendance_device', $device);
        if (! $device->last_seen_at || $device->last_seen_at->lt(now()->subMinute())) {
            $device->forceFill(['last_seen_at' => now(), 'last_ip' => $request->ip()])->save();
        }

        return $next($request);
    }
}
