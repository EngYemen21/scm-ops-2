<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Services\Sales\SalesService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\Request;

class ConsolidationsController extends Controller
{
    public function __construct(private readonly SalesService $sales) {}

    public function index(Request $request): array
    {
        return $this->sales->listConsolidations(Paging::from($request), $request->only(['status', 'warehouse']));
    }

    public function show(string $id): array
    {
        return $this->sales->getConsolidation($id);
    }

    public function store(Request $request): array
    {
        $data = $request->validate(['orderNumbers' => 'required|array|min:2', 'orderNumbers.*' => 'string', 'rule' => 'nullable|string']);

        return $this->sales->createConsolidation(AuthUser::current(), $data);
    }

    public function advance(string $id): array
    {
        return $this->sales->advanceConsolidation(AuthUser::current(), $id);
    }

    /** Dissolve the batch (action button: the reason is optional). */
    public function cancel(Request $request, string $id): array
    {
        $data = $request->validate(SalesRules::NOTE);

        return $this->sales->cancelConsolidation(AuthUser::current(), $id, ($data['reason'] ?? null) ?: ($data['note'] ?? null));
    }

    public function add(Request $request, string $id): array
    {
        $data = $request->validate(['orderNumber' => 'required|string']);

        return $this->sales->addRemoveOrder(AuthUser::current(), $id, $data['orderNumber'], true);
    }

    public function remove(Request $request, string $id): array
    {
        $data = $request->validate(['orderNumber' => 'required|string', 'reason' => 'nullable|string']);

        return $this->sales->addRemoveOrder(AuthUser::current(), $id, $data['orderNumber'], false, $data['reason'] ?? null);
    }

    /** Same as remove(), with the order in the path: an action button that needs no body. */
    public function removeOrder(Request $request, string $id, string $so): array
    {
        $data = $request->validate(SalesRules::NOTE);

        return $this->sales->addRemoveOrder(AuthUser::current(), $id, $so, false, ($data['reason'] ?? null) ?: ($data['note'] ?? null));
    }
}
