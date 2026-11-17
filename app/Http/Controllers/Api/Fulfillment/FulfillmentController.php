<?php

namespace App\Http\Controllers\Api\Fulfillment;

use App\Http\Controllers\Controller;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Fulfillment\LoadingService;
use App\Services\Fulfillment\PackingService;
use App\Services\Fulfillment\PickingService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\Request;

class FulfillmentController extends Controller
{
    private const REASONS = 'required|string|in:notfound,damaged,expired,other';

    public function __construct(
        private readonly FulfillmentService $fulfillment,
        private readonly PickingService $picking,
        private readonly PackingService $packing,
        private readonly LoadingService $loading,
    ) {}

    // ── documents ──

    public function index(Request $request): array
    {
        return $this->fulfillment->list(Paging::from($request), $request->only(['status', 'warehouse', 'trip', 'customer', 'mine']), AuthUser::current());
    }

    public function show(string $id): array
    {
        return $this->fulfillment->get($id);
    }

    // ── picking ──

    public function pickLists(Request $request): array
    {
        return $this->picking->pickLists(Paging::from($request), $request->only(['warehouse', 'status', 'mine']), AuthUser::current());
    }

    public function claim(string $id): array
    {
        return $this->picking->claim(AuthUser::current(), $id);
    }

    public function release(string $id): array
    {
        return $this->picking->release(AuthUser::current(), $id);
    }

    public function assign(Request $request, string $id): array
    {
        $data = $request->validate(['username' => 'required|string|min:1']);

        return $this->picking->assign(AuthUser::current(), $id, $data['username']);
    }

    public function pick(Request $request, string $id): array
    {
        $data = $request->validate(['scannedBin' => 'required|string|min:1', 'scannedProduct' => 'required|string|min:1', 'scannedBatch' => 'nullable|string', 'qty' => 'sometimes|integer|min:1']);

        return $this->picking->pick(AuthUser::current(), $id, $data);
    }

    public function short(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => self::REASONS, 'note' => 'nullable|string|max:500|required_if:reason,other']);

        return $this->picking->shortPick(AuthUser::current(), $id, $data);
    }

    public function substitute(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => self::REASONS, 'note' => 'nullable|string|max:500|required_if:reason,other']);

        return $this->picking->substitute(AuthUser::current(), $id, $data);
    }

    public function putback(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'required|string|min:1|max:500', 'cancelOrder' => 'sometimes|boolean', 'bins' => 'sometimes|array', 'bins.*' => 'string']);

        return $this->picking->putback(AuthUser::current(), $id, $data);
    }

    // ── packing ──

    public function pack(Request $request, string $id): array
    {
        $data = $request->validate(self::packageRules());

        return $this->packing->pack(AuthUser::current(), $id, $data + ['cartons' => 1]);
    }

    public function unpack(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'required|string|min:1|max:500']);

        return $this->packing->unpack(AuthUser::current(), $id, $data['reason']);
    }

    public function editPackage(Request $request, string $id): array
    {
        return $this->packing->editPackage(AuthUser::current(), $id, $request->validate(self::packageRules()));
    }

    // ── loading / dispatch ──

    public function staging(Request $request): array
    {
        return $this->loading->staging($request->query('warehouse') ?: null);
    }

    public function loadingPlan(string $trip): array
    {
        return $this->loading->loadingPlan($trip);
    }

    public function dock(Request $request, string $trip): array
    {
        $data = $request->validate(['dockCode' => 'present|nullable|string|max:20']);

        return $this->loading->setDock(AuthUser::current(), $trip, $data['dockCode']);
    }

    public function load(Request $request, string $trip): array
    {
        $data = $request->validate([
            'foNumber' => 'required|string|min:1', 'scannedVehicle' => 'nullable|string', 'scannedOrder' => 'nullable|string',
            'overrideSequence' => 'sometimes|boolean', 'reason' => 'nullable|string|max:500',
        ]);

        return $this->loading->load(AuthUser::current(), $trip, $data);
    }

    public function unload(Request $request, string $trip): array
    {
        $data = $request->validate(['foNumber' => 'required|string|min:1', 'reason' => 'required|string|min:1|max:500']);

        return $this->loading->unload(AuthUser::current(), $trip, $data);
    }

    public function dispatch(string $trip): array
    {
        return $this->loading->dispatch(AuthUser::current(), $trip);
    }

    private static function packageRules(): array
    {
        return [
            'cartons' => 'sometimes|integer|min:1|max:'.PackingService::MAX_CARTONS, 'weightKg' => 'sometimes|numeric|gt:0', 'volumeM3' => 'sometimes|numeric|gt:0',
            'cartonWeights' => 'sometimes|array', 'cartonWeights.*' => 'numeric|gt:0',
        ];
    }
}
