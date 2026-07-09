<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDelivery extends Model
{
    protected $fillable = [
        'attendance_request_id',
        'recipient_user_id',
        'event',
        'channel',
        'destination',
        'status',
        'payload',
        'response',
        'error',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'response' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function attendanceRequest(): BelongsTo
    {
        return $this->belongsTo(AttendanceRequest::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
