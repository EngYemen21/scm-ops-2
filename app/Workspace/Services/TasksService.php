<?php

namespace App\Workspace\Services;

use App\Services\Core\AuditService;
use App\Services\Core\NumberingService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Workspace\Models\ChecklistItem;
use App\Workspace\Models\Project;
use App\Workspace\Models\Task;
use App\Workspace\Models\TaskAssignee;
use App\Workspace\Models\TaskStage;
use App\Workspace\Models\TaskTag;
use App\Workspace\Models\WsFile;
use App\Workspace\Support\Owned;
use App\Workspace\Support\People;
use App\Workspace\Support\ProjectAccess;
use App\Workspace\Support\RecordLinks;
use App\Workspace\Support\Shape;
use App\Workspace\Support\Ws;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tasks: of a project (they stand in one of its stages and its members work them) or personal (no project — seen by
 * whoever made them and whoever they were given to).
 *
 * STATE MACHINE. `status` is open → done | cancelled and back to open by a reopening (Task::TRANSITIONS). On a board,
 * entering a closing stage IS finishing the task and leaving one reopens it; a cancelled task does not move. A task
 * carries assignees, a deadline, a priority, tags, a checklist, a link to another record of OPS, and a thread of
 * comments with files in which every step is told. `archived` puts a task away; nothing is deleted.
 *
 * OWNERSHIP. While the section follows Odoo a task that came from Odoo is Odoo's: its values, its stage, its checklist
 * and its closing are refused with WORKSPACE_OWNED_BY_SOURCE; comments written here are OPS's own and stay open.
 */
class TasksService
{
    public const WITH = ['project:id,number,name', 'stage:id,name_ar,name_en,is_closing', 'assignees.user:'.People::COLUMNS, 'tags'];

    public const SCOPES = ['all', 'mine', 'created'];

    /** The lists of the tool: open (default) · done · cancelled · archived · all; `board` = what a board shows. */
    public const STATES = ['open', 'done', 'cancelled', 'archived', 'all', 'board'];

    public const DUE = ['overdue', 'today', 'week', 'none'];

    private const SORTS = ['createdAt' => 'created_at', 'number' => 'number', 'title' => 'title', 'priority' => 'priority', 'deadline' => 'deadline', 'doneAt' => 'done_at'];

    private const SPARSE = ['deadline', 'done_at'];

    public function __construct(
        private readonly AuditService $audit,
        private readonly NumberingService $numbering,
        private readonly FilesService $files,
        private readonly Conversation $conversation,
        private readonly ProjectsService $projects,
    ) {}

    // ───────────── reading ─────────────

    /**
     * @param  array{scope?:?string, project?:?string, stage?:?string, state?:?string, assignee?:?string, tag?:?string, priority?:mixed, overdue?:mixed,
     *               due?:?string, origin?:?string}  $f
     */
    public function list(AuthUser $user, Paging $page, array $f): array
    {
        $query = self::counted($this->filtered($user, $f, $page->q)->with(self::WITH));
        $this->order($query, $page->sort, $page->order);
        $today = Carbon::today();

        return $page->paginate($query, fn (Task $t) => Shape::task($t, $today));
    }

    /**
     * The board of a project: one column per stage with its count and its first cards — the most urgent first. The rest
     * of a column is read page by page through the list (`state=board&project=…&stage=…`).
     */
    public function board(AuthUser $user, string $projectIdOrNumber, array $f, ?string $q, int $per): array
    {
        $project = $this->projects->find($projectIdOrNumber);
        $access = new ProjectAccess($user);
        $this->projects->mustSee($access, $project);
        unset($f['stage'], $f['scope']);
        $base = fn (): Builder => $this->filtered($user, ['state' => 'board', 'project' => (string) $project->id] + $f, $q);
        $sums = $base()->toBase()->selectRaw('stage_id, COUNT(*) AS n')->groupBy('stage_id')->pluck('n', 'stage_id');
        $today = Carbon::today();
        $column = function (Builder $query, int $n) use ($per, $today) {
            $cards = $n ? self::counted($this->order($query->with(self::WITH), 'board', 'desc'))->limit($per)->get() : collect();

            return ['count' => $n, 'items' => $cards->map(fn (Task $t) => Shape::task($t, $today))->all(), 'more' => $n > $cards->count()];
        };
        // a stage that was switched off and still holds tasks keeps its column
        $stages = TaskStage::where('project_id', $project->id)->where(fn (Builder $w) => $w->where('active', true)->orWhereIn('id', $sums->keys()->filter()->all()))
            ->orderBy('sequence')->orderBy('created_at')->orderBy('id')->get();
        $columns = [];
        $staged = 0;
        foreach ($stages as $s) {
            $n = (int) ($sums[$s->id] ?? 0);
            $staged += $n;
            $columns[] = Shape::taskStage($s) + $column($base()->where('stage_id', $s->id), $n);
        }
        $loose = (int) $sums->sum() - $staged; // tasks that stand in no stage of this project

        return [
            'project' => ['id' => $project->id, 'number' => $project->number, 'name' => $project->name, 'status' => $project->status, 'canWork' => Shape::workable($project, $access)],
            'per' => $per, 'stages' => $columns,
            'unstaged' => $column($base()->where(fn (Builder $w) => $w->whereNull('stage_id')->orWhereNotIn('stage_id', $stages->pluck('id')->all())), $loose),
        ];
    }

