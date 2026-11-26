<?php

namespace App\Workspace\Http;

use App\Http\Controllers\Controller;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Workspace\Models\Task;
use App\Workspace\Services\FilesService;
use App\Workspace\Services\ProjectsService;
use App\Workspace\Services\TasksService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tasks: the lists (mine, a project's, all I may see), one task with its checklist and its comments, and what happens
 * to it. No permission beyond opening the section sits on these routes: the service decides for the task in hand —
 * its project's people, or for a personal task its maker and its assignees.
 */
class TasksController extends Controller
{
    private const DAY = 'date_format:Y-m-d';

    public function __construct(private readonly TasksService $tasks, private readonly ProjectsService $projects) {}

    public function lookups(): array
    {
        return $this->projects->lookups(AuthUser::current());
    }

    public function index(Request $request): array
    {
        $rules = [
            'scope' => ['nullable', Rule::in(TasksService::SCOPES)], 'project' => 'nullable|string|max:40', 'stage' => 'nullable|string|max:40', 'state' => ['nullable', Rule::in(TasksService::STATES)],
            'assignee' => 'nullable|string|max:200', 'tag' => 'nullable|string|max:40', 'priority' => 'nullable|integer|min:0|max:'.Task::MAX_PRIORITY, 'overdue' => 'nullable|boolean',
            'due' => ['nullable', Rule::in(TasksService::DUE)], 'origin' => 'nullable|in:ops,odoo',
        ];
        $filters = array_intersect_key($request->validate($rules + TicketsController::listRules()), $rules);

        return $this->tasks->list(AuthUser::current(), Paging::from($request, 25), $filters);
    }

    public function show(string $id): array
    {
        return $this->tasks->get(AuthUser::current(), $id);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['title' => 'required|string|min:1|max:250', 'projectId' => 'nullable|string|max:40', 'stageId' => 'nullable|string|max:40',
            'checklist' => 'sometimes|array|max:100', 'checklist.*' => 'nullable|string|max:500'] + self::fieldRules() + FilesService::listRules());

        return response()->json($this->tasks->create(AuthUser::current(), $data), 201);
    }

    public function update(Request $request, string $id): array
    {
        return $this->tasks->update(AuthUser::current(), $id, $request->validate(['title' => 'sometimes|string|min:1|max:250'] + self::fieldRules()));
    }

    public function move(Request $request, string $id): array
    {
        $data = $request->validate(['stageId' => 'required|string|max:40']);

        return $this->tasks->move(AuthUser::current(), $id, $data['stageId']);
    }

    public function done(string $id): array
    {
        return $this->tasks->done(AuthUser::current(), $id);
    }

    public function reopen(Request $request, string $id): array
    {
        $data = $request->validate(['stageId' => 'nullable|string|max:40']);

        return $this->tasks->reopen(AuthUser::current(), $id, $data['stageId'] ?? null);
    }

    public function cancel(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'required|string|min:2|max:1000']);

        return $this->tasks->cancel(AuthUser::current(), $id, $data['reason']);
    }

    public function archive(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'nullable|string|max:1000']);

        return $this->tasks->archive(AuthUser::current(), $id, $data['reason'] ?? null);
    }

    public function restore(string $id): array
    {
        return $this->tasks->restore(AuthUser::current(), $id);
    }

    public function comment(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['body' => 'nullable|string|max:20000'] + FilesService::listRules());

        return response()->json($this->tasks->comment(AuthUser::current(), $id, $data['body'] ?? null, $data['files'] ?? null), 201);
    }

    public function checklistAdd(Request $request, string $id): array
    {
        $data = $request->validate(['text' => 'required|string|min:1|max:500']);

        return $this->tasks->checklistAdd(AuthUser::current(), $id, $data['text']);
    }

    public function checklistUpdate(Request $request, string $id, string $item): array
    {
        return $this->tasks->checklistUpdate(AuthUser::current(), $id, $item, $request->validate(['text' => 'sometimes|string|min:1|max:500', 'done' => 'sometimes|boolean']));
    }

    public function checklistRemove(string $id, string $item): array
    {
        return $this->tasks->checklistRemove(AuthUser::current(), $id, $item);
    }

    /** What the form of a task may carry besides its title. Every key is optional; in an edit an empty value clears. */
    private static function fieldRules(): array
    {
        return [
            'description' => 'sometimes|nullable|string|max:20000', 'assigneeKeys' => 'sometimes|array|max:50', 'assigneeKeys.*' => 'string|max:200',
            'deadline' => ['sometimes', 'nullable', self::DAY], 'priority' => 'sometimes|nullable|integer|min:0|max:'.Task::MAX_PRIORITY,
            'tagIds' => 'sometimes|array|max:30', 'tagIds.*' => 'string|max:40', 'refType' => 'sometimes|nullable|string|max:30', 'refNumber' => 'sometimes|nullable|string|max:80',
        ];
    }
}
