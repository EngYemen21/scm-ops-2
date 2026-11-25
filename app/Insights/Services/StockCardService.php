<?php

namespace App\Insights\Services;

use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Platform\ReportSupport;
use App\Support\AppError;
use App\Support\Paging;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The stock card of one product — what the inventory ledger page (/api/inventory/ledger) does not give: an OPENING
 * balance, each movement as IN or OUT with the RUNNING balance after it, the CLOSING balance, the document behind each
 * line, an export and a print layout. It reads the same movements the ledger lists (`inventory_movements`, written
 * only by InventoryService); it is not a second ledger.
 *
 * Effect of a movement: for the whole company, a movement into a bin with no source bin is IN, out of a bin with no
 * destination is OUT, from a bin to a bin is internal (0). For ONE warehouse: into that warehouse from elsewhere (or
 * from nowhere) is IN, out of it to elsewhere (or nowhere) is OUT, inside it is internal. The closing balance of a
 * card that ends today therefore equals the on-hand quantity of the balances (scm:reconcile keeps ledger = balances).
 */
class StockCardService
{
    public const MAX_EXPORT = 50000;

    /** @param array{sku:string, warehouse?:?string, from?:?string, to?:?string, internal?:?bool} $f */
    public function card(array $f, Paging $page): array
    {
        [$product, $warehouse, $from, $to] = $this->scope($f);
        $opening = $this->opening($product->id, $warehouse?->id, $from);
        $all = $this->movements($product->id, $warehouse?->id, $from, $to)->get();
        $running = $opening;
        $in = $out = 0;
        $rows = [];
        foreach ($all as $m) {
            [$i, $o] = $this->effect($m, $warehouse?->id);
            $running += $i - $o;
            $in += $i;
            $out += $o;
            if ($i === 0 && $o === 0 && empty($f['internal'])) {
                continue;   // a move between two bins of the scope: shown only when asked for
            }
            $rows[] = ['m' => $m, 'in' => $i, 'out' => $o, 'balance' => $running];
        }
        $slice = array_slice($rows, ($page->page - 1) * $page->pageSize, $page->pageSize);

        return $page->wrap(array_map(fn ($r) => $this->row($r), $slice), count($rows)) + [
            'product' => ['id' => $product->id, 'sku' => $product->sku, 'nameAr' => $product->name_ar, 'nameEn' => $product->name_en, 'tracksExpiry' => (bool) $product->tracks_expiry],
            'warehouse' => $warehouse ? ['id' => $warehouse->id, 'code' => $warehouse->code, 'nameAr' => $warehouse->name_ar, 'nameEn' => $warehouse->name_en] : null,
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'opening' => $opening, 'in' => $in, 'out' => $out, 'closing' => $running,
            'onHandNow' => $this->onHand($product->id, $warehouse?->id),
            'definition' => ['ar' => 'الرصيد الافتتاحي = أثر كل الحركات قبل بداية الفترة؛ الرصيد بعد كل حركة = الرصيد السابق + الوارد − الصادر. النقل بين موقعين داخل النطاق لا يغيّر الرصيد.',
                'en' => 'Opening = the effect of every movement before the period; balance after each line = previous balance + in − out. A move between two bins inside the scope does not change it.'],
        ];
    }

    /** @return array{fileName:string, body:string} */
    public function csv(array $f): array
    {
        $card = $this->card(['internal' => true] + $f, new Paging(1, self::MAX_EXPORT, null, null, 'asc'));
        $cols = [['key' => 'date', 'labelAr' => 'التاريخ'], ['key' => 'number', 'labelAr' => 'رقم الحركة'], ['key' => 'type', 'labelAr' => 'النوع'], ['key' => 'document', 'labelAr' => 'المستند'],
            ['key' => 'batch', 'labelAr' => 'الدفعة'], ['key' => 'from', 'labelAr' => 'من'], ['key' => 'to', 'labelAr' => 'إلى'], ['key' => 'in', 'labelAr' => 'وارد'], ['key' => 'out', 'labelAr' => 'صادر'],
            ['key' => 'balance', 'labelAr' => 'الرصيد'], ['key' => 'user', 'labelAr' => 'المستخدم'], ['key' => 'note', 'labelAr' => 'ملاحظة']];
        $rows = [['date' => $card['from'], 'number' => '', 'type' => 'opening', 'document' => 'رصيد افتتاحي', 'balance' => $card['opening']]];
        foreach ($card['items'] as $r) {
            $rows[] = ['date' => $r['at'], 'number' => $r['number'], 'type' => $r['type'], 'document' => $r['reference']['number'] ?? '', 'batch' => $r['batchNo'],
                'from' => $r['src'] ? "{$r['src']['warehouse']}/{$r['src']['bin']}" : '', 'to' => $r['dst'] ? "{$r['dst']['warehouse']}/{$r['dst']['bin']}" : '',
                'in' => $r['in'], 'out' => $r['out'], 'balance' => $r['balance'], 'user' => $r['user'], 'note' => $r['note']];
        }
        $rows[] = ['date' => $card['to'], 'type' => 'closing', 'document' => 'رصيد ختامي', 'in' => $card['in'], 'out' => $card['out'], 'balance' => $card['closing']];

        return ['fileName' => 'stock-card-'.$card['product']['sku'].($card['warehouse'] ? '-'.$card['warehouse']['code'] : '').'-'.$card['from'].'-'.$card['to'].'.csv', 'body' => ReportSupport::toCsv($cols, $rows)];
    }

