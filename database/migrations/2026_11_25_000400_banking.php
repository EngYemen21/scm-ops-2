<?php

use App\Support\PermissionSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «البنوك والمطابقة» — bank statements and their reconciliation with the ledger (docs/odoo/WAVE4.md §Q4).
 *
 * The bank accounts themselves are finance's (`fin_bank_accounts`) and the ledger is finance's (`fin_journals`,
 * `fin_journal_lines`): this section only reads them and posts through the ledger service. Its own tables:
 *
 *   bank_source_journals   the source system's bank and cash journals, and the OPS bank account each of them IS
 *   bank_statements        a statement of one bank account for a period (open → reconciled → open · open → void · history)
 *   bank_statement_lines   its movements; `fingerprint` refuses a line imported twice, `match_id` the match that explains it
 *   bank_imports           one import of a file: counts and, for what was not taken, row numbers and reasons — no figure, no text
 *   bank_import_profiles   a saved column mapping per bank account
 *   bank_matches           a confirmed match (lines against ledger entries, equal to the cent), undone with a reason
 *   bank_match_items       its two sides; the UNIQUE `live_*` columns make the database refuse a second standing match
 *   bank_writeoff_rules    where a difference may go and up to how much (posts a journal through the ledger service)
 *   bank_payment_terms     the source system's payment terms, kept as a dictionary
 *
 * Money is decimal(16,2) and compared in hundredths by the code; nothing is deleted that can be voided (a statement is
 * voided, a match undone, a rule switched off). Records that came from Odoo carry `source_system` + `external_ref`.
 */
