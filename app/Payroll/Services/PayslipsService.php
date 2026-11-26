<?php

namespace App\Payroll\Services;

use App\Hr\Models\Claim;
use App\Payroll\Models\Payslip;
use App\Payroll\Models\PayslipLine;
use App\Payroll\Models\Profile;
use App\Payroll\Models\Run;
use App\Payroll\Support\Access;
use App\Payroll\Support\Owned;
use App\Payroll\Support\Shape;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;

/**
 * Reading payslips. The payroll people (`payroll.manage` / `payroll.approve`) read all of them, in any state; an
 * employee reads their OWN, and only once the run is approved — a draft is not yet anybody's pay.
 */
class PayslipsService
{
    /** @param  array{scope?:?string, run?:?string, employee?:?string, department?:?string, period?:?string, year?:?string, state?:?string, source?:?string}  $f */
    public function list(AuthUser $user, Paging $page, array $f): array
    {
        $q = Payslip::query();
        if (($f['scope'] ?? 'mine') === 'all') {
            Access::needPay($user);
        } else {
            $q->where('employee_id', Access::employeeId($user) ?? '-')->whereIn('state', Payslip::VISIBLE_TO_EMPLOYEE);
        }
        if (! empty($f['state'])) {
            $q->whereIn('state', array_intersect(explode(',', (string) $f['state']), Run::STATES) ?: ['-']);
        }
        foreach (['run' => 'run_id', 'employee' => 'employee_id', 'department' => 'department_id', 'period' => 'period'] as $key => $column) {
            if (! empty($f[$key])) {
                $q->where($column, $f[$key]);
            }
        }
        if (! empty($f['year'])) {
            $q->where('period', 'like', ((int) $f['year']).'-%');
        }
        if (! empty($f['source'])) {
            $f['source'] === Owned::ODOO ? $q->where('source_system', Owned::ODOO) : $q->whereNull('source_system');
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('period', 'like', $like)->orWhereIn('employee_id', fn ($s) => $s->select('id')->from('hr_employees')
                ->where(fn ($x) => $x->where('code', 'like', $like)->orWhere('name_ar', 'like', $like)->orWhere('name_en', 'like', $like))));
        }
        $totals = (clone $q)->toBase()->selectRaw('count(*) as n, coalesce(sum(gross), 0) as gross, coalesce(sum(net), 0) as net')->first();
        $column = ['number' => 'number', 'net' => 'net', 'gross' => 'gross', 'state' => 'state'][$page->sort ?? 'period'] ?? 'period';
        $rows = $q->with(['employee', 'department', 'run:id,number'])->orderBy($column, $page->order)->orderBy('number', $page->order)->forPage($page->page, $page->pageSize)->get();

        return $page->wrap($rows->map(fn (Payslip $p) => self::row($p, $user))->all(), (int) $totals->n)
            + ['totals' => ['gross' => number_format((float) $totals->gross, 2, '.', ''), 'net' => number_format((float) $totals->net, 2, '.', '')]];
    }

    public function get(AuthUser $user, string $idOrNumber): array
    {
        $p = Payslip::with(['employee', 'department', 'run:id,number,state,period', 'structure', 'contract:id,number,type,start_date,end_date'])
            ->where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->first() ?? throw AppError::notFound('PAYSLIP_NOT_FOUND', 'قسيمة الراتب غير موجودة', 'Payslip not found');
        if (! Access::managesPay($user)) {
            // somebody else's payslip and a payslip that is not yet pay answer the same way: it is not there for this caller
            if (Access::employeeId($user) !== $p->employee_id || ! in_array($p->state, Payslip::VISIBLE_TO_EMPLOYEE, true)) {
                throw Access::denied();
            }
        }
        $lines = PayslipLine::where('payslip_id', $p->id)->orderBy('sequence')->orderBy('code')->orderBy('id')->get();
        $claims = Claim::whereIn('id', $lines->pluck('claim_id')->filter()->all() ?: ['-'])->pluck('number', 'id');

        return self::row($p, $user) + [
            'structure' => Shape::named($p->structure, ['gosiScheme' => 'gosi_scheme']),
            'contract' => $p->contract ? ['id' => $p->contract->id, 'number' => $p->contract->number, 'type' => $p->contract->type, 'startDate' => Shape::day($p->contract->start_date), 'endDate' => Shape::day($p->contract->end_date)] : null,
            'paymentMethod' => Profile::where('employee_id', $p->employee_id)->value('payment_method') ?? 'bank',
            'worked' => $p->worked, 'explain' => $p->explain, 'sourceState' => $p->source_state, 'externalRef' => $p->external_ref,
            'lines' => $lines->map(fn (PayslipLine $l) => ['id' => $l->id, 'sequence' => $l->sequence, 'code' => $l->code, 'nameAr' => $l->name_ar, 'nameEn' => $l->name_en, 'category' => $l->category,
                'quantity' => (float) $l->quantity, 'rate' => (float) $l->rate, 'amount' => $l->amount, 'total' => $l->total, 'explain' => $l->explain,
                'claim' => $l->claim_id ? ['id' => $l->claim_id, 'number' => $claims[$l->claim_id] ?? null] : null])->all(),
        ];
    }

    /** One payslip as a list shows it. */
    public static function row(Payslip $p, AuthUser $user): array
    {
        return ['id' => $p->id, 'number' => $p->number, 'run' => $p->run_id ? ['id' => $p->run_id, 'number' => $p->relationLoaded('run') ? $p->run?->number : null] : null,
            'employee' => Shape::employee($p->employee), 'department' => Shape::department($p->department), 'period' => $p->period, 'dateFrom' => Shape::day($p->date_from), 'dateTo' => Shape::day($p->date_to),
            'state' => $p->state, 'currency' => $p->currency, 'daysCovered' => $p->days_covered, 'daysInMonth' => $p->days_in_month, 'basic' => $p->basic, 'gross' => $p->gross, 'deductions' => $p->deductions,
            'reimbursements' => $p->reimbursements, 'net' => $p->net, 'employerCost' => $p->employer_cost, 'warnings' => $p->warnings ?? [],
            'mine' => Access::employeeId($user) === $p->employee_id, 'source' => $p->source_system, 'locked' => Owned::locked($p)];
    }
}
