<?php

use App\Support\PermissionSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «بوابة العملاء» — the staff side of what the company's customers do through the portal (docs/odoo/WAVE3.md, P3):
 * their branches, their favourite lists, their onboarding applications with documents, the orders they request and the
 * log of what was changed on order lines, and their statements.
 *
 * WHO A RECORD BELONGS TO. A customer is the OPS customer (`customer_id`) — or, for a record that came from another
 * system whose customer OPS does not have yet, that system's id and name of it (`customer_ref`, `customer_name`); the
 * link is filled the day the customer becomes an OPS customer. Products work the same way (`product_id` /
 * `product_ref`, `product_name`).
 *
 * WHERE A RECORD CAME FROM. `source_system = 'odoo'` + `external_ref` (the Odoo id). While the section follows Odoo
 * those records are Odoo's and OPS only adds what is its own (a branch's address, contact and delivery window, notes,
 * documents, a quotation made from a list). Records made in OPS are OPS's.
 *
 * VOLUME. Favourites are ~156 k rows and the line-change log ~10 k: every list of the section is read page by page
 * through one of the indexes below.
 *
 * Nothing here is deleted that can be put away: a branch or a list is archived, a document is taken off with a reason,
 * a statement is voided. A favourite is the one thing that is simply removed — it is a choice, not a document.
 */
