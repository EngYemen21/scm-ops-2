<?php

namespace App\Payroll;

use App\Payroll\Services\RunPosting;
use App\Payroll\Support\Owned;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * What the section needs before its first use — written only where nothing is there yet, so it never adds to what
 * people (or Odoo) already put in. Idempotent; runs from the migration, and `defaults()` is what a test calls.
 *
 * TWO KINDS OF THINGS:
 *   the engine's own configuration — pay components, the attendance policy, social-insurance rates, the engine's
 *     parameters, the posting map. Odoo has no counterpart OPS could run (its rules are Python formulas, its policies
 *     are not readable), so these are written whatever the section follows;
 *   what Odoo also holds — the work schedule, the kinds of time off, pay structures, résumé line types. Where the
 *     section follows Odoo they come from the copy (`php artisan scm:odoo-project payroll --apply`) and a second set
 *     beside them would only be noise: they are written only where the section does not follow Odoo.
 *
 * The rates below are STARTING VALUES in the database, each with the date it applies from — never constants of the
 * engine. Finance confirms them and adds a new dated row whenever the rules change.
 */
final class PayrollBootstrap
{
    /** code, Arabic, English, category, calc, contract field, in the social-insurance base, sequence, posting key, computed by the engine */
    public const COMPONENTS = [
        ['BASIC', 'الراتب الأساسي', 'Basic salary', 'basic', 'contract', 'wage', true, 10, 'salaries', true],
        ['HOUSING', 'بدل السكن', 'Housing allowance', 'allowance', 'contract', 'housing', true, 20, 'allowances', true],
        ['TRANSPORT', 'بدل النقل', 'Transport allowance', 'allowance', 'contract', 'transport', false, 30, 'allowances', true],
        ['OTHER', 'بدلات أخرى', 'Other allowances', 'allowance', 'contract', 'other', false, 40, 'allowances', true],
        ['OVERTIME', 'العمل الإضافي', 'Overtime', 'allowance', 'overtime', null, false, 50, 'overtime', true],
        ['BONUS', 'مكافأة', 'Bonus', 'allowance', 'input', null, false, 60, 'allowances', false],
        ['UNPAID', 'إجازة غير مدفوعة', 'Unpaid leave', 'deduction', 'unpaid_leave', null, false, 110, 'salaries', true],
        ['ABSENCE', 'غياب', 'Absence', 'deduction', 'absence', null, false, 120, 'salaries', true],
        ['LATE', 'تأخير', 'Lateness', 'deduction', 'late', null, false, 130, 'salaries', true],
        ['EARLY', 'خروج مبكر', 'Early leave', 'deduction', 'early', null, false, 140, 'salaries', true],
        ['GOSI_EE', 'التأمينات الاجتماعية — حصة الموظف', 'Social insurance — employee share', 'deduction', 'gosi_employee', null, false, 150, 'gosi_payable', true],
        ['PENALTY', 'جزاء', 'Penalty', 'deduction', 'input', null, false, 160, 'deductions', false],
        ['LOAN', 'قسط سلفة', 'Loan instalment', 'deduction', 'input', null, false, 170, 'deductions', false],
        ['DEDUCTION', 'خصم آخر', 'Other deduction', 'deduction', 'input', null, false, 180, 'deductions', false],
        ['REIMB', 'تعويض مصروفات', 'Expense reimbursement', 'reimbursement', 'claims', null, false, 190, 'reimbursements', true],
        ['GOSI_ER', 'التأمينات الاجتماعية — حصة المنشأة', 'Social insurance — employer share', 'company', 'gosi_employer', null, false, 200, 'gosi_expense', true],
    ];

    /** scheme, employee %, employer %, floor of the base, ceiling of the base */
    private const GOSI = [['saudi', 9.75, 11.75, 1500, 45000], ['expat', 0, 2, 1500, 45000]];

    private const GOSI_FROM = '2020-01-01';

    private const GOSI_NOTE = 'قيم ابتدائية — تُراجَع مع أنظمة التأمينات الاجتماعية وتُضاف نسبة جديدة بتاريخ سريانها عند كل تغيير · Starting values — confirm against GOSI\'s rules and add a dated row at every change';

    /** code → first value */
    private const PARAMETERS = ['month_days' => '30', 'unpaid_base' => 'gross', 'attendance_base' => 'basic'];

    /** code, Arabic, English, paid, needs days granted, days a year, approval, needs a document */
    private const LEAVE_TYPES = [
        ['ANNUAL', 'إجازة سنوية', 'Annual leave', true, true, 21, 'both', false],
        ['SICK', 'إجازة مرضية', 'Sick leave', true, false, null, 'hr', true],
        ['UNPAID', 'إجازة بدون راتب', 'Unpaid leave', false, false, null, 'both', false],
        ['COMP', 'إجازة تعويضية', 'Compensatory leave', true, true, null, 'manager', false],
    ];

