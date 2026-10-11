<?php

use App\Finance\FinanceBootstrap;
use App\Support\PermissionSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance core (docs/finance/ARCHITECTURE.ar.md): double-entry ledger (accounts, periods, cost centers, journals),
 * sub-ledgers (customer invoices, payments/receipts with allocations, bank accounts, expenses, fixed assets, budgets)
 * and the costing columns the inventory engine needs (unit cost per movement, moving average per product).
 * Nothing here posts an opening balance: that is an explicit, approved step (see the runbook).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_accounts', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('code', 20)->unique();
            $t->string('name_ar');
            $t->string('name_en');
            $t->string('type', 20);                    // asset | liability | equity | revenue | expense
            $t->string('subtype', 40)->nullable();     // bank, cash, receivable, inventory, fixed_asset, payable, tax, cogs, opex …
            $t->ulid('parent_id')->nullable();
            $t->boolean('is_postable')->default(true); // headers are not postable
            $t->string('role', 40)->nullable()->unique(); // control role the posting rules resolve (ar, ap, inventory, grni …)
            $t->boolean('active')->default(true);
            $t->integer('sort')->default(0);
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->index(['type', 'code'], 'fin_accounts_type_code_ix');
        });

        Schema::create('fin_periods', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('code', 7)->unique();           // YYYY-MM
            $t->integer('year');
            $t->integer('month');
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('status', 10)->default('open'); // open | closed
            $t->string('closed_by')->nullable();
            $t->dateTime('closed_at', 3)->nullable();
            $t->string('reopened_by')->nullable();
            $t->dateTime('reopened_at', 3)->nullable();
            $t->text('notes')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
        });

        Schema::create('fin_cost_centers', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('code', 30)->unique();
            $t->string('name_ar');
            $t->string('name_en');
            $t->string('kind', 20)->default('admin');  // warehouse | fleet | vehicle | sales | admin
            $t->ulid('warehouse_id')->nullable();
            $t->ulid('vehicle_id')->nullable();
            $t->boolean('active')->default(true);
            $t->dateTime('created_at', 3)->useCurrent();
        });

        Schema::create('fin_journals', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('number')->unique();            // JV-YYYY-NNNNN
            $t->date('journal_date');
            $t->ulid('period_id');
            $t->string('status', 10)->default('posted'); // draft | posted | reversed
            $t->string('kind', 12)->default('auto');     // auto | manual | reversal | opening
            $t->string('source_type', 40)->nullable();   // GoodsReceipt, FulfillmentOrder, ProofOfDelivery, SupplierInvoice, Payment …
            $t->string('source_id')->nullable();
            $t->string('source_number')->nullable();
            $t->string('event_key', 160)->nullable()->unique(); // idempotency: one journal per accounting event
            $t->string('memo', 500)->nullable();
            $t->decimal('total_debit', 16, 2)->default(0);
            $t->decimal('total_credit', 16, 2)->default(0);
            $t->string('created_by_id')->nullable();
            $t->string('created_by')->nullable();
            $t->string('approved_by_id')->nullable();   // manual journals: maker ≠ checker
            $t->string('approved_by')->nullable();
            $t->dateTime('approved_at', 3)->nullable();
            $t->dateTime('posted_at', 3)->nullable();
            $t->ulid('reversal_of_id')->nullable();     // this journal reverses that one
            $t->ulid('reversed_by_id')->nullable();     // that journal reversed this one
            $t->text('reversal_reason')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->index(['journal_date'], 'fin_journals_date_ix');
            $t->index(['source_type', 'source_id'], 'fin_journals_source_ix');
            $t->index(['period_id', 'status'], 'fin_journals_period_ix');
        });

        Schema::create('fin_journal_lines', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('journal_id');
            $t->integer('line_no');
            $t->ulid('account_id');
            $t->decimal('debit', 16, 2)->default(0);
            $t->decimal('credit', 16, 2)->default(0);
            $t->string('description', 500)->nullable();
            $t->ulid('cost_center_id')->nullable();
            $t->string('partner_type', 20)->nullable();  // customer | supplier
            $t->ulid('partner_id')->nullable();
            $t->ulid('product_id')->nullable();
            $t->ulid('warehouse_id')->nullable();
            $t->ulid('vehicle_id')->nullable();
            $t->ulid('trip_id')->nullable();
            $t->string('reference_type', 40)->nullable();
            $t->string('reference_id')->nullable();
            $t->unique(['journal_id', 'line_no'], 'fin_journal_lines_line_uq');
            $t->index(['account_id', 'journal_id'], 'fin_journal_lines_account_ix');
            $t->index(['partner_type', 'partner_id'], 'fin_journal_lines_partner_ix');
            $t->index(['cost_center_id'], 'fin_journal_lines_cc_ix');
        });

        Schema::create('fin_bank_accounts', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('code', 20)->unique();
            $t->string('name_ar');
            $t->string('name_en');
            $t->string('kind', 10)->default('bank');    // bank | cash
            $t->string('bank_name')->nullable();
            $t->string('iban', 40)->nullable();
            $t->ulid('account_id');                      // GL account
            $t->string('currency', 3)->default('SAR');
            $t->boolean('active')->default(true);
            $t->dateTime('created_at', 3)->useCurrent();
        });

        Schema::create('fin_customer_invoices', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('number')->unique();             // CI-YYYY-NNNNN (invoice) / CN-YYYY-NNNNN (credit note)
            $t->string('kind', 12)->default('invoice'); // invoice | credit_note
            $t->ulid('customer_id');
            $t->ulid('so_id')->nullable();
            $t->ulid('fo_id')->nullable();
            $t->ulid('pod_id')->nullable();
            $t->ulid('return_id')->nullable();
            $t->ulid('credit_of_id')->nullable();       // credit note → the invoice it credits
            $t->date('invoice_date');
            $t->date('due_date')->nullable();
            $t->string('status', 16)->default('issued'); // issued | partially_paid | paid | cancelled
            $t->decimal('subtotal', 16, 2)->default(0);
            $t->decimal('discount', 16, 2)->default(0);
            $t->double('vat_pct')->default(15);
            $t->decimal('vat_amount', 16, 2)->default(0);
            $t->decimal('total', 16, 2)->default(0);
            $t->decimal('paid_amount', 16, 2)->default(0);
            $t->decimal('cogs_total', 16, 2)->default(0);
            $t->string('currency', 3)->default('SAR');
            $t->string('payment_terms')->nullable();
            $t->text('notes')->nullable();
            $t->ulid('journal_id')->nullable();
            $t->ulid('cogs_journal_id')->nullable();
            $t->string('created_by')->nullable();
            $t->string('cancelled_by')->nullable();
            $t->dateTime('cancelled_at', 3)->nullable();
            $t->text('cancel_reason')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->index(['customer_id', 'status'], 'fin_ci_customer_ix');
            $t->index(['so_id'], 'fin_ci_so_ix');
            $t->index(['due_date'], 'fin_ci_due_ix');
        });

        Schema::create('fin_customer_invoice_lines', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('invoice_id');
            $t->integer('line_no');
            $t->ulid('so_line_id')->nullable();
            $t->ulid('product_id');
            $t->integer('qty');
            $t->decimal('price', 14, 2);
            $t->double('disc_pct')->default(0);
            $t->decimal('line_total', 16, 2);           // after discount, before VAT
            $t->decimal('unit_cost', 14, 4)->default(0);
            $t->decimal('cogs', 16, 2)->default(0);
            $t->unique(['invoice_id', 'line_no'], 'fin_ci_lines_line_uq');
            $t->index(['product_id'], 'fin_ci_lines_product_ix');
        });

        Schema::create('fin_payments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('number')->unique();             // RCT- (in) / PMT- (out)
            $t->string('direction', 3);                 // in | out
            $t->string('partner_type', 20);             // customer | supplier
            $t->ulid('partner_id');
            $t->date('payment_date');
            $t->decimal('amount', 16, 2);
            $t->decimal('allocated_amount', 16, 2)->default(0);
            $t->string('method', 12)->default('bank');  // bank | cash | cheque | card | wallet
            $t->ulid('bank_account_id');
            $t->string('reference', 80)->nullable();
            $t->text('notes')->nullable();
            $t->string('status', 10)->default('posted'); // posted | void
            $t->ulid('journal_id')->nullable();
            $t->string('created_by')->nullable();
            $t->string('voided_by')->nullable();
            $t->dateTime('voided_at', 3)->nullable();
            $t->text('void_reason')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->index(['partner_type', 'partner_id', 'status'], 'fin_payments_partner_ix');
            $t->index(['payment_date'], 'fin_payments_date_ix');
        });

        Schema::create('fin_payment_allocations', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('payment_id');
            $t->string('invoice_type', 20);             // customer | supplier
            $t->ulid('invoice_id');
            $t->decimal('amount', 16, 2);
            $t->dateTime('created_at', 3)->useCurrent();
            $t->index(['invoice_type', 'invoice_id'], 'fin_pay_alloc_invoice_ix');
            $t->index(['payment_id'], 'fin_pay_alloc_payment_ix');
        });

        Schema::create('fin_expenses', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('number')->unique();             // EXP-YYYY-NNNNN
            $t->date('expense_date');
            $t->ulid('account_id');                      // expense account
            $t->decimal('amount', 16, 2);                // before VAT
            $t->double('vat_pct')->default(0);
            $t->decimal('vat_amount', 16, 2)->default(0);
            $t->decimal('total', 16, 2);
            $t->string('settlement', 10)->default('bank'); // bank | supplier | accrued
            $t->ulid('bank_account_id')->nullable();
            $t->ulid('supplier_id')->nullable();
            $t->ulid('cost_center_id')->nullable();
            $t->ulid('vehicle_id')->nullable();
            $t->ulid('trip_id')->nullable();
            $t->string('description', 500);
            $t->string('reference', 80)->nullable();
            $t->string('status', 10)->default('posted'); // posted | void
            $t->ulid('journal_id')->nullable();
            $t->string('created_by')->nullable();
            $t->string('voided_by')->nullable();
            $t->dateTime('voided_at', 3)->nullable();
            $t->text('void_reason')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->index(['expense_date'], 'fin_expenses_date_ix');
            $t->index(['vehicle_id'], 'fin_expenses_vehicle_ix');
        });

        Schema::create('fin_fixed_assets', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('code', 30)->unique();           // FA-YYYY-NNNNN
            $t->string('name_ar');
            $t->string('name_en');
            $t->string('category', 20)->default('vehicle'); // vehicle | equipment | other
            $t->ulid('vehicle_id')->nullable();
            $t->date('acquired_on');
            $t->decimal('cost', 16, 2);
            $t->decimal('salvage_value', 16, 2)->default(0);
            $t->integer('useful_life_months');
            $t->string('method', 16)->default('straight_line');
            $t->decimal('accumulated_depreciation', 16, 2)->default(0);
            $t->date('depreciated_through')->nullable(); // last month depreciated (end of month)
            $t->ulid('asset_account_id');
            $t->ulid('accum_account_id');
            $t->ulid('expense_account_id');
            $t->ulid('cost_center_id')->nullable();
            $t->string('status', 10)->default('active'); // active | disposed
            $t->date('disposed_on')->nullable();
            $t->decimal('disposal_proceeds', 16, 2)->nullable();
            $t->ulid('acquisition_journal_id')->nullable();
            $t->text('notes')->nullable();
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
        });

        Schema::create('fin_depreciation_entries', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('asset_id');
            $t->string('period_code', 7);
            $t->decimal('amount', 16, 2);
            $t->ulid('journal_id');
            $t->dateTime('created_at', 3)->useCurrent();
            $t->unique(['asset_id', 'period_code'], 'fin_dep_asset_period_uq');
        });

        Schema::create('fin_budgets', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('account_id');
            $t->string('period_code', 7);
            $t->ulid('cost_center_id')->nullable();
            $t->decimal('amount', 16, 2);
            $t->string('updated_by')->nullable();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['account_id', 'period_code', 'cost_center_id'], 'fin_budgets_uq');
        });

        // costing on the existing inventory ledger
        Schema::table('inventory_movements', function (Blueprint $t) {
            $t->decimal('unit_cost', 14, 4)->nullable();    // cost per base unit applied to this movement
            $t->decimal('total_cost', 16, 2)->nullable();   // qty × unit_cost (null = not valued: internal move)
            $t->ulid('journal_id')->nullable();             // the journal this movement posted (valued movements only)
        });
        Schema::table('products', function (Blueprint $t) {
            $t->decimal('avg_cost', 14, 4)->nullable();     // moving weighted average (company level); null = never valued
            $t->dateTime('avg_cost_at', 3)->nullable();
        });
        Schema::table('supplier_invoices', function (Blueprint $t) {
            $t->decimal('paid_amount', 16, 2)->default(0);
            $t->ulid('journal_id')->nullable();
        });
        Schema::table('fuel_records', function (Blueprint $t) {
            $t->ulid('journal_id')->nullable();
            $t->ulid('trip_id')->nullable();
        });
        Schema::table('maintenance_orders', function (Blueprint $t) {
            $t->ulid('journal_id')->nullable();
        });
        Schema::table('customers', function (Blueprint $t) {
            $t->string('payment_terms_days')->nullable(); // e.g. 30 → due date of auto invoices; null = cash
        });

        // existing paid supplier invoices were paid in full before partial payments existed
        DB::table('supplier_invoices')->where('status', 'paid')->update(['paid_amount' => DB::raw('total')]);

        FinanceBootstrap::ensure();
        PermissionSync::ensure();
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn(['payment_terms_days']));
        Schema::table('maintenance_orders', fn (Blueprint $t) => $t->dropColumn(['journal_id']));
        Schema::table('fuel_records', fn (Blueprint $t) => $t->dropColumn(['journal_id', 'trip_id']));
        Schema::table('supplier_invoices', fn (Blueprint $t) => $t->dropColumn(['paid_amount', 'journal_id']));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['avg_cost', 'avg_cost_at']));
        Schema::table('inventory_movements', fn (Blueprint $t) => $t->dropColumn(['unit_cost', 'total_cost', 'journal_id']));
        foreach (['fin_budgets', 'fin_depreciation_entries', 'fin_fixed_assets', 'fin_expenses', 'fin_payment_allocations', 'fin_payments', 'fin_customer_invoice_lines', 'fin_customer_invoices', 'fin_bank_accounts', 'fin_journal_lines', 'fin_journals', 'fin_cost_centers', 'fin_periods', 'fin_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