return new class extends Migration
{
    private const TABLES = ['bank_match_items', 'bank_matches', 'bank_imports', 'bank_import_profiles', 'bank_statement_lines', 'bank_statements', 'bank_source_journals', 'bank_writeoff_rules', 'bank_payment_terms'];

    public function up(): void
    {
        Schema::create('bank_source_journals', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('code', 20)->nullable();
            $t->string('name', 190);
            $t->string('kind', 10);                              // bank | cash
            $t->string('currency', 3)->nullable();
            $t->string('bank_name', 190)->nullable();
            $t->string('account_number', 64)->nullable();        // as the source holds it — shown in full only to a banking permission
            $t->string('account_key', 64)->nullable();           // letters and digits, upper case: what a link compares
            $t->string('ledger_account', 190)->nullable();       // the source's own account label (Odoo's chart is not OPS's)
            $t->boolean('active')->default(true);
            $t->ulid('bank_account_id')->nullable();             // the OPS bank account the journal IS (OPS's own)
            $t->string('linked_by', 20)->nullable();             // iban | number | name | manual | adopted
            $t->dateTime('linked_at', 3)->nullable();
            $t->string('linked_user')->nullable();
            $t->boolean('auto_link')->default(true);             // false once a person removed a link: the projection does not make it again
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->dateTime('source_updated_at', 3)->nullable();
            $t->dateTime('source_deleted_at', 3)->nullable();
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'bank_source_journals_source_uq');
            $t->unique(['bank_account_id'], 'bank_source_journals_bank_uq');   // one journal ↔ one bank account
            $t->index(['account_key'], 'bank_source_journals_key_ix');
        });

        Schema::create('bank_statements', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('number', 40)->unique('bank_statements_number_uq');
            $t->ulid('bank_account_id')->nullable();             // null only for history of a journal no OPS bank account answers to yet
            $t->ulid('source_journal_id')->nullable();
            $t->string('name', 190)->nullable();
            $t->date('date_from');
            $t->date('date_to');
            $t->decimal('opening_balance', 16, 2)->nullable();   // always set on an OPS statement; history may lack it
            $t->decimal('closing_balance', 16, 2)->nullable();
            $t->string('currency', 3)->default('SAR');
            $t->string('status', 12)->default('open');           // open | reconciled | void | history
            $t->text('notes')->nullable();
            $t->dateTime('reconciled_at', 3)->nullable();
            $t->string('reconciled_by')->nullable();
            $t->dateTime('reopened_at', 3)->nullable();
            $t->string('reopened_by')->nullable();
            $t->string('reopen_reason', 500)->nullable();
            $t->dateTime('voided_at', 3)->nullable();
            $t->string('voided_by')->nullable();
            $t->string('void_reason', 500)->nullable();
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 60)->nullable();          // "<statement id>" or "loose:<journal id>"
            $t->dateTime('source_updated_at', 3)->nullable();
            $t->dateTime('source_deleted_at', 3)->nullable();
            $t->string('created_by')->nullable();
            $t->string('updated_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'bank_statements_source_uq');
            $t->index(['bank_account_id', 'status', 'date_from'], 'bank_statements_bank_ix');
            $t->index(['source_journal_id'], 'bank_statements_journal_ix');
            $t->index(['date_to'], 'bank_statements_to_ix');
        });

        Schema::create('bank_imports', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('statement_id');
            $t->ulid('bank_account_id')->nullable();
            $t->ulid('profile_id')->nullable();
            $t->string('file_name', 200);
            $t->string('file_hash', 64);
            $t->string('format', 10);                            // csv | xlsx
            $t->integer('rows_total')->default(0);
            $t->integer('rows_taken')->default(0);
            $t->integer('rows_duplicate')->default(0);
            $t->integer('rows_refused')->default(0);
            $t->json('report')->nullable();                      // [{row, status, code}] of what was not taken
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->index(['statement_id', 'created_at'], 'bank_imports_statement_ix');
        });

        Schema::create('bank_statement_lines', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('statement_id');
            $t->ulid('bank_account_id')->nullable();
            $t->integer('line_no')->default(0);
            $t->string('kind', 10)->default('line');             // line | opening
            $t->date('txn_date');
            $t->date('value_date')->nullable();
            $t->decimal('amount', 16, 2);                        // + money in · − money out
            $t->decimal('balance_after', 16, 2)->nullable();
            $t->string('reference', 120)->nullable();
            $t->string('description', 500)->nullable();
            $t->string('partner_name', 190)->nullable();
            $t->string('partner_account', 64)->nullable();       // shown in full only to a banking permission
            $t->string('txn_type', 60)->nullable();
            $t->string('fingerprint', 64)->nullable();           // released (null) when its statement is voided
            $t->ulid('import_id')->nullable();
            $t->ulid('match_id')->nullable();
            $t->string('source_state', 12)->nullable();          // history: reconciled | open | cancelled (what the source says)
            $t->string('source_document', 120)->nullable();
            $t->json('extra')->nullable();
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->dateTime('source_updated_at', 3)->nullable();
            $t->dateTime('source_deleted_at', 3)->nullable();
            $t->string('created_by')->nullable();
            $t->string('updated_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['fingerprint'], 'bank_statement_lines_fingerprint_uq');
            $t->unique(['source_system', 'external_ref'], 'bank_statement_lines_source_uq');
            $t->index(['statement_id', 'line_no'], 'bank_statement_lines_statement_ix');
            $t->index(['bank_account_id', 'match_id', 'txn_date'], 'bank_statement_lines_bank_ix');
            $t->index(['match_id'], 'bank_statement_lines_match_ix');
            $t->index(['import_id'], 'bank_statement_lines_import_ix');
        });

        Schema::create('bank_import_profiles', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('bank_account_id')->nullable();             // null = offered for every bank account
            $t->string('name', 120);
            $t->json('options')->nullable();
            $t->json('mapping')->nullable();
            $t->boolean('is_default')->default(false);
            $t->boolean('active')->default(true);
            $t->string('created_by')->nullable();
            $t->string('updated_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->index(['bank_account_id', 'active'], 'bank_import_profiles_bank_ix');
        });

        Schema::create('bank_matches', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('number', 40)->unique('bank_matches_number_uq');
            $t->ulid('bank_account_id');
            $t->string('status', 10)->default('active');         // active | undone
            $t->string('kind', 10)->default('match');            // match | contra | system
            $t->string('origin', 12)->default('manual');         // manual | suggestion | auto | system
            $t->decimal('statement_total', 16, 2)->default(0);
            $t->decimal('ledger_total', 16, 2)->default(0);      // with the write-off entry: equal to statement_total
            $t->decimal('writeoff_amount', 16, 2)->default(0);
            $t->ulid('writeoff_rule_id')->nullable();
            $t->ulid('writeoff_journal_id')->nullable();
            $t->ulid('writeoff_reversal_journal_id')->nullable();
            $t->date('cleared_date')->nullable();                // the day the match was complete (its latest side)
            $t->string('note', 1000)->nullable();
            $t->string('created_by')->nullable();
            $t->ulid('created_by_id')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->string('undone_by')->nullable();
            $t->dateTime('undone_at', 3)->nullable();
            $t->string('undo_reason', 500)->nullable();
            $t->index(['bank_account_id', 'status', 'cleared_date'], 'bank_matches_bank_ix');
            $t->index(['created_at'], 'bank_matches_created_ix');
        });

        Schema::create('bank_match_items', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('match_id');
            $t->string('side', 10);                              // statement | ledger
            $t->ulid('statement_line_id')->nullable();
            $t->ulid('live_statement_line_id')->nullable();      // = statement_line_id while the match stands
            $t->ulid('journal_line_id')->nullable();             // fin_journal_lines.id
            $t->ulid('live_journal_line_id')->nullable();        // = journal_line_id while the match stands
            $t->ulid('journal_id')->nullable();
            $t->decimal('amount', 16, 2);
            $t->date('item_date')->nullable();
            $t->string('label', 190)->nullable();
            $t->string('doc_type', 40)->nullable();
            $t->string('doc_id', 40)->nullable();
            $t->string('doc_number', 60)->nullable();
            $t->boolean('is_writeoff')->default(false);
            $t->unique(['live_statement_line_id'], 'bank_match_items_live_line_uq');
            $t->unique(['live_journal_line_id'], 'bank_match_items_live_entry_uq');
            $t->index(['match_id'], 'bank_match_items_match_ix');
            $t->index(['statement_line_id'], 'bank_match_items_line_ix');
            $t->index(['journal_line_id'], 'bank_match_items_entry_ix');
            $t->index(['doc_number'], 'bank_match_items_doc_ix');
        });

        Schema::create('bank_writeoff_rules', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('name_ar', 190);
            $t->string('name_en', 190);
            $t->string('kind', 10)->default('writeoff');         // writeoff | matching
            $t->string('nature', 4)->default('both');            // in | out | both
            $t->ulid('account_id')->nullable();                  // the OPS ledger account (OPS's own, also on a rule from Odoo)
            $t->string('label', 190)->nullable();
            $t->decimal('max_amount', 16, 2)->nullable();        // the largest difference it absorbs; none = it does not post
            $t->string('match_text', 250)->nullable();           // words that suggest the rule for a line
            $t->ulid('bank_account_id')->nullable();             // null = every bank account
            $t->integer('sequence')->default(10);
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
            $t->unique(['source_system', 'external_ref'], 'bank_writeoff_rules_source_uq');
            $t->index(['active', 'sequence'], 'bank_writeoff_rules_order_ix');
        });

        Schema::create('bank_payment_terms', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('name', 190);
            $t->text('note')->nullable();
            $t->integer('sequence')->default(0);
            $t->boolean('early_discount')->default(false);
            $t->decimal('discount_percent', 5, 2)->nullable();
            $t->integer('discount_days')->nullable();
            $t->integer('due_days')->nullable();
            $t->json('schedule')->nullable();                    // [{value, amount, days, delayType}]
            $t->boolean('active')->default(true);
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->dateTime('source_updated_at', 3)->nullable();
            $t->dateTime('source_deleted_at', 3)->nullable();
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'bank_payment_terms_source_uq');
        });

        $this->caseInsensitiveText();
        PermissionSync::ensure();   // page.banking · bankstatement.manage · bank.reconcile with their default role grants, on a database that predates them
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
