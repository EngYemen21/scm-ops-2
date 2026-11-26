<?php

use App\Payroll\PayrollBootstrap;
use App\Support\PermissionSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «الرواتب والإجازات والحضور» — payroll, time off, attendance, skills (docs/odoo/WAVE3.md, P6). Built on People &
 * expenses (hr_employees, hr_contracts — read through App\Hr\Services\Workforce, never changed from here).
 *
 *   work time    pay_schedules (+ days) · pay_holidays
 *   time off     pay_leave_types · pay_leaves · pay_leave_entries (the LEDGER: allocation, taken, restored, adjustment)
 *   attendance   pay_attendance · pay_attendance_changes (every correction: who, what, why — the original is kept)
 *                pay_attendance_policies · pay_attendance_rules (+ lines) · pay_policy_overtime_rules
 *                pay_sheet_batches · pay_sheets (+ lines): the period sheet whose approved totals feed the payslip
 *   payroll      pay_components · pay_structures (+ the rules Odoo held) · pay_gosi_rates · pay_parameters ·
 *                pay_accounts · pay_profiles · pay_adjustments · pay_runs · pay_payslips (+ lines)
 *   skills       pay_skill_types · pay_skills · pay_skill_levels · pay_employee_skills · pay_resume_types · pay_resume_lines
 *
 * Money is `decimal(14,2)` and computed in whole halalas (App\Payroll\Support\Money); durations are whole minutes.
 * A record that came from Odoo carries `source_system = 'odoo'` + `external_ref` (the Odoo id): while the section
 * follows Odoo it is Odoo's (App\Payroll\Support\Owned). Nothing a person entered is deleted: an attendance record is
 * voided, a leave cancelled, a run reversed — each with who did it and why.
 */
