<?php

namespace App\Workspace\Http;

use App\Http\Controllers\Controller;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Workspace\Models\Project;
use App\Workspace\Models\Task;
use App\Workspace\Services\ProjectsService;
use App\Workspace\Services\TasksService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Projects: the list, one project with its board's columns, and what its manager does to it — details, members,
 * stages, status. Creating a project and keeping the task tags need `project.manage` (on the routes); everything else
 * is decided by the service for the project in hand.
 */
class ProjectsController extends Controller
{
    private const DAY = 'date_format:Y-m-d';

    public function __construct(private readonly ProjectsService $projects, private readonly TasksService $tasks) {}

    public function index(Request $request): array
    {
        $rules = ['state' => ['nullable', Rule::in(ProjectsService::STATES)], 'mine' => 'nullable|boolean'];
        $filters = array_intersect_key($request->validate($rules + TicketsController::listRules()), $rules);

        return $this->projects->list(AuthUser::current(), Paging::from($request, 25), $filters);
    }

    public function show(string $id): array
    {
        return $this->projects->get(AuthUser::current(), $id);
    }

    public function board(Request $request, string $id): array
    {
        $rules = ['assignee' => 'nullable|string|max:200', 'tag' => 'nullable|string|max:40', 'priority' => 'nullable|integer|min:0|max:'.Task::MAX_PRIORITY];
        $data = $request->validate($rules + ['q' => 'nullable|string|max:120', 'per' => 'sometimes|integer|min:1|max:50']);
        $q = trim((string) ($data['q'] ?? ''));

        return $this->tasks->board(AuthUser::current(), $id, array_intersect_key($data, $rules), $q === '' ? null : $q, (int) ($data['per'] ?? 20));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|min:1|max:250', 'memberKeys' => 'sometimes|array|max:200', 'memberKeys.*' => 'string|max:200',
            'stages' => 'sometimes|array|min:1|max:30', 'stages.*.nameAr' => 'required|string|min:1|max:120', 'stages.*.nameEn' => 'nullable|string|max:120', 'stages.*.isClosing' => 'nullable|boolean'] + self::fieldRules());

        return response()->json($this->projects->create(AuthUser::current(), $data), 201);
    }

    public function update(Request $request, string $id): array
    {
        return $this->projects->update(AuthUser::current(), $id, $request->validate(['name' => 'sometimes|string|min:1|max:250'] + self::fieldRules()));
    }

    public function members(Request $request, string $id): array
    {
        $data = $request->validate(['memberKeys' => 'present|array|max:200', 'memberKeys.*' => 'string|max:200']);

        return $this->projects->members(AuthUser::current(), $id, $data['memberKeys'] ?? []);
    }

    public function status(Request $request, string $id): array
    {
        $data = $request->validate(['status' => ['required', Rule::in(Project::STATUSES)], 'note' => 'nullable|string|max:500']);

        return $this->projects->status(AuthUser::current(), $id, $data['status'], $data['note'] ?? null);
    }

    public function archive(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'nullable|string|max:1000']);

        return $this->projects->archive(AuthUser::current(), $id, $data['reason'] ?? null);
    }

    public function restore(string $id): array
    {
        return $this->projects->restore(AuthUser::current(), $id);
    }

    public function storeStage(Request $request, string $id): JsonResponse
    {
        return response()->json($this->projects->storeStage(AuthUser::current(), $id, $request->validate(['nameAr' => 'required|string|min:1|max:120'] + self::stageRules())), 201);
    }

    public function updateStage(Request $request, string $id): array
    {
        return $this->projects->updateStage(AuthUser::current(), $id, $request->validate(['nameAr' => 'sometimes|string|min:1|max:120', 'active' => 'sometimes|nullable|boolean'] + self::stageRules()));
    }

    // ── task tags (project.manage)

    public function storeTag(Request $request): JsonResponse
    {
        return response()->json($this->projects->storeTag(AuthUser::current(), $request->validate(['name' => 'required|string|min:1|max:80', 'color' => 'sometimes|nullable|integer|min:0|max:11'])), 201);
    }

    public function updateTag(Request $request, string $id): array
    {
        return $this->projects->updateTag(AuthUser::current(), $id, $request->validate(['name' => 'sometimes|string|min:1|max:80', 'color' => 'sometimes|nullable|integer|min:0|max:11', 'active' => 'sometimes|nullable|boolean']));
    }

    private static function fieldRules(): array
    {
        return [
            'description' => 'sometimes|nullable|string|max:20000', 'managerId' => 'sometimes|nullable|string|max:60', 'visibility' => ['sometimes', 'nullable', Rule::in(Project::VISIBILITY)],
            'startDate' => ['sometimes', 'nullable', self::DAY], 'endDate' => ['sometimes', 'nullable', self::DAY],
        ];
    }

    private static function stageRules(): array
    {
        return ['nameEn' => 'sometimes|nullable|string|max:120', 'sequence' => 'sometimes|nullable|integer|min:0|max:100000', 'isClosing' => 'sometimes|nullable|boolean'];
    }
}
