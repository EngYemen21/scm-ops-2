<?php

namespace App\Hr\Services;

use App\Hr\Models\Contract;
use App\Hr\Models\Department;
use App\Hr\Models\Employee;
use App\Hr\Models\HrAlert;
use App\Hr\Support\Hr;
use App\Hr\Support\Source;
use App\Services\Core\AuditService;
use App\Services\Core\NumberingService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Employment contracts: type, start, end, wage and allowances, state — people's pay, so everything here needs
 * `hr.private` (the routes require it and every entry point checks it again).
 *
 * draft → running → expired, or cancelled; one running contract per employee at a time. A contract whose end date
 * has passed stays `running` until a person renews it or ends it: the alert (AlertsService, category `contract`)
 * says so 60 / 30 / 7 days before and after. The audit trail names the fields that changed, never the amounts.
 */
class ContractsService
{
    private const MONEY = ['wage', 'housing_allowance', 'transport_allowance', 'other_allowances'];

    private const FIELDS = ['reference' => 'reference', 'type' => 'type', 'jobTitle' => 'job_title', 'notes' => 'notes'];

    public function __construct(private readonly AuditService $audit, private readonly NumberingService $numbering, private readonly AlertsService $alerts) {}

    /** @param  array{state?:?string, employee?:?string, type?:?string, ending?:?string, source?:?string, order?:?string}  $f */
    public function list(AuthUser $user, Paging $page, array $f): array
    {
        Hr::needPrivate($user);
        $q = Contract::query()->with(['employee:id,code,name_ar,name_en,status', 'department:id,name_ar,name_en']);
        if (! empty($f['state'])) {
            $q->whereIn('state', array_intersect(explode(',', (string) $f['state']), Contract::STATES) ?: ['-']);
        }
        if (! empty($f['employee'])) {
            $q->where('employee_id', $f['employee']);
        }
        if (! empty($f['type'])) {
            $q->where('type', $f['type']);
        }
        if (! empty($f['source'])) {
            $f['source'] === Source::ODOO ? $q->where('source_system', Source::ODOO) : $q->whereNull('source_system');
        }
        if (! empty($f['ending'])) { // running contracts whose end date is within N days, or already past
            $q->where('state', 'running')->whereNotNull('end_date')->whereDate('end_date', '<=', Carbon::today()->addDays(max(0, (int) $f['ending']))->toDateString());
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('reference', 'like', $like)->orWhere('job_title', 'like', $like)
                ->orWhereIn('employee_id', fn ($s) => $s->select('id')->from('hr_employees')->where(fn ($x) => $x->where('code', 'like', $like)->orWhere('name_ar', 'like', $like)->orWhere('name_en', 'like', $like))));
        }
        $column = ['start' => 'start_date', 'end' => 'end_date', 'number' => 'number'][$page->sort ?? 'start'] ?? 'start_date';
        $order = ($f['order'] ?? null) === 'asc' ? 'asc' : 'desc';
        if ($column === 'end_date') {
            $q->orderByRaw('end_date IS NULL');
        }
        $q->orderBy($column, $order)->orderBy('id', $order);

