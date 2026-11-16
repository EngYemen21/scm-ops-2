<?php

namespace App\Services\Fulfillment;

use App\Models\Batch;
use App\Models\FoLine;
use App\Models\FulfillmentOrder;
use App\Models\InventoryAllocation;
use App\Models\InventoryBalance;
use App\Models\InventoryReservation;
use App\Models\PickList;
use App\Models\PickTask;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\User;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\SettingsService;
use App\Services\Exceptions\ExceptionsService;
use App\Services\Inventory\InventoryService;
use App\Services\Sales\BackorderService;
use App\Services\Sales\CreditService;
use App\Services\Sales\Shape;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Support\Sm;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Picking: who holds a pick list, the scan-confirmed pick, and the three honest ways a pick can go wrong.
 *
 *  - short pick   the quantity is not there: the task closes short, the order ships less, and the shortage is never
 *                 dropped — a backorder order (or a cancelled quantity, per policy) when the pick list closes; the
 *                 system an order came from is told line by line. An order of which NOTHING could be picked is not
 *                 shipped empty: its fulfilment order is cancelled and the sales order waits for stock again.
 *  - substitute   this batch / bin is unusable but the product exists elsewhere: the unpicked quantity is reserved
 *                 again FEFO away from the reported row and new tasks join the same list.
 *  - put-back     the order will not ship (cancelled, wrong, re-planned): what was picked returns from PACK to the
 *                 bins it came from and the order is cancelled or goes back to waiting for allocation.
 *
 * Every stock change goes through the inventory engine. Locks: sales order → fulfilment order → pick list → task.
 */
class PickingService
{
    public const REASONS = ['notfound', 'damaged', 'expired', 'other'];

    private const REASON_AR = ['notfound' => 'غير موجود في الموقع', 'damaged' => 'تالف', 'expired' => 'منتهي الصلاحية', 'other' => 'سبب آخر'];

    private const REASON_EN = ['notfound' => 'not found at the bin', 'damaged' => 'damaged', 'expired' => 'expired', 'other' => 'other reason'];

    private const PL_SHAPE = ['fo' => ['number', 'status', 'customer'], 'fo.customer' => ['nameAr', 'zone'], 'tasks.*.bin' => ['code'], 'tasks.*.product' => ['sku', 'nameAr']];

    public function __construct(
        private readonly AuditService $audit,
        private readonly NotifyService $notify,
        private readonly SettingsService $settings,
        private readonly ExceptionsService $exceptions,
        private readonly InventoryService $inventory,
        private readonly BackorderService $backorders,
        private readonly CreditService $credit,
        private readonly FulfillmentSupport $support,
    ) {}

    // ───────────── pick lists and who holds them ─────────────

