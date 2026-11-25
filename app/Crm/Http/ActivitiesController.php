<?php

namespace App\Crm\Http;

use App\Crm\Models\CrmActivity;
use App\Crm\Services\ActivitiesService;
use App\Http\Controllers\Controller;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** To-dos on leads: the list across leads, and add / change / done / cancel. */
class ActivitiesController extends Controller
{
    private const DAY = 'date_format:Y-m-d';

    public function __construct(private readonly ActivitiesService $activities) {}

    public function index(Request $request): array
    {
        $rules = ['state' => 'nullable|in:'.implode(',', ActivitiesService::STATES), 'owner' => 'nullable|string|max:60', 'type' => ['nullable', Rule::in(array_keys(CrmActivity::TYPES))], 'lead' => 'nullable|string|max:60'];
        $data = $request->validate($rules + [
            'page' => 'sometimes|integer|min:1', 'pageSize' => 'sometimes|integer|min:1|max:200', 'q' => 'nullable|string|max:120', 'sort' => 'nullable|in:dueDate,createdAt', 'order' => 'sometimes|in:asc,desc',
        ]);

        return $this->activities->list(AuthUser::current(), Paging::from($request, 25), array_intersect_key($data, $rules));
    }

    public function store(Request $request, string $lead): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(CrmActivity::TYPES))], 'summary' => 'nullable|string|max:250', 'dueDate' => ['required', self::DAY],
            'userId' => 'nullable|string|max:60', 'note' => 'nullable|string|max:5000',
        ]);

        return response()->json($this->activities->create(AuthUser::current(), $lead, $data), 201);
    }

    public function update(Request $request, string $id): array
    {
        $data = $request->validate([
            'type' => ['sometimes', Rule::in(array_keys(CrmActivity::TYPES))], 'summary' => 'sometimes|nullable|string|max:250', 'dueDate' => ['sometimes', self::DAY],
            'userId' => 'sometimes|string|max:60', 'note' => 'sometimes|nullable|string|max:5000',
        ]);

        return $this->activities->update(AuthUser::current(), $id, $data);
    }

    /** Action button: the note of what happened is optional. */
    public function done(Request $request, string $id): array
    {
        $data = $request->validate(['note' => 'nullable|string|max:5000']);

        return $this->activities->done(AuthUser::current(), $id, $data['note'] ?? null);
    }

    public function cancel(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'nullable|string|max:1000']);

        return $this->activities->cancel(AuthUser::current(), $id, $data['reason'] ?? null);
    }
}
