<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
    public function log(string $action, ?Model $subject = null, array $metadata = []): AuditLog
    {
        return $this->logAs(Auth::id(), $action, $subject, $metadata);
    }

    public function logAs(?int $actorId, string $action, ?Model $subject = null, array $metadata = []): AuditLog
    {
        return AuditLog::query()->create([
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata === [] ? null : $metadata,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
        ]);
    }
}
