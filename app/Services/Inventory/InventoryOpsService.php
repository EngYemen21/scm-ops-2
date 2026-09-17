<?php

namespace App\Services\Inventory;

use App\Models\Batch;
use App\Models\Bin;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Support\Facades\DB;

/** Manual stock operations: adjustments, bin-to-bin moves and quarantine flags. All quantity changes go through the engine. */
class InventoryOpsService
{
    private const MOVE_REASONS = [
        'slot' => ['إعادة توزيع Slotting', 'Slotting'], 'consol' => ['دمج مواقع', 'Consolidation'], 'replen' => ['تغذية منطقة الصرف', 'Replenishment'],
        'damage' => ['عزل تالف', 'Isolate damaged'], 'qtn' => ['نقل للحجر', 'Move to quarantine'],
    ];

    public function __construct(private readonly InventoryService $inv, private readonly AuditService $audit, private readonly NotifyService $notify) {}

    /**
     * Resolves sku / warehouse / bin / batch codes to models (shared by every manual operation).
     *
     * @return array{product:Product, warehouse:Warehouse, bin:Bin, batch:?Batch, row:?InventoryBalance}
     */
    public function resolve(string $sku, string $warehouseCode, string $binCode, ?string $batchNo = null): array
    {
        $product = Product::where('sku', $sku)->first()
            ?? throw AppError::notFound('PRODUCT_NOT_FOUND', "المنتج {$sku} غير موجود", "Product {$sku} not found");
        $warehouse = Warehouse::where('code', mb_strtoupper($warehouseCode))->first()
            ?? throw AppError::notFound('WAREHOUSE_NOT_FOUND', "المستودع {$warehouseCode} غير موجود", "Warehouse {$warehouseCode} not found");
        $bin = $this->inv->bin($warehouse->id, $binCode);
        $batch = null;
        if ($batchNo !== null && $batchNo !== '' && $batchNo !== '—') {
            $batch = Batch::where('product_id', $product->id)->where('batch_no', $batchNo)->first()
                ?? throw AppError::notFound('BATCH_NOT_FOUND', "الدفعة {$batchNo} غير موجودة للمنتج {$product->sku}", "Batch {$batchNo} not found for {$product->sku}");
        }
        $row = InventoryBalance::where('product_id', $product->id)->where('bin_id', $bin->id)->where('batch_key', $batch?->id ?? '')->first();

        return compact('product', 'warehouse', 'bin', 'batch', 'row');
    }

    // ───────────── adjustment ─────────────

    /** @param  array{sku:string, warehouseCode:string, binCode:string, batchNo?:?string, qtyDelta:int, reason:string}  $dto */
    public function adjust(AuthUser $user, array $dto): InventoryMovement
    {
        return DB::transaction(function () use ($user, $dto) {
            ['product' => $product, 'warehouse' => $warehouse, 'bin' => $bin, 'batch' => $batch, 'row' => $row] = $this->resolve($dto['sku'], $dto['warehouseCode'], $dto['binCode'], $dto['batchNo'] ?? null);
            if ($row?->blocked) {
                throw AppError::rule('ROW_BLOCKED', "الموقع {$bin->code} مجمد لجرد جارٍ — التسوية تتم عبر اعتماد الجرد", 'Row is frozen by an open count');
            }
            $delta = (int) $dto['qtyDelta'];
            $txId = $this->inv->newTxId();
            $loc = ['warehouseId' => $warehouse->id, 'binId' => $bin->id];
            // positive delta = stock appears at the bin; negative = leaves it (engine refuses to go below reserved / negative)
            $mv = $this->inv->post($user, $txId, [
                'type' => 'adj', 'productId' => $product->id, 'batchId' => $batch?->id, 'qty' => abs($delta), ($delta > 0 ? 'to' : 'from') => $loc,
                'reference' => ['type' => 'Adjustment', 'number' => "ADJ · {$user->username}"], 'note' => "تسوية يدوية: {$dto['reason']}",
            ]);
            $sign = $delta > 0 ? '+' : '';
            $this->audit->log($user, [
                'action' => 'INVENTORY.ADJUST', 'entityType' => 'InventoryBalance', 'entityId' => $mv->id, 'entityNumber' => $mv->number,
                'field' => "{$product->sku} @ {$warehouse->code}/{$bin->code}".($batch ? '/'.$batch->batch_no : ''), 'oldValue' => $mv->before_qty,
                'newValue' => ['after' => $mv->after_qty, 'delta' => $delta, 'reason' => $dto['reason']], 'transactionId' => $txId,
            ]);
            $this->notify->activity($user, 'InventoryMovement', $mv->id, $mv->number,
                "تسوية مخزون {$sign}{$delta} × {$product->name_ar} في {$warehouse->code}/{$bin->code} — {$dto['reason']}",
                "Stock adjustment {$sign}{$delta} × {$product->name_en} at {$warehouse->code}/{$bin->code} — {$dto['reason']}", ['wm']);

            return $mv->fresh();
        });
    }

