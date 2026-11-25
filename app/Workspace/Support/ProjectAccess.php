<?php

namespace App\Workspace\Support;

use App\Support\AuthUser;
use App\Workspace\Models\Project;
use App\Workspace\Models\ProjectMember;
use App\Workspace\Models\Task;
use App\Workspace\Models\TaskAssignee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who sees a project and its tasks, and who works them.
 *
 *   A PROJECT is seen by the projects manager (`project.manage`), by everybody who opens the section when it is open
 *   (`visibility = all`), otherwise by its manager and its members. It is WORKED — tasks added, changed, moved,
 *   finished — by its manager, its members and the projects manager; it is CONFIGURED (details, members, stages,
 *   status) by its manager and the projects manager.
 *   A TASK of a project follows its project; whoever made it or was given it always sees and works it.
 *   A PERSONAL task (no project) belongs to whoever made it and whoever it was given to — nobody else, not even the
 *   projects manager, sees it.
 */
final class ProjectAccess
{
    /** @var string[]|null */
    private ?array $memberProjects = null;

    /** @var string[]|null */
    private ?array $visibleProjects = null;

    public function __construct(public readonly AuthUser $user) {}

    public function manages(): bool
    {
        return $this->user->can('project.manage');
    }

    /** @return string[] the projects the user manages or is a member of */
    public function memberProjectIds(): array
    {
        return $this->memberProjects ??= array_values(array_unique(array_merge(
            ProjectMember::where('user_id', $this->user->id)->pluck('project_id')->map(fn ($id) => (string) $id)->all(),
            Project::where('manager_id', $this->user->id)->pluck('id')->map(fn ($id) => (string) $id)->all())));
    }

    /** @return string[] the projects the user may see (for the projects manager: every project) */
    public function visibleProjectIds(): array
    {
        return $this->visibleProjects ??= $this->manages()
            ? Project::query()->pluck('id')->map(fn ($id) => (string) $id)->all()
            : array_values(array_unique(array_merge(Project::where('visibility', 'all')->pluck('id')->map(fn ($id) => (string) $id)->all(), $this->memberProjectIds())));
    }

    public function scopeProjects(Builder $query): Builder
    {
        return $this->manages() ? $query : $query->whereIn('id', $this->visibleProjectIds());
    }

    public function seesProject(Project $p): bool
    {
        return $this->manages() || $p->visibility === 'all' || in_array((string) $p->id, $this->memberProjectIds(), true);
    }

    public function worksProject(Project $p): bool
    {
        return $this->manages() || in_array((string) $p->id, $this->memberProjectIds(), true);
    }

    public function configuresProject(Project $p): bool
    {
        return $this->manages() || $this->same($p->manager_id);
    }

    /** Narrows a query of tasks to the ones the user may see. */
    public function scopeTasks(Builder $query): Builder
    {
        $me = $this->user->id;
        $mine = fn (Builder $w) => $w->orWhere('created_by_id', $me)->orWhereIn('id', DB::table('ws_task_assignees')->where('user_id', $me)->select('task_id'));

        return $query->where(function (Builder $w) use ($mine) {
            $this->manages() ? $w->whereNotNull('project_id') : $w->whereIn('project_id', $this->visibleProjectIds());
            $mine($w);
        });
    }

    public function seesTask(Task $t): bool
    {
        return ($t->project_id !== null && in_array((string) $t->project_id, $this->visibleProjectIds(), true)) || $this->named($t);
    }

    public function worksTask(Task $t): bool
    {
        return ($t->project_id !== null && ($this->manages() || in_array((string) $t->project_id, $this->memberProjectIds(), true))) || $this->named($t);
    }

    /** The task is the user's own: he made it or it was given to him. */
    public function named(Task $t): bool
    {
        return $this->same($t->created_by_id) || TaskAssignee::where('task_id', $t->id)->where('user_id', $this->user->id)->exists();
    }

    private function same(?string $userId): bool
    {
        return $userId !== null && strcasecmp($userId, $this->user->id) === 0;
    }
}
