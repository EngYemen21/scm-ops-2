<?php

namespace App\Http\Controllers\Api\Procurement;

use App\Services\Procurement\PrService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Purchase requisitions. */
class PrController extends ProcurementBaseController
{
    public function __construct(private readonly PrService $pr) {}

    public function index(Request $request): array
    {
        return $this->pr->list(Paging::from($request), $request->only(['status', 'warehouse', 'priority']));
    }

    public function show(string $id): array
    {
        return $this->pr->get($id);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouseCode' => 'required|string|min:1', 'needDate' => Rules::date(), 'priority' => 'nullable|in:normal,urgent,low',
            'justification' => 'required|string|min:1', 'costCenter' => 'nullable|string',
        ] + Rules::lines());

        return $this->created($this->pr->create(AuthUser::current(), $data));
    }

    public function submit(string $id): JsonResponse
    {
        return $this->created($this->pr->submit(AuthUser::current(), $id));
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => 'nullable|string']);

        return $this->created($this->pr->approve(AuthUser::current(), $id, $data['note'] ?? null));
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:3'], ['reason.required' => 'سبب الرفض مطلوب', 'reason.min' => 'سبب الرفض مطلوب']);

        return $this->created($this->pr->reject(AuthUser::current(), $id, $data['reason']));
    }

    public function toRfq(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'closeDate' => Rules::date(true), 'invitedRule' => 'nullable|'.Rules::INVITE_RULE, 'terms' => 'nullable|string',
            'deliveryWarehouseCode' => 'nullable|string', 'notes' => 'nullable|string',
        ] + Rules::supplierCodes());

        return $this->created($this->pr->toRfq(AuthUser::current(), $id, $data));
    }

    public function toPo(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'supplierCode' => 'required|string|min:1', 'dueDate' => Rules::date(true), 'paymentTerms' => 'nullable|string', 'notes' => 'nullable|string',
            'prices' => 'nullable|array', 'prices.*.sku' => 'required|string|min:1', 'prices.*.price' => 'required|numeric|gt:0',
        ] + Rules::scoreOverride());

        return $this->created($this->pr->toPo(AuthUser::current(), $id, $data));
    }
}
