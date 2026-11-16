<?php

namespace App\Http\Controllers\Api\Returns;

use App\Http\Controllers\Controller;
use App\Services\Returns\ReturnsService;
use App\Services\Returns\ReturnStockSources;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\Request;

class ReturnsController extends Controller
{
    public function __construct(private readonly ReturnsService $returns, private readonly ReturnStockSources $sources) {}

    public function index(Request $request): array
    {
        return $this->returns->list(Paging::from($request), $request->only(['status', 'type', 'warehouse', 'supplier', 'sourceMode']));
    }

    public function show(string $id): array
    {
        return $this->returns->get($id);
    }

    /** Balance rows a return from stock can be taken from (a supplier's receipts, or every eligible row with scope=all). */
    public function stockRows(Request $request): array
    {
        $data = $request->validate([
            'warehouse' => 'required|string|min:1',
            'supplier' => 'nullable|string',
            'po' => 'nullable|string',
            'grn' => 'nullable|string',
            'q' => 'nullable|string',
            'scope' => 'nullable|in:supplier,all',
        ]);

        return $this->sources->rows($data);
    }

    public function store(Request $request)
    {
        // tripNumber is deliberately not accepted here: only the delivery flow links a return to its trip. A manual
        // customer return may name the delivery it returns (foNumber / invoiceNumber) — that is what earns it a credit note.
        $data = $request->validate([
            'type' => 'required|in:cust,sup,del,dmg',
            'source' => 'required|string|min:1',
            'reference' => 'nullable|string',
            'customerCode' => 'nullable|string',
            'supplierCode' => 'nullable|string',
            'foNumber' => 'nullable|string',
            'invoiceNumber' => 'nullable|string',
            'poNumber' => 'nullable|string',
            'grnNumber' => 'nullable|string',
            'warehouseCode' => 'required|string|min:1',
            'reasonCode' => 'required|in:damaged,wrong_product,wrong_qty,expired,cust_reject,del_fail,quality,partial,other',
            'notes' => 'nullable|string',
            'attachments' => 'nullable|array',
            'attachments.*' => 'string',
            'lines' => 'required|array|min:1',
            'lines.*.sku' => 'required|string|min:1',
            'lines.*.qty' => 'required|integer|min:1',
            'lines.*.batchNo' => 'nullable|string',
            'lines.*.fromBin' => 'nullable|string',
            'lines.*.unitPrice' => 'nullable|numeric|min:0',
        ]);
        if ($data['type'] !== 'cust') {
            unset($data['foNumber'], $data['invoiceNumber']); // a delivery reference means something only on a customer return
        }

        return $this->returns->create(AuthUser::current(), $data);
    }

    public function approve(string $id): array
    {
        return $this->returns->approve(AuthUser::current(), $id);
    }

    public function reject(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'nullable|string', 'findings' => 'nullable|string']);

        return $this->returns->reject(AuthUser::current(), $id, $data['reason'] ?? null);
    }

    public function cancel(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        return $this->returns->cancel(AuthUser::current(), $id, $data['reason'] ?? null);
    }

    public function receive(Request $request, string $id): array
    {
        $data = $request->validate([
            'notes' => 'nullable|string|max:500',
            'lines' => 'nullable|array',
            'lines.*.lineNo' => 'required|integer|min:1',
            'lines.*.receivedQty' => 'required|integer|min:0',
        ]);

        return $this->returns->receive(AuthUser::current(), $id, $data['lines'] ?? null, $data['notes'] ?? null);
    }

    public function inspect(Request $request, string $id): array
    {
        $data = $request->validate([
            'reason' => 'nullable|string',
            'findings' => 'nullable|string',
            'lines' => 'nullable|array',
            'lines.*.lineNo' => 'required|integer|min:1',
            'lines.*.inspectedQty' => 'required|integer|min:0',
            'lines.*.condition' => 'required|in:'.implode(',', ReturnsService::CONDITIONS),
            'lines.*.note' => 'nullable|string|max:500',
        ]);

        return $this->returns->inspect(AuthUser::current(), $id, $data['findings'] ?? null, $data['lines'] ?? null);
    }

    public function decide(Request $request, string $id): array
    {
        $decisions = 'in:restock,qtn,dmg,dispose,sup';
        $data = $request->validate([
            // one decision for the whole return …
            'decision' => "required_without:lines|{$decisions}",
            'binCode' => 'nullable|string',
            'supplierCode' => 'nullable|string',
            'note' => 'nullable|string',
            // … or a decision per line, a line split over several parts
            'lines' => 'required_without:decision|array|min:1',
            'lines.*.lineNo' => 'required|integer|min:1',
            'lines.*.parts' => 'present|array',
            'lines.*.parts.*.qty' => 'required|integer|min:1',
            'lines.*.parts.*.decision' => "required|{$decisions}",
            'lines.*.parts.*.binCode' => 'nullable|string',
            'lines.*.parts.*.batchNo' => 'nullable|string|max:80',
            'lines.*.parts.*.expiryDate' => 'nullable|date',
            'lines.*.parts.*.supplierCode' => 'nullable|string',
            'lines.*.parts.*.note' => 'nullable|string|max:500',
        ]);

        return $this->returns->decide(AuthUser::current(), $id, $data);
    }

    public function ship(Request $request, string $id): array
    {
        $data = $request->validate(['carrier' => 'nullable|string|max:120', 'note' => 'nullable|string|max:500']);

        return $this->returns->ship(AuthUser::current(), $id, $data);
    }

    public function debitNote(Request $request, string $id): array
    {
        $data = $request->validate([
            'lines' => 'nullable|array',
            'lines.*.lineNo' => 'required|integer|min:1',
            'lines.*.unitPrice' => 'required|numeric|min:0',
        ]);

        return $this->returns->issueDebitNote(AuthUser::current(), $id, $data['lines'] ?? []);
    }
}
