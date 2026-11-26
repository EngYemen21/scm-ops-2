<?php

namespace App\Workspace\Services;

use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\NumberingService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Workspace\Models\Project;
use App\Workspace\Models\ProjectMember;
use App\Workspace\Models\Task;
use App\Workspace\Models\TaskStage;
use App\Workspace\Models\TaskTag;
use App\Workspace\Support\Owned;
use App\Workspace\Support\People;
use App\Workspace\Support\ProjectAccess;
use App\Workspace\Support\Shape;
use App\Workspace\Support\Ws;
use App\Workspace\WorkspaceBootstrap;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Projects: who runs one, who works in it, when it runs and how its board is laid out.
 *
 * STATE MACHINE. `status` is active ↔ on_hold, either → done, done → active (Project::TRANSITIONS). A project is not
 * finished while tasks in it are still open. `archived` puts a project away; nothing is deleted.
 *
 * WHO. A project is created by the projects manager (`project.manage`, on the route). Its details, members, stages
 * and status are kept by its own manager and by the projects manager; its tasks are worked by its members
 * (ProjectAccess).
 *
 * OWNERSHIP. While the section follows Odoo a project that came from Odoo is Odoo's: its details, its stages and its
 * status are refused here. Its MEMBERS stay open — Odoo holds no such list; it is OPS's own way of saying who works
 * the tasks people add here.
 */
class ProjectsService
{
    public const WITH = ['manager:'.People::COLUMNS, 'members.user:'.People::COLUMNS];

    public const STATES = ['active', 'done', 'archived', 'all'];

    private const SORTS = ['createdAt' => 'created_at', 'name' => 'name', 'number' => 'number', 'endDate' => 'end_date'];

    public function __construct(
        private readonly AuditService $audit,
        private readonly NumberingService $numbering,
        private readonly NotifyService $notify,
    ) {}

    // ───────────── reading ─────────────

