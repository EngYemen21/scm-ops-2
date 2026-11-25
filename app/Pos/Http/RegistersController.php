<?php

namespace App\Pos\Http;

use App\Http\Controllers\Controller;
use App\Pos\Models\PosDenomination;
use App\Pos\Models\PosPaymentMethod;
use App\Pos\Models\PosRegister;
use App\Pos\Services\RegistersService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Registers, payment methods, denominations, and the lookups of the page. */
class RegistersController extends Controller
{
    public function __construct(private readonly RegistersService $registers) {}

    public function lookups(): array
    {
        return $this->registers->lookups(AuthUser::current());
    }

    public function index(Request $request): array
    {
        return $this->registers->list(Paging::from($request), $request->only(['active', 'warehouse', 'source']));
    }

    public function show(string $id): array
    {
        return $this->registers->get($id);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => 'required|string|min:2|max:20|regex:/^[A-Za-z0-9_-]+$/', 'nameAr' => 'required|string|min:2|max:120', 'warehouse' => 'required|string|max:60'] + self::registerRules());

        return response()->json($this->registers->create(AuthUser::current(), $data), 201);
    }

    public function update(Request $request, string $id): array
    {
        $data = $request->validate(['nameAr' => 'sometimes|string|min:2|max:120', 'warehouse' => 'sometimes|string|max:60', 'active' => 'sometimes|boolean'] + self::registerRules());

        return $this->registers->update(AuthUser::current(), $id, $data);
    }

    private static function registerRules(): array
    {
        return [
            'nameEn' => 'sometimes|nullable|string|max:120', 'customer' => 'sometimes|nullable|string|max:60', 'maxDiscountPct' => 'sometimes|numeric|min:0|max:100',
            'allowNegativeStock' => 'sometimes|boolean', 'refundRestock' => 'sometimes|in:'.implode(',', PosRegister::RESTOCK), 'refundNeedsManager' => 'sometimes|boolean',
            'receiptFormat' => 'sometimes|in:'.implode(',', PosRegister::RECEIPTS), 'receiptFooter' => 'sometimes|nullable|string|max:500',
            'sellerNameAr' => 'sometimes|nullable|string|max:190', 'sellerNameEn' => 'sometimes|nullable|string|max:190', 'sellerVatNo' => 'sometimes|nullable|string|regex:/^\d{15}$/',
            'sellerCr' => 'sometimes|nullable|string|max:40', 'sellerAddress' => 'sometimes|nullable|string|max:300', 'differenceAccount' => 'sometimes|nullable|string|max:60',
            'methods' => 'sometimes|array|max:20', 'methods.*' => 'string|max:60',
        ];
    }

    public function methods(): array
    {
        return PosPaymentMethod::with('bankAccount:id,code,name_ar,name_en,kind,active')->orderBy('sequence')->orderBy('code')->get()->map->toArray()->all();
    }

    public function storeMethod(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string|min:2|max:20|regex:/^[A-Za-z0-9_-]+$/', 'nameAr' => 'required|string|min:2|max:120', 'nameEn' => 'nullable|string|max:120',
            'kind' => 'required|in:'.implode(',', PosPaymentMethod::KINDS), 'bankAccount' => 'required_unless:kind,account|nullable|string|max:60',
            'requiresReference' => 'sometimes|boolean', 'sequence' => 'sometimes|integer|min:0|max:1000',
        ]);

        return response()->json($this->registers->createMethod(AuthUser::current(), $data), 201);
    }

    public function updateMethod(Request $request, string $id): array
    {
        $data = $request->validate([
            'nameAr' => 'sometimes|string|min:2|max:120', 'nameEn' => 'sometimes|nullable|string|max:120', 'kind' => 'sometimes|in:'.implode(',', PosPaymentMethod::KINDS),
            'bankAccount' => 'sometimes|nullable|string|max:60', 'requiresReference' => 'sometimes|boolean', 'sequence' => 'sometimes|integer|min:0|max:1000', 'active' => 'sometimes|boolean',
        ]);

        return $this->registers->updateMethod(AuthUser::current(), $id, $data);
    }

    public function denominations(): array
    {
        return PosDenomination::orderBy('value')->get()->map->toArray()->all();
    }

    public function storeDenomination(Request $request): JsonResponse
    {
        $data = $request->validate(['value' => 'required|numeric|min:0.01|max:100000', 'kind' => 'required|in:'.implode(',', PosDenomination::KINDS), 'label' => 'nullable|string|max:60']);

        return response()->json($this->registers->createDenomination(AuthUser::current(), $data), 201);
    }

    public function updateDenomination(Request $request, string $id): array
    {
        $data = $request->validate(['kind' => 'sometimes|in:'.implode(',', PosDenomination::KINDS), 'label' => 'sometimes|string|max:60', 'active' => 'sometimes|boolean']);

        return $this->registers->updateDenomination(AuthUser::current(), $id, $data);
    }
}
