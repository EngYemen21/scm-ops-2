<?php

namespace App\Finance\Http;

use App\Finance\Services\CustomerInvoiceService;
use App\Finance\Services\ExpensesService;
use App\Finance\Services\PaymentsService;
use App\Http\Controllers\Controller;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Customer invoices / credit notes, receipts and supplier payments, expense vouchers. */
class ReceivablesController extends Controller
{
    public function __construct(private readonly CustomerInvoiceService $invoices, private readonly PaymentsService $payments, private readonly ExpensesService $expenses) {}

    // ── customer invoices
    public function invoices(Request $request): array
    {
        return $this->invoices->list(Paging::from($request), $request->only(['status', 'kind', 'customer', 'due', 'from', 'to']));
    }

    public function invoicesSummary(): array
    {
        return $this->invoices->summary();
    }

    public function invoice(string $id): array
    {
        return $this->invoices->get($id);
    }

    public function cancelInvoice(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:500']);

        return response()->json($this->invoices->cancel(AuthUser::current(), $id, $data['reason']), 201);
    }

    // ── receipts / payments
    public function payments(Request $request): array
    {
        return $this->payments->list(Paging::from($request), $request->only(['direction', 'partnerType', 'partner', 'status', 'from', 'to', 'bankAccount']));
    }

    public function payment(string $id): array
    {
        return $this->payments->get($id);
    }

    public function receive(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer' => 'required|string', 'amount' => 'required|numeric|gt:0', 'date' => 'nullable|date', 'method' => 'nullable|in:bank,cash,cheque,card,wallet', 'bankAccount' => 'nullable|string',
            'reference' => 'nullable|string|max:80', 'notes' => 'nullable|string|max:500', 'allocations' => 'nullable|array', 'allocations.*.invoice' => 'required|string', 'allocations.*.amount' => 'required|numeric|gt:0',
        ]);

        return response()->json($this->payments->receive(AuthUser::current(), $data), 201);
    }

    public function pay(Request $request): JsonResponse
    {
        $data = $request->validate([
            'supplier' => 'required|string', 'amount' => 'nullable|numeric|gt:0', 'date' => 'nullable|date', 'method' => 'nullable|in:bank,cash,cheque,card,wallet', 'bankAccount' => 'nullable|string',
            'reference' => 'required|string|min:1|max:80', 'notes' => 'nullable|string|max:500', 'allocations' => 'nullable|array', 'allocations.*.invoice' => 'required|string', 'allocations.*.amount' => 'nullable|numeric|gt:0',
        ]);

        return response()->json($this->payments->pay(AuthUser::current(), $data), 201);
    }

    public function voidPayment(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:500']);

        return response()->json($this->payments->void(AuthUser::current(), $id, $data['reason']), 201);
    }

    // ── expense vouchers
    public function expenses(Request $request): array
    {
        return $this->expenses->list(Paging::from($request), $request->only(['status', 'account', 'vehicle', 'trip', 'costCenter', 'from', 'to']));
    }

    public function expense(string $id): array
    {
        return $this->expenses->get($id);
    }

    public function storeExpense(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => 'nullable|date', 'account' => 'required|string', 'amount' => 'required|numeric|gt:0', 'vatPct' => 'nullable|numeric|min:0|max:100', 'settlement' => 'nullable|in:bank,supplier,accrued',
            'bankAccount' => 'nullable|string', 'supplier' => 'nullable|string', 'costCenter' => 'nullable|string', 'vehicle' => 'nullable|string', 'trip' => 'nullable|string',
            'description' => 'required|string|min:3|max:500', 'reference' => 'nullable|string|max:80',
        ]);

        return response()->json($this->expenses->create(AuthUser::current(), $data), 201);
    }

    public function voidExpense(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:500']);

        return response()->json($this->expenses->void(AuthUser::current(), $id, $data['reason']), 201);
    }
}
