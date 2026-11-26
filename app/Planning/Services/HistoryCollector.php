<?php

namespace App\Planning\Services;

use App\Planning\Models\Run;
use App\Planning\Support\Clock;
use App\Planning\Support\Refs;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The first phase of a computation: the demand of the history window, read ONCE into the run's own table
 * (`planning_demand`: product · warehouse · customer · day · quantity out · quantity returned · where it came from), so
 * that everything after it reads an indexed table of its own and the run is reproducible from what it read.
 *
 * WHERE THE DEMAND COMES FROM (history source of the run):
 *   delivery    what really left for a customer:
 *                 OPS   the delivered quantity of each fulfilment order line, on the day the order was delivered;
 *                 Odoo  every done move line from a warehouse location to the customers' location (PoS included), on
 *                       the day it was done, in the product's own unit (`quantity_product_uom`);
 *   sale_order  what customers ordered and the order was confirmed:
 *                 OPS   the quantity the customer asked for on each line of an order that is not a draft nor cancelled,
 *                       on the day of the order;
 *                 Odoo  each line of a confirmed order (`sale`, `done`; notes and sections left out), converted to the
 *                       product's unit, on the day of the order.
 * RETURNS (with "net customer returns"): OPS customer returns that were received; Odoo done move lines from the
 * customers' location back into a warehouse. They are kept apart (`returned`) and taken off where the method needs.
 *
 * WHAT IS LEFT OUT, and counted in the run's diagnostics: a line of a product, a warehouse or a customer outside the
 * run; an Odoo product with no OPS product; an Odoo warehouse nobody mapped to an OPS warehouse (it is never guessed);
 * an Odoo line in a unit that cannot be converted. An OPS order that came from Odoo is counted once, from Odoo.
 *
 * Also read here: the last purchase price of each product (OPS purchase orders, Odoo purchase order lines), the
 * fallback after the supplier price lists.
 *
 * Odoo is read from the local copy (`odoo_records`) in slices ordered by the Odoo id, so the phase can stop at any
 * moment and continue where it stood.
 */
class HistoryCollector
{
    /** Odoo records read per slice. */
    public const SLICE = 2500;

    private const OPS_OPEN_PO = ['approved', 'sent', 'confirmed', 'partial', 'closed', 'received', 'completed'];

    private Refs $refs;

    /** @var array<string, true>|null products the run is limited to (null = any) */
    private ?array $products = null;

    public function __construct()
    {
        $this->refs = new Refs;
    }

    /** @return string[] the steps of the phase for a run, in order */
    public static function steps(Run $run): array
    {
        $steps = [];
        if ($run->include_ops !== false) {
            $steps[] = 'ops';
        }
        if ($run->include_odoo !== false) {
            $steps[] = $run->history_source === 'sale_order' ? 'odoo:sale.order.line' : 'odoo:stock.move.line';
            if ($run->history_source === 'sale_order' && $run->include_returns) {
                $steps[] = 'odoo:returns';
            }
            $steps[] = 'odoo:purchase.order.line';
        }

        return $steps;
    }

    /** How many units of work the phase holds (Odoo records to read + one for OPS) — the progress bar. */
    public static function estimate(Run $run): int
    {
        $n = 0;
        foreach (self::steps($run) as $step) {
            $n += $step === 'ops' ? 1 : (int) DB::table('odoo_records')->where('model', self::model($step))->count();
        }

        return $n;
    }

    private static function model(string $step): string
    {
        return $step === 'odoo:returns' ? 'stock.move.line' : substr($step, 5);
    }

