<?php

namespace App\Services\Platform;

use App\Models\Warehouse;
use App\Services\Platform\ReportSupport as R;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A report is one grouped SELECT (`body`); the runner pages it, counts it and sums the numeric keys.
 *
 * SQL is MySQL 8 over the snake_case schema. Every user-supplied value (q, status, warehouse, range) is a bound
 * parameter; the only inlined literals are instants produced by Carbon here. Result aliases are the camelCase API
 * keys, always back-quoted (several are reserved words: `lines`, `rows`, `partial` …).
 */
class ReportsService
{
    private const NOT_AVAILABLE_ZONES = "'quarantine','staging','returns','damaged'";

    /** Sub-aggregate of GRN lines: ordered (from the shipment line), received, accepted, damaged, rejected per GRN. */
    private const GRN_AGG = '(SELECT gl.grn_id, COUNT(*) AS `lines`, SUM(COALESCE(sl.ordered_qty, gl.received_qty)) AS ordered, SUM(gl.received_qty) AS received,
        SUM(gl.accepted_qty) AS accepted, SUM(gl.damaged_qty) AS damaged, SUM(gl.rejected_qty) AS rejected
        FROM grn_lines gl LEFT JOIN shipment_lines sl ON sl.id = gl.shipment_line_id GROUP BY gl.grn_id)';

    /** Bound parameters of the report being built, in the order their placeholders appear in the SQL. */
    private array $bindings = [];

    public function names(): array
    {
        return array_map(fn ($name) => ['name' => $name] + R::REPORT_META[$name], R::REPORT_NAMES);
    }

    /**
     * @param  array{warehouse?:?string, from?:?string, to?:?string, status?:?string}  $f
     * @return array{columns:array, rows:array, totals?:array, total:int, page:int, pageSize:int, pages:int, generatedAt:string}
     */
    public function run(AuthUser $user, string $name, Paging $page, array $f): array
    {
        if (! in_array($name, R::REPORT_NAMES, true)) {
            throw AppError::notFound('REPORT_NOT_FOUND', "التقرير «{$name}» غير موجود", "Report \"{$name}\" not found");
        }
        $whId = null;
        $warehouse = trim((string) ($f['warehouse'] ?? ''));
        if ($warehouse !== '') {
            $whId = Warehouse::where('code', $warehouse)->orWhere('id', $warehouse)->value('id')
                ?? throw AppError::validation('WAREHOUSE_NOT_FOUND', "المستودع {$warehouse} غير موجود", "Warehouse {$warehouse} not found");
        }
        $meta = R::REPORT_META[$name];
        $range = ($meta['snapshot'] ?? false) ? ['from' => R::startOfToday(), 'to' => now()] : R::dateRange($f['from'] ?? null, $f['to'] ?? null, $meta['defaultDays']);
        $status = trim((string) ($f['status'] ?? ''));

        $this->bindings = [];
        $def = $this->build($name, new ReportContext($page->q, $status === '' ? null : $status, $whId, R::startOfToday(), now(), $range['from'], $range['to']));
        $bindings = $this->bindings;
        $body = $def['body'];

        $offset = ($page->page - 1) * $page->pageSize;
        $rows = DB::select("SELECT * FROM ({$body}) t ORDER BY {$def['order']} LIMIT {$page->pageSize} OFFSET {$offset}", $bindings);
        $total = (int) (DB::selectOne("SELECT COUNT(*) AS n FROM ({$body}) t", $bindings)->n ?? 0);
        $totals = null;
        if ($def['sums']) {
            $select = implode(', ', array_map(fn ($k) => "COALESCE(SUM(t.`{$k}`), 0) AS `{$k}`", $def['sums']));
            $totals = array_map(R::num(...), (array) DB::selectOne("SELECT {$select} FROM ({$body}) t", $bindings));
        }

        $result = ['columns' => $def['columns'], 'rows' => array_map(fn ($r) => R::castRow($def['columns'], (array) $r), $rows)];
        if ($totals !== null) {
            $result['totals'] = $totals;
        }
        $result += ['total' => $total, 'page' => $page->page, 'pageSize' => $page->pageSize, 'pages' => max(1, (int) ceil($total / $page->pageSize)), 'generatedAt' => R::iso(now())];

        return R::stripCost($result, $user->can('inventory.view_cost'));
    }

    /**
     * CSV of the requested page (same pagination bound — never an unbounded dump), Arabic headers, UTF-8 BOM.
     *
     * @return array{fileName:string, body:string}
     */
    public function csv(AuthUser $user, string $name, Paging $page, array $f): array
    {
        $result = $this->run($user, $name, $page, $f);

        return ['fileName' => $name.'-'.substr($result['generatedAt'], 0, 10).'.csv', 'body' => R::toCsv($result['columns'], $result['rows'])];
    }

    /** @return array{columns:array, body:string, order:string, sums:string[]} */
    private function build(string $name, ReportContext $c): array
    {
        return match ($name) {
            'inventory' => $this->inventory($c),
            'stock-movement' => $this->stockMovement($c),
            'inventory-aging' => $this->inventoryAging($c),
            'expiry' => $this->expiry($c),
            'receiving' => $this->receiving($c),
            'grn' => $this->grn($c),
            'supplier-performance' => $this->supplierPerformance($c),
            'purchase-orders' => $this->purchaseOrders($c),
            'picking-performance' => $this->pickingPerformance($c),
            'fulfillment' => $this->fulfillment($c),
            'delivery-performance' => $this->deliveryPerformance($c),
            'fleet-utilization' => $this->fleetUtilization($c),
            'trip-performance' => $this->tripPerformance($c),
            'returns' => $this->returns($c),
            'exceptions' => $this->exceptions($c),
        };
    }

    // ───────────── SQL fragments ─────────────
    /** Registers a bound value and returns its placeholder. */
    private function p(mixed $value): string
    {
        $this->bindings[] = $value instanceof Carbon ? R::db($value) : $value;

        return '?';
    }

    private function whereWh(string $column, ?string $id): string
    {
        return $id ? "AND {$column} = ".$this->p($id) : '';
    }

    /** `AND (a LIKE %q% OR b LIKE %q% …)` over the given column expressions (the collation is case-insensitive). */
    private function whereQ(?string $q, array $columns): string
    {
        return $q ? 'AND ('.implode(' OR ', array_map(fn ($col) => "{$col} LIKE ".$this->p(R::like($q)), $columns)).')' : '';
    }

    private function whereStatus(string $expression, ?string $status): string
    {
        return $status ? "AND {$expression} = ".$this->p($status) : '';
    }

    private function between(string $expression, ReportContext $c): string
    {
        return "{$expression} >= ".$this->p($c->from)." AND {$expression} <= ".$this->p($c->to);
    }

    private static function lit(Carbon $d): string
    {
        return "'".R::db($d)."'";
    }

    private static function storage(): string
    {
        return "'".implode("','", R::STORAGE_ZONES)."'";
    }

    private static function productCols(): array
    {
        return [R::col('sku', 'SKU', 'SKU'), R::col('productAr', 'المنتج', 'Product (AR)'), R::col('productEn', 'Product', 'Product (EN)')];
    }

    private static function supplierCols(): array
    {
        return [R::col('supplierAr', 'المورد', 'Supplier (AR)'), R::col('supplierEn', 'Supplier', 'Supplier (EN)')];
    }

    // ───────────── inventory ─────────────
    private function inventory(ReportContext $c): array
    {
        $today = self::lit($c->today);
        $available = 'b.quarantine = 0 AND b.blocked = 0 AND z.type NOT IN ('.self::NOT_AVAILABLE_ZONES.") AND (bt.expiry_date IS NULL OR bt.expiry_date >= {$today})";
        $status = "CASE WHEN b.quarantine = 1 OR z.type = 'quarantine' THEN 'quarantine' WHEN bt.expiry_date IS NOT NULL AND bt.expiry_date < {$today} THEN 'expired' WHEN b.blocked = 1 THEN 'blocked' WHEN z.type NOT IN (".self::storage().") THEN z.type ELSE 'available' END";

        return [
            'columns' => [...self::productCols(), R::col('warehouse', 'المستودع', 'Warehouse'), R::col('zone', 'المنطقة', 'Zone'), R::col('bin', 'الموقع', 'Bin'), R::col('batch', 'الدفعة', 'Batch'), R::col('expiry', 'الصلاحية', 'Expiry', 'date'),
                R::col('onHand', 'الرصيد', 'On hand', 'number'), R::col('reserved', 'محجوز', 'Reserved', 'number'), R::col('allocated', 'مخصص', 'Allocated', 'number'), R::col('available', 'متاح', 'Available', 'number'), R::col('value', 'القيمة (ر.س)', 'Value (SAR)', 'money'), R::col('status', 'الحالة', 'Status', 'status')],
            'body' => "SELECT p.sku AS `sku`, p.name_ar AS `productAr`, p.name_en AS `productEn`, w.code AS `warehouse`, z.code AS `zone`, bn.code AS `bin`, bt.batch_no AS `batch`, bt.expiry_date AS `expiry`,
                    b.on_hand AS `onHand`, b.reserved AS `reserved`, b.allocated AS `allocated`,
                    CASE WHEN {$available} THEN GREATEST(b.on_hand - b.reserved, 0) ELSE 0 END AS `available`,
                    (b.on_hand * COALESCE(p.purchase_price, 0)) AS `value`, {$status} AS `status`
                FROM inventory_balances b JOIN products p ON p.id = b.product_id JOIN warehouses w ON w.id = b.warehouse_id JOIN bins bn ON bn.id = b.bin_id JOIN zones z ON z.id = bn.zone_id LEFT JOIN batches bt ON bt.id = b.batch_id
                WHERE b.on_hand > 0 {$this->whereWh('b.warehouse_id', $c->whId)} {$this->whereQ($c->q, ['p.sku', 'p.name_ar', 'p.name_en', 'bn.code', 'bt.batch_no'])} {$this->whereStatus("({$status})", $c->status)}",
            'order' => 't.`sku`, t.`warehouse`, t.`bin`, t.`batch`', 'sums' => ['onHand', 'reserved', 'allocated', 'available', 'value'],
        ];
    }

    private function stockMovement(ReportContext $c): array
    {
        // Fragments are built in the order their placeholders appear in the SQL text.
        $range = $this->between('m.created_at', $c);
        $wh = $c->whId ? 'AND (m.src_warehouse_id = '.$this->p($c->whId).' OR m.dst_warehouse_id = '.$this->p($c->whId).')' : '';

        return [
            'columns' => [R::col('number', 'رقم الحركة', 'Movement #'), R::col('at', 'التاريخ', 'Date', 'datetime'), R::col('type', 'النوع', 'Type', 'status'), ...self::productCols(), R::col('batch', 'الدفعة', 'Batch'), R::col('qty', 'الكمية', 'Qty', 'number'),
                R::col('fromWarehouse', 'من مستودع', 'From WH'), R::col('fromBin', 'من موقع', 'From bin'), R::col('toWarehouse', 'إلى مستودع', 'To WH'), R::col('toBin', 'إلى موقع', 'To bin'), R::col('referenceType', 'نوع المرجع', 'Ref type'), R::col('referenceNumber', 'المرجع', 'Reference'), R::col('username', 'المستخدم', 'User'), R::col('note', 'ملاحظة', 'Note')],
            'body' => "SELECT m.number AS `number`, m.created_at AS `at`, m.type AS `type`, p.sku AS `sku`, p.name_ar AS `productAr`, p.name_en AS `productEn`, m.batch_no AS `batch`, m.qty AS `qty`,
                    sw.code AS `fromWarehouse`, sb.code AS `fromBin`, dw.code AS `toWarehouse`, db.code AS `toBin`, m.reference_type AS `referenceType`, m.reference_number AS `referenceNumber`, m.username AS `username`, m.note AS `note`
                FROM inventory_movements m JOIN products p ON p.id = m.product_id
                LEFT JOIN bins sb ON sb.id = m.src_bin_id LEFT JOIN warehouses sw ON sw.id = m.src_warehouse_id LEFT JOIN bins db ON db.id = m.dst_bin_id LEFT JOIN warehouses dw ON dw.id = m.dst_warehouse_id
                WHERE {$range} {$wh} {$this->whereStatus('m.type', $c->status)} {$this->whereQ($c->q, ['m.number', 'p.sku', 'p.name_ar', 'p.name_en', 'm.reference_number', 'm.batch_no'])}",
            'order' => 't.`at` DESC, t.`number` DESC', 'sums' => ['qty'],
        ];
    }

    private function inventoryAging(ReportContext $c): array
    {
        $bucket = fn (int $lo, ?int $hi = null) => "COALESCE(SUM(CASE WHEN b.age >= {$lo}".($hi !== null ? " AND b.age <= {$hi}" : '').' THEN b.on_hand ELSE 0 END), 0)';
        $now = self::lit($c->now);

        return [
            'columns' => [...self::productCols(), R::col('d0_30', '0–30 يوم', '0–30 days', 'number'), R::col('d31_60', '31–60 يوم', '31–60 days', 'number'), R::col('d61_90', '61–90 يوم', '61–90 days', 'number'), R::col('d90p', '+90 يوم', '90+ days', 'number'), R::col('total', 'الإجمالي', 'Total', 'number'), R::col('value', 'القيمة (ر.س)', 'Value (SAR)', 'money'), R::col('value90p', 'قيمة +90 يوم', 'Value 90+ (SAR)', 'money')],
            'body' => "SELECT p.sku AS `sku`, p.name_ar AS `productAr`, p.name_en AS `productEn`,
                    {$bucket(0, 30)} AS `d0_30`, {$bucket(31, 60)} AS `d31_60`, {$bucket(61, 90)} AS `d61_90`, {$bucket(91)} AS `d90p`,
                    SUM(b.on_hand) AS `total`, SUM(b.on_hand * COALESCE(p.purchase_price, 0)) AS `value`,
                    COALESCE(SUM(CASE WHEN b.age >= 91 THEN b.on_hand * COALESCE(p.purchase_price, 0) ELSE 0 END), 0) AS `value90p`
                FROM (SELECT b.product_id, b.on_hand, FLOOR(TIMESTAMPDIFF(SECOND, COALESCE(bt.created_at, b.updated_at), {$now}) / 86400) AS age
                      FROM inventory_balances b LEFT JOIN batches bt ON bt.id = b.batch_id WHERE b.on_hand > 0 {$this->whereWh('b.warehouse_id', $c->whId)}) b
                JOIN products p ON p.id = b.product_id WHERE 1 = 1 {$this->whereQ($c->q, ['p.sku', 'p.name_ar', 'p.name_en'])}
                GROUP BY p.id, p.sku, p.name_ar, p.name_en",
            'order' => 't.`d90p` DESC, t.`total` DESC, t.`sku`', 'sums' => ['d0_30', 'd31_60', 'd61_90', 'd90p', 'total', 'value', 'value90p'],
        ];
    }

    private function expiry(ReportContext $c): array
    {
        $days = 'DATEDIFF(bt.expiry_date, '.self::lit($c->today).')';
        $bucket = "CASE WHEN {$days} < 0 THEN 'expired' WHEN {$days} <= 7 THEN '0-7' WHEN {$days} <= 30 THEN '8-30' WHEN {$days} <= 90 THEN '31-90' ELSE '90+' END";

        return [
            'columns' => [...self::productCols(), R::col('batch', 'الدفعة', 'Batch'), R::col('expiry', 'تاريخ الانتهاء', 'Expiry', 'date'), R::col('daysToExpiry', 'أيام للانتهاء', 'Days to expiry', 'number'), R::col('bucket', 'الشريحة', 'Bucket', 'status'), R::col('qty', 'الكمية', 'Qty', 'number'), R::col('value', 'القيمة (ر.س)', 'Value (SAR)', 'money'), R::col('warehouses', 'المستودعات', 'Warehouses')],
            'body' => "SELECT p.sku AS `sku`, p.name_ar AS `productAr`, p.name_en AS `productEn`, bt.batch_no AS `batch`, bt.expiry_date AS `expiry`, {$days} AS `daysToExpiry`, {$bucket} AS `bucket`,
                    SUM(b.on_hand) AS `qty`, SUM(b.on_hand * COALESCE(p.purchase_price, 0)) AS `value`, GROUP_CONCAT(DISTINCT w.code ORDER BY w.code SEPARATOR ',') AS `warehouses`
                FROM batches bt JOIN products p ON p.id = bt.product_id JOIN inventory_balances b ON b.batch_id = bt.id JOIN warehouses w ON w.id = b.warehouse_id
                WHERE bt.expiry_date IS NOT NULL {$this->whereWh('b.warehouse_id', $c->whId)} {$this->whereQ($c->q, ['p.sku', 'p.name_ar', 'p.name_en', 'bt.batch_no'])} {$this->whereStatus("({$bucket})", $c->status)}
                GROUP BY bt.id, bt.batch_no, bt.expiry_date, p.sku, p.name_ar, p.name_en HAVING SUM(b.on_hand) > 0",
            'order' => 't.`expiry` ASC, t.`sku`', 'sums' => ['qty', 'value'],
        ];
    }

    // ───────────── inbound ─────────────
    private function receiving(ReportContext $c): array
    {
        return [
            'columns' => [R::col('number', 'رقم GRN', 'GRN #'), R::col('postedAt', 'تاريخ الاستلام', 'Posted at', 'datetime'), R::col('po', 'أمر الشراء', 'PO'), R::col('dueDate', 'موعد التسليم', 'PO due', 'date'), ...self::supplierCols(), R::col('warehouse', 'المستودع', 'Warehouse'), R::col('shipment', 'الشحنة', 'Shipment'),
                R::col('ordered', 'المطلوب', 'Ordered', 'number'), R::col('received', 'المستلم', 'Received', 'number'), R::col('accepted', 'المقبول', 'Accepted', 'number'), R::col('damaged', 'التالف', 'Damaged', 'number'), R::col('rejected', 'المرفوض', 'Rejected', 'number'), R::col('onTime', 'في الموعد', 'On time', 'bool'), R::col('daysLate', 'أيام التأخير', 'Days late', 'number'), R::col('postedBy', 'بواسطة', 'Posted by')],
            'body' => 'SELECT g.number AS `number`, g.posted_at AS `postedAt`, po.number AS `po`, po.due_date AS `dueDate`, s.name_ar AS `supplierAr`, s.name_en AS `supplierEn`, w.code AS `warehouse`, sh.number AS `shipment`,
                    COALESCE(a.ordered, 0) AS `ordered`, COALESCE(a.received, 0) AS `received`, COALESCE(a.accepted, 0) AS `accepted`, COALESCE(a.damaged, 0) AS `damaged`, COALESCE(a.rejected, 0) AS `rejected`,
                    CASE WHEN po.due_date IS NULL THEN NULL ELSE (DATE(g.posted_at) <= DATE(po.due_date)) END AS `onTime`,
                    CASE WHEN po.due_date IS NULL THEN NULL ELSE GREATEST(DATEDIFF(g.posted_at, po.due_date), 0) END AS `daysLate`, g.posted_by AS `postedBy`
                FROM goods_receipts g JOIN purchase_orders po ON po.id = g.po_id JOIN suppliers s ON s.id = g.supplier_id JOIN warehouses w ON w.id = g.warehouse_id JOIN inbound_shipments sh ON sh.id = g.shipment_id
                LEFT JOIN '.self::GRN_AGG." a ON a.grn_id = g.id
                WHERE {$this->between('g.posted_at', $c)} {$this->whereWh('g.warehouse_id', $c->whId)} {$this->whereQ($c->q, ['g.number', 'po.number', 's.name_ar', 's.name_en', 'sh.number'])}",
            'order' => 't.`postedAt` DESC, t.`number` DESC', 'sums' => ['ordered', 'received', 'accepted', 'damaged', 'rejected'],
        ];
    }

    private function grn(ReportContext $c): array
    {
        return [
            'columns' => [R::col('number', 'رقم GRN', 'GRN #'), R::col('postedAt', 'التاريخ', 'Posted at', 'datetime'), R::col('shipment', 'الشحنة', 'Shipment'), R::col('po', 'أمر الشراء', 'PO'), ...self::supplierCols(), R::col('warehouse', 'المستودع', 'Warehouse'),
                R::col('lines', 'الأسطر', 'Lines', 'number'), R::col('received', 'المستلم', 'Received', 'number'), R::col('accepted', 'المقبول', 'Accepted', 'number'), R::col('damaged', 'التالف', 'Damaged', 'number'), R::col('rejected', 'المرفوض', 'Rejected', 'number'), R::col('postedBy', 'بواسطة', 'Posted by'), R::col('summaryAr', 'الملخص', 'Summary')],
            'body' => 'SELECT g.number AS `number`, g.posted_at AS `postedAt`, sh.number AS `shipment`, po.number AS `po`, s.name_ar AS `supplierAr`, s.name_en AS `supplierEn`, w.code AS `warehouse`,
                    COALESCE(a.`lines`, 0) AS `lines`, COALESCE(a.received, 0) AS `received`, COALESCE(a.accepted, 0) AS `accepted`, COALESCE(a.damaged, 0) AS `damaged`, COALESCE(a.rejected, 0) AS `rejected`, g.posted_by AS `postedBy`, g.summary_ar AS `summaryAr`
                FROM goods_receipts g JOIN purchase_orders po ON po.id = g.po_id JOIN suppliers s ON s.id = g.supplier_id JOIN warehouses w ON w.id = g.warehouse_id JOIN inbound_shipments sh ON sh.id = g.shipment_id
                LEFT JOIN '.self::GRN_AGG." a ON a.grn_id = g.id
                WHERE {$this->between('g.posted_at', $c)} {$this->whereWh('g.warehouse_id', $c->whId)} {$this->whereQ($c->q, ['g.number', 'po.number', 's.name_ar', 's.name_en', 'sh.number', 'g.posted_by'])}",
            'order' => 't.`postedAt` DESC, t.`number` DESC', 'sums' => ['lines', 'received', 'accepted', 'damaged', 'rejected'],
        ];
    }

    // ───────────── procurement ─────────────
    private function supplierPerformance(ReportContext $c): array
    {
        $onTimeInFull = '(g.due_date IS NULL OR DATE(g.posted_at) <= DATE(g.due_date)) AND COALESCE(g.accepted, 0) >= COALESCE(g.ordered, 0)';

        return [
            'columns' => [R::col('code', 'الكود', 'Code'), ...self::supplierCols(), R::col('pos', 'أوامر الشراء', 'POs', 'number'), R::col('poValue', 'قيمة الأوامر (ر.س)', 'PO value (SAR)', 'money'), R::col('grns', 'الاستلامات', 'GRNs', 'number'), R::col('otifPct', 'OTIF %', 'OTIF %', 'pct'), R::col('fillPct', 'نسبة التعبئة %', 'Fill %', 'pct'), R::col('avgLeadDays', 'متوسط أيام التوريد', 'Avg lead days', 'number'), R::col('damagedPct', 'نسبة التالف %', 'Damaged %', 'pct'), R::col('score', 'التقييم', 'Score', 'number')],
            'body' => "WITH pos AS (SELECT x.* FROM purchase_orders x WHERE {$this->between('x.created_at', $c)} AND x.status NOT IN ('draft','cancelled') {$this->whereWh('x.warehouse_id', $c->whId)}),
                    g AS (SELECT gr.id, gr.supplier_id, gr.posted_at, pos.due_date, COALESCE(pos.sent_at, pos.approved_at, pos.created_at) AS sent_at, a.ordered, a.received, a.accepted, a.damaged
                          FROM goods_receipts gr JOIN pos ON pos.id = gr.po_id LEFT JOIN ".self::GRN_AGG." a ON a.grn_id = gr.id)
                SELECT s.code AS `code`, s.name_ar AS `supplierAr`, s.name_en AS `supplierEn`,
                    (SELECT COUNT(*) FROM pos WHERE pos.supplier_id = s.id) AS `pos`, (SELECT COALESCE(SUM(pos.total), 0) FROM pos WHERE pos.supplier_id = s.id) AS `poValue`,
                    COUNT(g.id) AS `grns`,
                    ROUND(100.0 * SUM(CASE WHEN g.id IS NOT NULL AND {$onTimeInFull} THEN 1 ELSE 0 END) / NULLIF(COUNT(g.id), 0), 1) AS `otifPct`,
                    ROUND(100.0 * SUM(g.accepted) / NULLIF(SUM(g.ordered), 0), 1) AS `fillPct`,
                    ROUND(AVG(TIMESTAMPDIFF(SECOND, g.sent_at, g.posted_at) / 86400), 1) AS `avgLeadDays`,
                    ROUND(100.0 * SUM(g.damaged) / NULLIF(SUM(g.received), 0), 1) AS `damagedPct`, s.score AS `score`
                FROM suppliers s LEFT JOIN g ON g.supplier_id = s.id
                WHERE s.active = 1 AND EXISTS (SELECT 1 FROM pos WHERE pos.supplier_id = s.id) {$this->whereQ($c->q, ['s.code', 's.name_ar', 's.name_en'])}
                GROUP BY s.id, s.code, s.name_ar, s.name_en, s.score",
            'order' => 't.`pos` DESC, t.`code`', 'sums' => ['pos', 'poValue', 'grns'],
        ];
    }

    private function purchaseOrders(ReportContext $c): array
    {
        return [
            'columns' => [R::col('number', 'رقم الأمر', 'PO #'), R::col('createdAt', 'التاريخ', 'Created', 'datetime'), ...self::supplierCols(), R::col('warehouse', 'المستودع', 'Warehouse'), R::col('status', 'الحالة', 'Status', 'status'), R::col('total', 'الإجمالي (ر.س)', 'Total (SAR)', 'money'), R::col('lines', 'الأسطر', 'Lines', 'number'), R::col('qty', 'الكمية', 'Qty', 'number'), R::col('receivedQty', 'المستلم', 'Received', 'number'), R::col('openQty', 'المتبقي', 'Open qty', 'number'), R::col('dueDate', 'موعد التسليم', 'Due', 'date'), R::col('createdBy', 'بواسطة', 'Created by')],
            'body' => "SELECT po.number AS `number`, po.created_at AS `createdAt`, s.name_ar AS `supplierAr`, s.name_en AS `supplierEn`, w.code AS `warehouse`, po.status AS `status`, po.total AS `total`,
                    (SELECT COUNT(*) FROM po_lines l WHERE l.po_id = po.id) AS `lines`, (SELECT COALESCE(SUM(l.qty), 0) FROM po_lines l WHERE l.po_id = po.id) AS `qty`, (SELECT COALESCE(SUM(l.received_qty), 0) FROM po_lines l WHERE l.po_id = po.id) AS `receivedQty`,
                    po.open_qty AS `openQty`, po.due_date AS `dueDate`, po.created_by AS `createdBy`
                FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id JOIN warehouses w ON w.id = po.warehouse_id
                WHERE {$this->between('po.created_at', $c)} {$this->whereWh('po.warehouse_id', $c->whId)} {$this->whereStatus('po.status', $c->status)} {$this->whereQ($c->q, ['po.number', 's.name_ar', 's.name_en', 'po.reference'])}",
            'order' => 't.`createdAt` DESC, t.`number` DESC', 'sums' => ['total', 'lines', 'qty', 'receivedQty', 'openQty'],
        ];
    }

    // ───────────── fulfillment ─────────────
    private function pickingPerformance(ReportContext $c): array
    {
        $short = "SUM(CASE WHEN pt.status = 'short' OR pt.picked_qty < pt.qty THEN 1 ELSE 0 END)";

        return [
            'columns' => [R::col('user', 'المستخدم', 'Picker'), R::col('day', 'اليوم', 'Day', 'date'), R::col('warehouse', 'المستودع', 'Warehouse'), R::col('pickLists', 'قوائم التجهيز', 'Pick lists', 'number'), R::col('lines', 'الأسطر', 'Lines', 'number'), R::col('plannedQty', 'المطلوب', 'Planned qty', 'number'), R::col('qty', 'المُجهز', 'Picked qty', 'number'), R::col('shortPicks', 'نقص تجهيز', 'Short picks', 'number'), R::col('shortPct', 'نسبة النقص %', 'Short %', 'pct')],
            'body' => "SELECT COALESCE(pt.picked_by, '—') AS `user`, DATE(pt.picked_at) AS `day`, w.code AS `warehouse`, COUNT(DISTINCT pt.pick_list_id) AS `pickLists`, COUNT(*) AS `lines`, SUM(pt.qty) AS `plannedQty`, SUM(pt.picked_qty) AS `qty`,
                    {$short} AS `shortPicks`, ROUND(100.0 * {$short} / NULLIF(COUNT(*), 0), 1) AS `shortPct`
                FROM pick_tasks pt JOIN pick_lists pl ON pl.id = pt.pick_list_id JOIN warehouses w ON w.id = pl.warehouse_id
                WHERE {$this->between('pt.picked_at', $c)} AND pt.status IN ('done','short','partial') {$this->whereWh('pl.warehouse_id', $c->whId)} {$this->whereQ($c->q, ['pt.picked_by'])}
                GROUP BY COALESCE(pt.picked_by, '—'), DATE(pt.picked_at), w.code",
            'order' => 't.`day` DESC, t.`qty` DESC, t.`user`', 'sums' => ['pickLists', 'lines', 'plannedQty', 'qty', 'shortPicks'],
        ];
    }

    private function fulfillment(ReportContext $c): array
    {
        return [
            'columns' => [R::col('warehouse', 'المستودع', 'Warehouse'), R::col('status', 'الحالة', 'Status', 'status'), R::col('orders', 'الطلبات', 'Orders', 'number'), R::col('cartons', 'الكراتين', 'Cartons', 'number'), R::col('kg', 'الوزن (كجم)', 'Weight (kg)', 'number'), R::col('packed', 'معبأ', 'Packed', 'number'), R::col('avgHoursAllocToPacked', 'متوسط ساعات التخصيص→التعبئة', 'Avg hours alloc→packed', 'number'), R::col('avgHoursPackedToDispatch', 'متوسط ساعات التعبئة→الشحن', 'Avg hours packed→dispatch', 'number')],
            'body' => "SELECT w.code AS `warehouse`, f.status AS `status`, COUNT(*) AS `orders`, SUM(f.cartons) AS `cartons`, ROUND(SUM(f.weight_kg), 1) AS `kg`, COUNT(f.packed_at) AS `packed`,
                    ROUND(AVG(TIMESTAMPDIFF(SECOND, f.created_at, f.packed_at) / 3600), 2) AS `avgHoursAllocToPacked`,
                    ROUND(AVG(TIMESTAMPDIFF(SECOND, f.packed_at, f.dispatched_at) / 3600), 2) AS `avgHoursPackedToDispatch`
                FROM fulfillment_orders f JOIN warehouses w ON w.id = f.warehouse_id
                WHERE {$this->between('f.created_at', $c)} {$this->whereWh('f.warehouse_id', $c->whId)} {$this->whereStatus('f.status', $c->status)}
                GROUP BY w.code, f.status",
            'order' => 't.`warehouse`, t.`status`', 'sums' => ['orders', 'cartons', 'kg', 'packed'],
        ];
    }

    // ───────────── delivery & fleet ─────────────
    private function deliveryPerformance(ReportContext $c): array
    {
        $n = fn (string $condition) => "SUM(CASE WHEN {$condition} THEN 1 ELSE 0 END)";
        $delivered = $n("d.result = 'delivered'");
        $partial = $n("d.result = 'partial'");
        $failed = $n("d.result IN ('failed','rejected')");

        return [
            'columns' => [R::col('day', 'اليوم', 'Day', 'date'), R::col('warehouse', 'المستودع', 'Warehouse'), R::col('trips', 'الرحلات', 'Trips', 'number'), R::col('stops', 'المحطات', 'Stops', 'number'), R::col('delivered', 'مسلّم', 'Delivered', 'number'), R::col('partial', 'جزئي', 'Partial', 'number'), R::col('failed', 'فشل', 'Failed', 'number'),
                R::col('deliveredPct', 'نسبة التسليم %', 'Delivered %', 'pct'), R::col('partialPct', 'نسبة الجزئي %', 'Partial %', 'pct'), R::col('failedPct', 'نسبة الفشل %', 'Failed %', 'pct'), R::col('avgStopsPerTrip', 'متوسط المحطات/رحلة', 'Avg stops/trip', 'number'), R::col('deliveredQty', 'كمية مسلّمة', 'Delivered qty', 'number'), R::col('returnedQty', 'كمية مرتجعة', 'Returned qty', 'number')],
            'body' => "SELECT DATE(d.completed_at) AS `day`, w.code AS `warehouse`, COUNT(DISTINCT d.trip_id) AS `trips`, COUNT(*) AS `stops`,
                    {$delivered} AS `delivered`, {$partial} AS `partial`, {$failed} AS `failed`,
                    ROUND(100.0 * {$delivered} / NULLIF(COUNT(*), 0), 1) AS `deliveredPct`, ROUND(100.0 * {$partial} / NULLIF(COUNT(*), 0), 1) AS `partialPct`, ROUND(100.0 * {$failed} / NULLIF(COUNT(*), 0), 1) AS `failedPct`,
                    ROUND(COUNT(*) / NULLIF(COUNT(DISTINCT d.trip_id), 0), 1) AS `avgStopsPerTrip`, SUM(d.delivered_qty) AS `deliveredQty`, SUM(d.returned_qty) AS `returnedQty`
                FROM delivery_records d JOIN trips tr ON tr.id = d.trip_id JOIN warehouses w ON w.id = tr.warehouse_id
                WHERE {$this->between('d.completed_at', $c)} {$this->whereWh('tr.warehouse_id', $c->whId)}
                GROUP BY DATE(d.completed_at), w.code",
            'order' => 't.`day` DESC, t.`warehouse`', 'sums' => ['trips', 'stops', 'delivered', 'partial', 'failed', 'deliveredQty', 'returnedQty'],
        ];
    }

    private function fleetUtilization(ReportContext $c): array
    {
        // The three sub-aggregates come first in the SQL text, so their bound values are registered first, in order.
        $trips = "(SELECT tr.vehicle_id, COUNT(*) AS trips, SUM(tr.km) AS km, SUM(tr.kg) AS kg FROM trips tr
            WHERE {$this->between('COALESCE(tr.date, tr.created_at)', $c)} AND tr.status NOT IN ('draft','cancelled') {$this->whereWh('tr.warehouse_id', $c->whId)} GROUP BY tr.vehicle_id)";
        $maintenance = "(SELECT mo.vehicle_id, SUM(COALESCE(mo.down_days, GREATEST(DATEDIFF(mo.end_date, mo.start_date), 0), 0)) AS days, SUM(mo.cost) AS cost FROM maintenance_orders mo
            WHERE {$this->between('COALESCE(mo.start_date, mo.created_at)', $c)} GROUP BY mo.vehicle_id)";
        $fuel = "(SELECT fr.vehicle_id, SUM(fr.liters) AS liters, SUM(fr.cost) AS cost FROM fuel_records fr WHERE {$this->between('fr.date', $c)} GROUP BY fr.vehicle_id)";
        $wh = $c->whId ? 'AND (v.warehouse_id = '.$this->p($c->whId).' OR tt.vehicle_id IS NOT NULL)' : '';

        return [
            'columns' => [R::col('code', 'المركبة', 'Vehicle'), R::col('plate', 'اللوحة', 'Plate'), R::col('kind', 'النوع', 'Kind'), R::col('state', 'الحالة', 'State', 'status'), R::col('trips', 'الرحلات', 'Trips', 'number'), R::col('km', 'كم', 'Km', 'number'), R::col('kgLoaded', 'الحمولة (كجم)', 'Loaded (kg)', 'number'), R::col('capacityKg', 'السعة (كجم)', 'Capacity (kg)', 'number'), R::col('loadPct', 'نسبة الاستغلال %', 'Load %', 'pct'),
                R::col('maintenanceDays', 'أيام الصيانة', 'Maintenance days', 'number'), R::col('maintenanceCost', 'تكلفة الصيانة (ر.س)', 'Maintenance cost (SAR)', 'money'), R::col('liters', 'الوقود (لتر)', 'Fuel (L)', 'number'), R::col('fuelCost', 'تكلفة الوقود (ر.س)', 'Fuel cost (SAR)', 'money'), R::col('kmPerL', 'كم/لتر', 'Km/L', 'number')],
            'body' => "SELECT v.code AS `code`, v.plate_ar AS `plate`, v.kind AS `kind`, v.state AS `state`, COALESCE(tt.trips, 0) AS `trips`, COALESCE(tt.km, 0) AS `km`, COALESCE(tt.kg, 0) AS `kgLoaded`, (v.max_kg * COALESCE(tt.trips, 0)) AS `capacityKg`,
                    ROUND(100.0 * COALESCE(tt.kg, 0) / NULLIF(v.max_kg * COALESCE(tt.trips, 0), 0), 1) AS `loadPct`,
                    COALESCE(m.days, 0) AS `maintenanceDays`, COALESCE(m.cost, 0) AS `maintenanceCost`, COALESCE(f.liters, 0) AS `liters`, COALESCE(f.cost, 0) AS `fuelCost`,
                    ROUND(COALESCE(tt.km, 0) / NULLIF(f.liters, 0), 2) AS `kmPerL`
                FROM vehicles v LEFT JOIN {$trips} tt ON tt.vehicle_id = v.id LEFT JOIN {$maintenance} m ON m.vehicle_id = v.id LEFT JOIN {$fuel} f ON f.vehicle_id = v.id
                WHERE v.active = 1 {$wh} {$this->whereQ($c->q, ['v.code', 'v.plate_ar', 'v.plate_en'])}",
            'order' => 't.`trips` DESC, t.`code`', 'sums' => ['trips', 'km', 'kgLoaded', 'capacityKg', 'maintenanceDays', 'maintenanceCost', 'liters', 'fuelCost'],
        ];
    }

    private function tripPerformance(ReportContext $c): array
    {
        $stops = fn (string $condition = '') => "(SELECT COUNT(*) FROM trip_stops s WHERE s.trip_id = tr.id {$condition})";
        $cost = 'COALESCE(c.fuel + c.`driver` + c.ot + c.maint + c.dep + c.tolls + c.parking + c.`third` + c.`other`, 0)';

        return [
            'columns' => [R::col('number', 'الرحلة', 'Trip #'), R::col('date', 'التاريخ', 'Date', 'date'), R::col('warehouse', 'المستودع', 'Warehouse'), R::col('status', 'الحالة', 'Status', 'status'), R::col('vehicle', 'المركبة', 'Vehicle'), R::col('driver', 'السائق', 'Driver'), R::col('route', 'المسار', 'Route'),
                R::col('plannedStart', 'الانطلاق المخطط', 'Planned start'), R::col('actualStart', 'الانطلاق الفعلي', 'Actual start', 'datetime'), R::col('actualEnd', 'العودة الفعلية', 'Actual end', 'datetime'), R::col('delayMin', 'التأخير (د)', 'Delay (min)', 'number'), R::col('stops', 'المحطات', 'Stops', 'number'), R::col('delivered', 'مسلّم', 'Delivered', 'number'), R::col('failed', 'فشل', 'Failed', 'number'),
                R::col('km', 'كم', 'Km', 'number'), R::col('kg', 'الحمولة (كجم)', 'Load (kg)', 'number'), R::col('costTotal', 'إجمالي التكلفة (ر.س)', 'Total cost (SAR)', 'money'), R::col('costPerKm', 'التكلفة/كم (ر.س)', 'Cost/km (SAR)', 'money')],
            'body' => "SELECT tr.number AS `number`, COALESCE(tr.date, tr.created_at) AS `date`, w.code AS `warehouse`, tr.status AS `status`, v.code AS `vehicle`, d.name_ar AS `driver`, tr.route_ar AS `route`,
                    tr.planned_start AS `plannedStart`, tr.actual_start AS `actualStart`, tr.actual_end AS `actualEnd`, tr.delay_min AS `delayMin`,
                    {$stops()} AS `stops`, {$stops("AND s.status = 'delivered'")} AS `delivered`, {$stops("AND s.status IN ('failed','rejected')")} AS `failed`,
                    tr.km AS `km`, tr.kg AS `kg`, {$cost} AS `costTotal`, ROUND({$cost} / NULLIF(tr.km, 0), 2) AS `costPerKm`
                FROM trips tr JOIN warehouses w ON w.id = tr.warehouse_id LEFT JOIN vehicles v ON v.id = tr.vehicle_id LEFT JOIN drivers d ON d.id = tr.driver_id LEFT JOIN trip_costs c ON c.trip_id = tr.id
                WHERE {$this->between('COALESCE(tr.date, tr.created_at)', $c)} {$this->whereWh('tr.warehouse_id', $c->whId)} {$this->whereStatus('tr.status', $c->status)} {$this->whereQ($c->q, ['tr.number', 'tr.route_ar', 'v.code', 'd.name_ar'])}",
            'order' => 't.`date` DESC, t.`number` DESC', 'sums' => ['delayMin', 'stops', 'delivered', 'failed', 'km', 'kg', 'costTotal'],
        ];
    }

    // ───────────── returns & exceptions ─────────────
    private function returns(ReportContext $c): array
    {
        return [
            'columns' => [R::col('type', 'النوع', 'Type', 'status'), R::col('reason', 'السبب', 'Reason'), R::col('reasonAr', 'وصف السبب', 'Reason (AR)'), R::col('decision', 'القرار', 'Decision', 'status'), R::col('returns', 'عدد المرتجعات', 'Returns', 'number'), R::col('qty', 'الكمية', 'Qty', 'number'), R::col('open', 'مفتوح', 'Open', 'number'), R::col('closed', 'مغلق', 'Closed', 'number')],
            'body' => "SELECT r.type AS `type`, COALESCE(r.reason_code, '—') AS `reason`, MAX(r.reason_ar) AS `reasonAr`, COALESCE(r.decision, '—') AS `decision`, COUNT(DISTINCT r.id) AS `returns`, COALESCE(SUM(l.qty), 0) AS `qty`,
                    COUNT(DISTINCT CASE WHEN r.status NOT IN ('closed','rejected') THEN r.id END) AS `open`, COUNT(DISTINCT CASE WHEN r.status IN ('closed','rejected') THEN r.id END) AS `closed`
                FROM returns r LEFT JOIN return_lines l ON l.return_id = r.id
                WHERE {$this->between('r.created_at', $c)} {$this->whereWh('r.warehouse_id', $c->whId)} {$this->whereStatus('r.status', $c->status)} {$this->whereQ($c->q, ['r.type', 'r.reason_code', 'r.reason_ar', 'r.decision'])}
                GROUP BY r.type, r.reason_code, r.decision",
            'order' => 't.`returns` DESC, t.`type`, t.`reason`, t.`decision`', 'sums' => ['returns', 'qty', 'open', 'closed'],
        ];
    }

    private function exceptions(ReportContext $c): array
    {
        $now = self::lit($c->now);
        $breached = "SUM(CASE WHEN COALESCE(e.resolved_at, {$now}) > TIMESTAMPADD(SECOND, ROUND(e.sla_hours * 3600), e.created_at) THEN 1 ELSE 0 END)";

        return [
            'columns' => [R::col('kind', 'النوع', 'Kind', 'status'), R::col('severity', 'الخطورة', 'Severity', 'status'), R::col('owner', 'المسؤول', 'Owner'), R::col('total', 'الإجمالي', 'Total', 'number'), R::col('open', 'مفتوح', 'Open', 'number'), R::col('resolved', 'مُغلق', 'Resolved', 'number'), R::col('avgResolutionHours', 'متوسط ساعات الحل', 'Avg resolution (h)', 'number'), R::col('slaBreaches', 'خروقات SLA', 'SLA breaches', 'number'), R::col('slaBreachPct', 'نسبة خرق SLA %', 'SLA breach %', 'pct')],
            'body' => "SELECT e.kind AS `kind`, e.severity AS `severity`, COALESCE(e.owner_role, '—') AS `owner`, COUNT(*) AS `total`,
                    SUM(CASE WHEN e.status <> 'resolved' THEN 1 ELSE 0 END) AS `open`, SUM(CASE WHEN e.status = 'resolved' THEN 1 ELSE 0 END) AS `resolved`,
                    ROUND(AVG(TIMESTAMPDIFF(SECOND, e.created_at, e.resolved_at) / 3600), 1) AS `avgResolutionHours`,
                    {$breached} AS `slaBreaches`, ROUND(100.0 * {$breached} / NULLIF(COUNT(*), 0), 1) AS `slaBreachPct`
                FROM exceptions e
                WHERE {$this->between('e.created_at', $c)} {$this->whereStatus('e.status', $c->status)} {$this->whereQ($c->q, ['e.kind', 'e.owner_role', 'e.text_ar'])}
                GROUP BY e.kind, e.severity, e.owner_role",
            'order' => 't.`total` DESC, t.`kind`, t.`severity`, t.`owner`', 'sums' => ['total', 'open', 'resolved', 'slaBreaches'],
        ];
    }
}
