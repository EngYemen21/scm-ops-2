<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Services\Inventory\InventoryOpsService;
use App\Services\Inventory\InventoryQueryService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\Request;

/** /api/inventory — balances, ledger, batches, reconciliation, staging, trace + manual operations. */
class InventoryController extends Controller
{
    public function __construct(private readonly InventoryQueryService $query, private readonly InventoryOpsService $ops) {}

    // ───────────── read views ─────────────

    public function balances(Request $request): array
    {
        $f = $request->validate([
            'warehouse' => 'nullable|string', 'sku' => 'nullable|string', 'zone' => 'nullable|string', 'category' => 'nullable|string',
            'storageClass' => 'nullable|in:ambient,chilled,frozen', 'status' => 'nullable|in:available,quarantine,expired,expiring,zero',
        ]);

        return $this->query->balances(Paging::from($request), $f);
    }

    public function summary(Request $request): array
    {
        return $this->query->summary(AuthUser::current(), $this->warehouse($request));
    }

    public function productStock(string $sku): array
    {
        return $this->query->productStock($sku);
    }

    public function ledger(Request $request): array
    {
        $f = $request->validate([
            'sku' => 'nullable|string', 'warehouse' => 'nullable|string', 'bin' => 'nullable|string', 'type' => 'nullable|string', 'referenceNumber' => 'nullable|string',
            'transactionId' => 'nullable|string', 'from' => 'nullable|date', 'to' => 'nullable|date', 'user' => 'nullable|string',
        ]);

        return $this->query->ledger(Paging::from($request), $f);
    }

    public function ledgerEntry(string $number): array
    {
        return $this->query->ledgerEntry($number);
    }

    public function batches(Request $request): array
    {
        $f = $request->validate(['sku' => 'nullable|string', 'warehouse' => 'nullable|string', 'status' => 'nullable|in:expired,expiring,ok,quarantine']);

        return $this->query->batches(Paging::from($request), $f);
    }

    public function reconciliation(Request $request): array
    {
        $user = AuthUser::current();
        if (! $user->can('audit.view') && ! $user->can('inventory.adjust')) {
            throw AppError::forbidden('FORBIDDEN', 'مرفوض — تقرير المطابقة يتطلب صلاحية audit.view أو inventory.adjust', 'Forbidden — needs audit.view or inventory.adjust');
        }

        return $this->query->reconciliation($this->warehouse($request));
    }

    public function staging(Request $request): array
    {
        return $this->query->staging($this->warehouse($request));
    }

    public function trace(string $referenceNumber): array
    {
        return $this->query->trace($referenceNumber);
    }

    // ───────────── manual operations ─────────────

    public function adjust(Request $request)
    {
        $data = $request->validate([
            'sku' => 'required|string|min:1', 'warehouseCode' => 'required|string|min:1', 'binCode' => 'required|string|min:1', 'batchNo' => 'nullable|string',
            'qtyDelta' => 'required|integer|not_in:0', 'reason' => 'required|string|min:1',
        ]);

        return response()->json($this->ops->adjust(AuthUser::current(), ['qtyDelta' => (int) $data['qtyDelta']] + $data), 201);
    }

    public function quarantine(Request $request): array
    {
        $data = $request->validate([
            'sku' => 'required|string|min:1', 'warehouseCode' => 'required|string|min:1', 'binCode' => 'required|string|min:1', 'batchNo' => 'nullable|string',
            'quarantine' => 'required|boolean', 'reason' => 'required|string|min:1',
        ]);

        return $this->ops->quarantine(AuthUser::current(), ['quarantine' => filter_var($data['quarantine'], FILTER_VALIDATE_BOOLEAN)] + $data);
    }

    public function move(Request $request)
    {
        $data = $request->validate([
            'sku' => 'required|string|min:1', 'warehouseCode' => 'required|string|min:1', 'fromBin' => 'required|string|min:1', 'toBin' => 'required|string|min:1',
            'batchNo' => 'nullable|string', 'qty' => 'required|integer|min:1', 'reason' => 'sometimes|in:slot,consol,replen,damage,qtn',
        ]);

        return response()->json($this->ops->move(AuthUser::current(), ['qty' => (int) $data['qty']] + $data + ['reason' => 'slot']), 201);
    }

    private function warehouse(Request $request): ?string
    {
        $code = $request->query('warehouse');

        return is_string($code) && $code !== '' ? $code : null;
    }
}