    /**
     * Reads until the phase is done or the clock says stop (one slice at least).
     *
     * @param  array{step?:int, cursor?:int, skipped?:array<string,int>, read?:array<string,int>}  $state
     * @return bool true when the phase is complete
     */
    public function advance(Run $run, array &$state, float $until, int &$done): bool
    {
        $steps = self::steps($run);
        $state += ['step' => 0, 'cursor' => 0, 'skipped' => [], 'read' => []];
        $first = true;
        while ($state['step'] < count($steps)) {
            if (! $first && microtime(true) >= $until) {
                return false;
            }
            $first = false;
            $step = $steps[$state['step']];
            if ($step === 'ops') {
                $this->ops($run, $state);
                $done++;
                $state['step']++;
                $state['cursor'] = 0;

                continue;
            }
            $rows = DB::table('odoo_records')->where('model', self::model($step))->where('odoo_id', '>', $state['cursor'])->whereNull('deleted_at')
                ->orderBy('odoo_id')->limit(self::SLICE)->get(['odoo_id', 'data']);
            if ($rows->isEmpty()) {
                $state['step']++;
                $state['cursor'] = 0;

                continue;
            }
            match ($step) {
                'odoo:stock.move.line' => $this->moveLines($run, $rows->all(), $state, true),
                'odoo:returns' => $this->moveLines($run, $rows->all(), $state, false),
                'odoo:sale.order.line' => $this->orderLines($run, $rows->all(), $state),
                'odoo:purchase.order.line' => $this->purchaseLines($run, $rows->all(), $state),
            };
            $state['cursor'] = (int) $rows->last()->odoo_id;
            $done += $rows->count();
        }

        return true;
    }

    // ───────────── OPS ─────────────

    private function ops(Run $run, array &$state): void
    {
        [$from, $to] = [$run->day('history_from'), $run->day('history_to')];
        // a day of the business starts 3 hours (Riyadh) before the UTC day: read one day more on each side, keep by day
        [$fromUtc, $toUtc] = [date('Y-m-d', strtotime($from.' -1 day')).' 00:00:00', date('Y-m-d', strtotime($to.' +1 day')).' 23:59:59'];
        $facts = [];
        $fromOdoo = fn ($w) => $w->whereNull('so.source_system')->orWhere('so.source_system', '!=', Refs::SYSTEM);
        if ($run->history_source === 'sale_order') {
            $lines = DB::table('sales_order_lines as l')->join('sales_orders as so', 'so.id', '=', 'l.so_id')
                ->whereNotIn('so.status', ['draft', 'cancelled'])->whereBetween('so.date', [$fromUtc, $toUtc])->where($fromOdoo)
                ->orderBy('l.id')->get(['l.product_id', 'so.warehouse_id', 'so.customer_id', 'so.date as at', DB::raw('COALESCE(l.ordered_qty, l.qty) as qty')]);
        } else {
            $lines = DB::table('fo_lines as l')->join('fulfillment_orders as fo', 'fo.id', '=', 'l.fo_id')->leftJoin('sales_orders as so', 'so.id', '=', 'fo.so_id')
                ->where('l.delivered_qty', '>', 0)->whereNotNull('fo.delivered_at')->whereBetween('fo.delivered_at', [$fromUtc, $toUtc])
                ->where(fn ($w) => $w->whereNull('so.id')->orWhere($fromOdoo))
                ->orderBy('l.id')->get(['l.product_id', 'fo.warehouse_id', 'fo.customer_id', 'fo.delivered_at as at', 'l.delivered_qty as qty']);
        }
        foreach ($lines as $l) {
            $this->add($run, $facts, $state, 'ops', (string) $l->product_id, (string) $l->warehouse_id, (string) $l->customer_id, Clock::day($this->utc($l->at)), (float) $l->qty, 0.0);
        }
        if ($run->include_returns) {
            $returns = DB::table('return_lines as l')->join('returns as r', 'r.id', '=', 'l.return_id')->join('return_receiving as rr', 'rr.return_id', '=', 'r.id')
                ->where('r.type', 'cust')->whereIn('r.status', ['received', 'inspect', 'closed'])->whereBetween('rr.received_at', [$fromUtc, $toUtc])
                ->orderBy('l.id')->get(['l.product_id', 'r.warehouse_id', 'r.customer_id', 'rr.received_at as at', DB::raw('COALESCE(l.received_qty, l.qty) as qty')]);
            foreach ($returns as $l) {
                $this->add($run, $facts, $state, 'ops', (string) $l->product_id, (string) $l->warehouse_id, $l->customer_id ? (string) $l->customer_id : null, Clock::day($this->utc($l->at)), 0.0, (float) $l->qty);
            }
        }
        $this->write($run, $facts);

        // the last price OPS paid: every line of an order that was approved or went further
        $prices = [];
        $po = DB::table('po_lines as l')->join('purchase_orders as po', 'po.id', '=', 'l.po_id')->whereIn('po.status', self::OPS_OPEN_PO)->where('l.price', '>', 0)
            ->orderBy('po.created_at')->orderBy('l.id')->get(['l.product_id', 'l.price', 'po.created_at', 'po.number']);
        foreach ($po as $l) {
            $prices[(string) $l->product_id] = ['price' => (float) $l->price, 'day' => Clock::day($this->utc($l->created_at)), 'ref' => (string) $l->number];
        }
        $this->writePrices($run, $prices, 'ops');
    }

