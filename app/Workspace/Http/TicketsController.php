<?php

namespace App\Workspace\Http;

use App\Http\Controllers\Controller;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Workspace\Models\Ticket;
use App\Workspace\Models\TicketTeam;
use App\Workspace\Services\FilesService;
use App\Workspace\Services\TicketConfigService;
use App\Workspace\Services\TicketsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The help desk: the list, one ticket with its thread, what happens to it — and what the desk is made of (teams,
 * stages, types; `ticket.manage` on those routes). Thin: validate (camelCase keys, exactly as the client sends them),
 * call a service, return.
 */
class TicketsController extends Controller
{
    public function __construct(private readonly TicketsService $tickets, private readonly TicketConfigService $config) {}

    public function lookups(): array
    {
        return $this->config->lookups(AuthUser::current());
    }

    public function index(Request $request): array
    {
        $rules = [
            'scope' => ['nullable', Rule::in(TicketsService::SCOPES)], 'state' => ['nullable', Rule::in(TicketsService::STATES)], 'team' => 'nullable|string|max:40', 'stage' => 'nullable|string|max:40',
            'type' => 'nullable|string|max:40', 'priority' => 'nullable|integer|min:0|max:'.Ticket::MAX_PRIORITY, 'assignee' => 'nullable|string|max:200', 'customer' => 'nullable|string|max:60',
            'overdue' => 'nullable|boolean', 'origin' => 'nullable|in:ops,odoo',
        ];
        $filters = array_intersect_key($request->validate($rules + self::listRules()), $rules);

        return $this->tickets->list(AuthUser::current(), Paging::from($request, 25), $filters);
    }

    public function show(string $id): array
    {
        return $this->tickets->get(AuthUser::current(), $id);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['subject' => 'required|string|min:1|max:250', 'teamId' => 'required|string|max:40', 'assigneeId' => 'nullable|string|max:60'] + self::fieldRules() + FilesService::listRules());

