<?php

namespace App\Models;

use App\Enums\MemberDeviceStatus;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberDevice extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'member_id',
        'label',
        'status',
        'credential_hash',
        'fingerprint_hash',
        'user_agent',
        'ip_address',
        'last_seen_at',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'revoked_at',
        'approved_key',
    ];

    protected function casts(): array
    {
        return [
            'status' => MemberDeviceStatus::class,
            'last_seen_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
