<?php

namespace App\Http\Controllers\Api\Delivery;

use App\Http\Controllers\Controller;
use App\Services\Delivery\DeliveryService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\Request;

/**
 * Driver app. POD bodies may carry a base64 signature / photo; the request size ceiling is PHP's post_max_size
 * (8M, the same limit the reference API used for these routes).
 */
class DeliveryController extends Controller
{
    private const GPS = ['gps' => 'nullable|array', 'gps.lat' => 'required_with:gps|numeric', 'gps.lng' => 'required_with:gps|numeric', 'gps.accuracy' => 'nullable|numeric'];

    private const POD = self::GPS + [
        'receiverName' => 'required|string|min:1',
        'notes' => 'nullable|string',
        'gpsStatus' => 'nullable|in:captured,denied,unavailable',
        'signature' => 'nullable|string',
        'photo' => 'nullable|string',
    ];

    public function __construct(private readonly DeliveryService $delivery) {}

    public function myTrips(Request $request): array
    {
        $driver = $request->query('driver');

        return $this->delivery->myTrips(AuthUser::current(), is_string($driver) && $driver !== '' ? $driver : null);
    }

    public function trip(string $id): array
    {
        return $this->delivery->trip(AuthUser::current(), $id);
    }

    public function start(string $id): array
    {
        return $this->delivery->start(AuthUser::current(), $id);
    }

    public function arrive(Request $request, string $id): array
    {
        $data = $request->validate(self::GPS);

        return $this->delivery->arrive(AuthUser::current(), $id, $data['gps'] ?? null);
    }

    public function deliver(Request $request, string $id): array
    {
        return $this->delivery->deliver(AuthUser::current(), $id, $request->validate(self::POD));
    }

    public function partial(Request $request, string $id): array
    {
        $data = $request->validate(self::POD + ['deliveredQty' => 'required|integer|min:1']);
        $data['deliveredQty'] = (int) $data['deliveredQty'];

        return $this->delivery->partial(AuthUser::current(), $id, $data);
    }

    public function fail(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'required|in:closed,noavail,wrongaddr,rejected,payment,product,other', 'notes' => 'nullable|string']);

        return $this->delivery->fail(AuthUser::current(), $id, $data);
    }

    public function pods(Request $request): array
    {
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);

        return $this->delivery->pods(Paging::from($request), $request->only(['trip', 'fo', 'from', 'to']));
    }

    public function pod(string $id)
    {
        return $this->delivery->pod($id);
    }
}