    /** code, Arabic, English, social-insurance scheme, the default */
    private const STRUCTURES = [
        ['STD', 'موظف بلا تأمينات', 'Employee without social insurance', null, true],
        ['STD-SA', 'موظف سعودي — تأمينات اجتماعية', 'Saudi employee — social insurance', 'saudi', false],
        ['STD-EX', 'موظف غير سعودي — أخطار مهنية', 'Non-Saudi employee — occupational hazards', 'expat', false],
    ];

    private const RESUME_TYPES = [['خبرة عملية', 'Experience'], ['تعليم', 'Education'], ['دورات وشهادات', 'Courses and certificates']];

    /** @return array<string, int> what was added */
    public static function ensure(): array
    {
        if (! Schema::hasTable('pay_components')) {
            return [];
        }

        return self::engine() + (Owned::following() ? [] : self::defaults());
    }

    /** The engine's own configuration, whatever the section follows. @return array<string, int> */
    public static function engine(): array
    {
        $out = ['components' => 0, 'gosiRates' => 0, 'parameters' => 0, 'accounts' => 0, 'policies' => 0];
        $now = now();
        $have = DB::table('pay_components')->pluck('code')->map(fn ($c) => mb_strtoupper((string) $c))->all();
        foreach (self::COMPONENTS as [$code, $ar, $en, $category, $calc, $field, $gosi, $sequence, $key, $system]) {
            if (! in_array($code, $have, true)) {
                DB::table('pay_components')->insert(['id' => (string) Str::ulid(), 'code' => $code, 'name_ar' => $ar, 'name_en' => $en, 'category' => $category, 'calc' => $calc, 'contract_field' => $field,
                    'gosi_base' => $gosi, 'sequence' => $sequence, 'account_key' => $key, 'system' => $system, 'active' => true, 'created_by' => 'system', 'created_at' => $now, 'updated_at' => $now]);
                $out['components']++;
            }
        }
        if (DB::table('pay_gosi_rates')->count() === 0) {
            foreach (self::GOSI as [$scheme, $employee, $employer, $min, $max]) {
                DB::table('pay_gosi_rates')->insert(['id' => (string) Str::ulid(), 'scheme' => $scheme, 'effective_from' => self::GOSI_FROM, 'employee_pct' => $employee, 'employer_pct' => $employer,
                    'base_min' => $min, 'base_max' => $max, 'note' => self::GOSI_NOTE, 'created_by' => 'system', 'created_at' => $now, 'updated_at' => $now]);
                $out['gosiRates']++;
            }
        }
        foreach (self::PARAMETERS as $code => $value) {
            if (! DB::table('pay_parameters')->where('code', $code)->exists()) {
                DB::table('pay_parameters')->insert(['id' => (string) Str::ulid(), 'code' => $code, 'name' => Services\Rates::KNOWN[$code][2], 'value' => $value, 'effective_from' => '2000-01-01',
                    'created_by' => 'system', 'created_at' => $now, 'updated_at' => $now]);
                $out['parameters']++;
            }
        }
        $out['accounts'] = self::accounts();
        if (! DB::table('pay_attendance_policies')->whereNull('source_system')->exists()) {
            self::policy();
            $out['policies']++;
        }

        return $out;
    }

