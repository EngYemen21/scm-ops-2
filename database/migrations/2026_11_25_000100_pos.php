<?php

use App\Pos\PosBootstrap;
use App\Support\PermissionSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «نقطة البيع» — the counter (docs/odoo/WAVE4.md §Q1): registers, payment methods bound to finance accounts, cash
 * denominations, sessions with their opening and closing counts, orders (sales and refunds) with their lines, the
 * stock movements of each line, their payments, cash put in / taken out, and sales held at the counter.
 *
 * Records that came from Odoo carry `source_system = 'odoo'` + `external_ref` (the Odoo id). Nothing is deleted: a
 * register or a method is switched off, a sale is corrected by a refund, a held sale is discarded.
 */
return new class extends Migration
{
    private const TABLES = ['pos_held_sales', 'pos_cash_moves', 'pos_order_payments', 'pos_order_line_lots', 'pos_order_lines', 'pos_orders', 'pos_sessions', 'pos_register_methods',
        'pos_registers', 'pos_payment_methods', 'pos_denominations'];

    public function up(): void
    {
        Schema::create('pos_denominations', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->decimal('value', 10, 2);
            $t->string('kind', 6)->default('note');            // coin | note
            $t->string('label', 60);
            $t->boolean('active')->default(true);
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'pos_denominations_source_uq');
        });

        Schema::create('pos_payment_methods', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('code', 30)->unique();
            $t->string('name_ar', 120);
            $t->string('name_en', 120);
            $t->string('kind', 10);                            // cash | card | transfer | account
            $t->ulid('bank_account_id')->nullable();           // fin_bank_accounts: where the money lands (none for "account")
            $t->boolean('requires_reference')->default(false); // the terminal's / the transfer's reference is required
            $t->integer('sequence')->default(0);
            $t->boolean('active')->default(true);
            $t->json('extra')->nullable();
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->dateTime('source_updated_at', 3)->nullable();
            $t->dateTime('source_deleted_at', 3)->nullable();
            $t->string('created_by')->nullable();
            $t->string('updated_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'pos_methods_source_uq');
        });

        Schema::create('pos_registers', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('code', 30)->unique();
            $t->string('name_ar', 120);
            $t->string('name_en', 120);
            $t->ulid('warehouse_id')->nullable();               // the warehouse it sells from (Odoo's register: not linked)
            $t->ulid('default_customer_id')->nullable();        // the walk-in customer
            $t->decimal('max_discount_pct', 5, 2)->default(0);  // the highest line discount the cashier may give
            $t->boolean('allow_negative_stock')->default(false); // sell beyond the shelf: the rest is owed to stock
            $t->string('refund_restock', 8)->default('returns'); // shelf | returns (RET-01)
            $t->boolean('refund_needs_manager')->default(true); // refunds need pos.manage
            $t->ulid('difference_account_id')->nullable();      // fin_accounts: cash over / short (default: role admin_expenses)
            $t->string('receipt_format', 6)->default('80mm');   // 80mm | a4
            $t->text('receipt_footer')->nullable();
            $t->string('seller_name_ar', 190)->nullable();      // the seller printed on the tax invoice …
            $t->string('seller_name_en', 190)->nullable();
            $t->string('seller_vat_no', 20)->nullable();        // … with the VAT number of the QR code
            $t->string('seller_cr', 40)->nullable();
            $t->string('seller_address', 300)->nullable();
            $t->boolean('active')->default(true);
            $t->json('extra')->nullable();
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->dateTime('source_updated_at', 3)->nullable();
            $t->dateTime('source_deleted_at', 3)->nullable();
            $t->string('created_by')->nullable();
            $t->string('updated_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'pos_registers_source_uq');
            $t->index(['warehouse_id'], 'pos_registers_warehouse_ix');
        });

        Schema::create('pos_register_methods', function (Blueprint $t) {
            $t->ulid('register_id');
            $t->ulid('method_id');
            $t->primary(['register_id', 'method_id']);
            $t->index(['method_id'], 'pos_register_methods_method_ix');
        });

        Schema::create('pos_sessions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('number', 40)->unique();                // PSS-YYYY-NNNNN (Odoo: POS/00001)
            $t->ulid('register_id')->nullable();
            $t->ulid('cashier_id')->nullable();                // the OPS user …
            $t->string('cashier_name', 190)->nullable();       // … or the name Odoo gives
            $t->string('state', 8)->default('open');           // open | closed
            $t->dateTime('opened_at', 3)->nullable();
            $t->dateTime('closed_at', 3)->nullable();
            $t->string('closed_by')->nullable();
            $t->decimal('opening_cash', 16, 2)->default(0);
            $t->json('opening_counts')->nullable();
            $t->text('opening_note')->nullable();
            $t->json('closing_counts')->nullable();            // [{value, count, total}]
            $t->decimal('counted_cash', 16, 2)->nullable();
            $t->decimal('expected_cash', 16, 2)->nullable();
            $t->decimal('difference', 16, 2)->nullable();
            $t->text('difference_reason')->nullable();
            $t->ulid('difference_journal_id')->nullable();
            $t->text('closing_note')->nullable();
            $t->json('report')->nullable();                    // the end-of-day report frozen at closing
            // set while open, null after: the unique indexes allow one open session per register and per cashier
            $t->ulid('open_register_key')->nullable()->unique('pos_sessions_open_register_uq');
            $t->ulid('open_cashier_key')->nullable()->unique('pos_sessions_open_cashier_uq');
            $t->json('extra')->nullable();
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->dateTime('source_updated_at', 3)->nullable();
            $t->dateTime('source_deleted_at', 3)->nullable();
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'pos_sessions_source_uq');
            $t->index(['register_id', 'opened_at'], 'pos_sessions_register_ix');
            $t->index(['cashier_id', 'state'], 'pos_sessions_cashier_ix');
            $t->index(['state', 'opened_at'], 'pos_sessions_state_ix');
        });

        Schema::create('pos_orders', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('number', 40)->unique();                // POS-YYYY-NNNNN / POSR-YYYY-NNNNN (Odoo: Shop/0001)
            $t->string('kind', 8)->default('sale');            // sale | refund
            $t->ulid('refund_of_id')->nullable();
            $t->string('client_key', 64)->nullable()->unique('pos_orders_client_key_uq'); // one sale per key of the selling screen
            $t->string('payload_hash', 40)->nullable();
            $t->ulid('session_id')->nullable();
            $t->ulid('register_id')->nullable();
            $t->ulid('warehouse_id')->nullable();
            $t->ulid('cashier_id')->nullable();
            $t->string('cashier_name', 190)->nullable();
            $t->ulid('customer_id')->nullable();
            $t->string('customer_name', 250)->nullable();
            $t->dateTime('ordered_at', 3)->nullable();
            $t->string('status', 10)->default('done');
            $t->decimal('subtotal', 16, 2)->default(0);        // after line discounts, before VAT
            $t->decimal('discount_total', 16, 2)->default(0);
            $t->decimal('vat_pct', 5, 2)->default(0);
            $t->decimal('vat_amount', 16, 2)->default(0);
            $t->decimal('total', 16, 2)->default(0);
            $t->decimal('tendered', 16, 2)->default(0);
            $t->decimal('change_due', 16, 2)->default(0);
            $t->decimal('on_account', 16, 2)->default(0);
            $t->decimal('cogs_total', 16, 2)->default(0);
            $t->decimal('refunded_total', 16, 2)->default(0);
            $t->integer('pending_qty')->default(0);            // units still owed to stock
            $t->integer('pending_issues')->default(0);
            $t->string('stock_tx', 40)->nullable();
            $t->ulid('invoice_id')->nullable();                // fin_customer_invoices: CI- of a sale, CN- of a refund
            $t->string('invoice_number', 40)->nullable();
            $t->ulid('cogs_journal_id')->nullable();
            $t->ulid('refund_journal_id')->nullable();
            $t->text('reason')->nullable();                    // why a refund
            $t->text('note')->nullable();
            $t->json('extra')->nullable();
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->dateTime('source_updated_at', 3)->nullable();
            $t->dateTime('source_deleted_at', 3)->nullable();
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'pos_orders_source_uq');
            $t->index(['session_id', 'kind'], 'pos_orders_session_ix');
            $t->index(['register_id', 'ordered_at'], 'pos_orders_register_ix');
            $t->index(['customer_id', 'ordered_at'], 'pos_orders_customer_ix');
            $t->index(['refund_of_id'], 'pos_orders_refund_of_ix');
            $t->index(['ordered_at'], 'pos_orders_ordered_ix');
            $t->index(['pending_qty'], 'pos_orders_pending_ix');
        });

        Schema::create('pos_order_lines', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('order_id');
            $t->integer('line_no');
            $t->ulid('refund_of_line_id')->nullable();
            $t->ulid('product_id')->nullable();                // Odoo's history: a product the master data did not link
            $t->string('sku', 60)->nullable();
            $t->string('name', 250);
            $t->string('name_en', 250)->nullable();
            $t->decimal('qty', 14, 3);
            $t->decimal('unit_price', 14, 2);                  // before VAT, rounded once
            $t->decimal('discount_pct', 5, 2)->default(0);
            $t->decimal('line_total', 16, 2);                  // after discount, before VAT
            $t->string('price_source', 30)->nullable();
            $t->integer('issued_qty')->default(0);
            $t->integer('pending_qty')->default(0);
            $t->integer('refunded_qty')->default(0);
            $t->decimal('unit_cost', 14, 4)->default(0);
            $t->decimal('cogs', 16, 2)->default(0);
            $t->json('extra')->nullable();
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->unique(['source_system', 'external_ref'], 'pos_order_lines_source_uq');
            $t->index(['order_id', 'line_no'], 'pos_order_lines_order_ix');
            $t->index(['product_id'], 'pos_order_lines_product_ix');
            $t->index(['refund_of_line_id'], 'pos_order_lines_refund_ix');
        });

        Schema::create('pos_order_line_lots', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('order_id');
            $t->ulid('line_id');
            $t->string('kind', 4);                             // out (sale) | back (refund)
            $t->ulid('movement_id');
            $t->string('movement_number', 40)->nullable();
            $t->ulid('warehouse_id');
            $t->ulid('bin_id');
            $t->ulid('batch_id')->nullable();
            $t->integer('qty');
            $t->integer('returned_qty')->default(0);
            $t->decimal('unit_cost', 14, 4)->default(0);
            $t->decimal('total_cost', 16, 2)->default(0);
            $t->ulid('from_lot_id')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->index(['line_id', 'kind'], 'pos_lots_line_ix');
            $t->index(['order_id'], 'pos_lots_order_ix');
        });

        Schema::create('pos_order_payments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('order_id');
            $t->integer('line_no');
            $t->ulid('method_id')->nullable();
            $t->string('method_code', 120)->nullable();
            $t->string('kind', 10);                            // the method's kind at the time
            $t->decimal('amount', 16, 2);                      // handed over (or paid back)
            $t->decimal('applied', 16, 2);                     // what settles the order (less change)
            $t->string('reference', 80)->nullable();
            $t->ulid('fin_payment_id')->nullable();            // the finance receipt RCT-
            $t->string('fin_payment_number', 40)->nullable();
            $t->json('extra')->nullable();
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->unique(['source_system', 'external_ref'], 'pos_order_payments_source_uq');
            $t->index(['order_id'], 'pos_order_payments_order_ix');
            $t->index(['method_id', 'kind'], 'pos_order_payments_method_ix');
        });

        Schema::create('pos_cash_moves', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('session_id');
            $t->string('direction', 3);                        // in | out
            $t->decimal('amount', 16, 2);
            $t->text('reason');
            $t->ulid('bank_account_id');                       // the other side: where the cash came from / went
            $t->ulid('journal_id')->nullable();
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->index(['session_id'], 'pos_cash_moves_session_ix');
        });

        Schema::create('pos_held_sales', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('session_id');
            $t->ulid('register_id');
            $t->ulid('user_id');
            $t->string('username')->nullable();
            $t->string('label', 120);
            $t->string('client_key', 64)->nullable();
            $t->json('payload');                               // {customer, lines: [{productId, qty, discountPct}]}
            $t->decimal('total', 16, 2)->nullable();
            $t->string('status', 10)->default('held');         // held | recalled | discarded
            $t->ulid('order_id')->nullable();
            $t->string('discard_reason', 250)->nullable();
            $t->dateTime('recalled_at', 3)->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->index(['session_id', 'status'], 'pos_held_session_ix');
        });

        $this->caseInsensitiveText();
        PermissionSync::ensure();   // page.pos · pos.sell · pos.manage with their default role grants, on a database that predates them
        PosBootstrap::ensure();     // the riyal coins and notes the cash is counted in
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * PostgreSQL only — a no-op on MySQL. The application compares text without regard to letter case
     * (2026_09_17_000000_pgsql_compatibility): the text and id columns of these tables get the same CITEXT type as the
     * tables they are joined with. Columns already converted report USER-DEFINED and are left alone.
     */
    private function caseInsensitiveText(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('CREATE EXTENSION IF NOT EXISTS citext');
        $marks = implode(', ', array_fill(0, count(self::TABLES), '?'));
        $columns = DB::select("SELECT table_name, column_name FROM information_schema.columns
            WHERE table_schema = current_schema() AND table_name IN ({$marks}) AND data_type IN ('character', 'character varying', 'text')
            ORDER BY table_name, ordinal_position", self::TABLES);
        $byTable = [];
        foreach ($columns as $c) {
            $byTable[$c->table_name][] = sprintf('ALTER COLUMN "%1$s" TYPE citext USING rtrim("%1$s")::citext', $c->column_name);
        }
        foreach ($byTable as $table => $changes) {
            DB::statement(sprintf('ALTER TABLE "%s" %s', $table, implode(', ', $changes)));
        }
    }
};
