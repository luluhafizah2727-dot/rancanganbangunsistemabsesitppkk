<?php

namespace App\Models;

use App\Enums\AttendanceRequestStatus;
use App\Enums\AttendanceRequestType;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRequest extends Model
{
    use HasPublicId;

    protected $fillable = [
        'member_id', 'type', 'date_from', 'date_to', 'proposed_check_in_at',
        'proposed_check_out_at', 'other_label', 'reason', 'attachment_path',
        'attachment_name', 'attachment_mime', 'attachment_size', 'status',
        'reviewed_by', 'review_note', 'approved_check_in_at',
        'approved_check_out_at', 'reviewed_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => AttendanceRequestType::class,
            'status' => AttendanceRequestStatus::class,
            'date_from' => 'date',
            'date_to' => 'date',
            'proposed_check_in_at' => 'datetime',
            'proposed_check_out_at' => 'datetime',
            'approved_check_in_at' => 'datetime',
            'approved_check_out_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'cancelled_at' => 'datetime',
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