return new class extends Migration
{
    private const TABLES = [
        'portal_events', 'portal_statement_lines', 'portal_statements', 'portal_line_changes', 'portal_order_request_lines', 'portal_order_requests',
        'portal_application_documents', 'portal_applications', 'portal_favorite_items', 'portal_favorite_lists', 'portal_branches',
    ];

    public function up(): void
    {
        // a branch of a customer: who orders for it, where it is and when it takes deliveries
        Schema::create('portal_branches', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('customer_id')->nullable();
            $t->string('customer_ref', 40)->nullable();
            $t->string('customer_name', 250)->nullable();
            $t->string('name', 190);
            $t->string('code', 40)->nullable();              // the customer's own short code of the branch
            $t->integer('sequence')->default(10);
            // OPS's own on every branch — Odoo holds none of it
            $t->string('contact_name', 190)->nullable();
            $t->string('phone', 40)->nullable();
            $t->string('email', 190)->nullable();
            $t->string('city', 120)->nullable();
            $t->string('district', 120)->nullable();
            $t->string('address', 500)->nullable();
            $t->double('lat')->nullable();
            $t->double('lng')->nullable();
            $t->string('window_from', 5)->nullable();        // HH:MM — the hours the branch takes deliveries
            $t->string('window_to', 5)->nullable();
            $t->json('delivery_days')->nullable();           // ['sat', 'sun', …]; null = any day
            $t->string('delivery_notes', 500)->nullable();
            $t->ulid('site_id')->nullable();                 // the customer's delivery site in OPS (customer_sites): what ties orders and invoices to the branch
            $t->text('notes')->nullable();
            // the customer's people who order for the branch, as the source system names them: [{ ref, name, role }]
            $t->json('portal_users')->nullable();
            $t->boolean('active')->default(true);
            $t->dateTime('archived_at', 3)->nullable();
            $t->string('archived_by')->nullable();
            $t->string('archive_reason', 500)->nullable();
            $t->json('extra')->nullable();                   // what the source holds and OPS has no column for
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->dateTime('source_updated_at', 3)->nullable();
            $t->dateTime('source_deleted_at', 3)->nullable();
            $t->string('created_by')->nullable();
            $t->string('updated_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'portal_branches_source_uq');
            $t->index(['customer_id', 'active', 'sequence'], 'portal_branches_customer_ix');
            $t->index(['customer_ref'], 'portal_branches_ref_ix');
            $t->index(['site_id'], 'portal_branches_site_ix');
            $t->index(['active', 'name'], 'portal_branches_name_ix');
        });

        // a list of products a customer orders again and again
        Schema::create('portal_favorite_lists', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('name', 190);
            $t->ulid('customer_id')->nullable();
            $t->string('customer_ref', 40)->nullable();
            $t->string('customer_name', 250)->nullable();
            $t->ulid('branch_id')->nullable();               // the branch the list is kept for (OPS's own; null = the whole customer)
            $t->integer('sequence')->default(10);
            $t->integer('items_count')->default(0);          // kept equal to the rows of portal_favorite_items
            $t->text('notes')->nullable();
            $t->boolean('active')->default(true);
            $t->dateTime('archived_at', 3)->nullable();
            $t->string('archived_by')->nullable();
            $t->string('archive_reason', 500)->nullable();
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->dateTime('source_updated_at', 3)->nullable();
            $t->dateTime('source_deleted_at', 3)->nullable();
            $t->string('created_by')->nullable();
            $t->string('updated_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'portal_fav_lists_source_uq');
            $t->index(['customer_id', 'active', 'sequence'], 'portal_fav_lists_customer_ix');
            $t->index(['customer_ref'], 'portal_fav_lists_ref_ix');
            $t->index(['branch_id'], 'portal_fav_lists_branch_ix');
            $t->index(['active', 'created_at'], 'portal_fav_lists_recent_ix');
        });

        // one product of a list, with the quantity the customer usually takes. Read per list, a page at a time.
        Schema::create('portal_favorite_items', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('list_id');
            $t->ulid('product_id')->nullable();
            $t->string('product_ref', 40)->nullable();
            $t->string('product_name', 250)->nullable();
            $t->decimal('qty', 14, 3)->default(1);
            $t->integer('sequence')->default(10);
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'portal_fav_items_source_uq');
            $t->index(['list_id', 'sequence', 'id'], 'portal_fav_items_list_ix');       // a page of a list, in its order
            $t->index(['product_id', 'list_id'], 'portal_fav_items_product_ix');        // the lists a product is on; "is it on this list already"
            $t->index(['product_ref', 'product_id'], 'portal_fav_items_ref_ix');        // filling the link when a product becomes an OPS product
        });

        // a company asking to become a customer
        Schema::create('portal_applications', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('number', 40)->unique();              // APL-YYYY-NNNNN; an application of Odoo keeps Odoo's reference
            $t->string('status', 16)->default('draft');      // draft | submitted | correction | accepted | approved | rejected | expired
            $t->string('source_state', 30)->nullable();      // the state as the source system words it
            $t->string('language', 5)->default('ar');
            $t->string('company_name_ar', 250)->nullable();
            $t->string('company_name_en', 250)->nullable();
            $t->string('legal_entity_type', 20)->nullable(); // establishment | llc | jsc | sole_prop | other
            $t->string('cr', 40)->nullable();                // commercial registration
            $t->string('vat_no', 40)->nullable();
            $t->string('unified_no', 40)->nullable();
            $t->string('owner_name', 190)->nullable();
            $t->string('owner_national_id', 40)->nullable(); // an identity paper: shown in full only to who decides, never written to a log
            $t->string('manager_name', 190)->nullable();
            $t->string('email', 190)->nullable();
            $t->string('mobile', 40)->nullable();
            $t->string('street', 250)->nullable();
            $t->string('building_no', 20)->nullable();
            $t->string('additional_no', 20)->nullable();
            $t->string('district', 120)->nullable();
            $t->string('city', 120)->nullable();
            $t->string('region', 120)->nullable();
            $t->string('postal_code', 20)->nullable();
            $t->json('contacts')->nullable();                // the people the company names: [{ role, name, mobile, email }]
            $t->boolean('terms_accepted')->default(false);
            $t->dateTime('terms_accepted_at', 3)->nullable();
            $t->string('terms_version', 120)->nullable();
            $t->dateTime('signed_at', 3)->nullable();
            $t->dateTime('submitted_at', 3)->nullable();
            $t->text('correction_note')->nullable();         // what the company was asked to correct, the last time
            $t->dateTime('decided_at', 3)->nullable();
            $t->string('decided_by')->nullable();
            $t->text('rejection_reason')->nullable();
            $t->dateTime('approved_at', 3)->nullable();
            $t->dateTime('expired_at', 3)->nullable();
            $t->ulid('customer_id')->nullable();             // the OPS customer it became, or was recognised as
            $t->string('customer_ref', 40)->nullable();      // the partner the source system made of it
            $t->string('customer_by', 12)->nullable();       // how the customer was named: new | link | vat | cr | person
            $t->integer('documents_count')->default(0);
            $t->json('extra')->nullable();
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->dateTime('source_updated_at', 3)->nullable();
            $t->dateTime('source_deleted_at', 3)->nullable();
            $t->string('created_by')->nullable();
            $t->string('updated_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'portal_applications_source_uq');
            $t->index(['status', 'created_at'], 'portal_applications_status_ix');
            $t->index(['created_at'], 'portal_applications_created_ix');
            $t->index(['customer_id'], 'portal_applications_customer_ix');
            $t->index(['vat_no'], 'portal_applications_vat_ix');
            $t->index(['cr'], 'portal_applications_cr_ix');
        });

        // a paper of an application. `status` says where its content lives: stored | uploaded (FileStore) or odoo (the Odoo file archive)
        Schema::create('portal_application_documents', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('application_id');
            $t->string('kind', 30);                          // cr | vat | national_address | owner_id | bank_letter | chamber | other
            $t->string('file_name', 250);
            $t->string('mime', 150)->nullable();
            $t->bigInteger('size')->nullable();
            $t->string('storage_key', 500)->nullable();
            $t->string('status', 12)->default('stored');
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();      // the attachment's id in Odoo
            $t->ulid('uploaded_by_id')->nullable();
            $t->string('uploaded_by')->nullable();
            $t->dateTime('removed_at', 3)->nullable();
            $t->string('removed_by')->nullable();
            $t->string('remove_reason', 500)->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->unique(['source_system', 'external_ref'], 'portal_app_docs_source_uq');
            $t->index(['application_id', 'kind'], 'portal_app_docs_application_ix');
        });

        // an order a branch asks for; approved, it becomes a quotation / an order of the sales module
        Schema::create('portal_order_requests', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('number', 40)->unique();              // ORQ-YYYY-NNNNN; a request of Odoo keeps Odoo's reference
            $t->string('status', 12)->default('pending');    // pending | approved | rejected | cancelled
            $t->ulid('customer_id')->nullable();
            $t->string('customer_ref', 40)->nullable();
            $t->string('customer_name', 250)->nullable();
            $t->ulid('branch_id')->nullable();
            $t->string('requested_by', 190)->nullable();     // the customer's person who asked
            $t->dateTime('request_date', 3)->nullable();
            $t->string('decided_by', 190)->nullable();
            $t->dateTime('decided_at', 3)->nullable();
            $t->double('decision_hours')->nullable();        // how long the request waited for its decision
            $t->decimal('amount_total', 16, 2)->default(0);
            $t->string('currency', 3)->default('SAR');
            $t->string('price_list', 190)->nullable();
            $t->text('note')->nullable();
            $t->text('rejection_reason')->nullable();
            $t->string('order_ref', 40)->nullable();         // the order it became in the source system (Odoo sale.order id) …
            $t->string('order_number', 60)->nullable();      // … and that order's number
            $t->ulid('quotation_id')->nullable();            // the OPS quotation made when it was approved here
            $t->integer('lines_count')->default(0);
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->dateTime('source_updated_at', 3)->nullable();
            $t->dateTime('source_deleted_at', 3)->nullable();
            $t->string('created_by')->nullable();
            $t->string('updated_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'portal_requests_source_uq');
            $t->index(['status', 'request_date'], 'portal_requests_status_ix');
            $t->index(['request_date'], 'portal_requests_date_ix');
            $t->index(['customer_id', 'request_date'], 'portal_requests_customer_ix');
            $t->index(['branch_id', 'request_date'], 'portal_requests_branch_ix');
            $t->index(['order_ref'], 'portal_requests_order_ix');
        });

        Schema::create('portal_order_request_lines', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('request_id');
            $t->integer('line_no')->default(1);
            $t->ulid('product_id')->nullable();
            $t->string('product_ref', 40)->nullable();
            $t->string('product_name', 250)->nullable();
            $t->string('description', 500)->nullable();
            $t->decimal('qty', 14, 3)->default(0);
            $t->decimal('price', 14, 2)->default(0);
            $t->decimal('subtotal', 16, 2)->default(0);
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'portal_request_lines_source_uq');
            $t->index(['request_id', 'line_no'], 'portal_request_lines_request_ix');
            $t->index(['product_id'], 'portal_request_lines_product_ix');
        });

        // who changed what on which order line, before and after. Append-only.
        Schema::create('portal_line_changes', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->dateTime('changed_at', 3);
            $t->string('change_type', 10);                   // added | modified | removed
            $t->string('field', 10)->nullable();             // qty | price (a modification names what it changed)
            $t->string('field_label', 60)->nullable();       // … as the source system words it
            $t->string('old_value', 60)->nullable();
            $t->string('new_value', 60)->nullable();
            $t->string('changed_by', 190)->nullable();
            $t->ulid('changed_by_id')->nullable();           // the OPS user, for a change made here
            $t->ulid('request_id')->nullable();              // the order request whose line it is
            $t->string('order_ref', 40)->nullable();         // the order in the source system (Odoo sale.order id) …
            $t->string('order_number', 60)->nullable();      // … and its number
            $t->ulid('customer_id')->nullable();
            $t->string('customer_ref', 40)->nullable();
            $t->string('customer_name', 250)->nullable();
            $t->ulid('branch_id')->nullable();
            $t->ulid('product_id')->nullable();
            $t->string('product_ref', 40)->nullable();
            $t->string('product_name', 250)->nullable();
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->unique(['source_system', 'external_ref'], 'portal_line_changes_source_uq');
            $t->index(['changed_at', 'id'], 'portal_line_changes_at_ix');
            $t->index(['change_type', 'changed_at'], 'portal_line_changes_type_ix');
            $t->index(['order_ref', 'changed_at'], 'portal_line_changes_order_ix');
            $t->index(['request_id', 'changed_at'], 'portal_line_changes_request_ix');
            $t->index(['customer_id', 'changed_at'], 'portal_line_changes_customer_ix');
            $t->index(['product_id', 'changed_at'], 'portal_line_changes_product_ix');
            $t->index(['customer_ref', 'customer_id'], 'portal_line_changes_cref_ix');
            $t->index(['product_ref', 'product_id'], 'portal_line_changes_pref_ix');
        });

        // a statement of account as it was given to the customer: a period, its opening and closing balance and its lines
        Schema::create('portal_statements', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('number', 40)->unique();              // SOA-YYYY-NNNNN; a statement of Odoo keeps Odoo's reference
            $t->string('status', 12)->default('issued');     // draft | issued | void
            $t->ulid('customer_id')->nullable();
            $t->string('customer_ref', 40)->nullable();
            $t->string('customer_name', 250)->nullable();
            $t->ulid('branch_id')->nullable();               // a statement of one branch (null = the whole customer)
            $t->date('date_from');
            $t->date('date_to');
            $t->decimal('opening_balance', 16, 2)->default(0);
            $t->decimal('total_debit', 16, 2)->default(0);
            $t->decimal('total_credit', 16, 2)->default(0);
            $t->decimal('closing_balance', 16, 2)->default(0);
            $t->string('currency', 3)->default('SAR');
            $t->integer('lines_count')->default(0);
            $t->decimal('books_closing', 16, 2)->nullable(); // what the general ledger said the customer owed on the last day, when it was issued here
            $t->text('note')->nullable();
            $t->dateTime('issued_at', 3)->nullable();
            $t->string('issued_by')->nullable();
            $t->dateTime('voided_at', 3)->nullable();
            $t->string('voided_by')->nullable();
            $t->string('void_reason', 500)->nullable();
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->dateTime('source_updated_at', 3)->nullable();
            $t->dateTime('source_deleted_at', 3)->nullable();
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'portal_statements_source_uq');
            $t->index(['customer_id', 'date_to'], 'portal_statements_customer_ix');
            $t->index(['branch_id', 'date_to'], 'portal_statements_branch_ix');
            $t->index(['status', 'date_to'], 'portal_statements_status_ix');
            $t->index(['date_to'], 'portal_statements_date_ix');
            $t->index(['customer_ref', 'customer_id'], 'portal_statements_cref_ix');
        });

        Schema::create('portal_statement_lines', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('statement_id');
            $t->date('date');
            $t->bigInteger('seq')->default(0);               // the order of the lines of one day
            $t->string('kind', 12)->default('other');        // invoice | credit_note | payment | reversal | other
            $t->string('reference', 80)->nullable();         // the document's number
            $t->string('description', 500)->nullable();
            $t->decimal('debit', 16, 2)->default(0);
            $t->decimal('credit', 16, 2)->default(0);
            $t->decimal('balance', 16, 2)->default(0);       // running balance after the line
            $t->ulid('branch_id')->nullable();               // the branch the document was for, when it names one
            $t->string('doc_type', 30)->nullable();          // CustomerInvoice | Payment (OPS) · account.move (Odoo)
            $t->string('doc_id', 40)->nullable();
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->unique(['source_system', 'external_ref'], 'portal_statement_lines_source_uq');
            $t->index(['statement_id', 'date', 'seq'], 'portal_statement_lines_statement_ix');
            $t->index(['branch_id', 'statement_id'], 'portal_statement_lines_branch_ix');
        });

        // what happened to an application, a request, a list, a statement or a branch — and the notes people leave on them
        Schema::create('portal_events', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('entity_type', 20);                   // application | request | list | statement | branch
            $t->ulid('entity_id');
            $t->string('kind', 24);
            $t->text('note')->nullable();
            $t->json('data')->nullable();
            $t->ulid('user_id')->nullable();
            $t->string('username')->nullable();
            $t->string('origin', 20)->nullable();            // 'odoo' when it happened there
            $t->dateTime('at', 3)->useCurrent();
            $t->index(['entity_type', 'entity_id', 'at'], 'portal_events_entity_ix');
        });

        $this->caseInsensitiveText();
        PermissionSync::ensure();   // page.portal · portal.manage with their default role grants, on a database that predates them
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
