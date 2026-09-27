<?php

namespace App\Http\Controllers\Api\Delivery;

use App\Http\Controllers\Controller;
use App\Services\Delivery\PhoneTrackingService;
use App\Support\AuthUser;
use Illuminate\Http\Request;

/** Driver phone tracking — called by the native driver app only. */
class PhoneTrackingController extends Controller
{
    public function __construct(private readonly PhoneTrackingService $tracking) {}

    public function state(): array
    {
        return $this->tracking->state(AuthUser::current());
    }

    public function consent(Request $request): array
    {
        $data = $request->validate(['accepted' => 'required|boolean']);

        return $this->tracking->consent(AuthUser::current(), (bool) $data['accepted']);
    }

    public function points(Request $request): array
    {
        $data = $request->validate([
            'platform' => 'nullable|in:android,ios,web',
            'points' => 'required|array|min:1|max:'.PhoneTrackingService::MAX_BATCH,
            'points.*.lat' => 'required|numeric', 'points.*.lng' => 'required|numeric', 'points.*.at' => 'required',
            'points.*.accuracy' => 'nullable|numeric', 'points.*.speed' => 'nullable|numeric', 'points.*.heading' => 'nullable|numeric',
        ]);

        return $this->tracking->ingest(AuthUser::current(), $data['points'], $data['platform'] ?? null);
    }
}
