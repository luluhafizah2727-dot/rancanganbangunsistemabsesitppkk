<?php

namespace App\Models;

use App\Enums\UserStatus;
use App\Models\Concerns\HasPublicId;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasPublicId, HasRoles, Notifiable;

    protected $fillable = [
        'login_id',
        'name',
        'email',
        'phone',
        'receive_wa_notifications',
        'avatar_path',
        'status',
        'registration_source',
        'must_change_password',
        'approved_at',
        'approved_by',
        'last_login_at',
        'password',
    ];

    protected $hidden = ['password', 'remember_token'];

    public function member(): HasOne
    {
        return $this->hasOne(Member::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'must_change_password' => 'boolean',
            'receive_wa_notifications' => 'boolean',
            'approved_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }
}
