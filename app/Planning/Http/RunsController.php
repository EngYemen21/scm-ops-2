<?php

namespace App\Planning\Http;

use App\Http\Controllers\Controller;
use App\Planning\Models\Run;
use App\Planning\Services\ActualsService;
use App\Planning\Services\ExportService;
use App\Planning\Services\PushService;
use App\Planning\Services\RunsService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Forecast runs: list, one run, its items, requirements, patterns, expected orders, export — and what happens to it. Thin. */
class RunsController extends Controller
{
    private const DAY = 'date_format:Y-m-d';

    public function __construct(private readonly RunsService $runs) {}

    public function index(Request $request): array
    {
        $f = $request->validate(['status' => 'nullable|in:'.implode(',', Run::STATUSES), 'method' => 'nullable|in:'.implode(',', Run::METHODS), 'active' => 'nullable|in:yes,no,all',
            'origin' => 'nullable|in:ops,odoo'] + self::paging());

        return $this->runs->list(Paging::from($request, 25), $f);
    }

    public function show(string $id): array
    {
        return $this->runs->get($id);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->runs->create(AuthUser::current(), $request->validate(self::rules())), 201);
    }

    public function update(Request $request, string $id): array
    {
        return $this->runs->update(AuthUser::current(), $id, $request->validate(self::rules()));
    }

    public function compute(string $id): array
    {
        return $this->runs->compute(AuthUser::current(), $id);
    }

    public function advance(string $id): array
    {
        return $this->runs->advance($id);
    }

    public function stop(string $id): array
    {
        return $this->runs->stop(AuthUser::current(), $id);
    }

    public function freeze(Request $request, string $id): array
    {
        return $this->runs->freeze(AuthUser::current(), $id, $request->validate(['note' => 'nullable|string|max:1000'])['note'] ?? null);
    }

    public function archive(Request $request, string $id): array
    {
        return $this->runs->archive(AuthUser::current(), $id, false, $request->validate(['reason' => 'nullable|string|max:1000'])['reason'] ?? null);
    }

    public function restore(string $id): array
    {
        return $this->runs->archive(AuthUser::current(), $id, true, null);
    }

    public function copy(string $id): JsonResponse
    {
        return response()->json($this->runs->copy(AuthUser::current(), $id), 201);
    }

    public function items(Request $request, string $id): array
    {
        $f = $request->validate(['kind' => 'nullable|in:stock,customer', 'status' => 'nullable|in:shortage,surplus,ok,no_demand', 'warehouse' => 'nullable|string|max:40', 'vendor' => 'nullable|string|max:40',
            'criticality' => 'nullable|in:critical,high,normal,low', 'priceSource' => 'nullable|in:vendor,supplier_link,last_purchase,cost,none', 'fallback' => 'nullable|in:true,false,1,0',
            'buy' => 'nullable|in:1,true', 'orderpoint' => 'nullable|in:missing,matches,differs', 'customer' => 'nullable|string|max:60'] + self::paging());

        return $this->runs->items($id, Paging::from($request, 50), $f);
    }

    public function item(string $id, string $item): array
    {
        return $this->runs->item($id, $item);
    }

    public function requirements(Request $request, string $id): array
    {
        $f = $request->validate(['warehouse' => 'nullable|string|max:40', 'vendor' => 'nullable|string|max:40', 'criticality' => 'nullable|in:critical,high,normal,low',
            'priceSource' => 'nullable|in:vendor,supplier_link,last_purchase,cost,none', 'late' => 'nullable|in:true,false,1,0'] + self::paging());

        return $this->runs->requirements($id, Paging::from($request, 50), $f);
    }

    public function patterns(Request $request, string $id): array
    {
        $f = $request->validate(['state' => 'nullable|in:active,overdue,lapsed,insufficient', 'band' => 'nullable|in:high,medium,low,none', 'warehouse' => 'nullable|string|max:40', 'customer' => 'nullable|string|max:60'] + self::paging());

        return $this->runs->patterns($id, Paging::from($request, 50), $f);
    }

    public function events(Request $request, string $id): array
    {
        $f = $request->validate(['actual' => 'nullable|in:pending,ordered,missed', 'pattern' => 'nullable|string|max:40', 'customer' => 'nullable|string|max:60'] + self::paging());

        return $this->runs->events($id, Paging::from($request, 50), $f);
    }

    public function push(Request $request, PushService $push, string $id): array
    {
        $data = $request->validate(['itemIds' => 'sometimes|array|max:5000', 'itemIds.*' => 'string|max:40']);

        return $push->push(AuthUser::current(), $this->runs->find($id), $data['itemIds'] ?? []);
    }

    public function actuals(ActualsService $actuals, string $id): array
    {
        return $actuals->refresh(AuthUser::current(), $this->runs->find($id));
    }

    public function export(Request $request, ExportService $export, string $id)
    {
        $data = $request->validate(['format' => 'nullable|in:csv,xlsx', 'sheet' => 'nullable|in:'.implode(',', ExportService::SHEETS), 'history' => 'nullable|in:0,1,true,false']);
        $run = $this->runs->find($id);
        if (($data['format'] ?? 'xlsx') === 'csv') {
            return $export->csv($run, $data['sheet'] ?? 'items');
        }

        return $export->xlsx($run, isset($data['history']) ? filter_var($data['history'], FILTER_VALIDATE_BOOLEAN) : (bool) $run->include_history_sheet);
    }

    private static function paging(): array
    {
        return ['page' => 'sometimes|integer|min:1', 'pageSize' => 'sometimes|integer|min:1|max:500', 'q' => 'nullable|string|max:120', 'sort' => 'nullable|string|max:40', 'order' => 'sometimes|in:asc,desc'];
    }

    /** Each parameter alone; the rules between them are the service's. Every key is optional (a new run takes the defaults). */
    private static function rules(): array
    {
        $ids = 'sometimes|array|max:5000';

        return [
            'name' => 'sometimes|nullable|string|max:190', 'note' => 'sometimes|nullable|string|max:5000', 'anchorDate' => ['sometimes', self::DAY], 'historyFrom' => ['sometimes', self::DAY],
            'historyTo' => ['sometimes', self::DAY], 'forecastFrom' => ['sometimes', self::DAY], 'forecastTo' => ['sometimes', self::DAY], 'periodKind' => 'sometimes|in:month,week',
            'method' => 'sometimes|in:'.implode(',', Run::METHODS), 'wmaWindow' => 'sometimes|integer|min:1|max:24', 'seasonalScale' => 'sometimes|boolean', 'growthPct' => 'sometimes|numeric|min:-90|max:500',
            'demandNetting' => 'sometimes|in:net,gross,ignore', 'historySource' => 'sometimes|in:delivery,sale_order', 'includeReturns' => 'sometimes|boolean', 'includeOdoo' => 'sometimes|boolean',
            'includeOps' => 'sometimes|boolean', 'safetyMethod' => 'sometimes|in:days,service_level', 'safetyDays' => 'sometimes|numeric|min:0|max:365', 'serviceLevel' => 'sometimes|nullable|in:90,95,98,99',
            'targetCoverDays' => 'sometimes|numeric|min:0|max:730', 'useVendorLeadTime' => 'sometimes|boolean', 'defaultLeadDays' => 'sometimes|numeric|min:0|max:365',
            'planningBufferDays' => 'sometimes|numeric|min:0|max:180', 'qtyMultipleSource' => 'sometimes|in:one,pack', 'productSelection' => 'sometimes|in:moving,manual,all',
            'productIds' => $ids, 'productIds.*' => 'string|max:40', 'categoryIds' => $ids, 'categoryIds.*' => 'string|max:40', 'warehouseIds' => 'sometimes|array|min:1|max:50', 'warehouseIds.*' => 'string|max:40',
            'customerMode' => 'sometimes|in:none,filter,split', 'customerIds' => $ids, 'customerIds.*' => 'string|max:40', 'excludeCustomerIds' => $ids, 'excludeCustomerIds.*' => 'string|max:40',
            'includeRollup' => 'sometimes|boolean', 'rollupMethod' => 'sometimes|in:pooled,sum', 'includeHistorySheet' => 'sometimes|boolean', 'cadenceMinOrders' => 'sometimes|integer|min:2|max:50',
            'cadenceIntervalWindow' => 'sometimes|integer|min:1|max:50', 'cadenceLapseFactor' => 'sometimes|numeric|min:1|max:20', 'cadenceUseTrend' => 'sometimes|boolean',
            'cadenceTrendCapPct' => 'sometimes|numeric|min:0|max:200', 'cadenceUseSeasonality' => 'sometimes|boolean', 'cadenceFallback' => 'sometimes|in:estimate,ignore',
            'budgetMonthlyAmount' => 'sometimes|numeric|min:0|max:9999999999',
        ];
    }
}
