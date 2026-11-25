<?php

namespace App\Hr\Services;

use App\Hr\Models\Department;
use App\Hr\Models\Employee;
use App\Hr\Models\Job;
use App\Hr\Support\Hr;
use App\Hr\Support\Source;
use App\Models\Driver;
use App\Models\User;
use App\Services\Core\AuditService;
use App\Services\Core\NumberingService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The staff directory: employees (their WORK part only — the personal file is PrivateFileService's), departments as a
 * tree, job positions. Reading needs `page.hr`, writing `hr.manage` (the routes say so); a row that came from Odoo
 * keeps Odoo's values while the section follows Odoo and takes only what is OPS's own: the link to an OPS login and
 * to a driver record, and notes.
 */
class DirectoryService
{
    /** API field → column of the work part a person may write. */
    private const EMPLOYEE_FIELDS = ['nameAr' => 'name_ar', 'nameEn' => 'name_en', 'employeeNo' => 'employee_no', 'jobTitle' => 'job_title', 'workEmail' => 'work_email',
        'workPhone' => 'work_phone', 'workMobile' => 'work_mobile', 'workLocation' => 'work_location', 'status' => 'status', 'notes' => 'notes'];

    /** What Odoo holds of an employee's work part (App\Hr\OdooHrProjection writes exactly these). */
    public const EMPLOYEE_SOURCE_OWNED = ['name_ar', 'name_en', 'employee_no', 'department_id', 'job_id', 'job_title', 'manager_id', 'work_email', 'work_phone', 'work_mobile',
        'work_location', 'start_date', 'status'];

    public const DEPARTMENT_SOURCE_OWNED = ['name_ar', 'name_en', 'parent_id', 'manager_id', 'active'];

    public const JOB_SOURCE_OWNED = ['name_ar', 'name_en', 'department_id', 'description', 'active'];

    public function __construct(private readonly AuditService $audit, private readonly NumberingService $numbering) {}

    // ───────────────────────────── employees ─────────────────────────────

    /** @param  array{status?:?string, department?:?string, sub?:?string, job?:?string, manager?:?string, source?:?string, linked?:?string, order?:?string}  $f */
    public function employees(Paging $page, array $f): array
    {
        $q = Employee::query()->with(['department:id,name_ar,name_en', 'job:id,name_ar,name_en', 'manager:id,code,name_ar,name_en']);
        $status = $f['status'] ?? 'active';
        if ($status !== 'all') {
            $q->whereIn('status', array_intersect(explode(',', (string) $status), Employee::STATUSES) ?: ['active']);
        }
        if (! empty($f['department'])) {
            $f['department'] === 'none' ? $q->whereNull('department_id') : $q->whereIn('department_id', ! empty($f['sub']) ? $this->withDescendants((string) $f['department']) : [(string) $f['department']]);
        }
        if (! empty($f['job'])) {
            $q->where('job_id', $f['job']);
        }
        if (! empty($f['manager'])) {
            $q->where('manager_id', $f['manager']);
        }
        if (! empty($f['source'])) {
            $f['source'] === Source::ODOO ? $q->where('source_system', Source::ODOO) : $q->whereNull('source_system');
        }
        if (! empty($f['linked'])) {
            $f['linked'] === 'no' ? $q->whereNull('user_id') : $q->whereNotNull('user_id');
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('code', 'like', $like)->orWhere('name_ar', 'like', $like)->orWhere('name_en', 'like', $like)->orWhere('work_email', 'like', $like)
                ->orWhere('job_title', 'like', $like)->orWhere('employee_no', 'like', $like)->orWhere('work_phone', 'like', $like)->orWhere('work_mobile', 'like', $like));
        }
        $column = ['name' => 'name_ar', 'nameEn' => 'name_en', 'code' => 'code', 'startDate' => 'start_date', 'status' => 'status'][$page->sort ?? 'name'] ?? 'name_ar';
        $order = ($f['order'] ?? null) === 'desc' ? 'desc' : 'asc'; // a directory reads A → Z unless asked otherwise
        $q->orderBy($column, $order)->orderBy('id', $order);
        $total = (clone $q)->toBase()->getCountForPagination();
        $rows = $q->forPage($page->page, $page->pageSize)->get();
        $links = $this->links($rows->all());

        return $page->wrap($rows->map(fn (Employee $e) => $this->shape($e, $links))->all(), $total);
    }

    public function find(string $idOrCode): Employee
    {
        return Employee::where('id', $idOrCode)->orWhere('code', $idOrCode)->first()
            ?? throw AppError::notFound('EMPLOYEE_NOT_FOUND', 'الموظف غير موجود', 'Employee not found');
    }

    /** One employee's work part with the people around them: who they report to, who reports to them, what they manage. */
    public function employee(string $idOrCode): array
    {
        $e = $this->find($idOrCode)->load(['department:id,name_ar,name_en', 'job:id,name_ar,name_en', 'manager:id,code,name_ar,name_en']);
        $brief = fn (Employee $x) => ['id' => $x->id, 'code' => $x->code, 'nameAr' => $x->name_ar, 'nameEn' => $x->name_en, 'jobTitle' => $x->job_title, 'status' => $x->status];

        return $this->shape($e, $this->links([$e])) + [
            'notes' => $e->notes,
            'reports' => Employee::where('manager_id', $e->id)->where('status', 'active')->orderBy('name_ar')->get(['id', 'code', 'name_ar', 'name_en', 'job_title', 'status'])->map($brief)->all(),
            'manages' => Department::where('manager_id', $e->id)->where('active', true)->orderBy('name_ar')->get(['id', 'name_ar', 'name_en'])->map(fn (Department $d) => self::named($d))->all(),
            'ownedFields' => Source::locked($e->source_system) ? array_map(fn (string $c) => Str::camel($c), self::EMPLOYEE_SOURCE_OWNED) : [],
            'createdBy' => $e->created_by, 'createdAt' => Hr::at($e->created_at), 'updatedAt' => Hr::at($e->updated_at),
        ];
    }

    public function createEmployee(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            $values = $this->employeeValues($dto, null) + ['status' => 'active'];
            $values['name_en'] = $values['name_en'] ?? $values['name_ar'];
            $e = Employee::create(['code' => $this->numbering->next('EMP'), 'created_by' => $user->username] + $values);
            if ($e->user_id && ! $e->driver_id) {
                $this->adoptDriver($e);
            }
            // names and work details are directory data; the trail still keeps to what identifies the record
            $this->audit->log($user, ['action' => 'HR.EMPLOYEE.CREATE', 'entityType' => 'Employee', 'entityId' => $e->id, 'entityNumber' => $e->code, 'newValue' => ['fields' => array_keys($values)]]);
            $this->audit->status($user, 'Employee', $e->id, $e->code, null, $e->status);

            return $this->employee($e->id);
        });
    }

    public function updateEmployee(AuthUser $user, string $idOrCode, array $dto): array
    {
        return DB::transaction(function () use ($user, $idOrCode, $dto) {
            $e = Employee::where('id', $idOrCode)->orWhere('code', $idOrCode)->lockForUpdate()->first() ?? throw AppError::notFound('EMPLOYEE_NOT_FOUND', 'الموظف غير موجود', 'Employee not found');
            $changes = Hr::diff($e, $this->employeeValues($dto, $e));
            if (array_key_exists('name_en', $changes) && $changes['name_en'] === null) {
                $changes['name_en'] = $changes['name_ar'] ?? $e->name_ar;
            }
            Hr::guardSource($e, $changes, self::EMPLOYEE_SOURCE_OWNED, 'employee', "الموظف {$e->code}", "Employee {$e->code}");
            if (! $changes) {
                return $this->employee($e->id);
            }
            $from = $e->status;
            $e->update($changes);
            if (array_key_exists('user_id', $changes) && $e->user_id && ! $e->driver_id) {
                $this->adoptDriver($e);
            }
            $this->audit->log($user, ['action' => 'HR.EMPLOYEE.UPDATE', 'entityType' => 'Employee', 'entityId' => $e->id, 'entityNumber' => $e->code, 'newValue' => ['fields' => array_keys($changes)]]);
            if (isset($changes['status'])) {
                $this->audit->status($user, 'Employee', $e->id, $e->code, $from, $e->status);
                app(AlertsService::class)->refreshEmployee($e->id); // a person who left has nothing to renew
            }

            return $this->employee($e->id);
        });
    }

    /** Column values of the work part from a request; only the keys that were sent. Checks every reference it is given. */
    private function employeeValues(array $dto, ?Employee $e): array
    {
        $v = [];
        foreach (self::EMPLOYEE_FIELDS as $field => $column) {
            if (array_key_exists($field, $dto)) {
                $v[$column] = Hr::text($dto[$field], $column === 'notes' ? 2000 : 250);
            }
        }
        if (array_key_exists('name_ar', $v) && $v['name_ar'] === null) {
            throw AppError::validation('EMPLOYEE_NAME', 'اسم الموظف مطلوب', 'The employee name is required');
        }
        if (array_key_exists('status', $v) && ! in_array($v['status'], Employee::STATUSES, true)) {
            unset($v['status']);
        }
        if (isset($v['work_email'])) {
            $v['work_email'] = mb_strtolower($v['work_email']);
            if (Employee::where('work_email', $v['work_email'])->when($e, fn ($q) => $q->where('id', '!=', $e->id))->where('status', 'active')->exists()) {
                throw AppError::conflict('EMPLOYEE_EMAIL_TAKEN', 'بريد العمل مسجَّل لموظف آخر', 'This work e-mail belongs to another employee');
            }
        }
        if (array_key_exists('startDate', $dto)) {
            $v['start_date'] = Hr::date($dto['startDate']);
        }
        if (array_key_exists('departmentId', $dto)) {
            $v['department_id'] = $dto['departmentId'] ? $this->department((string) $dto['departmentId'])->id : null;
        }
        if (array_key_exists('jobId', $dto)) {
            $job = $dto['jobId'] ? $this->job((string) $dto['jobId']) : null;
            $v['job_id'] = $job?->id;
            // the title printed beside the name follows the position unless a person wrote another
            if ($job && (array_key_exists('job_title', $v) ? $v['job_title'] === null : ! $e?->job_title)) {
                $v['job_title'] = $job->name_ar;
            }
        }
        if (array_key_exists('managerId', $dto)) {
            $manager = $dto['managerId'] ? $this->find((string) $dto['managerId']) : null;
            if ($manager && $e) {
                $this->assertNoManagerCycle($e, $manager);
            }
            $v['manager_id'] = $manager?->id;
        }
        if (array_key_exists('userId', $dto)) {
            $login = $dto['userId'] ? (User::where('id', $dto['userId'])->orWhere('username', $dto['userId'])->first() ?? throw AppError::notFound('USER_NOT_FOUND', 'المستخدم غير موجود', 'User not found')) : null;
            if ($login && Employee::where('user_id', $login->id)->when($e, fn ($q) => $q->where('id', '!=', $e->id))->exists()) {
                throw AppError::conflict('EMPLOYEE_USER_TAKEN', "المستخدم {$login->username} مرتبط بموظف آخر", "The login {$login->username} is linked to another employee");
            }
            $v['user_id'] = $login?->id;
        }
        if (array_key_exists('driverId', $dto)) {
            $driver = $dto['driverId'] ? (Driver::where('id', $dto['driverId'])->orWhere('code', $dto['driverId'])->first() ?? throw AppError::notFound('DRIVER_NOT_FOUND', 'السائق غير موجود', 'Driver not found')) : null;
            if ($driver && Employee::where('driver_id', $driver->id)->when($e, fn ($q) => $q->where('id', '!=', $e->id))->exists()) {
                throw AppError::conflict('EMPLOYEE_DRIVER_TAKEN', "سجل السائق {$driver->code} مرتبط بموظف آخر", "The driver record {$driver->code} is linked to another employee");
            }
            $v['driver_id'] = $driver?->id;
        }

        return $v;
    }

    /** A person cannot report — directly or through others — to someone who reports to them. */
    private function assertNoManagerCycle(Employee $e, Employee $manager): void
    {
        $seen = [];
        for ($x = $manager; $x && ! isset($seen[$x->id]); $x = $x->manager_id ? Employee::find($x->manager_id) : null) {
            if ($x->id === $e->id) {
                throw AppError::rule('EMPLOYEE_MANAGER_CYCLE', "لا يكون {$manager->code} مديرًا لـ {$e->code}: هو يتبعه في التسلسل الإداري", "{$manager->code} cannot manage {$e->code}: {$manager->code} reports to {$e->code}");
            }
            $seen[$x->id] = true;
        }
    }

    /** The driver record of the login a person was just linked to becomes their driver record too. */
    private function adoptDriver(Employee $e): void
    {
        $driverId = Driver::where('user_id', $e->user_id)->value('id');
        if ($driverId && ! Employee::where('driver_id', $driverId)->exists()) {
            $e->update(['driver_id' => $driverId]);
        }
    }

    /** Logins and driver records of a page of employees, read once. @param Employee[] $rows */
    private function links(array $rows): array
    {
        $userIds = array_values(array_filter(array_map(fn (Employee $e) => $e->user_id, $rows)));
        $driverIds = array_values(array_filter(array_map(fn (Employee $e) => $e->driver_id, $rows)));

        return [
            'users' => $userIds ? User::whereIn('id', $userIds)->get(['id', 'username', 'active'])->keyBy('id') : collect(),
            'drivers' => $driverIds ? Driver::whereIn('id', $driverIds)->get(['id', 'code', 'state'])->keyBy('id') : collect(),
        ];
    }

    /** The work part as the API returns it. Nothing of the personal file can be here: it is not on this model. */
    private function shape(Employee $e, array $links): array
    {
        $login = $e->user_id ? ($links['users'][$e->user_id] ?? null) : null;
        $driver = $e->driver_id ? ($links['drivers'][$e->driver_id] ?? null) : null;

        return [
            'id' => $e->id, 'code' => $e->code, 'employeeNo' => $e->employee_no, 'nameAr' => $e->name_ar, 'nameEn' => $e->name_en, 'jobTitle' => $e->job_title,
            'job' => $e->job ? self::named($e->job) : null, 'department' => $e->department ? self::named($e->department) : null,
            'manager' => $e->manager ? ['id' => $e->manager->id, 'code' => $e->manager->code, 'nameAr' => $e->manager->name_ar, 'nameEn' => $e->manager->name_en] : null,
            'workEmail' => $e->work_email, 'workPhone' => $e->work_phone, 'workMobile' => $e->work_mobile, 'workLocation' => $e->work_location,
            'startDate' => Hr::day($e->start_date), 'status' => $e->status,
            'user' => $login ? ['id' => $login->id, 'username' => $login->username, 'active' => (bool) $login->active] : null,
            'driver' => $driver ? ['id' => $driver->id, 'code' => $driver->code, 'state' => $driver->state] : null,
            'source' => $e->source_system, 'externalRef' => $e->external_ref, 'locked' => Source::locked($e->source_system),
        ];
    }

    private static function named(Department|Job $x): array
    {
        return ['id' => $x->id, 'nameAr' => $x->name_ar, 'nameEn' => $x->name_en];
    }

    // ───────────────────────────── departments ─────────────────────────────

    public function department(string $id): Department
    {
        return Department::find($id) ?? throw AppError::notFound('DEPARTMENT_NOT_FOUND', 'القسم غير موجود', 'Department not found');
    }

    /** Every department with its place in the tree, its manager and how many people work in it (and under it). */
    public function departments(bool $withArchived = false): array
    {
        $all = Department::with('manager:id,code,name_ar,name_en')->when(! $withArchived, fn ($q) => $q->where('active', true))->orderBy('name_ar')->orderBy('id')->get();
        $direct = Employee::where('status', 'active')->whereNotNull('department_id')->selectRaw('department_id, count(*) as n')->groupBy('department_id')->pluck('n', 'department_id');
        $children = [];
        foreach ($all as $d) {
            $children[$d->parent_id ?? ''][] = $d;
        }
        $known = $all->keyBy('id');
        $out = [];
        $walk = function (Department $d, int $depth, array $trail) use (&$walk, &$out, $children, $direct): int {
            $at = count($out);
            $out[] = null;
            $total = (int) ($direct[$d->id] ?? 0);
            foreach (isset($trail[$d->id]) ? [] : ($children[$d->id] ?? []) as $child) { // a loop in the data never becomes a loop here
                $total += $walk($child, $depth + 1, $trail + [$d->id => true]);
            }
            $out[$at] = [
                'id' => $d->id, 'nameAr' => $d->name_ar, 'nameEn' => $d->name_en, 'parentId' => $d->parent_id, 'depth' => $depth,
                'manager' => $d->manager ? ['id' => $d->manager->id, 'code' => $d->manager->code, 'nameAr' => $d->manager->name_ar, 'nameEn' => $d->manager->name_en] : null,
                'headcount' => (int) ($direct[$d->id] ?? 0), 'total' => $total, 'notes' => $d->notes, 'active' => (bool) $d->active,
                'source' => $d->source_system, 'locked' => Source::locked($d->source_system),
            ];

            return $total;
        };
        foreach ($all as $d) {
            if (! $d->parent_id || ! isset($known[$d->parent_id])) { // a root, or a child whose parent is archived / missing: shown at the top
                $walk($d, 0, []);
            }
        }

        return ['items' => array_values(array_filter($out)), 'unassigned' => Employee::where('status', 'active')->whereNull('department_id')->count()];
    }

    public function createDepartment(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            $values = $this->departmentValues($dto, null);
            $values['name_en'] = $values['name_en'] ?? $values['name_ar'];
            $d = Department::create($values + ['active' => true, 'created_by' => $user->username]);
            $this->audit->log($user, ['action' => 'HR.DEPARTMENT.CREATE', 'entityType' => 'Department', 'entityId' => $d->id, 'entityNumber' => $d->name_ar, 'newValue' => ['nameAr' => $d->name_ar, 'parent' => $d->parent_id]]);

            return $this->departmentRow($d->id);
        });
    }

    public function updateDepartment(AuthUser $user, string $id, array $dto): array
    {
        return DB::transaction(function () use ($user, $id, $dto) {
            $d = Department::whereKey($id)->lockForUpdate()->first() ?? throw AppError::notFound('DEPARTMENT_NOT_FOUND', 'القسم غير موجود', 'Department not found');
            $changes = Hr::diff($d, $this->departmentValues($dto, $d));
            Hr::guardSource($d, $changes, self::DEPARTMENT_SOURCE_OWNED, 'department', "القسم «{$d->name_ar}»", "Department '{$d->name_en}'");
            if (($changes['active'] ?? null) === false) {
                $people = Employee::where('department_id', $d->id)->where('status', 'active')->count();
                $subs = Department::where('parent_id', $d->id)->where('active', true)->count();
                if ($people || $subs) {
                    throw AppError::rule('DEPARTMENT_IN_USE', "لا يُؤرشف القسم «{$d->name_ar}»: فيه {$people} موظفًا و{$subs} قسمًا فرعيًا — انقلهم أولًا", "Department '{$d->name_en}' still has {$people} employee(s) and {$subs} sub-department(s) — move them first", ['employees' => $people, 'departments' => $subs]);
                }
            }
            if ($changes) {
                $old = array_intersect_key($d->getAttributes(), $changes);
                $d->update($changes);
                $this->audit->log($user, ['action' => 'HR.DEPARTMENT.UPDATE', 'entityType' => 'Department', 'entityId' => $d->id, 'entityNumber' => $d->name_ar, 'oldValue' => $old, 'newValue' => $changes]);
            }

            return $this->departmentRow($d->id);
        });
    }

    private function departmentValues(array $dto, ?Department $d): array
    {
        $v = [];
        foreach (['nameAr' => 'name_ar', 'nameEn' => 'name_en', 'notes' => 'notes'] as $field => $column) {
            if (array_key_exists($field, $dto)) {
                $v[$column] = Hr::text($dto[$field], $column === 'notes' ? 2000 : 250);
            }
        }
        if (array_key_exists('name_ar', $v) && $v['name_ar'] === null) {
            throw AppError::validation('DEPARTMENT_NAME', 'اسم القسم مطلوب', 'The department name is required');
        }
        if (array_key_exists('name_en', $v) && $v['name_en'] === null && $d) {
            $v['name_en'] = $v['name_ar'] ?? $d->name_ar;
        }
        if (array_key_exists('active', $dto) && $dto['active'] !== null) {
            $v['active'] = filter_var($dto['active'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('parentId', $dto)) {
            $parent = $dto['parentId'] ? $this->department((string) $dto['parentId']) : null;
            if ($parent && $d && in_array($parent->id, $this->withDescendants($d->id), true)) {
                throw AppError::rule('DEPARTMENT_CYCLE', "لا يكون «{$parent->name_ar}» القسم الأعلى لـ «{$d->name_ar}»: هو نفسه أو تابع له", "'{$parent->name_en}' cannot be the parent of '{$d->name_en}': it is the same department or one below it");
            }
            $v['parent_id'] = $parent?->id;
        }
        if (array_key_exists('managerId', $dto)) {
            $v['manager_id'] = $dto['managerId'] ? $this->find((string) $dto['managerId'])->id : null;
        }

        return $v;
    }

    private function departmentRow(string $id): array
    {
        return collect($this->departments(true)['items'])->firstWhere('id', $id) ?? throw AppError::notFound('DEPARTMENT_NOT_FOUND', 'القسم غير موجود', 'Department not found');
    }

    /** The department and every department under it. @return string[] */
    private function withDescendants(string $id): array
    {
        $children = [];
        foreach (Department::get(['id', 'parent_id']) as $d) {
            $children[$d->parent_id ?? ''][] = $d->id;
        }
        $out = [];
        for ($queue = [$id]; $queue;) {
            $x = array_shift($queue);
            if (! isset($out[$x])) {
                $out[$x] = true;
                array_push($queue, ...($children[$x] ?? []));
            }
        }

        return array_keys($out);
    }

    // ───────────────────────────── jobs ─────────────────────────────

    public function job(string $id): Job
    {
        return Job::find($id) ?? throw AppError::notFound('JOB_NOT_FOUND', 'المسمى الوظيفي غير موجود', 'Job position not found');
    }

    public function jobs(Paging $page, array $f): array
    {
        $q = Job::query()->with('department:id,name_ar,name_en');
        if (empty($f['archived'])) {
            $q->where('active', true);
        }
        if (! empty($f['department'])) {
            $q->where('department_id', $f['department']);
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('name_ar', 'like', $like)->orWhere('name_en', 'like', $like));
        }
        $q->orderBy('name_ar')->orderBy('id');
        $total = (clone $q)->toBase()->getCountForPagination();
        $rows = $q->forPage($page->page, $page->pageSize)->get();
        $counts = $rows->isEmpty() ? collect() : Employee::where('status', 'active')->whereIn('job_id', $rows->pluck('id'))->selectRaw('job_id, count(*) as n')->groupBy('job_id')->pluck('n', 'job_id');

        return $page->wrap($rows->map(fn (Job $j) => $this->jobShape($j, (int) ($counts[$j->id] ?? 0)))->all(), $total);
    }

    public function createJob(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            $values = $this->jobValues($dto, null);
            $values['name_en'] = $values['name_en'] ?? $values['name_ar'];
            $j = Job::create($values + ['active' => true, 'created_by' => $user->username]);
            $this->audit->log($user, ['action' => 'HR.JOB.CREATE', 'entityType' => 'Job', 'entityId' => $j->id, 'entityNumber' => $j->name_ar, 'newValue' => ['nameAr' => $j->name_ar]]);

            return $this->jobShape($j->load('department:id,name_ar,name_en'), 0);
        });
    }

    public function updateJob(AuthUser $user, string $id, array $dto): array
    {
        return DB::transaction(function () use ($user, $id, $dto) {
            $j = Job::whereKey($id)->lockForUpdate()->first() ?? throw AppError::notFound('JOB_NOT_FOUND', 'المسمى الوظيفي غير موجود', 'Job position not found');
            $changes = Hr::diff($j, $this->jobValues($dto, $j));
            Hr::guardSource($j, $changes, self::JOB_SOURCE_OWNED, 'job', "المسمى «{$j->name_ar}»", "Job '{$j->name_en}'");
            $holders = Employee::where('job_id', $j->id)->where('status', 'active')->count();
            if (($changes['active'] ?? null) === false && $holders) {
                throw AppError::rule('JOB_IN_USE', "لا يُؤرشف المسمى «{$j->name_ar}»: يشغله {$holders} موظفًا", "Job '{$j->name_en}' is held by {$holders} employee(s)", ['employees' => $holders]);
            }
            if ($changes) {
                $old = array_intersect_key($j->getAttributes(), $changes);
                $j->update($changes);
                $this->audit->log($user, ['action' => 'HR.JOB.UPDATE', 'entityType' => 'Job', 'entityId' => $j->id, 'entityNumber' => $j->name_ar, 'oldValue' => $old, 'newValue' => $changes]);
            }

            return $this->jobShape($j->load('department:id,name_ar,name_en'), $holders);
        });
    }

    private function jobValues(array $dto, ?Job $j): array
    {
        $v = [];
        foreach (['nameAr' => 'name_ar', 'nameEn' => 'name_en', 'description' => 'description'] as $field => $column) {
            if (array_key_exists($field, $dto)) {
                $v[$column] = Hr::text($dto[$field], $column === 'description' ? 2000 : 250);
            }
        }
        if (array_key_exists('name_ar', $v) && $v['name_ar'] === null) {
            throw AppError::validation('JOB_NAME', 'المسمى الوظيفي مطلوب', 'The job name is required');
        }
        if (array_key_exists('name_en', $v) && $v['name_en'] === null && $j) {
            $v['name_en'] = $v['name_ar'] ?? $j->name_ar;
        }
        if (array_key_exists('active', $dto) && $dto['active'] !== null) {
            $v['active'] = filter_var($dto['active'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('departmentId', $dto)) {
            $v['department_id'] = $dto['departmentId'] ? $this->department((string) $dto['departmentId'])->id : null;
        }

        return $v;
    }

    private function jobShape(Job $j, int $headcount): array
    {
        return ['id' => $j->id, 'nameAr' => $j->name_ar, 'nameEn' => $j->name_en, 'department' => $j->department ? self::named($j->department) : null, 'description' => $j->description,
            'headcount' => $headcount, 'active' => (bool) $j->active, 'source' => $j->source_system, 'locked' => Source::locked($j->source_system)];
    }

    // ───────────────────────────── org chart ─────────────────────────────

    /** The company as a picture: the department tree and the people in it with who they report to (active people only). */
    public function org(): array
    {
        $people = Employee::where('status', 'active')->orderBy('name_ar')->orderBy('id')->limit(3000)->get(['id', 'code', 'name_ar', 'name_en', 'job_title', 'department_id', 'manager_id']);

        return $this->departments() + [
            'employees' => $people->map(fn (Employee $e) => ['id' => $e->id, 'code' => $e->code, 'nameAr' => $e->name_ar, 'nameEn' => $e->name_en, 'jobTitle' => $e->job_title,
                'departmentId' => $e->department_id, 'managerId' => $e->manager_id])->all(),
        ];
    }
}
