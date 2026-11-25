<?php

namespace App\Hr\Http;

use App\Hr\Services\ContractsService;
use App\Http\Controllers\Controller;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Employment contracts — every route of this controller is behind `hr.private`. */
class ContractsController extends Controller
{
    private const RULES = [
        'reference' => 'nullable|string|max:200', 'type' => 'in:permanent,fixed_term,temporary,part_time,trainee,other', 'jobTitle' => 'nullable|string|max:200',
        'departmentId' => 'nullable|string|max:40', 'startDate' => 'date', 'endDate' => 'nullable|date', 'trialEndDate' => 'nullable|date',
        'wage' => 'numeric|min:0|max:99999999', 'housingAllowance' => 'nullable|numeric|min:0|max:99999999', 'transportAllowance' => 'nullable|numeric|min:0|max:99999999',
        'otherAllowances' => 'nullable|numeric|min:0|max:99999999', 'notes' => 'nullable|string|max:2000',
    ];

    public function __construct(private readonly ContractsService $contracts) {}

    public function index(Request $request): array
    {
        return $this->contracts->list(AuthUser::current(), Paging::from($request, 25), $request->only(['state', 'employee', 'type', 'ending', 'source', 'order']));
    }

    public function show(string $id): array
    {
        return $this->contracts->get(AuthUser::current(), $id);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['employee' => 'required|string|max:40'] + self::rules(['startDate', 'wage']));

        return response()->json($this->contracts->create(AuthUser::current(), $data), 201);
    }

    public function update(Request $request, string $id): array
    {
        return $this->contracts->update(AuthUser::current(), $id, $request->validate(self::rules()));
    }

    public function start(string $id): JsonResponse
    {
        return response()->json($this->contracts->start(AuthUser::current(), $id), 201);
    }

    public function end(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['date' => 'nullable|date', 'reason' => 'nullable|string|max:500']);

        return response()->json($this->contracts->end(AuthUser::current(), $id, $data['date'] ?? null, $data['reason'] ?? null), 201);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:500']);

        return response()->json($this->contracts->cancel(AuthUser::current(), $id, $data['reason']), 201);
    }

    public function renew(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['startDate' => 'nullable|date', 'endDate' => 'nullable|date']);

        return response()->json($this->contracts->renew(AuthUser::current(), $id, $data), 201);
    }

    private static function rules(array $required = []): array
    {
        $out = [];
        foreach (self::RULES as $field => $rule) {
            $out[$field] = (in_array($field, $required, true) ? 'required|' : 'sometimes|').$rule;
        }

        return $out;
    }
}
