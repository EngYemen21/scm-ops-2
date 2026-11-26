<?php

namespace App\Payroll\Services;

use App\Hr\Services\Workforce;
use App\Payroll\Models\Adjustment;
use App\Payroll\Models\Component;
use App\Payroll\Models\Payslip;
use App\Payroll\Models\Run;
use App\Payroll\Support\Access;
use App\Payroll\Support\Owned;
use App\Payroll\Support\Shape;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Facades\DB;

/**
 * One-off additions and deductions of a month: a bonus, a penalty, a loan instalment — and the way an approved run is
 * corrected, since its payslips never change: what was paid too little or too much goes into the next run, with its
 * reason. An adjustment waits (`pending`) until the payslip that carries it is approved (`applied`); while it waits it
 * can be cancelled. A month whose run is already approved for the employee takes no new adjustment — the next month does.
 */
class AdjustmentsService
{
    public function __construct(private readonly AuditService $audit, private readonly Workforce $workforce) {}

    /** @param  array{state?:?string, employee?:?string, period?:?string, component?:?string, kind?:?string}  $f */
    public function list(AuthUser $user, Paging $page, array $f): array
    {
        Access::needPay($user);
        $q = Adjustment::query();
        if (! empty($f['state'])) {
            $q->whereIn('state', array_intersect(explode(',', (string) $f['state']), Adjustment::STATES) ?: ['-']);
        }
        foreach (['employee' => 'employee_id', 'period' => 'period', 'component' => 'component_id'] as $key => $column) {
            if (! empty($f[$key])) {
                $q->where($column, $f[$key]);
            }
        }
        if (in_array($f['kind'] ?? null, Component::INPUT_CATEGORIES, true)) {
            $q->whereIn('component_id', Component::where('category', $f['kind'])->select('id'));
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('reason', 'like', $like)->orWhereIn('employee_id', fn ($s) => $s->select('id')->from('hr_employees')
                ->where(fn ($x) => $x->where('code', 'like', $like)->orWhere('name_ar', 'like', $like)->orWhere('name_en', 'like', $like))));
        }
        $column = ['amount' => 'amount', 'period' => 'period', 'state' => 'state'][$page->sort ?? ''] ?? 'created_at';
        $q->with(['employee', 'component'])->orderBy($column, $page->order)->orderBy('id', $page->order);

        return $page->paginate($q, fn (Adjustment $a) => $this->row($a, $user));
    }

    /** @param  array{employee:string, period:string, component:string, amount:float|int|string, reason:string}  $dto */
    public function create(AuthUser $user, array $dto): array
    {
        $reason = Shape::reason($dto['reason'] ?? null, 'التسوية', 'the adjustment');

        return DB::transaction(function () use ($user, $dto, $reason) {
            $employee = $this->workforce->employee((string) $dto['employee']) ?? throw AppError::notFound('EMPLOYEE_NOT_FOUND', 'الموظف غير موجود', 'Employee not found');
            $period = Shape::period($dto['period']);
            $component = Component::where('id', $dto['component'])->orWhere('code', $dto['component'])->first() ?? throw AppError::notFound('COMPONENT_NOT_FOUND', 'بند الراتب غير موجود', 'Pay component not found');
            if ($component->calc !== 'input' || ! $component->active || ! in_array($component->category, Component::INPUT_CATEGORIES, true)) {
                throw AppError::rule('COMPONENT_NOT_INPUT', "البند «{$component->name_ar}» يحسبه النظام — التسوية تكون ببند إضافة أو خصم يدوي", "Component '{$component->name_en}' is computed by the system — an adjustment uses a manual addition or deduction component");
            }
            $amount = round((float) $dto['amount'], 2);
            if ($amount <= 0) {
                throw AppError::validation('AMOUNT_INVALID', 'المبلغ يكون أكبر من صفر — الإضافة أو الخصم يحدده البند', 'The amount must be positive — the component says whether it adds or deducts');
            }
            $standing = Payslip::where('employee_id', $employee->id)->where('period', $period)->whereIn('state', Run::FINAL)->first(['number']);
            if ($standing) {
                throw AppError::rule('ADJUSTMENT_PERIOD_CLOSED', "راتب {$period} لهذا الموظف معتمد ({$standing->number}) ولا يتغيّر — أدخِل التسوية في الشهر التالي", "The pay of {$period} for this employee is approved ({$standing->number}) and does not change — enter the adjustment in the next month", ['payslip' => $standing->number]);
            }
            $a = Adjustment::create(['employee_id' => $employee->id, 'period' => $period, 'component_id' => $component->id, 'amount' => $amount, 'reason' => $reason, 'state' => 'pending', 'created_by' => $user->username]);
            // the trail says that an adjustment of which kind was entered for whom — not how much
            $this->audit->log($user, ['action' => 'PAYADJ.CREATE', 'entityType' => 'PayAdjustment', 'entityId' => $a->id, 'entityNumber' => $employee->code, 'newValue' => ['period' => $period, 'component' => $component->code]]);

            return $this->row($a->load(['employee', 'component']), $user);
        });
    }

    public function cancel(AuthUser $user, string $id, string $reason): array
    {
        $reason = Shape::reason($reason, 'إلغاء التسوية', 'cancelling the adjustment');

        return DB::transaction(function () use ($user, $id, $reason) {
            $a = Adjustment::whereKey($id)->lockForUpdate()->first() ?? throw AppError::notFound('ADJUSTMENT_NOT_FOUND', 'التسوية غير موجودة', 'Adjustment not found');
            Owned::refuse($a, 'adjustment', 'التسوية', 'The adjustment');
            if ($a->state !== 'pending') {
                throw AppError::rule('ADJUSTMENT_STATE', $a->state === 'applied' ? 'التسوية دخلت قسيمة معتمدة — تُعكس بتسوية مقابلة في الشهر التالي' : 'التسوية ملغاة مسبقًا',
                    $a->state === 'applied' ? 'The adjustment is in an approved payslip — undo it with an opposite adjustment next month' : 'The adjustment is already cancelled', ['state' => $a->state]);
            }
            $a->update(['state' => 'cancelled', 'cancelled_by' => $user->username, 'cancelled_at' => now(), 'cancel_reason' => $reason]);
            $this->audit->log($user, ['action' => 'PAYADJ.CANCEL', 'entityType' => 'PayAdjustment', 'entityId' => $a->id, 'entityNumber' => $a->employee?->code, 'newValue' => ['reason' => $reason]]);

            return $this->row($a->load(['employee', 'component']), $user);
        });
    }

    private function row(Adjustment $a, AuthUser $user): array
    {
        return ['id' => $a->id, 'employee' => Shape::employee($a->employee), 'period' => $a->period, 'component' => Shape::named($a->component, ['category' => 'category']),
            'kind' => $a->component?->category === 'deduction' ? 'deduction' : 'addition', 'amount' => $a->amount, 'reason' => $a->reason, 'state' => $a->state, 'payslipId' => $a->payslip_id,
            'cancelledBy' => $a->cancelled_by, 'cancelReason' => $a->cancel_reason, 'createdBy' => $a->created_by, 'createdAt' => Shape::at($a->created_at), 'source' => $a->source_system,
            'can' => ['cancel' => $a->state === 'pending' && $user->can('payroll.manage') && ! Owned::locked($a)]];
    }
}
