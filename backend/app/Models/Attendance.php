<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Enums\CheckInStatus;
use App\Enums\CheckOutStatus;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Attendance extends Model
{
    use HasFactory, HasPublicId, SoftDeletes;

    protected $fillable = [
        'member_id', 'attendance_day_id', 'active_key', 'attendance_request_id', 'status', 'check_in_at', 'check_in_status',
        'check_in_device_id', 'check_out_at', 'check_out_status', 'check_out_device_id',
        'source', 'note', 'adjustment_reason', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => AttendanceStatus::class,
            'check_in_at' => 'datetime',
            'check_in_status' => CheckInStatus::class,
            'check_out_at' => 'datetime',
            'check_out_status' => CheckOutStatus::class,
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(AttendanceDay::class, 'attendance_day_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(AttendanceRequest::class, 'attendance_request_id');
    }

    public function checkInDevice(): BelongsTo
    {
        return $this->belongsTo(AttendanceDevice::class, 'check_in_device_id');
    }

    public function checkOutDevice(): BelongsTo
    {
        return $this->belongsTo(AttendanceDevice::class, 'check_out_device_id');
    }

    protected static function booted(): void
    {
        static::deleting(function (Attendance $attendance): void {
            if (! $attendance->isForceDeleting()) {
                $attendance->forceFill(['active_key' => null])->saveQuietly();
            }
        });

        static::restoring(function (Attendance $attendance): void {
            $attendance->active_key = 1;
        });
    }
}
