<?php

namespace App\Services\Fulfillment;

use App\Models\Carton;
use App\Models\FulfillmentOrder;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\StatusHistory;
use App\Models\TripStop;
use App\Services\Sales\Shape;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;

/**
 * Fulfillment execution: FO (alloc) → Pick list (scan location → scan product → batch → qty, partial/short allowed,
 * no over-pick, no wrong bin/product/batch, no negative stock) → Packing (one package, numbered cartons, weight,
 * volume) → Outbound Staging (STG-OUT) → Loading (door, reverse delivery order, vehicle scan, order scan, cumulative
 * weight/volume, vehicle state) → Dispatch (transaction: trip/vehicle/driver/orders → on trip, timestamps, audit,
 * outbox event). Every stock change goes through the inventory engine: pick = bin → PACK · pack = PACK → STG-OUT ·
 * load = STG-OUT → vehicle (leaves warehouse stock) — and each step has its way back: put-back, unpack, unload.
 *
 * This class serves the documents; the steps live beside it: PickingService, PackingService, LoadingService.
 */
class FulfillmentService
{
    private const FO_SHAPE = [
        'customer' => ['code', 'nameAr', 'nameEn', 'zone', 'contact', 'address'], 'warehouse' => ['code', 'nameAr'],
        'so' => ['number', 'status', 'window', 'dueDate', 'priority'],
        'lines.*.product' => ['sku', 'nameAr', 'nameEn', 'weightKg', 'storageClass', 'barcodes'],
        'pickLists.*.tasks.*.bin' => ['code', 'zone'], 'pickLists.*.tasks.*.bin.zone' => ['code', 'nameAr', 'nameEn', 'type'], 'pickLists.*.tasks.*.product' => ['sku', 'nameAr'],
        'pickLists.*.tasks.*.batch' => ['batchNo', 'expiryDate'],
        'trip' => ['number', 'status', 'dockCode', 'vehicle', 'driver'], 'trip.vehicle' => ['code', 'plateAr', 'plateEn'], 'trip.driver' => ['code', 'nameAr'],
        'pods.*' => ['number', 'result', 'at', 'receiverName'], 'returns.*' => ['number', 'status'],
    ];

    private const MOVEMENT_SHAPE = ['*' => ['number', 'type', 'qty', 'batchNo', 'createdAt', 'srcBin', 'dstBin', 'product'], '*.srcBin' => ['code'], '*.dstBin' => ['code'], '*.product' => ['sku']];