return new class extends Migration
{
    private const TABLES = [
        'pay_resume_lines', 'pay_resume_types', 'pay_employee_skills', 'pay_skill_levels', 'pay_skills', 'pay_skill_types',
        'pay_payslip_lines', 'pay_payslips', 'pay_runs', 'pay_adjustments', 'pay_profiles', 'pay_accounts', 'pay_parameters', 'pay_gosi_rates',
        'pay_structure_rules', 'pay_structures', 'pay_components',
        'pay_sheet_lines', 'pay_sheets', 'pay_sheet_batches', 'pay_policy_overtime_rules', 'pay_attendance_policies', 'pay_attendance_rule_lines', 'pay_attendance_rules',
        'pay_attendance_changes', 'pay_attendance', 'pay_leave_entries', 'pay_leaves', 'pay_leave_types', 'pay_holidays', 'pay_schedule_days', 'pay_schedules',
    ];

    public function up(): void
    {
        $source = function (Blueprint $t, string $table) {
            $t->string('source_system', 20)->nullable();    // odoo | null (made in OPS)
            $t->string('external_ref', 60)->nullable();     // the id in the source system
            $t->unique(['source_system', 'external_ref'], "{$table}_source_uq");
        };
        $stamps = function (Blueprint $t) {
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
        };

        // ───────────── work time ─────────────

        Schema::create('pay_schedules', function (Blueprint $t) use ($source, $stamps) {
            $t->ulid('id')->primary();
            $t->string('name_ar', 190);
            $t->string('name_en', 190);
            $t->string('timezone', 60)->default('Asia/Riyadh');   // the clock the planned hours are told in
            $t->decimal('hours_per_day', 5, 2)->default(8);        // what one day of pay stands for (the hourly rate)
            $t->boolean('is_default')->default(false);
            $t->boolean('active')->default(true);
            $t->text('notes')->nullable();
            $source($t, 'pay_schedules');
            $stamps($t);
        });

        // one stretch of a weekday: work, or a break inside it (lunch). weekday 0 = Monday … 6 = Sunday
        Schema::create('pay_schedule_days', function (Blueprint $t) use ($source) {
            $t->ulid('id')->primary();
            $t->ulid('schedule_id');
            $t->smallInteger('weekday');
            $t->string('kind', 6)->default('work');                // work | break
            $t->string('name', 120)->nullable();
            $t->integer('from_minute');                            // minutes after midnight
            $t->integer('to_minute');
            $t->integer('sequence')->default(0);
            $source($t, 'pay_schedule_days');
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->index(['schedule_id', 'weekday', 'from_minute'], 'pay_schedule_days_day_ix');
        });

        Schema::create('pay_holidays', function (Blueprint $t) use ($source, $stamps) {
            $t->ulid('id')->primary();
            $t->string('name_ar', 190);
            $t->string('name_en', 190);
            $t->date('date_from');
            $t->date('date_to');
            $t->string('scope', 12)->default('company');           // company | department | employee
            $t->json('scope_ids')->nullable();                     // hr_departments.id / hr_employees.id the day is for
            $t->boolean('active')->default(true);
            $t->text('notes')->nullable();
            $source($t, 'pay_holidays');
            $stamps($t);
            $t->index(['date_from', 'date_to'], 'pay_holidays_dates_ix');
        });

        // ───────────── time off ─────────────

        Schema::create('pay_leave_types', function (Blueprint $t) use ($source, $stamps) {
            $t->ulid('id')->primary();
            $t->string('code', 30)->unique();
            $t->string('name_ar', 190);
            $t->string('name_en', 190);
            $t->boolean('paid')->default(true);                    // an unpaid type is deducted from the month's pay
            $t->boolean('requires_allocation')->default(false);    // days must be granted before they are taken
            $t->decimal('yearly_days', 6, 2)->nullable();          // the allowance a yearly grant gives
            $t->string('approval', 8)->default('manager');         // none | manager | hr | both
            $t->boolean('requires_document')->default(false);
            $t->boolean('allow_half_day')->default(true);
            $t->boolean('allow_negative')->default(false);
            $t->string('unit', 5)->default('day');                 // day | hour — how the source system counts it
            $t->smallInteger('color')->default(0);
            $t->integer('sequence')->default(0);
            $t->boolean('active')->default(true);
            $t->text('notes')->nullable();
            $source($t, 'pay_leave_types');
            $stamps($t);
        });

        Schema::create('pay_leaves', function (Blueprint $t) use ($source, $stamps) {
            $t->ulid('id')->primary();
            $t->string('number', 40)->unique();                    // LVE-YYYY-NNNNN (OD-LV-<id> when it came from Odoo)
            $t->ulid('employee_id');
            $t->ulid('leave_type_id');
            $t->ulid('department_id')->nullable();                 // where the person worked when it was asked
            $t->date('date_from');
            $t->date('date_to');
            $t->boolean('half_day')->default(false);
            $t->decimal('days', 6, 2)->default(0);                 // working days of the person's schedule, public holidays out
            $t->smallInteger('year');                              // the allowance year it is taken from
            $t->text('reason')->nullable();
            $t->string('state', 18)->default('draft');             // draft | submitted | manager_approved | approved | refused | cancelled
            $t->dateTime('submitted_at', 3)->nullable();
            $t->string('first_approved_by')->nullable();
            $t->ulid('first_approved_by_id')->nullable();
            $t->dateTime('first_approved_at', 3)->nullable();
            $t->string('decided_by')->nullable();
            $t->ulid('decided_by_id')->nullable();
            $t->dateTime('decided_at', 3)->nullable();
            $t->text('decision_note')->nullable();
            $t->ulid('requested_by_id')->nullable();               // who entered it (the employee, or someone on their behalf)
            $t->string('source_state', 20)->nullable();
            $source($t, 'pay_leaves');
            $stamps($t);
            $t->index(['employee_id', 'date_from'], 'pay_leaves_employee_ix');
            $t->index(['state', 'date_from'], 'pay_leaves_state_ix');
            $t->index(['department_id', 'date_from'], 'pay_leaves_department_ix');
            $t->index(['date_from', 'date_to'], 'pay_leaves_dates_ix');
        });

        // the ledger of leave days: + granted, − taken. A balance is the sum of its entries, nothing else.
        Schema::create('pay_leave_entries', function (Blueprint $t) use ($source) {
            $t->ulid('id')->primary();
            $t->ulid('employee_id');
            $t->ulid('leave_type_id');
            $t->smallInteger('year');
            $t->string('kind', 12);                                // allocation | taken | restored | adjustment
            $t->decimal('days', 7, 2);                             // signed
            $t->ulid('leave_id')->nullable();
            $t->string('reference', 80)->nullable();               // what makes a grant happen once (`yearly:2026`)
            $t->text('note')->nullable();
            $source($t, 'pay_leave_entries');
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->unique(['employee_id', 'leave_type_id', 'reference'], 'pay_leave_entries_once_uq');
            $t->index(['employee_id', 'leave_type_id', 'year'], 'pay_leave_entries_balance_ix');
            $t->index(['leave_id'], 'pay_leave_entries_leave_ix');
        });

        // ───────────── attendance ─────────────

        Schema::create('pay_attendance', function (Blueprint $t) use ($source, $stamps) {
            $t->ulid('id')->primary();
            $t->ulid('employee_id');
            $t->date('work_date');                                 // the day of the check-in on the schedule's clock
            $t->dateTime('check_in', 3);
            $t->dateTime('check_out', 3)->nullable();
            $t->integer('worked_minutes')->default(0);
            $t->string('in_mode', 10)->default('self');            // self | manual | kiosk | import …
            $t->string('out_mode', 10)->nullable();
            $t->string('status', 6)->default('ok');                // ok | void
            $t->boolean('corrected')->default(false);
            $t->dateTime('original_check_in', 3)->nullable();      // what the record said before its first correction
            $t->dateTime('original_check_out', 3)->nullable();
            $t->text('note')->nullable();
            $t->string('voided_by')->nullable();
            $t->dateTime('voided_at', 3)->nullable();
            $t->text('void_reason')->nullable();
            $source($t, 'pay_attendance');
            $stamps($t);
            $t->index(['employee_id', 'work_date'], 'pay_attendance_employee_ix');
            $t->index(['work_date', 'status'], 'pay_attendance_date_ix');
        });

        Schema::create('pay_attendance_changes', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('target_type', 12);                         // attendance | sheet_line
            $t->ulid('target_id');
            $t->ulid('employee_id')->nullable();
            $t->string('field', 30);
            $t->string('old_value', 120)->nullable();
            $t->string('new_value', 120)->nullable();
            $t->text('reason');
            $t->string('changed_by')->nullable();
            $t->ulid('changed_by_id')->nullable();
            $t->dateTime('changed_at', 3)->useCurrent();
            $t->index(['target_type', 'target_id', 'changed_at'], 'pay_attendance_changes_target_ix');
        });

        // a rule of a policy. late / early / absence carry lines; an overtime rule is one line of its own
        Schema::create('pay_attendance_rules', function (Blueprint $t) use ($source, $stamps) {
            $t->ulid('id')->primary();
            $t->string('kind', 10);                                // late | early | absence | overtime
            $t->string('name_ar', 190);
            $t->string('name_en', 190);
            $t->string('day_type', 8)->nullable();                 // overtime: workday | weekend | holiday
            $t->integer('active_after_minutes')->nullable();       // overtime counts once it exceeds this
            $t->decimal('rate', 8, 4)->nullable();                 // overtime: paid hours per hour worked
            $t->boolean('active')->default(true);
            $source($t, 'pay_attendance_rules');
            $stamps($t);
            $t->index(['kind', 'active'], 'pay_attendance_rules_kind_ix');
        });

        Schema::create('pay_attendance_rule_lines', function (Blueprint $t) use ($source) {
            $t->ulid('id')->primary();
            $t->ulid('rule_id');
            $t->integer('sequence')->default(0);
            $t->string('type', 5)->nullable();                     // late / early: rate | fix
            $t->integer('threshold_minutes')->default(0);          // applies from this many minutes
            $t->decimal('rate', 8, 4)->nullable();                 // rate: deducted = rate × minutes · absence: × the day's hours
            $t->integer('amount_minutes')->nullable();             // fix: this much is deducted
            $t->decimal('first', 8, 4)->nullable();                // how the n-th time in a period weighs (1 when empty)
            $t->decimal('second', 8, 4)->nullable();
            $t->decimal('third', 8, 4)->nullable();
            $t->decimal('fourth', 8, 4)->nullable();
            $t->decimal('fifth', 8, 4)->nullable();
            $t->smallInteger('counter')->nullable();               // absence: the n-th absence of the period
            $source($t, 'pay_attendance_rule_lines');
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->index(['rule_id', 'sequence'], 'pay_attendance_rule_lines_rule_ix');
        });

        Schema::create('pay_attendance_policies', function (Blueprint $t) use ($source, $stamps) {
            $t->ulid('id')->primary();
            $t->string('name_ar', 190);
            $t->string('name_en', 190);
            $t->integer('late_grace_minutes')->default(0);         // lateness up to this is not counted
            $t->integer('early_grace_minutes')->default(0);
            $t->ulid('late_rule_id')->nullable();
            $t->ulid('early_rule_id')->nullable();
            $t->ulid('absence_rule_id')->nullable();
            $t->boolean('is_default')->default(false);
            $t->boolean('incomplete')->default(false);             // known by name only: its rules could not be read from the source
            $t->boolean('active')->default(true);
            $t->text('notes')->nullable();
            $source($t, 'pay_attendance_policies');
            $stamps($t);
        });

        Schema::create('pay_policy_overtime_rules', function (Blueprint $t) {
            $t->ulid('policy_id');
            $t->ulid('rule_id');
            $t->primary(['policy_id', 'rule_id']);
        });

        Schema::create('pay_sheet_batches', function (Blueprint $t) use ($source, $stamps) {
            $t->ulid('id')->primary();
            $t->string('number', 40)->unique();                    // ATB-YYYY-NNNNN
            $t->string('name', 190)->nullable();
            $t->ulid('department_id')->nullable();                 // null = the whole company
            $t->date('date_from');
            $t->date('date_to');
            $t->string('state', 10)->default('draft');             // draft | generated | submitted | closed
            $t->json('skipped')->nullable();                       // who got no sheet and why (employee codes, never figures)
            $t->string('source_run_ref', 60)->nullable();          // the payslip batch the source system tied it to
            $t->text('notes')->nullable();
            $source($t, 'pay_sheet_batches');
            $stamps($t);
            $t->index(['state', 'date_from'], 'pay_sheet_batches_state_ix');
        });

        Schema::create('pay_sheets', function (Blueprint $t) use ($source, $stamps) {
            $t->ulid('id')->primary();
            $t->string('number', 40)->unique();                    // ATS-YYYY-NNNNN
            $t->ulid('employee_id');
            $t->ulid('batch_id')->nullable();
            $t->ulid('department_id')->nullable();
            $t->ulid('contract_id')->nullable();
            $t->ulid('policy_id')->nullable();
            $t->ulid('schedule_id')->nullable();
            $t->date('date_from');
            $t->date('date_to');
            $t->string('period', 7);                               // YYYY-MM of date_to: the payslip it feeds
            $t->string('state', 10)->default('draft');             // draft | confirmed | approved
            $t->integer('planned_minutes')->default(0);
            $t->integer('worked_minutes')->default(0);
            $t->integer('late_count')->default(0);
            $t->integer('late_actual_minutes')->default(0);
            $t->integer('late_minutes')->default(0);               // after the policy: what is deducted
            $t->integer('early_count')->default(0);
            $t->integer('early_actual_minutes')->default(0);
            $t->integer('early_minutes')->default(0);
            $t->integer('overtime_count')->default(0);
            $t->integer('overtime_actual_minutes')->default(0);
            $t->integer('overtime_minutes')->default(0);           // after the policy: what is paid
            $t->integer('absence_count')->default(0);
            $t->integer('absence_minutes')->default(0);
            $t->decimal('leave_days', 6, 2)->default(0);
            $t->integer('holiday_days')->default(0);
            $t->integer('weekend_days')->default(0);
            $t->json('extra')->nullable();                         // what the source system holds and OPS has no column for
            $t->ulid('payslip_id')->nullable();                    // the payslip its totals went into
            $t->string('confirmed_by')->nullable();
            $t->dateTime('confirmed_at', 3)->nullable();
            $t->string('approved_by')->nullable();
            $t->dateTime('approved_at', 3)->nullable();
            $t->text('notes')->nullable();
            $t->string('source_state', 20)->nullable();
            $source($t, 'pay_sheets');
            $stamps($t);
            $t->index(['employee_id', 'date_from'], 'pay_sheets_employee_ix');
            $t->index(['employee_id', 'period', 'state'], 'pay_sheets_period_ix');
            $t->index(['batch_id'], 'pay_sheets_batch_ix');
            $t->index(['state', 'date_from'], 'pay_sheets_state_ix');
        });

        Schema::create('pay_sheet_lines', function (Blueprint $t) use ($source) {
            $t->ulid('id')->primary();
            $t->ulid('sheet_id');
            $t->date('work_date');
            $t->smallInteger('weekday');
            $t->integer('planned_in')->nullable();                 // minutes after midnight, on the schedule's clock
            $t->integer('planned_out')->nullable();
            $t->integer('actual_in')->nullable();
            $t->integer('actual_out')->nullable();
            $t->integer('planned_minutes')->default(0);
            $t->integer('worked_minutes')->default(0);
            $t->integer('late_actual')->default(0);
            $t->integer('late')->default(0);
            $t->integer('early_actual')->default(0);
            $t->integer('early')->default(0);
            $t->integer('overtime_actual')->default(0);
            $t->integer('overtime')->default(0);
            $t->integer('absence_minutes')->default(0);
            $t->string('status', 8)->nullable();                   // null = a worked day | absence | weekend | holiday | leave
            $t->boolean('corrected')->default(false);
            $t->text('note')->nullable();
            $source($t, 'pay_sheet_lines');
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['sheet_id', 'work_date'], 'pay_sheet_lines_day_uq');
        });

        // ───────────── payroll ─────────────

        Schema::create('pay_components', function (Blueprint $t) use ($source, $stamps) {
            $t->ulid('id')->primary();
            $t->string('code', 40)->unique();
            $t->string('name_ar', 190);
            $t->string('name_en', 190);
            $t->string('category', 14);                            // basic | allowance | deduction | company | reimbursement
            $t->string('calc', 16);                                // contract | overtime | late | early | absence | unpaid_leave | gosi_employee | gosi_employer | claims | input
            $t->string('contract_field', 12)->nullable();          // wage | housing | transport | other
            $t->boolean('gosi_base')->default(false);              // counts in the social-insurance base
            $t->integer('sequence')->default(100);
            $t->string('account_key', 30)->nullable();             // pay_accounts.key it is posted to
            $t->string('account_code', 20)->nullable();            // … or an account of its own
            $t->boolean('system')->default(false);                 // the engine computes it: it cannot be removed
            $t->boolean('active')->default(true);
            $t->text('notes')->nullable();
            $source($t, 'pay_components');
            $stamps($t);
        });

        Schema::create('pay_structures', function (Blueprint $t) use ($source, $stamps) {
            $t->ulid('id')->primary();
            $t->string('code', 40)->nullable()->unique();
            $t->string('name_ar', 190);
            $t->string('name_en', 190);
            $t->string('gosi_scheme', 20)->nullable();             // pay_gosi_rates.scheme the people on it contribute under (null = none)
            $t->string('type_label', 190)->nullable();             // the structure type as the source system words it
            $t->string('country', 120)->nullable();
            $t->boolean('is_default')->default(false);
            $t->boolean('active')->default(true);
            $t->text('notes')->nullable();
            $source($t, 'pay_structures');
            $stamps($t);
        });

        // the rules of a structure as the source system holds them: kept to be read, not run — OPS computes with its components
        Schema::create('pay_structure_rules', function (Blueprint $t) use ($source) {
            $t->ulid('id')->primary();
            $t->ulid('structure_id');
            $t->integer('sequence')->default(0);
            $t->string('code', 60);
            $t->string('name', 250);
            $t->string('category_code', 20)->nullable();
            $t->string('condition', 10)->nullable();               // none | python | range
            $t->text('condition_text')->nullable();
            $t->text('formula')->nullable();
            $t->string('quantity', 120)->nullable();
            $t->boolean('appears_on_payslip')->default(true);
            $t->ulid('component_id')->nullable();                  // the OPS component that does the same work, when there is one
            $t->boolean('active')->default(true);
            $source($t, 'pay_structure_rules');
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->index(['structure_id', 'sequence'], 'pay_structure_rules_structure_ix');
        });

        // social insurance: what each side pays, from a date on. A new rate is a new row — history is never rewritten.
        Schema::create('pay_gosi_rates', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('scheme', 20);
            $t->date('effective_from');
            $t->decimal('employee_pct', 7, 4)->default(0);
            $t->decimal('employer_pct', 7, 4)->default(0);
            $t->decimal('base_min', 14, 2)->default(0);
            $t->decimal('base_max', 14, 2)->nullable();
            $t->text('note')->nullable();
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['scheme', 'effective_from'], 'pay_gosi_rates_scheme_uq');
        });

        // every other figure the engine uses, from a date on (`month_days`, `unpaid_base`, `attendance_base` …)
        Schema::create('pay_parameters', function (Blueprint $t) use ($source, $stamps) {
            $t->ulid('id')->primary();
            $t->string('code', 60);
            $t->string('name', 190)->nullable();
            $t->string('value', 190);
            $t->date('effective_from');
            $t->text('note')->nullable();
            $source($t, 'pay_parameters');
            $stamps($t);
            $t->unique(['code', 'effective_from'], 'pay_parameters_code_uq');
        });

        // which account of the chart each kind of payroll amount is posted to
        Schema::create('pay_accounts', function (Blueprint $t) {
            $t->string('key', 30)->primary();
            $t->string('account_code', 20)->nullable();
            $t->string('updated_by')->nullable();
            $t->dateTime('updated_at', 3)->nullable();
        });

        // how one employee is paid and timed
        Schema::create('pay_profiles', function (Blueprint $t) use ($source, $stamps) {
            $t->ulid('id')->primary();
            $t->ulid('employee_id')->unique();
            $t->ulid('structure_id')->nullable();
            $t->ulid('schedule_id')->nullable();
            $t->ulid('policy_id')->nullable();
            $t->string('payment_method', 6)->default('bank');      // bank | cash
            $t->text('notes')->nullable();
            $source($t, 'pay_profiles');
            $stamps($t);
        });

        // a one-off addition or deduction of a month (a bonus, a penalty, a loan instalment, a correction of an earlier month)
        Schema::create('pay_adjustments', function (Blueprint $t) use ($source, $stamps) {
            $t->ulid('id')->primary();
            $t->ulid('employee_id');
            $t->string('period', 7);                               // YYYY-MM it is to be paid / deducted in
            $t->ulid('component_id');
            $t->decimal('amount', 14, 2);
            $t->text('reason');
            $t->string('state', 10)->default('pending');           // pending | applied | cancelled
            $t->ulid('payslip_id')->nullable();
            $t->string('cancelled_by')->nullable();
            $t->dateTime('cancelled_at', 3)->nullable();
            $t->text('cancel_reason')->nullable();
            $source($t, 'pay_adjustments');
            $stamps($t);
            $t->index(['employee_id', 'period', 'state'], 'pay_adjustments_employee_ix');
            $t->index(['period', 'state'], 'pay_adjustments_period_ix');
        });

        Schema::create('pay_runs', function (Blueprint $t) use ($source, $stamps) {
            $t->ulid('id')->primary();
            $t->string('number', 40)->unique();                    // PYR-YYYY-NNNNN (OD-PR-<id> when it came from Odoo)
            $t->string('name', 190)->nullable();
            $t->string('period', 7);                               // YYYY-MM
            $t->date('date_from');
            $t->date('date_to');
            $t->ulid('department_id')->nullable();                 // null = the whole company
            $t->string('state', 10)->default('draft');             // draft | review | approved | posted | paid | reversed | cancelled
            $t->string('currency', 3)->default('SAR');
            $t->integer('slip_count')->default(0);
            $t->decimal('gross', 16, 2)->default(0);
            $t->decimal('deductions', 16, 2)->default(0);
            $t->decimal('reimbursements', 16, 2)->default(0);
            $t->decimal('net', 16, 2)->default(0);
            $t->decimal('employer_cost', 16, 2)->default(0);       // what the company pays on top (its social-insurance share)
            $t->json('skipped')->nullable();                       // contracts left out and why (references only)
            $t->json('warnings')->nullable();
            $t->dateTime('computed_at', 3)->nullable();
            $t->string('submitted_by')->nullable();
            $t->ulid('submitted_by_id')->nullable();
            $t->dateTime('submitted_at', 3)->nullable();
            $t->string('approved_by')->nullable();
            $t->ulid('approved_by_id')->nullable();
            $t->dateTime('approved_at', 3)->nullable();
            $t->text('approval_note')->nullable();
            $t->string('books', 6)->nullable();                    // ops | odoo — whose books carry it
            $t->string('posted_by')->nullable();
            $t->dateTime('posted_at', 3)->nullable();
            $t->date('accounting_date')->nullable();
            $t->ulid('journal_id')->nullable();
            $t->string('journal_number', 40)->nullable();
            $t->string('paid_by')->nullable();
            $t->dateTime('paid_at', 3)->nullable();
            $t->date('payment_date')->nullable();
            $t->string('payment_ref', 80)->nullable();
            $t->string('payment_account', 20)->nullable();         // fin_bank_accounts.code the money left from
            $t->ulid('payment_journal_id')->nullable();
            $t->string('payment_journal_number', 40)->nullable();
            $t->string('closed_by')->nullable();                   // who reversed or cancelled it
            $t->dateTime('closed_at', 3)->nullable();
            $t->text('close_reason')->nullable();
            $t->string('reversal_journal_number', 40)->nullable();
            $t->string('source_state', 20)->nullable();
            $source($t, 'pay_runs');
            $stamps($t);
            $t->index(['period', 'state'], 'pay_runs_period_ix');
            $t->index(['state', 'created_at'], 'pay_runs_state_ix');
        });

        Schema::create('pay_payslips', function (Blueprint $t) use ($source, $stamps) {
            $t->ulid('id')->primary();
            $t->string('number', 40)->unique();                    // PSL-YYYY-NNNNN (OD-PS-<id> when it came from Odoo)
            $t->ulid('run_id')->nullable();
            $t->ulid('employee_id');
            $t->ulid('contract_id')->nullable();
            $t->ulid('department_id')->nullable();
            $t->ulid('structure_id')->nullable();
            $t->string('period', 7);
            $t->date('date_from');
            $t->date('date_to');
            $t->string('state', 10)->default('draft');             // follows its run
            $t->string('currency', 3)->default('SAR');
            $t->smallInteger('days_covered')->default(0);          // days of the month the contract was in force
            $t->smallInteger('days_in_month')->default(0);
            $t->decimal('basic', 14, 2)->default(0);
            $t->decimal('gross', 14, 2)->default(0);
            $t->decimal('deductions', 14, 2)->default(0);
            $t->decimal('reimbursements', 14, 2)->default(0);
            $t->decimal('net', 14, 2)->default(0);
            $t->decimal('employer_cost', 14, 2)->default(0);
            $t->json('worked')->nullable();                        // days and hours behind the figures
            $t->json('explain')->nullable();                       // which contract, which days, which rule and rate
            $t->json('warnings')->nullable();
            // "<contract>:<period>" while the payslip stands, null once it is reversed or cancelled: the unique index is
            // what makes paying one contract twice for one month impossible, whatever the code does
            $t->string('live_key', 80)->nullable()->unique();
            $t->string('source_state', 20)->nullable();
            $source($t, 'pay_payslips');
            $stamps($t);
            $t->index(['run_id', 'employee_id'], 'pay_payslips_run_ix');
            $t->index(['employee_id', 'period'], 'pay_payslips_employee_ix');
            $t->index(['period', 'state'], 'pay_payslips_period_ix');
        });

        Schema::create('pay_payslip_lines', function (Blueprint $t) use ($source) {
            $t->ulid('id')->primary();
            $t->ulid('payslip_id');
            $t->integer('sequence')->default(0);
            $t->ulid('component_id')->nullable();
            $t->string('code', 60);
            $t->string('name_ar', 250);
            $t->string('name_en', 250);
            $t->string('category', 14);                            // basic | allowance | gross | deduction | reimbursement | net | company
            $t->decimal('quantity', 12, 4)->default(1);
            $t->decimal('rate', 12, 4)->default(100);
            $t->decimal('amount', 14, 2)->default(0);
            $t->decimal('total', 14, 2)->default(0);               // signed: a deduction is negative
            $t->json('explain')->nullable();
            $t->ulid('claim_id')->nullable();                      // the expense claim this line reimburses
            $t->ulid('adjustment_id')->nullable();
            $source($t, 'pay_payslip_lines');
            $t->dateTime('created_at', 3)->useCurrent();
            $t->index(['payslip_id', 'sequence'], 'pay_payslip_lines_payslip_ix');
            $t->index(['claim_id'], 'pay_payslip_lines_claim_ix');
        });

        // ───────────── skills and résumé ─────────────

        Schema::create('pay_skill_types', function (Blueprint $t) use ($source, $stamps) {
            $t->ulid('id')->primary();
            $t->string('name_ar', 190);
            $t->string('name_en', 190);
            $t->integer('sequence')->default(0);
            $t->boolean('active')->default(true);
            $source($t, 'pay_skill_types');
            $stamps($t);
        });

        Schema::create('pay_skills', function (Blueprint $t) use ($source, $stamps) {
            $t->ulid('id')->primary();
            $t->ulid('type_id');
            $t->string('name_ar', 190);
            $t->string('name_en', 190);
            $t->integer('sequence')->default(0);
            $t->boolean('active')->default(true);
            $source($t, 'pay_skills');
            $stamps($t);
            $t->index(['type_id', 'sequence'], 'pay_skills_type_ix');
        });

        Schema::create('pay_skill_levels', function (Blueprint $t) use ($source, $stamps) {
            $t->ulid('id')->primary();
            $t->ulid('type_id');
            $t->string('name_ar', 190);
            $t->string('name_en', 190);
            $t->smallInteger('progress')->default(0);              // 0..100
            $t->boolean('is_default')->default(false);
            $t->boolean('active')->default(true);
            $source($t, 'pay_skill_levels');
            $stamps($t);
            $t->index(['type_id', 'progress'], 'pay_skill_levels_type_ix');
        });

        Schema::create('pay_employee_skills', function (Blueprint $t) use ($source, $stamps) {
            $t->ulid('id')->primary();
            $t->ulid('employee_id');
            $t->ulid('type_id');
            $t->ulid('skill_id');
            $t->ulid('level_id')->nullable();
            $source($t, 'pay_employee_skills');
            $stamps($t);
            $t->unique(['employee_id', 'skill_id'], 'pay_employee_skills_once_uq');
            $t->index(['skill_id', 'level_id'], 'pay_employee_skills_skill_ix');
        });

        Schema::create('pay_resume_types', function (Blueprint $t) use ($source, $stamps) {
            $t->ulid('id')->primary();
            $t->string('name_ar', 190);
            $t->string('name_en', 190);
            $t->integer('sequence')->default(0);
            $t->boolean('active')->default(true);
            $source($t, 'pay_resume_types');
            $stamps($t);
        });

        Schema::create('pay_resume_lines', function (Blueprint $t) use ($source, $stamps) {
            $t->ulid('id')->primary();
            $t->ulid('employee_id');
            $t->ulid('type_id')->nullable();
            $t->string('title', 250);
            $t->date('date_start')->nullable();
            $t->date('date_end')->nullable();
            $t->text('description')->nullable();
            $source($t, 'pay_resume_lines');
            $stamps($t);
            $t->index(['employee_id', 'date_start'], 'pay_resume_lines_employee_ix');
        });

        $this->caseInsensitiveText();
        PermissionSync::ensure();        // page.payroll · payroll.manage · payroll.approve · leave.request · leave.approve · attendance.manage
        PayrollBootstrap::ensure();      // a schedule, leave types, pay components, structures, rates and the posting map to start from
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
