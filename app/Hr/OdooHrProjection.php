<?php

namespace App\Hr;

use App\Hr\Models\Claim;
use App\Hr\Models\ClaimLine;
use App\Hr\Models\Contract;
use App\Hr\Models\Department;
use App\Hr\Models\Employee;
use App\Hr\Models\EmployeePrivate;
use App\Hr\Models\ExpenseCategory;
use App\Hr\Models\HrFile;
use App\Hr\Models\Job;
use App\Hr\Services\AlertsService;
use App\Hr\Services\PrivateFileService;
use App\Hr\Support\Hr;
use App\Hr\Support\Source;
use App\Integration\Odoo\Models\OdooRecord;
use App\Integration\Odoo\OdooMasterData;
use App\Integration\Odoo\Projection\OdooProjection;
use App\Integration\Odoo\Projection\Val;
use App\Integration\Services\ExternalRefs;
use App\Services\Core\AuditService;
use App\Services\Core\NumberingService;
use App\Support\AppError;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * From the copy of Odoo to the «الموظفون والمصروفات» section (docs/odoo/WAVE2.md §7): Odoo's departments, job
 * positions, employees, contracts, expense reports with their lines, and the files attached to them become OPS
 * records marked `source_system = odoo` + `external_ref`. Idempotent: a second pass changes nothing.
 *
 * WHERE THINGS GO. An employee is split the way the section is: the work part into `hr_employees`, the personal
 * part (identity number, birth date, family, private phone, bank account, how the person left) into
 * `hr_employee_private`, contracts into `hr_contracts` — the two that only `hr.private` reads. A value is written only
 * when the copy holds its field: a deployment that does not copy the personal part leaves the OPS file untouched.
 *
 * WHAT IS ODOO'S. Exactly the columns listed in DirectoryService::*_SOURCE_OWNED and PrivateFileService::SOURCE_OWNED —
 * this class writes those and nothing else, so what OPS adds (the login and driver links a person sets, the expiry
 * of the identity, the passport, notes, the account of an expense category) survives every pass.
 *
 * LINKS BY IDENTITY. An employee is linked to an OPS login when the work e-mail or the Odoo login is that user's
 * e-mail or username — and to that login's driver record, or to the driver with the same staff number. Never by name.
 *
 * EXPENSES ARE HISTORY. A report becomes a claim that carries Odoo's state and Odoo's figures; nothing is posted to
 * the OPS books (`posted_in = odoo` for what Odoo posted — the books of that period are Odoo's). An expense that was
 * never put on a report becomes a draft claim of its own, so the employee can carry on in OPS after the hand-over.
 *
 * PEOPLE'S FILES. The counts and notes of a pass name records by model and Odoo id only — never a person, and a
 * database error is re-thrown without its statement, because the statement carries the values.
 */
class OdooHrProjection extends OdooProjection
{
    private const SHEET_STATE = ['draft' => 'draft', 'submit' => 'submitted', 'approve' => 'approved', 'post' => 'posted', 'done' => 'paid', 'cancel' => 'refused'];

    private const EXPENSE_STATE = ['draft' => 'draft', 'reported' => 'draft', 'submitted' => 'submitted', 'approved' => 'approved', 'done' => 'paid', 'refused' => 'refused'];

    private const CONTRACT_STATE = ['draft' => 'draft', 'open' => 'running', 'close' => 'expired', 'cancel' => 'cancelled'];

    private const MONEY = ['wage', 'housing_allowance', 'transport_allowance', 'other_allowances', 'amount', 'vat_amount', 'total'];

    /** What an attachment hangs on: Odoo model → [owner type here, the link entity of its owner]. */
    private const FILE_OWNERS = ['hr.employee' => ['employee', 'hr_employee'], 'hr.contract' => ['contract', 'hr_contract'], 'hr.expense' => ['claim_line', 'hr_claim_line'], 'hr.expense.sheet' => ['claim', 'hr_claim']];

    /** @var array<string, array<int|string, true>> per entity: what a plan would have created (a plan writes no link) */
    private array $planned = [];

    /** @var array<string, array<string, array<int, int[]>>> model → field → Odoo id pointed at → the Odoo ids that point at it */
    private array $pointing = [];

    /** @var array{email: array<string, string>, username: array<string, string>}|null OPS logins by e-mail and by username */
    private ?array $logins = null;

    /** @var array<int, string>|null Odoo user id → its login */
    private ?array $odooLogins = null;

    /** @var array<int, ?string> Odoo product id → OPS category id */
    private array $categories = [];

    /** @var array<int, float> Odoo tax id → percentage */
    private array $taxes = [];

    public function __construct(ExternalRefs $refs, private readonly NumberingService $numbering, private readonly AuditService $audit, private readonly AlertsService $alerts)
    {
        parent::__construct($refs);
    }

    public function key(): string
    {
        return Source::AREA;
    }

    /** Parents before children; the attachments last — they hang on all of the others. */
    public function models(): array
    {
        return ['hr.department', 'hr.job', 'hr.employee', 'hr.contract', 'hr.expense.sheet', 'hr.expense', 'ir.attachment'];
    }

    /**
     * After the hand-over (the switch is off) the section is OPS's: loading it from the copy again would put Odoo's
     * old values over what people changed here since. A plan may still be asked for — it writes nothing.
     */
    public function apply(): array
    {
        if (! $this->enabled()) {
            throw AppError::rule('ODOO_AREA_HANDED_OVER', 'قسم الموظفين والمصروفات سُلِّم لنظام العمليات (ODOO_PROJECT_HR متوقف) — إعادة تحميله من أودو تكتب قيم أودو فوق ما عُدِّل هنا. شغِّل المتابعة أولًا إن كان هذا المقصود',
                'People & expenses was handed over to OPS (ODOO_PROJECT_HR is off) — loading it from Odoo again would overwrite what was changed here. Turn the switch on first if that is intended');
        }

        return parent::apply();
    }

    public function consume(int $max = 500, float $seconds = 8.0): array
    {
        return $this->enabled() ? parent::consume($max, $seconds) : ['changes' => 0, 'counts' => []];
    }

    protected function begin(): void
    {
        $this->planned = $this->pointing = $this->categories = $this->taxes = [];
        $this->logins = $this->odooLogins = null;
        if (! $this->dry) {
            $this->actor(); // the service user exists before the first record's transaction, so a record that fails cannot take it back
        }
    }

    protected function project(OdooRecord $r): void
    {
        // tens of thousands of attachments hang on other areas' records: they are passed over before anything is opened for them
        if ($r->model === 'ir.attachment' && ! isset(self::FILE_OWNERS[is_string($r->data['res_model'] ?? null) ? $r->data['res_model'] : ''])) {
            return;
        }
        $work = fn () => match ($r->model) {
            'hr.department' => $this->department($r), 'hr.job' => $this->job($r), 'hr.employee' => $this->employee($r), 'hr.contract' => $this->contract($r),
            'hr.expense.sheet' => $this->sheet($r), 'hr.expense' => $this->expense($r), 'ir.attachment' => $this->attachment($r), default => null,
        };
        try {
            $this->dry ? $work() : DB::transaction($work); // one record is applied whole or not at all
        } catch (QueryException $e) {
            throw new RuntimeException("the database refused the record (SQLSTATE {$e->getCode()})"); // never the statement: it carries a person's values
        }
    }

    // ───────────────────────────── departments and jobs ─────────────────────────────

    private function department(OdooRecord $r): void
    {
        $d = (array) $r->data;
        [$ar, $en] = OdooMasterData::names((string) ($d['name'] ?? ''));
        if ($ar === '') {
            $this->count('departments.skippedNoName', "hr.department #{$r->odoo_id}");

            return;
        }
        $values = ['name_ar' => $ar, 'name_en' => $en ?: $ar, 'active' => $this->alive($r)]
            + $this->reference('parent_id', 'hr_department', Val::id($d['parent_id'] ?? null), $r->odoo_id)
            + $this->reference('manager_id', 'hr_employee', Val::id($d['manager_id'] ?? null));
        $row = $this->upsert('departments', 'hr_department', Department::class, $r, $values, fn () => ['created_by' => 'svc.odoo'], fn (Department $x) => ['HR.DEPARTMENT', 'Department', $x->name_ar]);
        if ($row && ! $this->dry) { // sub-departments that arrived before this one
            foreach ($this->pointingAt('hr.department', 'parent_id', $r->odoo_id) as $child) {
                if (($id = $this->linked('hr_department', $child)) && $id !== $row->id) {
                    Department::whereKey($id)->where(fn ($w) => $w->whereNull('parent_id')->orWhere('parent_id', '!=', $row->id))->update(['parent_id' => $row->id]);
                }
            }
        }
    }

    private function job(OdooRecord $r): void
    {
        $d = (array) $r->data;
        [$ar, $en] = OdooMasterData::names((string) ($d['name'] ?? ''));
        if ($ar === '') {
            $this->count('jobs.skippedNoName', "hr.job #{$r->odoo_id}");

            return;
        }
        $values = ['name_ar' => $ar, 'name_en' => $en ?: $ar, 'active' => $this->alive($r)] + $this->reference('department_id', 'hr_department', Val::id($d['department_id'] ?? null));
        if (array_key_exists('description', $d)) {
            $values['description'] = Val::plain($d['description'], 2000);
        }
        $this->upsert('jobs', 'hr_job', Job::class, $r, $values, fn () => ['created_by' => 'svc.odoo'], fn (Job $x) => ['HR.JOB', 'Job', $x->name_ar]);
    }

    // ───────────────────────────── employees ─────────────────────────────

    private function employee(OdooRecord $r): void
    {
        $d = (array) $r->data;
        [$ar, $en] = OdooMasterData::names((string) ($d['name'] ?? ''));
        if ($ar === '') {
            $this->count('employees.skippedNoName', "hr.employee #{$r->odoo_id}");

            return;
        }
        $has = fn (string $key) => array_key_exists($key, $d);
        $left = Val::date($d['departure_date'] ?? null) !== null || Val::id($d['departure_reason_id'] ?? null) !== null;
        $values = ['name_ar' => $ar, 'name_en' => $en ?: $ar, 'status' => $this->alive($r) ? 'active' : ($left ? 'left' : 'inactive')]
            + $this->reference('department_id', 'hr_department', Val::id($d['department_id'] ?? null))
            + $this->reference('job_id', 'hr_job', Val::id($d['job_id'] ?? null))
            + $this->reference('manager_id', 'hr_employee', Val::id($d['parent_id'] ?? null), $r->odoo_id)
            + ($has('registration_number') ? ['employee_no' => Val::text($d['registration_number'], 40)] : [])
            + ($has('job_title') || $has('job_id') ? ['job_title' => Val::text($d['job_title'] ?? null) ?? ($this->jobName(Val::label($d['job_id'] ?? null)))] : [])
            + ($has('work_email') ? ['work_email' => ($x = Val::text($d['work_email'])) === null ? null : mb_strtolower($x)] : [])
            + ($has('work_phone') ? ['work_phone' => Val::text($d['work_phone'], 40)] : [])
            + ($has('mobile_phone') ? ['work_mobile' => Val::text($d['mobile_phone'], 40)] : [])
            + ($has('work_location_id') ? ['work_location' => Val::label($d['work_location_id'])] : [])
            + ($has('first_contract_date') ? ['start_date' => Val::date($d['first_contract_date'])] : []);
        $before = $this->existing('hr_employee', Employee::class, $r->odoo_id)?->status;
        /** @var ?Employee $row */
        $row = $this->upsert('employees', 'hr_employee', Employee::class, $r, $values, fn () => ['code' => $this->numbering->next('EMP'), 'created_by' => 'svc.odoo'], fn (Employee $x) => ['HR.EMPLOYEE', 'Employee', $x->code]);
        $this->personal($d, $row);
        $this->linkLogin($d, $row);
        if (! $row || $this->dry) {
            return;
        }
        // people and departments that arrived before the person they point at
        foreach ($this->pointingAt('hr.employee', 'parent_id', $r->odoo_id) as $report) {
            if (($id = $this->linked('hr_employee', $report)) && $id !== $row->id) {
                Employee::whereKey($id)->where(fn ($w) => $w->whereNull('manager_id')->orWhere('manager_id', '!=', $row->id))->update(['manager_id' => $row->id]);
            }
        }
        foreach ($this->pointingAt('hr.department', 'manager_id', $r->odoo_id) as $department) {
            if ($id = $this->linked('hr_department', $department)) {
                Department::whereKey($id)->where(fn ($w) => $w->whereNull('manager_id')->orWhere('manager_id', '!=', $row->id))->update(['manager_id' => $row->id]);
            }
        }
        if ($before !== null && $before !== $row->status) {
            $this->alerts->refreshEmployee($row->id); // a person who left has nothing to renew
        }
    }

    /** The personal part of the Odoo record into the personal file — only the fields the copy holds. */
    private function personal(array $d, ?Employee $e): void
    {
        $v = [];
        $take = function (string $column, string $key, callable $read) use (&$v, $d) {
            if (array_key_exists($key, $d)) {
                $v[$column] = $read($d[$key]);
            }
        };
        $take('nationality', 'country_id', fn ($x) => Val::label($x));
        $take('identity_no', 'identification_id', fn ($x) => Val::text($x, 40));
        $take('birth_date', 'birthday', fn ($x) => Val::date($x));
        $take('gender', 'gender', fn ($x) => in_array($x, EmployeePrivate::GENDERS, true) ? $x : null);
        $take('marital_status', 'marital', fn ($x) => ['single' => 'single', 'married' => 'married', 'cohabitant' => 'married', 'divorced' => 'divorced', 'widower' => 'widowed'][is_string($x) ? $x : ''] ?? null);
        $take('children', 'children', fn ($x) => is_int($x) ? $x : null);
        $take('private_phone', 'private_phone', fn ($x) => Val::text($x, 40));
        $take('private_email', 'private_email', fn ($x) => ($t = Val::text($x)) === null ? null : mb_strtolower($t));
        $take('emergency_contact', 'emergency_contact', fn ($x) => Val::text($x));
        $take('emergency_phone', 'emergency_phone', fn ($x) => Val::text($x, 40));
        $take('end_date', 'departure_date', fn ($x) => Val::date($x));
        $take('departure_reason', 'departure_reason_id', fn ($x) => Val::label($x));
        if (array_key_exists('private_street', $d) || array_key_exists('private_city', $d)) {
            $parts = array_filter([Val::text($d['private_street'] ?? null), Val::text($d['private_street2'] ?? null), Val::text($d['private_city'] ?? null),
                Val::label($d['private_state_id'] ?? null), Val::text($d['private_zip'] ?? null), Val::label($d['private_country_id'] ?? null)]);
            $v['home_address'] = $parts ? mb_substr(implode('، ', $parts), 0, 2000) : null;
        }
        if (array_key_exists('bank_account_id', $d)) {
            // the account itself is another record of the copy; without it the number is not guessed from a label
            $bank = ($id = Val::id($d['bank_account_id'])) ? (array) ($this->mirrored('res.partner.bank', $id)?->data ?? []) : [];
            $number = Val::text($bank['acc_number'] ?? null, 60);
            $v['bank_account'] = $number === null ? null : mb_strtoupper(preg_replace('/\s+/', '', $number));
            $v['bank_name'] = Val::label($bank['bank_id'] ?? null);
            if ($id && ! $bank) {
                unset($v['bank_account'], $v['bank_name']);
            }
        }
        if (! $v) {
            return; // this copy does not hold the personal part
        }
        $p = $e ? EmployeePrivate::where('employee_id', $e->id)->first() : null;
        if (! $p) {
            if (! array_filter($v, fn ($x) => $x !== null && $x !== 0)) {
                return; // nothing a file would keep
            }
            $this->count('personalFiles.created');
            if (! $this->dry && $e) {
                EmployeePrivate::create(['employee_id' => $e->id, 'updated_by' => 'svc.odoo'] + $v);
            }

            return;
        }
        $changes = Hr::diff($p, array_intersect_key($v, array_flip(PrivateFileService::SOURCE_OWNED)));
        $this->count($changes ? 'personalFiles.updated' : 'personalFiles.unchanged');
        if ($changes && ! $this->dry) {
            $p->update($changes + ['updated_by' => 'svc.odoo']);
        }
    }

    /**
     * An employee without a login link gets the OPS login that IS theirs: the user whose e-mail or username is the
     * work e-mail or the Odoo login. A link a person set is never replaced. With the login comes its driver record;
     * failing that, the driver that carries the same staff number.
     */
    private function linkLogin(array $d, ?Employee $e): void
    {
        if ($e?->user_id) {
            return;
        }
        $this->logins ??= $this->opsLogins();
        // an e-mail address is a person's; a bare word ("admin") is an account name two systems can both have — never an identity
        $keys = array_values(array_unique(array_filter([
            ($x = Val::text($d['work_email'] ?? null)) === null ? null : mb_strtolower($x),
            ($uid = Val::id($d['user_id'] ?? null)) ? $this->odooLogin($uid) : null,
        ], fn (?string $key) => $key !== null && str_contains($key, '@'))));
        $found = [];
        foreach ($keys as $key) {
            foreach (['email', 'username'] as $by) {
                if (isset($this->logins[$by][$key])) {
                    $found[$this->logins[$by][$key]] = true;
                }
            }
        }
        if (count($found) !== 1) {
            if (count($found) > 1) {
                $this->count('employees.loginAmbiguous'); // two different logins claim this person: a person decides
            }

            return;
        }
        $userId = (string) array_key_first($found);
        if (Employee::where('user_id', $userId)->exists()) {
            $this->count('employees.loginTaken');

            return;
        }
        $this->count('employees.loginLinked');
        if ($this->dry || ! $e) {
            return;
        }
        $driverId = $e->driver_id ?: DB::table('drivers')->where('user_id', $userId)->value('id') ?: ($e->employee_no ? DB::table('drivers')->where('employee_no', $e->employee_no)->value('id') : null);
        if ($driverId && Employee::where('driver_id', $driverId)->where('id', '!=', $e->id)->exists()) {
            $driverId = null;
        }
        $e->update(['user_id' => $userId] + ($driverId && ! $e->driver_id ? ['driver_id' => $driverId] : []));
        // the claims of this person projected before the link become theirs to see
        Claim::where('employee_id', $e->id)->whereNull('claimant_user_id')->update(['claimant_user_id' => $userId]);
        $this->audit->log($this->actor(), ['action' => 'HR.EMPLOYEE.LINK', 'entityType' => 'Employee', 'entityId' => $e->id, 'entityNumber' => $e->code, 'newValue' => ['userId' => $userId, 'driverId' => $driverId, 'by' => 'work e-mail / Odoo login']]);
    }

    private function opsLogins(): array
    {
        $out = ['email' => [], 'username' => []];
        foreach (DB::table('users')->where('username', '!=', 'svc.odoo')->get(['id', 'username', 'email']) as $u) {
            $out['username'][mb_strtolower((string) $u->username)] = (string) $u->id;
            if ($u->email) {
                $out['email'][mb_strtolower((string) $u->email)] = (string) $u->id;
            }
        }

        return $out;
    }

    private function odooLogin(int $odooUserId): ?string
    {
        if ($this->odooLogins === null) {
            $this->odooLogins = [];
            foreach (OdooRecord::where('model', 'res.users')->whereNull('deleted_at')->get(['odoo_id', 'data']) as $u) {
                if (($login = Val::text($u->data['login'] ?? null)) !== null) {
                    $this->odooLogins[$u->odoo_id] = mb_strtolower($login);
                }
            }
        }

        return $this->odooLogins[$odooUserId] ?? null;
    }

    /** "Sales Manager" out of Odoo's label of a job (the label is the name; some versions append the department). */
    private function jobName(?string $label): ?string
    {
        return $label === null ? null : mb_substr(OdooMasterData::names($label)[0], 0, 250);
    }

    // ───────────────────────────── contracts ─────────────────────────────

    private function contract(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $employeeId = $this->linked('hr_employee', $odooEmployee = Val::id($d['employee_id'] ?? null));
        if (! $employeeId && ! ($this->dry && isset($this->planned['hr_employee'][$odooEmployee ?? 0]))) {
            $this->count($odooEmployee ? 'contracts.skippedEmployeeNotCopied' : 'contracts.skippedNoEmployee', "hr.contract #{$r->odoo_id}");

            return;
        }
        $start = Val::date($d['date_start'] ?? null);
        if ($start === null) {
            $this->count('contracts.skippedNoStartDate', "hr.contract #{$r->odoo_id}");

            return;
        }
        $state = self::CONTRACT_STATE[is_string($d['state'] ?? null) ? $d['state'] : ''] ?? 'draft';
        if (! $this->alive($r) && in_array($state, ['draft', 'running'], true)) {
            $state = 'cancelled'; // archived or deleted in Odoo: it is not in force
        }
        $label = Val::label($d['contract_type_id'] ?? null);
        $values = ['employee_id' => $employeeId, 'reference' => Val::text($d['name'] ?? null), 'type' => self::contractType($label), 'type_label' => $label,
            'job_title' => $this->jobName(Val::label($d['job_id'] ?? null)), 'start_date' => $start, 'end_date' => Val::date($d['date_end'] ?? null), 'trial_end_date' => Val::date($d['trial_date_end'] ?? null),
            'wage' => round(Val::float($d['wage'] ?? 0), 2), 'housing_allowance' => round(Val::float($d['l10n_sa_housing_allowance'] ?? 0), 2),
            'transport_allowance' => round(Val::float($d['l10n_sa_transportation_allowance'] ?? 0), 2), 'other_allowances' => round(Val::float($d['l10n_sa_other_allowances'] ?? 0), 2), 'state' => $state]
            + $this->reference('department_id', 'hr_department', Val::id($d['department_id'] ?? null));
        if ($values['end_date'] !== null && $values['end_date'] < $start) {
            $values['end_date'] = null; // an end before the start is a typing slip in the source, not a term
        }
        if ($employeeId === null) {
            unset($values['employee_id']);
        }
        $row = $this->upsert('contracts', 'hr_contract', Contract::class, $r, $values, fn () => ['number' => "OD-CT-{$r->odoo_id}", 'created_by' => 'svc.odoo'], fn (Contract $x) => ['HR.CONTRACT', 'Contract', $x->number]);
        if ($row && ! $this->dry) {
            $this->alerts->refreshEmployee($row->employee_id);
        }
    }

    private static function contractType(?string $label): string
    {
        $l = mb_strtolower((string) $label);

        return match (true) {
            $l === '' => 'other',
            str_contains($l, 'permanent') || str_contains($l, 'cdi') || str_contains($l, 'دائم') || str_contains($l, 'غير محدد') => 'permanent',
            str_contains($l, 'fixed') || str_contains($l, 'cdd') || str_contains($l, 'محدد') => 'fixed_term',
            str_contains($l, 'part') || str_contains($l, 'جزئي') => 'part_time',
            str_contains($l, 'temp') || str_contains($l, 'interim') || str_contains($l, 'season') || str_contains($l, 'مؤقت') => 'temporary',
            str_contains($l, 'train') || str_contains($l, 'intern') || str_contains($l, 'apprentice') || str_contains($l, 'تدريب') => 'trainee',
            default => 'other',
        };
    }

    // ───────────────────────────── expense reports and their lines ─────────────────────────────

    private function sheet(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $existing = $this->existing('hr_claim', Claim::class, $r->odoo_id);
        if ($r->deleted_at !== null && ! $existing) {
            $this->count('claims.skippedDeleted');

            return;
        }
        $odooState = is_string($d['state'] ?? null) ? $d['state'] : 'draft';
        $state = $r->deleted_at !== null ? 'cancelled' : (self::SHEET_STATE[$odooState] ?? 'draft');
        $inOdooBooks = in_array($state, ['posted', 'paid'], true);
        $employeeId = $this->linked('hr_employee', Val::id($d['employee_id'] ?? null));
        $values = [
            'title' => Val::text($d['name'] ?? null) ?? "Odoo expense report #{$r->odoo_id}", 'employee_id' => $employeeId, 'state' => $state, 'source_state' => mb_substr($odooState, 0, 20),
            'currency' => self::currency($d['currency_id'] ?? null), 'amount' => round(Val::float($d['untaxed_amount'] ?? 0), 2), 'vat_amount' => round(Val::float($d['total_tax_amount'] ?? 0), 2),
            'total' => round(Val::float($d['total_amount'] ?? 0), 2), 'decided_at' => Val::at($d['approval_date'] ?? null)?->format('Y-m-d H:i:s.v'),
            'decided_by' => Val::at($d['approval_date'] ?? null) && ($by = Val::label($d['user_id'] ?? null)) !== null ? mb_substr($by, 0, 250) : null,
            // what Odoo posted is in Odoo's books: it is never posted here
            'posted_in' => $inOdooBooks ? 'odoo' : null, 'accounting_date' => Val::date($d['accounting_date'] ?? null),
            'posted_at' => $inOdooBooks ? Val::at($d['accounting_date'] ?? null)?->format('Y-m-d H:i:s.v') : null,
        ] + $this->reference('department_id', 'hr_department', Val::id($d['department_id'] ?? null));
        if ($employeeId === null && ($this->dry || $existing?->employee_id)) {
            unset($values['employee_id']); // not reached yet in this pass / not in the copy: what OPS holds stays
        }
        if ($employeeId && ! $existing?->claimant_user_id && ($login = Employee::whereKey($employeeId)->value('user_id'))) {
            $values['claimant_user_id'] = $login;
        }
        $this->upsertClaim('claims', 'hr_claim', $r, (string) $r->odoo_id, $values, "OD-ES-{$r->odoo_id}", $d);
    }

    private function expense(OdooRecord $r): void
    {
        $d = (array) $r->data;
        /** @var ?ClaimLine $line */
        $line = $this->existing('hr_claim_line', ClaimLine::class, $r->odoo_id);
        if ($r->deleted_at !== null) {
            $this->removeLine($r, $line);

            return;
        }
        $sheet = Val::id($d['sheet_id'] ?? null);
        $total = round(Val::float($d['total_amount'] ?? 0), 2);
        $vat = round(Val::float($d['tax_amount'] ?? 0), 2);
        $net = round($total - $vat, 2);
        $paidBy = ($d['payment_mode'] ?? '') === 'company_account' ? 'company' : 'employee';
        $claimId = $sheet ? $this->linked('hr_claim', $sheet) : $this->looseClaim($r, $d, $net, $vat, $total, $paidBy);
        $product = Val::id($d['product_id'] ?? null);
        if (! $claimId) {
            if ($this->dry && (! $sheet || isset($this->planned['hr_claim'][$sheet]))) { // its claim is one this plan would create
                $this->category($product);
                $this->count('lines.'.($line ? 'updated' : 'created'));
                $this->planned['hr_claim_line'][$r->odoo_id] = true;
            } else {
                $this->count('lines.skippedReportNotCopied', "hr.expense #{$r->odoo_id}");
            }

            return;
        }
        $category = $this->category($product);
        $values = ['claim_id' => $claimId, 'expense_date' => Val::date($d['date'] ?? null) ?? Val::date($d['create_date'] ?? null) ?? now()->toDateString(),
            'description' => Val::text($d['name'] ?? null, 500) ?? "Odoo expense #{$r->odoo_id}", 'amount' => $net, 'vat_pct' => $this->vatPct($d, $net, $vat), 'vat_amount' => $vat, 'total' => $total,
            'paid_by' => $paidBy, 'source_account' => Val::label($d['account_id'] ?? null), 'source_state' => Val::text($d['state'] ?? null, 20)]
            + ($product === null || $category !== null ? ['category_id' => $category] : []); // a category a plan would create is not a change
        $from = $line?->claim_id;
        $this->upsert('lines', 'hr_claim_line', ClaimLine::class, $r, $values, fn () => ['line_no' => (int) ClaimLine::where('claim_id', $claimId)->max('line_no') + 1]);
        if ($this->dry) {
            return;
        }
        $this->retotal($claimId, ! $sheet);
        if ($from && $from !== $claimId) { // the expense was put on a report (or moved to another): the claim it left is brought in step
            $this->retotal($from, false);
            $this->dropEmptyLoose($from);
        }
    }

    /**
     * The claim of an expense that is on no report: a draft claim of its own (one line), so nothing of what people
     * entered in Odoo is lost and the employee can carry on from it after the hand-over.
     */
    private function looseClaim(OdooRecord $r, array $d, float $net, float $vat, float $total, string $paidBy): ?string
    {
        $odooState = is_string($d['state'] ?? null) ? $d['state'] : 'draft';
        $employeeId = $this->linked('hr_employee', Val::id($d['employee_id'] ?? null));
        $values = ['title' => Val::text($d['name'] ?? null) ?? "Odoo expense #{$r->odoo_id}", 'employee_id' => $employeeId, 'state' => self::EXPENSE_STATE[$odooState] ?? 'draft', 'source_state' => mb_substr($odooState, 0, 20),
            'currency' => self::currency($d['currency_id'] ?? null), 'amount' => $net, 'vat_amount' => $vat, 'total' => $total];
        $existing = $this->existing('hr_claim_loose', Claim::class, $r->odoo_id, "expense:{$r->odoo_id}");
        if ($employeeId === null && ($this->dry || $existing?->employee_id)) {
            unset($values['employee_id']);
        }
        if ($employeeId && ! $existing?->claimant_user_id && ($login = Employee::whereKey($employeeId)->value('user_id'))) {
            $values['claimant_user_id'] = $login;
        }

        return $this->upsertClaim('claims.fromLooseExpense', 'hr_claim_loose', $r, "expense:{$r->odoo_id}", $values, "OD-EX-{$r->odoo_id}", $d)?->id;
    }

    private function upsertClaim(string $what, string $entity, OdooRecord $r, string $ref, array $values, string $number, array $d): ?Claim
    {
        /** @var ?Claim $claim */
        $claim = $this->upsert($what, $entity, Claim::class, $r, $values, fn () => ['number' => $number, 'created_by' => 'svc.odoo',
            'created_at' => (Val::at($d['create_date'] ?? null) ?? now())->format('Y-m-d H:i:s.v')], fn (Claim $x) => ['CLAIM', 'ExpenseClaim', $x->number], $ref);

        return $claim;
    }

    /** An expense deleted in Odoo takes its line with it (only a draft can be deleted there); its receipts are taken off. */
    private function removeLine(OdooRecord $r, ?ClaimLine $line): void
    {
        if (! $line) {
            return;
        }
        $this->count('lines.removed');
        if ($this->dry) {
            return;
        }
        $claimId = $line->claim_id;
        HrFile::where('owner_type', 'claim_line')->where('owner_id', $line->id)->whereNull('removed_at')->update(['removed_at' => now(), 'removed_by' => 'svc.odoo']);
        $line->delete();
        $this->refs->unlink(self::SYSTEM, 'hr_claim_line', (string) $r->odoo_id);
        $this->retotal($claimId, false);
        $this->dropEmptyLoose($claimId);
    }

    /** The wrapper claim of a loose expense that no longer holds a line was only ever a place for that line. */
    private function dropEmptyLoose(string $claimId): void
    {
        $claim = Claim::whereKey($claimId)->where('source_system', Source::ODOO)->where('external_ref', 'like', 'expense:%')->first();
        if ($claim && ! ClaimLine::where('claim_id', $claim->id)->exists()) {
            $this->refs->unlink(self::SYSTEM, 'hr_claim_loose', substr((string) $claim->external_ref, 8));
            $claim->delete();
        }
    }

    /** What the employee is owed and what the company paid are the sums of the lines; a loose claim's totals too. */
    private function retotal(string $claimId, bool $all): void
    {
        $s = ClaimLine::where('claim_id', $claimId)->selectRaw("coalesce(sum(amount), 0) as amount, coalesce(sum(vat_amount), 0) as vat, coalesce(sum(total), 0) as total,
            coalesce(sum(case when paid_by = 'company' then total else 0 end), 0) as company")->first();
        Claim::whereKey($claimId)->update(['company_paid' => round((float) $s->company, 2), 'employee_due' => round((float) $s->total - (float) $s->company, 2)]
            + ($all ? ['amount' => round((float) $s->amount, 2), 'vat_amount' => round((float) $s->vat, 2), 'total' => round((float) $s->total, 2)] : []));
    }

    /** The OPS category of an Odoo expense product: linked, or the OPS category with exactly that code, or a new one. */
    private function category(?int $productId): ?string
    {
        if (! $productId) {
            return null;
        }
        if (array_key_exists($productId, $this->categories)) {
            return $this->categories[$productId];
        }
        if ($id = $this->linked('hr_expense_category', $productId)) {
            return $this->categories[$productId] = $id;
        }
        $product = (array) ($this->mirrored('product.product', $productId)?->data ?? []);
        $template = ($tid = Val::id($product['product_tmpl_id'] ?? null)) ? (array) ($this->mirrored('product.template', $tid)?->data ?? []) : [];
        [$ar, $en] = OdooMasterData::names(Val::text($template['name'] ?? null) ?? Val::text($product['name'] ?? null) ?? "Odoo expense product #{$productId}");
        $code = trim((string) preg_replace('/[^A-Z0-9.\-]+/', '_', mb_strtoupper((string) (Val::text($product['default_code'] ?? null, 40) ?? Val::text($template['default_code'] ?? null, 40)))), '_');
        $same = $code !== '' ? ExpenseCategory::where('code', $code)->first() : null;
        if ($same && ($same->source_system !== null || $this->refs->externalId(self::SYSTEM, 'hr_expense_category', $same->id) !== null)) {
            $same = null; // that code already stands for another product
            $code = '';
        }
        if ($same) { // the same code is the same category: OPS keeps its own row, its name and its account
            $this->count('categories.linked');
            if ($this->dry) {
                return $this->categories[$productId] = $same->id;
            }
            $this->link('hr_expense_category', $productId, $same->id, $same->code);

            return $this->categories[$productId] = $same->id;
        }
        $this->count('categories.created');
        if ($this->dry) {
            return $this->categories[$productId] = null;
        }
        $c = ExpenseCategory::create(['code' => $code !== '' ? $code : "OD-{$productId}", 'name_ar' => $ar, 'name_en' => $en ?: $ar, 'account_code' => null, 'vat_pct' => 0, 'active' => true,
            'source_system' => Source::ODOO, 'external_ref' => (string) $productId, 'created_by' => 'svc.odoo']);
        $this->link('hr_expense_category', $productId, $c->id, $c->code);

        return $this->categories[$productId] = $c->id;
    }

    /** The VAT percentage of an expense: what its taxes state, otherwise what its own figures say. */
    private function vatPct(array $d, float $net, float $vat): float
    {
        $pct = 0.0;
        foreach (Val::ids($d['tax_ids'] ?? null) as $taxId) {
            if (! array_key_exists($taxId, $this->taxes)) {
                $tax = (array) ($this->mirrored('account.tax', $taxId)?->data ?? []);
                $this->taxes[$taxId] = ($tax['amount_type'] ?? 'percent') === 'percent' ? Val::float($tax['amount'] ?? 0) : 0.0;
            }
            $pct += $this->taxes[$taxId];
        }

        return $pct > 0 ? round($pct, 4) : ($net > 0 && $vat > 0 ? round($vat / $net * 100, 2) : 0.0);
    }

    private static function currency(mixed $m2o): string
    {
        $label = mb_strtoupper((string) Val::label($m2o));

        return preg_match('/^[A-Z]{3}$/', $label) ? $label : 'SAR';
    }

    // ───────────────────────────── attachments ─────────────────────────────

    /** A file attached in Odoo to an employee, a contract, an expense or a report. Everything else in `ir.attachment` is not this area's. */
    private function attachment(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $owner = self::FILE_OWNERS[is_string($d['res_model'] ?? null) ? $d['res_model'] : ''] ?? null;
        if ($owner === null || ! empty($d['res_field'])) {
            return; // another area's file, or the picture of a field: not counted — the pass would read like noise
        }
        /** @var ?HrFile $file */
        $file = $this->existing('hr_file', HrFile::class, $r->odoo_id);
        if ($r->deleted_at !== null) {
            if ($file && ! $file->removed_at) {
                $this->count('files.removed');
                if (! $this->dry) {
                    $file->update(['removed_at' => now(), 'removed_by' => 'svc.odoo']);
                }
            }

            return;
        }
        if (($d['type'] ?? 'binary') !== 'binary') {
            $this->count('files.skippedLink');

            return;
        }
        $resId = Val::id($d['res_id'] ?? null);
        $ownerId = $this->linked($owner[1], $resId);
        if (! $ownerId) {
            $this->count($this->dry && isset($this->planned[$owner[1]][$resId ?? 0]) ? 'files.created' : 'files.skippedOwnerNotCopied');

            return;
        }
        // the name of a file can say whose paper it is: it is stored for the people who may open the file, and written nowhere else
        $values = ['owner_type' => $owner[0], 'owner_id' => $ownerId, 'name' => Val::text($d['name'] ?? null) ?? "attachment-{$r->odoo_id}", 'mime' => Val::text($d['mimetype'] ?? null, 150), 'size' => Val::int($d['file_size'] ?? 0)];
        $this->upsert('files', 'hr_file', HrFile::class, $r, $values, fn () => ['storage' => 'odoo', 'created_by' => 'svc.odoo']);
    }

    // ───────────────────────────── the machinery ─────────────────────────────

    /**
     * Creates or refreshes the OPS row of one Odoo record and counts what happened as `<what>.created | updated |
     * unchanged`. A plan decides and counts without writing.
     *
     * @param  class-string<Model>  $class
     * @param  ?callable  $onCreate  values only a new row gets (its number, its code)
     * @param  ?callable  $trail  fn (row) => [action prefix, entity type, entity number] — one audit line per created or changed row
     */
    private function upsert(string $what, string $entity, string $class, OdooRecord $r, array $values, ?callable $onCreate = null, ?callable $trail = null, ?string $ref = null): ?Model
    {
        $ref ??= (string) $r->odoo_id;
        $row = $this->existing($entity, $class, $r->odoo_id, $ref);
        if (! $row) {
            $this->count("{$what}.created");
            if ($this->dry) {
                $this->planned[$entity][$r->odoo_id] = true;

                return null;
            }
            $row = $class::create($values + ($onCreate ? $onCreate() : []) + ['source_system' => Source::ODOO, 'external_ref' => $ref]);
            $this->link($entity, $r->odoo_id, $row->getKey(), $row->getAttribute('code') ?? $row->getAttribute('number'));
            if ($trail) {
                [$action, $type, $number] = $trail($row);
                $this->audit->log($this->actor(), ['action' => "{$action}.IMPORT", 'entityType' => $type, 'entityId' => $row->getKey(), 'entityNumber' => $number, 'newValue' => ['origin' => "Odoo {$r->model} #{$r->odoo_id}"]]);
            }

            return $row;
        }
        $changes = Hr::diff($row, $values, self::MONEY);
        $this->count($changes ? "{$what}.updated" : "{$what}.unchanged");
        if ($changes && ! $this->dry) {
            $row->update($changes);
            if ($trail) {
                [$action, $type, $number] = $trail($row);
                // which fields Odoo changed — never their values: the trail is read by people who may not read the file
                $this->audit->log($this->actor(), ['action' => "{$action}.SYNC", 'entityType' => $type, 'entityId' => $row->getKey(), 'entityNumber' => $number, 'newValue' => ['fields' => array_keys($changes), 'origin' => 'Odoo']]);
            }
        }

        return $row;
    }

    /** The OPS row of an Odoo record: by its link, or — a link that was lost — by what the row itself says. */
    private function existing(string $entity, string $class, int $odooId, ?string $ref = null): ?Model
    {
        $id = $this->linked($entity, $odooId);
        $row = $id ? $class::find($id) : null;
        if (! $row) {
            $row = $class::where('source_system', Source::ODOO)->where('external_ref', $ref ?? (string) $odooId)->first();
            if ($row && ! $this->dry) {
                $this->refs->unlink(self::SYSTEM, $entity, (string) $odooId);
                $this->link($entity, $odooId, $row->getKey());
            }
        }

        return $row;
    }

    /**
     * The value of a column that points at another projected record: `[column => id]` when the target is known here,
     * `[column => null]` when Odoo names none (or the record points at itself), and NOTHING when Odoo names one that
     * this pass has not reached yet — it is set the moment that record arrives, and what OPS holds stays until then.
     */
    private function reference(string $column, string $entity, ?int $odooId, ?int $self = null): array
    {
        if ($odooId === null || $odooId === $self) {
            return [$column => null];
        }
        $id = $this->linked($entity, $odooId);

        return $id ? [$column => $id] : [];
    }

    /** The Odoo ids of the records of `$model` whose `$field` points at `$odooId` (the small models are read once per pass). @return int[] */
    private function pointingAt(string $model, string $field, int $odooId): array
    {
        if (! isset($this->pointing[$model])) {
            $this->pointing[$model] = [];
            foreach (OdooRecord::where('model', $model)->whereNull('deleted_at')->get(['odoo_id', 'data']) as $x) {
                foreach (['parent_id', 'manager_id'] as $f) {
                    if ($target = Val::id($x->data[$f] ?? null)) {
                        $this->pointing[$model][$f][$target][] = $x->odoo_id;
                    }
                }
            }
        }

        return $this->pointing[$model][$field][$odooId] ?? [];
    }
}