    /** @param  array{status?:?string, warehouse?:?string, trip?:?string, customer?:?string, mine?:mixed}  $filters */
    public function list(Paging $page, array $filters, ?AuthUser $user = null): array
    {
        $query = FulfillmentOrder::with(self::foWith())->orderBy('seq')->orderByDesc('created_at')->orderByDesc('id');
        if (! empty($filters['status'])) {
            $filters['status'] === 'active' ? $query->whereIn('status', ['alloc', 'picking', 'picked', 'packed', 'loaded']) : $query->where('status', $filters['status']);
        }
        foreach (['warehouse' => 'code', 'trip' => 'number', 'customer' => 'code'] as $relation => $column) {
            if (! empty($filters[$relation])) {
                $query->whereHas($relation, fn ($r) => $r->where($column, $filters[$relation]));
            }
        }
        // "my lists": orders whose open pick list the caller holds
        if ($user && filter_var($filters['mine'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->whereHas('pickLists', fn ($p) => $p->where('assigned_to_id', $user->id)->where('status', 'open'));
        }
        if ($page->q) {
            $query->where(fn ($w) => $w->where('number', 'like', "%{$page->q}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name_ar', 'like', "%{$page->q}%"))
                ->orWhereHas('so', fn ($s) => $s->where('number', 'like', "%{$page->q}%")));
        }

        return $page->paginate($query, fn (FulfillmentOrder $fo) => Shape::apply($fo->toArray(), self::FO_SHAPE));
    }

    public function get(string $idOrNumber): array
    {
        $fo = FulfillmentOrder::with(self::foWith())->where('id', $idOrNumber)->orWhere('number', $idOrNumber)->first()
            ?? throw AppError::notFound('FO_NOT_FOUND', 'أمر التنفيذ غير موجود');
        $movements = InventoryMovement::with(['srcBin', 'dstBin', 'product'])->where('reference_type', 'FulfillmentOrder')->where('reference_id', $fo->id)
            ->orderBy('created_at')->orderBy('number')->get()->map->toArray()->all();
        $doc = Shape::apply($fo->toArray(), self::FO_SHAPE);
        $doc['pickLists'] = $this->withBatchScan($doc['pickLists'] ?? []);
        $stop = $fo->trip_id ? TripStop::where('trip_id', $fo->trip_id)->where('fo_id', $fo->id)->where('status', '!=', 'skipped')->first() : null;

        return $doc + [
            'history' => StatusHistory::where('entity_type', 'FulfillmentOrder')->where('entity_id', $fo->id)->orderBy('at')->orderBy('id')->get()->map->toArray()->all(),
            'movements' => Shape::apply($movements, self::MOVEMENT_SHAPE),
            // one row per carton of the live package — the labels ("1 / N") print from this list. The key is not
            // `cartons`: that is, and stays, the number of cartons of the order.
            'cartonList' => $this->cartons($fo),
            'stop' => $stop ? ['seq' => $stop->seq, 'trip' => $doc['trip']['number'] ?? null] : null,
        ];
    }

    /**
     * The cartons of an order (void ones excluded). They follow their order: `packed` / `loaded` are written by load
     * and unload; after dispatch the status is read from the order — delivered when it was delivered, returned when
     * the delivery failed; on a partial delivery a carton is delivered when its code was scanned at the stop.
     *
     * @return array<int, array{code:string, seq:int, weightKg:?float, status:string, loadedAt:mixed, deliveredAt:mixed}>
     */
    private function cartons(FulfillmentOrder $fo): array
    {
        $rows = Carton::where('fo_id', $fo->id)->where('status', '!=', 'void')->orderBy('seq')->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $scanned = [];
        if ($fo->status === 'partial') {
            foreach ($fo->pods as $pod) {
                $scans = $pod->scans;
                foreach (is_string($scans) ? (array) json_decode($scans, true) : (array) $scans as $code) {
                    $scanned[] = ScanCodes::normalize(is_string($code) ? $code : null);
                }
            }
            $scanned = array_values(array_intersect($scanned, $rows->pluck('code')->map(fn ($c) => ScanCodes::normalize($c))->all()));
        }

        return $rows->map(function (Carton $c) use ($fo, $scanned) {
            $status = match ($fo->status) {
                'delivered' => 'delivered',
                'failed' => 'returned',
                'partial' => ! $scanned || in_array(ScanCodes::normalize($c->code), $scanned, true) ? 'delivered' : 'returned',
                default => $c->status,
            };

            return ['code' => $c->code, 'seq' => $c->seq, 'weightKg' => $c->weight_kg, 'status' => $status, 'loadedAt' => $c->loaded_at,
                'deliveredAt' => $status === 'delivered' ? ($c->delivered_at ?? $fo->delivered_at) : null];
        })->all();
    }

    /**
     * Marks the open tasks whose bin holds more than one batch of the product: for those the picker must scan the
     * batch too (`batchScan`), because bin + SKU cannot tell the batches apart.
     */
    private function withBatchScan(array $pickLists): array
    {
        $open = [];
        foreach ($pickLists as $pl) {
            foreach ($pl['tasks'] ?? [] as $t) {
                if (in_array($t['status'], ['open', 'partial'], true) && ! empty($t['batchId'])) {
                    $open[] = $t;
                }
            }
        }
        $several = [];
        if ($open) {
            $several = InventoryBalance::whereIn('product_id', array_unique(array_column($open, 'productId')))->whereIn('bin_id', array_unique(array_column($open, 'binId')))
                ->where('on_hand', '>', 0)->groupBy('product_id', 'bin_id')->selectRaw('product_id, bin_id, count(*) as n')->get()
                ->filter(fn ($r) => (int) $r->n > 1)->mapWithKeys(fn ($r) => ["{$r->product_id}|{$r->bin_id}" => true])->all();
        }
        foreach ($pickLists as $i => $pl) {
            foreach ($pl['tasks'] ?? [] as $j => $t) {
                $pickLists[$i]['tasks'][$j]['batchScan'] = in_array($t['status'], ['open', 'partial'], true) && ! empty($t['batchId']) && isset($several["{$t['productId']}|{$t['binId']}"]);
            }
        }

        return $pickLists;
    }

    /** Eager loads of the fulfillment document (lines by line number, tasks by pick sequence, primary barcodes only). */
    private static function foWith(): array
    {
        return [
            'customer', 'warehouse', 'so', 'lines' => fn ($q) => $q->orderBy('line_no'), 'lines.product', 'lines.product.barcodes' => fn ($q) => $q->where('is_primary', true),
            'pickLists' => fn ($q) => $q->orderBy('created_at')->orderBy('id'), 'pickLists.tasks' => fn ($q) => $q->orderBy('seq'), 'pickLists.tasks.bin.zone', 'pickLists.tasks.product', 'pickLists.tasks.batch',
            'packages' => fn ($q) => $q->orderBy('packed_at')->orderBy('id'), 'trip.vehicle', 'trip.driver', 'pods', 'returns',
        ];
    }
}
