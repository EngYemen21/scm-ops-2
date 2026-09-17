<?php

namespace App\Http\Controllers\Api\Procurement;

use App\Services\Procurement\RfqService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** RFQs (invite, comparison, award, cancel) and supplier quotations. */
class RfqController extends ProcurementBaseController
{
    public function __construct(private readonly RfqService $rfq) {}

    public function index(Request $request): array
    {
        return $this->rfq->list(Paging::from($request), $request->only(['status', 'pr']));
    }

    public function show(string $id): array
    {
        return $this->rfq->get($id);
    }

    public function comparison(string $id): array
    {
        return $this->rfq->comparison($id);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'prNumber' => 'nullable|string', 'invitedRule' => 'nullable|'.Rules::INVITE_RULE, 'closeDate' => Rules::date(true), 'terms' => 'nullable|string',
            'deliveryWarehouseCode' => 'nullable|string', 'notes' => 'nullable|string',
        ] + Rules::lines() + Rules::supplierCodes());

        return $this->created($this->rfq->create(AuthUser::current(), $data));
    }

    public function invite(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['invitedRule' => 'nullable|'.Rules::INVITE_RULE] + Rules::supplierCodes());

        return $this->created($this->rfq->invite(AuthUser::current(), $id, $data));
    }

    public function award(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'quotation' => 'required|string|min:1', 'warehouseCode' => 'nullable|string', 'dueDate' => Rules::date(), 'paymentTerms' => 'nullable|string', 'notes' => 'nullable|string',
        ] + Rules::scoreOverride());

        return $this->created($this->rfq->award(AuthUser::current(), $id, $data));
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'nullable|string']);

        return $this->created($this->rfq->cancel(AuthUser::current(), $id, $data['reason'] ?? null));
    }

    // ── supplier quotations ──

    public function quotations(Request $request): array
    {
        return $this->rfq->quotations(Paging::from($request), $request->only(['supplier', 'rfq', 'status']));
    }

    public function quotation(string $id): array
    {
        return $this->rfq->quotation($id);
    }

    public function storeQuotation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'supplierCode' => 'required|string|min:1', 'rfqNumber' => 'nullable|string', 'supplierRef' => 'required|string|min:1', 'date' => Rules::date(), 'validUntil' => Rules::date(true),
            'paymentTerms' => 'nullable|string', 'deliveryTerms' => 'nullable|string', 'minOrder' => 'nullable|integer|min:0', 'leadDays' => 'required|integer|min:0',
            'attachmentName' => ['required', 'string', 'regex:/\.(pdf|jpe?g|png)$/i'], 'notes' => 'nullable|string',
            'lines' => 'required|array|min:1', 'lines.*.sku' => 'required|string', 'lines.*.qty' => 'nullable|integer|min:0', 'lines.*.price' => 'required|numeric|gt:0',
            'lines.*.vatPct' => 'nullable|numeric', 'lines.*.leadDays' => 'nullable|integer',
        ], ['attachmentName.regex' => 'PDF / JPG / PNG']);

        return $this->created($this->rfq->recordQuotation(AuthUser::current(), $data));
    }
}
