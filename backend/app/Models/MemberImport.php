<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class MemberImport extends Model
{
    use HasPublicId;

    protected $fillable = [
        'created_by', 'original_name', 'path', 'status', 'total_rows',
        'valid_rows', 'failed_rows', 'errors', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return ['errors' => 'array', 'confirmed_at' => 'datetime'];
    }
}
