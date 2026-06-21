<?php

namespace App\Models;

use App\Enums\AttendancePhase;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceScan extends Model
{
    use HasPublicId;

    protected $fillable = [
        'member_id', 'attendance_day_id', 'attendance_device_id', 'phase', 'token_hash',
        'accepted', 'reason', 'ip_address', 'user_agent', 'scanned_at',
    ];

    protected function casts(): array
    {
        return [
            'phase' => AttendancePhase::class,
            'accepted' => 'boolean',
            'scanned_at' => 'datetime',
        ];
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(AttendanceDay::class, 'attendance_day_id');
    }
}
