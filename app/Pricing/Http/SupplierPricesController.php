<?php

namespace App\Pricing\Http;

use App\Http\Controllers\Controller;
use App\Pricing\Services\SupplierPricesService;
use App\Pricing\Support\Cost;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Supplier price lists. They are purchase costs: reading them needs — besides the page — `inventory.view_cost` or
 * `supplierprice.manage` (403 PRICING_COST_HIDDEN otherwise); changing them needs `supplierprice.manage` (route).
 */
class SupplierPricesController extends Controller
{
    private const DAY = 'date_format:Y-m-d';

    public function __construct(private readonly SupplierPricesService $prices) {}

    public function index(Request $request): array
    {
        Cost::assertVisible(AuthUser::current());

        return $this->prices->list(Paging::from($request, 25), $request->only(['supplier', 'product', 'state', 'period', 'source']));
    }

    public function suppliers(Request $request): array
    {
        Cost::assertVisible(AuthUser::current());

        return $this->prices->suppliers(Paging::from($request, 25));
    }

    public function show(string $id): array
    {
        Cost::assertVisible(AuthUser::current());

        return $this->prices->get($id);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['supplier' => 'required|string|min:1|max:120', 'product' => 'required|string|min:1|max:120', 'price' => 'required|numeric|gt:0|max:99999999'] + self::rules());

        return response()->json($this->prices->create(AuthUser::current(), $data), 201);
    }

    public function update(Request $request, string $id): array
    {
        $data = $request->validate(['supplier' => 'sometimes|string|min:1|max:120', 'product' => 'sometimes|string|min:1|max:120', 'price' => 'sometimes|numeric|gt:0|max:99999999'] + self::rules());

        return $this->prices->update(AuthUser::current(), $id, $data);
    }

    public function archive(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500', 'note' => 'nullable|string|max:500']);

        return $this->prices->archive(AuthUser::current(), $id, ($data['reason'] ?? null) ?: ($data['note'] ?? null));
    }

    public function restore(string $id): array
    {
        return $this->prices->restore(AuthUser::current(), $id);
    }

    private static function rules(): array
    {
        return [
            'currency' => 'sometimes|nullable|string|alpha|size:3', 'discountPct' => 'sometimes|nullable|numeric|min:0|max:99.9999', 'minQty' => 'sometimes|nullable|numeric|min:0|max:99999999',
            'leadDays' => 'sometimes|nullable|integer|min:0|max:3650', 'validFrom' => ['sometimes', 'nullable', self::DAY], 'validTo' => ['sometimes', 'nullable', self::DAY],
            'supplierSku' => 'sometimes|nullable|string|max:120', 'supplierProductName' => 'sometimes|nullable|string|max:250', 'sequence' => 'sometimes|nullable|integer|min:0|max:1000000',
            'note' => 'sometimes|nullable|string|max:2000',
        ];
    }
}
