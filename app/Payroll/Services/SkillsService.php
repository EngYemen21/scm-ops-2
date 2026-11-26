<?php

namespace App\Payroll\Services;

use App\Hr\Models\Employee;
use App\Hr\Services\Workforce;
use App\Payroll\Models\EmployeeSkill;
use App\Payroll\Models\ResumeLine;
use App\Payroll\Models\ResumeType;
use App\Payroll\Models\Skill;
use App\Payroll\Models\SkillLevel;
use App\Payroll\Models\SkillType;
use App\Payroll\Support\Access;
use App\Payroll\Support\Owned;
use App\Payroll\Support\Shape;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Skills and résumé of the employees (who they are comes from People & expenses, through Workforce).
 *
 * The CATALOGUE — skill types, the skills of a type, the levels of a type, the kinds of résumé line — is kept by
 * `payroll.manage`. An employee's OWN skills and résumé are read and kept by that employee and by the payroll people;
 * nobody else sees them: a résumé is a personal paper. A line that came from Odoo is Odoo's while the section follows Odoo.
 */
class SkillsService
{
    public function __construct(private readonly AuditService $audit, private readonly Workforce $workforce) {}

    // ───────────────────────────── the catalogue ─────────────────────────────

    public function catalog(bool $withInactive = false): array
    {
        $active = fn ($q) => $withInactive ? $q : $q->where('active', true);

        return [
            'types' => $active(SkillType::query())->with(['skills' => $active, 'levels' => $active])->orderBy('sequence')->orderBy('name_ar')->get()->map(fn (SkillType $t) => self::named($t) + [
                'skills' => $t->skills->map(fn (Skill $s) => self::named($s) + ['typeId' => $s->type_id])->values()->all(),
                'levels' => $t->levels->map(fn (SkillLevel $l) => self::named($l) + ['typeId' => $l->type_id, 'progress' => $l->progress, 'isDefault' => $l->is_default])->values()->all(),
            ])->all(),
            'resumeTypes' => $active(ResumeType::query())->orderBy('sequence')->orderBy('name_ar')->get()->map(fn (ResumeType $t) => self::named($t))->all(),
        ];
    }

    /** One entry of the catalogue. `$kind`: type | skill | level | resumeType. */
    public function save(AuthUser $user, string $kind, ?string $id, array $dto): array
    {
        $class = ['type' => SkillType::class, 'skill' => Skill::class, 'level' => SkillLevel::class, 'resumeType' => ResumeType::class][$kind];

        return DB::transaction(function () use ($user, $kind, $class, $id, $dto) {
            /** @var ?Model $row */
            $row = $id ? ($class::whereKey($id)->lockForUpdate()->first() ?? throw AppError::notFound('SKILL_NOT_FOUND', 'العنصر غير موجود', 'Entry not found')) : null;
            Owned::refuse($row, 'skill', 'هذا العنصر من دليل المهارات', 'This entry of the skills catalogue');
            $values = [];
            foreach (['nameAr' => 'name_ar', 'nameEn' => 'name_en'] as $key => $column) {
                if (array_key_exists($key, $dto) && ($text = Shape::text($dto[$key], 190)) !== null) {
                    $values[$column] = $text;
                }
            }
            if (array_key_exists('active', $dto)) {
                $values['active'] = (bool) $dto['active'];
            }
            if (array_key_exists('sequence', $dto) && $dto['sequence'] !== null && $kind !== 'level') {
                $values['sequence'] = max(0, min(100000, (int) $dto['sequence']));
            }
            if ($kind === 'level') {
                if (array_key_exists('progress', $dto) && $dto['progress'] !== null) {
                    $values['progress'] = max(0, min(100, (int) $dto['progress']));
                }
                if (array_key_exists('isDefault', $dto)) {
                    $values['is_default'] = (bool) $dto['isDefault'];
                }
            }
            if (! $row) {
                if (empty($values['name_ar'])) {
                    throw AppError::validation('INVALID_INPUT', 'الاسم مطلوب', 'A name is required');
                }
                if (in_array($kind, ['skill', 'level'], true)) {
                    $values['type_id'] = SkillType::whereKey((string) ($dto['typeId'] ?? ''))->value('id') ?? throw AppError::notFound('SKILL_TYPE_NOT_FOUND', 'نوع المهارة غير موجود', 'Skill type not found');
                }
                $row = $class::create($values + ['name_en' => $values['name_ar'], 'active' => true, 'created_by' => $user->username]);
            } elseif ($values) {
                $row->update($values);
            }
            if ($kind === 'level' && $row->is_default) {
                SkillLevel::where('type_id', $row->type_id)->where('id', '!=', $row->id)->where('is_default', true)->update(['is_default' => false]);
            }
            $this->audit->log($user, ['action' => 'SKILLS.'.strtoupper($kind).'.'.($id ? 'UPDATE' : 'CREATE'), 'entityType' => 'SkillsCatalog', 'entityId' => $row->getKey(), 'entityNumber' => $row->name_ar, 'newValue' => $values]);

            return $this->catalog(true);
        });
    }

