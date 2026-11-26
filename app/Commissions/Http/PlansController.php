<?php

namespace App\Commissions\Http;

use App\Commissions\Models\CommissionPlan;
use App\Commissions\Models\CommissionPlanRule;
use App\Commissions\Services\PlansService;
use App\Commissions\Support\People;
use App\Http\Controllers\Controller;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Commission plans. Thin: validate (camelCase keys, exactly as the client sends them), call the service, return.
 * A plan holds other people's rates: reading one needs commission.manage or commission.approve.
 */
class PlansController extends Controller
{
    public function __construct(private readonly PlansService $plans) {}

    public function index(Request $request): array
    {
        People::needAny(AuthUser::current(), People::MANAGE, People::APPROVE);

        return $this->plans->list(Paging::from($request, 25), $request->only(['status', 'earnerType', 'source']));
    }

    public function show(string $id): array
    {
        People::needAny(AuthUser::current(), People::MANAGE, People::APPROVE);

        return $this->plans->get($id);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->plans->create(AuthUser::current(), $request->validate(self::rules())), 201);
    }

    /** The form sends the whole plan: what may still change is decided by the service (terms lock once statements left the draft state). */
    public function update(Request $request, string $id): array
    {
        return $this->plans->update(AuthUser::current(), $id, $request->validate(self::rules()));
    }

    public function activate(string $id): array
    {
        return $this->plans->activate(AuthUser::current(), $id);
    }

    public function archive(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        return $this->plans->archive(AuthUser::current(), $id, $data['reason'] ?? null);
    }

    public function duplicate(string $id): JsonResponse
    {
        return response()->json($this->plans->duplicate(AuthUser::current(), $id), 201);
    }

    private static function rules(): array
    {
        return [
            'nameAr' => 'required|string|min:2|max:190', 'nameEn' => 'nullable|string|max:190',
            'earnerType' => ['required', Rule::in(CommissionPlan::EARNERS)], 'beneficiary' => 'nullable|string|max:60',
            'everyone' => 'nullable|boolean', 'members' => 'nullable|array|max:300', 'members.*' => 'string|max:60',
            'earnOn' => ['required', Rule::in(CommissionPlan::EARN_ON)], 'base' => ['required', Rule::in(CommissionPlan::BASES)], 'netOfReturns' => 'nullable|boolean',
            'validFrom' => 'required|date_format:Y-m-d', 'validTo' => 'nullable|date_format:Y-m-d', 'notes' => 'nullable|string|max:2000',
            'rules' => 'required|array|min:1|max:50',
            'rules.*.scope' => ['required', Rule::in(CommissionPlanRule::SCOPES)], 'rules.*.scopeIds' => 'nullable|array|max:500', 'rules.*.scopeIds.*' => 'string|max:40',
            'rules.*.rateType' => ['required', Rule::in(CommissionPlanRule::RATE_TYPES)], 'rules.*.rate' => 'nullable|numeric|min:0|max:100',
            'rules.*.tierMode' => ['nullable', Rule::in(CommissionPlanRule::TIER_MODES)], 'rules.*.tiers' => 'nullable|array|max:20',
            'rules.*.tiers.*.upTo' => 'nullable|numeric|gt:0', 'rules.*.tiers.*.rate' => 'nullable|numeric|min:0|max:100', 'rules.*.label' => 'nullable|string|max:190',
        ];
    }
}
