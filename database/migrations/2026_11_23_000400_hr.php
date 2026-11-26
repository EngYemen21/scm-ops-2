<?php

use App\Hr\HrBootstrap;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * People and expenses (docs/odoo/WAVE2.md §7): the staff directory, the personal file and the contracts (their own
 * tables, read only with `hr.private`), employee documents with expiry alerts, and expense claims that end as
 * expense vouchers of the finance module.
 *
 * Every table that Odoo's records are projected into carries `source_system` (`odoo` or null) + `external_ref`
 * (the Odoo id as text): while the area follows Odoo those rows are Odoo's (App\Hr\Support\Source).
 * Payroll is not here on purpose: it is not part of this section.
 */
return new class extends Migration
{
    private const TABLES = ['hr_departments', 'hr_jobs', 'hr_employees', 'hr_employee_private', 'hr_contracts', 'hr_documents', 'hr_files', 'hr_alerts',
        'hr_expense_categories', 'hr_claims', 'hr_claim_lines'];

    public function up(): void
    {
        $source = function (Blueprint $t, string $table) {
            $t->string('source_system', 20)->nullable();   // odoo | null (created in OPS)
            $t->string('external_ref', 40)->nullable();    // the id in the source system
            $t->unique(['source_system', 'external_ref'], "{$table}_source_uq");
        };
        $stamps = function (Blueprint $t) {
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
        };

        Schema::create('hr_departments', function (Blueprint $t) use ($source, $stamps) {
            $t->ulid('id')->primary();
            $t->string('name_ar');
            $t->string('name_en');
            $t->ulid('parent_id')->nullable();
            $t->ulid('manager_id')->nullable();             // hr_employees.id
            $t->text('notes')->nullable();                  // OPS's own, also on a department that came from Odoo
            $t->boolean('active')->default(true);
            $source($t, 'hr_departments');
            $stamps($t);
            $t->index(['parent_id'], 'hr_departments_parent_ix');
        });

        Schema::create('hr_jobs', function (Blueprint $t) use ($source, $stamps) {
            $t->ulid('id')->primary();
            $t->string('name_ar');
            $t->string('name_en');
            $t->ulid('department_id')->nullable();
            $t->text('description')->nullable();
            $t->boolean('active')->default(true);
            $source($t, 'hr_jobs');
            $stamps($t);
        });

        // the WORK part of an employee: what colleagues may know (page.hr)
        Schema::create('hr_employees', function (Blueprint $t) use ($source, $stamps) {
            $t->ulid('id')->primary();
            $t->string('code', 30)->unique();               // EMP-YYYY-NNNNN
            $t->string('employee_no', 40)->nullable();      // the company's own staff number
            $t->string('name_ar');
            $t->string('name_en');
            $t->ulid('department_id')->nullable();
            $t->ulid('job_id')->nullable();
            $t->string('job_title')->nullable();
            $t->ulid('manager_id')->nullable();
            $t->string('work_email')->nullable();
            $t->string('work_phone', 40)->nullable();
            $t->string('work_mobile', 40)->nullable();
            $t->string('work_location')->nullable();
            $t->date('start_date')->nullable();
            $t->string('status', 12)->default('active');    // active | left | inactive
            $t->ulid('user_id')->nullable()->unique();      // the OPS login of this person
            $t->ulid('driver_id')->nullable()->unique();    // the driver record of this person
            $t->text('notes')->nullable();
            $source($t, 'hr_employees');
            $stamps($t);
            $t->index(['status', 'name_ar'], 'hr_employees_status_ix');
            $t->index(['department_id'], 'hr_employees_department_ix');
            $t->index(['manager_id'], 'hr_employees_manager_ix');
        });

        // the PERSONAL file: its own table, so that no directory query can return it by accident (hr.private)
        Schema::create('hr_employee_private', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('employee_id')->unique();
            $t->string('nationality', 80)->nullable();
            $t->string('identity_no', 40)->nullable();      // national id / residence permit number
            $t->date('identity_expiry')->nullable();
            $t->string('passport_no', 40)->nullable();
            $t->date('passport_expiry')->nullable();
            $t->date('birth_date')->nullable();
            $t->string('gender', 10)->nullable();           // male | female
            $t->string('marital_status', 12)->nullable();   // single | married | divorced | widowed
            $t->integer('children')->nullable();
            $t->text('home_address')->nullable();
            $t->string('private_phone', 40)->nullable();
            $t->string('private_email')->nullable();
            $t->string('bank_name', 120)->nullable();
            $t->string('bank_account', 60)->nullable();     // IBAN
            $t->string('emergency_contact')->nullable();
            $t->string('emergency_phone', 40)->nullable();
            $t->date('end_date')->nullable();               // the day the person left
            $t->string('departure_reason')->nullable();
            $t->text('notes')->nullable();
            $t->string('updated_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
        });

        Schema::create('hr_contracts', function (Blueprint $t) use ($source, $stamps) {
            $t->ulid('id')->primary();
            $t->string('number', 40)->unique();             // HRC-YYYY-NNNNN (OD-CT-<id> when it came from Odoo)
            $t->ulid('employee_id');
            $t->string('reference')->nullable();            // the reference written on the paper contract
            $t->string('type', 20)->default('permanent');   // permanent | fixed_term | temporary | part_time | trainee | other
            $t->string('type_label')->nullable();           // the type as the source system words it
            $t->string('job_title')->nullable();
            $t->ulid('department_id')->nullable();
            $t->date('start_date');
            $t->date('end_date')->nullable();
            $t->date('trial_end_date')->nullable();
            $t->decimal('wage', 14, 2)->default(0);
            $t->decimal('housing_allowance', 14, 2)->default(0);
            $t->decimal('transport_allowance', 14, 2)->default(0);
            $t->decimal('other_allowances', 14, 2)->default(0);
            $t->string('currency', 3)->default('SAR');
            $t->string('state', 12)->default('draft');      // draft | running | expired | cancelled
            $t->text('notes')->nullable();
            $t->string('closed_by')->nullable();
            $t->dateTime('closed_at', 3)->nullable();
            $t->text('close_reason')->nullable();
            $source($t, 'hr_contracts');
            $stamps($t);
            $t->index(['employee_id', 'state'], 'hr_contracts_employee_ix');
            $t->index(['state', 'end_date'], 'hr_contracts_state_ix');
        });

        // a paper of an employee with (usually) an expiry date. A private one belongs to the personal file.
        Schema::create('hr_documents', function (Blueprint $t) use ($stamps) {
            $t->ulid('id')->primary();
            $t->ulid('employee_id');
            $t->string('kind', 30);                         // App\Hr\Models\Document::KINDS
            $t->string('title')->nullable();
            $t->string('number', 60)->nullable();
            $t->date('issue_date')->nullable();
            $t->date('expiry_date')->nullable();
            $t->boolean('is_private')->default(false);
            $t->string('status', 10)->default('active');    // active | replaced | void
            $t->ulid('replaced_by_id')->nullable();         // the renewal that took its place
            $t->text('notes')->nullable();
            $t->string('voided_by')->nullable();
            $t->dateTime('voided_at', 3)->nullable();
            $t->text('void_reason')->nullable();
            $stamps($t);
            $t->index(['employee_id', 'status'], 'hr_documents_employee_ix');
            $t->index(['status', 'expiry_date'], 'hr_documents_expiry_ix');
        });

        // files of this section (a document's scan, a receipt, a paper of the personal file). They are NOT rows of the
        // generic `attachments` table on purpose: that table is served to every signed-in user.
        Schema::create('hr_files', function (Blueprint $t) use ($source) {
            $t->ulid('id')->primary();
            $t->string('owner_type', 12);                   // employee | contract | document | claim | claim_line
            $t->ulid('owner_id');
            $t->string('name');
            $t->string('mime', 150)->nullable();
            $t->bigInteger('size')->default(0);
            $t->string('storage', 6)->default('ops');       // ops (FileStore) | odoo (content kept by the Odoo backup, odoo_files)
            $t->string('storage_key')->nullable();
            $t->string('storage_status', 12)->nullable();   // stored | uploaded (FileStore::KEPT)
            $source($t, 'hr_files');
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->string('removed_by')->nullable();
            $t->dateTime('removed_at', 3)->nullable();
            $t->index(['owner_type', 'owner_id'], 'hr_files_owner_ix');
        });

        // one open alert per (what expires): a document, the identity / passport of the personal file, a contract
        Schema::create('hr_alerts', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('employee_id');
            $t->string('category', 12);                     // document | identity | passport | contract
            $t->ulid('ref_id');                             // hr_documents.id | hr_employee_private.id | hr_contracts.id
            $t->string('kind', 30)->nullable();             // the document kind, for the wording
            $t->string('stage', 8);                         // d60 | d30 | d7 | expired
            $t->date('due_date');
            $t->boolean('is_private')->default(false);
            $t->string('status', 10)->default('open');      // open | resolved
            $t->string('notified_stage', 8)->nullable();    // the last stage people were told about
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->dateTime('resolved_at', 3)->nullable();
            $t->index(['status', 'due_date'], 'hr_alerts_status_ix');
            $t->index(['category', 'ref_id', 'status'], 'hr_alerts_ref_ix');
            $t->index(['employee_id'], 'hr_alerts_employee_ix');
        });

        Schema::create('hr_expense_categories', function (Blueprint $t) use ($source, $stamps) {
            $t->ulid('id')->primary();
            $t->string('code', 40)->unique();
            $t->string('name_ar');
            $t->string('name_en');
            $t->string('account_code', 20)->nullable();     // fin_accounts.code the lines of this category are charged to
            $t->double('vat_pct')->default(0);              // proposed on a new line
            $t->boolean('active')->default(true);
            $source($t, 'hr_expense_categories');
            $stamps($t);
        });

        Schema::create('hr_claims', function (Blueprint $t) use ($source, $stamps) {
            $t->ulid('id')->primary();
            $t->string('number', 40)->unique();             // CLM-YYYY-NNNNN (OD-ES-<id> / OD-EX-<id> when it came from Odoo)
            $t->string('title');
            $t->ulid('employee_id')->nullable();            // whose expenses these are
            $t->ulid('claimant_user_id')->nullable();       // the OPS login of that person, when there is one
            $t->ulid('department_id')->nullable();
            $t->string('state', 12)->default('draft');      // draft | submitted | approved | posted | paid | refused | cancelled
            $t->string('currency', 3)->default('SAR');
            $t->decimal('amount', 16, 2)->default(0);       // before VAT
            $t->decimal('vat_amount', 16, 2)->default(0);
            $t->decimal('total', 16, 2)->default(0);
            $t->decimal('employee_due', 16, 2)->default(0); // lines the employee paid: what the company owes back
            $t->decimal('company_paid', 16, 2)->default(0); // lines paid with the company's money
            $t->text('notes')->nullable();
            $t->dateTime('submitted_at', 3)->nullable();
            $t->string('decided_by')->nullable();
            $t->ulid('decided_by_id')->nullable();
            $t->dateTime('decided_at', 3)->nullable();
            $t->text('decision_note')->nullable();          // why it was refused or returned
            $t->string('posted_by')->nullable();
            $t->dateTime('posted_at', 3)->nullable();
            $t->string('posted_in', 6)->nullable();         // ops | odoo — whose books carry it
            $t->date('accounting_date')->nullable();
            $t->string('settlement', 10)->nullable();       // accrued | bank | none — how the posting settled what the employee paid
            $t->string('paid_by')->nullable();
            $t->dateTime('paid_at', 3)->nullable();
            $t->string('payment_ref', 80)->nullable();
            $t->string('payment_method', 12)->nullable();   // bank | cash | cheque
            $t->string('payment_account', 20)->nullable();  // fin_bank_accounts.code the money left from
            $t->string('source_state', 20)->nullable();     // the state as the source system words it
            $t->ulid('created_by_id')->nullable();
            $source($t, 'hr_claims');
            $stamps($t);
            $t->index(['state', 'created_at'], 'hr_claims_state_ix');
            $t->index(['employee_id'], 'hr_claims_employee_ix');
            $t->index(['claimant_user_id'], 'hr_claims_claimant_ix');
            $t->index(['created_by_id'], 'hr_claims_creator_ix');
        });

        Schema::create('hr_claim_lines', function (Blueprint $t) use ($source) {
            $t->ulid('id')->primary();
            $t->ulid('claim_id');
            $t->integer('line_no');
            $t->date('expense_date');
            $t->ulid('category_id')->nullable();
            $t->string('description', 500);
            $t->decimal('amount', 16, 2);                    // before VAT
            $t->double('vat_pct')->default(0);
            $t->decimal('vat_amount', 16, 2)->default(0);
            $t->decimal('total', 16, 2);
            $t->string('paid_by', 10)->default('employee');  // employee | company
            $t->string('account_code', 20)->nullable();      // the account it was posted to
            $t->ulid('finance_expense_id')->nullable();      // fin_expenses.id — the voucher of this line
            $t->string('finance_expense_number')->nullable();
            $t->string('source_account')->nullable();        // the account in the source system's books (history)
            $t->string('source_state', 20)->nullable();
            $source($t, 'hr_claim_lines');
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->index(['claim_id', 'line_no'], 'hr_claim_lines_claim_ix');
            $t->index(['finance_expense_id'], 'hr_claim_lines_voucher_ix');
        });

        // PostgreSQL: text compares case-insensitively everywhere in this application (see 2026_09_17_000000_pgsql_compatibility)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS citext');
            foreach (self::TABLES as $table) {
                $columns = DB::select("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ?
                    AND data_type IN ('character', 'character varying', 'text') ORDER BY ordinal_position", [$table]);
                if ($columns) {
                    DB::statement(sprintf('ALTER TABLE "%s" %s', $table, implode(', ', array_map(fn ($c) => sprintf('ALTER COLUMN "%1$s" TYPE citext USING rtrim("%1$s")::citext', $c->column_name), $columns))));
                }
            }
        }

        HrBootstrap::ensure();
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
