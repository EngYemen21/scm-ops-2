<?php

namespace App\Integration\Services;

use App\Integration\Handlers\Blocked;
use App\Integration\Handlers\Rejected;
use App\Integration\IntegrationBootstrap;
use App\Integration\Models\IntException;
use App\Integration\Models\IntInbox;
use App\Models\Customer;
use App\Models\CustomerSite;
use App\Models\IntegrationEvent;
use App\Models\OpsException;
use App\Models\Product;
use App\Models\ProofOfDelivery;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\StatusHistory;
use App\Models\Warehouse;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\NumberingService;
use App\Services\Exceptions\ExceptionsService;
use App\Services\Inventory\InventoryService;
use App\Services\Sales\SalesService;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sales orders received from another system (ARCHITECTURE §5–§7).
 *
 * intake():  `sales_order.confirmed` → one OPS sales order (unique per source + external ref, so a replay can never
 *            create a second one). Customer and every product must be mapped first, otherwise the event is parked.
 *            Reservation is FEFO through the central engine, but — unlike a direct OPS order — a shortage does NOT
 *            reject the order: what exists is reserved (so it cannot be sold to someone else) and the rest is
 *            backordered with a procurement shortage for the buyers and an ETA for Sales.
 *            Commercial truth (prices, discounts, VAT, totals) is the source's snapshot and is never recomputed here.
 * cancel():  `sales_order.cancelled` → releases the reservation while nothing has been picked; later than that a person
 *            must decide (exception), and Sales is told.
 * topUp():   when stock arrives, orders waiting for it are completed first-come-first-served.
 */
class OrderIntakeService
{
    /** OPS sales-order status → the status Sales shows (ops_status). Backorders are refined by fulfil_status. */
    public const SALES_STATUS = [
        'confirmed' => 'backordered', 'allocated' => 'reserved', 'preparing' => 'released', 'picking' => 'picking', 'picked' => 'picked',
        'packed' => 'packed', 'readydisp' => 'packed', 'loaded' => 'loaded', 'outfordel' => 'out_for_delivery', 'delivered' => 'delivered',
        'partial' => 'delivered_partial', 'failed' => 'delivery_failed', 'completed' => 'delivered', 'cancelled' => 'cancelled', 'returned' => 'returned',
    ];

    public const MAX_LINES = 200;

    public function __construct(
        private readonly ExternalRefs $refs,
        private readonly InventoryService $inventory,
        private readonly NumberingService $numbering,
        private readonly AuditService $audit,
        private readonly NotifyService $notify,
        private readonly ExceptionsService $opsExceptions,
        private readonly EventPublisher $publisher,
        private readonly AvailabilityService $availability,
        private readonly SalesService $sales,
        private readonly IntegrationExceptions $exceptions,
    ) {}

