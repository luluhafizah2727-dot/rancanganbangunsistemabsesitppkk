<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRequestActionToken extends Model
{
    protected $fillable = [
        'attendance_request_id',
        'user_id',
        'token_hash',
        'code_hash',
        'expires_at',
        'used_at',
        'used_action',
        'used_ip',
        'used_user_agent',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function attendanceRequest(): BelongsTo
    {
        return $this->belongsTo(AttendanceRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