        return response()->json($this->tickets->create(AuthUser::current(), $data), 201);
    }

    public function update(Request $request, string $id): array
    {
        return $this->tickets->update(AuthUser::current(), $id, $request->validate(['subject' => 'sometimes|string|min:1|max:250', 'teamId' => 'sometimes|string|max:40', 'dueAt' => 'sometimes|nullable|date'] + self::fieldRules()));
    }

    public function assign(Request $request, string $id): array
    {
        $data = $request->validate(['assigneeId' => 'nullable|string|max:60']);

        return $this->tickets->assign(AuthUser::current(), $id, $data['assigneeId'] ?? null);
    }

    public function move(Request $request, string $id): array
    {
        $data = $request->validate(['stageId' => 'required|string|max:40']);

        return $this->tickets->move(AuthUser::current(), $id, $data['stageId']);
    }

    public function close(Request $request, string $id): array
    {
        $data = $request->validate(['resolution' => 'required|string|min:2|max:5000', 'stageId' => 'nullable|string|max:40']);

        return $this->tickets->close(AuthUser::current(), $id, $data['resolution'], $data['stageId'] ?? null);
    }

    public function reopen(Request $request, string $id): array
    {
        $data = $request->validate(['stageId' => 'nullable|string|max:40', 'note' => 'nullable|string|max:1000']);

        return $this->tickets->reopen(AuthUser::current(), $id, $data['stageId'] ?? null, $data['note'] ?? null);
    }

    public function archive(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'nullable|string|max:1000']);

        return $this->tickets->archive(AuthUser::current(), $id, $data['reason'] ?? null);
    }

    public function restore(string $id): array
    {
        return $this->tickets->restore(AuthUser::current(), $id);
    }

    public function message(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['kind' => 'required|in:reply,note', 'body' => 'nullable|string|max:20000'] + FilesService::listRules());

        return response()->json($this->tickets->message(AuthUser::current(), $id, $data['kind'], $data['body'] ?? null, $data['files'] ?? null), 201);
    }

    // ── what the desk is made of (ticket.manage)

    public function storeTeam(Request $request): JsonResponse
    {
        return response()->json($this->config->storeTeam(AuthUser::current(), $request->validate(['nameAr' => 'required|string|min:1|max:120'] + self::teamRules())), 201);
    }

    public function updateTeam(Request $request, string $id): array
    {
        return $this->config->updateTeam(AuthUser::current(), $id, $request->validate(['nameAr' => 'sometimes|string|min:1|max:120', 'active' => 'sometimes|boolean'] + self::teamRules()));
    }

    public function storeStage(Request $request): JsonResponse
    {
        return response()->json($this->config->storeStage(AuthUser::current(), $request->validate(['nameAr' => 'required|string|min:1|max:120'] + self::stageRules())), 201);
    }

    public function updateStage(Request $request, string $id): array
    {
        return $this->config->updateStage(AuthUser::current(), $id, $request->validate(['nameAr' => 'sometimes|string|min:1|max:120', 'active' => 'sometimes|boolean'] + self::stageRules()));
    }

    public function storeType(Request $request): JsonResponse
    {
        return response()->json($this->config->storeType(AuthUser::current(), $request->validate(['nameAr' => 'required|string|min:1|max:120'] + self::dictionaryRules())), 201);
    }

    public function updateType(Request $request, string $id): array
    {
        return $this->config->updateType(AuthUser::current(), $id, $request->validate(['nameAr' => 'sometimes|string|min:1|max:120', 'active' => 'sometimes|boolean'] + self::dictionaryRules()));
    }

    // ── rules

    /** Paging, search and sort of a list: read by Paging, checked here. */
    public static function listRules(): array
    {
        return ['page' => 'sometimes|integer|min:1', 'pageSize' => 'sometimes|integer|min:1|max:200', 'q' => 'nullable|string|max:120', 'sort' => 'nullable|string|max:30', 'order' => 'sometimes|in:asc,desc'];
    }

    /** What the form of a ticket may carry besides its subject and team. Every key is optional; in an edit an empty value clears. */
    private static function fieldRules(): array
    {
        return [
            'description' => 'sometimes|nullable|string|max:20000', 'typeId' => 'sometimes|nullable|string|max:40', 'priority' => 'sometimes|nullable|integer|min:0|max:'.Ticket::MAX_PRIORITY,
            'requesterKind' => ['sometimes', 'nullable', Rule::in(Ticket::REQUESTER_KINDS)], 'customerId' => 'sometimes|nullable|string|max:60', 'requesterUserId' => 'sometimes|nullable|string|max:60',
            'requesterName' => 'sometimes|nullable|string|max:190', 'requesterEmail' => 'sometimes|nullable|email|max:190', 'requesterPhone' => 'sometimes|nullable|string|max:40',
            'refType' => 'sometimes|nullable|string|max:30', 'refNumber' => 'sometimes|nullable|string|max:80',
        ];
    }

    private static function dictionaryRules(): array
    {
        return ['nameEn' => 'sometimes|nullable|string|max:120', 'sequence' => 'sometimes|nullable|integer|min:0|max:100000'];
    }

    private static function teamRules(): array
    {
        return self::dictionaryRules() + [
            'description' => 'sometimes|nullable|string|max:2000', 'visibility' => ['sometimes', 'nullable', Rule::in(TicketTeam::VISIBILITY)],
            'firstResponseHours' => 'sometimes|nullable|numeric|min:0|max:9999', 'resolutionHours' => 'sometimes|nullable|numeric|min:0|max:9999',
            'defaultAssigneeId' => 'sometimes|nullable|string|max:60', 'memberKeys' => 'sometimes|array|max:200', 'memberKeys.*' => 'string|max:200',
        ];
    }

    private static function stageRules(): array
    {
        return self::dictionaryRules() + ['isClosing' => 'sometimes|nullable|boolean', 'teamIds' => 'sometimes|array|max:100', 'teamIds.*' => 'string|max:40'];
    }
}
