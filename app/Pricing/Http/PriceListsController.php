<?php

namespace App\Pricing\Http;

use App\Http\Controllers\Controller;
use App\Pricing\Models\PriceRule;
use App\Pricing\Services\PriceListsService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Customer price lists and their rules. Thin: validate the shape (camelCase keys, as the client sends them), call the service, return. */
class PriceListsController extends Controller
{
    private const DAY = 'date_format:Y-m-d';

    public function __construct(private readonly PriceListsService $lists) {}

    public function index(Request $request): array
    {
        return $this->lists->list(Paging::from($request, 25), $request->only(['state', 'source', 'currency']));
    }

    public function show(string $id): array
    {
        return $this->lists->get($id);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|min:1|max:190', 'isDefault' => 'sometimes|boolean'] + self::listRules());

        return response()->json($this->lists->create(AuthUser::current(), self::typed($data)), 201);
    }

    public function update(Request $request, string $id): array
    {
        return $this->lists->update(AuthUser::current(), $id, self::typed($request->validate(['name' => 'sometimes|string|min:1|max:190'] + self::listRules())));
    }

    public function archive(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500', 'note' => 'nullable|string|max:500']);

        return $this->lists->archive(AuthUser::current(), $id, ($data['reason'] ?? null) ?: ($data['note'] ?? null));
    }

    public function restore(string $id): array
    {
        return $this->lists->restore(AuthUser::current(), $id);
    }

    public function makeDefault(string $id): array
    {
        return $this->lists->makeDefault(AuthUser::current(), $id);
    }

    public function clearDefault(string $id): array
    {
        return $this->lists->clearDefault(AuthUser::current(), $id);
    }

    public function duplicate(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['name' => 'nullable|string|max:190', 'note' => 'nullable|string|max:2000']);

        return response()->json($this->lists->duplicate(AuthUser::current(), $id, $data), 201);
    }

    // ── rules

    public function rules(Request $request, string $id): array
    {
        return $this->lists->rules($id, Paging::from($request, 50), $request->only(['appliesTo', 'compute', 'state', 'product']));
    }

    public function storeRule(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['appliesTo' => 'required|in:'.implode(',', PriceRule::APPLIES), 'compute' => 'required|in:'.implode(',', PriceRule::COMPUTES)] + self::ruleRules());

        return response()->json($this->lists->createRule(AuthUser::current(), $id, $data), 201);
    }

    public function updateRule(Request $request, string $rule): array
    {
        $data = $request->validate(['appliesTo' => 'sometimes|in:'.implode(',', PriceRule::APPLIES), 'compute' => 'sometimes|in:'.implode(',', PriceRule::COMPUTES)] + self::ruleRules());

        return $this->lists->updateRule(AuthUser::current(), $rule, $data);
    }

    public function archiveRule(Request $request, string $rule): array
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500', 'note' => 'nullable|string|max:500']);

        return $this->lists->archiveRule(AuthUser::current(), $rule, ($data['reason'] ?? null) ?: ($data['note'] ?? null));
    }

    public function restoreRule(string $rule): array
    {
        return $this->lists->restoreRule(AuthUser::current(), $rule);
    }

    /** A pasted sheet of "code, price": fixed prices for many products of the list at once. */
    public function setPrices(Request $request, string $id): array
    {
        $data = $request->validate([
            'items' => 'required|array|min:1|max:'.PriceListsService::BULK_LIMIT, 'items.*.sku' => 'required|string|min:1|max:120',
            'items.*.price' => 'required|numeric|gt:0|max:99999999', 'items.*.minQty' => 'nullable|numeric|min:0|max:99999999',
        ]);

        return $this->lists->setPrices(AuthUser::current(), $id, $data['items']);
    }

    private static function listRules(): array
    {
        return [
            'currency' => 'sometimes|nullable|string|alpha|size:3', 'validFrom' => ['sometimes', 'nullable', self::DAY], 'validTo' => ['sometimes', 'nullable', self::DAY],
            'note' => 'sometimes|nullable|string|max:2000', 'sequence' => 'sometimes|nullable|integer|min:0|max:1000000',
        ];
    }

    private static function ruleRules(): array
    {
        $amount = 'sometimes|nullable|numeric|min:-99999999|max:99999999';

        return [
            'product' => 'sometimes|nullable|string|max:120', 'category' => 'sometimes|nullable|string|max:120', 'minQty' => 'sometimes|nullable|numeric|min:0|max:99999999',
            'validFrom' => ['sometimes', 'nullable', self::DAY], 'validTo' => ['sometimes', 'nullable', self::DAY],
            'fixedPrice' => 'sometimes|nullable|numeric|min:0|max:99999999', 'percent' => 'sometimes|nullable|numeric|min:-1000|max:100',
            'base' => 'sometimes|nullable|in:'.implode(',', PriceRule::BASES), 'baseList' => 'sometimes|nullable|string|max:60',
            'discountPct' => 'sometimes|nullable|numeric|min:-1000|max:100', 'surcharge' => $amount, 'roundTo' => 'sometimes|nullable|numeric|min:0|max:99999999',
            'minMargin' => $amount, 'maxMargin' => $amount, 'note' => 'sometimes|nullable|string|max:2000',
        ];
    }

    /** Validated flags may arrive as 0 / 1 or "true": give the service real booleans. */
    private static function typed(array $data): array
    {
        if (array_key_exists('isDefault', $data)) {
            $data['isDefault'] = filter_var($data['isDefault'], FILTER_VALIDATE_BOOLEAN);
        }

        return $data;
    }
}
