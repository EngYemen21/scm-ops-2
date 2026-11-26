<?php

namespace App\Planning\Http;

use App\Http\Controllers\Controller;
use App\Planning\Models\Plan;
use App\Planning\Services\BudgetsService;
use App\Planning\Services\PlansService;
use App\Planning\Services\RunsService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Budget scenarios (with their allocation) and purchase plans. Thin. */
class PlansController extends Controller
{
    private const DAY = 'date_format:Y-m-d';

    public function __construct(private readonly BudgetsService $budgets, private readonly PlansService $plans, private readonly RunsService $runs) {}

    // ── budget scenarios
    public function budgets(string $id): array
    {
        return ['items' => $this->budgets->list($this->runs->find($id))];
    }

    public function storeBudget(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|min:1|max:190'] + self::budgetRules());

        return response()->json($this->budgets->create(AuthUser::current(), $this->runs->find($id), $data), 201);
    }

    public function budget(string $id): array
    {
        return $this->budgets->get($id);
    }

    public function updateBudget(Request $request, string $id): array
    {
        return $this->budgets->update(AuthUser::current(), $id, $request->validate(['name' => 'sometimes|string|min:1|max:190'] + self::budgetRules()));
    }

    public function allocate(string $id): array
    {
        return $this->budgets->allocate(AuthUser::current(), $id);
    }

    public function allocations(Request $request, string $id): array
    {
        $f = $request->validate(['status' => 'nullable|in:funded,partial,deferred', 'month' => 'nullable|date_format:Y-m'] + self::paging());

        return $this->budgets->allocations($id, Paging::from($request, 50), $f);
    }

    // ── purchase plans
    public function index(Request $request): array
    {
        $f = $request->validate(['status' => 'nullable|in:'.implode(',', Plan::STATUSES), 'run' => 'nullable|string|max:40', 'budget' => 'nullable|string|max:40'] + self::paging());

        return $this->plans->list(Paging::from($request, 25), $f);
    }

    public function show(string $id): array
    {
        return $this->plans->get($id);
    }

    public function store(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['name' => 'nullable|string|max:190', 'dateFrom' => ['nullable', self::DAY], 'dateTo' => ['nullable', self::DAY], 'rfqGrouping' => 'nullable|in:'.implode(',', Plan::GROUPINGS),
            'note' => 'nullable|string|max:5000']);

        return response()->json($this->plans->create(AuthUser::current(), $id, array_filter($data, fn ($v) => $v !== null)), 201);
    }

    public function update(Request $request, string $id): array
    {
        return $this->plans->update(AuthUser::current(), $id, $request->validate(['name' => 'sometimes|nullable|string|max:190', 'note' => 'sometimes|nullable|string|max:5000',
            'rfqGrouping' => 'sometimes|in:'.implode(',', Plan::GROUPINGS)]));
    }

    public function updateLine(Request $request, string $id, string $line): array
    {
        return $this->plans->updateLine(AuthUser::current(), $id, $line, $request->validate(['qty' => 'sometimes|integer|min:1|max:99999999', 'vendorId' => 'sometimes|nullable|string|max:40',
            'orderDate' => ['sometimes', self::DAY], 'note' => 'sometimes|nullable|string|max:1000']));
    }

    public function cancelLine(Request $request, string $id, string $line): array
    {
        return $this->plans->cancelLine(AuthUser::current(), $id, $line, $request->validate(['reason' => 'required|string|min:3|max:1000'])['reason']);
    }

    public function submit(string $id): array
    {
        return $this->plans->submit(AuthUser::current(), $id);
    }

    public function approve(Request $request, string $id): array
    {
        return $this->plans->approve(AuthUser::current(), $id, $request->validate(['note' => 'nullable|string|max:1000'])['note'] ?? null);
    }

    public function returnToDraft(Request $request, string $id): array
    {
        return $this->plans->returnToDraft(AuthUser::current(), $id, $request->validate(['reason' => 'required|string|min:3|max:1000'])['reason']);
    }

    public function close(Request $request, string $id): array
    {
        return $this->plans->close(AuthUser::current(), $id, $request->validate(['note' => 'nullable|string|max:1000'])['note'] ?? null);
    }

    public function cancel(Request $request, string $id): array
    {
        return $this->plans->cancel(AuthUser::current(), $id, $request->validate(['reason' => 'required|string|min:3|max:1000'])['reason']);
    }

    public function request(Request $request, string $id): array
    {
        $data = $request->validate(['lineIds' => 'sometimes|array|max:2000', 'lineIds.*' => 'string|max:40']);

        return $this->plans->request(AuthUser::current(), $id, $data['lineIds'] ?? []);
    }

    private static function paging(): array
    {
        return ['page' => 'sometimes|integer|min:1', 'pageSize' => 'sometimes|integer|min:1|max:500', 'q' => 'nullable|string|max:120', 'sort' => 'nullable|string|max:40', 'order' => 'sometimes|in:asc,desc'];
    }

    private static function budgetRules(): array
    {
        $w = 'integer|min:0|max:100';

        return [
            'note' => 'sometimes|nullable|string|max:5000', 'monthlyAmount' => 'sometimes|numeric|min:0|max:9999999999', 'weights' => 'sometimes|array', 'weights.urgency' => $w, 'weights.stockout' => $w,
            'weights.criticality' => $w, 'weights.velocity' => $w, 'weights.confidence' => $w, 'weights.customer' => $w, 'weights.leadTime' => $w, 'criticalFirst' => 'sometimes|boolean',
            'allowPartial' => 'sometimes|boolean', 'carryDeferred' => 'sometimes|boolean', 'carryUnused' => 'sometimes|boolean', 'urgencyHorizonDays' => 'sometimes|integer|min:1|max:365',
            'lines' => 'sometimes|array|min:1|max:36', 'lines.*.month' => 'required_with:lines|date_format:Y-m', 'lines.*.amount' => 'required_with:lines|numeric|min:0|max:9999999999',
        ];
    }
}
