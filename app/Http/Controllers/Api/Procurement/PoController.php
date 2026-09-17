<?php

namespace App\Http\Controllers\Api\Procurement;

use App\Services\Procurement\PoService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Purchase orders: create, approval chain, send (expected inbound), confirm, cancel. */
class PoController extends ProcurementBaseController
{
    public function __construct(private readonly PoService $po) {}

    public function index(Request $request): array
    {
        return $this->po->list(Paging::from($request), $request->only(['status', 'supplier', 'warehouse', 'step']));
    }

    public function show(string $id): array
    {
        return $this->po->get($id);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'supplierCode' => 'required|string|min:1', 'warehouseCode' => 'required|string|min:1', 'dueDate' => Rules::date(true),
            'paymentTerms' => 'nullable|string', 'reference' => 'nullable|string', 'notes' => 'nullable|string',
        ] + Rules::lines(priceRequired: true) + Rules::scoreOverride());

        return $this->created($this->po->create(AuthUser::current(), $data));
    }

    public function submit(string $id): JsonResponse
    {
        return $this->created($this->po->submit(AuthUser::current(), $id));
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => 'nullable|string']);

        return $this->created($this->po->approve(AuthUser::current(), $id, $data['note'] ?? null));
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:3'], ['reason.required' => 'سبب الرفض مطلوب', 'reason.min' => 'سبب الرفض مطلوب']);

        return $this->created($this->po->reject(AuthUser::current(), $id, $data['reason']));
    }

    public function send(string $id): JsonResponse
    {
        return $this->created($this->po->send(AuthUser::current(), $id));
    }

    public function confirm(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['supplierRef' => 'nullable|string', 'note' => 'nullable|string']);

        return $this->created($this->po->confirm(AuthUser::current(), $id, $data));
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:3'], ['reason.required' => 'سبب الإلغاء مطلوب', 'reason.min' => 'سبب الإلغاء مطلوب']);

        return $this->created($this->po->cancel(AuthUser::current(), $id, $data['reason']));
    }
}