    /** @return array{0:Product, 1:?Warehouse, 2:Carbon, 3:Carbon} */
    private function scope(array $f): array
    {
        $product = Product::where('sku', $f['sku'])->orWhere('id', $f['sku'])->first()
            ?? throw AppError::notFound('PRODUCT_NOT_FOUND', "المنتج {$f['sku']} غير موجود", "Product {$f['sku']} not found");
        $warehouse = null;
        if (! empty($f['warehouse'])) {
            $warehouse = Warehouse::where('code', mb_strtoupper($f['warehouse']))->orWhere('id', $f['warehouse'])->first()
                ?? throw AppError::notFound('WAREHOUSE_NOT_FOUND', "المستودع {$f['warehouse']} غير موجود", 'Warehouse not found');
        }
        $to = ! empty($f['to']) ? Carbon::parse($f['to'])->endOfDay() : now()->endOfDay();
        $from = ! empty($f['from']) ? Carbon::parse($f['from'])->startOfDay() : $to->copy()->startOfMonth();
        if ($from->gt($to)) {
            throw AppError::validation('RANGE', 'بداية الفترة بعد نهايتها', 'From is after to');
        }

        return [$product, $warehouse, $from, $to];
    }

    private function base(string $productId)
    {
        return DB::table('inventory_movements as m')->leftJoin('bins as sb', 'sb.id', '=', 'm.src_bin_id')->leftJoin('bins as db', 'db.id', '=', 'm.dst_bin_id')
            ->where('m.product_id', $productId);
    }

    private function opening(string $productId, ?string $warehouseId, Carbon $from): int
    {
        $q = $this->base($productId)->where('m.created_at', '<', $from);
        if ($warehouseId) {
            $w = DB::getPdo()->quote($warehouseId);
            $r = $q->selectRaw("coalesce(sum(case when db.warehouse_id = {$w} and (sb.warehouse_id is null or sb.warehouse_id <> {$w}) then m.qty else 0 end), 0) as i,
                coalesce(sum(case when sb.warehouse_id = {$w} and (db.warehouse_id is null or db.warehouse_id <> {$w}) then m.qty else 0 end), 0) as o")->first();
        } else {
            $r = $q->selectRaw('coalesce(sum(case when m.dst_bin_id is not null and m.src_bin_id is null then m.qty else 0 end), 0) as i,
                coalesce(sum(case when m.src_bin_id is not null and m.dst_bin_id is null then m.qty else 0 end), 0) as o')->first();
        }

        return (int) $r->i - (int) $r->o;
    }

    private function movements(string $productId, ?string $warehouseId, Carbon $from, Carbon $to)
    {
        return $this->base($productId)->whereBetween('m.created_at', [$from, $to])
            ->when($warehouseId, fn ($q) => $q->where(fn ($w) => $w->where('sb.warehouse_id', $warehouseId)->orWhere('db.warehouse_id', $warehouseId)))
            ->leftJoin('warehouses as sw', 'sw.id', '=', 'sb.warehouse_id')->leftJoin('warehouses as dw', 'dw.id', '=', 'db.warehouse_id')
            ->orderBy('m.created_at')->orderBy('m.number')->orderBy('m.id')
            ->select(['m.id', 'm.number', 'm.type', 'm.qty', 'm.batch_no', 'm.reference_type', 'm.reference_id', 'm.reference_number', 'm.note', 'm.username', 'm.created_at', 'm.src_bin_id', 'm.dst_bin_id',
                'sb.warehouse_id as src_wh', 'db.warehouse_id as dst_wh', 'sb.code as src_bin', 'db.code as dst_bin', 'sw.code as src_code', 'dw.code as dst_code']);
    }

    /** @return array{0:int, 1:int} in, out */
    private function effect(object $m, ?string $warehouseId): array
    {
        $q = (int) $m->qty;
        if ($warehouseId) {
            $src = $m->src_wh === $warehouseId;
            $dst = $m->dst_wh === $warehouseId;

            return [$dst && ! $src ? $q : 0, $src && ! $dst ? $q : 0];
        }

        return [$m->dst_bin_id && ! $m->src_bin_id ? $q : 0, $m->src_bin_id && ! $m->dst_bin_id ? $q : 0];
    }

    private function row(array $r): array
    {
        $m = $r['m'];

        return [
            'id' => $m->id, 'number' => $m->number, 'at' => Carbon::parse($m->created_at)->toJSON(), 'type' => $m->type, 'batchNo' => $m->batch_no,
            'reference' => $m->reference_number || $m->reference_id ? ['type' => $m->reference_type, 'id' => $m->reference_id, 'number' => $m->reference_number] : null,
            'src' => $m->src_bin_id ? ['warehouse' => $m->src_code, 'bin' => $m->src_bin] : null, 'dst' => $m->dst_bin_id ? ['warehouse' => $m->dst_code, 'bin' => $m->dst_bin] : null,
            'in' => $r['in'], 'out' => $r['out'], 'balance' => $r['balance'], 'user' => $m->username, 'note' => $m->note,
        ];
    }

    private function onHand(string $productId, ?string $warehouseId): int
    {
        return (int) DB::table('inventory_balances')->where('product_id', $productId)->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))->sum('on_hand');
    }
}