    /** @param  array{state?:?string, mine?:mixed}  $f */
    public function list(AuthUser $user, Paging $page, array $f): array
    {
        $access = new ProjectAccess($user);
        $query = $access->scopeProjects(Project::query())->with(self::WITH);
        match ($f['state'] ?? 'active') {
            'done' => $query->where('status', 'done')->where('archived', false),
            'archived' => $query->where('archived', true),
            'all' => null,
            default => $query->whereIn('status', ['active', 'on_hold'])->where('archived', false),
        };
        if (filter_var($f['mine'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->whereIn('id', $access->memberProjectIds());
        }
        Ws::search($query, ['number', 'name', 'manager_name'], $page->q);
        $column = self::SORTS[$page->sort ?? ''] ?? null;
        if ($column === null) {
            $query->orderByDesc('created_at')->orderByDesc('id');
        } else {
            if ($column === 'end_date') {
                $query->orderByRaw('end_date IS NULL');
            }
            $query->orderBy($column, $page->order)->orderBy('id', $page->order);
        }
        $out = $page->paginate($query);
        $counts = self::counts(array_map(fn (Project $p) => (string) $p->id, $out['items']));
        $out['items'] = array_map(fn (Project $p) => Shape::project($p, $counts[(string) $p->id] ?? self::NO_TASKS, $access), $out['items']);

        return $out;
    }

    public function get(AuthUser $user, string $idOrNumber): array
    {
        $p = $this->find($idOrNumber);
        $access = new ProjectAccess($user);
        $this->mustSee($access, $p);
        $p->loadMissing(self::WITH);
        $owned = Owned::bySource($p);
        $keeps = $access->configuresProject($p);
        $live = ! $p->archived;

        return Shape::project($p, self::counts([(string) $p->id])[(string) $p->id] ?? self::NO_TASKS, $access) + [
            'stages' => $p->stages()->get()->map(fn (TaskStage $s) => Shape::taskStage($s))->all(),
            'extra' => $p->extra ?: null, 'createdBy' => $p->created_by, 'updatedAt' => Ws::at($p->updated_at),
            'archivedAt' => Ws::at($p->archived_at), 'archivedBy' => $p->archived_by, 'archiveReason' => $p->archive_reason,
            'can' => [
                'edit' => ! $owned && $keeps && $live, 'status' => ! $owned && $keeps && $live, 'archive' => ! $owned && $keeps && $live, 'restore' => ! $owned && $keeps && ! $live,
                'members' => $keeps && $live, 'stages' => ! $owned && $keeps && $live, 'addTask' => Shape::workable($p, $access),
            ],
        ];
    }

    /** What the forms and filters of the tasks tab pick from: the projects the caller may see with their stages, the tags, the people tasks are given to. */
    public function lookups(AuthUser $user): array
    {
        $access = new ProjectAccess($user);
        $projects = $access->scopeProjects(Project::query())->with('stages')->orderBy('archived')->orderBy('name')->orderBy('id')->get();
        $assignees = People::ofRows(
            DB::table('ws_task_assignees as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')->distinct()
                ->get(['a.user_id', 'a.person_name', 'u.username', 'u.name_ar', 'u.name_en'])
                ->map(fn ($r) => (object) ['user_id' => $r->user_id, 'person_name' => $r->user_id ? null : $r->person_name, 'user' => $r->user_id ? $r : null])
                ->unique(fn ($r) => $r->user_id ?? 'name:'.$r->person_name)->all());

        return [
            'follows' => Owned::following(),
            'projects' => $projects->map(fn (Project $p) => [
                'id' => $p->id, 'number' => $p->number, 'name' => $p->name, 'status' => $p->status, 'archived' => $p->archived, 'canWork' => Shape::workable($p, $access),
                'stages' => $p->stages->map(fn (TaskStage $s) => Shape::taskStage($s))->all(),
            ])->all(),
            'tags' => TaskTag::query()->orderBy('name')->orderBy('id')->get()->map(fn (TaskTag $t) => Shape::tag($t))->all(),
            'assignees' => $assignees,
        ];
    }

    private const NO_TASKS = ['open' => 0, 'done' => 0, 'overdue' => 0, 'total' => 0];

    /**
     * The tasks of some projects in figures (archived and cancelled tasks are not counted).
     *
     * @param  string[]  $projectIds
     * @return array<string, array{open:int, done:int, overdue:int, total:int}>
     */
    public static function counts(array $projectIds): array
    {
        if (! $projectIds) {
            return [];
        }
        $rows = DB::table('ws_tasks')->whereIn('project_id', $projectIds)->where('archived', false)->whereIn('status', ['open', 'done'])
            ->selectRaw("project_id, SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS open_n, SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) AS done_n, "
                ."SUM(CASE WHEN status = 'open' AND deadline IS NOT NULL AND deadline < ? THEN 1 ELSE 0 END) AS overdue_n, COUNT(*) AS total_n", [Carbon::today()->toDateString()])
            ->groupBy('project_id')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->project_id] = ['open' => (int) $r->open_n, 'done' => (int) $r->done_n, 'overdue' => (int) $r->overdue_n, 'total' => (int) $r->total_n];
        }

        return $out;
    }

    // ───────────── writing ─────────────

