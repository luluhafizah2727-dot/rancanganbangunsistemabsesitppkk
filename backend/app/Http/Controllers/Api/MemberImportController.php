<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MemberImport;
use App\Services\AuditLogger;
use App\Services\MemberImportService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemberImportController extends Controller
{
    public function preview(Request $request, MemberImportService $service, AuditLogger $audit): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:5120']]);
        $result = $service->preview($request->file('file'), $request->user());
        $audit->log('member_import.previewed', $result['import']);

        return ApiResponse::success([
            'import_id' => $result['import']->public_id,
            'total_rows' => $result['import']->total_rows,
            'valid_rows' => $result['import']->valid_rows,
            'failed_rows' => $result['import']->failed_rows,
            'preview' => $result['preview'],
            'errors' => $result['errors'],
        ], status: 201);
    }

    public function confirm(MemberImport $memberImport, MemberImportService $service, AuditLogger $audit): JsonResponse
    {
        $result = $service->confirm($memberImport);
        $audit->log('member_import.completed', $memberImport, $result);

        return ApiResponse::success($result);
    }
}