    public function intake(IntInbox $event, AuthUser $actor): array
    {
        $system = $event->source;
        $d = $event->data;
        $ref = trim((string) ($d['id'] ?? ''));
        if ($ref === '' || $ref !== $event->subject) {
            throw new Rejected('ORDER_ID_INVALID', 'data.id is required and must equal the subject');
        }
        // already created (a replay, or the same order sent twice): nothing happens twice
        $existing = SalesOrder::where('source_system', $system)->where('external_ref', $ref)->first();
        if ($existing) {
            return ['opsOrder' => $existing->number, 'created' => false];
        }
        $lines = $this->validLines($d['lines'] ?? null);

        $customerExt = trim((string) ($d['customerId'] ?? ''));
        $customerId = $customerExt !== '' ? $this->refs->internalId($system, 'customer', $customerExt) : null;
        if (! $customerId) {
            throw new Blocked('CUSTOMER_UNMAPPED', "طلب {$ref}: العميل {$customerExt} لم يصل من المبيعات بعد", 'customer', $customerExt ?: $ref);
        }
        $productIds = $this->refs->internalIds($system, 'product', array_column($lines, 'productId'));
        $missing = array_values(array_unique(array_diff(array_column($lines, 'productId'), array_keys($productIds))));
        if ($missing) {
            throw new Blocked('PRODUCT_UNMAPPED', "طلب {$ref}: منتجات غير مربوطة بأصناف العمليات — ".implode('، ', $missing), 'product', $missing, ['order' => $ref, 'products' => $missing]);
        }
        $customer = Customer::findOrFail($customerId);
        if (! $customer->active) {
            throw new Rejected('CUSTOMER_INACTIVE', "customer {$customer->code} is inactive in OPS");
        }
        $warehouse = $this->warehouse($d);
        $site = $this->site($customer, (array) ($d['branch'] ?? []));
        $txId = $this->inventory->newTxId();
        $number = $this->numbering->next('SO');
        $kg = 0.0;
        $products = Product::whereIn('id', array_values($productIds))->get()->keyBy('id');
        foreach ($lines as $l) {
            $kg += $l['qty'] * ($products[$productIds[$l['productId']]]->weight_kg ?: 12);
        }
        $commercial = [
            'source' => $system, 'ref' => $ref, 'currency' => (string) ($d['currency'] ?? 'SAR'), 'vatPct' => is_numeric($d['vatPct'] ?? null) ? (float) $d['vatPct'] : null,
            'totals' => is_array($d['totals'] ?? null) ? $d['totals'] : null, 'salesRep' => $d['salesRep'] ?? null, 'customerNotes' => $d['notes'] ?? null,
            'operationalNotes' => $d['operationalNotes'] ?? null, 'paymentTerms' => $d['paymentTerms'] ?? null, 'confirmedAt' => $d['confirmedAt'] ?? null,
            'branch' => $d['branch'] ?? null,
        ];
        $so = SalesOrder::create([
            'number' => $number, 'customer_id' => $customer->id, 'warehouse_id' => $warehouse->id, 'site_id' => $site?->id,
            'due_date' => $this->dueDate($d['requiredDate'] ?? null), 'window' => is_string($d['window'] ?? null) ? mb_substr($d['window'], 0, 40) : null,
            'priority' => in_array($d['priority'] ?? 'normal', ['high', 'urgent'], true) ? 'high' : 'normal', 'status' => 'confirmed',
            'kg' => round($kg), 'cbm' => round($kg / 200 * 10) / 10, 'created_by_id' => $actor->id, 'created_by' => $actor->username,
            'source_system' => $system, 'external_ref' => $ref, 'commercial' => $commercial,
        ]);
        $outLines = [];
        foreach ($lines as $l) {
            $productId = $productIds[$l['productId']];
            $sl = SalesOrderLine::create(['so_id' => $so->id, 'line_no' => $l['lineNo'], 'product_id' => $productId, 'qty' => $l['qty'], 'price' => $l['unitPrice'], 'disc_pct' => $l['discountPct']]);
            $reserved = $this->reserveUpTo($actor, $txId, $so, $sl, $l['qty']);
            $outLines[] = ['productId' => $l['productId'], 'sku' => $products[$productId]->sku, 'qty' => $l['qty'], 'reserved' => $reserved, 'backordered' => $l['qty'] - $reserved];
        }
        $short = array_values(array_filter($outLines, fn ($x) => $x['backordered'] > 0));
        $reservedAny = array_sum(array_column($outLines, 'reserved')) > 0;
        $availability = ! $short ? 'full' : ($reservedAny ? 'partial' : 'none');
        $so->update(['status' => $short ? 'confirmed' : 'allocated', 'fulfil_status' => $short ? ($reservedAny ? 'partial' : 'backordered') : 'full']);

        $this->audit->log($actor, ['action' => 'SO.CREATE', 'entityType' => 'SalesOrder', 'entityId' => $so->id, 'entityNumber' => $number,
            'newValue' => ['source' => $system, 'ref' => $ref, 'customer' => $customer->code, 'warehouse' => $warehouse->code, 'lines' => $outLines, 'availability' => $availability], 'transactionId' => $txId]);
        $this->audit->status($actor, 'SalesOrder', $so->id, $number, null, $so->status, "من {$system} {$ref} — ".($short ? 'حجز جزئي/انتظار مخزون' : 'حجز FEFO كامل'), $txId);
        $this->notify->activity($actor, 'SalesOrder', $so->id, $number,
            "وصل طلب المبيعات {$ref} → {$number}".($short ? ' — ينقصه مخزون لـ '.count($short).' صنف' : ' — حُجز بالكامل'), "Sales order {$ref} received as {$number}", ['wm', 'disp', 'sales']);

        $this->publisher->publish('order.accepted', $ref, ['opsOrder' => $number, 'status' => ['full' => 'reserved', 'partial' => 'partially_reserved', 'none' => 'backordered'][$availability], 'warehouse' => $warehouse->code, 'availability' => $availability, 'lines' => $outLines], $ref, $event->event_id);
        if ($short) {
            $this->backorder($actor, $so, $ref, $short, $event->event_id);
        } else {
            $this->publisher->publish('order.reserved', $ref, ['opsOrder' => $number, 'status' => 'reserved', 'lines' => array_map(fn ($x) => ['productId' => $x['productId'], 'reserved' => $x['reserved']], $outLines)], $ref, $event->event_id);
        }

        return ['opsOrder' => $number, 'created' => true, 'availability' => $availability, 'warehouse' => $warehouse->code];
    }

