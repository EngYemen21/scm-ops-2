<?php

namespace App\Http\Controllers\Api\Procurement;

use App\Services\Procurement\SupplierInvoiceService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Supplier invoices: register (three-way match), approve for payment, record payment, cancel. */
class InvoicesController extends ProcurementBaseController
{
    public function __construct(private readonly SupplierInvoiceService $invoices) {}

    public function index(Request $request): array
    {
        return $this->invoices->list(Paging::from($request), $request->only(['status', 'supplier', 'po', 'due']));
    }

    public function show(string $id): array
    {
        return $this->invoices->get($id);
    }

    public function summary(): array
    {
        return $this->invoices->summary();
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'po' => 'required|string|min:1', 'supplierInvoiceNo' => 'required|string|min:1|max:60', 'invoiceDate' => Rules::date(true), 'dueDate' => Rules::date(),
            'discount' => 'nullable|numeric|min:0', 'shippingCost' => 'nullable|numeric|min:0', 'vatPct' => 'nullable|numeric|min:0|max:100', 'notes' => 'nullable|string',
            'lines' => 'required|array|min:1', 'lines.*.lineNo' => 'required|integer|min:1', 'lines.*.qty' => 'required|integer|min:1', 'lines.*.price' => 'required|numeric|min:0',
        ]);

        return $this->created($this->invoices->register(AuthUser::current(), $data));
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => 'nullable|string']);

        return $this->created($this->invoices->approve(AuthUser::current(), $id, $data['note'] ?? null));
    }

    public function pay(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reference' => 'required|string|min:1|max:80', 'paidAt' => Rules::date()]);

        return $this->created($this->invoices->pay(AuthUser::current(), $id, $data));
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:3'], ['reason.required' => 'سبب الإلغاء مطلوب', 'reason.min' => 'سبب الإلغاء مطلوب']);

        return $this->created($this->invoices->cancel(AuthUser::current(), $id, $data['reason']));
    }
}
