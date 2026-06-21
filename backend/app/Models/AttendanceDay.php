<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceDay extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'attendance_date', 'is_working_day', 'source', 'check_in_target_at',
        'check_in_opens_at', 'check_in_closes_at', 'check_out_target_at',
        'check_out_opens_at', 'check_out_closes_at', 'status', 'note',
        'schedule_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date:Y-m-d',
            'is_working_day' => 'boolean',
            'check_in_target_at' => 'datetime',
            'check_in_opens_at' => 'datetime',
            'check_in_closes_at' => 'datetime',
            'check_out_target_at' => 'datetime',
            'check_out_opens_at' => 'datetime',
            'check_out_closes_at' => 'datetime',
            'schedule_snapshot' => 'array',
        ];
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function scans(): HasMany
    {
        return $this->hasMany(AttendanceScan::class);
    }
}