    // ───────────────────────────── people ─────────────────────────────

    /** @param  array{department?:?string, type?:?string, skill?:?string, level?:?string, has?:?string}  $f */
    public function employees(AuthUser $user, Paging $page, array $f): array
    {
        Access::needPay($user);
        $q = Employee::query()->where('status', 'active');
        if (! empty($f['department'])) {
            $q->where('department_id', $f['department']);
        }
        $link = fn (string $column, string $value) => $q->whereIn('id', EmployeeSkill::where($column, $value)->select('employee_id'));
        foreach (['type' => 'type_id', 'skill' => 'skill_id', 'level' => 'level_id'] as $key => $column) {
            if (! empty($f[$key])) {
                $link($column, (string) $f[$key]);
            }
        }
        if (($f['has'] ?? '') === 'skills') {
            $q->whereIn('id', EmployeeSkill::query()->select('employee_id'));
        } elseif (($f['has'] ?? '') === 'resume') {
            $q->whereIn('id', ResumeLine::query()->select('employee_id'));
        } elseif (($f['has'] ?? '') === 'none') {
            $q->whereNotIn('id', EmployeeSkill::query()->select('employee_id'))->whereNotIn('id', ResumeLine::query()->select('employee_id'));
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('code', 'like', $like)->orWhere('name_ar', 'like', $like)->orWhere('name_en', 'like', $like)
                ->orWhereIn('id', fn ($s) => $s->select('employee_id')->from('pay_employee_skills')->whereIn('skill_id', fn ($x) => $x->select('id')->from('pay_skills')->where('name_ar', 'like', $like)->orWhere('name_en', 'like', $like))));
        }
        $total = (clone $q)->toBase()->getCountForPagination();
        $rows = $q->with('department')->orderBy($page->sort === 'code' ? 'code' : 'name_ar', $page->sort ? $page->order : 'asc')->orderBy('id')->forPage($page->page, $page->pageSize)->get();
        $ids = $rows->pluck('id')->all() ?: ['-'];
        $skills = EmployeeSkill::whereIn('employee_id', $ids)->with(['skill', 'level'])->get()->groupBy('employee_id');
        $resume = ResumeLine::whereIn('employee_id', $ids)->selectRaw('employee_id, count(*) as n')->groupBy('employee_id')->pluck('n', 'employee_id');

