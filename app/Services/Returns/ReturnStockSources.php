<?php

namespace App\Services\Returns;

use App\Finance\Services\DebitNoteService;
use App\Models\GoodsReceipt;
use App\Models\GrnLine;
use App\Models\InventoryBalance;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Support\AppError;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Where a return taken from stock can come from: the balance rows (bin × batch) a purchase return or a damaged-goods
 * return may pick, with what is free on each. For a supplier the rows are narrowed to what that supplier delivered
 * (goods receipts → product and batch), optionally to one purchase order or one receipt.
 */
class ReturnStockSources
{
    /** Zones whose stock belongs to a document in progress (receiving, picking, a return being inspected). */
    public const NO_SOURCE_ZONES = ['staging', 'returns'];

    public function __construct(private readonly DebitNoteService $debitNotes) {}

    /**
     * @param  array{warehouse?:?string, supplier?:?string, po?:?string, grn?:?string, q?:?string, scope?:?string}  $f
     *                                                                                                                  `scope=all` lists every eligible row of the warehouse (stock from before the receipts were kept in the system)
     * @return array{rows: array<int, array>, receipts: array{pos: array, grns: array}, scope: string, truncated: bool}
     */
    public function rows(array $f): array
    {
        $wh = Warehouse::where('code', (string) ($f['warehouse'] ?? ''))->first() ?? throw AppError::notFound('WH_NOT_FOUND', 'المستودع غير موجود', 'Warehouse not found');
        $supplier = null;
        if (! empty($f['supplier'])) {
            $supplier = Supplier::where('code', $f['supplier'])->orWhere('id', $f['supplier'])->first()
                ?? throw AppError::notFound('SUPPLIER_NOT_FOUND', "المورد {$f['supplier']} غير موجود", 'Supplier not found');
        }
        $po = ! empty($f['po']) ? (PurchaseOrder::where('number', $f['po'])->orWhere('id', $f['po'])->first() ?? throw AppError::notFound('PO_NOT_FOUND', 'أمر الشراء غير موجود', 'PO not found')) : null;
        $grn = ! empty($f['grn']) ? (GoodsReceipt::where('number', $f['grn'])->orWhere('id', $f['grn'])->first() ?? throw AppError::notFound('GRN_NOT_FOUND', 'سند الاستلام غير موجود', 'GRN not found')) : null;
        $all = ($f['scope'] ?? null) === 'all' || ! $supplier;

        $q = InventoryBalance::query()->from('inventory_balances as b')
            ->join('products as p', 'p.id', '=', 'b.product_id')->join('bins as bn', 'bn.id', '=', 'b.bin_id')->join('zones as z', 'z.id', '=', 'bn.zone_id')
            ->leftJoin('batches as bt', 'bt.id', '=', 'b.batch_id')
            ->where('b.warehouse_id', $wh->id)->where('b.on_hand', '>', 0)->whereNotIn('z.type', self::NO_SOURCE_ZONES);
        if (! $all) {
            $receipts = fn () => GrnLine::query()->join('goods_receipts as g', 'g.id', '=', 'grn_lines.grn_id')->where('g.supplier_id', $supplier->id)->whereNull('g.voided_at')
                ->when($po, fn ($w) => $w->where('g.po_id', $po->id))->when($grn, fn ($w) => $w->where('g.id', $grn->id));
            $productIds = $receipts()->distinct()->pluck('grn_lines.product_id')->all();
            $batchIds = $receipts()->whereNotNull('grn_lines.batch_id')->distinct()->pluck('grn_lines.batch_id')->all();
            // a batch row must be one of the supplier's batches; a batchless row cannot be told apart, so the product is enough
            $q->whereIn('b.product_id', $productIds)->where(fn ($w) => $w->whereNull('b.batch_id')->orWhereIn('b.batch_id', $batchIds));
        }
        if (! empty($f['q'])) {
            $like = '%'.trim((string) $f['q']).'%';
            $q->where(fn ($w) => $w->where('p.sku', 'like', $like)->orWhere('p.name_ar', 'like', $like)->orWhere('p.name_en', 'like', $like)->orWhere('bn.code', 'like', $like)->orWhere('bt.batch_no', 'like', $like));
        }
        $limit = 80;
        $found = $q->orderBy('p.sku')->orderBy('bn.code')->orderBy('b.id')->limit($limit + 1)->get([
            'b.id', 'b.product_id', 'b.batch_id', 'b.on_hand', 'b.reserved', 'b.quarantine', 'b.blocked', 'p.sku', 'p.name_ar', 'p.name_en', 'p.tracks_expiry', 'p.avg_cost', 'p.purchase_price',
            'bn.code as bin_code', 'z.type as zone_type', 'bt.batch_no', 'bt.expiry_date as batch_expiry',
        ]);
        $prices = [];
        $rows = $found->take($limit)->map(function ($b) use ($supplier, $po, &$prices) {
            $prices[$b->product_id] ??= $supplier ? $this->debitNotes->purchasePrice($supplier->id, $b->product_id, $po?->id) : null;
            $priced = $prices[$b->product_id];
            $cost = $b->avg_cost !== null ? (float) $b->avg_cost : ($b->purchase_price !== null ? (float) $b->purchase_price : null);

            return [
                'key' => $b->id, 'sku' => $b->sku, 'nameAr' => $b->name_ar, 'nameEn' => $b->name_en, 'tracksExpiry' => (bool) $b->tracks_expiry,
                'bin' => $b->bin_code, 'zoneType' => $b->zone_type, 'batchNo' => $b->batch_no, 'expiryDate' => $b->batch_expiry ? Carbon::parse($b->batch_expiry)->toJSON() : null,
                'onHand' => (int) $b->on_hand, 'reserved' => (int) $b->reserved, 'available' => $b->blocked ? 0 : max(0, (int) $b->on_hand - (int) $b->reserved),
                'quarantine' => (bool) $b->quarantine, 'blocked' => (bool) $b->blocked,
                'price' => $priced['price'] ?? $cost, 'priceSource' => $priced ? 'po' : ($cost !== null ? 'cost' : null), 'poNumber' => $priced['poNumber'] ?? null,
            ];
        })->values()->all();

        return ['rows' => $rows, 'receipts' => $supplier ? $this->receiptsOf($supplier, $wh) : ['pos' => [], 'grns' => []], 'scope' => $all ? 'all' : 'supplier', 'truncated' => $found->count() > $limit];
    }

    /** The supplier's recent purchase orders and goods receipts at this warehouse (the optional reference of a purchase return). */
    private function receiptsOf(Supplier $supplier, Warehouse $wh): array
    {
        $grns = DB::table('goods_receipts as g')->join('purchase_orders as po', 'po.id', '=', 'g.po_id')->where('g.supplier_id', $supplier->id)->where('g.warehouse_id', $wh->id)->whereNull('g.voided_at')
            ->orderByDesc('g.posted_at')->orderByDesc('g.id')->limit(40)->get(['g.number', 'g.posted_at', 'po.number as po_number']);
        $pos = $grns->pluck('po_number')->unique()->values();

        return [
            'pos' => $pos->map(fn ($n) => ['number' => $n])->all(),
            'grns' => $grns->map(fn ($g) => ['number' => $g->number, 'poNumber' => $g->po_number, 'postedAt' => $g->posted_at ? Carbon::parse($g->posted_at)->toJSON() : null])->all(),
        ];
    }
}
