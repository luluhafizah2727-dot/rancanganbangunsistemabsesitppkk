<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceException extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'attendance_date', 'is_working_day', 'check_in_time', 'check_in_before_minutes',
        'check_in_after_minutes', 'check_out_time', 'check_out_before_minutes',
        'check_out_after_minutes', 'note', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date:Y-m-d',
            'is_working_day' => 'boolean',
            'check_in_before_minutes' => 'integer',
            'check_in_after_minutes' => 'integer',
            'check_out_before_minutes' => 'integer',
            'check_out_after_minutes' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
