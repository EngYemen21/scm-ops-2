<?php

use App\Credit\CreditBootstrap;
use App\Support\PermissionSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «حسابات الائتمان الآجل» — deferred credit accounts (docs/odoo/WAVE4.md §Q3): the workflow (stages), the versions of
 * the terms, the contractual payment methods, the section's policies, the requests with the lines and papers of their
 * form, the approval audit, the signature envelopes, the timeline and the notification log.
 *
 * Records that came from Odoo carry `source_system = 'odoo'` + `external_ref` (the Odoo id): while the section follows
 * Odoo they are Odoo's. Nothing here is deleted: a stage, a version, a method is archived, a request is cancelled, a
 * paper is replaced or removed and stays, an approval line, an event and a notification are never removed.
 *
 * Personal values — the owner's and the responsible persons' identity numbers, bank account numbers and IBANs — are
 * kept ENCRYPTED with the application key (text columns: the cipher is longer than the value).
 */
return new class extends Migration
{
    private const TABLES = [
        'credit_notifications', 'credit_events', 'credit_files', 'credit_envelopes', 'credit_approvals', 'credit_bank_lines', 'credit_signatories', 'credit_responsibles',
        'credit_request_methods', 'credit_requests', 'credit_payment_methods', 'credit_terms_versions', 'credit_stages', 'credit_settings',
    ];

    public function up(): void
    {
        Schema::create('credit_settings', function (Blueprint $t) {
            $t->string('key', 40)->primary();                 // calendar · documents · reminders · signature
            $t->text('value');                                // JSON
            $t->string('updated_by')->nullable();
            $t->dateTime('updated_at', 3)->nullable();
        });

        Schema::create('credit_stages', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('code', 40)->unique();
            $t->string('name_ar', 120);
            $t->string('name_en', 120);
            $t->string('state', 20);                          // the state a request carries while it stands here (Flow::STATES)
            $t->integer('sequence')->default(0);
            $t->boolean('active')->default(true);
            $t->boolean('fold')->default(false);
            $t->string('responsible_role', 60)->nullable();  // an OPS role key …
            $t->string('responsible_role_name', 190)->nullable(); // … or the name of Odoo's group no OPS role answers to yet
            $t->ulid('responsible_user_id')->nullable();
            $t->string('responsible_user_login', 190)->nullable(); // an Odoo user with no OPS user: his login, lower-case
            $t->string('responsible_user_name', 190)->nullable();
            $t->string('approval_mode', 10)->default('any_user'); // any_user | all_users
            $t->decimal('sla_hours', 8, 2)->nullable();
            $t->decimal('reminder_before_hours', 8, 2)->nullable();
            $t->decimal('escalate_after_hours', 8, 2)->nullable();
            $t->string('escalation_role', 60)->nullable();
            $t->string('escalation_role_name', 190)->nullable();
            $t->boolean('require_documents')->default(false);
            $t->boolean('require_signature')->default(false);
            $t->boolean('auto_send_signature')->default(false);
            $t->boolean('lock_request_fields')->default(false);
            $t->boolean('portal_visible')->default(false);
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'credit_stages_source_uq');
            $t->index(['active', 'state', 'sequence'], 'credit_stages_path_ix');
        });

        Schema::create('credit_terms_versions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('name', 190);
            $t->string('version', 40);
            $t->string('status', 12)->default('draft');      // draft → published → retired
            $t->date('effective_date');
            $t->longText('terms_ar')->nullable();
            $t->longText('terms_en')->nullable();
            $t->string('jurisdiction_city', 120)->nullable();
            $t->string('content_hash', 64)->nullable();       // the proof of the published text
            $t->dateTime('published_at', 3)->nullable();
            $t->string('published_by')->nullable();
            $t->dateTime('retired_at', 3)->nullable();
            $t->string('retired_by')->nullable();
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'credit_terms_source_uq');
            $t->index(['status', 'effective_date'], 'credit_terms_force_ix');
            $t->index(['version'], 'credit_terms_version_ix');
        });

        Schema::create('credit_payment_methods', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('code', 40)->unique();
            $t->string('name_ar', 120);
            $t->string('name_en', 120);
            $t->integer('sequence')->default(10);
            $t->boolean('active')->default(true);
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'credit_methods_source_uq');
        });

        Schema::create('credit_requests', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('number', 40)->unique();               // CRA-YYYY-NNNNN
            $t->string('source_name', 60)->nullable();        // the request's own name in Odoo
            $t->date('request_date');
            $t->string('source', 10)->default('internal');    // internal | portal
            // the customer
            $t->ulid('customer_id')->nullable();
            $t->string('partner_ref', 40)->nullable();        // the Odoo partner it points at
            $t->string('partner_name', 190)->nullable();
            $t->string('currency', 10)->default('SAR');
            // where it stands
            $t->ulid('stage_id')->nullable();
            $t->string('state', 20)->default('draft');
            $t->integer('round')->default(0);                 // how many times it was sent
            $t->ulid('requester_id')->nullable();
            $t->string('requester_login', 190)->nullable();
            $t->string('requester_name', 190)->nullable();
            $t->dateTime('submitted_at', 3)->nullable();
            $t->dateTime('stage_entered_at', 3)->nullable();
            $t->dateTime('sla_deadline', 3)->nullable();
            $t->dateTime('sla_due_soon_at', 3)->nullable();
            $t->dateTime('sla_escalate_at', 3)->nullable();
            $t->dateTime('last_reminder_at', 3)->nullable();
            $t->integer('reminder_count')->default(0);
            $t->dateTime('sla_checked_at', 3)->nullable();
            $t->dateTime('escalated_at', 3)->nullable();
            $t->integer('escalation_count')->default(0);
            // the company
            $t->string('company_name', 190)->nullable();
            $t->string('commercial_registration', 40)->nullable();
            $t->string('business_activity', 190)->nullable();
            $t->string('legal_entity_type', 120)->nullable();
            $t->string('owner_name', 190)->nullable();
            $t->text('owner_national_id')->nullable();        // encrypted
            $t->ulid('owner_national_id_by')->nullable();     // who typed it
            $t->string('phone', 40)->nullable();
            $t->string('mobile', 40)->nullable();
            $t->string('email', 190)->nullable();
            $t->string('website', 250)->nullable();
            $t->string('po_box', 40)->nullable();
            $t->string('postal_code', 20)->nullable();
            $t->string('street', 250)->nullable();
            $t->string('district', 120)->nullable();
            $t->string('building_no', 20)->nullable();
            $t->string('city', 120)->nullable();
            // what is asked for and what is granted
            $t->decimal('requested_credit_limit', 16, 2)->nullable();
            $t->integer('requested_payment_days')->nullable();
            $t->decimal('monthly_purchase_volume', 16, 2)->nullable();
            $t->decimal('approved_credit_limit', 16, 2)->nullable();
            $t->integer('approved_payment_days')->nullable();
            $t->string('approved_term', 120)->nullable();
            $t->text('finance_recommendation')->nullable();
            $t->text('legal_notes')->nullable();
            $t->text('ceo_notes')->nullable();
            $t->text('rejection_reason')->nullable();
            $t->dateTime('rejected_at', 3)->nullable();
            $t->string('rejected_by')->nullable();
            $t->text('changes_requested_note')->nullable();
            // the terms the customer accepted — frozen
            $t->ulid('terms_version_id')->nullable();
            $t->json('terms_snapshot')->nullable();
            $t->dateTime('terms_accepted_at', 3)->nullable();
            $t->string('terms_accepted_by')->nullable();
            $t->boolean('attachments_complete')->default(false);
            $t->string('signature_status', 12)->default('none');
            // how it ended
            $t->dateTime('approved_at', 3)->nullable();
            $t->ulid('approved_by_id')->nullable();
            $t->string('approved_by')->nullable();
            $t->dateTime('cancelled_at', 3)->nullable();
            $t->string('cancelled_by')->nullable();
            $t->text('cancel_reason')->nullable();
            // what it changed on the customer
            $t->string('apply_status', 10)->default('none');  // none | applied | blocked | restored
            $t->dateTime('applied_at', 3)->nullable();
            $t->ulid('applied_by_id')->nullable();
            $t->string('applied_by')->nullable();
            $t->json('applied_changes')->nullable();           // the limit and the terms before and after, the exposure then
            $t->json('apply_error')->nullable();
            $t->dateTime('restored_at', 3)->nullable();
            $t->string('restored_by')->nullable();
            $t->json('extra')->nullable();                     // what Odoo holds and OPS has no column for
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->dateTime('source_updated_at', 3)->nullable();
            $t->dateTime('source_deleted_at', 3)->nullable();
            $t->string('created_by')->nullable();
            $t->string('updated_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'credit_requests_source_uq');
            $t->index(['state', 'stage_id'], 'credit_requests_state_ix');
            $t->index(['customer_id', 'state'], 'credit_requests_customer_ix');
            $t->index(['requester_id', 'state'], 'credit_requests_requester_ix');
            $t->index(['sla_deadline'], 'credit_requests_sla_ix');
            $t->index(['created_at'], 'credit_requests_created_ix');
            $t->index(['request_date'], 'credit_requests_date_ix');
        });

        Schema::create('credit_request_methods', function (Blueprint $t) {
            $t->ulid('request_id');
            $t->ulid('payment_method_id');
            $t->primary(['request_id', 'payment_method_id']);
            $t->index(['payment_method_id'], 'credit_request_methods_method_ix');
        });

        Schema::create('credit_responsibles', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('request_id');
            $t->integer('line_no')->default(1);
            $t->string('name', 190);
            $t->string('position', 120)->nullable();
            $t->text('national_id')->nullable();              // encrypted
            $t->string('mobile', 40)->nullable();
            $t->ulid('entered_by_id')->nullable();
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'credit_responsibles_source_uq');
            $t->index(['request_id', 'line_no'], 'credit_responsibles_request_ix');
        });

        Schema::create('credit_signatories', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('request_id');
            $t->integer('line_no')->default(1);
            $t->string('name', 190);
            $t->string('job_title', 120)->nullable();
            $t->string('mobile', 40)->nullable();
            $t->string('signature_note', 250)->nullable();
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'credit_signatories_source_uq');
            $t->index(['request_id', 'line_no'], 'credit_signatories_request_ix');
        });

        Schema::create('credit_bank_lines', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('request_id');
            $t->integer('line_no')->default(1);
            $t->string('bank_name', 120);
            $t->text('account_number')->nullable();           // encrypted
            $t->string('branch', 120)->nullable();
            $t->text('iban')->nullable();                     // encrypted
            $t->string('swift', 11)->nullable();
            $t->ulid('entered_by_id')->nullable();
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'credit_bank_lines_source_uq');
            $t->index(['request_id', 'line_no'], 'credit_bank_lines_request_ix');
        });

        // the approval audit: one line per stage stay (any user) or per person (all users); answered once, never removed
        Schema::create('credit_approvals', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('request_id');
            $t->ulid('stage_id')->nullable();                 // null only for a line of Odoo whose stage is not known here
            $t->integer('round')->default(1);
            $t->string('state', 20)->default('pending');      // pending · approved · rejected · changes_requested · skipped · escalated
            $t->string('role_key', 60)->nullable();
            $t->string('role_name', 190)->nullable();
            $t->ulid('user_id')->nullable();
            $t->string('user_login', 190)->nullable();
            $t->string('user_name', 190)->nullable();
            $t->boolean('personal')->default(false);          // the line asks this one person (all users), not his role
            $t->dateTime('deadline', 3)->nullable();
            $t->ulid('decided_by_id')->nullable();
            $t->string('decided_by_login', 190)->nullable();
            $t->string('decided_by')->nullable();
            $t->dateTime('decided_at', 3)->nullable();
            $t->text('note')->nullable();
            $t->integer('reminder_count')->default(0);
            $t->dateTime('last_reminder_at', 3)->nullable();
            $t->dateTime('escalated_at', 3)->nullable();
            $t->string('escalated_to_role', 60)->nullable();
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'credit_approvals_source_uq');
            $t->index(['request_id', 'stage_id', 'round', 'state'], 'credit_approvals_open_ix');
            $t->index(['user_id', 'state'], 'credit_approvals_user_ix');
            $t->index(['role_key', 'state'], 'credit_approvals_role_ix');
        });

        Schema::create('credit_envelopes', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('request_id');
            $t->string('provider', 30);                       // manual · signit · …
            $t->string('status', 12)->default('draft');       // draft · sent · delivered · signed · declined · expired · failed · voided
            $t->string('external_envelope_id', 120)->nullable();
            $t->string('agreement_hash', 64)->nullable();     // the proof of the agreement that was sent
            $t->string('signer_name', 190)->nullable();
            $t->string('signer_email', 190)->nullable();
            $t->string('signer_mobile', 40)->nullable();
            $t->dateTime('sent_at', 3)->nullable();
            $t->dateTime('delivered_at', 3)->nullable();
            $t->dateTime('signed_at', 3)->nullable();
            $t->dateTime('closed_at', 3)->nullable();
            $t->dateTime('expires_at', 3)->nullable();
            $t->ulid('signed_file_id')->nullable();
            $t->text('error_message')->nullable();
            $t->longText('last_payload')->nullable();         // what the provider last said — never returned by the API
            $t->ulid('created_by_id')->nullable();
            $t->string('created_by')->nullable();
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'credit_envelopes_source_uq');
            $t->index(['request_id', 'status'], 'credit_envelopes_request_ix');
            $t->index(['status', 'expires_at'], 'credit_envelopes_expiry_ix');
        });

        // the papers: the nine slots and the signed agreement — never the generic attachments table
        Schema::create('credit_files', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('request_id');
            $t->string('slot', 40);
            $t->string('name', 200)->nullable();
            $t->string('mime', 120)->nullable();
            $t->bigInteger('size')->default(0);
            $t->string('sha256', 64)->nullable();
            $t->string('storage', 10)->default('ops');        // ops: FileStore · odoo: the Odoo backup's attachment
            $t->string('storage_key', 250)->nullable();
            $t->string('storage_status', 20)->nullable();
            $t->string('attachment_ref', 40)->nullable();
            $t->string('status', 10)->default('current');     // current · replaced · removed
            $t->ulid('uploaded_by_id')->nullable();
            $t->string('uploaded_by')->nullable();
            $t->dateTime('closed_at', 3)->nullable();
            $t->string('closed_by')->nullable();
            $t->text('close_reason')->nullable();
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 60)->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->index(['request_id', 'slot', 'status'], 'credit_files_request_ix');
            $t->index(['source_system', 'external_ref'], 'credit_files_source_ix');
        });

        // the timeline of a request — append-only
        Schema::create('credit_events', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('request_id');
            $t->string('kind', 30);
            $t->ulid('stage_id')->nullable();
            $t->text('note')->nullable();
            $t->json('data')->nullable();                     // the facts — names of fields and counts, never a personal value
            $t->ulid('user_id')->nullable();
            $t->string('user_name')->nullable();
            $t->dateTime('at', 3)->useCurrent();
            $t->index(['request_id', 'at'], 'credit_events_request_ix');
        });

        // the notification log (Odoo's b2b.credit.notification.log): every attempt, honest about what was not sent
        Schema::create('credit_notifications', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('request_id')->nullable();
            $t->string('channel', 12);                        // in_app · email · whatsapp
            $t->string('template_code', 80)->nullable();
            $t->string('status', 10);                         // pending · sent · failed · skipped
            $t->dateTime('sent_at', 3)->nullable();
            $t->text('error_message')->nullable();
            $t->text('payload')->nullable();
            $t->string('recipient_role', 60)->nullable();
            $t->ulid('recipient_user_id')->nullable();
            $t->string('recipient_partner_ref', 40)->nullable();
            $t->string('recipient_name', 190)->nullable();
            $t->string('recipient_value', 190)->nullable();
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->unique(['source_system', 'external_ref'], 'credit_notifications_source_uq');
            $t->index(['request_id', 'created_at'], 'credit_notifications_request_ix');
            $t->index(['channel', 'status'], 'credit_notifications_status_ix');
        });

        $this->caseInsensitiveText();
        PermissionSync::ensure();   // page.credit · credit.request · credit.review · credit.approve · credit.manage with their default role grants
        CreditBootstrap::ensure();  // a first workflow and the usual payment methods, into empty tables only
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