    /** A schedule, kinds of time off, pay structures and résumé types to start from — each only into an empty table. @return array<string, int> */
    public static function defaults(): array
    {
        $out = ['schedules' => 0, 'leaveTypes' => 0, 'structures' => 0, 'resumeTypes' => 0];
        $now = now();
        $stamp = ['created_by' => 'system', 'created_at' => $now, 'updated_at' => $now];
        if (DB::table('pay_schedules')->count() === 0) {
            $id = (string) Str::ulid();
            DB::table('pay_schedules')->insert(['id' => $id, 'name_ar' => 'دوام الشركة — الأحد إلى الخميس', 'name_en' => 'Company hours — Sunday to Thursday', 'timezone' => (string) config('app.business_timezone', 'Asia/Riyadh'),
                'hours_per_day' => 8, 'is_default' => true, 'active' => true] + $stamp);
            foreach ([6, 0, 1, 2, 3] as $weekday) { // Sunday, Monday … Thursday
                foreach ([['work', 'الفترة الصباحية', 480, 720, 10], ['break', 'استراحة', 720, 780, 20], ['work', 'فترة بعد الظهر', 780, 1020, 30]] as [$kind, $name, $from, $to, $sequence]) {
                    DB::table('pay_schedule_days')->insert(['id' => (string) Str::ulid(), 'schedule_id' => $id, 'weekday' => $weekday, 'kind' => $kind, 'name' => $name, 'from_minute' => $from, 'to_minute' => $to,
                        'sequence' => $sequence, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
            $out['schedules']++;
        }
        if (DB::table('pay_leave_types')->count() === 0) {
            foreach (self::LEAVE_TYPES as $i => [$code, $ar, $en, $paid, $allocated, $yearly, $approval, $document]) {
                DB::table('pay_leave_types')->insert(['id' => (string) Str::ulid(), 'code' => $code, 'name_ar' => $ar, 'name_en' => $en, 'paid' => $paid, 'requires_allocation' => $allocated, 'yearly_days' => $yearly,
                    'approval' => $approval, 'requires_document' => $document, 'allow_half_day' => true, 'allow_negative' => false, 'unit' => 'day', 'color' => $i, 'sequence' => ($i + 1) * 10, 'active' => true] + $stamp);
                $out['leaveTypes']++;
            }
        }
        if (DB::table('pay_structures')->count() === 0) {
            foreach (self::STRUCTURES as [$code, $ar, $en, $scheme, $default]) {
                DB::table('pay_structures')->insert(['id' => (string) Str::ulid(), 'code' => $code, 'name_ar' => $ar, 'name_en' => $en, 'gosi_scheme' => $scheme, 'is_default' => $default, 'active' => true] + $stamp);
                $out['structures']++;
            }
        }
        if (DB::table('pay_resume_types')->count() === 0) {
            foreach (self::RESUME_TYPES as $i => [$ar, $en]) {
                DB::table('pay_resume_types')->insert(['id' => (string) Str::ulid(), 'name_ar' => $ar, 'name_en' => $en, 'sequence' => ($i + 1) * 10, 'active' => true] + $stamp);
                $out['resumeTypes']++;
            }
        }

        return $out;
    }

    /**
     * The posting map: one row per kind of payroll amount. A key that has no account yet gets the obvious one of the
     * chart when the chart has it — the expense account «رواتب وأجور» for what is earned, the accrued-expenses account
     * (posting role `accrued_expenses`) for what is owed — and stays empty otherwise: posting a run then names the key.
     */
    public static function accounts(): int
    {
        $added = 0;
        $chart = Schema::hasTable('fin_accounts');
        $wages = $chart ? DB::table('fin_accounts')->where('code', '6230')->where('type', 'expense')->where('is_postable', true)->where('active', true)->value('code') : null;
        $accrued = $chart ? DB::table('fin_accounts')->where('role', 'accrued_expenses')->where('is_postable', true)->where('active', true)->value('code') : null;
        foreach (RunPosting::KEYS as $key => [$side]) {
            if (! DB::table('pay_accounts')->where('key', $key)->exists()) {
                DB::table('pay_accounts')->insert(['key' => $key, 'account_code' => $side === 'expense' ? $wages : $accrued, 'updated_by' => 'system', 'updated_at' => now()]);
                $added++;
            }
        }

        return $added;
    }

    /**
     * The default attendance policy: fifteen minutes of grace, lateness and early leave deducted minute for minute,
     * an absent day deducted as the day, overtime paid one and a half times on any kind of day.
     */
    private static function policy(): void
    {
        $now = now();
        $stamp = ['active' => true, 'created_by' => 'system', 'created_at' => $now, 'updated_at' => $now];
        $rule = function (string $kind, string $ar, string $en, array $more = []) use ($stamp): string {
            $id = (string) Str::ulid();
            DB::table('pay_attendance_rules')->insert(['id' => $id, 'kind' => $kind, 'name_ar' => $ar, 'name_en' => $en] + $more + $stamp);

            return $id;
        };
        $line = fn (string $ruleId, array $values) => DB::table('pay_attendance_rule_lines')->insert(['id' => (string) Str::ulid(), 'rule_id' => $ruleId, 'sequence' => 10, 'created_at' => $now, 'updated_at' => $now] + $values);
        $late = $rule('late', 'خصم التأخير بالدقيقة', 'Lateness, minute for minute');
        $line($late, ['type' => 'rate', 'threshold_minutes' => 0, 'rate' => 1]);
        $early = $rule('early', 'خصم الخروج المبكر بالدقيقة', 'Early leave, minute for minute');
        $line($early, ['type' => 'rate', 'threshold_minutes' => 0, 'rate' => 1]);
        $absence = $rule('absence', 'خصم يوم عن كل يوم غياب', 'One day for every day of absence');
        $line($absence, ['counter' => 1, 'rate' => 1]);
        $overtime = [];
        foreach ([['workday', 'إضافي أيام العمل', 'Overtime on working days'], ['weekend', 'إضافي العطلة الأسبوعية', 'Overtime on the weekend'], ['holiday', 'إضافي العطل الرسمية', 'Overtime on public holidays']] as [$type, $ar, $en]) {
            $overtime[] = $rule('overtime', $ar, $en, ['day_type' => $type, 'active_after_minutes' => 0, 'rate' => 1.5]);
        }
        $policy = (string) Str::ulid();
        DB::table('pay_attendance_policies')->insert(['id' => $policy, 'name_ar' => 'سياسة الحضور الأساسية', 'name_en' => 'Standard attendance policy', 'late_grace_minutes' => 15, 'early_grace_minutes' => 15,
            'late_rule_id' => $late, 'early_rule_id' => $early, 'absence_rule_id' => $absence, 'is_default' => true, 'incomplete' => false] + $stamp);
        foreach ($overtime as $ruleId) {
            DB::table('pay_policy_overtime_rules')->insert(['policy_id' => $policy, 'rule_id' => $ruleId]);
        }
    }
}