    public function create(AuthUser $user, array $dto): array
    {
        $id = DB::transaction(function () use ($user, $dto) {
            $values = $this->values($dto, null);
            $manager = ! empty($dto['managerId']) ? People::assignable((string) $dto['managerId'], 'managerId') : null;
            $p = Project::create($values + [
                'number' => $this->numbering->next('PRJ'), 'status' => 'active', 'visibility' => $values['visibility'] ?? 'all',
                // nobody was named: the person who opens the project runs it
                'manager_id' => $manager?->id ?? $user->id, 'created_by' => $user->username, 'updated_by' => $user->username,
            ]);
            People::sync(ProjectMember::class, 'project_id', $p->id, (array) ($dto['memberKeys'] ?? []), 'memberKeys');
            $stages = ! empty($dto['stages']) ? array_map(fn (array $s) => [(string) $s['nameAr'], (string) ($s['nameEn'] ?? '') ?: (string) $s['nameAr'], (bool) ($s['isClosing'] ?? false)], (array) $dto['stages'])
                : WorkspaceBootstrap::TASK_STAGES;
            foreach (array_values($stages) as $i => [$ar, $en, $closing]) {
                TaskStage::create(['project_id' => $p->id, 'name_ar' => mb_substr($ar, 0, 120), 'name_en' => mb_substr($en, 0, 120), 'sequence' => ($i + 1) * 10, 'is_closing' => $closing, 'active' => true, 'created_by' => $user->username]);
            }
            $this->audit->log($user, ['action' => 'PROJECT.CREATE', 'entityType' => 'WsProject', 'entityId' => $p->id, 'entityNumber' => $p->number, 'newValue' => $dto]);
            $this->notify->activity($user, 'WsProject', $p->id, $p->number, "مشروع جديد {$p->number} — {$p->name}", "New project {$p->number} — {$p->name}");
            People::tell($p->manager_id, $user, 'WsProject', $p->id, $p->number, "أُسندت إليك إدارة المشروع {$p->number} — {$p->name}", "You were made the manager of project {$p->number} — {$p->name}");

            return $p->id;
        });

        return $this->get($user, $id);
    }

    public function update(AuthUser $user, string $idOrNumber, array $dto): array
    {
        $this->mustKeep($user, $this->find($idOrNumber));
        $id = DB::transaction(function () use ($user, $idOrNumber, $dto) {
            $p = $this->locked($idOrNumber);
            Owned::refuse($p, 'project');
            $this->mustBeLive($p);
            $values = $this->values($dto, $p);
            if (array_key_exists('managerId', $dto)) {
                $values['manager_id'] = ! empty($dto['managerId']) ? (string) People::assignable((string) $dto['managerId'], 'managerId')->id : null;
                $values['manager_name'] = null;
            }
            [$old, $new] = Ws::diff($p, $values);
            if (! $new) {
                return $p->id;
            }
            $p->update($new + ['updated_by' => $user->username]);
            $this->audit->log($user, ['action' => 'PROJECT.UPDATE', 'entityType' => 'WsProject', 'entityId' => $p->id, 'entityNumber' => $p->number, 'oldValue' => $old, 'newValue' => $new]);
            if (! empty($new['manager_id'])) {
                People::tell((string) $new['manager_id'], $user, 'WsProject', $p->id, $p->number, "أُسندت إليك إدارة المشروع {$p->number} — {$p->name}", "You were made the manager of project {$p->number} — {$p->name}");
            }

            return $p->id;
        });

        return $this->get($user, $id);
    }

    /**
     * Who works the tasks of the project. Open on a project of Odoo too: this list is OPS's own.
     *
     * @param  string[]  $keys  Person keys
     */
    public function members(AuthUser $user, string $idOrNumber, array $keys): array
    {
        $this->mustKeep($user, $this->find($idOrNumber));
        $id = DB::transaction(function () use ($user, $idOrNumber, $keys) {
            $p = $this->locked($idOrNumber);
            $this->mustBeLive($p);
            $before = People::names(ProjectMember::class, 'project_id', $p->id);
            $known = ProjectMember::where('project_id', $p->id)->whereNotNull('user_id')->pluck('user_id')->map(fn ($x) => (string) $x)->all();
            if (People::sync(ProjectMember::class, 'project_id', $p->id, $keys, 'memberKeys')) {
                $this->audit->log($user, ['action' => 'PROJECT.MEMBERS', 'entityType' => 'WsProject', 'entityId' => $p->id, 'entityNumber' => $p->number, 'oldValue' => $before,
                    'newValue' => People::names(ProjectMember::class, 'project_id', $p->id)]);
                foreach (ProjectMember::where('project_id', $p->id)->whereNotNull('user_id')->pluck('user_id') as $member) {
                    if (! in_array((string) $member, $known, true)) {
                        People::tell((string) $member, $user, 'WsProject', $p->id, $p->number, "أُضفت إلى المشروع {$p->number} — {$p->name}", "You were added to project {$p->number} — {$p->name}");
                    }
                }
            }

            return $p->id;
        });

        return $this->get($user, $id);
    }