    // ───────────── quarantine flag ─────────────

    /**
     * Quarantine on/off is a FLAG change on the balance row — quantities do not move, so no ledger (movement) row is
     * written: a `qtn` movement with qty would break `reconcile()` (ledger Σ ≠ balance). The change is recorded as an
     * AuditLog + ActivityLog entry instead (who / when / why / before → after).
     *
     * @param  array{sku:string, warehouseCode:string, binCode:string, batchNo?:?string, quarantine:bool, reason:string}  $dto
     */
    public function quarantine(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            ['product' => $product, 'warehouse' => $warehouse, 'bin' => $bin, 'batch' => $batch, 'row' => $row] = $this->resolve($dto['sku'], $dto['warehouseCode'], $dto['binCode'], $dto['batchNo'] ?? null);
            if (! $row) {
                throw AppError::notFound('BALANCE_NOT_FOUND', "لا يوجد رصيد لـ {$product->sku} في {$bin->code}", 'No balance row at bin');
            }
            $on = (bool) $dto['quarantine'];
            $r = $this->inv->setQuarantine($product->id, $bin->id, $batch?->id, $on);
            $label = "{$product->sku} @ {$warehouse->code}/{$bin->code}".($batch ? '/'.$batch->batch_no : '');
            $this->audit->log($user, [
                'action' => $on ? 'INVENTORY.QUARANTINE' : 'INVENTORY.UNQUARANTINE', 'entityType' => 'InventoryBalance', 'entityId' => $r['rowId'], 'entityNumber' => $label,
                'field' => 'quarantine', 'oldValue' => $r['before'], 'newValue' => ['quarantine' => $r['after'], 'onHand' => $r['onHand'], 'reason' => $dto['reason']],
            ]);
            $at = "{$warehouse->code}/{$bin->code} — {$dto['reason']}";
            $this->notify->activity($user, 'InventoryBalance', $r['rowId'], $label,
                $on ? "حُجر {$r['onHand']} × {$product->name_ar} في {$at}" : "رُفع الحجر عن {$r['onHand']} × {$product->name_ar} في {$at}",
                $on ? "Quarantined {$r['onHand']} × {$product->name_en} at {$at}" : "Released {$r['onHand']} × {$product->name_en} from quarantine at {$at}", ['wm', 'inv']);

            return [
                'rowId' => $r['rowId'], 'sku' => $product->sku, 'warehouse' => $warehouse->code, 'bin' => $bin->code, 'batch' => $batch?->batch_no ?: null,
                'onHand' => $r['onHand'], 'quarantine' => $r['after'],
            ];
        });
    }

    // ───────────── bin-to-bin move ─────────────

    /** @param  array{sku:string, warehouseCode:string, fromBin:string, toBin:string, batchNo?:?string, qty:int, reason:string}  $dto */
    public function move(AuthUser $user, array $dto): InventoryMovement
    {
        if (mb_strtoupper(trim($dto['fromBin'])) === mb_strtoupper(trim($dto['toBin']))) {
            throw AppError::rule('SAME_BIN', 'موقع المصدر والوجهة متطابقان', 'Source and destination bins are the same');
        }

        return DB::transaction(function () use ($user, $dto) {
            ['product' => $product, 'warehouse' => $warehouse, 'bin' => $fromBin, 'batch' => $batch, 'row' => $row] = $this->resolve($dto['sku'], $dto['warehouseCode'], $dto['fromBin'], $dto['batchNo'] ?? null);
            $reason = $dto['reason'];
            $qty = (int) $dto['qty'];
            if (! $row || $row->on_hand <= 0) {
                throw AppError::rule('NO_STOCK', "موقع المصدر {$fromBin->code} لا يحوي رصيدًا لهذا المنتج/الدفعة", 'Source bin has no stock for this product/batch');
            }
            if ($row->blocked) {
                throw AppError::rule('ROW_BLOCKED', "الموقع {$fromBin->code} مجمد لجرد جارٍ", 'Source row is frozen by an open count');
            }
            if ($row->quarantine && ! in_array($reason, ['qtn', 'damage'], true)) {
                throw AppError::rule('SOURCE_QUARANTINED', 'الرصيد في الحجر — أزل الحجر أولًا أو استخدم سبب حجر/تالف', 'Stock is quarantined — release it first or use a quarantine/damage reason');
            }
            $toBin = $this->inv->bin($warehouse->id, $dto['toBin']);
            // destination rules: normal reasons must pass locCheck (frozen → FZ only, chilled → CH …); 'qtn' targets the quarantine zone, 'damage' the damaged zone
            if ($reason === 'qtn') {
                if ($toBin->zone->type !== 'quarantine') {
                    throw AppError::rule('LOC_RULE', "نقل للحجر يجب أن يكون إلى موقع في منطقة الحجر وليس {$toBin->zone->code}", 'Quarantine moves must target a quarantine-zone bin');
                }
                if ($toBin->status !== 'active') {
                    throw AppError::rule('LOC_RULE', "الموقع {$toBin->code} غير نشط", "Bin {$toBin->code} is {$toBin->status}");
                }
            } elseif ($reason === 'damage' && $toBin->zone->type === 'damaged') {
                if ($toBin->status !== 'active') {
                    throw AppError::rule('LOC_RULE', "الموقع {$toBin->code} غير نشط", "Bin {$toBin->code} is {$toBin->status}");
                }
            } else {
                $chk = $this->inv->locCheck($product->id, $toBin->id);
                if (! $chk['ok']) {
                    throw AppError::rule('LOC_RULE', $chk['why'], $chk['whyEn'] ?? null);
                }
            }
            $available = $row->on_hand - $row->reserved;
            if ($qty > $available) {
                throw AppError::rule('INSUFFICIENT_AVAILABLE', "الكمية تتجاوز المتاح ({$available}) — المحجوز لا يُنقل", "Quantity exceeds available ({$available}) — reserved stock cannot be moved", ['available' => $available, 'requested' => $qty]);
            }
            $txId = $this->inv->newTxId();
            $why = self::MOVE_REASONS[$reason] ?? [$reason, $reason];
            $mv = $this->inv->post($user, $txId, [
                'type' => 'move', 'productId' => $product->id, 'batchId' => $batch?->id, 'qty' => $qty,
                'from' => ['warehouseId' => $warehouse->id, 'binId' => $fromBin->id], 'to' => ['warehouseId' => $warehouse->id, 'binId' => $toBin->id],
                'quarantine' => $reason === 'qtn', 'reference' => ['type' => 'BinMove', 'number' => "MOVE · {$fromBin->code} ← {$toBin->code}"],
                'note' => "{$why[0]} · {$fromBin->code} → {$toBin->code}",
            ]);
            $this->audit->log($user, [
                'action' => 'INVENTORY.MOVE', 'entityType' => 'InventoryMovement', 'entityId' => $mv->id, 'entityNumber' => $mv->number,
                'field' => $product->sku.($batch ? '/'.$batch->batch_no : ''), 'oldValue' => "{$warehouse->code}/{$fromBin->code}",
                'newValue' => ['to' => "{$warehouse->code}/{$toBin->code}", 'qty' => $qty, 'reason' => $reason], 'transactionId' => $txId,
            ]);
            $this->notify->activity($user, 'InventoryMovement', $mv->id, $mv->number,
                "نُقل {$qty} × {$product->name_ar} من {$fromBin->code} إلى {$toBin->code} ({$warehouse->code}) — {$why[0]}",
                "Moved {$qty} × {$product->name_en} from {$fromBin->code} to {$toBin->code} ({$warehouse->code}) — {$why[1]}",
                in_array($reason, ['qtn', 'damage'], true) ? ['wm', 'inv'] : []);

            return $mv->fresh();
        });
    }
}
