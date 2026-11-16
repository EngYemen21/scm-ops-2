<?php

namespace App\Services\Sales;

use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\NumberingService;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Support\Facades\DB;

/**
 * What an order could not ship is never dropped silently. It leaves the order explicitly, in one of two ways:
 *
 *  - split():  the quantity moves to a linked BACKORDER order (its own number, status `confirmed` = waiting for stock,
 *              same customer, prices and delivery terms). The original order then ships what it really has and ends
 *              at 100 % of its own lines, while `ordered_qty` keeps what the customer asked for.
 *  - cancel(): the quantity is cancelled on the line (the customer or the business does not want a backorder).
 *
 * One order = one fulfilment order = one shipment stays true, so the status machine and the documents downstream
 * (pick list, packing, trip, POD, invoice) need no notion of split shipments.
 *
 * Callers: a short pick (FulfillmentService), an order accepted with a shortage (SalesService). Both run inside the
 * caller's transaction with the order row locked. Reservations are the caller's business — this class only moves
 * ordered quantity between documents. Orders received from another system are never split here: their shortage is
 * reported to that system, which owns the commercial decision.
 */
class BackorderService
{
    public function __construct(
        private readonly NumberingService $numbering,
        private readonly AuditService $audit,
        private readonly NotifyService $notify,
        private readonly CreditService $credit,
    ) {}

    /**
     * @param  array<string, int>  $parts  sales-order-line id → quantity to move to the backorder (1 … line qty)
     * @param  string  $reason  short_pick | no_stock
     * @return SalesOrder the backorder order (status `confirmed`, `fulfil_status` backordered, nothing reserved)
     */
    public function split(?AuthUser $user, SalesOrder $so, array $parts, string $reason): SalesOrder
    {
        $this->requireTransaction();
        $lines = $this->take($so, $parts);
        $root = $so->backorder_of_id ?: $so->id;
        $number = $this->numbering->next('SO');
        $kg = 0.0;
        foreach ($lines as [$line, $qty]) {
            $kg += $qty * (float) ($line->product?->weight_kg ?: 12);
        }
        $bo = SalesOrder::create([
            'number' => $number, 'customer_id' => $so->customer_id, 'warehouse_id' => $so->warehouse_id, 'due_date' => $so->due_date, 'window' => $so->window,
            'priority' => $so->priority, 'status' => 'confirmed', 'fulfil_status' => 'backordered', 'backorder_of_id' => $root, 'site_id' => $so->site_id,
            'kg' => round($kg), 'cbm' => round($kg / 200 * 10) / 10, 'created_by_id' => $user?->id, 'created_by' => $user?->username ?? 'system',
        ]);
        foreach ($lines as $i => [$line, $qty]) {
            SalesOrderLine::create(['so_id' => $bo->id, 'line_no' => $i + 1, 'product_id' => $line->product_id, 'qty' => $qty, 'price' => $line->price, 'disc_pct' => $line->disc_pct]);
        }
        $so->update(['has_shortage' => true]);
        $summary = $this->summary($lines);
        $why = $reason === 'short_pick' ? 'نقص عند التجهيز' : 'لا رصيد كافٍ عند الطلب';
        $this->audit->log($user, ['action' => 'SO.BACKORDER', 'entityType' => 'SalesOrder', 'entityId' => $so->id, 'entityNumber' => $so->number,
            'newValue' => ['backorder' => $number, 'reason' => $reason, 'lines' => array_map(fn ($x) => ['sku' => $x[0]->product?->sku, 'qty' => $x[1]], $lines)]]);
        $this->audit->status($user, 'SalesOrder', $bo->id, $number, null, 'confirmed', "طلب معلّق من {$so->number} — {$why}");
        $this->notify->activity($user, 'SalesOrder', $bo->id, $number, "طلب معلّق {$number} من {$so->number} ({$why}): {$summary} — ينتظر توفر المخزون",
            "Backorder {$number} of {$so->number}: {$summary}", ['sales', 'proc']);

        return $bo->refresh();
    }

    /**
     * Cancels quantity on the order's lines (no backorder wanted). Returns the cancelled quantity.
     *
     * @param  array<string, int>  $parts  sales-order-line id → quantity
     */
    public function cancel(?AuthUser $user, SalesOrder $so, array $parts, string $reason): int
    {
        $this->requireTransaction();
        $lines = $this->take($so, $parts);
        $so->update(['has_shortage' => true]);
        $summary = $this->summary($lines);
        $this->audit->log($user, ['action' => 'SO.SHORT_CANCEL', 'entityType' => 'SalesOrder', 'entityId' => $so->id, 'entityNumber' => $so->number,
            'newValue' => ['reason' => $reason, 'lines' => array_map(fn ($x) => ['sku' => $x[0]->product?->sku, 'qty' => $x[1]], $lines)]]);
        $this->notify->activity($user, 'SalesOrder', $so->id, $so->number, "أُلغيت كمية غير متوفرة من {$so->number}: {$summary} — أبلغ العميل", "Short quantity cancelled on {$so->number}: {$summary}", ['sales']);
        if (! $so->source_system) {
            $this->credit->sync($so->customer_id); // the order is worth less now
        }

        return array_sum(array_map(fn ($x) => $x[1], $lines));
    }

    /**
     * Takes the quantities off the order's lines (qty ↓, short_qty ↑, ordered_qty remembers the original).
     *
     * @return array<int, array{0: SalesOrderLine, 1: int}>
     */
    private function take(SalesOrder $so, array $parts): array
    {
        $out = [];
        foreach ($parts as $lineId => $qty) {
            $qty = (int) $qty;
            if ($qty <= 0) {
                continue;
            }
            $line = SalesOrderLine::with('product:id,sku,name_ar,weight_kg')->where('so_id', $so->id)->whereKey($lineId)->lockForUpdate()->first()
                ?? throw AppError::validation('SO_LINE_NOT_FOUND', 'سطر أمر البيع غير موجود', 'Sales order line not found');
            if ($qty > $line->qty) {
                throw AppError::validation('BACKORDER_QTY', "الكمية ({$qty}) أكبر من كمية السطر ({$line->qty})", "Quantity {$qty} exceeds the line quantity {$line->qty}");
            }
            $line->update(['ordered_qty' => $line->ordered_qty ?? $line->qty, 'qty' => $line->qty - $qty, 'short_qty' => $line->short_qty + $qty]);
            $out[] = [$line, $qty];
        }
        if (! $out) {
            throw AppError::validation('BACKORDER_EMPTY', 'لا كمية لتعليقها', 'Nothing to backorder');
        }

        return $out;
    }

    private function summary(array $lines): string
    {
        return implode('، ', array_map(fn ($x) => ($x[0]->product?->name_ar ?: $x[0]->product?->sku).' × '.$x[1], $lines));
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('BackorderService must run inside the caller\'s DB::transaction() with the order row locked.');
        }
    }
}