    public function status(AuthUser $user, string $idOrNumber, string $status, ?string $note = null): array
    {
        $this->mustKeep($user, $this->find($idOrNumber));
        $id = DB::transaction(function () use ($user, $idOrNumber, $status, $note) {
            $p = $this->locked($idOrNumber);
            Owned::refuse($p, 'project');
            $this->mustBeLive($p);
            if ($p->status === $status) {
                return $p->id;
            }
            Ws::transition(Project::TRANSITIONS, $p->status, $status, 'WS_PROJECT_TRANSITION');
            if ($status === 'done' && ($open = Task::where('project_id', $p->id)->where('status', 'open')->where('archived', false)->count()) > 0) {
                throw AppError::rule('WS_PROJECT_HAS_OPEN_TASKS', "في المشروع {$open} مهمة مفتوحة — أنجزها أو ألغها قبل إنهاء المشروع", "The project still has {$open} open task(s) — finish or cancel them before closing the project", ['open' => $open]);
            }
            $from = $p->status;
            $p->update(['status' => $status, 'updated_by' => $user->username]);
            $this->audit->status($user, 'WsProject', $p->id, $p->number, $from, $status, Ws::text($note, 500));
            $this->notify->activity($user, 'WsProject', $p->id, $p->number, "المشروع {$p->number}: {$from} ← {$status}", "Project {$p->number}: {$from} → {$status}");

            return $p->id;
        });

        return $this->get($user, $id);
    }

    public function archive(AuthUser $user, string $idOrNumber, ?string $reason = null): array
    {
        return $this->shelve($user, $idOrNumber, true, $reason);
    }

    public function restore(AuthUser $user, string $idOrNumber): array
    {
        return $this->shelve($user, $idOrNumber, false, null);
    }

    private function shelve(AuthUser $user, string $idOrNumber, bool $away, ?string $reason): array
    {
        $this->mustKeep($user, $this->find($idOrNumber));
        $id = DB::transaction(function () use ($user, $idOrNumber, $away, $reason) {
            $p = $this->locked($idOrNumber);
            Owned::refuse($p, 'project');
            if ($p->archived === $away) {
                return $p->id;
            }
            $reason = Ws::text($reason, 1000);
            $p->update(['archived' => $away, 'archived_at' => $away ? now() : null, 'archived_by' => $away ? $user->username : null, 'archive_reason' => $away ? $reason : null, 'updated_by' => $user->username]);
            $this->audit->log($user, ['action' => $away ? 'PROJECT.ARCHIVE' : 'PROJECT.RESTORE', 'entityType' => 'WsProject', 'entityId' => $p->id, 'entityNumber' => $p->number, 'newValue' => array_filter(['reason' => $reason])]);

            return $p->id;
        });

        return $this->get($user, $id);
    }

    // ───────────── the board's columns ─────────────

    public function storeStage(AuthUser $user, string $idOrNumber, array $dto): array
    {
        $this->mustKeep($user, $this->find($idOrNumber));
        $id = DB::transaction(function () use ($user, $idOrNumber, $dto) {
            $p = $this->locked($idOrNumber);
            Owned::refuse($p, 'project');
            $this->mustBeLive($p);
            $nameAr = Ws::text($dto['nameAr'], 120);
            $s = TaskStage::create(['project_id' => $p->id, 'name_ar' => $nameAr, 'name_en' => Ws::text($dto['nameEn'] ?? null, 120) ?? $nameAr, 'is_closing' => (bool) ($dto['isClosing'] ?? false), 'active' => true,
                'sequence' => isset($dto['sequence']) ? (int) $dto['sequence'] : ((int) TaskStage::where('project_id', $p->id)->max('sequence')) + 10, 'created_by' => $user->username]);
            $this->audit->log($user, ['action' => 'PROJECT.STAGE.CREATE', 'entityType' => 'WsTaskStage', 'entityId' => $s->id, 'entityNumber' => "{$p->number} · {$s->name_en}", 'newValue' => $dto]);

            return $p->id;
        });

        return $this->get($user, $id);
    }

