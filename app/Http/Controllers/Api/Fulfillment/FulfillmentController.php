<?php

namespace App\Http\Controllers\Api\Fulfillment;

use App\Http\Controllers\Controller;
use App\Services\Fulfillment\FulfillmentService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\Request;

class FulfillmentController extends Controller
{
    public function __construct(private readonly FulfillmentService $fulfillment) {}

    public function index(Request $request): array
    {
        return $this->fulfillment->list(Paging::from($request), $request->only(['status', 'warehouse', 'trip', 'customer']));
    }

    public function show(string $id): array
    {
        return $this->fulfillment->get($id);
    }

    public function pickLists(Request $request): array
    {
        return $this->fulfillment->pickLists(Paging::from($request), $request->only(['warehouse', 'status']));
    }

    public function pick(Request $request, string $id): array
    {
        $data = $request->validate(['scannedBin' => 'required|string|min:1', 'scannedProduct' => 'required|string|min:1', 'qty' => 'sometimes|integer|min:1']);

        return $this->fulfillment->pick(AuthUser::current(), $id, $data);
    }

    public function short(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'required|string|min:1']);

        return $this->fulfillment->shortPick(AuthUser::current(), $id, $data['reason']);
    }

    public function pack(Request $request, string $id): array
    {
        $data = $request->validate(['cartons' => 'sometimes|integer|min:1', 'weightKg' => 'sometimes|numeric|gt:0', 'volumeM3' => 'sometimes|numeric|gt:0']);

        return $this->fulfillment->pack(AuthUser::current(), $id, $data + ['cartons' => 1]);
    }

    public function loading(string $trip): array
    {
        return $this->fulfillment->loadingPlan($trip);
    }

    public function load(Request $request, string $trip): array
    {
        $data = $request->validate(['foNumber' => 'required|string|min:1', 'scannedVehicle' => 'nullable|string', 'scannedOrder' => 'nullable|string']);

        return $this->fulfillment->load(AuthUser::current(), $trip, $data);
    }

    public function dispatch(string $trip): array
    {
        return $this->fulfillment->dispatch(AuthUser::current(), $trip);
    }
}
