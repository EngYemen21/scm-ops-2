<?php

use App\Support\PermissionSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «قوائم الأسعار» — price lists (docs/odoo/WAVE3.md, P1): customer price lists with their rules, the list of each
 * customer, the list price of each product (what a discount starts from and what a customer without a rule pays), and
 * supplier prices per supplier and product.
 *
 * Records that came from Odoo carry `source_system = 'odoo'` + `external_ref` (the Odoo id): while the section follows
 * Odoo they are Odoo's and are read-only here. Nothing is deleted: a list, a rule or a supplier price that is no longer
 * wanted is archived (`active = false`) and stays readable. Prices keep four decimals — Odoo's figures are kept as
 * they are, a document rounds when it takes one.
 */
return new class extends Migration
{
    private const TABLES = ['pricing_sync_marks', 'supplier_prices', 'product_list_prices', 'price_list_customers', 'price_list_rules', 'price_lists'];

    public function up(): void
    {
        Schema::create('price_lists', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('code', 40)->unique();                 // PRL-YYYY-NNNNN
            $t->string('name', 190);                          // as people call it (two lists may carry one name: the code tells them apart)
            $t->string('currency', 3)->default('SAR');
            $t->boolean('is_default')->default(false);        // the list of every customer that has none of its own (one at most)
            $t->integer('sequence')->default(0);
            $t->date('valid_from')->nullable();               // outside its validity a list is not used
            $t->date('valid_to')->nullable();
            $t->text('note')->nullable();
            $t->boolean('active')->default(true);
            $t->json('extra')->nullable();                    // what Odoo holds and OPS has no column for
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->dateTime('source_updated_at', 3)->nullable();
            $t->dateTime('source_deleted_at', 3)->nullable(); // it no longer exists in the system it came from
            $t->string('created_by')->nullable();
            $t->string('updated_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'price_lists_source_uq');
            $t->index(['active', 'sequence'], 'price_lists_active_ix');
        });

        // A rule gives the price of a product, of a category (with its sub-categories) or of everything, from a
        // quantity and inside a period. Which rule wins: the most specific, then the highest minimum quantity, then the
        // nearest category, then the newest (`seq`).
        Schema::create('price_list_rules', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('price_list_id');
            $t->string('applies_to', 10);                     // product | category | all
            $t->ulid('product_id')->nullable();
            $t->ulid('category_id')->nullable();
            $t->decimal('min_qty', 14, 3)->default(0);
            $t->date('valid_from')->nullable();
            $t->date('valid_to')->nullable();
            $t->string('compute', 10)->default('fixed');      // fixed | percent | formula
            $t->decimal('fixed_price', 16, 4)->nullable();    // fixed: the price itself
            $t->decimal('percent', 9, 4)->nullable();         // percent: discount on the base (negative = a mark-up)
            $t->string('base', 10)->default('list');          // what percent / formula start from: list | cost | pricelist
            $t->ulid('base_list_id')->nullable();             // base = pricelist: the price on that other list
            $t->decimal('discount_pct', 9, 4)->default(0);    // formula: base − discount %
            $t->decimal('surcharge', 16, 4)->default(0);      // formula: + a fixed amount
            $t->decimal('round_to', 16, 4)->default(0);       // formula: rounded to a multiple of this (0 = not rounded)
            $t->decimal('min_margin', 16, 4)->default(0);     // formula: never below base + this (0 = no floor)
            $t->decimal('max_margin', 16, 4)->default(0);     // formula: never above base + this (0 = no ceiling)
            $t->bigInteger('seq')->default(0);                // between equal rules the higher wins (the newer one)
            $t->boolean('active')->default(true);
            $t->text('note')->nullable();
            $t->string('pending_label', 250)->nullable();     // a rule of Odoo whose product / category is not in OPS yet: what it waits for
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->dateTime('source_updated_at', 3)->nullable();
            $t->dateTime('source_deleted_at', 3)->nullable();
            $t->string('created_by')->nullable();
            $t->string('updated_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'price_list_rules_source_uq');
            $t->index(['price_list_id', 'active', 'applies_to'], 'price_list_rules_list_ix');
            $t->index(['product_id', 'active'], 'price_list_rules_product_ix');
            $t->index(['category_id'], 'price_list_rules_category_ix');
            $t->index(['base_list_id'], 'price_list_rules_base_ix');
        });

        // the list of a customer — one at most; a customer without one is priced by the default list
        Schema::create('price_list_customers', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('customer_id');
            $t->ulid('price_list_id');
            $t->string('basis', 10)->default('manual');       // manual (a person, here) | partner (Odoo's own field) | order (the customer's latest Odoo order)
            $t->string('basis_ref', 60)->nullable();          // basis = order: the number of that order …
            $t->bigInteger('basis_odoo_id')->nullable();      // … its Odoo id …
            $t->dateTime('basis_at', 3)->nullable();          // … and its date
            $t->text('note')->nullable();
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();       // the Odoo partner
            $t->string('created_by')->nullable();
            $t->string('updated_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['customer_id'], 'price_list_customers_customer_uq');
            $t->index(['price_list_id'], 'price_list_customers_list_ix');
        });

        // the list (public) price of a product: the base of a discount and the price where no rule applies
        Schema::create('product_list_prices', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('product_id');
            $t->decimal('price', 16, 4);
            $t->string('currency', 3)->default('SAR');
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();       // the Odoo product template
            $t->dateTime('source_updated_at', 3)->nullable();
            $t->string('created_by')->nullable();
            $t->string('updated_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['product_id'], 'product_list_prices_product_uq');
            $t->index(['source_system', 'external_ref'], 'product_list_prices_source_ix');
        });

        // what a supplier charges for a product, from a quantity and inside a period
        Schema::create('supplier_prices', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('supplier_id')->nullable();              // null: a price of Odoo whose supplier is not in OPS yet
            $t->ulid('product_id')->nullable();               // null: … or whose product is not
            $t->decimal('price', 16, 4);
            $t->string('currency', 3)->default('SAR');
            $t->decimal('discount_pct', 9, 4)->default(0);
            $t->decimal('min_qty', 14, 3)->default(0);
            $t->integer('lead_days')->nullable();             // null = not stated: the supplier's own lead time stands
            $t->date('valid_from')->nullable();
            $t->date('valid_to')->nullable();
            $t->string('supplier_sku', 120)->nullable();      // the supplier's own code and name of the product
            $t->string('supplier_product_name', 250)->nullable();
            $t->integer('sequence')->default(0);              // the order of a product's suppliers (lower first)
            $t->boolean('active')->default(true);
            $t->text('note')->nullable();
            $t->string('pending_label', 250)->nullable();     // what it waits for (the supplier / the product as Odoo names it)
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->dateTime('source_updated_at', 3)->nullable();
            $t->dateTime('source_deleted_at', 3)->nullable();
            $t->string('created_by')->nullable();
            $t->string('updated_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'supplier_prices_source_uq');
            $t->index(['product_id', 'active'], 'supplier_prices_product_ix');
            $t->index(['supplier_id', 'active'], 'supplier_prices_supplier_ix');
        });

        // How far the section has read the Odoo models it follows outside the change feed (the feed of products and
        // partners belongs to the master data; a product's list price and a partner's price list are read from the
        // copy by "changed since").
        Schema::create('pricing_sync_marks', function (Blueprint $t) {
            $t->string('source', 60)->primary();
            $t->dateTime('seen_at', 3)->nullable();
            $t->bigInteger('seen_id')->default(0);
            $t->dateTime('updated_at', 3)->nullable();
        });

        $this->caseInsensitiveText();
        PermissionSync::ensure();   // page.pricing · pricelist.manage · supplierprice.manage with their default role grants, on a database that predates them
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