    public function updateStage(AuthUser $user, string $stageId, array $dto): array
    {
        $stage = TaskStage::find($stageId) ?? throw AppError::notFound('WS_STAGE_NOT_FOUND', 'المرحلة غير موجودة', 'Stage not found');
        $project = $this->find((string) $stage->project_id);
        $this->mustKeep($user, $project);
        DB::transaction(function () use ($user, $stageId, $dto, $project) {
            $s = TaskStage::where('id', $stageId)->lockForUpdate()->firstOrFail();
            Owned::refuse($s, 'stage');
            Owned::refuse($project, 'project');
            $this->mustBeLive($project);
            $values = [];
            if (array_key_exists('nameAr', $dto) && Ws::text($dto['nameAr']) !== null) {
                $values['name_ar'] = Ws::text($dto['nameAr'], 120);
            }
            if (array_key_exists('nameEn', $dto)) {
                $values['name_en'] = Ws::text($dto['nameEn'], 120) ?? $values['name_ar'] ?? $s->name_ar;
            }
            if (array_key_exists('sequence', $dto) && $dto['sequence'] !== null) {
                $values['sequence'] = (int) $dto['sequence'];
            }
            foreach (['isClosing' => 'is_closing', 'active' => 'active'] as $key => $column) {
                if (array_key_exists($key, $dto) && $dto[$key] !== null) {
                    $values[$column] = (bool) $dto[$key];
                }
            }
            [$old, $new] = Ws::diff($s, $values);
            if (! $new) {
                return;
            }
            // tasks stand in it: it cannot change what it means under them, nor leave while open tasks wait in it
            if (array_key_exists('is_closing', $new) && Task::where('stage_id', $s->id)->exists()) {
                throw AppError::rule('WS_STAGE_IN_USE', 'في هذه المرحلة مهام — لا يتغيّر كونها مرحلة إنجاز. أضف مرحلة جديدة وانقل المهام إليها', 'Tasks stand in this stage — whether it finishes a task cannot change. Add a new stage and move them');
            }
            if (($new['active'] ?? null) === false && Task::where('stage_id', $s->id)->where('status', 'open')->where('archived', false)->exists()) {
                throw AppError::rule('WS_STAGE_IN_USE', 'في هذه المرحلة مهام مفتوحة — انقلها إلى مرحلة أخرى قبل إيقافها', 'Open tasks stand in this stage — move them before switching it off');
            }
            $s->update($new);
            $this->audit->log($user, ['action' => 'PROJECT.STAGE.UPDATE', 'entityType' => 'WsTaskStage', 'entityId' => $s->id, 'entityNumber' => "{$project->number} · {$s->name_en}", 'oldValue' => $old, 'newValue' => $new]);
        });

        return $this->get($user, (string) $project->id);
    }

    // ───────────── tags (project.manage on the route) ─────────────

    public function storeTag(AuthUser $user, array $dto): array
    {
        $name = Ws::text($dto['name'], 80);
        $twin = TaskTag::where('name', $name)->first();
        if ($twin) {
            throw AppError::conflict('WS_TAG_EXISTS', "الوسم «{$name}» موجود مسبقًا", "The tag “{$name}” already exists");
        }
        $tag = DB::transaction(function () use ($user, $dto, $name) {
            $tag = TaskTag::create(['name' => $name, 'color' => self::color($dto['color'] ?? 0), 'active' => true, 'created_by' => $user->username]);
            $this->audit->log($user, ['action' => 'TASK.TAG.CREATE', 'entityType' => 'WsTaskTag', 'entityId' => $tag->id, 'entityNumber' => $tag->name, 'newValue' => $dto]);

            return $tag;
        });

        return Shape::tag($tag->refresh());
    }

