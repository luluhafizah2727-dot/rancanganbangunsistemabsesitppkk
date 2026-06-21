<?php

namespace App\Services;

use App\Enums\MemberDeviceStatus;
use App\Models\AppSetting;
use App\Models\Member;
use App\Models\MemberDevice;
use App\Support\Present;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MemberDeviceBindingService
{
    public const SETTING_KEY = 'member_device_binding_mode';

    public const MODE_AUDIT = 'audit_only';

    public const MODE_APPROVAL = 'approval_required';

    public function __construct(
        private readonly MemberDeviceCredentialService $credentials,
        private readonly AuditLogger $audit,
    ) {}

    public function mode(): string
    {
        return AppSetting::valueFor(self::SETTING_KEY, self::MODE_APPROVAL) ?: self::MODE_APPROVAL;
    }

    public function context(Request $request, Member $member): array
    {
        $device = $this->currentForMember($request, $member);
        $mode = $this->mode();

        return [
            'mode' => $mode,
            'required' => $mode === self::MODE_APPROVAL,
            'can_scan' => $this->canScan($mode, $device),
            'device' => $device ? Present::memberDevice($device) : null,
            'message' => $this->messageFor($mode, $device),
        ];
    }

    public function requestDevice(Request $request, Member $member, array $data): array
    {
        $current = $this->currentForMember($request, $member);
        if ($current && $current->status !== MemberDeviceStatus::Revoked) {
            $current->forceFill([
                'label' => $data['label'] ?? $current->label,
                'fingerprint_hash' => $this->fingerprintHash($data['fingerprint'] ?? null) ?? $current->fingerprint_hash,
                'user_agent' => $request->userAgent(),
                'ip_address' => $request->ip(),
                'last_seen_at' => now(),
            ])->save();

            return ['device' => $current->fresh('member.user', 'reviewer'), 'cookie' => null];
        }

        $mode = $this->mode();
        $device = MemberDevice::query()->create([
            'member_id' => $member->id,
            'label' => $data['label'] ?? 'Perangkat anggota',
            'status' => $mode === self::MODE_AUDIT ? MemberDeviceStatus::Approved : MemberDeviceStatus::Pending,
            'approved_key' => $mode === self::MODE_AUDIT ? 1 : null,
            'fingerprint_hash' => $this->fingerprintHash($data['fingerprint'] ?? null),
            'user_agent' => $request->userAgent(),
            'ip_address' => $request->ip(),
            'last_seen_at' => now(),
            'reviewed_by' => $mode === self::MODE_AUDIT ? $request->user()->id : null,
            'reviewed_at' => $mode === self::MODE_AUDIT ? now() : null,
        ]);
        $cookie = $this->credentials->issue($device);
        $this->audit->log($mode === self::MODE_AUDIT ? 'member_device.auto_approved' : 'member_device.requested', $device, [
            'member_number' => $member->member_number,
        ]);

        return ['device' => $device->fresh('member.user', 'reviewer'), 'cookie' => $cookie];
    }

    public function authorizeScan(Request $request, Member $member): array
    {
        $mode = $this->mode();
        $device = $this->currentForMember($request, $member);

        if ($mode === self::MODE_AUDIT && ! $device) {
            $auditDevice = MemberDevice::query()->create([
                'member_id' => $member->id,
                'label' => 'Perangkat anggota',
                'status' => MemberDeviceStatus::Pending,
                'fingerprint_hash' => null,
                'user_agent' => $request->userAgent(),
                'ip_address' => $request->ip(),
                'last_seen_at' => now(),
            ]);
            $cookie = $this->credentials->issue($auditDevice);
            $this->audit->log('member_device.audit_recorded', $auditDevice, [
                'member_number' => $member->member_number,
            ]);

            return ['allowed' => true, 'cookie' => $cookie];
        }

        if ($this->canScan($mode, $device)) {
            $device?->forceFill(['last_seen_at' => now(), 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()])->save();

            return ['allowed' => true, 'cookie' => null];
        }

        $code = match ($device?->status) {
            MemberDeviceStatus::Pending => 'member_device_pending',
            MemberDeviceStatus::Rejected => 'member_device_rejected',
            MemberDeviceStatus::Revoked => 'member_device_revoked',
            default => 'member_device_required',
        };

        return [
            'allowed' => false,
            'code' => $code,
            'message' => $this->messageFor($mode, $device),
            'cookie' => null,
        ];
    }

    public function approve(MemberDevice $device, int $reviewerId, ?string $note = null): MemberDevice
    {
        return DB::transaction(function () use ($device, $reviewerId, $note): MemberDevice {
            $device = MemberDevice::query()->lockForUpdate()->findOrFail($device->id);
            abort_if($device->status !== MemberDeviceStatus::Pending, 409, 'Permohonan perangkat tidak sedang menunggu persetujuan.');

            $before = Present::memberDevice($device);
            $device->forceFill([
                'status' => MemberDeviceStatus::Approved,
                'approved_key' => 1,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();
            $this->audit->log('member_device.approved', $device, ['before' => $before, 'after' => Present::memberDevice($device->fresh())]);

            return $device->fresh('member.user', 'reviewer');
        });
    }

    public function reject(MemberDevice $device, int $reviewerId, string $note): MemberDevice
    {
        return DB::transaction(function () use ($device, $reviewerId, $note): MemberDevice {
            $device = MemberDevice::query()->lockForUpdate()->findOrFail($device->id);
            abort_if($device->status !== MemberDeviceStatus::Pending, 409, 'Permohonan perangkat tidak sedang menunggu persetujuan.');
            $before = Present::memberDevice($device);
            $device->forceFill([
                'status' => MemberDeviceStatus::Rejected,
                'approved_key' => null,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();
            $this->audit->log('member_device.rejected', $device, ['before' => $before, 'after' => Present::memberDevice($device->fresh())]);

            return $device->fresh('member.user', 'reviewer');
        });
    }

    public function revoke(MemberDevice $device, int $reviewerId, string $note): MemberDevice
    {
        return DB::transaction(function () use ($device, $reviewerId, $note): MemberDevice {
            $device = MemberDevice::query()->lockForUpdate()->findOrFail($device->id);
            abort_if($device->status === MemberDeviceStatus::Revoked, 409, 'Perangkat sudah dicabut.');
            $before = Present::memberDevice($device);
            $device->forceFill([
                'status' => MemberDeviceStatus::Revoked,
                'approved_key' => null,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
                'review_note' => $note,
                'revoked_at' => now(),
            ])->save();
            $this->audit->log('member_device.revoked', $device, ['before' => $before, 'after' => Present::memberDevice($device->fresh())]);

            return $device->fresh('member.user', 'reviewer');
        });
    }

    private function currentForMember(Request $request, Member $member): ?MemberDevice
    {
        $device = $this->credentials->resolve($request);

        return $device && $device->member_id === $member->id ? $device : null;
    }

    private function canScan(string $mode, ?MemberDevice $device): bool
    {
        if ($mode === self::MODE_AUDIT) {
            return ! in_array($device?->status, [MemberDeviceStatus::Rejected, MemberDeviceStatus::Revoked], true);
        }

        return $device?->status === MemberDeviceStatus::Approved;
    }

    private function messageFor(string $mode, ?MemberDevice $device): string
    {
        if ($mode === self::MODE_AUDIT) {
            return 'Perangkat dapat digunakan. Aktivitas perangkat tetap dicatat.';
        }

        return match ($device?->status) {
            MemberDeviceStatus::Approved => 'Perangkat ini sudah disetujui.',
            MemberDeviceStatus::Pending => 'Perangkat ini menunggu persetujuan admin.',
            MemberDeviceStatus::Rejected => 'Permohonan perangkat ini ditolak.',
            MemberDeviceStatus::Revoked => 'Perangkat ini sudah dicabut.',
            default => 'Ajukan perangkat ini sebelum memindai QR.',
        };
    }

    private function fingerprintHash(?string $fingerprint): ?string
    {
        return $fingerprint ? hash('sha256', $fingerprint) : null;
    }
}
