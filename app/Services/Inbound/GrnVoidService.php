<?php

namespace App\Services\Inbound;

use App\Models\GoodsReceipt;
use App\Models\GrnLine;
use App\Models\InboundShipment;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\OpsException;
use App\Models\PoLine;
use App\Models\PurchaseOrder;
use App\Models\PutawayTask;
use App\Models\ReturnOrder;
use App\Models\ShipmentLine;
use App\Models\StagingEntry;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceLine;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Exceptions\ExceptionsService;
use App\Services\Inventory\InventoryService;
use App\Services\Procurement\ProcurementHelpers as H;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Sm;
use Illuminate\Support\Facades\DB;

/**
 * Voiding a goods receipt that was posted wrongly (wrong shipment, wrong quantities, wrong batch) — the correction
 * that did not exist: until now the only way back was a manual adjustment that left the purchase order, the supplier
 * accrual and the putaway tasks describing goods that were never there.
 *
 * Allowed only while the receipt is UNTOUCHED: every putaway task still open and unstarted, the stock still where the
 * receipt put it (STG-IN / DMG-01), no supplier invoice billed on its quantity, no follow-up shipment already waiting.
 * After that the goods have a life of their own and the way back is a purchase return (`GRN_VOID_LATE`).
 *
 * One transaction: `grnvoid` movements out of STG-IN / DMG-01 at each receipt movement's own unit cost (Dr GRNI /
 * Cr inventory — the accrual and the stock value return to what they were), putaway tasks and staging entries
 * cancelled, PO-line and shipment-line counters and the PO status / open quantity rolled back, the shipment reopened
 * for inspection so it can be received correctly, the receipt stamped, its exceptions resolved with a note.
 */
class GrnVoidService
{
    /** Shipments still waiting for goods: only one may exist per purchase order. */
    private const AWAITING_GOODS = ['expected', 'arrived', 'inspecting'];

    public function __construct(
        private readonly AuditService $audit,
        private readonly NotifyService $notify,
        private readonly ExceptionsService $exceptions,
        private readonly InventoryService $inventory,
    ) {}

