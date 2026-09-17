<?php

namespace App\Http\Controllers\Api\Procurement;

use App\Services\Procurement\ProcurementService;
use App\Support\AuthUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Procurement dashboard, replenishment suggestions (and their conversions) and supplier performance. */
class ProcurementController extends ProcurementBaseController
{
    public function __construct(private readonly ProcurementService $procurement) {}

    public function dashboard(): array
    {
        return $this->procurement->dashboard();
    }

    public function suggestions(Request $request): array
    {
        $q = $request->validate(['warehouse' => 'nullable|string', 'urgent' => 'nullable', 'sku' => 'nullable|string', 'limit' => 'nullable|integer|min:1|max:1000']);

        return $this->procurement->suggestions(['warehouse' => $q['warehouse'] ?? null, 'urgent' => $request->boolean('urgent'), 'sku' => $q['sku'] ?? null, 'limit' => isset($q['limit']) ? (int) $q['limit'] : null]);
    }

    public function toPr(Request $request, string $sku): JsonResponse
    {
        $data = $request->validate([
            'qty' => 'nullable|integer|min:1', 'warehouseCode' => 'nullable|string', 'needDate' => Rules::date(), 'priority' => 'nullable|in:normal,urgent,low',
            'costCenter' => 'nullable|string', 'justification' => 'nullable|string',
        ]);

        return $this->created($this->procurement->toPr(AuthUser::current(), $sku, $data));
    }

    public function toRfq(Request $request, string $sku): JsonResponse
    {
        $data = $request->validate([
            'qty' => 'nullable|integer|min:1', 'closeDate' => Rules::date(), 'invitedRule' => 'nullable|'.Rules::INVITE_RULE, 'terms' => 'nullable|string',
            'deliveryWarehouseCode' => 'nullable|string', 'notes' => 'nullable|string',
        ] + Rules::supplierCodes());

        return $this->created($this->procurement->toRfq(AuthUser::current(), $sku, $data));
    }

    public function toPo(Request $request, string $sku): JsonResponse
    {
        $data = $request->validate([
            'qty' => 'nullable|integer|min:1', 'supplierCode' => 'nullable|string', 'warehouseCode' => 'nullable|string', 'dueDate' => Rules::date(),
            'price' => 'nullable|numeric|gt:0', 'paymentTerms' => 'nullable|string',
        ] + Rules::scoreOverride());

        return $this->created($this->procurement->toPo(AuthUser::current(), $sku, $data));
    }

    public function supplierPerformance(string $code): array
    {
        return $this->procurement->supplierPerformance($code);
    }
}
