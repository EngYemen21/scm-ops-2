<?php

namespace App\Http\Controllers\Api\Master;

use App\Services\Master\PartnersService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CustomersController extends MasterController
{
    public function __construct(private readonly PartnersService $service) {}

    public function index(Request $request): array
    {
        $filters = $this->filters($request, ['city' => 'nullable|string', 'terms' => 'nullable|string', 'priceList' => 'nullable|string', 'active' => self::FLAG]);

        return $this->service->listCustomers(Paging::from($request), $filters);
    }

    public function show(string $id): array
    {
        return $this->service->getCustomer($id);
    }

    public function store(Request $request): array
    {
        $data = self::typed($request->validate([
            'nameAr' => 'required|string|min:1', 'zone' => 'required|string|min:1', 'contact' => 'required|string|min:1', 'cr' => 'required|string|min:1',
        ] + self::optionalRules()), floats: ['creditLimit', 'lat', 'lng']);
        // Same refinement as the reference schema: credit terms need a positive limit (reported on creditLimit).
        if ((($data['terms'] ?? null) ?: PartnersService::CASH_TERMS) !== PartnersService::CASH_TERMS && ! (($data['creditLimit'] ?? 0) > 0)) {
            throw ValidationException::withMessages(['creditLimit' => 'العميل الآجل يحتاج حدًا ائتمانيًا أكبر من صفر']);
        }

        return $this->service->createCustomer(AuthUser::current(), $data);
    }

    public function update(Request $request, string $id): array
    {
        $data = $request->validate([
            'nameAr' => 'sometimes|string|min:1', 'zone' => 'sometimes|string|min:1', 'contact' => 'sometimes|string|min:1', 'cr' => 'sometimes|string|min:1',
            'active' => 'sometimes|boolean',
        ] + self::optionalRules());

        return $this->service->updateCustomer(AuthUser::current(), $id, self::withoutNulls(self::typed($data, floats: ['creditLimit', 'lat', 'lng'], bools: ['active']), ['terms', 'creditLimit']));
    }

    private static function optionalRules(): array
    {
        return [
            'nameEn' => 'nullable|string', 'city' => 'nullable|string', 'vat' => 'nullable|string', 'terms' => 'nullable|string', 'creditLimit' => 'nullable|numeric|min:0',
            'priceList' => 'nullable|string', 'address' => 'nullable|string', 'lat' => 'nullable|numeric', 'lng' => 'nullable|numeric',
        ];
    }
}
