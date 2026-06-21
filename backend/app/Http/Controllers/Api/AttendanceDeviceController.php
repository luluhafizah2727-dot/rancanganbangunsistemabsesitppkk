<?php

namespace App\Http\Controllers\Api;

use App\Enums\AttendanceDeviceStatus;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\AttendanceDay;
use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceActivationCode;
use App\Services\AttendanceDeviceCredentialService;
use App\Services\AuditLogger;
use App\Services\DailyAttendanceService;
use App\Services\QrTokenService;
use App\Support\ApiResponse;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AttendanceDeviceController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(
            AttendanceDevice::query()->orderBy('name')->get()->map(fn (AttendanceDevice $device) => Present::device($device))->values(),
        );
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'alpha_dash:ascii', 'unique:attendance_devices,code'],
            'name' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'ip_allowlist' => ['nullable', 'array', 'max:20'],
            'ip_allowlist.*' => ['ip'],
        ]);
        $device = AttendanceDevice::query()->create([
            ...$data,
            'code' => Str::upper($data['code']),
            'status' => AttendanceDeviceStatus::Pending,
        ]);
        $audit->log('device.created', $device);

        return ApiResponse::success(Present::device($device), status: 201);
    }

    public function update(Request $request, AttendanceDevice $attendanceDevice, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['pending', 'active', 'inactive'])],
            'ip_allowlist' => ['nullable', 'array', 'max:20'],
            'ip_allowlist.*' => ['ip'],
        ]);
        abort_if($attendanceDevice->status === AttendanceDeviceStatus::Revoked, 409, 'Gawai yang sudah dicabut tidak dapat digunakan kembali.');
        abort_if(($data['status'] ?? null) === 'active' && ! $attendanceDevice->credential_hash, 422, 'Gawai harus diaktivasi sebelum dapat berstatus aktif.');
        $before = Present::device($attendanceDevice);
        $attendanceDevice->update($data);
        $audit->log('device.updated', $attendanceDevice, ['before' => $before, 'after' => Present::device($attendanceDevice->fresh())]);

        return ApiResponse::success(Present::device($attendanceDevice->fresh()));
    }

    public function activationCode(AttendanceDevice $attendanceDevice, Request $request, AuditLogger $audit): JsonResponse
    {
        abort_unless($attendanceDevice->status === AttendanceDeviceStatus::Pending && ! $attendanceDevice->credential_hash, 409, 'Gawai ini sudah diaktivasi atau tidak lagi tersedia.');
        $plain = Str::upper(Str::random(12));
        $code = DB::transaction(function () use ($attendanceDevice, $request, $plain): AttendanceDeviceActivationCode {
            AttendanceDeviceActivationCode::query()
                ->where('attendance_device_id', $attendanceDevice->id)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            return AttendanceDeviceActivationCode::query()->create([
                'attendance_device_id' => $attendanceDevice->id,
                'code_hash' => hash('sha256', $plain),
                'expires_at' => now()->addMinutes(15),
                'created_by' => $request->user()->id,
            ]);
        });
        $audit->log('device.activation_code_created', $attendanceDevice, ['expires_at' => $code->expires_at->toIso8601String()]);

        return ApiResponse::success(['activation_code' => $plain, 'expires_at' => $code->expires_at->toIso8601String()], status: 201);
    }

    public function activate(Request $request, AuditLogger $audit, AttendanceDeviceCredentialService $credentials): JsonResponse
    {
        abort_if($credentials->resolve($request), 409, 'Browser ini sudah terikat pada gawai aktif. Cabut gawai lama sebelum aktivasi baru.');
        $data = $request->validate([
            'activation_code' => ['required', 'string', 'size:12'],
            'fingerprint' => ['nullable', 'string', 'max:500'],
            'screen' => ['nullable', 'array'],
            'screen.width' => ['nullable', 'integer', 'min:320', 'max:10000'],
            'screen.height' => ['nullable', 'integer', 'min:320', 'max:10000'],
            'timezone' => ['nullable', 'timezone'],
        ]);
        $plainCode = Str::upper($data['activation_code']);

        [$device, $plainCredential] = DB::transaction(function () use ($data, $plainCode, $request): array {
            $activation = AttendanceDeviceActivationCode::query()
                ->where('code_hash', hash('sha256', $plainCode))
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();
            abort_unless($activation, 422, 'Kode aktivasi tidak valid atau kedaluwarsa.');
            $device = AttendanceDevice::query()->lockForUpdate()->findOrFail($activation->attendance_device_id);
            abort_unless($device->status === AttendanceDeviceStatus::Pending && ! $device->credential_hash, 409, 'Gawai ini sudah diaktivasi.');

            $plainCredential = bin2hex(random_bytes(32));
            $activation->update(['used_at' => now()]);
            $device->forceFill([
                'status' => AttendanceDeviceStatus::Active,
                'credential_hash' => hash('sha256', $plainCredential),
                'credential_rotated_at' => now(),
                'credential_expires_at' => now()->addDays(AttendanceDeviceCredentialService::COOKIE_DAYS),
                'fingerprint_hash' => isset($data['fingerprint']) ? hash('sha256', $data['fingerprint']) : null,
                'user_agent' => $request->userAgent(),
                'last_ip' => $request->ip(),
                'screen' => $data['screen'] ?? null,
                'timezone' => $data['timezone'] ?? null,
                'activated_at' => now(),
                'activated_by' => $activation->created_by,
                'last_seen_at' => now(),
            ])->save();

            return [$device, $plainCredential];
        });
        $audit->log('device.activated', $device);

        return ApiResponse::success(['registered' => true, 'device' => Present::device($device)], status: 201)
            ->withCookie($credentials->cookie($plainCredential));
    }

    public function revoke(AttendanceDevice $attendanceDevice, Request $request, AuditLogger $audit): JsonResponse
    {
        abort_if($attendanceDevice->status === AttendanceDeviceStatus::Revoked, 409, 'Gawai sudah dicabut.');
        $attendanceDevice->forceFill([
            'status' => AttendanceDeviceStatus::Revoked,
            'credential_hash' => null,
            'previous_credential_hash' => null,
            'previous_credential_expires_at' => null,
            'credential_expires_at' => now(),
            'revoked_at' => now(),
            'revoked_by' => $request->user()->id,
        ])->save();
        AttendanceDeviceActivationCode::query()->where('attendance_device_id', $attendanceDevice->id)->whereNull('used_at')->update(['used_at' => now()]);
        $audit->log('device.revoked', $attendanceDevice);

        return ApiResponse::success(Present::device($attendanceDevice));
    }

    public function context(Request $request, AttendanceDeviceCredentialService $credentials, QrTokenService $tokens, DailyAttendanceService $days): JsonResponse
    {
        $device = $credentials->resolve($request);
        if (! $device) {
            return ApiResponse::success(['registered' => false, 'server_time' => now()->toIso8601String()]);
        }
        $allowlist = $device->ip_allowlist ?? [];
        abort_if($allowlist !== [] && ! in_array($request->ip(), $allowlist, true), 403, 'Alamat IP gawai tidak diizinkan.');
        $device->forceFill(['last_seen_at' => now(), 'last_ip' => $request->ip()])->save();
        $day = $tokens->dayForDisplay();
        $phase = $days->phaseAt($day);
        $nextDay = $day->is_working_day && $day->check_out_closes_at?->isFuture() ? $day : $days->nextWorkingDay(now()->addDay());

        return ApiResponse::success([
            'registered' => true,
            'device' => Present::device($device),
            'qr' => $tokens->currentOrRotate($device),
            'attendance_day' => Present::day($day),
            'current_phase' => $phase,
            'next_working_day' => $nextDay ? Present::day($nextDay) : null,
            'attendance_summary' => $tokens->summaryFor($day),
            'recent_attendance' => $this->recentAttendance($day, $phase),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function currentQr(Request $request, QrTokenService $tokens): JsonResponse
    {
        $current = $tokens->currentOrRotate($request->attributes->get('attendance_device'));
        if (! $current) {
            return ApiResponse::error('Belum berada dalam waktu check-in atau check-out.', 'attendance_window_closed', 404);
        }

        return ApiResponse::success($current);
    }

    public function heartbeat(Request $request, AttendanceDeviceCredentialService $credentials): JsonResponse
    {
        /** @var AttendanceDevice $device */
        $device = $request->attributes->get('attendance_device');
        $device->update(['last_seen_at' => now(), 'last_ip' => $request->ip()]);
        $plain = (string) ($request->cookie(AttendanceDeviceCredentialService::COOKIE_NAME) ?: $request->cookie(AttendanceDeviceCredentialService::LEGACY_COOKIE_NAME));

        return ApiResponse::success(['server_time' => now()->toIso8601String()])->withCookie($credentials->renew($device, $plain));
    }

    private function recentAttendance(AttendanceDay $day, ?string $phase): array
    {
        $column = $phase === 'check_out' ? 'check_out_at' : 'check_in_at';

        return Attendance::query()->with('member.user')->where('attendance_day_id', $day->id)->whereNotNull($column)
            ->orderByDesc($column)->limit(5)->get()->map(fn (Attendance $attendance) => [
                'id' => $attendance->public_id,
                'member_name' => $this->maskName((string) $attendance->member->user->name),
                'position' => $attendance->member->position,
                'phase' => $phase ?? 'check_in',
                'recorded_at' => $attendance->{$column}?->toIso8601String(),
            ])->values()->all();
    }

    private function maskName(string $name): string
    {
        return collect(preg_split('/\s+/', trim($name)) ?: [])->filter()->map(
            fn (string $part, int $index) => $index === 0 ? $part : mb_substr($part, 0, 1).str_repeat('*', max(2, mb_strlen($part) - 1)),
        )->implode(' ');
    }
}