        return $page->wrap($rows->map(fn (Employee $e) => ['employee' => Shape::employee($e), 'department' => Shape::department($e->department), 'jobTitle' => $e->job_title,
            'skills' => ($skills[$e->id] ?? collect())->map(fn (EmployeeSkill $s) => ['skill' => self::named($s->skill), 'level' => $s->level ? self::named($s->level) + ['progress' => $s->level->progress] : null])->values()->all(),
            'resumeLines' => (int) ($resume[$e->id] ?? 0)])->all(), $total);
    }

    public function employee(AuthUser $user, string $idOrCode): array
    {
        $e = $this->who($user, $idOrCode, false);
        $free = fn (Model $row) => ! Owned::locked($row) && $this->mayKeep($user, $e);

        return ['employee' => Shape::employee($e), 'department' => Shape::department($e->department), 'jobTitle' => $e->job_title, 'mine' => Access::employeeId($user) === $e->id,
            'can' => ['edit' => $this->mayKeep($user, $e)],
            'skills' => EmployeeSkill::where('employee_id', $e->id)->with(['skill', 'level', 'type'])->get()->sortBy(fn (EmployeeSkill $s) => [$s->type?->sequence ?? 0, $s->skill?->sequence ?? 0])->values()
                ->map(fn (EmployeeSkill $s) => ['id' => $s->id, 'type' => self::named($s->type), 'skill' => self::named($s->skill), 'level' => $s->level ? self::named($s->level) + ['progress' => $s->level->progress] : null,
                    'source' => $s->source_system, 'editable' => $free($s)])->all(),
            'resume' => ResumeLine::where('employee_id', $e->id)->with('type')->orderByRaw('date_start IS NULL')->orderByDesc('date_start')->orderByDesc('id')->get()
                ->map(fn (ResumeLine $l) => ['id' => $l->id, 'type' => self::named($l->type), 'title' => $l->title, 'dateStart' => Shape::day($l->date_start), 'dateEnd' => Shape::day($l->date_end),
                    'description' => $l->description, 'source' => $l->source_system, 'editable' => $free($l)])->all(),
        ];
    }

    /** @param  array{skillId:string, levelId?:?string}  $dto */
    public function setSkill(AuthUser $user, string $idOrCode, array $dto): array
    {
        return DB::transaction(function () use ($user, $idOrCode, $dto) {
            $e = $this->who($user, $idOrCode, true);
            $skill = Skill::whereKey((string) $dto['skillId'])->where('active', true)->first() ?? throw AppError::notFound('SKILL_NOT_FOUND', 'المهارة غير موجودة', 'Skill not found');
            $level = ! empty($dto['levelId']) ? (SkillLevel::whereKey((string) $dto['levelId'])->where('type_id', $skill->type_id)->first() ?? throw AppError::rule('SKILL_LEVEL', 'هذا المستوى ليس من مستويات نوع هذه المهارة', 'This level does not belong to the type of the skill')) : null;
            $row = EmployeeSkill::where('employee_id', $e->id)->where('skill_id', $skill->id)->lockForUpdate()->first();
            Owned::refuse($row, 'skill', 'هذه المهارة', 'This skill');
            $row ? $row->update(['level_id' => $level?->id]) : ($row = EmployeeSkill::create(['employee_id' => $e->id, 'type_id' => $skill->type_id, 'skill_id' => $skill->id, 'level_id' => $level?->id, 'created_by' => $user->username]));
            $this->audit->log($user, ['action' => 'SKILLS.EMPLOYEE.SET', 'entityType' => 'EmployeeSkill', 'entityId' => $row->id, 'entityNumber' => $e->code]);

            return $this->employee($user, $e->id);
        });
    }

    public function removeSkill(AuthUser $user, string $idOrCode, string $id): array
    {
        return DB::transaction(function () use ($user, $idOrCode, $id) {
            $e = $this->who($user, $idOrCode, true);
            $row = EmployeeSkill::where('employee_id', $e->id)->whereKey($id)->lockForUpdate()->first() ?? throw AppError::notFound('SKILL_NOT_FOUND', 'المهارة غير موجودة على هذا الموظف', 'The employee does not have this skill');
            Owned::refuse($row, 'skill', 'هذه المهارة', 'This skill');
            $row->delete(); // a skill a person no longer claims: the trail keeps that it was taken off
            $this->audit->log($user, ['action' => 'SKILLS.EMPLOYEE.REMOVE', 'entityType' => 'EmployeeSkill', 'entityId' => $id, 'entityNumber' => $e->code]);

            return $this->employee($user, $e->id);
        });
    }

    /** @param  array{typeId?:?string, title?:string, dateStart?:?string, dateEnd?:?string, description?:?string}  $dto */
    public function saveResumeLine(AuthUser $user, string $idOrCode, ?string $id, array $dto): array
    {
        return DB::transaction(function () use ($user, $idOrCode, $id, $dto) {
            $e = $this->who($user, $idOrCode, true);
            $line = $id ? (ResumeLine::where('employee_id', $e->id)->whereKey($id)->lockForUpdate()->first() ?? throw AppError::notFound('RESUME_LINE_NOT_FOUND', 'سطر السيرة غير موجود', 'Résumé line not found')) : null;
            Owned::refuse($line, 'resume', 'سطر السيرة الذاتية', 'This résumé line');
            $values = [];
            if (array_key_exists('title', $dto)) {
                $values['title'] = Shape::text($dto['title'], 250) ?? throw AppError::validation('INVALID_INPUT', 'عنوان السطر مطلوب', 'The line needs a title');
            }
            if (array_key_exists('typeId', $dto)) {
                $values['type_id'] = $dto['typeId'] ? (ResumeType::whereKey((string) $dto['typeId'])->value('id') ?? throw AppError::notFound('RESUME_TYPE_NOT_FOUND', 'نوع السطر غير موجود', 'Résumé line type not found')) : null;
            }
            foreach (['dateStart' => 'date_start', 'dateEnd' => 'date_end'] as $key => $column) {
                if (array_key_exists($key, $dto)) {
                    $values[$column] = $dto[$key] ? Carbon::parse((string) $dto[$key])->format('Y-m-d') : null;
                }
            }
            if (array_key_exists('description', $dto)) {
                $values['description'] = Shape::text($dto['description'], 4000);
            }
            $start = array_key_exists('date_start', $values) ? $values['date_start'] : Shape::day($line?->date_start);
            $end = array_key_exists('date_end', $values) ? $values['date_end'] : Shape::day($line?->date_end);
            if ($start !== null && $end !== null && $end < $start) {
                throw AppError::validation('RESUME_DATES', 'نهاية المدة لا تسبق بدايتها', 'The end cannot be before the start');
            }
            if (! $line) {
                if (empty($values['title'])) {
                    throw AppError::validation('INVALID_INPUT', 'عنوان السطر مطلوب', 'The line needs a title');
                }
                $line = ResumeLine::create($values + ['employee_id' => $e->id, 'created_by' => $user->username]);
            } else {
                $line->update($values);
            }
            // the trail says a line was written — not what a person's history says
            $this->audit->log($user, ['action' => 'RESUME.'.($id ? 'UPDATE' : 'CREATE'), 'entityType' => 'ResumeLine', 'entityId' => $line->id, 'entityNumber' => $e->code]);

            return $this->employee($user, $e->id);
        });
    }

    public function removeResumeLine(AuthUser $user, string $idOrCode, string $id): array
    {
        return DB::transaction(function () use ($user, $idOrCode, $id) {
            $e = $this->who($user, $idOrCode, true);
            $line = ResumeLine::where('employee_id', $e->id)->whereKey($id)->lockForUpdate()->first() ?? throw AppError::notFound('RESUME_LINE_NOT_FOUND', 'سطر السيرة غير موجود', 'Résumé line not found');
            Owned::refuse($line, 'resume', 'سطر السيرة الذاتية', 'This résumé line');
            $line->delete();
            $this->audit->log($user, ['action' => 'RESUME.REMOVE', 'entityType' => 'ResumeLine', 'entityId' => $id, 'entityNumber' => $e->code]);

            return $this->employee($user, $e->id);
        });
    }

    // ───────────────────────────── internals ─────────────────────────────

    /** The employee a call is about (`me` = the caller's own record), readable — and, when asked, writable — by the caller. */
    private function who(AuthUser $user, string $idOrCode, bool $toKeep): Employee
    {
        $e = $idOrCode === 'me' ? Employee::find(Access::needEmployee($user)) : $this->workforce->employee($idOrCode);
        $e = $e?->load('department') ?? throw AppError::notFound('EMPLOYEE_NOT_FOUND', 'الموظف غير موجود', 'Employee not found');
        if (! Access::seesPayOf($user, $e->id) || ($toKeep && ! $this->mayKeep($user, $e))) {
            throw Access::denied();
        }

        return $e;
    }

    private function mayKeep(AuthUser $user, Employee $e): bool
    {
        return $user->can('payroll.manage') || Access::employeeId($user) === $e->id;
    }

    private static function named(?Model $row): ?array
    {
        return $row ? ['id' => $row->getKey(), 'nameAr' => $row->getAttribute('name_ar'), 'nameEn' => $row->getAttribute('name_en'), 'active' => (bool) ($row->getAttribute('active') ?? true),
            'source' => $row->getAttribute('source_system'), 'locked' => Owned::locked($row)] : null;
    }
}
