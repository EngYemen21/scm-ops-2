<?php

use App\Insights\Support\AlertRules;
use App\Support\PermissionSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «العلامات والصلاحية والتحليلات» (docs/odoo/WAVE4.md §Q6): brand owners with their products, categories and
 * franchisees; the alerts of their dashboards and the rules' thresholds; expiry alert levels with their recipients and
 * the log of what was announced; the copy of Odoo's customer invoices that gives the dashboards their history; the
 * section's small settings.
 *
 * Records that came from Odoo carry `source_system = 'odoo'` + `external_ref`. Nothing here is deleted by the
 * application: an owner is archived, a franchisee stopped, a level made inactive, an alert read. Only the two
 * `ins_odoo_*` tables — a derived copy of the mirror — follow Odoo's deletions.
 */
return new class extends Migration
{
    private const TABLES = ['ins_settings', 'ins_odoo_sale_lines', 'ins_odoo_invoices', 'ins_expiry_log', 'ins_expiry_level_users', 'ins_expiry_levels', 'ins_brand_alerts', 'ins_alert_rules',
        'ins_franchisees', 'ins_brand_owner_categories', 'ins_brand_owner_products', 'ins_brand_owners'];

    public function up(): void
    {
        Schema::create('ins_brand_owners', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('name', 190);
            $t->string('brand_name', 120)->nullable();       // products whose `brand` is this name belong to the owner
            $t->ulid('user_id')->nullable();                 // the OPS user who IS the owner (one owner per user) …
            $t->string('user_name', 190)->nullable();        // … or the name Odoo gives when that person has no OPS user
            $t->ulid('contact_customer_id')->nullable();
            $t->string('contact_name', 190)->nullable();
            $t->string('state', 12)->default('draft');       // draft | active | suspended | archived
            $t->date('date_start')->nullable();
            $t->boolean('allow_invoice')->default(true);
            $t->boolean('allow_payment')->default(false);
            $t->boolean('allow_return')->default(true);
            $t->boolean('allow_margin')->default(false);
            $t->text('notes')->nullable();
            $t->boolean('active')->default(true);
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 60)->nullable();
            $t->dateTime('source_updated_at', 3)->nullable();
            $t->string('created_by')->nullable();
            $t->string('updated_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['user_id'], 'ins_owners_user_uq');
            $t->unique(['source_system', 'external_ref'], 'ins_owners_source_uq');
            $t->index(['state', 'name'], 'ins_owners_state_ix');
        });

        Schema::create('ins_brand_owner_products', function (Blueprint $t) {
            $t->ulid('owner_id');
            $t->ulid('product_id');
            $t->primary(['owner_id', 'product_id']);
            $t->index(['product_id'], 'ins_owner_products_product_ix');
        });

        Schema::create('ins_brand_owner_categories', function (Blueprint $t) {
            $t->ulid('owner_id');
            $t->ulid('category_id');
            $t->primary(['owner_id', 'category_id']);
        });

        Schema::create('ins_franchisees', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('owner_id');
            $t->ulid('customer_id');
            $t->string('region', 120)->nullable();
            $t->string('city', 120)->nullable();
            $t->date('date_start')->nullable();
            $t->decimal('monthly_target', 16, 2)->default(0);
            $t->string('currency', 3)->default('SAR');
            $t->string('status', 10)->default('active');     // active | weak | inactive | stopped
            $t->boolean('active')->default(true);
            $t->text('notes')->nullable();
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 60)->nullable();
            $t->string('created_by')->nullable();
            $t->string('updated_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['owner_id', 'customer_id'], 'ins_franchisees_owner_customer_uq');
            $t->unique(['source_system', 'external_ref'], 'ins_franchisees_source_uq');
            $t->index(['customer_id'], 'ins_franchisees_customer_ix');
        });

        Schema::create('ins_alert_rules', function (Blueprint $t) {
            $t->string('type', 30)->primary();
            $t->boolean('enabled')->default(true);
            $t->decimal('threshold', 12, 2)->default(0);
            $t->integer('window_size')->default(0);          // days or months, per type (App\Insights\Support\AlertRules)
            $t->string('severity', 10)->default('warning');
            $t->string('updated_by')->nullable();
            $t->dateTime('updated_at', 3)->nullable();
        });

        Schema::create('ins_brand_alerts', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('owner_id');
            $t->ulid('customer_id')->nullable();
            $t->ulid('product_id')->nullable();
            $t->string('alert_type', 30);
            $t->string('occurrence_key', 190);              // what it was raised for — once per owner
            $t->string('title_ar', 250);
            $t->string('title_en', 250)->nullable();
            $t->text('description_ar')->nullable();
            $t->text('description_en')->nullable();
            $t->string('severity', 10)->default('info');    // info | warning | danger | success
            $t->date('date')->nullable();
            $t->json('data')->nullable();                    // the figures it was raised on
            $t->boolean('is_read')->default(false);
            $t->dateTime('read_at', 3)->nullable();
            $t->string('read_by')->nullable();
            $t->string('action_model', 60)->nullable();
            $t->string('action_ref', 60)->nullable();
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 60)->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->unique(['owner_id', 'occurrence_key'], 'ins_alerts_once_uq');
            $t->unique(['source_system', 'external_ref'], 'ins_alerts_source_uq');
            $t->index(['owner_id', 'is_read', 'date'], 'ins_alerts_owner_ix');
        });

        Schema::create('ins_expiry_levels', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('name', 120);
            $t->integer('days_before_expiry');
            $t->string('notification_type', 8)->default('inapp'); // inapp | email | both
            $t->integer('sequence')->default(10);
            $t->smallInteger('color')->default(0);
            $t->boolean('active')->default(true);
            $t->json('recipients_kept')->nullable();         // Odoo recipients without an OPS user (names only)
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 60)->nullable();
            $t->string('created_by')->nullable();
            $t->string('updated_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'ins_levels_source_uq');
        });

        Schema::create('ins_expiry_level_users', function (Blueprint $t) {
            $t->ulid('level_id');
            $t->ulid('user_id');
            $t->primary(['level_id', 'user_id']);
        });

        Schema::create('ins_expiry_log', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('level_id');
            $t->ulid('batch_id')->nullable();                // the OPS batch (null: an Odoo lot OPS does not hold)
            $t->ulid('product_id')->nullable();
            $t->string('batch_no', 120);
            $t->dateTime('expiry_date', 3)->nullable();
            $t->integer('qty_on_hand')->default(0);
            $t->integer('days_left')->nullable();
            $t->dateTime('notified_at', 3);
            $t->string('notification_type', 8)->default('inapp');
            $t->json('recipients')->nullable();              // OPS user ids
            $t->string('email_status', 10)->default('none'); // none | skipped (no mail provider) | odoo (sent by Odoo)
            $t->string('odoo_lot_ref', 40)->nullable();
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 60)->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->unique(['level_id', 'batch_id'], 'ins_explog_once_uq');
            $t->unique(['source_system', 'external_ref'], 'ins_explog_source_uq');
            $t->index(['notified_at'], 'ins_explog_at_ix');
        });

        Schema::create('ins_odoo_invoices', function (Blueprint $t) {
            $t->unsignedBigInteger('odoo_id')->primary();
            $t->string('number', 64);
            $t->string('move_type', 16);                     // out_invoice | out_refund
            $t->string('state', 12);
            $t->string('payment_state', 16)->nullable();
            $t->string('partner_ref', 40)->nullable();       // the commercial partner's Odoo id
            $t->string('partner_name', 190)->nullable();
            $t->date('invoice_date')->nullable();
            $t->date('due_date')->nullable();
            $t->decimal('amount_untaxed', 16, 2)->default(0);
            $t->decimal('amount_total', 16, 2)->default(0);
            $t->decimal('residual', 16, 2)->default(0);
            $t->string('currency', 8)->nullable();
            $t->dateTime('updated_at', 3)->nullable();
            $t->index(['partner_ref', 'invoice_date'], 'ins_oinv_partner_ix');
            $t->index(['invoice_date'], 'ins_oinv_date_ix');
            $t->index(['due_date'], 'ins_oinv_due_ix');
        });

        Schema::create('ins_odoo_sale_lines', function (Blueprint $t) {
            $t->unsignedBigInteger('odoo_id')->primary();
            $t->unsignedBigInteger('move_id');
            $t->string('move_type', 16);
            $t->string('state', 12);
            $t->date('invoice_date')->nullable();
            $t->string('partner_ref', 40)->nullable();
            $t->string('product_ref', 40);
            $t->string('product_name', 190)->nullable();
            $t->decimal('qty', 16, 3)->default(0);           // negative on a credit note
            $t->decimal('amount', 16, 2)->default(0);        // before VAT, negative on a credit note
            $t->index(['product_ref', 'invoice_date'], 'ins_oline_product_ix');
            $t->index(['partner_ref', 'invoice_date'], 'ins_oline_partner_ix');
            $t->index(['move_id'], 'ins_oline_move_ix');
        });

        Schema::create('ins_settings', function (Blueprint $t) {
            $t->string('key', 60)->primary();
            $t->json('value')->nullable();
            $t->string('updated_by')->nullable();
            $t->dateTime('updated_at', 3)->nullable();
        });

        foreach (AlertRules::DEFAULTS as $type => $d) {
            DB::table('ins_alert_rules')->insert(['type' => $type, 'enabled' => true, 'threshold' => $d['threshold'], 'window_size' => $d['window'], 'severity' => $d['severity'], 'updated_at' => now()]);
        }

        $this->caseInsensitiveText();
        PermissionSync::ensure();   // page.insights · brandowner.manage · expiryalert.manage with their default role grants
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * PostgreSQL only — a no-op on MySQL. Text and id columns get the CITEXT type of the tables they are joined with
     * (2026_09_17_000000_pgsql_compatibility). Columns already converted report USER-DEFINED and are left alone.
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