        return $page->paginate($q, fn (Contract $c) => $this->shape($c));
    }

    public function get(AuthUser $user, string $idOrNumber): array
    {
        Hr::needPrivate($user);

        return $this->shape($this->find($idOrNumber)->load(['employee:id,code,name_ar,name_en,status', 'department:id,name_ar,name_en']));
    }

    /** The contracts of one employee, newest first (the employee's card). */
    public function forEmployee(AuthUser $user, string $employeeId): array
    {
        Hr::needPrivate($user);

        return Contract::with('department:id,name_ar,name_en')->where('employee_id', $employeeId)->orderByDesc('start_date')->orderByDesc('id')->get()->map(fn (Contract $c) => $this->shape($c))->all();
    }

    public function create(AuthUser $user, array $dto): array
    {
        Hr::needPrivate($user);

        return DB::transaction(function () use ($user, $dto) {
            $e = Employee::where('id', $dto['employee'])->orWhere('code', $dto['employee'])->first() ?? throw AppError::notFound('EMPLOYEE_NOT_FOUND', 'الموظف غير موجود', 'Employee not found');
            $values = $this->values($dto, null) + ['type' => 'permanent'];
            if (empty($values['start_date'])) {
                throw AppError::validation('CONTRACT_START', 'تاريخ بداية العقد مطلوب', 'The start date is required');
            }
            $this->assertDates($values['start_date'], $values['end_date'] ?? null, $values['trial_end_date'] ?? null);
            $c = Contract::create(['number' => $this->numbering->next('HRC'), 'employee_id' => $e->id, 'state' => 'draft', 'created_by' => $user->username,
                'department_id' => $values['department_id'] ?? $e->department_id, 'job_title' => $values['job_title'] ?? $e->job_title] + $values);
            $this->audit->log($user, ['action' => 'HR.CONTRACT.CREATE', 'entityType' => 'Contract', 'entityId' => $c->id, 'entityNumber' => $c->number, 'newValue' => ['employee' => $e->code, 'type' => $c->type]]);
            $this->audit->status($user, 'Contract', $c->id, $c->number, null, 'draft');

            return $this->get($user, $c->id);
        });
    }

    public function update(AuthUser $user, string $idOrNumber, array $dto): array
    {
        Hr::needPrivate($user);

        return DB::transaction(function () use ($user, $idOrNumber, $dto) {
            $c = $this->lock($idOrNumber);
            $changes = Hr::diff($c, $this->values($dto, $c), self::MONEY);
            if ($changes && Source::locked($c->source_system) && array_diff(array_keys($changes), ['notes'])) {
                throw Source::refusal('contract', "العقد {$c->number}", "Contract {$c->number}");
            }
            if (! $changes) {
                return $this->get($user, $c->id);
            }
            // the terms of a closed contract are history; a note may still be added to it
            if (! in_array($c->state, ['draft', 'running'], true) && array_diff(array_keys($changes), ['notes'])) {
                throw AppError::rule('CONTRACT_STATE', "العقد {$c->number} {$this->stateAr($c->state)} — لا يُعدَّل؛ أنشئ عقدًا جديدًا أو جدِّده", "Contract {$c->number} is {$c->state} — it cannot be edited; renew it or create a new one");
            }
            $this->assertDates($changes['start_date'] ?? Hr::day($c->start_date), array_key_exists('end_date', $changes) ? $changes['end_date'] : Hr::day($c->end_date),
                array_key_exists('trial_end_date', $changes) ? $changes['trial_end_date'] : Hr::day($c->trial_end_date));
            $c->update($changes);
            $this->audit->log($user, ['action' => 'HR.CONTRACT.UPDATE', 'entityType' => 'Contract', 'entityId' => $c->id, 'entityNumber' => $c->number, 'newValue' => ['fields' => array_keys($changes)]]);
            $this->alerts->refreshEmployee($c->employee_id);

            return $this->get($user, $c->id);
        });
    }

    /** draft → running: the contract is in force. An employee has one running contract at a time. */
    public function start(AuthUser $user, string $idOrNumber): array
    {
        return $this->move($user, $idOrNumber, 'running', null, function (Contract $c) {
            $other = Contract::where('employee_id', $c->employee_id)->where('state', 'running')->where('id', '!=', $c->id)->first();
            if ($other) {
                throw AppError::rule('CONTRACT_OVERLAP', "للموظف عقد سارٍ ({$other->number}) — أنهِه أو ألغِه قبل تشغيل {$c->number}", "The employee has a running contract ({$other->number}) — end or cancel it before starting {$c->number}", ['running' => $other->number]);
            }

            return [];
        });
    }

    /** running → expired: the contract ended on a date (today when none is given). */
    public function end(AuthUser $user, string $idOrNumber, ?string $date, ?string $reason): array
    {
        return $this->move($user, $idOrNumber, 'expired', $reason, function (Contract $c) use ($date) {
            $day = Hr::date($date) ?? Carbon::today()->toDateString();
            if ($day < Hr::day($c->start_date)) {
                throw AppError::validation('CONTRACT_DATES', 'تاريخ نهاية العقد قبل بدايته', 'The end date is before the start date');
            }

            return ['end_date' => $day];
        });
    }

    public function cancel(AuthUser $user, string $idOrNumber, string $reason): array
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw AppError::validation('REASON_REQUIRED', 'اكتب سبب الإلغاء (5 أحرف على الأقل)', 'A reason is required (at least 5 characters)');
        }

        return $this->move($user, $idOrNumber, 'cancelled', $reason, fn () => []);
    }

    /** A new draft that continues a running or expired contract: same terms, starting the day after it ends. */
    public function renew(AuthUser $user, string $idOrNumber, array $dto): array
    {
        Hr::needPrivate($user);

        return DB::transaction(function () use ($user, $idOrNumber, $dto) {
            $c = $this->lock($idOrNumber);
            if (! in_array($c->state, ['running', 'expired'], true)) {
                throw AppError::rule('CONTRACT_STATE', "يُجدَّد العقد الساري أو المنتهي فقط — {$c->number} {$this->stateAr($c->state)}", "Only a running or expired contract is renewed — {$c->number} is {$c->state}");
            }
            $start = Hr::date($dto['startDate'] ?? null) ?? ($c->end_date ? Carbon::parse(Hr::day($c->end_date))->addDay()->toDateString() : Carbon::today()->toDateString());
            $end = Hr::date($dto['endDate'] ?? null);
            $this->assertDates($start, $end, null);
            $n = Contract::create(['number' => $this->numbering->next('HRC'), 'employee_id' => $c->employee_id, 'reference' => null, 'type' => $c->type, 'type_label' => $c->type_label, 'job_title' => $c->job_title,
                'department_id' => $c->department_id, 'start_date' => $start, 'end_date' => $end, 'wage' => $c->wage, 'housing_allowance' => $c->housing_allowance, 'transport_allowance' => $c->transport_allowance,
                'other_allowances' => $c->other_allowances, 'currency' => $c->currency, 'state' => 'draft', 'notes' => "تجديد للعقد {$c->number}", 'created_by' => $user->username]);
            $this->audit->log($user, ['action' => 'HR.CONTRACT.RENEW', 'entityType' => 'Contract', 'entityId' => $n->id, 'entityNumber' => $n->number, 'newValue' => ['renews' => $c->number]]);
            $this->audit->status($user, 'Contract', $n->id, $n->number, null, 'draft', "تجديد {$c->number}");

            return $this->get($user, $n->id);
        });
    }

    private function move(AuthUser $user, string $idOrNumber, string $to, ?string $note, callable $check): array
    {
        Hr::needPrivate($user);

        return DB::transaction(function () use ($user, $idOrNumber, $to, $note, $check) {
            $c = $this->lock($idOrNumber);
            Source::assertFree($c->source_system, 'contract', "العقد {$c->number}", "Contract {$c->number}");
            $from = $c->state;
            if (! in_array($to, Contract::TRANSITIONS[$from] ?? [], true)) {
                throw AppError::rule('CONTRACT_TRANSITION', "انتقال غير مسموح للعقد {$c->number}: {$this->stateAr($from)} ← {$this->stateAr($to)}", "Invalid transition for contract {$c->number}: {$from} -> {$to}");
            }
            $extra = $check($c);
            $c->update(['state' => $to] + $extra + (in_array($to, ['expired', 'cancelled'], true) ? ['closed_by' => $user->username, 'closed_at' => now(), 'close_reason' => Hr::text($note, 500)] : []));
            $this->audit->status($user, 'Contract', $c->id, $c->number, $from, $to, Hr::text($note, 500));
            $this->alerts->refreshEmployee($c->employee_id);

            return $this->get($user, $c->id);
        });
    }

    private function values(array $dto, ?Contract $c): array
    {
        $v = [];
        foreach (self::FIELDS as $field => $column) {
            if (array_key_exists($field, $dto)) {
                $v[$column] = Hr::text($dto[$field], $column === 'notes' ? 2000 : 250);
            }
        }
        if (array_key_exists('type', $v) && ! in_array($v['type'], Contract::TYPES, true)) {
            unset($v['type']);
        }
        foreach (['startDate' => 'start_date', 'endDate' => 'end_date', 'trialEndDate' => 'trial_end_date'] as $field => $column) {
            if (array_key_exists($field, $dto)) {
                $v[$column] = Hr::date($dto[$field]);
            }
        }
        if (array_key_exists('start_date', $v) && $v['start_date'] === null && $c) {
            unset($v['start_date']); // a contract always has a start
        }
        foreach (['wage' => 'wage', 'housingAllowance' => 'housing_allowance', 'transportAllowance' => 'transport_allowance', 'otherAllowances' => 'other_allowances'] as $field => $column) {
            if (array_key_exists($field, $dto)) {
                $v[$column] = max(0, Hr::money($dto[$field]));
            }
        }
        if (array_key_exists('departmentId', $dto)) {
            $v['department_id'] = $dto['departmentId'] ? (Department::find($dto['departmentId'])?->id ?? throw AppError::notFound('DEPARTMENT_NOT_FOUND', 'القسم غير موجود', 'Department not found')) : null;
        }

        return $v;
    }

    private function assertDates(string $start, ?string $end, ?string $trialEnd): void
    {
        if ($end !== null && $end < $start) {
            throw AppError::validation('CONTRACT_DATES', 'تاريخ نهاية العقد قبل بدايته', 'The end date is before the start date');
        }
        if ($trialEnd !== null && ($trialEnd < $start || ($end !== null && $trialEnd > $end))) {
            throw AppError::validation('CONTRACT_DATES', 'نهاية فترة التجربة خارج مدة العقد', 'The end of the trial period is outside the contract');
        }
    }

    private function find(string $idOrNumber): Contract
    {
        return Contract::where('id', $idOrNumber)->orWhere('number', $idOrNumber)->first() ?? throw AppError::notFound('CONTRACT_NOT_FOUND', 'العقد غير موجود', 'Contract not found');
    }

    private function lock(string $idOrNumber): Contract
    {
        return Contract::where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->lockForUpdate()->first() ?? throw AppError::notFound('CONTRACT_NOT_FOUND', 'العقد غير موجود', 'Contract not found');
    }

    private function stateAr(?string $state): string
    {
        return ['draft' => 'مسودة', 'running' => 'سارٍ', 'expired' => 'منتهٍ', 'cancelled' => 'ملغى'][$state ?? ''] ?? (string) $state;
    }

    private function shape(Contract $c): array
    {
        $e = $c->relationLoaded('employee') ? $c->employee : null;
        $wage = (float) $c->wage;
        $allowances = (float) $c->housing_allowance + (float) $c->transport_allowance + (float) $c->other_allowances;
        $days = $c->state === 'running' ? Hr::daysTo($c->end_date) : null;

        return [
            'id' => $c->id, 'number' => $c->number, 'reference' => $c->reference, 'type' => $c->type, 'typeLabel' => $c->type_label, 'jobTitle' => $c->job_title,
            'department' => $c->department ? ['id' => $c->department->id, 'nameAr' => $c->department->name_ar, 'nameEn' => $c->department->name_en] : null,
            'startDate' => Hr::day($c->start_date), 'endDate' => Hr::day($c->end_date), 'trialEndDate' => Hr::day($c->trial_end_date),
            'wage' => number_format($wage, 2, '.', ''), 'housingAllowance' => number_format((float) $c->housing_allowance, 2, '.', ''), 'transportAllowance' => number_format((float) $c->transport_allowance, 2, '.', ''),
            'otherAllowances' => number_format((float) $c->other_allowances, 2, '.', ''), 'package' => number_format($wage + $allowances, 2, '.', ''), 'currency' => $c->currency,
            'state' => $c->state, 'endsInDays' => $days, 'stage' => $days === null ? null : HrAlert::stageOf($days),
            'notes' => $c->notes, 'closedBy' => $c->closed_by, 'closedAt' => Hr::at($c->closed_at), 'closeReason' => $c->close_reason,
            'source' => $c->source_system, 'locked' => Source::locked($c->source_system), 'createdBy' => $c->created_by, 'createdAt' => Hr::at($c->created_at),
            'can' => $this->can($c),
        ] + ($e ? ['employee' => ['id' => $e->id, 'code' => $e->code, 'nameAr' => $e->name_ar, 'nameEn' => $e->name_en, 'status' => $e->status]] : ['employeeId' => $c->employee_id]);
    }

    /** What a holder of hr.private may do with it now (the client shows these buttons and no others). */
    private function can(Contract $c): array
    {
        $free = ! Source::locked($c->source_system);

        return ['edit' => $free && in_array($c->state, ['draft', 'running'], true), 'start' => $free && $c->state === 'draft', 'end' => $free && $c->state === 'running',
            'cancel' => $free && in_array($c->state, ['draft', 'running'], true), 'renew' => in_array($c->state, ['running', 'expired'], true)];
    }
}
