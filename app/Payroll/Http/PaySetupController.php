<?php

namespace App\Payroll\Http;

use App\Http\Controllers\Controller;
use App\Payroll\Services\PaySetupService;
use App\Payroll\Services\RunPosting;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** What pay is made of: components, structures, social-insurance rates, parameters, the posting map, and each employee's pay profile. */
class PaySetupController extends Controller
{
    private const COMPONENT = ['nameEn' => 'sometimes|nullable|string|max:190', 'sequence' => 'sometimes|nullable|integer|min:1|max:899', 'accountKey' => 'sometimes|nullable|string|max:30',
        'accountCode' => 'sometimes|nullable|string|max:20', 'gosiBase' => 'sometimes|boolean', 'active' => 'sometimes|boolean', 'notes' => 'sometimes|nullable|string|max:2000'];

    private const STRUCTURE = ['nameEn' => 'sometimes|nullable|string|max:190', 'gosiScheme' => 'sometimes|nullable|string|max:20', 'isDefault' => 'sometimes|boolean', 'active' => 'sometimes|boolean',
        'notes' => 'sometimes|nullable|string|max:2000'];

    private const GOSI = ['employeePct' => 'numeric|min:0|max:100', 'employerPct' => 'numeric|min:0|max:100', 'baseMin' => 'nullable|numeric|min:0|max:99999999', 'baseMax' => 'nullable|numeric|min:0|max:99999999',
        'note' => 'nullable|string|max:500'];

    public function __construct(private readonly PaySetupService $setup) {}

    // ── components
    public function components(): array
    {
        return ['items' => $this->setup->components()];
    }

    public function storeComponent(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'min:2', 'max:40', 'regex:/^[A-Za-z0-9_.\-]+$/'], 'nameAr' => 'required|string|min:2|max:190', 'category' => 'required|in:allowance,deduction'] + self::COMPONENT);

        return response()->json($this->setup->saveComponent(AuthUser::current(), null, $data), 201);
    }

    public function updateComponent(Request $request, string $id): array
    {
        return $this->setup->saveComponent(AuthUser::current(), $id, $request->validate(['nameAr' => 'sometimes|string|min:2|max:190'] + self::COMPONENT));
    }

    // ── structures
    public function structures(): array
    {
        return ['items' => $this->setup->structures()];
    }

    public function structure(string $id): array
    {
        return $this->setup->structure($id);
    }

    public function storeStructure(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'min:2', 'max:40', 'regex:/^[A-Za-z0-9_.\-]+$/'], 'nameAr' => 'required|string|min:2|max:190'] + self::STRUCTURE);

        return response()->json($this->setup->saveStructure(AuthUser::current(), null, $data), 201);
    }

    public function updateStructure(Request $request, string $id): array
    {
        return $this->setup->saveStructure(AuthUser::current(), $id, $request->validate(['nameAr' => 'sometimes|string|min:2|max:190'] + self::STRUCTURE));
    }

    // ── social-insurance rates
    public function gosiRates(): array
    {
        return $this->setup->gosiRates();
    }

    public function storeGosiRate(Request $request): JsonResponse
    {
        $rules = array_map(fn (string $rule) => str_starts_with($rule, 'numeric') ? "required|{$rule}" : $rule, self::GOSI);

        return response()->json($this->setup->createGosiRate(AuthUser::current(), $request->validate(['scheme' => 'required|string|min:2|max:20', 'effectiveFrom' => 'required|date'] + $rules)), 201);
    }

    public function updateGosiRate(Request $request, string $id): array
    {
        return $this->setup->updateGosiRate(AuthUser::current(), $id, $request->validate(array_map(fn (string $rule) => "sometimes|{$rule}", self::GOSI)));
    }

    public function destroyGosiRate(string $id): array
    {
        return $this->setup->removeGosiRate(AuthUser::current(), $id);
    }

    // ── parameters
    public function parameters(): array
    {
        return ['items' => $this->setup->parameters()];
    }

    public function storeParameter(Request $request): JsonResponse
    {
        // the value is a number or a word, as the parameter asks: the service checks it against the parameter's kind
        $data = $request->validate(['code' => 'required|string|max:60', 'value' => 'required', 'effectiveFrom' => 'required|date', 'note' => 'nullable|string|max:500']);

        return response()->json(['items' => $this->setup->createParameter(AuthUser::current(), $data)], 201);
    }

    public function destroyParameter(string $id): array
    {
        return ['items' => $this->setup->removeParameter(AuthUser::current(), $id)];
    }

    // ── the posting map
    public function accounts(): array
    {
        return ['items' => $this->setup->accounts()];
    }

    public function saveAccounts(Request $request): array
    {
        $rules = ['accounts' => 'required|array'];
        foreach (array_keys(RunPosting::KEYS) as $key) {
            $rules["accounts.{$key}"] = 'sometimes|nullable|string|max:20';
        }

        $request->validate($rules);
        // the whole map as sent: a key the posting map does not know is refused by the service (ACCOUNT_KEY), never silently dropped
        $accounts = array_map(fn ($code) => is_scalar($code) ? (string) $code : null, (array) $request->input('accounts'));

        return ['items' => $this->setup->saveAccounts(AuthUser::current(), $accounts)];
    }

    // ── pay profiles
    public function profiles(Request $request): array
    {
        return $this->setup->profiles(AuthUser::current(), Paging::from($request, 25), $request->only(['department', 'structure', 'schedule', 'policy', 'method', 'status']));
    }

    public function saveProfile(Request $request, string $employee): array
    {
        $data = $request->validate(['structureId' => 'sometimes|nullable|string|max:40', 'scheduleId' => 'sometimes|nullable|string|max:40', 'policyId' => 'sometimes|nullable|string|max:40',
            'paymentMethod' => 'sometimes|in:bank,cash', 'notes' => 'sometimes|nullable|string|max:2000']);

        return $this->setup->saveProfile(AuthUser::current(), $employee, $data);
    }
}
