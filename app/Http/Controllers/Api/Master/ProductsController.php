<?php

namespace App\Http\Controllers\Api\Master;

use App\Services\Master\ProductsService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\Request;

class ProductsController extends MasterController
{
    private const INTS = ['unitsPerPallet', 'reorderMin', 'reorderMax', 'shelfLifeDays'];

    private const FLOATS = ['weightKg', 'purchasePrice', 'lengthCm', 'widthCm', 'heightCm'];

    public function __construct(private readonly ProductsService $service) {}

    public function index(Request $request): array
    {
        $filters = $this->filters($request, [
            'category' => 'nullable|string', 'storageClass' => 'nullable|string', 'active' => self::FLAG, 'warehouse' => 'nullable|string', 'supplier' => 'nullable|string',
        ]);

        return $this->service->list(Paging::from($request), $filters);
    }

    public function show(string $id): array
    {
        return $this->service->get($id);
    }

    public function store(Request $request): array
    {
        $data = $request->validate([
            'sku' => 'required|string|min:3|max:32', 'nameAr' => 'required|string|min:1', 'nameEn' => 'required|string|min:1', 'weightKg' => 'required|numeric|gt:0',
            'lengthCm' => 'required|numeric|gt:0', 'widthCm' => 'required|numeric|gt:0', 'heightCm' => 'required|numeric|gt:0',
        ] + self::optionalRules());
        $data = self::typed($data, self::INTS, self::FLOATS, ['tracksExpiry']);

        // Schema defaults of the reference: storageClass 'ambient', tracksExpiry false, reorderMin 0.
        return $this->service->create(AuthUser::current(), $data + ['storageClass' => 'ambient', 'tracksExpiry' => false, 'reorderMin' => 0]);
    }

    public function update(Request $request, string $id): array
    {
        $data = $request->validate([
            'sku' => 'sometimes|string|min:3|max:32', 'nameAr' => 'sometimes|string|min:1', 'nameEn' => 'sometimes|string|min:1', 'weightKg' => 'sometimes|numeric|gt:0',
            'lengthCm' => 'sometimes|numeric|gt:0', 'widthCm' => 'sometimes|numeric|gt:0', 'heightCm' => 'sometimes|numeric|gt:0',
            'reason' => 'required|string|min:1', 'active' => 'sometimes|boolean',
        ] + self::optionalRules(), ['reason.required' => 'سبب التعديل مطلوب']);

        return $this->service->update(AuthUser::current(), $id, self::typed($data, self::INTS, self::FLOATS, ['tracksExpiry', 'active']));
    }

    public function activate(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'nullable|string']);

        return $this->service->setActive(AuthUser::current(), $id, true, $data['reason'] ?? null);
    }

    public function deactivate(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'nullable|string']);

        return $this->service->setActive(AuthUser::current(), $id, false, $data['reason'] ?? null);
    }

    public function addBarcode(Request $request, string $id)
    {
        $data = $request->validate(['barcode' => 'required|string|min:1', 'uomCode' => 'nullable|string', 'isPrimary' => 'sometimes|boolean']);

        return $this->service->addBarcode(AuthUser::current(), $id, self::typed($data, bools: ['isPrimary']) + ['isPrimary' => false]);
    }

    public function removeBarcode(string $id, string $barcode): array
    {
        return $this->service->removeBarcode(AuthUser::current(), $id, $barcode);
    }

    public function linkSupplier(Request $request, string $id)
    {
        $data = $request->validate([
            'supplierCode' => 'required|string|min:1', 'supplierSku' => 'nullable|string', 'price' => 'nullable|numeric|min:0', 'leadDays' => 'nullable|integer|min:0', 'preferred' => 'sometimes|boolean',
        ]);

        return $this->service->linkSupplier(AuthUser::current(), $id, self::typed($data, ['leadDays'], ['price'], ['preferred']) + ['preferred' => false]);
    }

    public function unlinkSupplier(string $id, string $code): array
    {
        return $this->service->unlinkSupplier(AuthUser::current(), $id, $code);
    }

    private static function optionalRules(): array
    {
        return [
            'brand' => 'nullable|string', 'categoryCode' => 'nullable|string', 'uomCode' => 'nullable|string', 'storageClass' => 'sometimes|in:ambient,chilled,frozen',
            'unitsPerPallet' => 'nullable|integer|min:1', 'purchasePrice' => 'nullable|numeric|min:0', 'tracksExpiry' => 'sometimes|boolean', 'reorderMin' => 'nullable|integer|min:0',
            'reorderMax' => 'nullable|integer|min:0', 'shelfLifeDays' => 'nullable|integer|min:1', 'homeWarehouseCode' => 'nullable|string', 'barcode' => 'nullable|string',
            'preferredSupplierCode' => 'nullable|string', 'reason' => 'nullable|string',
        ];
    }
}
