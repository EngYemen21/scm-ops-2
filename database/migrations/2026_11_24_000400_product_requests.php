<?php

use App\Support\PermissionSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «طلبات الأصناف الجديدة» — the way a product enters the catalogue (docs/odoo/WAVE3.md, P4): somebody asks for a
 * product, a reviewer checks it, and on approval the product is created in the OPS catalogue and the request keeps the
 * link to it. Replaces Odoo's custom modules `b2b.new.product` (the staff flow) and `hub.product.request` (what a
 * customer asks for through the portal) and holds their whole history: a record that came from Odoo carries
 * `source_system = 'odoo'`, `source_model` (which of the two models) and `external_ref` (its id there).
 *
 * Nothing here is deleted: a request is cancelled or rejected with a reason, a picture is taken off a request and
 * stays, and every step is one line of the request's history (`product_request_events`).
 *
 * A person is kept three ways on purpose: the OPS user (`*_id`) when there is one, the name as it was (`*_name`) and,
 * for a person of Odoo, the login there (`*_login`). A category, a supplier, a unit and a customer are kept the same
 * way: the OPS record when it is one, otherwise the name as it was given.
 */
return new class extends Migration
{
    private const TABLES = ['product_requests', 'product_request_events', 'product_request_pictures'];

    public function up(): void
    {
        Schema::create('product_requests', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('number', 40)->unique();                 // PREQ-2026-00001 · NPR-00042 / PRQ-2026-00007 (Odoo's own reference)
            $t->string('request_type', 12)->default('new');     // new: the catalogue lacks it | existing: a customer asks for a product the catalogue has
            $t->string('status', 16)->default('draft');         // draft | submitted | in_review | returned | approved | rejected | cancelled
            $t->dateTime('status_at', 3)->nullable();           // when it entered that status: the age of what waits
            // ── the product that is asked for
            $t->string('name_ar');
            $t->string('name_en')->nullable();
            $t->string('name_key', 250)->nullable();            // the Arabic name as duplicate detection compares it (App\ProductRequests\Support\NameKey)
            $t->string('name_en_key', 250)->nullable();
            $t->string('brand', 120)->nullable();
            $t->ulid('category_id')->nullable();                // the OPS category, when it is one …
            $t->string('category_name')->nullable();            // … or the category as it was named (a customer's hint, an Odoo category OPS does not have)
            $t->ulid('supplier_id')->nullable();
            $t->string('supplier_name')->nullable();
            $t->ulid('uom_id')->nullable();                     // the base unit: stock is kept in it
            $t->string('uom_name', 120)->nullable();
            $t->string('pack_text', 120)->nullable();           // the pack as people write it: 125ml*18pcs
            $t->integer('pack_qty')->nullable();                // base units in one purchase pack
            $t->ulid('pack_uom_id')->nullable();
            $t->decimal('cost_price', 14, 4)->nullable();       // proposed purchase cost
            $t->decimal('sale_price', 14, 4)->nullable();       // proposed selling price
            $t->string('currency', 3)->default('SAR');
            $t->string('barcode', 64)->nullable();
            $t->string('proposed_sku', 64)->nullable();         // the code asked for; the reviewer decides the code at approval
            $t->string('storage_class', 12)->nullable();        // ambient | chilled | frozen
            $t->integer('shelf_life_days')->nullable();
            $t->boolean('tracks_expiry')->default(false);
            $t->double('weight_kg')->nullable();                // of one stock unit; needed, with the dimensions, before the product is created
            $t->double('length_cm')->nullable();
            $t->double('width_cm')->nullable();
            $t->double('height_cm')->nullable();
            $t->text('reason')->nullable();                     // why the product is needed
            $t->ulid('customer_id')->nullable();                // the customer who asked for it
            $t->string('customer_name')->nullable();
            // ── who
            $t->ulid('requested_by_id')->nullable();
            $t->string('requested_by_name')->nullable();
            $t->string('requested_by_login', 190)->nullable();
            $t->ulid('reviewer_id')->nullable();                // who took it into review
            $t->string('reviewer_name')->nullable();
            $t->ulid('decided_by_id')->nullable();              // who approved or rejected it
            $t->string('decided_by_name')->nullable();
            $t->dateTime('submitted_at', 3)->nullable();
            $t->dateTime('review_started_at', 3)->nullable();
            $t->dateTime('decided_at', 3)->nullable();
            $t->integer('returns_count')->default(0);           // how many times it was sent back for correction
            $t->text('return_reason')->nullable();              // the correction last asked for
            $t->text('reject_reason')->nullable();
            $t->text('cancel_reason')->nullable();
            // ── the product it became
            $t->ulid('product_id')->nullable();
            $t->string('product_sku', 64)->nullable();
            $t->string('product_link', 12)->nullable();         // created (by the approval here) | restored (Odoo's product, already an OPS product) | existing (the product asked about, or the one it duplicates) | manual
            // ── where it came from
            $t->string('source_system', 20)->nullable();        // 'odoo' | null (raised in OPS)
            $t->string('source_model', 60)->nullable();         // b2b.new.product | hub.product.request
            $t->string('external_ref', 60)->nullable();         // the Odoo id, as text
            $t->string('source_number', 60)->nullable();        // Odoo's own reference as it prints it (NPR/00042)
            $t->string('source_state', 40)->nullable();         // Odoo's own word for the state (sales_approval, pending…)
            $t->string('source_product_ref', 60)->nullable();   // the Odoo product (product.product id) it became or asks about — linked here once that product is an OPS product
            $t->json('source_meta')->nullable();                // what Odoo holds for it and OPS has no column for
            $t->dateTime('source_updated_at', 3)->nullable();
            $t->dateTime('source_deleted_at', 3)->nullable();
            $t->ulid('created_by_id')->nullable();
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'source_model', 'external_ref'], 'product_requests_source_uq');
            $t->index(['status', 'status_at'], 'product_requests_status_ix');
            $t->index(['requested_by_id', 'status'], 'product_requests_requester_ix');
            $t->index(['created_by_id', 'status'], 'product_requests_creator_ix');
            $t->index(['name_key'], 'product_requests_name_ix');
            $t->index(['name_en_key'], 'product_requests_name_en_ix');
            $t->index(['barcode'], 'product_requests_barcode_ix');
            $t->index(['product_id'], 'product_requests_product_ix');
            $t->index(['source_product_ref'], 'product_requests_source_product_ix');
            $t->index(['category_id', 'status'], 'product_requests_category_ix');
            $t->index(['supplier_id'], 'product_requests_supplier_ix');
            $t->index(['customer_id'], 'product_requests_customer_ix');
            $t->index(['created_at'], 'product_requests_created_ix');
        });

        // the story of a request in the order it happened: raised, edited, sent, taken into review, sent back, corrected,
        // approved / rejected / cancelled, comments, pictures, the product it was linked to
        Schema::create('product_request_events', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('request_id');
            $t->string('kind', 24);                             // App\ProductRequests\Support\Flow::EVENTS
            $t->string('from_status', 16)->nullable();
            $t->string('to_status', 16)->nullable();
            $t->ulid('user_id')->nullable();
            $t->string('user_name')->nullable();
            $t->text('note')->nullable();
            $t->json('meta')->nullable();
            $t->string('source_system', 20)->nullable();        // 'odoo': it happened in Odoo and was read from the copy
            $t->dateTime('at', 3)->useCurrent();
            $t->index(['request_id', 'at'], 'product_request_events_request_ix');
        });

        // pictures of the product. A picture added in OPS lives in the file store (FileStore: the object store, or
        // `stored_files`); a picture of Odoo is listed here and its content is read from the Odoo file archive
        // (`odoo_files`) once it is kept there — it is linked, never copied.
        Schema::create('product_request_pictures', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('request_id');
            $t->string('file_name');
            $t->string('mime', 150)->nullable();
            $t->bigInteger('size')->default(0);
            $t->string('storage_key')->nullable();
            $t->string('status', 20)->default('stored');        // stored | uploaded (FileStore) | odoo
            $t->ulid('uploaded_by_id')->nullable();
            $t->string('uploaded_by')->nullable();
            $t->dateTime('removed_at', 3)->nullable();          // taken off the request (the row and the file stay)
            $t->string('removed_by')->nullable();
            $t->string('remove_reason', 500)->nullable();
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 60)->nullable();         // ir.attachment id in Odoo
            $t->string('source_field', 60)->nullable();         // the field of Odoo's record the file fills (product_image); null: attached to its conversation
            $t->dateTime('created_at', 3)->useCurrent();
            $t->unique(['source_system', 'external_ref'], 'product_request_pictures_source_uq');
            $t->index(['request_id'], 'product_request_pictures_request_ix');
        });

        // PostgreSQL only: text compares case-insensitively everywhere in this schema (2026_09_17_000000_pgsql_compatibility).
        // Done before the keys below so both sides of every key have the same type.
        if (DB::getDriverName() === 'pgsql') {
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

        foreach (['product_request_events' => 'product_request_events', 'product_request_pictures' => 'product_request_pictures'] as $table => $short) {
            Schema::table($table, function (Blueprint $t) use ($short) {
                $t->foreign(['request_id'], "{$short}_request_fk")->references(['id'])->on('product_requests')->cascadeOnDelete()->deferrable();
            });
        }

        // page.preq · preq.create · preq.review are declared in config/scm.php: a live database gets them (with their
        // default role grants) here, a fresh one from the seeder — the same call, and it adds nothing twice
        PermissionSync::ensure();
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
