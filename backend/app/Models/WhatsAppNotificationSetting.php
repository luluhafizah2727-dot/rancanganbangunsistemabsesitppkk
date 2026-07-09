<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppNotificationSetting extends Model
{
    protected $table = 'whatsapp_notification_settings';

    protected $fillable = [
        'enabled',
        'send_url',
        'status_url',
        'auth_mode',
        'auth_username',
        'auth_password',
        'auth_header_name',
        'auth_header_value',
        'auth_bearer_token',
        'footer',
        'public_base_url',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'send_url' => 'encrypted',
            'status_url' => 'encrypted',
            'auth_username' => 'encrypted',
            'auth_password' => 'encrypted',
            'auth_header_value' => 'encrypted',
            'auth_bearer_token' => 'encrypted',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'enabled' => false,
            'auth_mode' => 'none',
            'footer' => 'Absensi TP PKK Balangan',
        ]);
    }

    public function configured(): bool
    {
        return filled($this->send_url);
    }

    public function enabledAndConfigured(): bool
    {
        return $this->enabled && $this->configured() && filled($this->public_base_url);
    }
}
