<?php

use App\Support\PermissionSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «العمولات» — sales commissions (docs/odoo/WAVE3.md, team P2).
 *
 *   commission_plans (+ rules, members)   who earns, on what base, at which rate or tiers, for which products / customers
 *   commission_assignments                whose sale a customer's (or a sales-platform representative's) invoice is
 *   commission_statements                 one per earner and month: draft → submitted → approved → paid
 *   commission_lines                      what a statement is made of: one line per invoice and rule, a negative line per
 *                                         credit note, a reversal of a line that no longer holds, an adjustment with a reason
 *
 * Odoo's history has the same home: a `sales.commission` is a statement (with the rate and the type it was calculated
 * at), a `commission.invoice.line` is a line of it, and its `invoice.commission.tracking` record is the line's
 * "processed at". Those rows carry `source_system = 'odoo'` + `external_ref` (the Odoo id) and are Odoo's while the
 * section follows Odoo. Nothing here is deleted: a plan is archived, an adjustment is voided, a payout is reversed.
 */
return new class extends Migration
{
    private const TABLES = ['commission_lines', 'commission_statements', 'commission_assignments', 'commission_plan_members', 'commission_plan_rules', 'commission_plans'];

    public function up(): void
    {
        Schema::create('commission_plans', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('code', 40)->unique();                  // CPL-YYYY-NNNNN
            $t->string('name_ar', 190);
            $t->string('name_en', 190);
            $t->string('earner_type', 12);                     // salesperson (earns on his own sales) | manager (earns on a team's sales)
            $t->ulid('beneficiary_id')->nullable();            // manager plan: the OPS user who earns …
            $t->string('beneficiary_name', 190)->nullable();   // … or the name Odoo gives when that person has no OPS user
            $t->string('beneficiary_ref', 40)->nullable();     // … and the Odoo user id
            $t->boolean('everyone')->default(false);           // no member list: every salesperson / every invoice
            $t->string('earn_on', 12)->default('invoiced');    // invoiced (when the invoice is issued) | collected (when it is fully paid)
            $t->string('base_kind', 8)->default('net');        // net (before tax) | gross (with tax) | margin (net − cost of goods)
            $t->boolean('net_of_returns')->default(true);      // a credit note takes its share back
            $t->date('valid_from');
            $t->date('valid_to')->nullable();
            $t->string('status', 10)->default('draft');        // draft | active | archived
            $t->text('notes')->nullable();
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 80)->nullable();
            $t->string('created_by')->nullable();
            $t->string('updated_by')->nullable();
            $t->dateTime('activated_at', 3)->nullable();
            $t->string('activated_by')->nullable();
            $t->dateTime('archived_at', 3)->nullable();
            $t->string('archived_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'commission_plans_source_uq');
            $t->index(['status', 'valid_from'], 'commission_plans_status_ix');
            $t->index(['beneficiary_id'], 'commission_plans_beneficiary_ix');
        });

        // the rate of a plan, by what was sold and to whom: the first rule (by sequence) that covers an invoice line decides
        Schema::create('commission_plan_rules', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('plan_id');
            $t->integer('sequence')->default(0);
            $t->string('scope', 10)->default('all');           // all | category | customer
            $t->json('scope_ids')->nullable();                 // ids of the product categories / customers the rule covers
            $t->string('rate_type', 8)->default('flat');       // flat | tiered
            $t->decimal('rate', 9, 4)->nullable();             // percent, for a flat rule
            $t->string('tier_mode', 12)->nullable();           // whole (the tier reached pays on everything) | progressive (each slice at its own rate)
            $t->json('tiers')->nullable();                     // [{ upTo: 100000 | null, rate: 1.5 }, …] on the month's base of this rule
            $t->string('label', 190)->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->index(['plan_id', 'sequence'], 'commission_plan_rules_plan_ix');
        });

        // the salespeople of a plan: the earners of a salesperson plan, the team of a manager plan
        Schema::create('commission_plan_members', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('plan_id');
            $t->ulid('user_id')->nullable();                   // an OPS user …
            $t->string('person_name', 190)->nullable();        // … or a name kept from Odoo
            $t->string('person_ref', 40)->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->index(['plan_id'], 'commission_plan_members_plan_ix');
            $t->index(['user_id'], 'commission_plan_members_user_ix');
        });

        // OPS documents name no salesperson: this says whose sale an invoice is (by its customer, or by the
        // representative's name the sales platform writes on the order)
        Schema::create('commission_assignments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('kind', 10);                            // customer | rep
            $t->string('subject', 190);                        // the customer id / the representative's name, normalised
            $t->string('subject_label', 250)->nullable();
            $t->ulid('user_id');
            $t->string('created_by')->nullable();
            $t->string('updated_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['kind', 'subject'], 'commission_assignments_subject_uq');
            $t->index(['user_id'], 'commission_assignments_user_ix');
        });

        Schema::create('commission_statements', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('number', 40)->unique();                // CMS-YYYY-NNNNN
            $t->string('reference', 120)->nullable();          // the reference the source system gave it (Odoo's COM/2025/0001)
            $t->string('slot', 120)->unique();                 // one statement per earner and month: `u:<user>:<period>`; `odoo:<id>` for history
            $t->ulid('beneficiary_id')->nullable();            // the OPS user who earns …
            $t->string('beneficiary_name', 190)->nullable();   // … or kept by name
            $t->string('beneficiary_ref', 40)->nullable();     // the Odoo user id
            $t->string('period', 7);                           // YYYY-MM
            $t->date('date_from');
            $t->date('date_to');
            $t->ulid('plan_id')->nullable();
            $t->string('earner_type', 12)->default('salesperson');
            $t->json('terms')->nullable();                     // the plan as it stood when the statement was calculated
            $t->string('status', 12)->default('draft');        // draft | submitted | approved | paid
            $t->integer('lines_count')->default(0);
            $t->decimal('base_total', 16, 2)->default(0);
            $t->decimal('commission_total', 16, 2)->default(0); // the calculated lines
            $t->decimal('adjustments_total', 16, 2)->default(0);
            $t->decimal('total', 16, 2)->default(0);           // what is paid out
            $t->string('currency', 3)->default('SAR');
            $t->dateTime('calculated_at', 3)->nullable();
            $t->string('calculated_by')->nullable();
            $t->dateTime('submitted_at', 3)->nullable();
            $t->string('submitted_by')->nullable();
            $t->dateTime('approved_at', 3)->nullable();
            $t->string('approved_by')->nullable();
            $t->text('approval_note')->nullable();
            $t->dateTime('returned_at', 3)->nullable();        // sent back to draft (by the approver, or reopened after approval)
            $t->string('returned_by')->nullable();
            $t->text('return_reason')->nullable();
            $t->dateTime('paid_at', 3)->nullable();
            $t->string('paid_by')->nullable();
            $t->date('payout_date')->nullable();
            $t->string('payout_reference', 80)->nullable();    // the bank transfer / cheque
            $t->string('payout_bank', 40)->nullable();         // code of the bank account or cash box it left from
            $t->string('payout_account', 40)->nullable();      // code of the expense account it was charged to
            $t->ulid('finance_expense_id')->nullable();        // the expense voucher of the finance module that posted it
            $t->string('finance_expense_number', 40)->nullable();
            $t->text('notes')->nullable();
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->dateTime('source_updated_at', 3)->nullable();
            $t->dateTime('source_deleted_at', 3)->nullable();   // it no longer exists in the system it came from
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'commission_statements_source_uq');
            $t->index(['beneficiary_id', 'period'], 'commission_statements_beneficiary_ix');
            $t->index(['status', 'period'], 'commission_statements_status_ix');
            $t->index(['period'], 'commission_statements_period_ix');
            $t->index(['plan_id'], 'commission_statements_plan_ix');
        });

        Schema::create('commission_lines', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('statement_id');
            $t->string('kind', 12);                            // invoice | credit_note | reversal | adjustment
            $t->ulid('beneficiary_id')->nullable();            // the statement's earner (the "was this invoice paid for already?" look-up)
            $t->ulid('plan_id')->nullable();
            $t->ulid('rule_id')->nullable();
            $t->string('rule_label', 190)->nullable();
            // the document that earned it
            $t->string('source_type', 20)->nullable();         // CustomerInvoice (finance) | OdooInvoice (account.move of the copy)
            $t->string('source_id', 40)->nullable();
            $t->string('doc_number', 80)->nullable();
            $t->date('doc_date')->nullable();
            $t->date('earned_on')->nullable();                 // the day that put it in a period: the invoice date, or the day it was fully paid
            $t->ulid('customer_id')->nullable();
            $t->string('customer_name', 250)->nullable();
            $t->string('customer_ref', 40)->nullable();        // the Odoo partner id
            $t->ulid('salesperson_id')->nullable();            // whose sale it is …
            $t->string('salesperson_name', 190)->nullable();
            $t->string('salesperson_ref', 40)->nullable();
            $t->string('salesperson_via', 12)->nullable();     // … and how that was known: rep | customer | creator | odoo
            // the figures
            $t->decimal('invoice_total', 16, 2)->nullable();   // the document's total with tax
            $t->decimal('base_amount', 16, 2)->default(0);     // what the rate applies to (negative on a credit note / reversal)
            $t->decimal('rate', 9, 4)->nullable();             // percent
            $t->decimal('commission', 18, 4)->default(0);
            $t->json('explain')->nullable();                   // how the figures were reached: parts of the base, the tier, late / reversal reason
            $t->ulid('reverses_id')->nullable();               // the line this one takes back
            $t->boolean('excluded')->default(false);           // listed, earns nothing (a person decided, with a reason)
            $t->string('exclude_reason', 250)->nullable();
            $t->string('payment_state', 20)->nullable();
            $t->text('reason')->nullable();                    // an adjustment's reason (mandatory)
            $t->dateTime('voided_at', 3)->nullable();          // an adjustment taken back: kept, counted no more
            $t->string('voided_by')->nullable();
            $t->text('void_reason')->nullable();
            $t->dateTime('processed_at', 3)->nullable();       // Odoo: when the invoice was confirmed for commission (its tracking record)
            $t->string('tracking_ref', 40)->nullable();        // Odoo: the id of that tracking record
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'commission_lines_source_uq');
            $t->index(['statement_id', 'kind', 'doc_date'], 'commission_lines_statement_ix');
            $t->index(['beneficiary_id', 'source_type', 'source_id'], 'commission_lines_granted_ix');
            $t->index(['source_type', 'source_id'], 'commission_lines_doc_ix');
            $t->index(['doc_number'], 'commission_lines_number_ix');
            $t->index(['customer_id'], 'commission_lines_customer_ix');
            $t->index(['salesperson_id'], 'commission_lines_salesperson_ix');
            $t->index(['reverses_id'], 'commission_lines_reverses_ix');
            $t->index(['tracking_ref'], 'commission_lines_tracking_ix');
        });

        $this->caseInsensitiveText();
        PermissionSync::ensure();   // page.commissions · commission.manage · commission.approve, on a database that predates them
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
