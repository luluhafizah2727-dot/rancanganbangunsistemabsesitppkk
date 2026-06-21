<?php

namespace App\Models;

use App\Enums\AttendanceDeviceStatus;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceDevice extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'code', 'name', 'location', 'status', 'ip_allowlist', 'credential_hash',
        'previous_credential_hash', 'previous_credential_expires_at',
        'credential_rotated_at', 'credential_expires_at', 'fingerprint_hash',
        'user_agent', 'last_ip', 'screen', 'timezone', 'activated_at',
        'activated_by', 'last_seen_at', 'revoked_at', 'revoked_by',
    ];

    protected $hidden = ['credential_hash', 'previous_credential_hash'];

    protected function casts(): array
    {
        return [
            'status' => AttendanceDeviceStatus::class,
            'ip_allowlist' => 'array',
            'screen' => 'array',
            'activated_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
            'previous_credential_expires_at' => 'datetime',
            'credential_rotated_at' => 'datetime',
            'credential_expires_at' => 'datetime',
        ];
    }
}