    /** @param  array{warehouse?:?string, status?:?string, mine?:mixed}  $filters */
    public function pickLists(Paging $page, array $filters, ?AuthUser $user = null): array
    {
        $query = PickList::with(['fo.customer', 'tasks' => fn ($q) => $q->orderBy('seq'), 'tasks.bin', 'tasks.product'])
            ->where('status', ($filters['status'] ?? null) ?: 'open')->orderBy('created_at')->orderBy('id');
        if (! empty($filters['warehouse'])) {
            $query->whereHas('warehouse', fn ($w) => $w->where('code', $filters['warehouse']));
        }
        if ($user && filter_var($filters['mine'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->where('assigned_to_id', $user->id);
        }

        return $page->paginate($query, fn (PickList $pl) => Shape::apply($pl->toArray(), self::PL_SHAPE));
    }

    /** The picker takes a free list. Asking again for a list one already holds changes nothing. */
    public function claim(AuthUser $user, string $idOrNumber): array
    {
        return DB::transaction(function () use ($user, $idOrNumber) {
            $pl = $this->lockList($idOrNumber);
            $this->assertOpen($pl);
            if ($pl->assigned_to_id && $pl->assigned_to_id !== $user->id) {
                throw $this->heldBy($pl);
            }
            if (! $pl->assigned_to_id) {
                $this->hold($user, $pl, $user->id, $user->username, 'PICK.CLAIM');
            }

            return $this->listState($pl, "قائمة التجهيز {$pl->number} بعهدتك الآن");
        });
    }

    /** The holder gives the list back, or a supervisor (`pick.override`) frees it. */
    public function release(AuthUser $user, string $idOrNumber): array
    {
        return DB::transaction(function () use ($user, $idOrNumber) {
            $pl = $this->lockList($idOrNumber);
            if ($pl->assigned_to_id && $pl->assigned_to_id !== $user->id && ! $user->can('pick.override')) {
                throw $this->heldBy($pl);
            }
            if ($pl->assigned_to_id) {
                $old = $pl->assigned_to;
                $pl->update(['assigned_to_id' => null, 'assigned_to' => null, 'assigned_at' => null]);
                $this->audit->log($user, ['action' => 'PICK.RELEASE', 'entityType' => 'PickList', 'entityId' => $pl->id, 'entityNumber' => $pl->number, 'field' => 'assignedTo', 'oldValue' => $old, 'newValue' => null]);
            }

            return $this->listState($pl, "قائمة التجهيز {$pl->number} متاحة لأي مجهّز");
        });
    }

    /** A supervisor gives the list to a picker (route permission `pick.override`). */
    public function assign(AuthUser $user, string $idOrNumber, string $username): array
    {
        $picker = User::with('roles.role.permissions.permission')->where('username', trim($username))->first();
        if (! $picker || ! $picker->active) {
            throw AppError::notFound('USER_NOT_FOUND', "المستخدم {$username} غير موجود أو غير نشط", "User {$username} not found or inactive");
        }
        $canPick = $picker->roles->contains(fn ($r) => $r->role?->permissions->contains(fn ($p) => $p->permission?->key === 'pick.confirm'));
        if (! $canPick) {
            throw AppError::rule('USER_CANNOT_PICK', "المستخدم {$picker->username} لا يملك صلاحية التجهيز", "User {$picker->username} has no picking permission");
        }

        return DB::transaction(function () use ($user, $idOrNumber, $picker) {
            $pl = $this->lockList($idOrNumber);
            $this->assertOpen($pl);
            if ($pl->assigned_to_id !== $picker->id) {
                $this->hold($user, $pl, $picker->id, $picker->username, 'PICK.ASSIGN');
                $this->notify->activity($user, 'PickList', $pl->id, $pl->number, "أُسندت قائمة التجهيز {$pl->number} إلى {$picker->name_ar}", "Pick list {$pl->number} assigned to {$picker->username}", ['worker']);
            }

            return $this->listState($pl, "أُسندت {$pl->number} إلى {$picker->username}");
        });
    }

    // ───────────── confirm a pick ─────────────

    /**
     * Confirm one pick task: right bin, right product (SKU or barcode), right batch when the bin holds more than one,
     * no over-pick, no expired / quarantined batch, no negative stock. Moves bin → PACK and consumes the allocation.
     *
     * @param  array{scannedBin?:?string, scannedProduct?:?string, scannedBatch?:?string, qty?:int|string|null}  $dto
     */
    public function pick(AuthUser $user, string $taskId, array $dto): array
    {
        $this->assertTaskExists($taskId);
        $txId = $this->inventory->newTxId();

        return DB::transaction(function () use ($user, $taskId, $dto, $txId) {
            // the task row is locked so a double scan cannot pick the same quantity twice
            [$so, $fo, $pl, $t] = $this->lockTask($taskId);
            if (! in_array($fo->status, ['alloc', 'picking'], true)) {
                throw AppError::rule('FO_STATE', "الطلب ليس في مرحلة التجهيز ({$fo->status})", "Order is not in picking stage ({$fo->status})");
            }
            if ($t->status === 'done') {
                throw AppError::conflict('TASK_DONE', 'السطر مكتمل — لا تكرار', 'Task already completed');
            }
            if ($t->status === 'cancelled') {
                throw AppError::rule('TASK_CANCELLED', 'المهمة ملغاة', 'Task is cancelled');
            }
            if ($t->status === 'short') {
                throw AppError::conflict('TASK_CLOSED', 'المهمة أُقفلت بنقص', 'Task was closed short');
            }
            $this->guardPicker($user, $pl);
            $scannedBin = trim((string) ($dto['scannedBin'] ?? ''));
            $sp = trim((string) ($dto['scannedProduct'] ?? ''));
            if ($scannedBin === '' || $sp === '') {
                throw AppError::validation('SCAN_REQUIRED', 'امسح الموقع والمنتج أولًا (Scan Location → Scan Product)', 'Scan location and product first');
            }
            if (mb_strtoupper($scannedBin) !== mb_strtoupper($t->bin->code)) {
                throw AppError::rule('WRONG_BIN', "Scan موقع خاطئ: {$scannedBin} — المطلوب {$t->bin->code}", "Wrong location {$scannedBin}, expected {$t->bin->code}");
            }
            if (mb_strtoupper($sp) !== mb_strtoupper($t->product->sku) && ! $t->product->barcodes->contains(fn ($b) => $b->barcode === $sp)) {
                throw AppError::rule('WRONG_PRODUCT', "Scan منتج خاطئ: {$sp} — المطلوب {$t->product->sku}", "Wrong product {$sp}, expected {$t->product->sku}");
            }
            // the batch: when the bin holds several batches of this product the picker must prove which one he took
            $batchNo = $t->batch?->batch_no ?? $t->batch_no;
            $scannedBatch = trim((string) ($dto['scannedBatch'] ?? ''));
            if ($scannedBatch === '' && $t->batch_id && self::binHoldsSeveralBatches($t->product_id, $t->bin_id)) {
                throw AppError::rule('BATCH_SCAN_REQUIRED', "الموقع {$t->bin->code} فيه أكثر من دفعة لهذا المنتج — امسح الدفعة المطلوبة {$batchNo}", "Bin {$t->bin->code} holds several batches of this product — scan batch {$batchNo}");
            }
            if ($scannedBatch !== '' && mb_strtoupper($scannedBatch) !== mb_strtoupper((string) $batchNo)) {
                $wanted = $batchNo ?: '—';
                throw AppError::rule('WRONG_BATCH', "Scan دفعة خاطئة: {$scannedBatch} — المطلوب {$wanted} (FEFO)", "Wrong batch {$scannedBatch}, expected {$wanted}");
            }
            $remaining = $t->qty - $t->picked_qty;
            $q = isset($dto['qty']) ? (int) $dto['qty'] : $remaining;
            if ($q <= 0) {
                throw AppError::validation('BAD_QTY', 'كمية غير صالحة', 'Invalid quantity');
            }
            if ($q > $remaining) {
                throw AppError::rule('OVER_PICK', "مرفوض: {$q} أكبر من المتبقي ({$remaining}) — لا Over-Picking", "{$q} exceeds remaining {$remaining} — no over-picking");
            }
            // expiry is a date: a batch is good through its last day, exactly as allocation (FEFO) reads it
            if ($t->batch?->expiry_date && $t->batch->expiry_date->lt(Carbon::today())) {
                throw AppError::rule('EXPIRED_BATCH', "الدفعة {$t->batch->batch_no} منتهية — لا يُصرف منها", 'Expired batch cannot be picked');
            }

            $row = InventoryBalance::where('product_id', $t->product_id)->where('bin_id', $t->bin_id)->where('batch_key', $t->batch_id ?? '')->first();
            if (! $row || $row->on_hand < $q) {
                $onHand = $row?->on_hand ?? 0;
                throw AppError::rule('NEGATIVE_STOCK', "الرصيد في {$t->bin->code} غير كافٍ ({$onHand}) — لا رصيد سالب", 'Insufficient stock at bin');
            }
            if ($row->quarantine) {
                throw AppError::rule('QUARANTINED', 'الدفعة '.($t->batch_no ?? '').' محجورة — لا يُصرف منها', 'Quarantined batch');
            }
            $pack = $this->inventory->specialBin($fo->warehouse_id, 'PACK');
            $mv = $this->inventory->post($user, $txId, [
                'type' => 'pick', 'productId' => $t->product_id, 'batchId' => $t->batch_id, 'qty' => $q,
                'from' => ['warehouseId' => $fo->warehouse_id, 'binId' => $t->bin_id], 'to' => ['warehouseId' => $fo->warehouse_id, 'binId' => $pack->id],
                'consumeReserved' => $q, 'reference' => ['type' => 'FulfillmentOrder', 'id' => $fo->id, 'number' => $fo->number],
                'note' => "{$pl->number} · {$fo->number}".($q < $remaining ? ' · جزئي' : ''),
            ]);
            $this->consumeAllocations($t, $q);
            if ($t->foLine->so_line_id) {
                SalesOrderLine::whereKey($t->foLine->so_line_id)->update(['picked_qty' => DB::raw("picked_qty + {$q}")]);
            }
            $before = $t->picked_qty;
            $np = $before + $q;
            $lineDone = $np >= $t->qty;
            $t->update(['picked_qty' => $np, 'status' => $lineDone ? 'done' : 'partial', 'picked_by_id' => $user->id, 'picked_by' => $user->username, 'picked_at' => now()]);
            FoLine::whereKey($t->fo_line_id)->update(['picked_qty' => DB::raw("picked_qty + {$q}")]);
            $this->audit->log($user, ['action' => 'PICK.CONFIRM', 'entityType' => 'PickTask', 'entityId' => $t->id, 'entityNumber' => $pl->number,
                'field' => $t->product->sku, 'oldValue' => $before, 'newValue' => $np, 'transactionId' => $txId]);
            $end = $this->afterTask($user, $so, $fo, $pl, $txId);
            $left = $t->qty - $np;
            $message = $end['closed']
                ? "اكتمل تجهيز {$fo->number} — انتقل لمحطة التعبئة".($end['note'] ? " · {$end['note']}" : '')
                : ($lineDone ? 'تم السطر — Scan الموقع والمنتج مطابق ✓' : "تجهيز جزئي: {$np} / {$t->qty} — المتبقي {$left}");

            return ['task' => $t->id, 'picked' => $np, 'remaining' => $left, 'lineDone' => $lineDone, 'orderDone' => $end['closed'], 'movement' => $mv->number, 'message' => $message]
                + ($end['backorder'] ? ['backorder' => $end['backorder']] : []);
        });
    }

    // ───────────── short pick ─────────────

    /**
     * Short pick: the rest of the task cannot be picked. The task closes `short`, the fulfilment-order line ships less
     * (`short_qty` remembers how much), the reservation of the missing units is given back, and — reason `notfound`
     * under `fulfillment.blockBinOnShortPick` — the stock row is frozen so the same empty bin is not allocated again
     * before it is counted.
     *
     * @param  array{reason:string, note?:?string}  $dto
     */
    public function shortPick(AuthUser $user, string $taskId, array $dto): array
    {
        $this->assertTaskExists($taskId);
        $reason = self::reason($dto);
        $note = self::note($dto);
        $txId = $this->inventory->newTxId();

        return DB::transaction(function () use ($user, $taskId, $reason, $note, $txId) {
            [$so, $fo, $pl, $t] = $this->lockTask($taskId);
            if (in_array($t->status, ['done', 'short', 'cancelled'], true)) {
                throw AppError::conflict('TASK_CLOSED', 'المهمة مغلقة', 'Task is closed');
            }
            if (! in_array($fo->status, ['alloc', 'picking'], true)) {
                throw AppError::rule('FO_STATE', "الطلب ليس في مرحلة التجهيز ({$fo->status})", "Order is not in picking stage ({$fo->status})");
            }
            $this->guardPicker($user, $pl);
            $shortQty = $t->qty - $t->picked_qty;
            $this->releaseUnpicked($t, $shortQty);
            $frozen = $this->recordShort($user, $so, $fo, $t, $shortQty, $reason, $note, true);
            $t->update(['status' => 'short', 'short_reason' => $reason, 'note' => $note, 'picked_by_id' => $user->id, 'picked_by' => $user->username, 'picked_at' => now()]);
            $this->audit->log($user, ['action' => 'PICK.SHORT', 'entityType' => 'PickTask', 'entityId' => $t->id, 'entityNumber' => $pl->number, 'field' => $t->product->sku,
                'newValue' => ['shortQty' => $shortQty, 'reason' => $reason, 'note' => $note, 'binFrozen' => $frozen], 'transactionId' => $txId]);
            $this->startPicking($user, $so, $fo, $txId);
            $end = $this->afterTask($user, $so, $fo, $pl, $txId);
            $this->reportShortage($so, $fo, $reason, $end);

            return [
                'ok' => true, 'shortQty' => $shortQty, 'reason' => $reason, 'binFrozen' => $frozen, 'orderStatus' => $end['status'], 'backorder' => $end['backorder'],
                'message' => "سُجّل نقص {$shortQty} من {$t->product->name_ar} — ".self::REASON_AR[$reason].($frozen ? ' · جُمّد رصيد الموقع حتى الجرد' : '').($end['note'] ? " · {$end['note']}" : ''),
            ];
        });
    }

    // ───────────── batch substitution ─────────────

    /**
     * The batch / bin of a task cannot be used, the product can: the unpicked quantity leaves its row and is reserved
     * again FEFO **away from that row** (for an expired batch: away from every row of that batch), as new tasks on the
     * same list. What cannot be found elsewhere goes the short-pick way. The reported row is frozen (`notfound`) or
     * handed to the inventory controller (`damaged` / `expired`).
     *
     * @param  array{reason:string, note?:?string}  $dto
     */
    public function substitute(AuthUser $user, string $taskId, array $dto): array
    {
        $this->assertTaskExists($taskId);
        $reason = self::reason($dto);
        $note = self::note($dto);
        $txId = $this->inventory->newTxId();

        return DB::transaction(function () use ($user, $taskId, $reason, $note, $txId) {
            [$so, $fo, $pl, $t] = $this->lockTask($taskId);
            if (! in_array($t->status, ['open', 'partial'], true)) {
                throw AppError::conflict('TASK_CLOSED', 'المهمة مغلقة', 'Task is closed');
            }
            if (! in_array($fo->status, ['alloc', 'picking'], true)) {
                throw AppError::rule('FO_STATE', "الطلب ليس في مرحلة التجهيز ({$fo->status})", "Order is not in picking stage ({$fo->status})");
            }
            $this->guardPicker($user, $pl);
            $unpicked = $t->qty - $t->picked_qty;
            $exclude = $this->rowsToAvoid($t, $reason);
            $this->releaseUnpicked($t, $unpicked);

            // reserve again elsewhere — as much as there is
            $take = 0;
            $newTasks = [];
            $soLineId = $t->foLine->so_line_id;
            if ($so && $soLineId) {
                $available = (int) $this->inventory->allocRows($t->product_id, $fo->warehouse_id, true)
                    ->reject(fn ($r) => in_array($r->id, $exclude, true))->sum(fn ($r) => $r->on_hand - $r->reserved);
                $take = min($unpicked, $available);
                if ($take > 0) {
                    $reserved = $this->inventory->reserve($user, $txId, ['soId' => $so->id, 'soLineId' => $soLineId, 'productId' => $t->product_id,
                        'warehouseId' => $fo->warehouse_id, 'qty' => $take, 'referenceNumber' => $so->number, 'excludeRows' => $exclude]);
                    $seq = (int) PickTask::where('pick_list_id', $pl->id)->max('seq');
                    foreach ($reserved['allocations'] as $al) {
                        $newTasks[] = PickTask::create([
                            'pick_list_id' => $pl->id, 'fo_line_id' => $t->fo_line_id, 'seq' => ++$seq, 'product_id' => $t->product_id, 'bin_id' => $al->bin_id, 'batch_id' => $al->batch_id,
                            'batch_no' => $al->batch_id ? Batch::whereKey($al->batch_id)->value('batch_no') : null, 'qty' => $al->qty, 'substituted' => true,
                            'note' => "بديل عن {$t->bin->code}".($t->batch_no ? " / {$t->batch_no}" : '').' — '.self::REASON_AR[$reason],
                        ])->id;
                    }
                }
            }
            $short = $unpicked - $take;
            $stamp = ['short_reason' => $reason, 'note' => $note, 'picked_by_id' => $user->id, 'picked_by' => $user->username, 'picked_at' => now()];
            if ($short > 0) {
                // nothing (or not enough) elsewhere: the rest is a short pick of this task
                $frozen = $this->recordShort($user, $so, $fo, $t, $short, $reason, $note, true);
                $t->update(['qty' => $t->picked_qty + $short, 'status' => 'short'] + $stamp);
            } else {
                $frozen = $this->recordShort($user, $so, $fo, $t, 0, $reason, $note, false);
                $t->update(($t->picked_qty > 0 ? ['qty' => $t->picked_qty, 'status' => 'done'] : ['status' => 'cancelled']) + $stamp);
            }
            $this->audit->log($user, ['action' => 'PICK.SUBSTITUTE', 'entityType' => 'PickTask', 'entityId' => $t->id, 'entityNumber' => $pl->number, 'field' => $t->product->sku,
                'oldValue' => ['bin' => $t->bin->code, 'batch' => $t->batch_no, 'unpicked' => $unpicked],
                'newValue' => ['reason' => $reason, 'note' => $note, 'substituted' => $take, 'short' => $short, 'tasks' => $newTasks, 'binFrozen' => $frozen], 'transactionId' => $txId]);
            $this->notify->activity($user, 'FulfillmentOrder', $fo->id, $fo->number,
                "دفعة بديلة في {$fo->number}: {$t->product->name_ar} — ".self::REASON_AR[$reason]." في {$t->bin->code}".($take > 0 ? " · أُعيد حجز {$take} من موقع آخر" : '').($short > 0 ? " · نقص {$short}" : ''),
                "Batch substitution on {$fo->number}: {$t->product->sku} — ".self::REASON_EN[$reason], ['wm']);
            $this->startPicking($user, $so, $fo, $txId);
            $end = $this->afterTask($user, $so, $fo, $pl, $txId);
            if ($short > 0) {
                $this->reportShortage($so, $fo, $reason, $end);
            }
            $message = $take > 0
                ? "أُعيد حجز {$take} من {$t->product->name_ar} من موقع آخر (FEFO)".($short > 0 ? " — والباقي {$short} نقص" : '')
                : "لا رصيد بديل لـ {$t->product->name_ar} — سُجّل نقص {$short}";

            return [
                'ok' => true, 'substituted' => $take, 'short' => $short, 'tasks' => $newTasks, 'binFrozen' => $frozen, 'orderStatus' => $end['status'], 'backorder' => $end['backorder'],
                'message' => $message.($frozen ? ' · جُمّد رصيد الموقع حتى الجرد' : '').($end['note'] ? " · {$end['note']}" : ''),
            ];
        });
    }

    // ───────────── put-back ─────────────

    /**
     * The order will not ship: every picked quantity goes PACK → the bin it was picked from (type `restock`; `bins`
     * names another bin for a task whose own bin can no longer take stock), the tasks and the list are cancelled, the
     * fulfilment order is cancelled, the reservations are released and the sales order is cancelled (`cancelOrder`) or
     * goes back to `confirmed` / backordered so that allocation can reserve it again.
     *
     * @param  array{reason:string, cancelOrder?:bool, bins?:array<string,string>}  $dto
     */
    public function putback(AuthUser $user, string $idOrNumber, array $dto): array
    {
        $foId = $this->support->findFo($idOrNumber)->id;
        $reason = trim((string) ($dto['reason'] ?? ''));
        if ($reason === '') {
            throw AppError::validation('REASON_REQUIRED', 'اكتب سبب إرجاع التجهيز', 'A reason is required');
        }
        $cancelOrder = (bool) ($dto['cancelOrder'] ?? false);
        $bins = (array) ($dto['bins'] ?? []);
        $txId = $this->inventory->newTxId();

        return DB::transaction(function () use ($user, $foId, $reason, $cancelOrder, $bins, $txId) {
            [$so, $fo] = $this->support->lockOrder($foId);
            if (! in_array($fo->status, ['picking', 'picked'], true)) {
                throw AppError::rule('PUTBACK_STATE', "الإرجاع للمواقع متاح أثناء التجهيز أو بعده وقبل التعبئة فقط (الحالة {$fo->status})".($fo->status === 'packed' ? ' — فكّ التعبئة أولًا' : ''), "Put-back needs a picking / picked order ({$fo->status})");
            }
            if ($cancelOrder && $so?->source_system) {
                throw AppError::rule('PUTBACK_EXTERNAL', "الطلب {$so->number} وارد من نظام آخر — إلغاؤه قرار ذلك النظام؛ أرجِع التجهيز بلا إلغاء", 'The order came from another system, which owns its cancellation');
            }
            Sm::assert('FO_TRANSITIONS', $fo->status, 'cancelled', 'FO_TRANSITION');
            $lists = PickList::where('fo_id', $fo->id)->lockForUpdate()->get();
            $tasks = PickTask::with(['bin', 'product'])->whereIn('pick_list_id', $lists->pluck('id')->all())->orderBy('seq')->lockForUpdate()->get();
            $pack = $this->inventory->specialBin($fo->warehouse_id, 'PACK');
            $returned = 0;
            foreach ($tasks as $t) {
                if ($t->picked_qty <= 0) {
                    continue;
                }
                $bin = isset($bins[$t->id]) && trim((string) $bins[$t->id]) !== '' ? $this->inventory->bin($fo->warehouse_id, (string) $bins[$t->id]) : $t->bin;
                $check = $this->inventory->locCheck($t->product_id, $bin->id);
                if (! $check['ok']) {
                    throw AppError::rule('BIN_UNUSABLE', "لا يمكن إرجاع {$t->product->sku} إلى {$bin->code}: {$check['why']} — حدّد موقعًا بديلًا لهذه المهمة", "Cannot return {$t->product->sku} to {$bin->code}: {$check['whyEn']} — name another bin for this task",
                        ['taskId' => $t->id, 'sku' => $t->product->sku, 'bin' => $bin->code, 'qty' => $t->picked_qty]);
                }
                $this->inventory->post($user, $txId, [
                    'type' => 'restock', 'productId' => $t->product_id, 'batchId' => $t->batch_id, 'qty' => $t->picked_qty,
                    'from' => ['warehouseId' => $fo->warehouse_id, 'binId' => $pack->id], 'to' => ['warehouseId' => $fo->warehouse_id, 'binId' => $bin->id],
                    'reference' => ['type' => 'FulfillmentOrder', 'id' => $fo->id, 'number' => $fo->number], 'note' => "إرجاع التجهيز PACK → {$bin->code} — {$reason}",
                ]);
                $returned += $t->picked_qty;
            }
            // a task that ended short keeps saying so; everything else is cancelled
            PickTask::whereIn('id', $tasks->where('status', '!=', 'short')->pluck('id')->all())->update(['status' => 'cancelled']);
            PickList::whereIn('id', $lists->pluck('id')->all())->update(['status' => 'cancelled', 'completed_at' => now()]);
            FoLine::where('fo_id', $fo->id)->update(['picked_qty' => 0, 'packed_qty' => 0]);
            $from = $fo->status;
            $fo->update(['status' => 'cancelled']);
            $this->audit->status($user, 'FulfillmentOrder', $fo->id, $fo->number, $from, 'cancelled', "إرجاع التجهيز — {$reason}", $txId);
            $trip = $this->support->detachFromTrip($user, $fo, "أُلغي أمر التنفيذ (إرجاع التجهيز) — {$reason}", $txId);
            $soStatus = $so ? $this->sendOrderBack($user, $so, $cancelOrder, "إرجاع تجهيز {$fo->number} — {$reason}", $txId) : null;
            $this->audit->log($user, ['action' => 'PICK.PUTBACK', 'entityType' => 'FulfillmentOrder', 'entityId' => $fo->id, 'entityNumber' => $fo->number,
                'newValue' => ['reason' => $reason, 'returnedQty' => $returned, 'cancelOrder' => $cancelOrder, 'order' => $so?->number, 'orderStatus' => $soStatus, 'trip' => $trip], 'transactionId' => $txId]);
            $this->notify->activity($user, 'FulfillmentOrder', $fo->id, $fo->number,
                "أُرجع تجهيز {$fo->number} إلى المواقع ({$returned} قطعة) وأُلغي أمر التنفيذ — {$reason}".($so ? ($cancelOrder ? " · أُلغي {$so->number}" : " · {$so->number} بانتظار التخصيص من جديد") : ''),
                "Picking of {$fo->number} put back ({$returned} units) — {$reason}", ['wm', 'sales']);
            $this->support->emit($so?->id, 'order.putback', ['fulfilmentOrder' => $fo->number, 'reason' => $reason]);

            return [
                'ok' => true, 'fo' => $fo->number, 'status' => 'cancelled', 'returnedQty' => $returned, 'order' => $so?->number, 'orderStatus' => $soStatus, 'trip' => $trip,
                'message' => "أُرجعت {$returned} قطعة إلى مواقعها وأُلغي {$fo->number}".($so ? ($cancelOrder ? " — أُلغي أمر البيع {$so->number}" : " — {$so->number} عاد بانتظار التخصيص") : ''),
            ];
        });
    }

    // ───────────── what a closed task means for the order ─────────────

    /** The first report on an order that is still `alloc` starts its picking (a short pick is picking work too). */
    private function startPicking(AuthUser $user, ?SalesOrder $so, FulfillmentOrder $fo, string $txId): void
    {
        if ($fo->status !== 'alloc') {
            return;
        }
        $fo->update(['status' => 'picking']);
        $this->audit->status($user, 'FulfillmentOrder', $fo->id, $fo->number, 'alloc', 'picking', null, $txId);
        if ($so) {
            $so->update(['status' => 'picking']);
            $this->support->emit($so->id, 'picking.started', ['fulfilmentOrder' => $fo->number]);
        }
    }

    /**
     * After a task changed: the order moves to `picking`, or — when no task is open any more — the pick list closes:
     * `picked` when something was picked (the shortage, if any, becomes ONE backorder order or one cancellation), or
     * cancelled when nothing was — an empty order is never packed, loaded or delivered.
     *
     * @return array{closed:bool, cancelled:bool, status:string, backorder:?string, note:?string}
     */
    private function afterTask(AuthUser $user, ?SalesOrder $so, FulfillmentOrder $fo, PickList $pl, string $txId): array
    {
        $open = PickTask::where('pick_list_id', $pl->id)->whereIn('status', ['open', 'partial'])->count();
        if ($open > 0) {
            if ($fo->status !== 'picking') {
                $from = $fo->status;
                $fo->update(['status' => 'picking']);
                $this->audit->status($user, 'FulfillmentOrder', $fo->id, $fo->number, $from, 'picking', null, $txId);
                if ($so) {
                    $so->update(['status' => 'picking']);
                    $this->support->emit($so->id, 'picking.started', ['fulfilmentOrder' => $fo->number]);
                }
            }

            return ['closed' => false, 'cancelled' => false, 'status' => 'picking', 'backorder' => null, 'note' => null];
        }
        $lines = FoLine::where('fo_id', $fo->id)->orderBy('line_no')->get();
        if ((int) $lines->sum('picked_qty') === 0) {
            return $this->cancelEmpty($user, $so, $fo, $pl, $txId);
        }
        $short = $lines->filter(fn (FoLine $l) => $l->short_qty > 0);
        $from = $fo->status;
        $fo->update(['status' => 'picked']);
        $pl->update(['status' => 'done', 'completed_at' => now()]);
        $this->audit->status($user, 'FulfillmentOrder', $fo->id, $fo->number, $from, 'picked', $short->isNotEmpty() ? 'short pick' : null, $txId);
        $backorder = null;
        $note = null;
        if ($so) {
            // the commercial side of the shortage, once per pick list: every short line together
            $parts = $short->filter(fn (FoLine $l) => $l->so_line_id)->mapWithKeys(fn (FoLine $l) => [$l->so_line_id => $l->short_qty])->all();
            if ($parts && ! $so->source_system) {
                if ((bool) $this->settings->get('fulfillment.backorderShortPicks')) {
                    $backorder = $this->backorders->split($user, $so, $parts, 'short_pick')->number;
                    $note = "الناقص صار طلبًا معلّقًا {$backorder}";
                } else {
                    $this->backorders->cancel($user, $so, $parts, 'short_pick');
                    $note = 'أُلغيت الكمية الناقصة من أمر البيع';
                }
            }
            $so->update(['status' => 'picked']);
            $this->support->emit($so->id, 'picking.completed', ['fulfilmentOrder' => $fo->number]
                + ($short->isNotEmpty() ? ['shortPicked' => true, 'lines' => $this->support->shortLines($fo->id)] : []));
        }

        return ['closed' => true, 'cancelled' => false, 'status' => 'picked', 'backorder' => $backorder, 'note' => $note];
    }

    /** Every task ended short and nothing was picked: no shipment. The order waits for stock again, or is cancelled. */
    private function cancelEmpty(AuthUser $user, ?SalesOrder $so, FulfillmentOrder $fo, PickList $pl, string $txId): array
    {
        $from = $fo->status;
        Sm::assert('FO_TRANSITIONS', $from, 'cancelled', 'FO_TRANSITION');
        $fo->update(['status' => 'cancelled']);
        $pl->update(['status' => 'cancelled', 'completed_at' => now()]);
        $this->audit->status($user, 'FulfillmentOrder', $fo->id, $fo->number, $from, 'cancelled', 'لم يُجهَّز منه شيء — نقص كامل', $txId);
        $this->support->detachFromTrip($user, $fo, 'لم يُجهَّز منه شيء (نقص كامل) — أُلغي أمر التنفيذ', $txId);
        $note = "لم يُجهَّز شيء من {$fo->number} — أُلغي أمر التنفيذ";
        $status = null;
        if ($so) {
            // our order under the "cancel" policy is cancelled; otherwise (and always for another system's order) it waits
            $cancel = ! $so->source_system && ! (bool) $this->settings->get('fulfillment.backorderShortPicks');
            $status = $this->sendOrderBack($user, $so, $cancel, "نقص كامل عند تجهيز {$fo->number}", $txId);
            $note .= $cancel ? " وأمر البيع {$so->number}" : " — {$so->number} عاد بانتظار المخزون";
            $this->notify->activity($user, 'SalesOrder', $so->id, $so->number,
                $cancel ? "أُلغي {$so->number}: لم يتوفر شيء منه عند التجهيز — أبلغ العميل" : "{$so->number} عاد بانتظار المخزون: لم يتوفر شيء منه عند التجهيز ({$fo->number})",
                "Nothing of {$so->number} could be picked ({$fo->number})", ['sales', 'proc']);
        }

        return ['closed' => true, 'cancelled' => true, 'status' => 'cancelled', 'backorder' => null, 'note' => $note, 'orderStatus' => $status];
    }

    /**
     * The sales order of a fulfilment order that was cancelled without shipping: its reservations are released and its
     * line counters reset; then it is cancelled, or goes back to `confirmed` (`fulfil_status = backordered`) where
     * allocation picks it up again. Returns the new status.
     */
    private function sendOrderBack(AuthUser $user, SalesOrder $so, bool $cancel, string $why, string $txId): string
    {
        $this->inventory->release($so->id);
        SalesOrderLine::where('so_id', $so->id)->update(['reserved_qty' => 0, 'allocated_qty' => 0, 'picked_qty' => 0]);
        $from = $so->status;
        $to = $cancel ? 'cancelled' : 'confirmed';
        // the flag says "part of this order could not ship on it": an order that is whole again does not carry it
        $shortage = SalesOrderLine::where('so_id', $so->id)->where('short_qty', '>', 0)->exists();
        $so->update(['status' => $to, 'has_shortage' => $shortage] + ($cancel ? [] : ['fulfil_status' => 'backordered']));
        $this->audit->status($user, 'SalesOrder', $so->id, $so->number, $from, $to, $why, $txId);
        if ($cancel && ! $so->source_system) {
            $this->credit->sync($so->customer_id); // the order no longer counts as exposure
        }

        return $to;
    }

    /** Tells the system an order came from what it will not get (our own orders: nothing to send). */
    private function reportShortage(?SalesOrder $so, FulfillmentOrder $fo, string $reason, array $end): void
    {
        if (! $so?->source_system) {
            return;
        }
        $this->support->emit($so->id, 'order.short', ['fulfilmentOrder' => $fo->number, 'reason' => $reason, 'lines' => $this->support->shortLines($fo->id)]
            + ($end['cancelled'] ? ['fulfilmentCancelled' => true] : []));
    }

    // ───────────── the stock and document side of a shortage ─────────────

    /**
     * Gives the reservation of a task's unpicked units back: balance row (reserved / allocated), the allocations of
     * that order line at that bin / batch, their reservations and the sales-order line counters all go down by the
     * same quantity, so reserved = Σ open allocations still holds. Returns what was released.
     */
    private function releaseUnpicked(PickTask $t, int $qty): int
    {
        $left = $qty;
        foreach ($this->activeAllocations($t)->reverse() as $al) {
            if ($left <= 0) {
                break;
            }
            $give = min($left, $al->qty - $al->picked_qty);
            if ($give <= 0) {
                continue;
            }
            // an allocation that was partly picked is closed at what was picked; an untouched one is released whole
            $rest = $al->qty - $give;
            $al->update($rest > $al->picked_qty ? ['qty' => $rest] : ($al->picked_qty > 0 ? ['qty' => $al->picked_qty, 'status' => 'consumed'] : ['status' => 'released']));
            $reservation = InventoryReservation::whereKey($al->reservation_id)->lockForUpdate()->first();
            if ($reservation) {
                $newQty = max(0, $reservation->qty - $give);
                $reservation->update(['qty' => $newQty] + ($newQty === 0 ? ['status' => 'released', 'released_at' => now()] : []));
            }
            $left -= $give;
        }
        $released = $qty - $left;
        if ($released > 0) {
            $this->inventory->unreserve($t->product_id, $t->bin_id, $t->batch_id, $released);
            if ($t->foLine->so_line_id) {
                $sl = SalesOrderLine::whereKey($t->foLine->so_line_id)->lockForUpdate()->first();
                $sl?->update(['reserved_qty' => max(0, $sl->reserved_qty - $released), 'allocated_qty' => max(0, $sl->allocated_qty - $released)]);
            }
        }

        return $released;
    }

    /**
     * Records what the picker reported about a stock row. With `$qty` > 0 the order line ships that much less. Reason
     * `notfound` freezes the row (policy); `damaged` / `expired` open an exception for the inventory controller; a
     * shortage always opens one for the warehouse manager. Returns true when the row is frozen.
     */
    private function recordShort(AuthUser $user, ?SalesOrder $so, FulfillmentOrder $fo, PickTask $t, int $qty, string $reason, ?string $note, bool $isShort): bool
    {
        if ($qty > 0) {
            FoLine::whereKey($t->fo_line_id)->update(['qty' => DB::raw("qty - {$qty}"), 'short_qty' => DB::raw("short_qty + {$qty}")]);
            if ($so && ! $so->has_shortage) {
                $so->update(['has_shortage' => true]);
            }
        }
        $frozen = false;
        if ($reason === 'notfound' && (bool) $this->settings->get('fulfillment.blockBinOnShortPick')) {
            $row = InventoryBalance::where('product_id', $t->product_id)->where('bin_id', $t->bin_id)->where('batch_key', $t->batch_id ?? '')->first();
            if ($row && ! $row->blocked) {
                $this->inventory->setBlocked([$row->id], true);
                $this->audit->log($user, ['action' => 'INVENTORY.FREEZE', 'entityType' => 'InventoryBalance', 'entityId' => $row->id, 'entityNumber' => "{$t->product->sku}@{$t->bin->code}",
                    'field' => 'blocked', 'oldValue' => false, 'newValue' => true]);
            }
            $frozen = (bool) $row;
        }
        $where = "{$t->bin->code}".($t->batch_no ? " / دفعة {$t->batch_no}" : '');
        $why = self::REASON_AR[$reason].($note ? " — {$note}" : '');
        $ref = ['entityType' => 'FulfillmentOrder', 'entityId' => $fo->id, 'entityNumber' => $fo->number];
        if ($isShort && $qty > 0) {
            $this->exceptions->raise($user, $ref + ['kind' => 'missing', 'severity' => 'w', 'ownerRole' => 'wm',
                'textAr' => "نقص تجهيز {$t->product->name_ar}: {$qty} في {$where} — {$why}".($frozen ? ' · جُمّد رصيد الموقع ولن يُخصَّص حتى يُجرد' : ''),
                'textEn' => "Short pick {$t->product->sku}: {$qty} at {$t->bin->code} — ".self::REASON_EN[$reason].($frozen ? ' · the stock row is frozen until it is counted' : '')]);
            if ($so) {
                $this->notify->activity($user, 'SalesOrder', $so->id, $so->number, "نقص تجهيز في {$so->number} ({$fo->number}): {$t->product->name_ar} × {$qty} — {$why}",
                    "Short pick on {$so->number}: {$t->product->sku} × {$qty}", ['sales']);
            }
        } elseif ($frozen) {
            $this->exceptions->raise($user, $ref + ['kind' => 'missing', 'severity' => 'w', 'ownerRole' => 'wm',
                'textAr' => "الرصيد غير موجود في {$where} ({$t->product->name_ar}) — جُمّد حتى يُجرد؛ أُعيد الحجز من موقع آخر", 'textEn' => "Stock not found at {$t->bin->code} ({$t->product->sku}) — frozen until counted; reserved elsewhere"]);
        }
        if (in_array($reason, ['damaged', 'expired'], true)) {
            $this->exceptions->raise($user, $ref + ['kind' => $reason === 'damaged' ? 'damaged' : 'other', 'severity' => 'w', 'ownerRole' => 'inv',
                'textAr' => "رصيد {$t->product->name_ar} في {$where}: {$why} — انقله إلى التالف / الحجر قبل أن يُخصَّص لطلب آخر",
                'textEn' => "Stock of {$t->product->sku} at {$t->bin->code} reported ".self::REASON_EN[$reason].' — move it to damaged / quarantine before it is allocated again']);
        }

        return $frozen;
    }

    /** Balance rows a substitution must not allocate from: the task's row; for an expired batch every row of that batch. */
    private function rowsToAvoid(PickTask $t, string $reason): array
    {
        $query = InventoryBalance::where('product_id', $t->product_id);
        if ($reason === 'expired' && $t->batch_id) {
            $query->where('batch_id', $t->batch_id);
        } else {
            $query->where('bin_id', $t->bin_id)->where('batch_key', $t->batch_id ?? '');
        }

        return $query->pluck('id')->all();
    }

    // ───────────── internals ─────────────

    private function assertTaskExists(string $taskId): void
    {
        if (! PickTask::whereKey($taskId)->exists()) {
            throw AppError::notFound('TASK_NOT_FOUND', 'مهمة التجهيز غير موجودة', 'Pick task not found');
        }
    }

    /** @return array{0: ?SalesOrder, 1: FulfillmentOrder, 2: PickList, 3: PickTask} */
    private function lockTask(string $taskId): array
    {
        $probe = PickTask::whereKey($taskId)->firstOrFail(['id', 'fo_line_id', 'pick_list_id']);
        [$so, $fo] = $this->support->lockOrder(FoLine::whereKey($probe->fo_line_id)->value('fo_id'));
        $pl = PickList::whereKey($probe->pick_list_id)->lockForUpdate()->firstOrFail();
        $t = PickTask::with(['bin', 'product.barcodes', 'batch', 'foLine'])->whereKey($taskId)->lockForUpdate()->firstOrFail();

        return [$so, $fo, $pl, $t];
    }

    private function lockList(string $idOrNumber): PickList
    {
        return PickList::where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->lockForUpdate()->first()
            ?? throw AppError::notFound('PICK_LIST_NOT_FOUND', 'قائمة التجهيز غير موجودة', 'Pick list not found');
    }

    private function assertOpen(PickList $pl): void
    {
        if ($pl->status !== 'open') {
            throw AppError::rule('PICK_LIST_CLOSED', "قائمة التجهيز {$pl->number} مغلقة ({$pl->status})", "Pick list {$pl->number} is {$pl->status}");
        }
    }

    private function heldBy(PickList $pl): AppError
    {
        return AppError::conflict('PICK_ASSIGNED', "قائمة التجهيز {$pl->number} بعهدة {$pl->assigned_to} — يحرّرها هو أو يعيد المشرف إسنادها", "Pick list {$pl->number} is held by {$pl->assigned_to}");
    }

    private function hold(AuthUser $by, PickList $pl, string $userId, string $username, string $action): void
    {
        $old = $pl->assigned_to;
        $pl->update(['assigned_to_id' => $userId, 'assigned_to' => $username, 'assigned_at' => now()]);
        $this->audit->log($by, ['action' => $action, 'entityType' => 'PickList', 'entityId' => $pl->id, 'entityNumber' => $pl->number, 'field' => 'assignedTo', 'oldValue' => $old, 'newValue' => $username]);
    }

    /** A list held by someone else is his (unless the caller may override); a free list becomes the caller's on his first scan. */
    private function guardPicker(AuthUser $user, PickList $pl): void
    {
        if ($pl->assigned_to_id && $pl->assigned_to_id !== $user->id) {
            if (! $user->can('pick.override')) {
                throw $this->heldBy($pl);
            }

            return;
        }
        if (! $pl->assigned_to_id) {
            $this->hold($user, $pl, $user->id, $user->username, 'PICK.CLAIM');
        }
    }

    private function listState(PickList $pl, string $message): array
    {
        return ['pickList' => $pl->number, 'status' => $pl->status, 'assignedTo' => $pl->assigned_to, 'assignedToId' => $pl->assigned_to_id, 'assignedAt' => $pl->assigned_at, 'message' => $message];
    }

    /** True when the bin holds stock of more than one batch of the product — the scan of the bin and the SKU cannot tell them apart. */
    public static function binHoldsSeveralBatches(string $productId, string $binId): bool
    {
        return InventoryBalance::where('product_id', $productId)->where('bin_id', $binId)->where('on_hand', '>', 0)->count() > 1;
    }

    /**
     * The active allocations a pick task draws from (same order line, bin and batch), oldest first, locked.
     *
     * @return Collection<int, InventoryAllocation>
     */
    private function activeAllocations(PickTask $t)
    {
        return InventoryAllocation::where('bin_id', $t->bin_id)->where('status', 'active')
            ->when($t->foLine->so_line_id, fn ($q, $soLineId) => $q->where('so_line_id', $soLineId))
            ->when($t->batch_id, fn ($q, $batchId) => $q->where('batch_id', $batchId), fn ($q) => $q->whereNull('batch_id'))
            ->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();
    }

    /** Consumes the picked quantity from the task's allocations, never more from one than it still holds. */
    private function consumeAllocations(PickTask $t, int $qty): void
    {
        $left = $qty;
        foreach ($this->activeAllocations($t) as $al) {
            if ($left <= 0) {
                break;
            }
            $take = min($left, $al->qty - $al->picked_qty);
            if ($take <= 0) {
                continue;
            }
            $al->update(['picked_qty' => $al->picked_qty + $take, 'status' => $al->picked_qty + $take >= $al->qty ? 'consumed' : 'active']);
            $left -= $take;
        }
    }

    private static function reason(array $dto): string
    {
        $reason = (string) ($dto['reason'] ?? '');
        if (! in_array($reason, self::REASONS, true)) {
            throw AppError::validation('BAD_REASON', 'اختر سببًا: غير موجود، تالف، منتهٍ، أو سبب آخر', 'Reason must be one of: '.implode(', ', self::REASONS));
        }
        if ($reason === 'other' && self::note($dto) === null) {
            throw AppError::validation('NOTE_REQUIRED', 'اكتب توضيحًا عند اختيار «سبب آخر»', 'A note is required with reason "other"');
        }

        return $reason;
    }

    private static function note(array $dto): ?string
    {
        $note = trim((string) ($dto['note'] ?? ''));

        return $note === '' ? null : $note;
    }
}
