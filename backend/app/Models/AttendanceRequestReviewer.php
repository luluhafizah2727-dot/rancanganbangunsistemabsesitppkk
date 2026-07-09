<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRequestReviewer extends Model
{
    protected $fillable = ['user_id', 'enabled_by', 'enabled_at'];

    protected function casts(): array
    {
        return [
            'enabled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function enabler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enabled_by');
    }
}
