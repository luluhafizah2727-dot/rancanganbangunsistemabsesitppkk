<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MemberDevice;
use App\Services\MemberDeviceBindingService;
use App\Support\ApiResponse;
use App\Support\Present;
use App\Support\Search;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MemberDeviceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = MemberDevice::query()->with('member.user', 'reviewer')->latest();

        if ($request->user()->hasRole('member')) {
            abort_unless($request->user()->member, 403, 'Profil anggota tidak ditemukan.');
            $query->where('member_id', $request->user()->member->id);
        } else {
            $query->when($request->string('status')->toString(), fn ($query, string $status) => $query->where('status', $status));
            $query->when($request->string('search')->toString(), function ($query, string $search): void {
                $query->whereHas('member', function ($member) use ($search): void {
                    Search::contains($member, 'member_number', $search)
                        ->orWhereHas('user', fn ($user) => Search::contains($user, 'name', $search));
                });
            });
        }

        $devices = $query->paginate(min($request->integer('per_page', 50), 100));
        $items = collect($devices->items());
        $counts = DB::table('member_devices')
            ->whereIn('member_id', $items->pluck('member_id')->unique()->values())
            ->selectRaw('member_id, status, count(*) as aggregate')
            ->groupBy('member_id', 'status')
            ->get()
            ->groupBy('member_id');

        return ApiResponse::success(
            $items->map(function (MemberDevice $device) use ($counts): array {
                $memberCounts = $counts->get($device->member_id, collect())->pluck('aggregate', 'status');

                return [
                    ...Present::memberDevice($device),
                    'counts' => [
                        'approved' => (int) ($memberCounts['approved'] ?? 0),
                        'pending' => (int) ($memberCounts['pending'] ?? 0),
                        'rejected' => (int) ($memberCounts['rejected'] ?? 0),
                        'revoked' => (int) ($memberCounts['revoked'] ?? 0),
                        'total' => (int) $memberCounts->sum(),
                    ],
                ];
            })->values(),
            ['current_page' => $devices->currentPage(), 'last_page' => $devices->lastPage(), 'total' => $devices->total()],
        );
    }

    public function current(Request $request, MemberDeviceBindingService $binding): JsonResponse
    {
        abort_unless($request->user()->member, 403, 'Profil anggota tidak ditemukan.');

        return ApiResponse::success($binding->context($request, $request->user()->member));
    }

    public function store(Request $request, MemberDeviceBindingService $binding): JsonResponse
    {
        abort_unless($request->user()->member, 403, 'Profil anggota tidak ditemukan.');
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:100'],
            'fingerprint' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = $binding->requestDevice($request, $request->user()->member, $data);
        $response = ApiResponse::success(Present::memberDevice($result['device']), status: 201);

        return $result['cookie'] ? $response->withCookie($result['cookie']) : $response;
    }

    public function approve(Request $request, MemberDevice $memberDevice, MemberDeviceBindingService $binding): JsonResponse
    {
        $data = $request->validate(['review_note' => ['nullable', 'string', 'max:500']]);

        return ApiResponse::success(Present::memberDevice($binding->approve($memberDevice, $request->user()->id, $data['review_note'] ?? null)));
    }

    public function reject(Request $request, MemberDevice $memberDevice, MemberDeviceBindingService $binding): JsonResponse
    {
        $data = $request->validate(['review_note' => ['required', 'string', 'min:5', 'max:500']]);

        return ApiResponse::success(Present::memberDevice($binding->reject($memberDevice, $request->user()->id, $data['review_note'])));
    }

    public function revoke(Request $request, MemberDevice $memberDevice, MemberDeviceBindingService $binding): JsonResponse
    {
        $data = $request->validate(['review_note' => ['required', 'string', 'min:5', 'max:500']]);

        return ApiResponse::success(Present::memberDevice($binding->revoke($memberDevice, $request->user()->id, $data['review_note'])));
    }
}
