<?php

namespace App\Http\Controllers\Api\Master;

use App\Services\Master\PartnersService;
use App\Services\Master\ProductsService;
use App\Support\AuthUser;
use Illuminate\Http\Request;

class CategoriesController extends MasterController
{
    private const CODE = ['string', 'regex:/^[a-z0-9_-]{2,}$/i'];

    public function __construct(private readonly ProductsService $service) {}

    /** Tree by default (`?includeInactive=false` hides inactive nodes); `?flat=true` returns the flat list. */
    public function index(Request $request): array
    {
        $q = $request->validate(['flat' => self::FLAG, 'includeInactive' => self::FLAG]);
        if (PartnersService::flag($q['flat'] ?? null) === true) {
            return $this->service->categoriesFlat();
        }

        return $this->service->categoryTree(PartnersService::flag($q['includeInactive'] ?? null) !== false);
    }

    public function show(string $id): array
    {
        return $this->service->getCategory($id);
    }

    public function store(Request $request): array
    {
        $data = $request->validate(['code' => ['required', ...self::CODE], 'nameAr' => 'required|string|min:1'] + self::optionalRules(), self::messages());

        return $this->service->createCategory(AuthUser::current(), self::typed($data, bools: ['active']) + ['active' => true]);
    }

    public function update(Request $request, string $id): array
    {
        $data = $request->validate(['code' => ['sometimes', ...self::CODE], 'nameAr' => 'sometimes|string|min:1'] + self::optionalRules(), self::messages());

        return $this->service->updateCategory(AuthUser::current(), $id, self::typed($data, bools: ['active']));
    }

    private static function optionalRules(): array
    {
        return ['nameEn' => 'nullable|string', 'parentId' => 'nullable|string', 'active' => 'sometimes|boolean'];
    }

    private static function messages(): array
    {
        return ['code.regex' => 'الرمز حروف لاتينية وأرقام فقط'];
    }
}
