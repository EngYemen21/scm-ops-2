<?php

namespace App\Http\Controllers\Api\Master;

use App\Services\Master\PartnersService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\Request;

class SuppliersController extends MasterController
{
    /** Digits with optional spaces, as the reference strips whitespace before checking the length. */
    private const CR = ['string', 'regex:/^(\d\s*){10}$/'];

    private const VAT = ['string', 'regex:/^(\d\s*){15}$/'];

    public function __construct(private readonly PartnersService $service) {}

    public function index(Request $request): array
    {
        $filters = $this->filters($request, ['category' => 'nullable|string', 'active' => self::FLAG, 'isNew' => self::FLAG]);

        return $this->service->listSuppliers(Paging::from($request), $filters);
    }

    public function show(string $id): array
    {
        return $this->service->getSupplier($id);
    }

    public function store(Request $request): array
    {
        $data = $request->validate([
            'nameAr' => 'required|string|min:1', 'cr' => ['required', ...self::CR], 'vat' => ['required', ...self::VAT], 'contact' => 'required|string|min:1',
        ] + self::optionalRules(), self::messages());

        return $this->service->createSupplier(AuthUser::current(), self::typed($data, ints: ['leadDays'], floats: ['minOrder']));
    }

    public function update(Request $request, string $id): array
    {
        $data = $request->validate([
            'nameAr' => 'sometimes|string|min:1', 'cr' => ['sometimes', ...self::CR], 'vat' => ['sometimes', ...self::VAT], 'contact' => 'sometimes|string|min:1',
            'active' => 'sometimes|boolean',
        ] + self::optionalRules(), self::messages());

        return $this->service->updateSupplier(AuthUser::current(), $id, self::withoutNulls(self::typed($data, ints: ['leadDays'], floats: ['minOrder'], bools: ['active']), ['leadDays', 'minOrder']));
    }

    private static function optionalRules(): array
    {
        return [
            'nameEn' => 'nullable|string', 'category' => 'nullable|string', 'email' => 'nullable|email', 'leadDays' => 'nullable|integer|min:0', 'terms' => 'nullable|string',
            'iban' => 'nullable|string', 'minOrder' => 'nullable|numeric|min:0', 'notes' => 'nullable|string',
        ];
    }

    private static function messages(): array
    {
        return ['cr.regex' => 'السجل التجاري يجب أن يكون 10 أرقام', 'vat.regex' => 'الرقم الضريبي يجب أن يكون 15 رقمًا'];
    }
}
