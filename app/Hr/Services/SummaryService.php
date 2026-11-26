<?php

namespace App\Hr\Services;

use App\Finance\Models\Account;
use App\Finance\Models\BankAccount;
use App\Finance\Models\CostCenter;
use App\Hr\Models\Contract;
use App\Hr\Models\Department;
use App\Hr\Models\Document;
use App\Hr\Models\Employee;
use App\Hr\Models\ExpenseCategory;
use App\Hr\Models\Job;
use App\Hr\Support\Hr;
use App\Hr\Support\Source;
use App\Models\Driver;
use App\Models\User;
use App\Support\AuthUser;

/**
 * The figures at the top of the section and what its forms pick from — each part only for who may see it: the
 * directory for `page.hr`, what is due for HR, contracts for `hr.private`, claims for who files or reviews them.
 */
class SummaryService
{
    public function __construct(private readonly AlertsService $alerts, private readonly ClaimsService $claims) {}

    public function summary(AuthUser $user): array
    {
        $out = [
            // while true, what came from Odoo is Odoo's: shown here, changed there
            'odoo' => ['follows' => Source::follows()],
            // said in the section instead of built: payroll is not part of it (pay, time off and attendance are a section of their own)
            'payroll' => ['inThisSection' => false,
                'ar' => 'الرواتب ليست جزءًا من هذا القسم: هنا دليل الموظفين، الملف الشخصي والعقود (الأجر والبدلات كما في العقد)، الوثائق وتنبيهاتها، ومطالبات المصروفات. احتساب الرواتب والإجازات والحضور قسم مستقل، وقسائم الرواتب القليلة التي أُصدرت في أودو محفوظة في أرشيف أودو لمن يملك صلاحية ملفات الأشخاص.',
                'en' => 'Payroll is not part of this section: here are the staff directory, the personal file and contracts (wage and allowances as the contract states them), documents with their alerts, and expense claims. Calculating pay, time off and attendance is a section of its own, and the few payslips issued in Odoo are kept in the Odoo archive for holders of the people\'s-files permission.'],
        ];
        if ($user->can('page.hr')) {
            $by = Employee::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
            $out['directory'] = ['employees' => (int) ($by['active'] ?? 0), 'left' => (int) ($by['left'] ?? 0) + (int) ($by['inactive'] ?? 0),
                'departments' => Department::where('active', true)->count(), 'jobs' => Job::where('active', true)->count(),
                'withLogin' => Employee::where('status', 'active')->whereNotNull('user_id')->count(), 'fromOdoo' => Employee::where('source_system', Source::ODOO)->count()];
        }
        if (Hr::seesDocuments($user)) {
            $out['due'] = $this->alerts->counts($user);
        }
        if (Hr::seesPrivate($user)) {
            $by = Contract::selectRaw('state, count(*) as n')->groupBy('state')->pluck('n', 'state');
            $out['contracts'] = ['running' => (int) ($by['running'] ?? 0), 'draft' => (int) ($by['draft'] ?? 0),
                'withoutContract' => Employee::where('status', 'active')->whereNotIn('id', fn ($q) => $q->select('employee_id')->from('hr_contracts')->where('state', 'running'))->count()];
        }
        if ($user->can('expense.claim') || $user->can('expense.review')) {
            $out['claims'] = $this->claims->counts($user);
        }

        return $out;
    }

    /** Everything the forms of the section pick from, in ONE request — each list only for who may use it. */
    public function lookups(AuthUser $user): array
    {
        $named = fn ($x) => ['id' => $x->id, 'nameAr' => $x->name_ar, 'nameEn' => $x->name_en];
        $reviewer = $user->can('expense.review');
        $out = [
            'categories' => ExpenseCategory::where('active', true)->orderBy('name_ar')->get()->map(fn (ExpenseCategory $c) => ['id' => $c->id, 'code' => $c->code, 'nameAr' => $c->name_ar, 'nameEn' => $c->name_en,
                'vatPct' => (float) $c->vat_pct] + ($reviewer ? ['accountCode' => $c->account_code] : []))->all(),
        ];
        if ($user->can('page.hr') || $reviewer) {
            $out['departments'] = Department::where('active', true)->orderBy('name_ar')->get(['id', 'name_ar', 'name_en', 'parent_id'])->map(fn (Department $d) => $named($d) + ['parentId' => $d->parent_id])->all();
            $out['jobs'] = Job::where('active', true)->orderBy('name_ar')->get(['id', 'name_ar', 'name_en', 'department_id'])->map(fn (Job $j) => $named($j) + ['departmentId' => $j->department_id])->all();
            $out['employees'] = Employee::whereIn('status', ['active', 'left'])->orderBy('name_ar')->limit(3000)->get(['id', 'code', 'name_ar', 'name_en', 'status', 'department_id'])
                ->map(fn (Employee $e) => $named($e) + ['code' => $e->code, 'status' => $e->status, 'departmentId' => $e->department_id])->all();
        }
        if (Hr::seesDocuments($user)) {
            $private = Hr::seesPrivate($user);
            $out['documentKinds'] = collect(Document::KINDS)->filter(fn ($k) => $private || ! $k['private'])->map(fn ($k, $key) => ['key' => $key, 'ar' => $k['ar'], 'en' => $k['en'], 'private' => $k['private']])->values()->all();
        }
        if (Hr::seesPrivate($user)) {
            $out['contractTypes'] = Contract::TYPES;
        }
        if ($user->can('hr.manage')) {
            // logins and driver records a person can be linked to; `employeeId` says who holds it now
            $userOf = Employee::whereNotNull('user_id')->pluck('id', 'user_id');
            $driverOf = Employee::whereNotNull('driver_id')->pluck('id', 'driver_id');
            $out['users'] = User::where('active', true)->orderBy('username')->limit(2000)->get(['id', 'username', 'name_ar', 'name_en'])
                ->map(fn (User $u) => ['id' => $u->id, 'username' => $u->username, 'nameAr' => $u->name_ar, 'nameEn' => $u->name_en, 'employeeId' => $userOf[$u->id] ?? null])->all();
            $out['drivers'] = Driver::where('active', true)->orderBy('code')->limit(2000)->get(['id', 'code', 'name_ar', 'name_en'])
                ->map(fn (Driver $d) => ['id' => $d->id, 'code' => $d->code, 'nameAr' => $d->name_ar, 'nameEn' => $d->name_en, 'employeeId' => $driverOf[$d->id] ?? null])->all();
        }
        if ($reviewer) {
            // what the posting form picks from: the accounts a voucher accepts, where money leaves from, cost centers
            $out['accounts'] = Account::where('is_postable', true)->where('active', true)->whereIn('type', ['expense', 'asset'])->orderBy('code')->get(['code', 'name_ar', 'name_en', 'type'])
                ->map(fn (Account $a) => ['code' => $a->code, 'nameAr' => $a->name_ar, 'nameEn' => $a->name_en, 'type' => $a->type])->all();
            $out['banks'] = BankAccount::where('active', true)->orderBy('code')->get(['code', 'name_ar', 'name_en', 'kind'])
                ->map(fn (BankAccount $b) => ['code' => $b->code, 'nameAr' => $b->name_ar, 'nameEn' => $b->name_en, 'kind' => $b->kind])->all();
            $out['costCenters'] = CostCenter::where('active', true)->orderBy('code')->get(['code', 'name_ar', 'name_en'])
                ->map(fn (CostCenter $c) => ['code' => $c->code, 'nameAr' => $c->name_ar, 'nameEn' => $c->name_en])->all();
        }

        return $out;
    }
}
