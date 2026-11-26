<?php

use App\Support\PermissionSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «الاعتمادات» — generic approval requests (docs/odoo/WAVE2.md §5): categories that say who approves, in which order,
 * how many approvals are needed and which fields a request must carry; requests with their approver lines, items,
 * attachments and timeline. Replaces Odoo's Approvals app and holds its whole history: a record that came from Odoo
 * carries `source_system = 'odoo'` and `external_ref` = its Odoo id (App\Approvals\OdooApprovalsProjection).
 *
 * A person is kept three ways on purpose: the OPS user (`*_id`) when there is one, the name as it was (`*_name`), and
 * for a person of Odoo the login there (`*_login`) — the identity an OPS user created later is recognised by.
 *
 * Not related to `po_approvals` / `pr_approvals` (the approval chains of purchase orders and requisitions).
 */
return new class extends Migration
{
    private const TABLES = [
        'approval_categories', 'approval_category_approvers', 'approval_requests', 'approval_request_approvers',
        'approval_request_items', 'approval_request_events', 'approval_attachments',
    ];

    public function up(): void
    {
        Schema::create('approval_categories', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('code', 40)->unique();                       // AC-001 · OD-5 (Odoo category #5)
            $t->string('name_ar');
            $t->string('name_en');
            $t->string('description', 500)->nullable();
            $t->boolean('active')->default(true);
            $t->integer('sort')->default(0);
            $t->string('approval_mode', 12)->default('parallel');   // parallel: everyone is asked at once | sequence: one after the other
            $t->integer('min_approvals')->default(1);               // approvals needed (never more than the approvers a request has)
            $t->boolean('allow_self_approval')->default(false);     // may the requester decide his own request when he is one of the approvers
            $t->json('fields')->nullable();                         // field → required | optional | no (App\Approvals\Services\ApprovalFlow::FIELDS)
            $t->string('source_system', 20)->nullable();            // 'odoo' | null (created in OPS)
            $t->string('external_ref', 60)->nullable();             // the Odoo id, as text
            $t->json('source_meta')->nullable();                    // what Odoo holds for it and OPS has no column for
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'approval_categories_source_uq');
            $t->index(['active', 'sort'], 'approval_categories_active_ix');
        });

        // who approves the requests of a category
        Schema::create('approval_category_approvers', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('category_id');
            $t->ulid('user_id')->nullable();                        // null: a person of Odoo who has no OPS user yet
            $t->string('user_name')->nullable();
            $t->string('user_login', 190)->nullable();
            $t->integer('sequence')->default(10);
            $t->boolean('required')->default(false);                // the request cannot be approved without this approval
            $t->decimal('min_amount', 16, 2)->nullable();           // asked only when the amount of the request reaches this
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 60)->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->unique(['source_system', 'external_ref'], 'approval_cat_approvers_source_uq');
            $t->index(['category_id', 'sequence'], 'approval_cat_approvers_category_ix');
        });

        Schema::create('approval_requests', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('number', 40)->unique();                     // APR-2026-00001 · APR-OD-01529 (Odoo request #1529)
            $t->string('title');
            $t->ulid('category_id');
            $t->string('status', 12)->default('draft');             // draft | pending | approved | refused | cancelled
            $t->ulid('requester_id')->nullable();
            $t->string('requester_name')->nullable();
            $t->string('requester_login', 190)->nullable();
            $t->ulid('created_by_id')->nullable();                  // who typed it in (the requester, or a manager on his behalf)
            $t->string('created_by')->nullable();
            $t->decimal('amount', 16, 2)->nullable();
            $t->string('currency', 3)->default('SAR');
            $t->decimal('quantity', 16, 3)->nullable();
            $t->date('request_date')->nullable();                   // the date the request is about (due date, day of the event)
            $t->dateTime('period_start', 3)->nullable();
            $t->dateTime('period_end', 3)->nullable();
            $t->string('location')->nullable();
            $t->string('reference', 120)->nullable();
            $t->string('partner_type', 12)->nullable();             // customer | supplier | other
            $t->ulid('partner_id')->nullable();                     // the OPS customer / supplier, when it is one
            $t->string('partner_name')->nullable();
            $t->string('payment_method', 20)->nullable();           // bank | cash | cheque | card | other
            $t->text('reason')->nullable();
            // the rule the request was submitted under (a later change of the category does not move a running request)
            $t->string('approval_mode', 12)->default('parallel');
            $t->integer('min_approvals')->default(1);
            $t->dateTime('submitted_at', 3)->nullable();
            $t->dateTime('decided_at', 3)->nullable();              // when it became approved or refused
            $t->dateTime('cancelled_at', 3)->nullable();
            $t->string('cancelled_by')->nullable();
            $t->text('cancel_reason')->nullable();
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 60)->nullable();
            $t->json('source_meta')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'approval_requests_source_uq');
            $t->index(['status', 'created_at'], 'approval_requests_status_ix');
            $t->index(['requester_id', 'status'], 'approval_requests_requester_ix');
            $t->index(['requester_login'], 'approval_requests_requester_login_ix');
            $t->index(['category_id', 'status'], 'approval_requests_category_ix');
            $t->index(['created_at'], 'approval_requests_created_ix');
        });

        // one line per approver of a request, with his decision
        Schema::create('approval_request_approvers', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('request_id');
            $t->ulid('user_id')->nullable();
            $t->string('user_name')->nullable();
            $t->string('user_login', 190)->nullable();
            $t->integer('sequence')->default(10);
            $t->boolean('required')->default(false);
            $t->string('status', 12)->default('new');               // new | waiting | pending | approved | refused | cancelled | removed
            $t->text('comment')->nullable();                        // what the approver wrote with his decision
            $t->dateTime('decided_at', 3)->nullable();
            $t->dateTime('notified_at', 3)->nullable();             // when he was last told it is his turn
            $t->string('origin', 12)->default('category');          // category | requester | manager | odoo
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 60)->nullable();
            $t->string('source_status', 12)->nullable();            // the status as Odoo words it, when it came from Odoo
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'approval_approvers_source_uq');
            $t->index(['request_id', 'sequence'], 'approval_approvers_request_ix');
            $t->index(['user_id', 'status'], 'approval_approvers_user_ix');
            $t->index(['user_login', 'status'], 'approval_approvers_login_ix');
        });

        // what is asked for, when the request is about products or other countable things
        Schema::create('approval_request_items', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('request_id');
            $t->integer('line_no')->default(1);
            $t->ulid('product_id')->nullable();                     // the OPS product, when it is one
            $t->string('description', 500);
            $t->decimal('quantity', 16, 3)->default(1);
            $t->string('uom', 60)->nullable();
            $t->string('warehouse', 120)->nullable();
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 60)->nullable();
            $t->json('source_meta')->nullable();
            $t->unique(['source_system', 'external_ref'], 'approval_items_source_uq');
            $t->index(['request_id', 'line_no'], 'approval_items_request_ix');
        });

        // the story of a request in the order it happened: raised, sent, each decision, comments, files, cancellation
        Schema::create('approval_request_events', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('request_id');
            $t->string('kind', 24);                                 // App\Approvals\Models\ApprovalEvent::KINDS
            $t->ulid('user_id')->nullable();
            $t->string('user_name')->nullable();
            $t->text('note')->nullable();
            $t->json('meta')->nullable();
            $t->string('source_system', 20)->nullable();            // 'odoo': it happened in Odoo and was read from the copy
            $t->dateTime('at', 3)->useCurrent();
            $t->index(['request_id', 'at'], 'approval_events_request_ix');
        });

        // files of a request. An OPS file lives in the file store (FileStore: object store or `stored_files`); a file of
        // Odoo is listed here and its content is read from the Odoo file archive (`odoo_files`) once it is kept there.
        Schema::create('approval_attachments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('request_id');
            $t->string('file_name');
            $t->string('mime', 150)->nullable();
            $t->bigInteger('size')->default(0);
            $t->string('storage_key')->nullable();
            $t->string('status', 20)->default('stored');            // stored | uploaded (FileStore) | odoo
            $t->ulid('uploaded_by_id')->nullable();
            $t->string('uploaded_by')->nullable();
            $t->dateTime('removed_at', 3)->nullable();              // taken off the request (the row and the file stay)
            $t->string('removed_by')->nullable();
            $t->string('remove_reason', 500)->nullable();
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 60)->nullable();             // ir.attachment id in Odoo
            $t->dateTime('created_at', 3)->useCurrent();
            $t->unique(['source_system', 'external_ref'], 'approval_attachments_source_uq');
            $t->index(['request_id'], 'approval_attachments_request_ix');
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

        Schema::table('approval_category_approvers', function (Blueprint $t) {
            $t->foreign(['category_id'], 'approval_cat_approvers_category_fk')->references(['id'])->on('approval_categories')->cascadeOnDelete()->deferrable();
        });
        Schema::table('approval_requests', function (Blueprint $t) {
            $t->foreign(['category_id'], 'approval_requests_category_fk')->references(['id'])->on('approval_categories')->restrictOnDelete()->deferrable();
        });
        foreach (['approval_request_approvers' => 'approval_approvers', 'approval_request_items' => 'approval_items', 'approval_request_events' => 'approval_events', 'approval_attachments' => 'approval_attachments'] as $table => $short) {
            Schema::table($table, function (Blueprint $t) use ($short) {
                $t->foreign(['request_id'], "{$short}_request_fk")->references(['id'])->on('approval_requests')->cascadeOnDelete()->deferrable();
            });
        }

        // page.approvals · approval.request · approval.manage are declared in config/scm.php: a live database gets them
        // (with their default role grants) here, a fresh one from the seeder — the same call, and it adds nothing twice
        PermissionSync::ensure();
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