    private function utc(mixed $at): ?string
    {
        return $at === null ? null : substr((string) $at, 0, 19);
    }

    // ───────────── Odoo ─────────────

    /** Done move lines: to the customers = delivered (`$out`), or back from them = returned. */
    private function moveLines(Run $run, array $rows, array &$state, bool $out): void
    {
        $decoded = [];
        $pickings = [];
        foreach ($rows as $r) {
            $d = json_decode((string) $r->data, true) ?: [];
            if (($d['state'] ?? null) !== 'done') {
                continue;
            }
            $src = $this->refs->location(self::id($d['location_id'] ?? null));
            $dst = $this->refs->location(self::id($d['location_dest_id'] ?? null));
            if (! $src || ! $dst) {
                continue;
            }
            $delivery = $dst['usage'] === 'customer' && $src['usage'] === 'internal';
            $return = $src['usage'] === 'customer' && $dst['usage'] === 'internal';
            if (! ($delivery || ($return && $run->include_returns)) || ($out === false && ! $return)) {
                continue;
            }
            $decoded[] = [$d, $delivery ? $src : $dst, $delivery];
            if ($p = self::id($d['picking_id'] ?? null)) {
                $pickings[$p] = true;
            }
        }
        $partners = $this->pickingPartners(array_keys($pickings));
        $facts = [];
        foreach ($decoded as [$d, $loc, $delivery]) {
            $qty = (float) ($d['quantity_product_uom'] ?? $d['quantity'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $product = $this->odooProduct($d['product_id'] ?? null, $state);
            $warehouse = $this->odooWarehouse($loc['warehouse'], $state);
            if (! $product || ! $warehouse) {
                continue;
            }
            $customer = $this->refs->customerKey($partners[self::id($d['picking_id'] ?? null) ?? 0] ?? null);
            $day = Clock::day((string) ($d['date'] ?? ''));
            $this->add($run, $facts, $state, 'odoo', $product, $warehouse, $customer, $day, $delivery ? $qty : 0.0, $delivery ? 0.0 : $qty);
        }
        $this->write($run, $facts);
    }

    /** @return array<int, int> picking id → partner id */
    private function pickingPartners(array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach (DB::table('odoo_records')->where('model', 'stock.picking')->whereIn('odoo_id', $chunk)->get(['odoo_id', 'data']) as $p) {
                $d = json_decode((string) $p->data, true) ?: [];
                if ($partner = self::id($d['partner_id'] ?? null)) {
                    $out[(int) $p->odoo_id] = $partner;
                }
            }
        }

        return $out;
    }

    /** Lines of confirmed sale orders. */
    private function orderLines(Run $run, array $rows, array &$state): void
    {
        $decoded = [];
        $orders = [];
        foreach ($rows as $r) {
            $d = json_decode((string) $r->data, true) ?: [];
            if (! in_array($d['state'] ?? null, ['sale', 'done'], true) || ! empty($d['display_type']) || ! self::id($d['product_id'] ?? null)) {
                continue;
            }
            $decoded[] = $d;
            if ($o = self::id($d['order_id'] ?? null)) {
                $orders[$o] = true;
            }
        }
        $heads = [];
        foreach (array_chunk(array_keys($orders), 1000) as $chunk) {
            foreach (DB::table('odoo_records')->where('model', 'sale.order')->whereIn('odoo_id', $chunk)->whereNull('deleted_at')->get(['odoo_id', 'data']) as $o) {
                $heads[(int) $o->odoo_id] = json_decode((string) $o->data, true) ?: [];
            }
        }
        $units = $this->productUnits(array_values(array_unique(array_map(fn ($d) => self::id($d['product_id']), array_filter($decoded, fn ($d) => self::id($d['product_uom'] ?? null) !== 1)))));
        $facts = [];
        foreach ($decoded as $d) {
            $head = $heads[self::id($d['order_id'] ?? null) ?? 0] ?? null;
            if (! $head || ! in_array($head['state'] ?? null, ['sale', 'done'], true)) {
                continue;
            }
            $odooProduct = self::id($d['product_id']);
            $qty = (float) ($d['product_uom_qty'] ?? 0);
            $lineUnit = self::id($d['product_uom'] ?? null);
            if ($lineUnit !== null && $lineUnit !== 1) {
                $qty = $this->refs->convert($qty, $lineUnit, $units[$odooProduct] ?? null);
                if ($qty === null) {
                    $state['skipped']['unit'] = ($state['skipped']['unit'] ?? 0) + 1;

                    continue;
                }
            }
            if ($qty <= 0) {
                continue;
            }
            $product = $this->odooProduct($d['product_id'], $state);
            $warehouse = $this->odooWarehouse(self::id($head['warehouse_id'] ?? null), $state);
            if (! $product || ! $warehouse) {
                continue;
            }
            $customer = $this->refs->customerKey(self::id($head['partner_id'] ?? null) ?? self::id($d['order_partner_id'] ?? null));
            $this->add($run, $facts, $state, 'odoo', $product, $warehouse, $customer, Clock::day((string) ($head['date_order'] ?? '')), $qty, 0.0);
        }
        $this->write($run, $facts);
    }

    /** @return array<int, ?int> Odoo product id → the unit of its template (only for lines in another unit than the reference one) */
    private function productUnits(array $productIds): array
    {
        if (! $productIds) {
            return [];
        }
        $templates = [];
        foreach (DB::table('odoo_records')->where('model', 'product.product')->whereIn('odoo_id', $productIds)->get(['odoo_id', 'data']) as $p) {
            $d = json_decode((string) $p->data, true) ?: [];
            $templates[(int) $p->odoo_id] = self::id($d['product_tmpl_id'] ?? null);
        }
        $units = [];
        foreach (DB::table('odoo_records')->where('model', 'product.template')->whereIn('odoo_id', array_filter($templates))->get(['odoo_id', 'data']) as $t) {
            $d = json_decode((string) $t->data, true) ?: [];
            $units[(int) $t->odoo_id] = self::id($d['uom_id'] ?? null);
        }

        return array_map(fn ($t) => $t ? ($units[$t] ?? null) : null, $templates);
    }

    /** The last price paid for each product on a confirmed Odoo purchase order line. */
    private function purchaseLines(Run $run, array $rows, array &$state): void
    {
        $prices = [];
        foreach ($rows as $r) {
            $d = json_decode((string) $r->data, true) ?: [];
            if (! in_array($d['state'] ?? null, ['purchase', 'done'], true) || (float) ($d['price_unit'] ?? 0) <= 0 || ! empty($d['display_type'])) {
                continue;
            }
            $product = $this->refs->link('product', self::id($d['product_id'] ?? null));
            if (! $product) {
                continue;
            }
            $day = Clock::day((string) ($d['date_planned'] ?? $d['create_date'] ?? '')) ?? '0000-00-00';
            $known = $prices[$product] ?? null;
            if (! $known || $day >= $known['day']) {
                $prices[$product] = ['price' => round((float) $d['price_unit'], 4), 'day' => $day, 'ref' => (string) (is_array($d['order_id'] ?? null) ? ($d['order_id'][1] ?? '') : '')];
            }
        }
        $this->writePrices($run, $prices, 'odoo');
    }

    private function odooProduct(mixed $value, array &$state): ?string
    {
        $id = self::id($value);
        $product = $this->refs->link('product', $id);
        if (! $product) {
            $state['skipped']['odooProductNotLinked'] = ($state['skipped']['odooProductNotLinked'] ?? 0) + 1;
        }

        return $product;
    }

    private function odooWarehouse(?int $odooWarehouse, array &$state): ?string
    {
        $wh = $this->refs->warehouse($odooWarehouse);
        if (! $wh) {
            $key = 'odooWarehouseNotMapped:'.($odooWarehouse ?? 0);
            $state['skipped'][$key] = ($state['skipped'][$key] ?? 0) + 1;
        }

        return $wh;
    }

    // ───────────── the run's own table ─────────────

    /** One line of demand, if it belongs to the run. */
    private function add(Run $run, array &$facts, array &$state, string $origin, string $product, string $warehouse, ?string $customer, ?string $day, float $qty, float $returned): void
    {
        $skip = function (string $why) use (&$state) {
            $state['skipped'][$why] = ($state['skipped'][$why] ?? 0) + 1;
        };
        if ($day === null || $day < $run->day('history_from') || $day > $run->day('history_to')) {
            return; // outside the window: not demand of this run (not counted — the window decides)
        }
        if (! in_array($warehouse, (array) $run->warehouse_ids, true)) {
            $skip('otherWarehouse');

            return;
        }
        if (! $this->productInScope($run, $product)) {
            $skip('productOutsideRun');

            return;
        }
        if ($customer !== null && in_array($customer, (array) $run->exclude_customer_ids, true)) {
            $skip('customerExcluded');

            return;
        }
        if ($run->customer_mode === 'filter' && ($customer === null || ! in_array($customer, (array) $run->customer_ids, true))) {
            $skip('customerNotSelected');

            return;
        }
        $key = "{$product}|{$warehouse}|{$customer}|{$day}|{$origin}";
        $facts[$key] ??= ['product_id' => $product, 'warehouse_id' => $warehouse, 'customer_key' => $customer, 'day' => $day, 'origin' => $origin, 'qty' => 0.0, 'returned' => 0.0];
        $facts[$key]['qty'] += $qty;
        $facts[$key]['returned'] += $returned;
        $state['read'][$origin] = ($state['read'][$origin] ?? 0) + 1;
    }

    private function productInScope(Run $run, string $product): bool
    {
        if ($run->product_selection !== 'manual') {
            return true;
        }
        $this->products ??= array_fill_keys(RunScope::manualProducts($run), true);

        return isset($this->products[$product]);
    }

    private function write(Run $run, array $facts): void
    {
        $rows = [];
        foreach ($facts as $f) {
            $rows[] = $f + ['run_id' => $run->id];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('planning_demand')->insert($chunk);
        }
    }

    private function writePrices(Run $run, array $prices, string $origin): void
    {
        $rows = [];
        foreach ($prices as $product => $p) {
            $rows[] = ['id' => (string) Str::ulid(), 'run_id' => $run->id, 'product_id' => (string) $product, 'price' => $p['price'], 'day' => $p['day'] === '0000-00-00' ? null : $p['day'], 'origin' => $origin, 'ref' => mb_substr($p['ref'], 0, 60)];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('planning_prices')->insert($chunk);
        }
    }

    private static function id(mixed $v): ?int
    {
        if (is_array($v)) {
            $v = $v[0] ?? null;
        }

        return is_int($v) && $v > 0 ? $v : null;
    }
}
