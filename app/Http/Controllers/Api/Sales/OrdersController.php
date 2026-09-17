<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Services\Sales\SalesService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\Request;

class OrdersController extends Controller
{
    public function __construct(private readonly SalesService $sales) {}

    public function index(Request $request): array
    {
        return $this->sales->listOrders(Paging::from($request), $request->only(['status', 'customer', 'warehouse', 'waiting']));
    }

    public function show(string $id): array
    {
        return $this->sales->getOrder($id);
    }

    public function store(Request $request): array
    {
        $data = $request->validate(SalesRules::lines() + [
            'customerCode' => 'required|string|min:1', 'warehouseCode' => 'required|string|min:1', 'dueDate' => ['required', 'string', SalesRules::DATE],
            'window' => 'nullable|string', 'priority' => 'sometimes|in:normal,high',
        ]);

        return $this->sales->createOrder(AuthUser::current(), $data + ['priority' => 'normal']);
    }

    public function allocate(string $id): array
    {
        return $this->sales->allocateOrder(AuthUser::current(), $id);
    }

    public function fulfill(string $id)
    {
        return $this->sales->fulfill(AuthUser::current(), $id)->toArray();
    }

    public function cancel(Request $request, string $id): array
    {
        $data = $request->validate(SalesRules::NOTE);

        return $this->sales->cancelOrder(AuthUser::current(), $id, ($data['reason'] ?? null) ?: ($data['note'] ?? null));
    }
}