    /**
     * May this receipt be voided now? When not, `code` / `blocker` / `reason*` say what stands in the way.
     *
     * @return array{allowed:bool, code:?string, blocker:?string, reasonAr:?string, reasonEn:?string, details:?array}
     */
    public function state(GoodsReceipt $g): array
    {
        $no = fn (string $code, string $blocker, string $ar, string $en, array $details = []) => ['allowed' => false, 'code' => $code, 'blocker' => $blocker, 'reasonAr' => $ar, 'reasonEn' => $en, 'details' => ['blocker' => $blocker] + $details];
        if ($g->voided_at) {
            return $no('GRN_VOIDED', 'voided', "إذن الاستلام {$g->number} ملغى مسبقًا", "Receipt {$g->number} is already voided");
        }
        $return = 'الطريق الآن مرتجع مشتريات لإعادة البضاعة للمورد';
        $returnEn = 'the way back is now a purchase return';

        // 1. putaway not started on any task
        $started = PutawayTask::where('grn_id', $g->id)->where(fn ($w) => $w->where('status', 'done')->orWhere('done_qty', '>', 0))->orderBy('number')->pluck('number')->all();
        if ($started) {
            $list = implode('، ', $started);

            return $no('GRN_VOID_LATE', 'putaway', "بدأ تخزين بضاعة {$g->number} (المهام: {$list}) — لم يعد الإذن قابلًا للإلغاء؛ {$return}", "Putaway of {$g->number} has started ({$list}) — {$returnEn}", ['tasks' => $started]);
        }

        // 1b. a purchase return was raised on this receipt: the return is already the way back (cancel it first if it was a mistake)
        $returns = ReturnOrder::where('grn_id', $g->id)->whereNotIn('status', ['cancelled', 'rejected'])->orderBy('number')->pluck('number')->all();
        if ($returns) {
            $list = implode('، ', $returns);

            return $no('GRN_VOID_LATE', 'return', "على {$g->number} مرتجع مشتريات ({$list}) — يُسوّى الإذن عبر المرتجع، أو ألغِ المرتجع أولًا إن أُنشئ بالخطأ", "A purchase return ({$list}) exists on {$g->number} — settle the receipt through it, or cancel the return first", ['returns' => $returns]);
        }

        // 2. the stock is still where the receipt put it (accepted in STG-IN, damaged in DMG-01), free of any hold
        $need = [];
        foreach ($this->receiptMovements($g->id) as $m) {
            $key = "{$m->product_id}|{$m->dst_bin_id}|{$m->batch_id}";
            $need[$key] ??= ['productId' => $m->product_id, 'binId' => $m->dst_bin_id, 'batchId' => $m->batch_id, 'bin' => $m->dstBin?->code, 'type' => $m->type, 'qty' => 0];
            $need[$key]['qty'] += (int) $m->qty;
        }
        foreach ($need as $n) {
            $row = InventoryBalance::where('product_id', $n['productId'])->where('bin_id', $n['binId'])
                ->when($n['batchId'], fn ($q) => $q->where('batch_id', $n['batchId']), fn ($q) => $q->whereNull('batch_id'))->first();
            $free = $row ? (int) $row->on_hand - (int) $row->reserved : 0;
            if ($free < $n['qty']) {
                return $n['type'] === 'dmg'
                    ? $no('GRN_VOID_LATE', 'damaged', "الكمية التالفة من {$g->number} لم تعد كاملة في منطقة التالف {$n['bin']} (المتاح {$free} من {$n['qty']}) — سُوّيت أو حُجزت لمرتجع؛ {$return}", "The damaged quantity of {$g->number} is no longer fully in {$n['bin']} ({$free} of {$n['qty']}) — {$returnEn}", ['bin' => $n['bin'], 'free' => $free, 'needed' => $n['qty']])
                    : $no('GRN_VOID_LATE', 'stock', "بضاعة {$g->number} لم تعد كاملة في منطقة الاستلام {$n['bin']} (المتاح {$free} من {$n['qty']}) — {$return}", "The goods of {$g->number} are no longer fully in {$n['bin']} ({$free} of {$n['qty']}) — {$returnEn}", ['bin' => $n['bin'], 'free' => $free, 'needed' => $n['qty']]);
            }
        }

        // 3. no supplier invoice billed on this receipt's quantity: what other live receipts accepted must still cover what was invoiced
        $mine = GrnLine::where('grn_id', $g->id)->whereNotNull('po_line_id')->selectRaw('po_line_id, sum(accepted_qty) as qty')->groupBy('po_line_id')->pluck('qty', 'po_line_id');
        if ($mine->isNotEmpty()) {
            $others = GrnLine::whereIn('po_line_id', $mine->keys())->where('grn_id', '!=', $g->id)->whereHas('grn', fn ($q) => $q->whereNull('voided_at'))
                ->selectRaw('po_line_id, sum(accepted_qty) as qty')->groupBy('po_line_id')->pluck('qty', 'po_line_id');
            $invoiced = SupplierInvoiceLine::whereIn('po_line_id', $mine->keys())->whereHas('invoice', fn ($q) => $q->where('status', '!=', 'cancelled'))
                ->selectRaw('po_line_id, sum(qty) as qty')->groupBy('po_line_id')->pluck('qty', 'po_line_id');
            $billed = $mine->keys()->filter(fn ($id) => (int) ($invoiced[$id] ?? 0) > (int) ($others[$id] ?? 0))->values();
            if ($billed->isNotEmpty()) {
                $numbers = SupplierInvoice::where('status', '!=', 'cancelled')->whereHas('lines', fn ($q) => $q->whereIn('po_line_id', $billed))->orderBy('number')->pluck('number')->all();
                $list = implode('، ', $numbers);

                return $no('GRN_VOID_LATE', 'invoice', "كمية {$g->number} مفوترة من المورد ({$list}) — ألغِ الفاتورة أولًا، أو {$return} بإشعار مدين", "The quantity of {$g->number} is billed on {$list} — cancel the invoice first, or raise a purchase return with a debit note", ['invoices' => $numbers]);
            }
        }

        // 4. a follow-up shipment already waits for the remainder: reopening this one would put two shipments on the same quantity
        $waiting = InboundShipment::where('po_id', $g->po_id)->where('id', '!=', $g->shipment_id)->whereIn('status', self::AWAITING_GOODS)->orderBy('created_at')->value('number');
        if ($waiting) {
            return $no('GRN_VOID_FOLLOWUP_OPEN', 'shipment', "شحنة متابعة مفتوحة لأمر الشراء ({$waiting}) — ألغِها أو ارفضها أولًا ثم ألغِ إذن الاستلام، وبعدها تُفتح شحنة جديدة بالمتبقي الصحيح", "A follow-up shipment ({$waiting}) is open for this order — cancel or refuse it first, then void the receipt", ['shipment' => $waiting]);
        }

        return ['allowed' => true, 'code' => null, 'blocker' => null, 'reasonAr' => null, 'reasonEn' => null, 'details' => null];
    }

