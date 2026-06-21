<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Services\AuditLogger;
use App\Services\MemberDeviceBindingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SecuritySettingController extends Controller
{
    public function memberDeviceBinding(): JsonResponse
    {
        return ApiResponse::success([
            'mode' => AppSetting::valueFor(MemberDeviceBindingService::SETTING_KEY, MemberDeviceBindingService::MODE_APPROVAL),
        ]);
    }

    public function updateMemberDeviceBinding(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in([MemberDeviceBindingService::MODE_AUDIT, MemberDeviceBindingService::MODE_APPROVAL])],
        ]);
        $before = AppSetting::valueFor(MemberDeviceBindingService::SETTING_KEY, MemberDeviceBindingService::MODE_APPROVAL);
        AppSetting::setValue(MemberDeviceBindingService::SETTING_KEY, $data['mode']);
        $audit->log('security.member_device_binding_updated', $request->user(), ['before' => $before, 'after' => $data['mode']]);

        return ApiResponse::success(['mode' => $data['mode']]);
    }
}