    public function cancel(IntInbox $event, AuthUser $actor): array
    {
        $ref = $event->subject;
        $so = SalesOrder::with('fos')->where('source_system', $event->source)->where('external_ref', $ref)->lockForUpdate()->first();
        $reason = mb_substr(trim((string) ($event->data['reason'] ?? '')), 0, 300) ?: 'ألغاه العميل/المبيعات';
        if (! $so) {
            // nothing was created (still parked, or never sent): the subject's sequence now makes any older "confirmed" stale
            return ['cancelled' => true, 'opsOrder' => null];
        }
        if ($so->status === 'cancelled') {
            return ['cancelled' => true, 'opsOrder' => $so->number];
        }
        $started = ! in_array($so->status, ['confirmed', 'allocated', 'preparing'], true)
            || $so->fos->contains(fn ($f) => ! in_array($f->status, ['alloc', 'cancelled'], true));
        if ($started) {
            $this->exceptions->raise('CANCEL_TOO_LATE', "طلب {$ref} ({$so->number}) أُلغي في المبيعات بعد بدء التنفيذ ({$so->status}) — يلزم قرار", [
                'system' => $event->source, 'entity' => 'order', 'entityRef' => $ref, 'correlationId' => $ref, 'eventId' => $event->event_id, 'severity' => 'crit',
                'details' => ['opsOrder' => $so->number, 'status' => $so->status, 'reason' => $reason],
            ]);
            $this->publisher->publish('order.cancel_rejected', $ref, ['opsOrder' => $so->number, 'status' => self::SALES_STATUS[$so->status] ?? $so->status,
                'reason' => 'التنفيذ بدأ — يتواصل فريق العمليات لإيقاف الشحنة'], $ref, $event->event_id);

            return ['cancelled' => false, 'opsOrder' => $so->number, 'status' => $so->status];
        }
        $this->sales->cancelOrder($actor, $so->id, "إلغاء من المبيعات: {$reason}");
        $this->publisher->publish('order.cancelled', $ref, ['opsOrder' => $so->number, 'status' => 'cancelled', 'reason' => $reason], $ref, $event->event_id);

        return ['cancelled' => true, 'opsOrder' => $so->number];
    }

    /** The customer confirmed receipt in Sales: kept with the order and compared with OPS's proof of delivery. */
    public function received(IntInbox $event, AuthUser $actor): array
    {
        $so = SalesOrder::with('lines.product')->where('source_system', $event->source)->where('external_ref', $event->subject)->first();
        if (! $so) {
            throw new Blocked('ORDER_NOT_FOUND', "إقرار استلام لطلب {$event->subject} غير موجود في العمليات", 'order', $event->subject);
        }
        $result = in_array($event->data['result'] ?? null, ['done', 'short'], true) ? $event->data['result'] : 'done';
        $received = [];
        foreach ((array) ($event->data['lines'] ?? []) as $l) {
            if (is_array($l) && isset($l['productId'], $l['receivedQty']) && is_numeric($l['receivedQty'])) {
                $received[(string) $l['productId']] = (int) $l['receivedQty'];
            }
        }
        $commercial = (array) $so->commercial;
        $commercial['receipt'] = ['result' => $result, 'lines' => $received, 'at' => now()->toIso8601ZuluString()];
        $so->update(['commercial' => $commercial]);
        // compare with what OPS says it delivered
        $mismatch = [];
        foreach ($so->lines as $line) {
            $ext = $this->refs->externalId($event->source, 'product', $line->product_id);
            if ($ext !== null && array_key_exists($ext, $received) && $received[$ext] !== (int) $line->delivered_qty) {
                $mismatch[] = ['productId' => $ext, 'deliveredByOps' => (int) $line->delivered_qty, 'receivedByCustomer' => $received[$ext]];
            }
        }
        if ($mismatch) {
            $this->exceptions->raise('RECEIPT_MISMATCH', "طلب {$event->subject}: استلام العميل يختلف عن إثبات التسليم", [
                'system' => $event->source, 'entity' => 'order', 'entityRef' => $event->subject, 'correlationId' => $event->subject, 'eventId' => $event->event_id,
                'details' => ['opsOrder' => $so->number, 'lines' => $mismatch],
            ]);
        }
        $this->audit->log($actor, ['action' => 'SO.CUSTOMER_RECEIPT', 'entityType' => 'SalesOrder', 'entityId' => $so->id, 'entityNumber' => $so->number,
            'newValue' => $commercial['receipt']]);

        return ['opsOrder' => $so->number, 'mismatches' => count($mismatch)];
    }

