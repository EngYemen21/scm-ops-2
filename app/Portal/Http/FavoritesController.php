<?php

namespace App\Portal\Http;

use App\Http\Controllers\Api\Sales\SalesRules;
use App\Http\Controllers\Controller;
use App\Portal\Services\FavoritesService;
use App\Portal\Services\ReorderService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Favourite lists, their items (always a page of ONE list) and "order again". Thin: validate, call a service, return.
 */
class FavoritesController extends Controller
{
    public function __construct(private readonly FavoritesService $favorites, private readonly ReorderService $reorder) {}

    public function lists(Request $request): array
    {
        $filters = PortalRules::filters($request, [
            'customer' => 'nullable|string|max:160', 'branch' => 'nullable|string|max:40', 'state' => 'nullable|in:active,archived,all', 'origin' => 'nullable|in:ops,odoo',
            'linked' => 'nullable|in:yes,no', 'product' => 'nullable|string|max:80',
        ]);

        return $this->favorites->lists(Paging::from($request, 25), $filters);
    }

    public function showList(string $id): array
    {
        return $this->favorites->getList($id);
    }

    public function items(Request $request, string $id): array
    {
        $request->validate(PortalRules::PAGING);

        return $this->favorites->items($id, Paging::from($request, 50));
    }

    public function storeList(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customerId' => 'required|string|max:160', 'name' => 'required|string|min:1|max:190', 'branchId' => PortalRules::text(40), 'notes' => PortalRules::text(4000),
            'items' => 'sometimes|array|max:300', 'items.*.sku' => 'required|string|min:1|max:80', 'items.*.qty' => 'nullable|numeric|gt:0|max:'.FavoritesService::MAX_QTY,
        ]);

        return response()->json($this->favorites->createList(AuthUser::current(), $data), 201);
    }

    public function updateList(Request $request, string $id): array
    {
        $data = $request->validate([
            'name' => 'sometimes|string|min:1|max:190', 'sequence' => 'sometimes|nullable|integer|min:0|max:100000', 'branchId' => PortalRules::text(40), 'notes' => PortalRules::text(4000),
        ]);

        return $this->favorites->updateList(AuthUser::current(), $id, $data);
    }

    public function archiveList(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        return $this->favorites->archiveList(AuthUser::current(), $id, $data['reason'] ?? null);
    }

    public function restoreList(string $id): array
    {
        return $this->favorites->restoreList(AuthUser::current(), $id);
    }

    public function addItem(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['sku' => 'required|string|min:1|max:80', 'qty' => 'nullable|numeric|gt:0|max:'.FavoritesService::MAX_QTY]);

        return response()->json($this->favorites->addItem(AuthUser::current(), $id, $data), 201);
    }

    public function updateItem(Request $request, string $id, string $item): array
    {
        $data = $request->validate(['qty' => 'required|numeric|gt:0|max:'.FavoritesService::MAX_QTY]);

        return $this->favorites->updateItem(AuthUser::current(), $id, $item, $data);
    }

    public function removeItem(string $id, string $item): array
    {
        return $this->favorites->removeItem(AuthUser::current(), $id, $item);
    }

    public function moveItem(Request $request, string $id, string $item): array
    {
        $data = $request->validate(['position' => 'required|integer|min:1|max:100000']);

        return $this->favorites->moveItem(AuthUser::current(), $id, $item, (int) $data['position']);
    }

    // ── order again: the list as a draft quotation of the sales module
    public function orderAgainPreview(string $id): array
    {
        return $this->reorder->preview($id);
    }

    public function orderAgain(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(SalesRules::lines() + [
            'validUntil' => ['required', 'string', SalesRules::DATE], 'notes' => 'nullable|string|max:2000', 'terms' => 'nullable|string|max:120', 'delivery' => 'nullable|string|max:120',
        ]);

        return response()->json($this->reorder->order(AuthUser::current(), $id, $data), 201);
    }
}