    public function updateTag(AuthUser $user, string $id, array $dto): array
    {
        $tag = DB::transaction(function () use ($user, $id, $dto) {
            $tag = TaskTag::where('id', $id)->lockForUpdate()->first() ?? throw AppError::notFound('WS_TAG_NOT_FOUND', 'الوسم غير موجود', 'Tag not found');
            Owned::refuse($tag, 'tag');
            $values = [];
            if (array_key_exists('name', $dto) && Ws::text($dto['name']) !== null) {
                $values['name'] = Ws::text($dto['name'], 80);
                if (TaskTag::where('name', $values['name'])->where('id', '!=', $tag->id)->exists()) {
                    throw AppError::conflict('WS_TAG_EXISTS', "الوسم «{$values['name']}» موجود مسبقًا", "The tag “{$values['name']}” already exists");
                }
            }
            if (array_key_exists('color', $dto) && $dto['color'] !== null) {
                $values['color'] = self::color($dto['color']);
            }
            if (array_key_exists('active', $dto) && $dto['active'] !== null) {
                $values['active'] = (bool) $dto['active'];
            }
            [$old, $new] = Ws::diff($tag, $values);
            if ($new) {
                $tag->update($new);
                $this->audit->log($user, ['action' => 'TASK.TAG.UPDATE', 'entityType' => 'WsTaskTag', 'entityId' => $tag->id, 'entityNumber' => $tag->name, 'oldValue' => $old, 'newValue' => $new]);
            }

            return $tag;
        });

        return Shape::tag($tag->refresh());
    }

    // ───────────── helpers ─────────────

    public function find(string $idOrNumber): Project
    {
        return Project::where('id', $idOrNumber)->orWhere('number', $idOrNumber)->first()
            ?? throw AppError::notFound('WS_PROJECT_NOT_FOUND', 'المشروع غير موجود', 'Project not found');
    }

    private function locked(string $idOrNumber): Project
    {
        return Project::where(fn (Builder $w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->lockForUpdate()->first()
            ?? throw AppError::notFound('WS_PROJECT_NOT_FOUND', 'المشروع غير موجود', 'Project not found');
    }

    public function mustSee(ProjectAccess $access, Project $p): void
    {
        if (! $access->seesProject($p)) {
            Ws::deny($access->user, 'WS_PROJECT_FORBIDDEN', 'هذا المشروع لأعضائه فقط', 'This project is for its members only');
        }
    }

    /** The caller keeps the project: its manager, or the projects manager. */
    private function mustKeep(AuthUser $user, Project $p): void
    {
        $access = new ProjectAccess($user);
        $this->mustSee($access, $p);
        if (! $access->configuresProject($p)) {
            Ws::deny($user, 'WS_PROJECT_FORBIDDEN', 'يعدّل المشروع مديره أو مدير المشاريع', 'A project is changed by its manager or by the projects manager');
        }
    }

    private function mustBeLive(Project $p): void
    {
        if ($p->archived) {
            throw AppError::rule('WS_ARCHIVED', 'المشروع مؤرشف — استعده أولًا', 'The project is archived — restore it first');
        }
    }

    /** @return array<string, mixed> the columns a payload sets (in an edit: only what was sent) */
    private function values(array $dto, ?Project $p): array
    {
        $out = [];
        if (array_key_exists('name', $dto) && Ws::text($dto['name']) !== null) {
            $out['name'] = Ws::text($dto['name'], 250);
        }
        if (array_key_exists('description', $dto)) {
            $out['description'] = Ws::text($dto['description']);
        }
        if (! empty($dto['visibility'])) {
            $out['visibility'] = (string) $dto['visibility'];
        }
        foreach (['startDate' => 'start_date', 'endDate' => 'end_date'] as $key => $column) {
            if (array_key_exists($key, $dto)) {
                $out[$column] = Ws::text($dto[$key]);
            }
        }
        $start = array_key_exists('start_date', $out) ? $out['start_date'] : Ws::day($p?->start_date);
        $end = array_key_exists('end_date', $out) ? $out['end_date'] : Ws::day($p?->end_date);
        if ($start !== null && $end !== null && $end < $start) {
            throw AppError::validation('INVALID_INPUT', 'تاريخ نهاية المشروع قبل تاريخ بدايته', 'The project ends before it starts', [['path' => 'endDate', 'message' => 'قبل تاريخ البداية']]);
        }

        return $out;
    }

    private static function color(mixed $v): int
    {
        return max(0, min(TaskTag::COLORS - 1, (int) $v));
    }
}