    /** @return string the receipt number */
    public function void(AuthUser $user, string $idOrNumber, string $reason): string
    {
        $reason = trim($reason);
        // ids are resolved BEFORE the transaction: under MySQL REPEATABLE READ the row locks have to be the first statements
        $ref = GoodsReceipt::where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->first(['id', 'shipment_id', 'po_id'])
            ?? throw AppError::notFound('GRN_NOT_FOUND', 'GRN غير موجود', 'Receipt not found');

        return DB::transaction(function () use ($user, $ref, $reason) {
            // Four row locks before the first plain read. Order shared with putaway confirmation (task → shipment) and GRN
            // posting (shipment → order). The order lock serialises the void with "open a follow-up shipment" and with
            // invoice registration, so the checks below cannot be overtaken by either.
            $tasks = PutawayTask::where('grn_id', $ref->id)->lockForUpdate()->get();
            $s = InboundShipment::whereKey($ref->shipment_id)->lockForUpdate()->first();
            $g = GoodsReceipt::whereKey($ref->id)->lockForUpdate()->firstOrFail();
            $po = PurchaseOrder::whereKey($ref->po_id)->lockForUpdate()->first();
            $state = $this->state($g);
            if (! $state['allowed']) {
                throw $state['code'] === 'GRN_VOIDED'
                    ? AppError::conflict('GRN_VOIDED', $state['reasonAr'], $state['reasonEn'])
                    : AppError::rule($state['code'], $state['reasonAr'], $state['reasonEn'], $state['details']);
            }
            $txId = $this->inventory->newTxId();
            $reference = ['type' => 'GoodsReceipt', 'id' => $g->id, 'number' => $g->number];

            // 1. stock + accrual: every receipt movement leaves at the unit cost it came in at
            $units = 0;
            $value = 0.0;
            foreach ($this->receiptMovements($g->id) as $m) {
                $this->inventory->post($user, $txId, ['type' => 'grnvoid', 'productId' => $m->product_id, 'batchId' => $m->batch_id, 'qty' => (int) $m->qty, 'unitCost' => $m->unit_cost !== null ? (float) $m->unit_cost : null,
                    'from' => ['warehouseId' => $m->dst_warehouse_id, 'binId' => $m->dst_bin_id], 'reference' => $reference,
                    'note' => "إلغاء {$g->number} — عكس الحركة {$m->number} ({$m->dstBin?->code})"]);
                $units += (int) $m->qty;
                $value += (float) ($m->total_cost ?? 0);
            }

            // 2. putaway tasks and their staging entries (task-referenced, and the GRN-referenced ones of older receipts)
            PutawayTask::whereIn('id', $tasks->pluck('id'))->update(['status' => 'cancelled']);
            StagingEntry::where('status', 'waiting')->where(fn ($w) => $w->where(fn ($a) => $a->where('reference_type', 'PutawayTask')->whereIn('reference_id', $tasks->pluck('id')))
                ->orWhere(fn ($b) => $b->where('reference_type', 'GoodsReceipt')->where('reference_id', $g->id)))->update(['status' => 'cancelled', 'cleared_at' => now()]);

            // 3. counters of the PO lines and the shipment lines
            $lines = GrnLine::where('grn_id', $g->id)->get();
            foreach ($lines->whereNotNull('po_line_id')->groupBy('po_line_id') as $poLineId => $rows) {
                if ($pl = PoLine::whereKey($poLineId)->lockForUpdate()->first()) {
                    $pl->update(['received_qty' => max(0, $pl->received_qty - (int) $rows->sum('accepted_qty')), 'damaged_qty' => max(0, $pl->damaged_qty - (int) $rows->sum('damaged_qty')), 'rejected_qty' => max(0, $pl->rejected_qty - (int) $rows->sum('rejected_qty'))]);
                }
            }
            foreach ($lines->whereNotNull('shipment_line_id')->groupBy('shipment_line_id') as $shipmentLineId => $rows) {
                if ($sl = ShipmentLine::whereKey($shipmentLineId)->lockForUpdate()->first()) {
                    $sl->update(['accepted_qty' => max(0, $sl->accepted_qty - (int) $rows->sum('accepted_qty')), 'damaged_qty' => max(0, $sl->damaged_qty - (int) $rows->sum('damaged_qty')), 'rejected_qty' => max(0, $sl->rejected_qty - (int) $rows->sum('rejected_qty'))]);
                }
            }

            // 4. the receipt itself (before the PO status, which counts the receipts still in force)
            $g->update(['voided_at' => now(), 'voided_by' => $user->username, 'void_reason' => $reason]);

            // 5. purchase order: open quantity and status as if this receipt had never been posted. A correction, not a step
            //    of the order's life, so it is written with its own audit line instead of going through PO_TRANSITIONS.
            $poTo = $po?->status;
            if ($po && $po->status !== 'closed') {
                $open = (int) PoLine::where('po_id', $po->id)->get()->sum(fn ($l) => H::openQty($l));
                if (in_array($po->status, ['partial', 'received'], true)) {
                    $live = GoodsReceipt::where('po_id', $po->id)->whereNull('voided_at')->exists();
                    $poTo = $live ? ($open === 0 ? 'received' : 'partial') : ($po->confirmed_at ? 'confirmed' : 'sent');
                }
                $poFrom = $po->status;
                $po->update(['open_qty' => $open, 'status' => $poTo]);
                if ($poTo !== $poFrom) {
                    $this->audit->status($user, 'PurchaseOrder', $po->id, $po->number, $poFrom, $poTo, "إلغاء إذن الاستلام {$g->number}", $txId);
                }
            }

            // 6. shipment: back to inspection so it can be received correctly — unless a later shipment already took over the
            //    remainder of the order, in which case this one is closed and the open quantity goes on a new follow-up shipment
            $shipTo = null;
            if ($s) {
                $later = InboundShipment::where('po_id', $s->po_id)->where('id', '!=', $s->id)->where('status', '!=', 'cancelled')->where('created_at', '>', $s->created_at)->exists();
                $shipTo = ! $later && Sm::can('SHIPMENT_TRANSITIONS', $s->status, 'inspecting') ? 'inspecting' : ($s->status === 'putaway' ? 'done' : null);
                if ($shipTo) {
                    $shipFrom = $s->status;
                    $s->update(['status' => $shipTo, 'completed_at' => $shipTo === 'done' ? now() : null]);
                    $this->audit->status($user, 'InboundShipment', $s->id, $s->number, $shipFrom, $shipTo, "إلغاء إذن الاستلام {$g->number}", $txId);
                }
            }

            // 7. exceptions the receipt raised describe goods that are no longer received
            $note = "أُلغي إذن الاستلام {$g->number}: {$reason}";
            foreach (OpsException::where('entity_type', 'GoodsReceipt')->where('entity_id', $g->id)->where('status', '!=', 'resolved')->orderBy('created_at')->get() as $e) {
                $this->exceptions->setStatus($user, $e->id, 'resolved', $note);
            }

            $this->audit->log($user, ['action' => 'GRN.VOID', 'entityType' => 'GoodsReceipt', 'entityId' => $g->id, 'entityNumber' => $g->number, 'transactionId' => $txId,
                'oldValue' => ['postedBy' => $g->posted_by, 'postedAt' => $g->posted_at?->toJSON()],
                'newValue' => ['reason' => $reason, 'units' => $units, 'value' => round($value, 2), 'putawayTasksCancelled' => $tasks->count(), 'po' => $po?->number, 'poStatus' => $poTo, 'shipment' => $s?->number, 'shipmentStatus' => $shipTo ?? $s?->status]]);
            $next = $shipTo === 'inspecting' ? "الشحنة {$s->number} عادت للفحص لإعادة الاستلام" : 'المتبقي على أمر الشراء يُستلم بشحنة متابعة';
            $this->notify->activity($user, 'GoodsReceipt', $g->id, $g->number, "أُلغي إذن الاستلام {$g->number} ({$units} وحدة) — {$reason}. {$next}",
                "Receipt {$g->number} voided ({$units} units) — {$reason}", ['wm', 'proc', 'finance']);
            // No outbox event: `InventoryReceived` has consumers, a "receipt voided" message has no agreed contract yet (see W7 report).

            return $g->number;
        });
    }

    /** The stock movements the receipt posted: accepted → STG-IN (`grn`), damaged → DMG-01 (`dmg`). */
    private function receiptMovements(string $grnId)
    {
        return InventoryMovement::with('dstBin:id,code')->where('reference_type', 'GoodsReceipt')->where('reference_id', $grnId)->whereIn('type', ['grn', 'dmg'])
            ->whereNotNull('dst_bin_id')->orderBy('created_at')->orderBy('number')->get();
    }
}