    public function get(AuthUser $user, string $idOrNumber): array
    {
        $t = $this->find($idOrNumber);
        $access = new ProjectAccess($user);
        $this->mustSee($access, $t);

        return $this->detail($user, $t, $access);
    }

    /** What the top of the page counts. @return array{open:int, mine:int, overdue:int, dueToday:int} */
    public function summary(AuthUser $user): array
    {
        $access = new ProjectAccess($user);
        $open = fn (): Builder => $access->scopeTasks(Task::query())->where('status', 'open')->where('archived', false);
        $mine = fn (): Builder => $open()->whereIn('id', DB::table('ws_task_assignees')->where('user_id', $user->id)->select('task_id'));
        $today = Carbon::today()->toDateString();

        return ['open' => $open()->count(), 'mine' => $mine()->count(), 'overdue' => $mine()->where('deadline', '<', $today)->count(), 'dueToday' => $mine()->where('deadline', $today)->count()];
    }

    /** The filters every list of tasks shares (the board reads through it too). */
    public function filtered(AuthUser $user, array $f, ?string $q): Builder
    {
        $query = (new ProjectAccess($user))->scopeTasks(Task::query());
        match ($f['state'] ?? 'open') {
            'done' => $query->where('status', 'done')->where('archived', false),
            'cancelled' => $query->where('status', 'cancelled')->where('archived', false),
            'archived' => $query->where('archived', true),
            'all' => null,
            'board' => $query->whereIn('status', ['open', 'done'])->where('archived', false),
            default => $query->where('status', 'open')->where('archived', false),
        };
        $assignedTo = fn (string $userId) => DB::table('ws_task_assignees')->where('user_id', $userId)->select('task_id');
        match ($f['scope'] ?? 'all') {
            'mine' => $query->whereIn('id', $assignedTo($user->id)),
            'created' => $query->where('created_by_id', $user->id),
            default => null,
        };
        foreach (['project' => 'project_id', 'stage' => 'stage_id'] as $key => $column) {
            if (! empty($f[$key])) {
                $f[$key] === 'none' ? $query->whereNull($column) : $query->where($column, $f[$key]);
            }
        }
        if (isset($f['priority']) && $f['priority'] !== '' && $f['priority'] !== null) {
            $query->where('priority', (int) $f['priority']);
        }
        if (! empty($f['assignee'])) {
            $who = (string) $f['assignee'];
            match (true) {
                $who === 'me' => $query->whereIn('id', $assignedTo($user->id)),
                $who === 'none' => $query->whereNotIn('id', DB::table('ws_task_assignees')->select('task_id')),
                str_starts_with($who, 'name:') => $query->whereIn('id', DB::table('ws_task_assignees')->whereNull('user_id')->where('person_name', substr($who, 5))->select('task_id')),
                default => $query->whereIn('id', $assignedTo($who)),
            };
        }
        if (! empty($f['tag'])) {
            $query->whereIn('id', DB::table('ws_task_tag_links')->where('tag_id', $f['tag'])->select('task_id'));
        }
        if (! empty($f['origin'])) {
            $f['origin'] === 'ops' ? $query->whereNull('source_system') : $query->where('source_system', $f['origin']);
        }
        $today = Carbon::today();
        if (filter_var($f['overdue'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->where('status', 'open')->where('archived', false)->where('deadline', '<', $today->toDateString());
        }
        match ($f['due'] ?? null) {
            'overdue' => $query->where('deadline', '<', $today->toDateString()),
            'today' => $query->where('deadline', $today->toDateString()),
            'week' => $query->whereBetween('deadline', [$today->toDateString(), $today->copy()->addDays(7)->toDateString()]),
            'none' => $query->whereNull('deadline'),
            default => null,
        };
        Ws::search($query, ['number', 'title', 'ref_number'], $q, function (Builder $w, string $term) {
            if (ctype_digit($term)) {
                $w->orWhere('external_ref', $term); // the id people know the task by in Odoo
            }
        });

        return $query;
    }

    private function order(Builder $query, ?string $sort, string $order): Builder
    {
        if ($sort === 'board') {
            // what needs attention first: the urgent, then the earliest deadline (a task with none comes after), then the newest
            return $query->orderByDesc('priority')->orderByRaw('deadline IS NULL')->orderBy('deadline')->orderByDesc('created_at')->orderByDesc('id');
        }
        $column = self::SORTS[$sort ?? ''] ?? null;
        if ($column === null) {
            return $query->orderByDesc('created_at')->orderByDesc('id');
        }
        if (in_array($column, self::SPARSE, true)) {
            $query->orderByRaw("{$column} IS NULL");
        }

        return $query->orderBy($column, $order)->orderBy('id', $order);
    }

    /** Adds the two figures of the checklist to a query of tasks. */
    private static function counted(Builder $query): Builder
    {
        return $query->withCount(['checklist as checklist_total', 'checklist as checklist_done' => fn (Builder $c) => $c->where('done', true)]);
    }

    private function detail(AuthUser $user, Task $t, ProjectAccess $access): array
    {
        $t = self::counted(Task::with(self::WITH))->findOrFail($t->id);
        $project = $t->project_id ? Project::find($t->project_id) : null;
        $manages = $project ? $access->configuresProject($project) : false;
        $owned = Owned::bySource($t);
        $works = $access->worksTask($t);
        $live = ! $t->archived;
        $removable = fn (WsFile $f) => FilesService::removableBy($user, $f, $manages);

        return Shape::task($t) + [
            'description' => $t->description, 'cancelReason' => $t->cancel_reason, 'doneBy' => $t->done_by, 'extra' => $t->extra ?: null,
            'updatedAt' => Ws::at($t->updated_at), 'updatedBy' => $t->updated_by, 'archivedAt' => Ws::at($t->archived_at), 'archivedBy' => $t->archived_by, 'archiveReason' => $t->archive_reason,
            'checklistItems' => $t->checklist()->get()->map(fn (ChecklistItem $i) => ['id' => $i->id, 'text' => $i->text, 'done' => $i->done, 'doneBy' => $i->done_by, 'doneAt' => Ws::at($i->done_at), 'position' => $i->position])->all(),
            'comments' => $this->conversation->thread($user, 'task', $t->id, $manages),
            'files' => $this->files->of('task', [$t->id], $removable)[(string) $t->id] ?? [],
            'can' => [
                'edit' => ! $owned && $works && $live, 'move' => ! $owned && $works && $live && $t->project_id !== null && $t->status !== 'cancelled',
                'done' => ! $owned && $works && $live && $t->status === 'open', 'reopen' => ! $owned && $works && $live && $t->status !== 'open',
                'cancel' => ! $owned && $works && $live && $t->status === 'open', 'archive' => ! $owned && $works && $live, 'restore' => ! $owned && $works && ! $live,
                'comment' => $live, 'checklist' => ! $owned && $works && $live,
            ],
        ];
    }

    // ───────────── writing ─────────────

    public function create(AuthUser $user, array $dto): array
    {
        $files = $this->files->decode($dto['files'] ?? null);
        $project = ! empty($dto['projectId']) ? $this->projects->find((string) $dto['projectId']) : null;
        if ($project) {
            $access = new ProjectAccess($user);
            $this->projects->mustSee($access, $project);
            if (! $access->worksProject($project)) {
                Ws::deny($user, 'WS_PROJECT_FORBIDDEN', 'يضيف المهام إلى المشروع أعضاؤه أو مديره', 'Tasks are added to a project by its members or its manager');
            }
        }
        $id = DB::transaction(function () use ($user, $dto, $files, $project) {
            $stage = null;
            if ($project) {
                if ($project->archived || $project->status === 'done') {
                    throw AppError::rule('WS_PROJECT_CLOSED', 'المشروع منتهٍ أو مؤرشف — لا تُضاف إليه مهام', 'The project is finished or archived — no task can be added to it');
                }
                $stage = ! empty($dto['stageId']) ? $this->stage((string) $dto['stageId'], (string) $project->id)
                    : TaskStage::where('project_id', $project->id)->where('active', true)->where('is_closing', false)->orderBy('sequence')->orderBy('created_at')->orderBy('id')->first();
                if ($stage?->is_closing) {
                    throw AppError::rule('WS_STAGE_IS_CLOSING', 'لا تُنشأ المهمة في مرحلة إنجاز — أنشئها مفتوحة ثم أنجزها', 'A task is not created in a finishing stage — create it open, then finish it');
                }
            }
            [$refType, $refNumber] = RecordLinks::check($dto['refType'] ?? null, $dto['refNumber'] ?? null);
            $t = Task::create([
                'number' => $this->numbering->next('TSK'), 'project_id' => $project?->id, 'stage_id' => $stage?->id, 'title' => trim((string) $dto['title']), 'description' => Ws::text($dto['description'] ?? null),
                'priority' => (int) ($dto['priority'] ?? 0), 'status' => 'open', 'deadline' => Ws::text($dto['deadline'] ?? null), 'ref_type' => $refType, 'ref_number' => $refNumber,
                'stage_changed_at' => now(), 'created_by' => $user->username, 'created_by_id' => $user->id, 'updated_by' => $user->username,
            ]);
            // a personal task nobody was named for is its maker's own
            $keys = (array) ($dto['assigneeKeys'] ?? []);
            People::sync(TaskAssignee::class, 'task_id', $t->id, $keys || $project ? $keys : [$user->id], 'assigneeKeys');
            if (! empty($dto['tagIds'])) {
                $t->tags()->sync($this->tagIds((array) $dto['tagIds']));
            }
            foreach (array_values(array_filter(array_map(fn ($line) => Ws::text($line, 500), (array) ($dto['checklist'] ?? [])))) as $i => $line) {
                ChecklistItem::create(['task_id' => $t->id, 'text' => $line, 'position' => $i + 1, 'created_by' => $user->username]);
            }
            $this->files->keepAll($user, 'task', $t->id, $files);
            $this->conversation->event('task', $t->id, 'created', $user, ['to' => $stage ? ['ar' => $stage->name_ar, 'en' => $stage->name_en] : null]);
            $this->audit->log($user, ['action' => 'TASK.CREATE', 'entityType' => 'WsTask', 'entityId' => $t->id, 'entityNumber' => $t->number, 'newValue' => array_diff_key($dto, ['files' => 1]) + ['files' => count($files)]]);
            $this->tellAssignees($t, $user, "أُسندت إليك المهمة {$t->number} — {$t->title}", "Task {$t->number} was assigned to you — {$t->title}");

            return $t->id;
        });

        return $this->get($user, $id);
    }

    public function update(AuthUser $user, string $idOrNumber, array $dto): array
    {
        $this->mustWork($user, $this->find($idOrNumber));
        $id = DB::transaction(function () use ($user, $idOrNumber, $dto) {
            $t = $this->locked($idOrNumber);
            Owned::refuse($t, 'task');
            $this->mustBeLive($t);
            $data = [];
            if (array_key_exists('title', $dto) && Ws::text($dto['title']) !== null) {
                $data['title'] = Ws::text($dto['title'], 250);
            }
            if (array_key_exists('description', $dto)) {
                $data['description'] = Ws::text($dto['description']);
            }
            if (array_key_exists('priority', $dto) && $dto['priority'] !== null) {
                $data['priority'] = (int) $dto['priority'];
            }
            if (array_key_exists('deadline', $dto)) {
                $data['deadline'] = Ws::text($dto['deadline']);
            }
            if (array_key_exists('refType', $dto) || array_key_exists('refNumber', $dto)) {
                [$data['ref_type'], $data['ref_number']] = RecordLinks::check(array_key_exists('refType', $dto) ? $dto['refType'] : $t->ref_type, array_key_exists('refNumber', $dto) ? $dto['refNumber'] : $t->ref_number);
            }
            [$old, $new] = Ws::diff($t, $data);
            $fields = self::fieldNames(array_keys($new));
            if (array_key_exists('tagIds', $dto)) {
                $tags = collect($this->tagIds((array) $dto['tagIds']))->sort()->values()->all();
                $before = $t->tags()->pluck('ws_task_tags.id')->map(fn ($x) => (string) $x)->sort()->values()->all();
                if ($before !== $tags) {
                    $t->tags()->sync($tags);
                    [$old['tags'], $new['tags']] = [$before, $tags];
                    $fields[] = ['ar' => 'الوسوم', 'en' => 'Tags'];
                }
            }
            $assigned = null;
            if (array_key_exists('assigneeKeys', $dto)) {
                $before = People::names(TaskAssignee::class, 'task_id', $t->id);
                $known = TaskAssignee::where('task_id', $t->id)->whereNotNull('user_id')->pluck('user_id')->map(fn ($x) => (string) $x)->all();
                if (People::sync(TaskAssignee::class, 'task_id', $t->id, (array) $dto['assigneeKeys'], 'assigneeKeys')) {
                    $after = People::names(TaskAssignee::class, 'task_id', $t->id);
                    [$old['assignees'], $new['assignees']] = [$before, $after];
                    $assigned = ['from' => implode('، ', $before) ?: null, 'to' => implode('، ', $after) ?: null];
                    $this->tellAssignees($t, $user, "أُسندت إليك المهمة {$t->number} — {$t->title}", "Task {$t->number} was assigned to you — {$t->title}", $known);
                }
            }
            if (! $new) {
                return $t->id;
            }
            $t->update(array_diff_key($new, ['tags' => 1, 'assignees' => 1]) + ['updated_by' => $user->username]);
            if ($fields) {
                $this->conversation->event('task', $t->id, 'edited', $user, ['fields' => $fields]);
            }
            if ($assigned) {
                $this->conversation->event('task', $t->id, 'assigned', $user, $assigned);
            }
            $this->audit->log($user, ['action' => 'TASK.UPDATE', 'entityType' => 'WsTask', 'entityId' => $t->id, 'entityNumber' => $t->number, 'oldValue' => $old, 'newValue' => $new]);

            return $t->id;
        });

        return $this->get($user, $id);
    }

    /** To another column of the project's board. Entering a closing stage finishes the task; leaving one reopens it. */
    public function move(AuthUser $user, string $idOrNumber, string $stageId): array
    {
        $this->mustWork($user, $this->find($idOrNumber));
        $id = DB::transaction(function () use ($user, $idOrNumber, $stageId) {
            $t = $this->locked($idOrNumber);
            Owned::refuse($t, 'task');
            $this->mustBeLive($t);
            if ($t->project_id === null) {
                throw AppError::rule('WS_STAGE_NOT_OF_PROJECT', 'المهمة الشخصية بلا مراحل — أنجزها أو ألغها مباشرة', 'A personal task has no stages — finish or cancel it directly');
            }
            if ($t->status === 'cancelled') {
                throw AppError::rule('WS_TASK_TRANSITION', 'المهمة ملغاة — أعد فتحها قبل نقلها', 'The task is cancelled — reopen it before moving it');
            }
            $to = $this->stage($stageId, (string) $t->project_id);
            if ($to->id === $t->stage_id) {
                return $t->id; // it is there already
            }
            $from = $t->stage_id ? TaskStage::find($t->stage_id) : null;
            $status = $to->is_closing ? 'done' : 'open';
            $changes = ['stage_id' => $to->id, 'stage_changed_at' => now(), 'updated_by' => $user->username];
            if ($status !== $t->status) {
                $changes += ['status' => $status, 'done_at' => $status === 'done' ? now() : null, 'done_by' => $status === 'done' ? $user->username : null];
                $this->audit->status($user, 'WsTask', $t->id, $t->number, $t->status, $status, "stage: {$to->name_en}");
            }
            $t->update($changes);
            $this->conversation->event('task', $t->id, 'moved', $user, ['from' => $from ? ['ar' => $from->name_ar, 'en' => $from->name_en] : null, 'to' => ['ar' => $to->name_ar, 'en' => $to->name_en]]);
            $this->audit->log($user, ['action' => 'TASK.MOVE', 'entityType' => 'WsTask', 'entityId' => $t->id, 'entityNumber' => $t->number, 'field' => 'stage', 'oldValue' => $from?->name_en, 'newValue' => $to->name_en]);

            return $t->id;
        });

        return $this->get($user, $id);
    }

    /** Finishes the task; on a board it enters the project's first closing stage. */
    public function done(AuthUser $user, string $idOrNumber): array
    {
        return $this->turn($user, $idOrNumber, 'done', null, null);
    }

    /** Opens a finished or cancelled task again; on a board it goes back to an open stage. */
    public function reopen(AuthUser $user, string $idOrNumber, ?string $stageId = null): array
    {
        return $this->turn($user, $idOrNumber, 'open', $stageId, null);
    }

    public function cancel(AuthUser $user, string $idOrNumber, string $reason): array
    {
        return $this->turn($user, $idOrNumber, 'cancelled', null, $reason);
    }

    private function turn(AuthUser $user, string $idOrNumber, string $status, ?string $stageId, ?string $reason): array
    {
        $this->mustWork($user, $this->find($idOrNumber));
        $id = DB::transaction(function () use ($user, $idOrNumber, $status, $stageId, $reason) {
            $t = $this->locked($idOrNumber);
            Owned::refuse($t, 'task');
            $this->mustBeLive($t);
            Ws::transition(Task::TRANSITIONS, $t->status, $status, 'WS_TASK_TRANSITION');
            $from = $t->status;
            $changes = ['status' => $status, 'updated_by' => $user->username,
                'done_at' => $status === 'done' ? now() : null, 'done_by' => $status === 'done' ? $user->username : null,
                'cancelled_at' => $status === 'cancelled' ? now() : null, 'cancelled_by' => $status === 'cancelled' ? $user->username : null, 'cancel_reason' => $status === 'cancelled' ? Ws::text($reason, 1000) : null];
            $to = null;
            if ($t->project_id !== null && $status !== 'cancelled') {
                // the stage follows the state: a finished task stands in a closing stage, an open one in an open stage
                $current = $t->stage_id ? TaskStage::find($t->stage_id) : null;
                $closing = $status === 'done';
                if ($stageId) {
                    $to = $this->stage($stageId, (string) $t->project_id);
                    if ($to->is_closing !== $closing) {
                        throw AppError::rule($closing ? 'WS_STAGE_NOT_CLOSING' : 'WS_STAGE_IS_CLOSING', 'المرحلة المختارة لا توافق حالة المهمة', 'The chosen stage does not fit the state of the task');
                    }
                } elseif (! $current || $current->is_closing !== $closing) {
                    $to = TaskStage::where('project_id', $t->project_id)->where('active', true)->where('is_closing', $closing)->orderBy('sequence')->orderBy('created_at')->orderBy('id')->first();
                }
                if ($to && $to->id !== $t->stage_id) {
                    $changes += ['stage_id' => $to->id, 'stage_changed_at' => now()];
                }
            }
            $t->update($changes);
            $event = ['done' => 'done', 'open' => 'reopened', 'cancelled' => 'cancelled'][$status];
            $this->conversation->event('task', $t->id, $event, $user, ['to' => $to ? ['ar' => $to->name_ar, 'en' => $to->name_en] : null, 'reason' => Ws::text($reason, 1000)]);
            $this->audit->status($user, 'WsTask', $t->id, $t->number, $from, $status, Ws::text($reason, 500));
            if ($status === 'done' && $t->created_by_id) {
                People::tell((string) $t->created_by_id, $user, 'WsTask', $t->id, $t->number, "أُنجزت المهمة {$t->number} — {$t->title}", "Task {$t->number} is done — {$t->title}");
            }

            return $t->id;
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
        $this->mustWork($user, $this->find($idOrNumber));
        $id = DB::transaction(function () use ($user, $idOrNumber, $away, $reason) {
            $t = $this->locked($idOrNumber);
            Owned::refuse($t, 'task');
            if ($t->archived === $away) {
                return $t->id;
            }
            $reason = Ws::text($reason, 1000);
            $t->update(['archived' => $away, 'archived_at' => $away ? now() : null, 'archived_by' => $away ? $user->username : null, 'archive_reason' => $away ? $reason : null, 'updated_by' => $user->username]);
            $this->conversation->event('task', $t->id, $away ? 'archived' : 'restored', $user, ['reason' => $reason]);
            $this->audit->log($user, ['action' => $away ? 'TASK.ARCHIVE' : 'TASK.RESTORE', 'entityType' => 'WsTask', 'entityId' => $t->id, 'entityNumber' => $t->number, 'newValue' => array_filter(['reason' => $reason])]);

            return $t->id;
        });

        return $this->get($user, $id);
    }

    /**
     * A comment with files, by anybody who sees the task. Allowed on a task Odoo owns too: what is written here is OPS's own.
     *
     * @param  array<int, array>|null  $uploads
     */
    public function comment(AuthUser $user, string $idOrNumber, ?string $body, ?array $uploads): array
    {
        $this->mustSee(new ProjectAccess($user), $this->find($idOrNumber));
        $files = $this->files->decode($uploads);
        if (Ws::text($body) === null && ! $files) {
            throw AppError::validation('WS_MESSAGE_EMPTY', 'اكتب نصًّا أو أرفق ملفًّا', 'Write something or attach a file', [['path' => 'body', 'message' => 'التعليق فارغ']]);
        }
        $id = DB::transaction(function () use ($user, $idOrNumber, $body, $files) {
            $t = $this->locked($idOrNumber);
            $this->mustBeLive($t);
            $message = $this->conversation->post($user, 'task', $t->id, 'comment', $body, $files);
            $t->timestamps = false; // a comment is not an edit of the task
            $t->update(['comment_count' => $t->comment_count + 1]);
            $this->audit->log($user, ['action' => 'TASK.COMMENT', 'entityType' => 'WsTask', 'entityId' => $t->id, 'entityNumber' => $t->number, 'newValue' => ['messageId' => $message->id, 'files' => count($files)]]);
            $this->tellAssignees($t, $user, "تعليق جديد على المهمة {$t->number} — {$t->title}", "A new comment on task {$t->number} — {$t->title}");
            if ($t->created_by_id && ! TaskAssignee::where('task_id', $t->id)->where('user_id', $t->created_by_id)->exists()) {
                People::tell((string) $t->created_by_id, $user, 'WsTask', $t->id, $t->number, "تعليق جديد على المهمة {$t->number} — {$t->title}", "A new comment on task {$t->number} — {$t->title}");
            }

            return $t->id;
        });

        return $this->get($user, $id);
    }

    // ───────────── the checklist ─────────────

    public function checklistAdd(AuthUser $user, string $idOrNumber, string $text): array
    {
        return $this->checklist($user, $idOrNumber, function (Task $t) use ($user, $text) {
            $item = ChecklistItem::create(['task_id' => $t->id, 'text' => Ws::text($text, 500), 'position' => ((int) ChecklistItem::where('task_id', $t->id)->max('position')) + 1, 'created_by' => $user->username]);

            return ['action' => 'TASK.CHECKLIST.ADD', 'newValue' => ['item' => $item->id, 'text' => $item->text]];
        });
    }

    /** @param  array{text?:?string, done?:?bool}  $dto */
    public function checklistUpdate(AuthUser $user, string $idOrNumber, string $itemId, array $dto): array
    {
        return $this->checklist($user, $idOrNumber, function (Task $t) use ($user, $itemId, $dto) {
            $item = $this->item($t, $itemId);
            $values = [];
            if (array_key_exists('text', $dto) && Ws::text($dto['text']) !== null) {
                $values['text'] = Ws::text($dto['text'], 500);
            }
            if (array_key_exists('done', $dto) && $dto['done'] !== null && (bool) $dto['done'] !== $item->done) {
                $values += ['done' => (bool) $dto['done'], 'done_by' => $dto['done'] ? $user->username : null, 'done_at' => $dto['done'] ? now() : null];
            }
            [$old, $new] = Ws::diff($item, $values);
            if (! $new) {
                return null;
            }
            $item->update($new);

            return ['action' => 'TASK.CHECKLIST.UPDATE', 'oldValue' => array_intersect_key($old, ['text' => 1, 'done' => 1]), 'newValue' => ['item' => $item->id] + array_intersect_key($new, ['text' => 1, 'done' => 1])];
        });
    }

    /** A line of the checklist is part of what the task says: it is taken out, and the audit trail keeps what it said. */
    public function checklistRemove(AuthUser $user, string $idOrNumber, string $itemId): array
    {
        return $this->checklist($user, $idOrNumber, function (Task $t) use ($itemId) {
            $item = $this->item($t, $itemId);
            $was = ['item' => $item->id, 'text' => $item->text, 'done' => $item->done];
            $item->delete();

            return ['action' => 'TASK.CHECKLIST.REMOVE', 'oldValue' => $was];
        });
    }

    /** @param  callable(Task): ?array  $change  does the change and returns its audit entry (null = nothing changed) */
    private function checklist(AuthUser $user, string $idOrNumber, callable $change): array
    {
        $this->mustWork($user, $this->find($idOrNumber));
        $id = DB::transaction(function () use ($user, $idOrNumber, $change) {
            $t = $this->locked($idOrNumber);
            Owned::refuse($t, 'task');
            $this->mustBeLive($t);
            if ($entry = $change($t)) {
                $this->audit->log($user, $entry + ['entityType' => 'WsTask', 'entityId' => $t->id, 'entityNumber' => $t->number]);
            }

            return $t->id;
        });

        return $this->get($user, $id);
    }

    private function item(Task $t, string $itemId): ChecklistItem
    {
        return ChecklistItem::where('task_id', $t->id)->where('id', $itemId)->first()
            ?? throw AppError::notFound('WS_CHECKLIST_NOT_FOUND', 'بند القائمة غير موجود في هذه المهمة', 'The checklist line is not on this task');
    }

    // ───────────── helpers ─────────────

    public function find(string $idOrNumber): Task
    {
        return Task::where('id', $idOrNumber)->orWhere('number', $idOrNumber)->first() ?? throw AppError::notFound('WS_TASK_NOT_FOUND', 'المهمة غير موجودة', 'Task not found');
    }

    private function locked(string $idOrNumber): Task
    {
        return Task::where(fn (Builder $w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->lockForUpdate()->first()
            ?? throw AppError::notFound('WS_TASK_NOT_FOUND', 'المهمة غير موجودة', 'Task not found');
    }

    private function mustSee(ProjectAccess $access, Task $t): void
    {
        if (! $access->seesTask($t)) {
            Ws::deny($access->user, 'WS_TASK_FORBIDDEN', 'لا تملك صلاحية عرض هذه المهمة', 'You may not see this task');
        }
    }

    private function mustWork(AuthUser $user, Task $t): void
    {
        $access = new ProjectAccess($user);
        $this->mustSee($access, $t);
        if (! $access->worksTask($t)) {
            Ws::deny($user, 'WS_TASK_FORBIDDEN', 'يعمل على المهمة أعضاء مشروعها أو من أُسندت إليه', 'A task is worked by the members of its project or by whoever it was given to');
        }
    }

    private function mustBeLive(Task $t): void
    {
        if ($t->archived) {
            throw AppError::rule('WS_ARCHIVED', 'المهمة مؤرشفة — استعدها أولًا', 'The task is archived — restore it first');
        }
    }

    /** An active stage of this project. */
    private function stage(string $id, string $projectId): TaskStage
    {
        $stage = TaskStage::where('id', $id)->where('active', true)->first() ?? throw AppError::notFound('WS_STAGE_NOT_FOUND', 'المرحلة غير موجودة أو متوقفة', 'The stage does not exist or is not active');
        if ((string) $stage->project_id !== $projectId) {
            throw AppError::rule('WS_STAGE_NOT_OF_PROJECT', 'هذه المرحلة ليست من مراحل مشروع المهمة', 'This stage is not one of the task\'s project');
        }

        return $stage;
    }

    /** @param  array<int, mixed>  $ids  @return string[] the ids of tags that exist */
    private function tagIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));
        $known = TaskTag::whereIn('id', $ids)->pluck('id')->map(fn ($x) => (string) $x)->all();
        if (count($known) !== count($ids)) {
            throw AppError::validation('WS_TAG_UNKNOWN', 'أحد الوسوم المختارة غير موجود', 'One of the chosen tags does not exist', [['path' => 'tagIds', 'message' => 'وسم غير موجود']]);
        }

        return $known;
    }

    /** @param  string[]  $skip  user ids that are not told again */
    private function tellAssignees(Task $t, AuthUser $by, string $ar, string $en, array $skip = []): void
    {
        foreach (TaskAssignee::where('task_id', $t->id)->whereNotNull('user_id')->pluck('user_id') as $userId) {
            if (! in_array((string) $userId, $skip, true)) {
                People::tell((string) $userId, $by, 'WsTask', $t->id, $t->number, $ar, $en);
            }
        }
    }

    /** Columns that changed → what people call them, for the line of the thread. @return array<int, array{ar:string, en:string}> */
    private static function fieldNames(array $columns): array
    {
        $names = ['title' => ['العنوان', 'Title'], 'description' => ['الوصف', 'Description'], 'priority' => ['الأولوية', 'Priority'], 'deadline' => ['الموعد النهائي', 'Deadline'],
            'ref_type' => ['السجل المرتبط', 'Linked record'], 'ref_number' => ['السجل المرتبط', 'Linked record']];
        $out = [];
        foreach ($columns as $column) {
            if (isset($names[$column])) {
                $out[$names[$column][1]] = ['ar' => $names[$column][0], 'en' => $names[$column][1]];
            }
        }

        return array_values($out);
    }
}
