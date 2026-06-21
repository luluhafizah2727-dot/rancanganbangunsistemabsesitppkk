<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\ApiResponse;
use App\Support\Search;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $logs = AuditLog::query()
            ->with('actor')
            ->when($request->string('action')->toString(), fn ($query, string $action) => Search::contains($query, 'action', $action))
            ->latest('created_at')
            ->paginate(min($request->integer('per_page', 30), 100));

        return ApiResponse::success(
            collect($logs->items())->map(fn (AuditLog $log) => [
                'id' => $log->public_id,
                'action' => $log->action,
                'actor' => $log->actor?->name ?? 'Sistem',
                'metadata' => $log->metadata ?? (object) [],
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at->toIso8601String(),
            ])->values(),
            ['current_page' => $logs->currentPage(), 'last_page' => $logs->lastPage(), 'total' => $logs->total()],
        );
    }
}
