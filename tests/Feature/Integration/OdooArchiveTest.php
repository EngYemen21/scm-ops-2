<?php

namespace Tests\Feature\Integration;

use App\Integration\Odoo\Archive\ArchiveIndexer;
use App\Integration\Odoo\Archive\ArchiveKinds;
use App\Integration\Odoo\Archive\ArchiveWriter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\ApiTestCase;

/**
 * «أرشيف أودو» (docs/odoo/WAVE2.md §3) on a small copy of Odoo written straight into `odoo_models` / `odoo_records`
 * (no Odoo anywhere): the index and its build, the catalog, the lists with every filter and sort of two kinds, the
 * page of a document (lines, related documents, history, attachments, all fields), the partner and product pages,
 * the search, the CSV, the rule of people's files, and that the index follows the copy.
 */
class OdooArchiveTest extends ApiTestCase
{
    private const API = '/api/odoo-archive';

    /** @var array<int, array> */
    private array $records = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['odoo_docs', 'odoo_docs_state', 'odoo_files', 'odoo_runs', 'odoo_changes', 'odoo_records', 'odoo_models'] as $table) {
            DB::table($table)->delete();
        }
        Cache::flush();
        $this->mirror();
    }

    // ───────────── the small copy of Odoo ─────────────

    private function model(string $model, string $label, array $fields): void
    {
        DB::table('odoo_models')->insert(['model' => $model, 'label' => $label, 'status' => 'active', 'has_active' => ($fields['active'] ?? null) === 'boolean',
            'fields' => json_encode($fields + ['create_date' => 'datetime', 'write_date' => 'datetime', 'display_name' => 'char']), 'full_state' => 'done', 'mirrored_count' => 1,
            'created_at' => now(), 'updated_at' => now()]);
    }

    private function rec(string $model, int $id, array $data, ?string $display = null): void
    {
        ksort($data);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $this->records[] = ['model' => $model, 'odoo_id' => $id, 'display_name' => $display ?? (is_string($data['name'] ?? null) ? $data['name'] : null), 'write_date' => '2026-04-20 08:00:00',
            'active' => array_key_exists('active', $data) ? $data['active'] : null, 'data' => $json, 'hash' => sha1($json), 'version' => 1,
            'first_seen_at' => now()->format('Y-m-d H:i:s.v'), 'changed_at' => now()->format('Y-m-d H:i:s.v'), 'deleted_at' => null];
    }

    /** What the mirror does when Odoo changed a record: new values, a new version, a new `changed_at`. */
    private function change(string $model, int $id, array $values, bool $deleted = false): void
    {
        $row = DB::table('odoo_records')->where('model', $model)->where('odoo_id', $id)->first();
        $data = array_merge(json_decode($row->data, true), $values);
        ksort($data);
        $at = now()->addSeconds(5)->format('Y-m-d H:i:s.v');
        DB::table('odoo_records')->where('id', $row->id)->update(['data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'version' => $row->version + 1, 'changed_at' => $at, 'deleted_at' => $deleted ? $at : null]);
    }

    private function mirror(): void
    {
        $sar = [151, 'SAR'];
        $co = [1, 'شركة الاختبار'];
        $sara = [7, 'Sara Sales'];
        $reef = [10, 'مطعم الريف'];
        $flour = [200, '[P0001] طحين فاخر Flour 10kg'];
        $oil = [201, '[P0002] زيت Oil 1L'];

        $this->model('res.company', 'Companies', ['name' => 'char', 'currency_id' => 'many2one', 'partner_id' => 'many2one']);
        $this->rec('res.company', 1, ['name' => 'شركة الاختبار', 'currency_id' => $sar]);

        $this->model('res.partner', 'Contact', ['name' => 'char', 'vat' => 'char', 'email' => 'char', 'phone' => 'char', 'city' => 'char', 'ref' => 'char', 'customer_rank' => 'integer',
            'supplier_rank' => 'integer', 'parent_id' => 'many2one', 'user_id' => 'many2one', 'active' => 'boolean', 'is_company' => 'boolean']);
        $this->rec('res.partner', 10, ['name' => 'مطعم الريف', 'vat' => '300111222', 'email' => 'reef@example.test', 'city' => 'الرياض', 'customer_rank' => 3, 'supplier_rank' => 0, 'is_company' => true, 'active' => true, 'user_id' => $sara]);
        $this->rec('res.partner', 11, ['name' => 'Blue Hotel', 'city' => 'Jeddah', 'customer_rank' => 1, 'supplier_rank' => 0, 'is_company' => true, 'active' => true]);
        $this->rec('res.partner', 12, ['name' => 'مورد الخضار', 'customer_rank' => 0, 'supplier_rank' => 5, 'is_company' => true, 'active' => true]);
        $this->rec('res.partner', 13, ['name' => 'شركة النخبة', 'customer_rank' => 1, 'supplier_rank' => 1, 'is_company' => true, 'active' => true]);
        $this->rec('res.partner', 14, ['name' => 'فرع الريف - العليا', 'parent_id' => $reef, 'customer_rank' => 0, 'supplier_rank' => 0, 'is_company' => false, 'active' => true]);
        $this->rec('res.partner', 15, ['name' => 'Archived Co', 'customer_rank' => 1, 'supplier_rank' => 0, 'is_company' => true, 'active' => false]);

        $this->model('res.users', 'User', ['login' => 'char', 'share' => 'boolean', 'active' => 'boolean', 'partner_id' => 'many2one']);
        $this->rec('res.users', 7, ['login' => 'sara@example.test', 'share' => false, 'active' => true], 'Sara Sales');

        $this->model('product.category', 'Product Category', ['name' => 'char', 'complete_name' => 'char', 'parent_id' => 'many2one']);
        $this->rec('product.category', 5, ['name' => 'Food', 'complete_name' => 'All / Food']);
        $this->model('product.template', 'Product', ['name' => 'char', 'default_code' => 'char', 'list_price' => 'float', 'detailed_type' => 'selection', 'categ_id' => 'many2one', 'uom_id' => 'many2one',
            'tracking' => 'selection', 'active' => 'boolean']);
        $this->rec('product.template', 100, ['name' => 'طحين فاخر Flour 10kg', 'default_code' => 'P0001', 'list_price' => 50.0, 'detailed_type' => 'product', 'categ_id' => [5, 'All / Food'], 'uom_id' => [1, 'Units'], 'tracking' => 'lot', 'active' => true]);
        $this->rec('product.template', 101, ['name' => 'زيت Oil 1L', 'default_code' => 'P0002', 'list_price' => 12.0, 'detailed_type' => 'product', 'categ_id' => [5, 'All / Food'], 'uom_id' => [1, 'Units'], 'tracking' => 'none', 'active' => true]);
        $this->model('product.product', 'Product Variant', ['default_code' => 'char', 'barcode' => 'char', 'product_tmpl_id' => 'many2one', 'standard_price' => 'float', 'active' => 'boolean']);
        $this->rec('product.product', 200, ['default_code' => 'P0001', 'barcode' => '6281000000017', 'product_tmpl_id' => [100, '[P0001] طحين فاخر Flour 10kg'], 'standard_price' => 31.5, 'active' => true], $flour[1]);
        $this->rec('product.product', 201, ['default_code' => 'P0002', 'product_tmpl_id' => [101, '[P0002] زيت Oil 1L'], 'standard_price' => 8.0, 'active' => true], $oil[1]);

        // sales
        $this->model('sale.order', 'Sales Order', ['name' => 'char', 'state' => 'selection', 'partner_id' => 'many2one', 'partner_shipping_id' => 'many2one', 'date_order' => 'datetime', 'validity_date' => 'date',
            'amount_total' => 'monetary', 'amount_untaxed' => 'monetary', 'amount_tax' => 'monetary', 'currency_id' => 'many2one', 'user_id' => 'many2one', 'invoice_status' => 'selection',
            'delivery_status' => 'selection', 'client_order_ref' => 'char', 'origin' => 'char', 'note' => 'html', 'warehouse_id' => 'many2one']);
        $order = fn (int $id, string $state, array $partner, string $at, float $total, string $invoicing, array $more = []) => $this->rec('sale.order', $id, $more + ['name' => sprintf('S%05d', $id), 'state' => $state,
            'partner_id' => $partner, 'date_order' => $at, 'amount_total' => $total, 'amount_untaxed' => round($total / 1.15, 2), 'amount_tax' => round($total - $total / 1.15, 2), 'currency_id' => $sar,
            'invoice_status' => $invoicing, 'create_date' => '2026-01-10 08:00:00']);
        $order(1, 'sale', $reef, '2026-03-05 21:30:00', 1150.0, 'invoiced', ['delivery_status' => 'full', 'user_id' => $sara, 'client_order_ref' => 'PO-REEF-77', 'partner_shipping_id' => [14, 'فرع الريف - العليا'],
            'warehouse_id' => [1, 'Riyadh warehouse'], 'validity_date' => '2026-04-04', 'note' => '<p>التسليم <b>صباحًا</b></p>']);
        $order(2, 'sale', $reef, '2026-03-10 09:00:00', 230.0, 'to invoice', ['delivery_status' => 'partial']);
        $order(3, 'draft', [11, 'Blue Hotel'], '2026-04-01 10:00:00', 575.0, 'no');
        $order(4, 'cancel', [13, 'شركة النخبة'], '2026-02-01 08:00:00', 99.5, 'no');
        $order(5, 'sale', [14, 'فرع الريف - العليا'], '2026-04-15 12:00:00', 345.0, 'invoiced', ['delivery_status' => 'full', 'user_id' => $sara]);
        $this->model('sale.order.line', 'Sales Order Line', ['order_id' => 'many2one', 'name' => 'text', 'product_id' => 'many2one', 'product_uom_qty' => 'float', 'qty_delivered' => 'float', 'qty_invoiced' => 'float',
            'price_unit' => 'float', 'discount' => 'float', 'price_subtotal' => 'monetary', 'price_total' => 'monetary', 'product_uom' => 'many2one', 'sequence' => 'integer', 'state' => 'selection',
            'order_partner_id' => 'many2one', 'salesman_id' => 'many2one', 'currency_id' => 'many2one', 'invoice_status' => 'selection']);
        $line = fn (int $id, int $order, array $partner, array $product, float $qty, float $price, int $sequence, string $state = 'sale') => $this->rec('sale.order.line', $id, ['order_id' => [$order, sprintf('S%05d', $order)],
            'name' => $product[1], 'product_id' => $product, 'product_uom_qty' => $qty, 'qty_delivered' => $qty, 'qty_invoiced' => $qty, 'price_unit' => $price, 'discount' => 0.0, 'price_subtotal' => $qty * $price,
            'price_total' => round($qty * $price * 1.15, 2), 'product_uom' => [1, 'Units'], 'sequence' => $sequence, 'state' => $state, 'order_partner_id' => $partner, 'currency_id' => $sar, 'invoice_status' => 'invoiced'],
            sprintf('S%05d - %s', $order, $product[1]));
        $line(1001, 1, $reef, $flour, 16, 50, 20);
        $line(1002, 1, $reef, $oil, 20, 10, 10);
        $line(1003, 2, $reef, $flour, 4, 50, 10);
        $line(1004, 3, [11, 'Blue Hotel'], $oil, 50, 10, 10, 'draft');
        $line(1005, 5, [14, 'فرع الريف - العليا'], $flour, 6, 50, 10);

        // inventory
        $this->model('stock.warehouse', 'Warehouse', ['name' => 'char', 'code' => 'char', 'active' => 'boolean']);
        $this->rec('stock.warehouse', 1, ['name' => 'Riyadh warehouse', 'code' => 'WH', 'active' => true]);
        $this->rec('stock.warehouse', 2, ['name' => 'Jeddah warehouse', 'code' => 'JED', 'active' => true]);
        $this->model('stock.location', 'Inventory Locations', ['name' => 'char', 'complete_name' => 'char', 'usage' => 'selection', 'warehouse_id' => 'many2one', 'active' => 'boolean']);
        $this->rec('stock.location', 8, ['name' => 'Stock', 'complete_name' => 'WH/Stock', 'usage' => 'internal', 'warehouse_id' => [1, 'Riyadh warehouse'], 'active' => true]);
        $this->rec('stock.location', 18, ['name' => 'Stock', 'complete_name' => 'JED/Stock', 'usage' => 'internal', 'warehouse_id' => [2, 'Jeddah warehouse'], 'active' => true]);
        $this->rec('stock.location', 5, ['name' => 'Customers', 'complete_name' => 'Partners/Customers', 'usage' => 'customer', 'active' => true]);
        $this->model('stock.picking.type', 'Picking Type', ['name' => 'char', 'code' => 'selection', 'sequence_code' => 'char', 'warehouse_id' => 'many2one', 'active' => 'boolean']);
        $this->rec('stock.picking.type', 1, ['name' => 'Receipts', 'code' => 'incoming', 'sequence_code' => 'IN', 'warehouse_id' => [1, 'Riyadh warehouse'], 'active' => true]);
        $this->rec('stock.picking.type', 2, ['name' => 'Delivery Orders', 'code' => 'outgoing', 'sequence_code' => 'OUT', 'warehouse_id' => [1, 'Riyadh warehouse'], 'active' => true]);
        $this->model('stock.picking', 'Transfer', ['name' => 'char', 'state' => 'selection', 'origin' => 'char', 'partner_id' => 'many2one', 'sale_id' => 'many2one', 'backorder_id' => 'many2one',
            'picking_type_id' => 'many2one', 'scheduled_date' => 'datetime', 'date_done' => 'datetime', 'location_id' => 'many2one', 'location_dest_id' => 'many2one']);
        $this->rec('stock.picking', 500, ['name' => 'WH/OUT/00001', 'state' => 'done', 'origin' => 'S00001', 'partner_id' => $reef, 'sale_id' => [1, 'S00001'], 'picking_type_id' => [2, 'Riyadh warehouse: Delivery Orders'],
            'scheduled_date' => '2026-03-06 06:00:00', 'date_done' => '2026-03-06 10:00:00', 'location_id' => [8, 'WH/Stock'], 'location_dest_id' => [5, 'Partners/Customers']]);
        $this->rec('stock.picking', 501, ['name' => 'WH/OUT/00002', 'state' => 'assigned', 'origin' => 'S00002', 'partner_id' => $reef, 'sale_id' => [2, 'S00002'], 'picking_type_id' => [2, 'Riyadh warehouse: Delivery Orders'],
            'scheduled_date' => '2026-03-11 06:00:00', 'location_id' => [8, 'WH/Stock'], 'location_dest_id' => [5, 'Partners/Customers']]);
        $this->rec('stock.picking', 502, ['name' => 'WH/IN/00001', 'state' => 'done', 'origin' => 'P00001', 'partner_id' => [12, 'مورد الخضار'], 'picking_type_id' => [1, 'Riyadh warehouse: Receipts'],
            'scheduled_date' => '2026-02-20 06:00:00', 'date_done' => '2026-02-20 09:00:00', 'location_dest_id' => [8, 'WH/Stock']]);
        $this->model('stock.move', 'Stock Move', ['reference' => 'char', 'origin' => 'char', 'picking_id' => 'many2one', 'product_id' => 'many2one', 'location_id' => 'many2one', 'location_dest_id' => 'many2one',
            'quantity' => 'float', 'product_uom_qty' => 'float', 'product_uom' => 'many2one', 'date' => 'datetime', 'state' => 'selection', 'sequence' => 'integer', 'picking_type_id' => 'many2one']);
        $this->rec('stock.move', 600, ['reference' => 'WH/OUT/00001', 'origin' => 'S00001', 'picking_id' => [500, 'WH/OUT/00001'], 'product_id' => $flour, 'location_id' => [8, 'WH/Stock'], 'location_dest_id' => [5, 'Partners/Customers'],
            'quantity' => 16.0, 'product_uom_qty' => 16.0, 'product_uom' => [1, 'Units'], 'date' => '2026-03-06 10:00:00', 'state' => 'done', 'sequence' => 10, 'picking_type_id' => [2, 'Riyadh warehouse: Delivery Orders']], 'S00001/P0001');
        $this->rec('stock.move', 601, ['reference' => 'WH/OUT/00001', 'origin' => 'S00001', 'picking_id' => [500, 'WH/OUT/00001'], 'product_id' => $oil, 'location_id' => [8, 'WH/Stock'], 'location_dest_id' => [5, 'Partners/Customers'],
            'quantity' => 20.0, 'product_uom_qty' => 20.0, 'product_uom' => [1, 'Units'], 'date' => '2026-03-06 10:00:00', 'state' => 'done', 'sequence' => 20, 'picking_type_id' => [2, 'Riyadh warehouse: Delivery Orders']], 'S00001/P0002');
        $this->model('stock.quant', 'Quants', ['product_id' => 'many2one', 'location_id' => 'many2one', 'lot_id' => 'many2one', 'quantity' => 'float', 'reserved_quantity' => 'float', 'in_date' => 'datetime']);
        $this->rec('stock.quant', 700, ['product_id' => $flour, 'location_id' => [8, 'WH/Stock'], 'lot_id' => [1, 'L-1'], 'quantity' => 30.0, 'reserved_quantity' => 5.0, 'in_date' => '2026-02-20 09:00:00'], 'WH/Stock - L-1');
        $this->rec('stock.quant', 701, ['product_id' => $flour, 'location_id' => [18, 'JED/Stock'], 'quantity' => 7.0, 'reserved_quantity' => 0.0, 'in_date' => '2026-02-21 09:00:00'], 'JED/Stock');
        $this->rec('stock.quant', 702, ['product_id' => $flour, 'location_id' => [5, 'Partners/Customers'], 'quantity' => -40.0, 'reserved_quantity' => 0.0, 'in_date' => '2026-03-06 10:00:00'], 'Partners/Customers');
        $this->rec('stock.quant', 703, ['product_id' => $oil, 'location_id' => [8, 'WH/Stock'], 'quantity' => 12.0, 'reserved_quantity' => 0.0, 'in_date' => '2026-02-20 09:00:00'], 'WH/Stock');

        // accounting
        $this->model('account.journal', 'Journal', ['name' => 'char', 'code' => 'char', 'type' => 'selection', 'active' => 'boolean']);
        $this->rec('account.journal', 1, ['name' => 'Customer Invoices', 'code' => 'INV', 'type' => 'sale', 'active' => true]);
        $this->rec('account.journal', 3, ['name' => 'Bank', 'code' => 'BNK1', 'type' => 'bank', 'active' => true]);
        $this->model('account.account', 'Account', ['code' => 'char', 'name' => 'char', 'account_type' => 'selection', 'reconcile' => 'boolean']);
        $this->rec('account.account', 40, ['code' => '110200', 'name' => 'Receivables', 'account_type' => 'asset_receivable', 'reconcile' => true], '110200 Receivables');
        $this->rec('account.account', 41, ['code' => '410000', 'name' => 'Sales', 'account_type' => 'income', 'reconcile' => false], '410000 Sales');
        $this->model('account.move', 'Journal Entry', ['name' => 'char', 'move_type' => 'selection', 'state' => 'selection', 'payment_state' => 'selection', 'partner_id' => 'many2one', 'invoice_date' => 'date', 'date' => 'date',
            'invoice_date_due' => 'date', 'amount_total' => 'monetary', 'amount_untaxed' => 'monetary', 'amount_total_signed' => 'monetary', 'amount_untaxed_signed' => 'monetary', 'amount_residual' => 'monetary',
            'amount_residual_signed' => 'monetary', 'currency_id' => 'many2one', 'company_id' => 'many2one', 'invoice_origin' => 'char', 'ref' => 'char', 'invoice_user_id' => 'many2one', 'journal_id' => 'many2one',
            'reversed_entry_id' => 'many2one', 'payment_id' => 'many2one', 'sale_order_ids' => 'many2many']);
        $move = fn (int $id, string $name, string $type, string $state, ?array $partner, string $date, float $signed, float $residual, array $more = []) => $this->rec('account.move', $id, $more + array_filter(['name' => $name,
            'move_type' => $type, 'state' => $state, 'partner_id' => $partner, 'date' => $date, 'amount_total' => abs($signed), 'amount_total_signed' => $signed, 'amount_untaxed_signed' => round($signed / 1.15, 2),
            'amount_residual' => abs($residual), 'amount_residual_signed' => $residual, 'currency_id' => $sar, 'company_id' => $co], fn ($v) => $v !== null));
        $move(900, 'INV/2026/00001', 'out_invoice', 'posted', $reef, '2026-03-06', 1150.0, 150.0, ['invoice_date' => '2026-03-06', 'invoice_date_due' => '2026-04-05', 'payment_state' => 'partial', 'invoice_origin' => 'S00001',
            'sale_order_ids' => [1], 'journal_id' => [1, 'Customer Invoices'], 'invoice_user_id' => $sara]);
        $move(901, 'INV/2026/00002', 'out_invoice', 'posted', $reef, '2026-03-11', 230.0, 230.0, ['invoice_date' => '2026-03-11', 'payment_state' => 'not_paid', 'invoice_origin' => 'S00002', 'sale_order_ids' => [2], 'journal_id' => [1, 'Customer Invoices']]);
        $move(902, 'RINV/2026/00001', 'out_refund', 'posted', $reef, '2026-03-12', -115.0, -115.0, ['invoice_date' => '2026-03-12', 'payment_state' => 'not_paid', 'invoice_origin' => 'S00001', 'reversed_entry_id' => [900, 'INV/2026/00001'],
            'journal_id' => [1, 'Customer Invoices'], 'ref' => 'Reversal of INV/2026/00001']);
        $move(903, 'BILL/2026/00001', 'in_invoice', 'posted', [12, 'مورد الخضار'], '2026-02-21', -500.0, -500.0, ['invoice_date' => '2026-02-21', 'payment_state' => 'not_paid', 'invoice_origin' => 'P00001']);
        $move(904, 'BNK1/2026/00001', 'entry', 'posted', $reef, '2026-03-08', 1000.0, 0.0, ['payment_state' => 'not_paid', 'payment_id' => [50, 'BNK1/2026/00001'], 'journal_id' => [3, 'Bank'], 'ref' => 'INV/2026/00001']);
        $move(905, '/', 'out_invoice', 'draft', [11, 'Blue Hotel'], '2026-04-02', 575.0, 575.0, ['payment_state' => 'not_paid', 'invoice_origin' => 'S00003']);
        $this->model('account.move.line', 'Journal Item', ['move_id' => 'many2one', 'move_name' => 'char', 'name' => 'char', 'account_id' => 'many2one', 'journal_id' => 'many2one', 'partner_id' => 'many2one', 'product_id' => 'many2one',
            'date' => 'date', 'debit' => 'monetary', 'credit' => 'monetary', 'balance' => 'monetary', 'quantity' => 'float', 'price_unit' => 'float', 'price_subtotal' => 'monetary', 'price_total' => 'monetary',
            'display_type' => 'selection', 'parent_state' => 'selection', 'sequence' => 'integer', 'matching_number' => 'char', 'company_id' => 'many2one']);
        $item = fn (int $id, int $move, string $moveName, array $journal, string $date, string $type, array $account, float $debit, float $credit, int $sequence, array $more = []) => $this->rec('account.move.line', $id, $more + [
            'move_id' => [$move, $moveName], 'move_name' => $moveName, 'account_id' => $account, 'journal_id' => $journal, 'date' => $date, 'debit' => $debit, 'credit' => $credit, 'balance' => $debit - $credit,
            'display_type' => $type, 'parent_state' => 'posted', 'sequence' => $sequence, 'company_id' => $co, 'partner_id' => $reef], "{$moveName} #{$id}");
        $item(9004, 900, 'INV/2026/00001', [1, 'Customer Invoices'], '2026-03-06', 'payment_term', [40, '110200 Receivables'], 1150.0, 0.0, 1, ['name' => 'INV/2026/00001', 'matching_number' => 'P1']);
        $item(9003, 900, 'INV/2026/00001', [1, 'Customer Invoices'], '2026-03-06', 'tax', [43, '210500 VAT'], 0.0, 150.0, 2, ['name' => 'VAT 15%']);
        $item(9002, 900, 'INV/2026/00001', [1, 'Customer Invoices'], '2026-03-06', 'product', [41, '410000 Sales'], 0.0, 200.0, 20, ['name' => $oil[1], 'product_id' => $oil, 'quantity' => 20.0, 'price_unit' => 10.0, 'price_subtotal' => 200.0, 'price_total' => 230.0]);
        $item(9001, 900, 'INV/2026/00001', [1, 'Customer Invoices'], '2026-03-06', 'product', [41, '410000 Sales'], 0.0, 800.0, 10, ['name' => $flour[1], 'product_id' => $flour, 'quantity' => 16.0, 'price_unit' => 50.0, 'price_subtotal' => 800.0, 'price_total' => 920.0]);
        $item(9010, 904, 'BNK1/2026/00001', [3, 'Bank'], '2026-03-08', 'product', [42, '110100 Bank'], 1000.0, 0.0, 10, ['name' => 'Customer payment']);
        $item(9011, 904, 'BNK1/2026/00001', [3, 'Bank'], '2026-03-08', 'product', [40, '110200 Receivables'], 0.0, 1000.0, 20, ['name' => 'Customer payment', 'matching_number' => 'P1']);
        $this->model('account.partial.reconcile', 'Partial Reconcile', ['debit_move_id' => 'many2one', 'credit_move_id' => 'many2one', 'amount' => 'monetary', 'max_date' => 'date', 'company_id' => 'many2one']);
        $this->rec('account.partial.reconcile', 80, ['debit_move_id' => [9004, 'INV/2026/00001'], 'credit_move_id' => [9011, 'BNK1/2026/00001'], 'amount' => 1000.0, 'max_date' => '2026-03-08', 'company_id' => $co], 'account.partial.reconcile,80');
        $this->model('account.payment', 'Payments', ['move_id' => 'many2one', 'partner_id' => 'many2one', 'amount' => 'monetary', 'amount_company_currency_signed' => 'monetary', 'payment_type' => 'selection',
            'partner_type' => 'selection', 'currency_id' => 'many2one', 'payment_method_line_id' => 'many2one', 'is_reconciled' => 'boolean']);
        $this->rec('account.payment', 50, ['move_id' => [904, 'BNK1/2026/00001 (INV/2026/00001)'], 'partner_id' => $reef, 'amount' => 1000.0, 'amount_company_currency_signed' => 1000.0, 'payment_type' => 'inbound',
            'partner_type' => 'customer', 'currency_id' => $sar, 'payment_method_line_id' => [1, 'Manual'], 'is_reconciled' => true], 'BNK1/2026/00001');

        // Odoo's dictionary, the history of documents and their attachments
        $this->model('ir.model.fields', 'Fields', ['model' => 'char', 'name' => 'char', 'field_description' => 'char', 'ttype' => 'selection', 'relation' => 'char']);
        foreach ([601 => ['sale.order', 'state', 'Status', 'selection'], 602 => ['sale.order', 'amount_total', 'Total', 'monetary'], 603 => ['sale.order', 'partner_id', 'Customer', 'many2one', 'res.partner'],
            604 => ['sale.order', 'partner_shipping_id', 'Delivery Address', 'many2one', 'res.partner'], 605 => ['sale.order', 'warehouse_id', 'Warehouse', 'many2one', 'stock.warehouse'],
            620 => ['hr.employee', 'identification_id', 'Identification No', 'char'], 621 => ['hr.employee', 'job_title', 'Job Title', 'char'], 630 => ['pos.session', 'state', 'Status', 'selection']] as $id => $field) {
            $this->rec('ir.model.fields', $id, array_filter(['model' => $field[0], 'name' => $field[1], 'field_description' => $field[2], 'ttype' => $field[3], 'relation' => $field[4] ?? null]), "{$field[2]} ({$field[0]})");
        }
        $this->model('ir.model.fields.selection', 'Fields Selection', ['field_id' => 'many2one', 'value' => 'char', 'name' => 'char', 'sequence' => 'integer']);
        foreach ([[601, 'draft', 'Quotation'], [601, 'sale', 'Sales Order'], [601, 'cancel', 'Cancelled'], [630, 'opened', 'In Progress'], [630, 'closed', 'Closed & Posted']] as $i => [$field, $value, $name]) {
            $this->rec('ir.model.fields.selection', 650 + $i, ['field_id' => [$field, 'x'], 'value' => $value, 'name' => $name, 'sequence' => $i]);
        }
        $this->model('mail.message', 'Message', ['model' => 'char', 'res_id' => 'many2one_reference', 'body' => 'html', 'date' => 'datetime', 'author_id' => 'many2one', 'message_type' => 'selection', 'subject' => 'char']);
        $this->rec('mail.message', 3001, ['model' => 'sale.order', 'res_id' => 1, 'body' => '<p>تم تأكيد الطلب <b>S00001</b></p>', 'date' => '2026-03-05 21:31:00', 'author_id' => [20, 'Sara Sales'], 'message_type' => 'notification']);
        $this->rec('mail.message', 3002, ['model' => 'sale.order', 'res_id' => 1, 'body' => '<p>Customer asked for morning delivery</p>', 'date' => '2026-03-06 07:00:00', 'author_id' => [20, 'Sara Sales'], 'message_type' => 'comment']);
        $this->rec('mail.message', 3003, ['model' => 'discuss.channel', 'res_id' => 9, 'body' => '<p>SECRET-CHAT about salaries</p>', 'date' => '2026-03-06 08:00:00', 'author_id' => [20, 'Sara Sales'], 'message_type' => 'comment']);
        $this->rec('mail.message', 3004, ['model' => 'sale.order', 'res_id' => 2, 'body' => '<p>NOTE-OF-ANOTHER-ORDER</p>', 'date' => '2026-03-10 09:05:00', 'author_id' => [20, 'Sara Sales'], 'message_type' => 'comment']);
        $this->rec('mail.message', 3005, ['body' => '<p>DIRECT-MESSAGE between two people</p>', 'date' => '2026-03-07 08:00:00', 'author_id' => [20, 'Sara Sales'], 'message_type' => 'comment']);
        $this->rec('mail.message', 3006, ['model' => 'hr.employee', 'res_id' => 1, 'body' => '', 'date' => '2026-03-01 08:00:00', 'author_id' => [20, 'Sara Sales'], 'message_type' => 'notification']);
        $this->model('mail.tracking.value', 'Mail Tracking Value', ['field_id' => 'many2one', 'mail_message_id' => 'many2one', 'old_value_char' => 'char', 'new_value_char' => 'char', 'old_value_float' => 'float', 'new_value_float' => 'float',
            'old_value_integer' => 'integer', 'new_value_integer' => 'integer']);
        $none = ['old_value_float' => 0.0, 'new_value_float' => 0.0, 'old_value_integer' => 0, 'new_value_integer' => 0];
        $this->rec('mail.tracking.value', 4001, ['field_id' => [601, 'Status (Sales Order)'], 'mail_message_id' => [3001, 'x'], 'old_value_char' => 'Quotation', 'new_value_char' => 'Sales Order'] + $none);
        $this->rec('mail.tracking.value', 4002, ['field_id' => [602, 'Total (Sales Order)'], 'mail_message_id' => [3001, 'x'], 'new_value_float' => 1150.0] + $none);
        $this->rec('mail.tracking.value', 4003, ['field_id' => [620, 'Identification No (Employee)'], 'mail_message_id' => [3006, 'x'], 'old_value_char' => 'ID-OLD-1', 'new_value_char' => 'ID-1090'] + $none);
        $this->rec('mail.tracking.value', 4004, ['field_id' => [621, 'Job Title (Employee)'], 'mail_message_id' => [3006, 'x'], 'old_value_char' => 'مساعد', 'new_value_char' => 'سائق'] + $none);
        $this->model('ir.attachment', 'Attachment', ['name' => 'char', 'res_model' => 'char', 'res_id' => 'many2one_reference', 'res_field' => 'char', 'mimetype' => 'char', 'file_size' => 'integer', 'type' => 'selection']);
        $this->rec('ir.attachment', 7001, ['name' => 'S00001.pdf', 'res_model' => 'sale.order', 'res_id' => 1, 'mimetype' => 'application/pdf', 'file_size' => 20480, 'type' => 'binary', 'create_date' => '2026-03-05 21:40:00']);
        $this->rec('ir.attachment', 7002, ['name' => 'signature.png', 'res_model' => 'sale.order', 'res_id' => 1, 'mimetype' => 'image/png', 'file_size' => 900, 'type' => 'binary', 'create_date' => '2026-03-05 21:35:00']);
        $this->rec('ir.attachment', 7003, ['name' => 'image_1920', 'res_model' => 'product.template', 'res_id' => 100, 'res_field' => 'image_1920', 'mimetype' => 'image/jpeg', 'file_size' => 5000, 'type' => 'binary']);
        $this->rec('ir.attachment', 7004, ['name' => 'contract-scan.pdf', 'res_model' => 'hr.contract', 'res_id' => 1, 'mimetype' => 'application/pdf', 'file_size' => 7000, 'type' => 'binary']);
        DB::table('odoo_files')->insert(['attachment_id' => 7001, 'res_model' => 'sale.order', 'res_id' => 1, 'name' => 'S00001.pdf', 'mimetype' => 'application/pdf', 'size' => 20480, 'checksum' => str_repeat('a', 40),
            'status' => 'stored', 'disk' => 'local', 'path' => 'odoo-files/7001', 'stored_at' => now()]);

        // people: the staff directory is open, the personal file and the contracts are not
        $this->model('hr.department', 'Department', ['name' => 'char', 'complete_name' => 'char', 'active' => 'boolean']);
        $this->rec('hr.department', 3, ['name' => 'Logistics', 'complete_name' => 'Logistics', 'active' => true]);
        $this->model('hr.employee', 'Employee', ['name' => 'char', 'job_title' => 'char', 'work_email' => 'char', 'department_id' => 'many2one', 'private_street' => 'char', 'identification_id' => 'char', 'birthday' => 'date',
            'bank_account_id' => 'many2one', 'active' => 'boolean']);
        $this->rec('hr.employee', 1, ['name' => 'موظف أول', 'job_title' => 'سائق', 'work_email' => 'driver@example.test', 'department_id' => [3, 'Logistics'], 'private_street' => 'HOME-STREET-9', 'identification_id' => 'ID-1090',
            'birthday' => '1990-01-01', 'bank_account_id' => [1, 'SA00-IBAN-SECRET'], 'active' => true]);
        $this->model('hr.contract', 'Employee Contract', ['name' => 'char', 'employee_id' => 'many2one', 'wage' => 'monetary', 'state' => 'selection', 'date_start' => 'date', 'department_id' => 'many2one', 'active' => 'boolean', 'company_id' => 'many2one']);
        $this->rec('hr.contract', 1, ['name' => 'CONTRACT-001', 'employee_id' => [1, 'موظف أول'], 'wage' => 9000.0, 'state' => 'open', 'date_start' => '2026-01-01', 'department_id' => [3, 'Logistics'], 'active' => true, 'company_id' => $co]);

        // models nobody described: a plain kind each
        $this->model('res.country', 'Country', ['name' => 'char', 'code' => 'char']);
        $this->rec('res.country', 1, ['name' => 'Saudi Arabia', 'code' => 'SA']);
        $this->rec('res.country', 2, ['name' => 'Egypt', 'code' => 'EG']);
        $this->model('pos.session', 'Point of Sale Session', ['name' => 'char', 'state' => 'selection', 'user_id' => 'many2one']);
        $this->rec('pos.session', 1, ['name' => 'POS/00001', 'state' => 'opened', 'user_id' => $sara]);
        $this->model('x_custom.thing', 'Custom Thing', ['name' => 'char']);
        $this->rec('x_custom.thing', 1, ['name' => 'Something the company built']);
        $this->model('discuss.channel', 'Discussion Channel', ['name' => 'char', 'channel_type' => 'selection', 'active' => 'boolean']);
        $this->rec('discuss.channel', 9, ['name' => 'Sara, Omar', 'channel_type' => 'chat', 'active' => true]);

        foreach (array_chunk($this->records, 100) as $chunk) {
            DB::table('odoo_records')->insert($chunk);
        }
    }

    private function build(array $options = []): string
    {
        $this->assertSame(0, Artisan::call('scm:odoo-archive-index', $options));

        return Artisan::output();
    }

    private function api(string $path, string $as = 'admin'): array
    {
        return $this->expectOk($this->getAs($as, self::API.$path));
    }

    private function doc(string $model, int $id): ?object
    {
        return DB::table('odoo_docs')->where('model', $model)->where('odoo_id', $id)->first();
    }

    /** @return int[] */
    private static function ids(array $list): array
    {
        return array_map(fn ($item) => $item['odooId'], $list['items'] ?? $list);
    }

    private static function find(array $list, string $key, mixed $value): ?array
    {
        return collect($list)->firstWhere($key, $value);
    }

    // ───────────── the index ─────────────

    public function test_the_build_indexes_every_copied_record_once_and_can_be_stopped_and_resumed(): void
    {
        $copy = DB::table('odoo_records')->count();
        $before = DB::table('odoo_records')->selectRaw('MAX(changed_at) AS latest, SUM(version) AS versions')->first();
        $this->assertFalse($this->api('/catalog', 'gm')['ready']);

        // a build that runs out of time stops between two steps and goes on from the id it reached
        config(['odoo_archive.chunk' => 2]);
        $writer = $this->app->make(ArchiveWriter::class);
        $stopped = $writer->build(['sale.order.line'], false, null, microtime(true));
        $this->assertSame([false, 2], [$stopped['complete'], $stopped['written']]);
        $this->assertSame([1002, 0], [(int) DB::table('odoo_docs_state')->where('model', 'sale.order.line')->value('cursor_id'), (int) DB::table('odoo_docs_state')->where('model', 'sale.order.line')->value('done')]);
        $resumed = $writer->build(['sale.order.line']);
        $this->assertSame([true, 3, 5], [$resumed['complete'], $resumed['written'], DB::table('odoo_docs')->where('model', 'sale.order.line')->count()]);
        config(['odoo_archive.chunk' => 1000]);

        $this->assertStringContainsString('index complete', $this->build());
        // one row per record of the copy, whatever its model
        $this->assertSame($copy, DB::table('odoo_docs')->count());
        $this->assertSame($copy, DB::table('odoo_docs')->distinct()->count(DB::raw("CONCAT(model, ':', odoo_id)")));
        $this->assertSame(0, DB::table('odoo_docs_state')->where('done', false)->count());

        $so = $this->doc('sale.order', 1);
        $this->assertSame(['sale_order', 'S00001', 'PO-REEF-77', 10, 'مطعم الريف', '2026-03-05 21:30:00', 'sale', 1150.0, 'SAR', 7, 'Sara Sales', 'invoiced', 'full', 1, 1, 0],
            [$so->kind, $so->number, $so->title, (int) $so->partner_id, $so->partner_name, $so->doc_date, $so->state, (float) $so->amount, $so->currency, (int) $so->user_id, $so->user_name, $so->dim1, $so->dim2,
                (int) $so->live, (int) $so->active, (int) $so->private]);
        // the search text is folded: lower case, one spelling of the Arabic letters
        $this->assertStringContainsString('po-reef-77', $so->search);
        $this->assertStringContainsString('مطعم الريف', $so->search);
        $this->assertStringContainsString('شركه النخبه', $this->doc('sale.order', 4)->search);

        // the kind of a record is decided by its values: the five kinds of account.move
        $this->assertSame(['customer_invoice', 'customer_invoice', 'customer_credit_note', 'vendor_bill', 'journal_entry'], array_map(fn ($id) => $this->doc('account.move', $id)->kind, [900, 901, 902, 903, 904]));
        $this->assertSame([150.0, -115.0, 'partial', 'S00001'], [(float) $this->doc('account.move', 900)->residual, (float) $this->doc('account.move', 902)->residual, $this->doc('account.move', 900)->dim1, $this->doc('account.move', 900)->dim2]);
        // what a record hangs on: a line on its order, a credit note on its invoice, a delivery on its sales order, a payment's entry on the payment
        $parent = fn (string $model, int $id) => [$this->doc($model, $id)->parent_model, (int) $this->doc($model, $id)->parent_id];
        $this->assertSame([['sale.order', 1], ['account.move', 900], ['sale.order', 1], ['account.payment', 50], ['account.move', 900], ['sale.order', 1], ['product.template', 100]],
            [$parent('sale.order.line', 1001), $parent('account.move', 902), $parent('stock.picking', 500), $parent('account.move', 904), $parent('account.move.line', 9001), $parent('mail.message', 3001), $parent('product.product', 200)]);
        // a payment is dated and numbered by its journal entry; a product is named by its template
        $pay = $this->doc('account.payment', 50);
        $this->assertSame(['payment', 'BNK1/2026/00001', '2026-03-08 00:00:00', 'posted', 1000.0, 'inbound'], [$pay->kind, $pay->number, $pay->doc_date, $pay->state, (float) $pay->amount, $pay->dim1]);
        $product = $this->doc('product.product', 200);
        $this->assertSame(['product', 'P0001', 'طحين فاخر Flour 10kg', 200, 'product', 50.0, '5', 'lot'], [$product->kind, $product->number, $product->title, (int) $product->product_id, $product->state, (float) $product->amount, $product->dim1, $product->dim2]);
        $this->assertSame(200, (int) $this->doc('product.template', 100)->product_id);
        // the lines of a document keep the document's order: its own lines, then the tax, then the amount due
        $this->assertSame([10, 20, 5000002, 6000001], array_map(fn ($id) => (int) $this->doc('account.move.line', $id)->seq, [9001, 9002, 9003, 9004]));
        // contacts carry who they are for the lists of customers and vendors
        $this->assertSame([['1', '0'], ['0', '1'], ['1', '1'], ['0', '0']], array_map(fn ($id) => [$this->doc('res.partner', $id)->dim1, $this->doc('res.partner', $id)->dim2], [10, 12, 13, 14]));
        // a chat is not the history of a document: its messages hang on nothing
        $this->assertSame([null, null], [$this->doc('mail.message', 3003)->parent_model, $this->doc('mail.message', 3005)->parent_model]);
        // people's files: the kind (a contract), or what a file hangs on (the scan of a contract)
        $this->assertSame([1, 0, 1, 0, 1], array_map(fn ($pair) => (int) $this->doc($pair[0], $pair[1])->private, [['hr.contract', 1], ['hr.employee', 1], ['ir.attachment', 7004], ['ir.attachment', 7001], ['discuss.channel', 9]]));
        $this->assertSame(['attachment', 'attachment_field', 'res_country', 'x_custom_thing'], [$this->doc('ir.attachment', 7001)->kind, $this->doc('ir.attachment', 7003)->kind, $this->doc('res.country', 1)->kind, $this->doc('x_custom.thing', 1)->kind]);
        // nothing personal reaches the search text of an open kind
        $this->assertStringNotContainsString('id-1090', (string) $this->doc('hr.employee', 1)->search);

        // a second run finds nothing to do, and the copy was only read
        $this->assertStringContainsString('0 rows written', $this->build());
        $after = DB::table('odoo_records')->selectRaw('MAX(changed_at) AS latest, SUM(version) AS versions')->first();
        $this->assertEquals([$before->latest, $before->versions, $copy], [$after->latest, $after->versions, DB::table('odoo_records')->count()]);
        $this->assertTrue($this->api('/catalog', 'gm')['ready']);
    }

    // ───────────── the catalog ─────────────

    public function test_the_catalog_lists_the_sections_and_kinds_with_their_counts_columns_and_filters(): void
    {
        $this->build();
        $this->expectRejected($this->getAs('worker', self::API.'/catalog'), 'FORBIDDEN', [403]);
        $catalog = $this->api('/catalog');
        $this->assertTrue($catalog['ready']);
        $this->assertSame(['sales', 'purchasing', 'inventory', 'accounting', 'products', 'people', 'other', 'settings'], array_column($catalog['sections'], 'key'));
        $kinds = collect($catalog['sections'])->flatMap(fn ($s) => array_map(fn ($k) => $k + ['section' => $s['key']], $s['kinds']))->keyBy('key');
        $count = fn (string $kind) => $kinds[$kind]['count'] ?? null;
        $this->assertSame([5, 5, 4, 2, 6, 3, 1, 1, 1, 6, 1, 2, 1], [$count('sale_order'), $count('sale_order_line'), $count('customer'), $count('supplier'), $count('contact'), $count('customer_invoice'),
            $count('customer_credit_note'), $count('vendor_bill'), $count('journal_entry'), $count('journal_item'), $count('payment'), $count('product'), $count('contract')]);
        // the history of documents is never a list; every listed record is counted once (views are other lists of the same records)
        $this->assertFalse($kinds->has('mail_message') || $kinds->has('mail_tracking'));
        $this->assertSame(DB::table('odoo_docs')->whereNotIn('model', ['mail.message', 'mail.tracking.value'])->count(), $catalog['indexed']);
        $this->assertEquals(['partner' => 'contact', 'product' => 'product'], $catalog['lookups']);

        $so = $kinds['sale_order'];
        $this->assertSame(['sales', 'أوامر البيع', 'Sales orders', 'أمر بيع', 'sale.order', false, false, false], [$so['section'], $so['label'], $so['labelEn'], $so['one'], $so['model'], $so['private'], $so['plain'], $so['line']]);
        $this->assertSame([['number', 'text'], ['partner', 'partner'], ['date', 'datetime'], ['user', 'user'], ['amount_untaxed', 'money'], ['amount', 'money'], ['invoice_status', 'state'], ['delivery_status', 'state'], ['state', 'state']],
            array_map(fn ($c) => [$c['key'], $c['type']], $so['columns']));
        $this->assertSame(['العميل', 'Customer'], [$so['columns'][1]['label'], $so['columns'][1]['labelEn']]);
        // the choices of a state: the definition's labels and tones, with how many documents are in each
        $this->assertSame([['draft', 'عرض سعر', 'Quotation', 'neutral', 1], ['sale', 'أمر بيع', 'Sales order', 'ok', 3], ['cancel', 'ملغى', 'Cancelled', 'off', 1]],
            collect($so['columns'][8]['options'])->whereIn('value', ['draft', 'sale', 'cancel'])->map(fn ($o) => [$o['value'], $o['label'], $o['labelEn'], $o['tone'], $o['count'] ?? 0])->values()->all());
        $this->assertSame([['state', 'choice'], ['date', 'daterange'], ['partner', 'partner'], ['dim1', 'choice'], ['dim2', 'choice']], array_map(fn ($f) => [$f['key'], $f['type']], $so['filters']));
        $this->assertSame(['حالة الفوترة', 'to invoice'], [$so['filters'][3]['label'], $so['filters'][3]['options'][1]['value']]);
        $this->assertSame([['date', 'number', 'amount'], 'date', 'desc', true], [$so['sorts'], $so['defaultSort'], $so['defaultOrder'], $so['totals']]);
        // a dimension that names a record offers the records in use, by name; a free one stays a text box until its values are few
        $this->assertSame([['1', 'Receipts', 1], ['2', 'Delivery Orders', 2]], array_map(fn ($o) => [$o['value'], $o['label'], $o['count']], collect(self::find($kinds['transfer']['filters'], 'key', 'dim1')['options'])->sortBy('value')->values()->all()));
        $this->assertSame('choice', self::find($kinds['customer_invoice']['filters'], 'key', 'dim2')['type']);
        $this->assertSame(['product'], array_column(array_filter($kinds['sale_order_line']['filters'], fn ($f) => $f['type'] === 'product'), 'key'));

        // a model nobody described: its name, date and state, under the section its name points at — Odoo's own labels for its states
        $this->assertSame([['settings', 'الدول', 'Country', true], ['other', 'Custom Thing', 'Custom Thing', true], ['other', 'جلسات نقطة البيع', 'Point of Sale Session', true]],
            array_map(fn ($k) => [$kinds[$k]['section'], $kinds[$k]['label'], $kinds[$k]['labelEn'], $kinds[$k]['plain']], ['res_country', 'x_custom_thing', 'pos_session']));
        $this->assertSame(['number', 'title', 'date'], array_column($kinds['res_country']['columns'], 'key'));
        $this->assertSame([['opened', 'In Progress', 'blue']], array_map(fn ($o) => [$o['value'], $o['label'], $o['tone']], self::find($kinds['pos_session']['filters'], 'key', 'state')['options']));

        // people's files: marked for who may read them — and for who may not, neither listed nor counted
        $this->assertSame([true, true, false, false], [$kinds['contract']['private'], $kinds['discuss_channel']['private'], $kinds['employee']['private'], self::find($catalog['sections'], 'key', 'people')['locked']]);
        $open = $this->api('/catalog', 'gm');
        $openKinds = collect($open['sections'])->flatMap(fn ($s) => $s['kinds'])->keyBy('key');
        $this->assertFalse($openKinds->has('contract') || $openKinds->has('discuss_channel'));
        $this->assertTrue($openKinds->has('employee') && self::find($open['sections'], 'key', 'people')['locked']);
        $this->assertSame([3, 2], [$count('attachment'), $openKinds['attachment']['count']]);
        $this->assertSame($catalog['indexed'] - 3, $open['indexed']); // the contract, the chat channel and the scan of the contract
    }

    // ───────────── lists ─────────────

    public function test_the_list_of_sales_orders_filters_sorts_pages_and_totals(): void
    {
        $this->build();
        $list = fn (string $query = '', string $as = 'admin') => $this->api('/docs/sale_order'.($query ? "?{$query}" : ''), $as);
        $all = $list();
        $this->assertSame([[5, 3, 2, 1, 4], 5, 1, 50, 1], [self::ids($all), $all['total'], $all['page'], $all['pageSize'], $all['pages']]);
        $this->assertEquals(['amount' => 2399.5, 'currency' => 'SAR'], $all['totals']);
        $first = self::find($all['items'], 'odooId', 1);
        $this->assertEquals(['odooId' => 1, 'kind' => 'sale_order', 'number' => 'S00001', 'title' => 'PO-REEF-77', 'partner' => ['id' => 10, 'name' => 'مطعم الريف'], 'date' => '2026-03-05T21:30:00Z', 'state' => 'sale',
            'amount' => 1150.0, 'currency' => 'SAR', 'user' => 'Sara Sales', 'active' => true, 'deleted' => false, 'parent' => null,
            'cells' => ['number' => 'S00001', 'partner' => ['id' => 10, 'name' => 'مطعم الريف'], 'date' => '2026-03-05T21:30:00Z', 'user' => 'Sara Sales', 'amount_untaxed' => 1000.0, 'amount' => 1150.0,
                'invoice_status' => 'invoiced', 'delivery_status' => 'full', 'state' => 'sale']], $first);

        // every filter
        $this->assertSame([[5, 2, 1], [3], [4]], [self::ids($list('state=sale')), self::ids($list('state=draft')), self::ids($list('state=cancel'))]);
        $this->assertSame([[5, 1], [2], [2]], [self::ids($list('dim1=invoiced')), self::ids($list('dim1=to%20invoice')), self::ids($list('dim2=partial'))]);
        // a partner means the partner and the contacts under it; a day means the day in the business time zone (21:30 UTC is the next day in Riyadh)
        $this->assertSame([[5, 2, 1], [3], [5]], [self::ids($list('partner=10')), self::ids($list('partner=11')), self::ids($list('partner=14'))]);
        $this->assertSame([[1], [], [2, 1], [5, 3]], [self::ids($list('from=2026-03-06&to=2026-03-06')), self::ids($list('from=2026-03-05&to=2026-03-05')), self::ids($list('from=2026-03-01&to=2026-03-31')), self::ids($list('from=2026-04-01'))]);
        $this->assertSame([[2]], [self::ids($list('state=sale&dim2=partial&partner=10&from=2026-03-01&to=2026-03-31'))]);
        // the search box: any word of the number, the reference or the partner — every word must be found, whatever the spelling of the letters
        $this->assertSame([[1], [5, 2, 1], [4], [2], [1], []], [self::ids($list('q=reef')), self::ids($list('q='.urlencode('الريف'))), self::ids($list('q='.urlencode('شركه النخبه'))), self::ids($list('q='.urlencode('ريف s00002'))),
            self::ids($list('q=S00001')), self::ids($list('q=nothing-like-this'))]);
        $this->assertSame([[], []], [self::ids($list('q='.urlencode('100%'))), self::ids($list('q=_____'))]); // what a person types is text, not a pattern

        // every sort, both ways; a page of a list
        $this->assertSame([[4, 2, 5, 3, 1], [1, 3, 5, 2, 4], [1, 2, 3, 4, 5], [5, 4, 3, 2, 1], [4, 1, 2, 3, 5], [5, 3, 2, 1, 4]], [self::ids($list('sort=amount&order=asc')), self::ids($list('sort=amount&order=desc')),
            self::ids($list('sort=number&order=asc')), self::ids($list('sort=number')), self::ids($list('sort=date&order=asc')), self::ids($list('sort=not-a-column'))]);
        $page = $list('pageSize=2&page=2');
        $this->assertSame([[2, 1], 5, 2, 2, 3], [self::ids($page), $page['total'], $page['page'], $page['pageSize'], $page['pages']]);
        // the total is of the whole filtered list, not of the page
        $this->assertEquals([1725.0, 3, 1725.0], [$list('state=sale')['totals']['amount'], $list('state=sale')['total'], $list('state=sale&pageSize=1')['totals']['amount']]);
        $this->assertSame([0, []], [$list('deleted=1')['total'], $list('deleted=1')['items']]);

        // refusals
        $this->expectRejected($this->getAs('admin', self::API.'/docs/sale_order?from=06-03-2026'), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->getAs('admin', self::API.'/docs/no_such_kind'), 'ODOO_KIND_NOT_FOUND', [404]);
        $this->expectRejected($this->getAs('admin', self::API.'/docs/mail_message'), 'ODOO_KIND_NOT_FOUND', [404]);
        $this->expectRejected($this->getAs('worker', self::API.'/docs/sale_order'), 'FORBIDDEN', [403]);
    }

    public function test_the_lists_of_invoices_and_lines_filter_by_day_origin_product_and_parent(): void
    {
        $this->build();
        $list = fn (string $kind, string $query = '') => $this->api("/docs/{$kind}".($query ? "?{$query}" : ''));
        $invoices = $list('customer_invoice');
        $this->assertSame([[905, 901, 900], 3], [self::ids($invoices), $invoices['total']]);
        $this->assertEquals(['amount' => 1955.0, 'currency' => 'SAR'], $invoices['totals']);
        $cells = self::find($invoices['items'], 'odooId', 900)['cells'];
        $this->assertEquals(['INV/2026/00001', ['id' => 10, 'name' => 'مطعم الريف'], '2026-03-06', '2026-04-05', 'S00001', 1000.0, 1150.0, 150.0, 'partial', 'posted'],
            [$cells['number'], $cells['partner'], $cells['date'], $cells['invoice_date_due'], $cells['origin'], $cells['amount_untaxed_signed'], $cells['amount'], $cells['residual'], $cells['payment_state'], $cells['state']]);
        // every filter of the kind: state, payment status, the source document (free text, exact), the partner, the day (a day here is a day)
        $this->assertSame([[901, 900], [905], [900], [905, 901], [900], [901], [901, 900], [905], [900], [901]], [self::ids($list('customer_invoice', 'state=posted')), self::ids($list('customer_invoice', 'state=draft')),
            self::ids($list('customer_invoice', 'dim1=partial')), self::ids($list('customer_invoice', 'dim1=not_paid')), self::ids($list('customer_invoice', 'dim2=S00001')), self::ids($list('customer_invoice', 'dim2=S00002')),
            self::ids($list('customer_invoice', 'partner=10')), self::ids($list('customer_invoice', 'partner=11')), self::ids($list('customer_invoice', 'from=2026-03-06&to=2026-03-06')), self::ids($list('customer_invoice', 'q=inv+00002'))]);
        $this->assertSame([[901, 905, 900], [900, 905, 901], [905, 900, 901], [900, 901, 905]], [self::ids($list('customer_invoice', 'sort=amount&order=asc')), self::ids($list('customer_invoice', 'sort=amount')),
            self::ids($list('customer_invoice', 'sort=number&order=asc')), self::ids($list('customer_invoice', 'sort=date&order=asc'))]);
        $this->assertEquals([1380.0, 2], [$list('customer_invoice', 'state=posted')['totals']['amount'], $list('customer_invoice', 'state=posted')['total']]);
        // the other kinds of the same Odoo model are other lists
        $this->assertSame([[902], [903], [904]], [self::ids($list('customer_credit_note')), self::ids($list('vendor_bill')), self::ids($list('journal_entry'))]);

        // lines: by product, and the lines of ONE document in the document's order (the rest of a long document is read this way)
        $this->assertSame([[1005, 1003, 1001], [1002, 1001], [1001, 1002]], [self::ids($list('sale_order_line', 'product=200')), self::ids($list('sale_order_line', 'parent=sale.order:1')),
            self::ids($list('sale_order_line', 'parent=sale.order:1&sort=amount'))]);
        $line = self::find($list('sale_order_line', 'parent=sale.order:1')['items'], 'odooId', 1001);
        $this->assertEquals(['S00001', '2026-03-05T21:30:00Z', ['kind' => 'sale_order', 'id' => 1, 'model' => 'sale.order'], ['id' => 200, 'name' => '[P0001] طحين فاخر Flour 10kg'], 16.0, 800.0, 920.0],
            [$line['number'], $line['date'], $line['parent'], $line['cells']['product'], $line['cells']['product_uom_qty'], $line['cells']['amount'], $line['cells']['price_total']]);
        $items = $list('journal_item', 'parent=account.move:900');
        $this->assertSame([[9001, 9002, 9003, 9004], 0.0], [self::ids($items), (float) $items['totals']['amount']]);
        $this->assertSame([[9011, 9004], [9001], [9011, 9010]], [self::ids($list('journal_item', 'dim1=110200')), self::ids($list('journal_item', 'product=200')), self::ids($list('journal_item', 'dim2=3'))]);
        // customers and vendors are the contacts that are one, the other, or both
        $this->assertSame([[10, 11, 13, 15], [12, 13], [15], 6], [collect(self::ids($list('customer')))->sort()->values()->all(), collect(self::ids($list('supplier')))->sort()->values()->all(),
            self::ids($list('customer', 'active=0')), $list('contact')['total']]);
        $this->assertSame([14], self::ids($list('contact', 'partner=10')));
    }

    // ───────────── the page of a document ─────────────

    public function test_a_document_shows_its_summary_lines_related_documents_history_attachments_and_fields(): void
    {
        $this->build();
        $so = $this->api('/docs/sale_order/1');
        $this->assertEquals(['sale_order', 'sale.order', 1, 'أمر بيع', 'S00001', 'PO-REEF-77', ['value' => 'sale', 'label' => 'أمر بيع', 'labelEn' => 'Sales order', 'tone' => 'ok'], ['id' => 10, 'name' => 'مطعم الريف'],
            '2026-03-05T21:30:00Z', 1150.0, 'SAR', false, false],
            [$so['kind'], $so['model'], $so['odooId'], $so['label'], $so['number'], $so['title'], $so['state'], $so['partner'], $so['date'], $so['amount'], $so['currency'], $so['deleted'], $so['private']]);
        // the summary: only what has a value, each many2one a link to the document it names when the archive holds it
        $summary = collect($so['summary'])->keyBy('key');
        $this->assertEquals([['العميل', 'Customer', 'partner', ['id' => 10, 'name' => 'مطعم الريف'], ['kind' => 'contact', 'id' => 10]], ['عنوان التوصيل', 'Delivery address', 'text', 'فرع الريف - العليا', ['kind' => 'contact', 'id' => 14]],
            ['المستودع', 'Warehouse', 'text', 'Riyadh warehouse', ['kind' => 'warehouse', 'id' => 1]], ['المندوب', 'Salesperson', 'user', 'Sara Sales', ['kind' => 'user', 'id' => 7]]],
            array_map(fn ($key) => [$summary[$key]['label'], $summary[$key]['labelEn'], $summary[$key]['type'], $summary[$key]['value'], $summary[$key]['link'] ?? null], ['partner_id', 'partner_shipping_id', 'warehouse_id', 'user_id']));
        $this->assertEquals([['value' => 'invoiced', 'label' => 'مفوتر بالكامل', 'labelEn' => 'Fully invoiced', 'tone' => 'ok'], '2026-04-04', 1000.0, 'التسليم صباحًا'],
            [$summary['invoice_status']['value'], $summary['validity_date']['value'], $summary['amount_untaxed']['value'], $summary['note']['value']]);
        $this->assertFalse($summary->has('origin') || $summary->has('opportunity_id'));

        // its lines, in the order of the document
        $this->assertSame(['lines'], array_column($so['sections'], 'key'));
        $lines = $so['sections'][0];
        $this->assertSame(['بنود الأمر', 'sale_order_line', 2, false, [1002, 1001], ['parent' => 'sale.order:1']], [$lines['label'], $lines['kind'], $lines['total'], $lines['more'], array_column($lines['rows'], 'odooId'), $lines['query']]);
        $this->assertSame(['product', 'title', 'product_uom_qty', 'qty_delivered', 'qty_invoiced', 'product_uom', 'price_unit', 'discount', 'amount', 'price_total'], array_column($lines['columns'], 'key'));
        $this->assertEquals([['id' => 201, 'name' => '[P0002] زيت Oil 1L'], 20.0, 10.0, 200.0, 230.0, 'Units'], array_map(fn ($key) => $lines['rows'][0]['cells'][$key], ['product', 'product_uom_qty', 'price_unit', 'amount', 'price_total', 'product_uom']));

        // related documents: the invoices that name the order, and what hangs on it (its delivery)
        $this->assertEquals([['فواتير العميل', 'customer_invoice', 1, [900], ['dim2' => 'S00001']], ['إشعارات دائنة للعملاء', 'customer_credit_note', 1, [902], ['dim2' => 'S00001']], ['أذون المخزون', 'transfer', 1, [500], ['parent' => 'sale.order:1']]],
            array_map(fn ($g) => [$g['label'], $g['kind'], $g['total'], array_column($g['items'], 'odooId'), $g['query']], $so['related']));
        $this->assertEquals(['odooId' => 900, 'kind' => 'customer_invoice', 'number' => 'INV/2026/00001', 'title' => null, 'date' => '2026-03-06', 'state' => 'posted', 'amount' => 1150.0, 'currency' => 'SAR',
            'partner' => ['id' => 10, 'name' => 'مطعم الريف']], $so['related'][0]['items'][0]);

        // history: Odoo's messages ON THIS ORDER, newest first, with the value changes — never a chat, never another document's note
        $this->assertEquals([[3002, '2026-03-06T07:00:00Z', 'Sara Sales', 'note', 'Customer asked for morning delivery', []],
            [3001, '2026-03-05T21:31:00Z', 'Sara Sales', 'system', 'تم تأكيد الطلب S00001', [['field' => 'Status', 'name' => 'state', 'old' => 'Quotation', 'new' => 'Sales Order'], ['field' => 'Total', 'name' => 'amount_total', 'old' => 0.0, 'new' => 1150.0]]]],
            array_map(fn ($h) => [$h['id'], $h['at'], $h['author'], $h['type'], $h['text'], $h['changes']], $so['history']));
        $this->assertSame(2, $so['historyTotal']);
        $body = json_encode($so, JSON_UNESCAPED_UNICODE);
        foreach (['SECRET-CHAT', 'DIRECT-MESSAGE', 'NOTE-OF-ANOTHER-ORDER'] as $notHere) {
            $this->assertStringNotContainsString($notHere, $body);
        }

        // attachments: the files attached to the order (not the images of fields); a link where OPS keeps the content
        $this->assertEquals([[7001, 'S00001.pdf', 'application/pdf', 20480, true, '/api/odoo-backup/files/7001'], [7002, 'signature.png', 'image/png', 900, false, null]],
            array_map(fn ($a) => [$a['id'], $a['name'], $a['mimetype'], $a['size'], $a['archived'], $a['url']], $so['attachments']));

        // every copied field as it is in Odoo: Odoo's own label and type, the value as it was copied (a choice also by Odoo's name of it); empty fields are listed empty
        $fields = collect($so['fields'])->keyBy('name');
        $this->assertEquals([['Status', 'selection', 'sale'], ['Customer', 'many2one', [10, 'مطعم الريف']], ['Total', 'monetary', 1150.0], ['date_order', 'datetime', '2026-03-05 21:30:00'], ['origin', 'char', null],
            ['note', 'html', '<p>التسليم <b>صباحًا</b></p>']], array_map(fn ($name) => [$fields[$name]['label'], $fields[$name]['type'], $fields[$name]['value']], ['state', 'partner_id', 'amount_total', 'date_order', 'origin', 'note']));
        $this->assertSame(['Sales Order', false], [$fields['state']['valueLabel'], isset($fields['origin']['valueLabel'])]);

        // an invoice: its own lines apart from its accounting, the order it came from, the credit note on it, the payment that settled part of it
        $invoice = $this->api('/docs/customer_invoice/900');
        $this->assertSame([['lines', 'journal_item', 2, [9001, 9002]], ['items', 'journal_item', 4, [9001, 9002, 9003, 9004]]], array_map(fn ($s) => [$s['key'], $s['kind'], $s['total'], array_column($s['rows'], 'odooId')], $invoice['sections']));
        $this->assertSame([['product', 'title', 'quantity', 'price_unit', 'discount', 'price_subtotal', 'price_total'], ['account', 'partner', 'title', 'debit', 'credit']], array_map(fn ($s) => array_column($s['columns'], 'key'), $invoice['sections']));
        $this->assertEquals([['أوامر البيع', 'sale_order', [1], [1150.0]], ['التسويات والمدفوعات', 'payment', [50], [1000.0]], ['إشعارات دائنة للعملاء', 'customer_credit_note', [902], [-115.0]]],
            array_map(fn ($g) => [$g['label'], $g['kind'], array_column($g['items'], 'odooId'), array_column($g['items'], 'amount')], $invoice['related']));
        $this->assertEquals(['partial', 'مدفوع جزئيًا', 'warn'], array_map(fn ($key) => collect($invoice['summary'])->firstWhere('key', 'payment_state')['value'][$key], ['value', 'label', 'tone']));
        // … and from the payment back: its journal entry, and the invoice it settled, with the amount that was matched
        $payment = $this->api('/docs/payment/50');
        $this->assertEquals([['القيد المحاسبي', 'journal_entry', [904], [1000.0]], ['ما سوَّته الدفعة', 'customer_invoice', [900], [1000.0]]],
            array_map(fn ($g) => [$g['label'], $g['kind'], array_column($g['items'], 'odooId'), array_column($g['items'], 'amount')], $payment['related']));
        $this->assertEquals(['BNK1/2026/00001', '2026-03-08', 'posted'], [$payment['number'], $payment['date'], $payment['state']['value']]);

        // a long document carries its first lines and says that there are more
        config(['odoo_archive.section_rows' => 1]);
        $short = $this->api('/docs/sale_order/1')['sections'][0];
        $this->assertSame([[1002], 2, true], [array_column($short['rows'], 'odooId'), $short['total'], $short['more']]);

        // a kind names the model: the page answers with the record's own kind — and refuses what is not there
        $this->assertSame(['contact', 10], [$this->api('/docs/customer/10')['kind'], $this->api('/docs/supplier/10')['odooId']]);
        $this->assertSame('customer_credit_note', $this->api('/docs/journal_entry/902')['kind']);
        $this->expectRejected($this->getAs('admin', self::API.'/docs/sale_order/999'), 'ODOO_DOC_NOT_FOUND', [404]);
    }

    // ───────────── a partner, a product, the search, the CSV ─────────────

    public function test_the_partner_page_gathers_what_odoo_holds_about_a_partner(): void
    {
        $this->build();
        $reef = $this->api('/partners/10');
        $this->assertEquals(['contact', 10, 'مطعم الريف', true, false, 1], [$reef['kind'], $reef['odooId'], $reef['name'], $reef['isCustomer'], $reef['isSupplier'], $reef['contacts']]);
        // open balance = Odoo's own residuals of the POSTED invoices and credit notes (150 + 230 − 115); nothing is recomputed
        $this->assertEquals([['amount' => 265.0, 'currency' => 'SAR', 'documents' => 3], ['amount' => 0.0, 'currency' => null, 'documents' => 0]], [$reef['receivable'], $reef['payable']]);
        $groups = collect($reef['groups'])->keyBy('kind');
        $this->assertEquals([[3, 1725.0, [5, 2, 1]], [2, 1380.0, [901, 900]], [1, -115.0, [902]], [1, 1000.0, [50]], [2, null, [501, 500]], [1, null, [14]]],
            array_map(fn ($kind) => [$groups[$kind]['total'], $groups[$kind]['amount'], self::ids($groups[$kind])], ['sale_order', 'customer_invoice', 'customer_credit_note', 'payment', 'transfer', 'contact']));
        $this->assertSame(['partner' => 10], $groups['sale_order']['query']);
        $this->assertEquals(['S00005', 345.0, 'sale'], [$groups['sale_order']['items'][0]['number'], $groups['sale_order']['items'][0]['cells']['amount'], $groups['sale_order']['items'][0]['state']]);
        $this->assertSame('300111222', collect($reef['summary'])->firstWhere('key', 'vat')['value']);

        $vendor = $this->api('/partners/12');
        $this->assertEquals([false, true, ['amount' => 500.0, 'currency' => 'SAR', 'documents' => 1], 0.0], [$vendor['isCustomer'], $vendor['isSupplier'], $vendor['payable'], $vendor['receivable']['amount']]);
        // a draft invoice is not a debt
        $this->assertEquals(0.0, $this->api('/partners/11')['receivable']['amount']);
        $this->expectRejected($this->getAs('admin', self::API.'/partners/999'), 'ODOO_PARTNER_NOT_FOUND', [404]);
    }

    public function test_the_product_page_shows_odoo_stock_and_the_latest_sales_purchases_and_moves(): void
    {
        $this->build();
        $flour = $this->api('/products/200');
        $this->assertEquals(['product', 200, 'طحين فاخر Flour 10kg', 'P0001', 50.0], [$flour['kind'], $flour['odooId'], $flour['name'], $flour['code'], $flour['amount']]);
        // Odoo's stock: the quants in a warehouse's internal locations (what is "at customers" is Odoo's bookkeeping)
        $this->assertEquals([37.0, 5.0, 32.0, [['warehouseId' => 1, 'warehouse' => 'Riyadh warehouse', 'qty' => 30.0, 'reserved' => 5.0, 'available' => 25.0, 'lots' => 1, 'nearestExpiry' => null],
            ['warehouseId' => 2, 'warehouse' => 'Jeddah warehouse', 'qty' => 7.0, 'reserved' => 0.0, 'available' => 7.0, 'lots' => 0, 'nearestExpiry' => null]]],
            [$flour['stock']['qty'], $flour['stock']['reserved'], $flour['stock']['available'], $flour['stock']['warehouses']]);
        $this->assertSame([['sale_order_line', 3, [1005, 1003, 1001]], ['stock_move', 1, [600]], ['journal_item', 1, [9001]]], array_map(fn ($g) => [$g['kind'], $g['total'], self::ids($g)], $flour['groups']));
        $this->assertEquals([['product' => 200], 6.0], [$flour['groups'][0]['query'], $flour['groups'][0]['items'][0]['cells']['product_uom_qty']]);
        $card = collect($flour['summary'])->keyBy('key');
        $this->assertEquals(['6281000000017', 'All / Food', 31.5, 'منتج مخزني', ['kind' => 'product_template', 'id' => 100]],
            [$card['barcode']['value'], $card['category']['value'], $card['standard_price']['value'], $card['type']['value']['label'], $card['product_tmpl_id']['link']]);
        $this->expectRejected($this->getAs('admin', self::API.'/products/999'), 'ODOO_PRODUCT_NOT_FOUND', [404]);
    }

    public function test_the_search_finds_documents_across_kinds(): void
    {
        $this->build();
        $search = fn (string $q, string $as = 'admin') => $this->api('/search?q='.urlencode($q), $as)['groups'];
        // a document number: the order, the delivery and the invoices that came from it, the file named after it (lines are found through their document)
        $this->assertSame([['sale_order', 'أوامر البيع', 1, [1]], ['transfer', 'أذون المخزون', 1, [500]], ['customer_invoice', 'فواتير العملاء', 1, [900]], ['customer_credit_note', 'إشعارات دائنة للعملاء', 1, [902]],
            ['attachment', 'المرفقات', 1, [7001]]], array_map(fn ($g) => [$g['kind'], $g['label'], $g['total'], self::ids($g)], $search('s00001')));
        $this->assertEquals(['S00001', 1150.0, 'sales', 'المبيعات'], [$search('S00001')[0]['items'][0]['number'], $search('S00001')[0]['items'][0]['cells']['amount'], $search('S00001')[0]['section'], $search('S00001')[0]['sectionLabel']]);
        // a name, in any spelling of its letters: the contact, and the documents of the contact
        $byKind = collect($search('الريف'))->keyBy('kind');
        $this->assertSame([[14, 10], 3, 3], [self::ids($byKind['contact']), $byKind['sale_order']['total'], $byKind['customer_invoice']['total'] + $byKind['customer_credit_note']['total']]);
        $this->assertSame(['product'], array_column($search('flour'), 'kind'));
        $this->assertSame([[], [], []], [$search('x'), $search(''), $search('nothing-like-this')]);
        // people's files: found only by who may read them; the personal file and people's chats are found by nobody
        $this->assertSame([['contract'], []], [array_column($search('CONTRACT-001'), 'kind'), $search('CONTRACT-001', 'gm')]);
        $this->assertSame([[], [], [], ['employee']], [$search('ID-1090'), $search('SECRET-CHAT'), $search('HOME-STREET'), array_column($search('سائق', 'gm'), 'kind')]);
        $this->expectRejected($this->getAs('worker', self::API.'/search?q=S00001'), 'FORBIDDEN', [403]);
    }

    public function test_a_list_is_exported_as_csv_with_its_filters(): void
    {
        $this->build();
        $res = $this->getAs('admin', self::API.'/docs/sale_order/export?state=sale&sort=number&order=asc');
        $res->assertOk();
        $this->assertStringContainsString('text/csv', (string) $res->headers->get('Content-Type'));
        $this->assertStringContainsString('sale_order-', (string) $res->headers->get('Content-Disposition'));
        $csv = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $rows = array_map('str_getcsv', array_filter(explode("\n", substr($csv, 3))));
        $this->assertSame(['الرقم', 'العميل', 'تاريخ الطلب', 'المندوب', 'قبل الضريبة', 'الإجمالي', 'الفوترة', 'التسليم', 'الحالة'], $rows[0]);
        // the rows of the list, in its order; a state by its label, a moment in the business time zone, a number as a number
        $this->assertSame([['S00001', 'مطعم الريف', '2026-03-06 00:30', 'Sara Sales', '1000', '1150', 'مفوتر بالكامل', 'مسلَّم بالكامل', 'أمر بيع'], ['S00002', 'مطعم الريف', '2026-03-10 12:00', '', '200', '230', 'بانتظار الفوترة', 'مسلَّم جزئيًا', 'أمر بيع'],
            ['S00005', 'فرع الريف - العليا', '2026-04-15 15:00', 'Sara Sales', '300', '345', 'مفوتر بالكامل', 'مسلَّم بالكامل', 'أمر بيع']], array_slice($rows, 1));
        $english = array_map('str_getcsv', array_filter(explode("\n", substr($this->getAs('admin', self::API.'/docs/sale_order/export?q=reef&lang=en')->streamedContent(), 3))));
        $this->assertSame([['Number', 'Customer', 'Order date', 'Salesperson', 'Untaxed', 'Total', 'Invoicing', 'Delivery', 'Status'], 'Sales order', 2], [$english[0], $english[1][8], count($english)]);
        // at most `export_rows` rows
        config(['odoo_archive.export_rows' => 2]);
        $this->assertCount(3, array_filter(explode("\n", $this->getAs('admin', self::API.'/docs/sale_order/export')->streamedContent())));

        // the file needs report.export on top of the page; people's files need odoo.private here too
        $permission = DB::table('permissions')->where('key', 'report.export')->value('id');
        $role = DB::table('roles')->where('key', 'gm')->value('id');
        $this->getAs('gm', self::API.'/docs/sale_order/export')->assertOk();
        $this->expectRejected($this->getAs('gm', self::API.'/docs/contract/export'), 'ODOO_PRIVATE', [403]);
        DB::table('role_permissions')->where('role_id', $role)->where('permission_id', $permission)->delete();
        try {
            $this->expectRejected($this->getAs('gm', self::API.'/docs/sale_order/export'), 'FORBIDDEN', [403]);
            $this->assertSame(5, $this->api('/docs/sale_order', 'gm')['total']);
        } finally {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission]);
        }
    }

    // ───────────── people's files ─────────────

    public function test_peoples_files_are_shown_only_to_who_holds_odoo_private(): void
    {
        $this->build();
        // a kind built on people's files: list, page, search and CSV are refused without odoo.private — and open with it
        foreach (['/docs/contract', '/docs/contract/1', '/docs/discuss_channel', '/docs/discuss_channel/9', '/docs/attachment/7004'] as $path) {
            $this->expectRejected($this->getAs('gm', self::API.$path), 'ODOO_PRIVATE', [403]);
        }
        $this->assertEquals([[1], 'CONTRACT-001', 9000.0], [self::ids($this->api('/docs/contract')), $this->api('/docs/contract/1')['number'], $this->api('/docs/contract/1')['amount']]);

        // the staff directory is open — without the personal file, wherever it would show: summary, all fields, history, related lists
        $this->assertSame([1], self::ids($this->api('/docs/employee', 'gm')));
        $seen = $this->api('/docs/employee/1', 'gm');
        $text = json_encode($seen, JSON_UNESCAPED_UNICODE);
        foreach (['HOME-STREET-9', 'ID-1090', 'ID-OLD-1', '1990-01-01', 'SA00-IBAN-SECRET', 'CONTRACT-001'] as $personal) {
            $this->assertStringNotContainsString($personal, $text);
        }
        $this->assertEquals(['سائق', 'Logistics'], [collect($seen['summary'])->firstWhere('key', 'job_title')['value'], collect($seen['summary'])->firstWhere('key', 'department_id')['value']]);
        $this->assertSame([], array_values(array_intersect(array_column($seen['fields'], 'name'), ['private_street', 'identification_id', 'birthday', 'bank_account_id'])));
        $this->assertEquals([[['field' => 'Job Title', 'name' => 'job_title', 'old' => 'مساعد', 'new' => 'سائق']]], array_column($seen['history'], 'changes'));
        $this->assertSame([], array_column($seen['related'], 'kind'));

        $full = $this->api('/docs/employee/1');
        $this->assertEquals(['HOME-STREET-9', 'ID-1090', 'SA00-IBAN-SECRET'], array_map(fn ($key) => collect($full['summary'])->firstWhere('key', $key)['value'], ['private_street', 'identification_id', 'bank_account_id']));
        $this->assertSame([2, ['contract']], [count($full['history'][0]['changes']), array_column($full['related'], 'kind')]);

        // a file that hangs on people's files is one of them: not in the list of attachments, nor counted, for who may not read them
        $this->assertSame([[7001, 7002], [7001, 7002, 7004]], [collect(self::ids($this->api('/docs/attachment', 'gm')))->sort()->values()->all(), collect(self::ids($this->api('/docs/attachment')))->sort()->values()->all()]);
        $this->assertSame(['contract-scan.pdf'], array_column($this->api('/docs/contract/1')['attachments'], 'name'));
        $this->assertEquals(['S00001.pdf', ['model' => 'sale.order', 'kind' => 'sale_order', 'id' => 1], 'S00001'], [$this->api('/docs/attachment/7001', 'gm')['title'], self::find($this->api('/docs/attachment', 'gm')['items'], 'odooId', 7001)['parent'],
            self::find($this->api('/docs/attachment', 'gm')['items'], 'odooId', 7001)['cells']['attached_to']]);

        // what people wrote to each other is nobody's document: a chat channel has no history here, also for who may read people's files
        $channel = $this->api('/docs/discuss_channel/9');
        $this->assertSame([[], 0, true], [$channel['history'], $channel['historyTotal'], $channel['private']]);
        foreach (['/docs/sale_order/1', '/docs/sale_order/2', '/docs/discuss_channel/9', '/partners/10', '/search?q=salaries', '/search?q=between+two+people'] as $path) {
            $answer = $this->getAs('admin', self::API.$path)->getContent();
            $this->assertStringNotContainsString('SECRET-CHAT', $answer);
            $this->assertStringNotContainsString('DIRECT-MESSAGE', $answer);
        }
        $this->assertSame([[], []], [$this->api('/search?q=SECRET-CHAT')['groups'], $this->api('/search?q=DIRECT-MESSAGE')['groups']]);
    }

    public function test_a_server_that_has_the_code_but_not_the_index_says_so(): void
    {
        // the code was deployed and `php artisan migrate` has not run yet: nothing may fail on a missing table — least of all the mirror
        $schema = Schema::getFacadeRoot();
        ArchiveWriter::forgetInstalled();
        Schema::partialMock()->shouldReceive('hasTable')->with('odoo_docs')->andReturnFalse();
        try {
            $this->assertSame([false, 0, []], array_map(fn ($key) => $this->api('/catalog')[$key], ['ready', 'indexed', 'sections']));
            foreach (['/docs/sale_order', '/docs/sale_order/1', '/docs/sale_order/export', '/partners/10', '/products/200', '/search?q=reef'] as $path) {
                $this->expectRejected($this->getAs('admin', self::API.$path), 'ODOO_ARCHIVE_NOT_INSTALLED', [422]);
            }
            $this->assertSame(0, ArchiveIndexer::index('sale.order', [1, 2, 3]));
            $this->assertSame(1, Artisan::call('scm:odoo-archive-index'));
            $this->assertSame(0, DB::table('odoo_docs')->count());
        } finally {
            Schema::swap($schema);
            ArchiveWriter::forgetInstalled();
        }
        // … and once the tables are there, the same calls work
        $this->assertSame(1, ArchiveIndexer::index('sale.order', [1]));
        $this->build();
        $this->assertSame([true, 5], [$this->api('/catalog')['ready'], $this->api('/docs/sale_order')['total']]);
    }

    // ───────────── the index follows the copy ─────────────

    public function test_the_index_follows_what_the_mirror_writes(): void
    {
        $this->build();
        $states = fn () => collect(collect($this->api('/catalog')['sections'])->flatMap(fn ($s) => $s['kinds'])->firstWhere('key', 'sale_order')['filters'][0]['options'])->pluck('count', 'value')->all();
        $this->assertEquals(['draft' => 1, 'sale' => 3, 'cancel' => 1], array_intersect_key($states(), ['draft' => 1, 'sale' => 1, 'cancel' => 1]));

        // the mirror wrote a page: it calls the indexer the way OdooSync does — and the row, the lists, the totals and the catalog follow
        $this->change('sale.order', 3, ['state' => 'sale', 'amount_total' => 600.0, 'partner_id' => [10, 'مطعم الريف']]);
        $this->assertSame(1, $this->app->make('App\\Integration\\Odoo\\Archive\\ArchiveIndexer')->index('sale.order', [3]));
        $row = $this->doc('sale.order', 3);
        $this->assertSame(['sale', 600.0, 10, 2], [$row->state, (float) $row->amount, (int) $row->partner_id, (int) $row->rec_version]);
        $sale = $this->api('/docs/sale_order?state=sale');
        $this->assertEquals([[5, 3, 2, 1], 2325.0, 4], [self::ids($sale), $sale['totals']['amount'], $states()['sale']]);
        $this->assertSame([5, 3, 2, 1], self::ids($this->api('/docs/sale_order?partner=10')));

        // a record Odoo deleted keeps its row: out of the lists and the counts, listed apart, still readable
        $this->change('sale.order', 4, [], deleted: true);
        $this->assertSame(1, ArchiveIndexer::index('sale.order', [4]));
        $this->assertSame([0, 4, [4], true], [(int) $this->doc('sale.order', 4)->live, $this->api('/docs/sale_order')['total'], self::ids($this->api('/docs/sale_order?deleted=1')), $this->api('/docs/sale_order?deleted=1')['items'][0]['deleted']]);
        $this->assertEquals([true, 'S00004', false], [$this->api('/docs/sale_order/4')['deleted'], $this->api('/docs/sale_order/4')['number'], isset($states()['cancel'])]);
        // a record the copy no longer has at all loses its row
        DB::table('odoo_records')->where('model', 'sale.order.line')->where('odoo_id', 1004)->delete();
        ArchiveIndexer::index('sale.order.line', [1004, 1005]);
        $this->assertSame([null, 'sale_order_line'], [$this->doc('sale.order.line', 1004), $this->doc('sale.order.line', 1005)->kind]);

        // records that show values of another record follow it: a payment its journal entry, a product its template
        $this->change('account.move', 904, ['state' => 'cancel', 'date' => '2026-03-09']);
        ArchiveIndexer::index('account.move', [904]);
        $this->assertSame(['cancel', '2026-03-09 00:00:00'], [$this->doc('account.payment', 50)->state, $this->doc('account.payment', 50)->doc_date]);
        $this->change('product.template', 100, ['name' => 'طحين ممتاز Flour 10kg', 'list_price' => 55.0]);
        ArchiveIndexer::index('product.template', [100]);
        $this->assertSame(['طحين ممتاز Flour 10kg', 55.0], [$this->doc('product.product', 200)->title, (float) $this->doc('product.product', 200)->amount]);
        $this->assertSame([200], self::ids($this->api('/docs/product?q='.urlencode('ممتاز'))));
        // nothing to index, an unknown model, an id that is not there: quietly nothing
        $this->assertSame([0, 0, 0], [ArchiveIndexer::index('sale.order', []), ArchiveIndexer::index('no.such.model', [1]), ArchiveIndexer::index('sale.order', [424242])]);

        // what changed while the indexer was not called is caught up by the command — only that
        $this->change('sale.order', 1, ['client_order_ref' => 'PO-NEW-88']);
        $this->change('stock.picking', 501, ['state' => 'done']);
        $out = $this->build();
        $this->assertStringContainsString('2 rows written', $out);
        $this->assertSame(['PO-NEW-88', 'done', [1]], [$this->doc('sale.order', 1)->title, $this->doc('stock.picking', 501)->state, self::ids($this->api('/docs/sale_order?q=po-new'))]);
        // a row whose record is gone from the copy (a model copied again) is swept
        DB::table('odoo_docs')->insert(['kind' => 'sale_order', 'model' => 'sale.order', 'odoo_id' => 777, 'doc_date' => '2026-01-01 00:00:00', 'indexed_at' => now()]);
        $this->assertStringContainsString('(1 removed)', $this->build());
        $this->assertNull($this->doc('sale.order', 777));

        // the definitions of a kind changed: the command indexes that model again, by itself — and only that model
        config(['odoo_archive.kinds.sale_order.title' => 'origin', 'odoo_archive.kinds.sale_order.search' => ['origin']]);
        $this->app->forgetInstance(ArchiveKinds::class);
        $this->app->forgetInstance(ArchiveWriter::class);
        $out = $this->build();
        $this->assertStringContainsString('5 rows written', $out);
        $this->assertSame(['', []], [$this->doc('sale.order', 1)->title, self::ids($this->api('/docs/sale_order?q=po-new'))]);
        // --fresh --model rebuilds one model from nothing; --fresh alone the whole index
        $this->assertStringContainsString('6 rows written', $this->build(['--fresh' => true, '--model' => ['res.partner']]));
        $this->build(['--fresh' => true]);
        $this->assertSame(DB::table('odoo_records')->count(), DB::table('odoo_docs')->count());
    }
}