    /**
     * Orders waiting for stock get it first-come-first-served as soon as it is available (after a receipt, a return to
     * stock, a cancellation). Each completed order moves to `allocated` and Sales is told. @return int orders completed
     */
    public function topUp(?array $productIds = null, int $limit = 50): int
    {
        $waiting = SalesOrder::whereNotNull('source_system')->where('status', 'confirmed')
            ->when($productIds, fn ($q) => $q->whereHas('lines', fn ($l) => $l->whereIn('product_id', $productIds)))
            ->orderBy('created_at')->orderBy('id')->limit($limit)->pluck('id');
        $completed = 0;
        foreach ($waiting as $soId) {
            $completed += DB::transaction(function () use ($soId) {
                $so = SalesOrder::with('lines')->lockForUpdate()->find($soId);
                if (! $so || $so->status !== 'confirmed') {
                    return 0;
                }
                $actor = IntegrationBootstrap::actor($so->source_system);
                $txId = $this->inventory->newTxId();
                $gained = 0;
                foreach ($so->lines as $sl) {
                    $gained += $this->reserveUpTo($actor, $txId, $so, $sl, $sl->qty - $sl->reserved_qty);
                }
                $so->load('lines');
                $short = $so->lines->contains(fn ($l) => $l->reserved_qty < $l->qty);
                if (! $short) {
                    $so->update(['status' => 'allocated', 'fulfil_status' => 'full']);
                    $this->audit->status($actor, 'SalesOrder', $so->id, $so->number, 'confirmed', 'allocated', 'وصل المخزون — اكتمل الحجز', $txId);
                    $this->publisher->publish('order.reserved', $so->external_ref, ['opsOrder' => $so->number, 'status' => 'reserved', 'completedBackorder' => true,
                        'lines' => $this->linesForEvent($so)], $so->external_ref);

                    return 1;
                }
                if ($gained > 0) {
                    $so->update(['fulfil_status' => 'partial']);
                    $this->publisher->publish('order.backordered', $so->external_ref, ['opsOrder' => $so->number, 'status' => 'partially_reserved', 'lines' => $this->shortLines($so),
                        'eta' => $this->eta($so)], $so->external_ref);
                }

                return 0;
            });
        }

        return $completed;
    }

    /** OPS's view of an order another system sent (GET /api/v1/orders/{ref}). */
    public function view(string $system, string $ref): array
    {
        $so = SalesOrder::with(['lines.product', 'warehouse', 'fos.trip.vehicle', 'fos.trip.driver'])->where('source_system', $system)->where('external_ref', $ref)->first();
        $inbox = IntInbox::where('source', $system)->where('subject', $ref)->orderBy('received_at')->get(['event_id', 'type', 'status', 'last_code', 'received_at']);
        if (! $so && $inbox->isEmpty()) {
            throw AppError::notFound('ORDER_NOT_FOUND', 'الطلب غير موجود في العمليات', 'Order not found in OPS');
        }
        $events = IntegrationEvent::where('source', 'ops')->where('correlation_id', $ref)->orderBy('sequence')->get(['id', 'type', 'sequence', 'created_at', 'payload']);
        $pod = $so ? ProofOfDelivery::whereIn('fo_id', $so->fos->pluck('id'))->orderByDesc('at')->first() : null;
        $trip = $so?->fos->first(fn ($f) => $f->trip)?->trip;

        return [
            'ref' => $ref, 'opsOrder' => $so?->number, 'opsStatus' => $so?->status, 'status' => $so ? $this->salesStatus($so) : 'pending_intake',
            'warehouse' => $so?->warehouse?->code, 'fulfilment' => $so?->fulfil_status,
            'lines' => $so ? $this->linesForEvent($so, true) : [],
            'shipment' => $trip ? ['trip' => $trip->number, 'status' => $trip->status, 'vehicle' => $trip->vehicle?->plate_ar ?? $trip->vehicle?->code,
                'driver' => $trip->driver?->name_ar, 'driverPhone' => $trip->driver?->mobile] : null,
            'pod' => $pod ? ['number' => $pod->number, 'result' => $pod->result, 'receiver' => $pod->receiver_name, 'at' => $pod->at,
                'deliveredQty' => $pod->delivered_qty, 'returnedQty' => $pod->returned_qty, 'gps' => $pod->gps_status === 'captured'] : null,
            // the journey as Sales saw it: every step OPS reported, in order
            'timeline' => $events->map(fn ($e) => ['type' => $e->type, 'status' => $e->payload['status'] ?? null, 'sequence' => $e->sequence, 'at' => $e->created_at])->all(),
            'history' => $so ? StatusHistory::where('entity_type', 'SalesOrder')->where('entity_id', $so->id)->orderBy('at')->get(['from_status', 'to_status', 'note', 'at'])
                ->map(fn ($h) => ['from' => $h->from_status, 'to' => $h->to_status, 'note' => $h->note, 'at' => $h->at])->all() : [],
            'received' => $inbox->map(fn ($e) => ['eventId' => $e->event_id, 'type' => $e->type, 'status' => $e->status, 'code' => $e->last_code, 'at' => $e->received_at])->all(),
            'published' => $events->map(fn ($e) => ['eventId' => $e->id, 'type' => $e->type, 'sequence' => $e->sequence, 'at' => $e->created_at])->all(),
            'exceptions' => IntException::where('correlation_id', $ref)->orderByDesc('last_at')->get(['code', 'status', 'message', 'last_at'])->all(),
            'eta' => $so && $so->status === 'confirmed' ? $this->eta($so) : null,
        ];
    }

