<?php

use App\Support\PermissionSync;
use App\Tax\Support\TaxBootstrap;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «الضريبة والفوترة الإلكترونية» — tax & e-invoicing (docs/odoo/WAVE4.md §Q5):
 *
 *   the dictionary     tax groups, tax codes (use, kind, rate, the line of the VAT return, the e-invoice category and
 *                      exemption reason), fiscal positions with their replacements
 *   the taxpayer       the one profile row, the tax details of customers and suppliers, a person's code for a document
 *   the VAT return     one per period, the documents behind its figures, adjustment lines, explanations of the ledger
 *   e-invoicing        the units with their onboarding (key, request and certificates ENCRYPTED with the application
 *                      key), the register (counter + previous-hash chain per unit), every call to the authority
 *   the history        the e-invoice documents Odoo made (`account.edi.document`)
 *
 * Records that came from Odoo carry `source_system = 'odoo'` + `external_ref` (the Odoo id). Nothing is deleted that
 * can be voided: a code, a group, a position or a unit is archived / retired, an adjustment or an explanation is
 * voided, a return made by mistake is cancelled, an e-invoice that was replaced keeps its place in the chain.
 */
return new class extends Migration
{
    private const TABLES = ['tax_einvoice_attempts', 'tax_einvoices', 'tax_einvoice_units', 'tax_einvoice_history', 'tax_return_ledger_notes', 'tax_return_adjustments',
        'tax_return_entries', 'tax_returns', 'tax_document_codes', 'tax_parties', 'tax_fiscal_position_lines', 'tax_fiscal_positions', 'tax_codes', 'tax_groups', 'tax_profile'];

    public function up(): void
    {
        // ───────────── the dictionary ─────────────
        Schema::create('tax_groups', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('name_ar', 120);
            $t->string('name_en', 120);
            $t->integer('sequence')->default(10);
            $t->boolean('active')->default(true);
            $t->json('extra')->nullable();
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'tax_groups_source_uq');
        });

        Schema::create('tax_codes', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('code', 30)->unique();                     // the short code people type (OPS's own, also on a code of Odoo)
            $t->string('name_ar', 190);
            $t->string('name_en', 190);
            $t->string('label', 190)->nullable();                 // what an invoice prints beside the tax
            $t->text('description')->nullable();
            $t->string('use', 10);                                // sale | purchase
            $t->string('kind', 20);                               // standard | zero | exempt | out_of_scope | reverse_charge | import | withholding
            $t->decimal('rate', 8, 4)->default(0);                // percent
            $t->unsignedTinyInteger('return_box')->nullable();    // the line of the VAT return it feeds (null: none)
            $t->string('category_code', 2)->nullable();           // the e-invoice VAT category: S | Z | E | O
            $t->string('exemption_reason_code', 30)->nullable();  // VATEX-SA-… of the authority's list
            $t->string('exemption_reason_ar', 300)->nullable();
            $t->string('exemption_reason_en', 300)->nullable();
            $t->string('scope', 10)->nullable();                  // goods | service
            $t->boolean('price_include')->default(false);
            $t->integer('sequence')->default(0);
            $t->boolean('is_default')->default(false);            // THE code of OPS documents of its use and rate
            $t->boolean('active')->default(true);
            $t->ulid('group_id')->nullable();
            $t->json('extra')->nullable();                        // what the source system gives (box_from_source, reason_from_source …)
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->dateTime('source_updated_at', 3)->nullable();
            $t->string('created_by')->nullable();
            $t->string('updated_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'tax_codes_source_uq');
            $t->index(['use', 'active', 'is_default'], 'tax_codes_use_ix');
            $t->index(['return_box'], 'tax_codes_box_ix');
        });

        Schema::create('tax_fiscal_positions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('name_ar', 120);
            $t->string('name_en', 120);
            $t->integer('sequence')->default(10);
            $t->boolean('auto_apply')->default(false);
            $t->boolean('vat_required')->default(false);
            $t->string('country_code', 2)->nullable();
            $t->string('country_group', 60)->nullable();
            $t->string('zip_from', 20)->nullable();
            $t->string('zip_to', 20)->nullable();
            $t->string('foreign_vat', 40)->nullable();
            $t->text('note')->nullable();
            $t->boolean('active')->default(true);
            $t->json('extra')->nullable();
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'tax_positions_source_uq');
        });

        Schema::create('tax_fiscal_position_lines', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('position_id');
            $t->ulid('from_code_id');
            $t->ulid('to_code_id');
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->unique(['position_id', 'from_code_id'], 'tax_position_lines_from_uq');   // a code is replaced once per position
            $t->unique(['source_system', 'external_ref'], 'tax_position_lines_source_uq');
        });

        // ───────────── the taxpayer and the parties ─────────────
        Schema::create('tax_profile', function (Blueprint $t) {
            $t->string('id', 20)->primary();                      // 'company': one row
            $t->string('legal_name_ar', 190)->default('');
            $t->string('legal_name_en', 190)->nullable();
            $t->string('vat_no', 15)->nullable();
            $t->string('id_scheme', 3)->nullable();               // CRN | MOM | MLS | 700 | SAG | OTH
            $t->string('id_value', 40)->nullable();
            $t->string('street', 190)->nullable();
            $t->string('building_no', 10)->nullable();
            $t->string('additional_no', 10)->nullable();
            $t->string('district', 120)->nullable();
            $t->string('city', 120)->nullable();
            $t->string('postal_code', 10)->nullable();
            $t->string('province', 120)->nullable();
            $t->string('country_code', 2)->default('SA');
            $t->string('filing_frequency', 10)->default('quarterly');
            $t->boolean('einvoice_auto')->default(true);          // OPS makes the e-invoice of every invoice by itself
            $t->date('einvoice_from')->nullable();                // … of the invoices issued from this day on
            $t->string('updated_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
        });

        Schema::create('tax_parties', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('partner_type', 10);                       // customer | supplier
            $t->string('partner_id', 40);
            $t->ulid('fiscal_position_id')->nullable();
            $t->ulid('default_code_id')->nullable();
            $t->string('invoice_kind', 12)->default('auto');      // auto | standard | simplified
            $t->string('street', 190)->nullable();
            $t->string('building_no', 10)->nullable();
            $t->string('additional_no', 10)->nullable();
            $t->string('district', 120)->nullable();
            $t->string('city', 120)->nullable();
            $t->string('postal_code', 10)->nullable();
            $t->string('country_code', 2)->default('SA');
            $t->string('id_scheme', 3)->nullable();
            $t->string('id_value', 40)->nullable();
            $t->string('updated_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['partner_type', 'partner_id'], 'tax_parties_partner_uq');
            $t->index(['partner_id'], 'tax_parties_id_ix');
            $t->index(['fiscal_position_id'], 'tax_parties_position_ix');
        });

        Schema::create('tax_document_codes', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('doc_type', 30);                           // CustomerInvoice | SupplierInvoice | DebitNote | Expense | FuelRecord | MaintenanceOrder
            $t->string('doc_id', 40);
            $t->ulid('tax_code_id');
            $t->string('reason', 300)->nullable();
            $t->string('set_by')->nullable();
            $t->dateTime('set_at', 3)->useCurrent();
            $t->unique(['doc_type', 'doc_id'], 'tax_document_codes_doc_uq');
            $t->index(['doc_id'], 'tax_document_codes_id_ix');
        });

        // ───────────── the VAT return ─────────────
        Schema::create('tax_returns', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('period_code', 10);                        // 2026-08 | 2026-Q3
            $t->string('uniq', 50)->unique();                     // = period_code while alive; "<code>:<id>" once cancelled
            $t->string('frequency', 10);
            $t->date('period_from');
            $t->date('period_to');
            $t->string('status', 12)->default('draft');           // draft | reviewed | filed | locked | cancelled
            $t->json('figures')->nullable();                      // the form, the corrections and the reconciliation as last computed
            $t->string('signature', 64)->nullable();              // fingerprint of what a draft's figures depend on
            $t->decimal('output_vat', 16, 2)->default(0);
            $t->decimal('input_vat', 16, 2)->default(0);
            $t->decimal('corrections_vat', 16, 2)->default(0);
            $t->decimal('credit_carried', 16, 2)->default(0);     // line 15, typed by a person
            $t->decimal('net_due', 16, 2)->default(0);
            $t->decimal('ledger_output', 16, 2)->default(0);
            $t->decimal('ledger_input', 16, 2)->default(0);
            $t->decimal('unexplained', 16, 2)->default(0);
            $t->dateTime('computed_at', 3)->nullable();
            $t->string('reviewed_by')->nullable();
            $t->dateTime('reviewed_at', 3)->nullable();
            $t->string('filed_reference', 80)->nullable();
            $t->date('filed_on')->nullable();
            $t->string('filed_by')->nullable();
            $t->dateTime('filed_at', 3)->nullable();
            $t->string('locked_by')->nullable();
            $t->dateTime('locked_at', 3)->nullable();
            $t->string('cancelled_by')->nullable();
            $t->dateTime('cancelled_at', 3)->nullable();
            $t->text('cancel_reason')->nullable();
            $t->text('notes')->nullable();
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->index(['status', 'period_from'], 'tax_returns_status_ix');
            $t->index(['period_from', 'period_to'], 'tax_returns_period_ix');
        });

        Schema::create('tax_return_entries', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('return_id');
            $t->string('entry_key', 80);                          // the journal id, or "doc:<type>:<id>" for a document without one
            $t->string('journal_id', 40)->nullable();
            $t->string('journal_number', 40)->nullable();
            $t->date('entry_date');
            $t->string('doc_type', 30);
            $t->string('doc_id', 40);
            $t->string('doc_number', 60);
            $t->string('doc_kind', 20);                           // invoice | credit_note | supplier_invoice | debit_note | expense | fuel | maintenance
            $t->boolean('reversal')->default(false);
            $t->boolean('late')->default(false);                  // of an earlier, already declared period: a correction (line 14)
            $t->string('late_of_period', 10)->nullable();
            $t->boolean('notional')->default(false);              // reverse charge: VAT that is not in the books
            $t->string('side', 10);                               // sale | purchase
            $t->unsignedTinyInteger('box')->nullable();
            $t->string('col', 12);                                // amount | adjustment
            $t->decimal('base', 16, 2)->default(0);
            $t->decimal('vat', 16, 2)->default(0);                // the ledger's figure (signed)
            $t->decimal('doc_vat', 16, 2)->default(0);            // what the document says (signed)
            $t->decimal('rate', 8, 4)->default(0);
            $t->string('code_id', 40)->nullable();
            $t->string('code', 30)->nullable();
            $t->string('classified', 10);                         // explicit | partner | position | default | none | nobox
            $t->string('partner_type', 10)->nullable();
            $t->string('partner_id', 40)->nullable();
            $t->string('partner_name', 190)->nullable();
            $t->index(['return_id', 'late', 'box'], 'tax_return_entries_box_ix');
            $t->index(['return_id', 'entry_date'], 'tax_return_entries_date_ix');
            $t->index(['doc_type', 'doc_id'], 'tax_return_entries_doc_ix');
            $t->index(['entry_key'], 'tax_return_entries_key_ix');
            $t->index(['code_id'], 'tax_return_entries_code_ix');
        });

        Schema::create('tax_return_adjustments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('return_id');
            $t->unsignedTinyInteger('box');
            $t->decimal('base', 16, 2)->default(0);
            $t->decimal('vat', 16, 2)->default(0);
            $t->text('reason');
            $t->string('ledger_line_id', 40)->nullable();         // the VAT-account movement it takes into the return
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('voided_at', 3)->nullable();
            $t->string('voided_by')->nullable();
            $t->text('void_reason')->nullable();
            $t->index(['return_id'], 'tax_return_adjustments_return_ix');
            $t->index(['ledger_line_id'], 'tax_return_adjustments_line_ix');
        });

        Schema::create('tax_return_ledger_notes', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('return_id');
            $t->string('journal_line_id', 40);
            $t->string('treatment', 20);                          // settlement | opening | other
            $t->text('reason');
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('voided_at', 3)->nullable();
            $t->string('voided_by')->nullable();
            $t->index(['return_id', 'journal_line_id'], 'tax_return_notes_line_ix');
            $t->index(['journal_line_id'], 'tax_return_notes_jl_ix');
        });

        // ───────────── e-invoicing ─────────────
        Schema::create('tax_einvoice_units', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('name', 120);
            $t->string('serial', 190);                            // "1-maker|2-model|3-serial"
            $t->string('environment', 12)->default('sandbox');    // sandbox | simulation | production
            $t->string('invoice_types', 4)->default('1100');      // standard · simplified · 0 · 0
            $t->string('branch_name', 128)->nullable();
            $t->string('location', 128)->nullable();
            $t->string('industry', 128)->nullable();
            $t->string('status', 16)->default('not_onboarded');  // not_onboarded | csr_ready | compliance | compliant | active | retired
            $t->boolean('is_default')->default(false);
            $t->boolean('sending')->default(true);
            $t->unsignedBigInteger('icv')->default(0);            // the counter of the last document made
            $t->string('last_hash', 100)->nullable();             // its hash: the "previous hash" of the next one
            $t->string('last_einvoice_id', 40)->nullable();
            // SECRETS — written encrypted with the application key, hidden from every array / JSON form, never logged
            $t->text('private_key')->nullable();
            $t->text('csr')->nullable();
            $t->text('compliance_token')->nullable();
            $t->text('compliance_secret')->nullable();
            $t->text('compliance_request_id')->nullable();
            $t->text('production_token')->nullable();
            $t->text('production_secret')->nullable();
            $t->text('production_request_id')->nullable();
            // what is shown of the certificate (none of it is secret)
            $t->string('cert_serial', 80)->nullable();
            $t->string('cert_issuer', 300)->nullable();
            $t->string('cert_subject', 300)->nullable();
            $t->dateTime('cert_not_before', 3)->nullable();
            $t->dateTime('cert_not_after', 3)->nullable();
            $t->json('compliance_checks')->nullable();
            $t->dateTime('retired_at', 3)->nullable();
            $t->string('retired_by')->nullable();
            $t->text('retire_reason')->nullable();
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->index(['is_default', 'status'], 'tax_einvoice_units_default_ix');
        });

        Schema::create('tax_einvoices', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('unit_id');
            $t->unsignedBigInteger('icv');                        // the invoice counter value in the unit's sequence
            $t->string('uuid', 36)->unique();
            $t->string('doc_type', 30);
            $t->string('doc_id', 40);
            $t->string('doc_number', 60);
            $t->string('doc_kind', 12);                           // invoice | credit_note | debit_note
            $t->string('current_key', 80)->nullable()->unique();  // "<doc type>:<doc id>" while it is THE e-invoice of its document
            $t->string('type_code', 3);                           // 388 | 381 | 383
            $t->string('transaction_code', 7);                    // 0100000 | 0200000 …
            $t->string('profile', 10);                            // standard | simplified
            $t->dateTime('issue_at', 3);
            $t->string('customer_id', 40)->nullable();
            $t->string('buyer_name', 190)->nullable();
            $t->string('buyer_vat', 20)->nullable();
            $t->decimal('net', 16, 2)->default(0);
            $t->decimal('vat', 16, 2)->default(0);
            $t->decimal('total', 16, 2)->default(0);
            $t->string('currency', 3)->default('SAR');
            $t->string('hash', 100);                              // base64 SHA-256 of the canonical document
            $t->string('prev_hash', 100);
            $t->text('qr');
            $t->longText('xml');
            $t->longText('cleared_xml')->nullable();              // the copy the authority stamped and returned
            $t->boolean('signed')->default(false);
            $t->boolean('is_current')->default(true);
            $t->integer('attempt_no')->default(1);
            $t->string('superseded_by_id', 40)->nullable();
            $t->string('status', 20)->default('not_sent');        // not_sent | queued | reported | cleared | accepted_warnings | rejected | withdrawn
            $t->string('via', 12)->nullable();                    // reporting | clearance
            $t->integer('warnings')->default(0);
            $t->json('messages')->nullable();
            $t->integer('http_status')->nullable();
            $t->dateTime('sent_at', 3)->nullable();
            $t->dateTime('accepted_at', 3)->nullable();
            $t->integer('attempts')->default(0);
            $t->dateTime('next_attempt_at', 3)->nullable();
            $t->text('last_error')->nullable();
            $t->json('issues')->nullable();                       // what the document lacks against the authority's rules
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['unit_id', 'icv'], 'tax_einvoices_counter_uq');              // a counter is never used twice
            $t->index(['doc_type', 'doc_id', 'is_current'], 'tax_einvoices_doc_ix');
            $t->index(['unit_id', 'status', 'icv'], 'tax_einvoices_queue_ix');
            $t->index(['is_current', 'status'], 'tax_einvoices_status_ix');
            $t->index(['issue_at'], 'tax_einvoices_issue_ix');
            $t->index(['customer_id'], 'tax_einvoices_customer_ix');
        });

        Schema::create('tax_einvoice_attempts', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('einvoice_id', 40)->nullable();            // null: a call of the onboarding
            $t->ulid('unit_id');
            $t->string('api', 20);                                // reporting | clearance | compliance | compliance.csid | production.csid | production.renew
            $t->string('environment', 12);
            $t->integer('http_status')->nullable();
            $t->string('outcome', 16);
            $t->json('messages')->nullable();
            $t->text('error')->nullable();
            $t->integer('duration_ms')->default(0);
            $t->dateTime('at', 3)->useCurrent();
            $t->string('by')->nullable();
            $t->index(['einvoice_id', 'at'], 'tax_einvoice_attempts_doc_ix');
            $t->index(['unit_id', 'at'], 'tax_einvoice_attempts_unit_ix');
        });

        // ───────────── the history that came from Odoo ─────────────
        Schema::create('tax_einvoice_history', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('source_system', 20);
            $t->string('external_ref', 40);
            $t->string('format_code', 40)->nullable();
            $t->string('format_name', 120)->nullable();
            $t->string('move_ref', 40)->nullable();
            $t->string('move_number', 80)->nullable();
            $t->string('move_type', 20)->nullable();
            $t->string('archive_kind', 40)->nullable();
            $t->string('partner_name', 190)->nullable();
            $t->string('partner_ref', 40)->nullable();
            $t->string('customer_id', 40)->nullable();
            $t->date('invoice_date')->nullable();
            $t->decimal('amount_total', 16, 2)->nullable();
            $t->decimal('amount_tax', 16, 2)->nullable();
            $t->string('uuid', 64)->nullable();
            $t->unsignedBigInteger('chain_index')->nullable();
            $t->dateTime('confirmed_at', 3)->nullable();
            $t->string('state', 20)->nullable();                  // Odoo's own
            $t->string('status', 20);                             // accepted | not_sent | error | cancelling | cancelled
            $t->string('blocking_level', 20)->nullable();
            $t->text('error')->nullable();
            $t->string('attachment_ref', 40)->nullable();
            $t->string('attachment_name', 255)->nullable();
            $t->dateTime('source_created_at', 3)->nullable();
            $t->dateTime('source_updated_at', 3)->nullable();
            $t->dateTime('source_deleted_at', 3)->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'tax_einvoice_history_source_uq');
            $t->index(['invoice_date'], 'tax_einvoice_history_date_ix');
            $t->index(['status'], 'tax_einvoice_history_status_ix');
            $t->index(['customer_id'], 'tax_einvoice_history_customer_ix');
            $t->index(['move_number'], 'tax_einvoice_history_number_ix');
        });

        $this->caseInsensitiveText();
        PermissionSync::ensure();   // page.tax · vatreturn.manage · vatreturn.file · einvoice.manage with their default role grants
        TaxBootstrap::ensure();     // the first dictionary: the VAT group and the codes every OPS document needs
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