    public function salesStatus(SalesOrder $so): string
    {
        if ($so->status === 'confirmed') {
            return $so->fulfil_status === 'partial' ? 'partially_reserved' : 'backordered';
        }

        return self::SALES_STATUS[$so->status] ?? $so->status;
    }

    // ───────────── internals ─────────────

    /** Reserves min(available, $qty) for one line; returns what was reserved. */
    private function reserveUpTo(AuthUser $actor, string $txId, SalesOrder $so, SalesOrderLine $sl, int $qty): int
    {
        if ($qty <= 0) {
            return 0;
        }
        $available = (int) $this->inventory->allocRows($sl->product_id, $so->warehouse_id, true)->sum(fn ($r) => $r->on_hand - $r->reserved);
        $take = min($qty, $available);
        if ($take > 0) {
            $this->inventory->reserve($actor, $txId, ['soId' => $so->id, 'soLineId' => $sl->id, 'productId' => $sl->product_id,
                'warehouseId' => $so->warehouse_id, 'qty' => $take, 'referenceNumber' => $so->number]);
        }

        return $take;
    }

    /** The shortage: buyers get an operational exception, Sales gets the backorder + ETA + procurement requirement. */
    private function backorder(AuthUser $actor, SalesOrder $so, string $ref, array $short, ?string $causation): void
    {
        $eta = $this->eta($so);
        $text = collect($short)->map(fn ($x) => "{$x['sku']} × {$x['backordered']}")->implode('، ');
        $this->opsExceptions->raise($actor, [
            'kind' => 'shortage', 'severity' => 'w', 'entityType' => 'SalesOrder', 'entityId' => $so->id, 'entityNumber' => $so->number,
            'documentType' => 'SalesOrder', 'documentId' => $so->id, 'documentNumber' => $so->number,
            'textAr' => "نقص مخزون لطلب المبيعات {$ref} ({$so->number}): {$text} — يلزم توريد", 'textEn' => "Stock shortage for sales order {$ref} ({$so->number}): {$text}",
        ]);
        $lines = array_map(fn ($x) => ['productId' => $x['productId'], 'missing' => $x['backordered']], $short);
        $this->publisher->publish('order.backordered', $ref, ['opsOrder' => $so->number, 'status' => $this->salesStatus($so), 'lines' => $lines, 'eta' => $eta], $ref, $causation);
        $this->publisher->publish('procurement.required', $ref, ['opsOrder' => $so->number, 'status' => $this->salesStatus($so), 'lines' => $lines, 'eta' => $eta], $ref, $causation);
    }

    /** Earliest date the missing quantities are expected (open purchase orders), null when nothing is on order. */
    private function eta(SalesOrder $so): ?string
    {
        $dates = [];
        foreach ($so->lines()->get() as $l) {
            if ($l->reserved_qty >= $l->qty) {
                continue;
            }
            $a = $this->availability->forProduct($l->product_id, Warehouse::find($so->warehouse_id));
            if ($a['incoming'] < $l->qty - $l->reserved_qty || ! $a['incomingEta']) {
                return null; // not (fully) on order yet: no honest date
            }
            $dates[] = $a['incomingEta'];
        }

        return $dates ? max($dates) : null;
    }

    private function linesForEvent(SalesOrder $so, bool $detail = false): array
    {
        return $so->lines()->with('product')->orderBy('line_no')->get()->map(function ($l) use ($so, $detail) {
            $row = ['productId' => $this->refs->externalId($so->source_system, 'product', $l->product_id), 'sku' => $l->product?->sku, 'qty' => $l->qty, 'reserved' => $l->reserved_qty];

            return $detail ? $row + ['picked' => $l->picked_qty, 'delivered' => $l->delivered_qty, 'returned' => $l->returned_qty] : $row;
        })->all();
    }

    private function shortLines(SalesOrder $so): array
    {
        return array_values(array_filter(array_map(fn ($l) => ['productId' => $l['productId'], 'missing' => $l['qty'] - $l['reserved']], $this->linesForEvent($so)), fn ($x) => $x['missing'] > 0));
    }

    /** @return list<array{lineNo:int, productId:string, qty:int, unitPrice:float, discountPct:float}> */
    private function validLines(mixed $lines): array
    {
        if (! is_array($lines) || ! $lines || ! array_is_list($lines)) {
            throw new Rejected('ORDER_LINES_MISSING', 'data.lines must be a non-empty list');
        }
        if (count($lines) > self::MAX_LINES) {
            throw new Rejected('ORDER_TOO_MANY_LINES', 'more than '.self::MAX_LINES.' lines');
        }
        $out = [];
        $seen = [];
        foreach ($lines as $i => $l) {
            $pid = is_array($l) ? trim((string) ($l['productId'] ?? '')) : '';
            $qty = is_array($l) ? ($l['qty'] ?? null) : null;
            $price = is_array($l) ? ($l['unitPrice'] ?? null) : null;
            $disc = is_array($l) ? ($l['discountPct'] ?? 0) : 0;
            if ($pid === '' || ! is_int($qty) || $qty < 1 || $qty > 100000 || ! is_numeric($price) || $price < 0 || ! is_numeric($disc) || $disc < 0 || $disc > 100) {
                throw new Rejected('ORDER_LINE_INVALID', "line {$i}: productId, qty (integer 1–100000), unitPrice ≥ 0 and discountPct 0–100 are required", ['line' => $i]);
            }
            if (isset($seen[$pid])) {
                throw new Rejected('ORDER_LINE_DUPLICATE', "product {$pid} appears twice — send one line per product", ['productId' => $pid]);
            }
            $seen[$pid] = true;
            $out[] = ['lineNo' => (int) ($l['lineNo'] ?? $i + 1), 'productId' => $pid, 'qty' => $qty, 'unitPrice' => round((float) $price, 2), 'discountPct' => (float) $disc];
        }

        return $out;
    }

    private function warehouse(array $d): Warehouse
    {
        $code = is_string($d['warehouse'] ?? null) && $d['warehouse'] !== '' ? $d['warehouse'] : (string) config('integration.default_warehouse', 'RYD');

        return Warehouse::where('code', $code)->where('active', true)->first()
            ?? throw AppError::rule('WAREHOUSE_NOT_FOUND', "مستودع التنفيذ {$code} غير موجود", "Fulfilment warehouse {$code} not found");
    }

    private function site(Customer $c, array $branch): ?CustomerSite
    {
        $name = trim((string) ($branch['name'] ?? ''));
        $key = trim((string) ($branch['key'] ?? ''));
        if ($key === '' && $name === '') {
            return null;
        }
        $site = CustomerSite::firstOrNew(['customer_id' => $c->id, 'external_key' => $key !== '' ? $key : $c->id.':'.$name]);
        if (! $site->exists) {
            $site->fill(['name' => $name !== '' ? mb_substr($name, 0, 200) : $key, 'address' => isset($branch['address']) ? mb_substr((string) $branch['address'], 0, 500) : null, 'active' => true])->save();
        }

        return $site;
    }

    private function dueDate(mixed $required): Carbon
    {
        if (is_string($required) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $required)) {
            $date = Carbon::parse($required);

            return $date->lt(Carbon::today()) ? Carbon::tomorrow() : $date;
        }

        return Carbon::tomorrow();
    }
}
