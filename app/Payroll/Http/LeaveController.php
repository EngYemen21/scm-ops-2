<?php

namespace App\Payroll\Http;

use App\Http\Controllers\Controller;
use App\Payroll\Services\LeaveLedger;
use App\Payroll\Services\LeaveService;
use App\Payroll\Support\Access;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Time off: requests, decisions, the calendar, balances and the ledger behind them. */
class LeaveController extends Controller
{
    public function __construct(private readonly LeaveService $leaves, private readonly LeaveLedger $ledger) {}

    public function index(Request $request): array
    {
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);

        return $this->leaves->list(AuthUser::current(), Paging::from($request, 25), $request->only(['scope', 'state', 'type', 'employee', 'department', 'from', 'to', 'source']));
    }

    public function calendar(Request $request): array
    {
        $data = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date', 'department' => 'nullable|string|max:40']);
        $from = isset($data['from']) ? Carbon::parse($data['from']) : Carbon::today()->startOfMonth();
        $to = isset($data['to']) ? Carbon::parse($data['to']) : $from->copy()->endOfMonth();

        return $this->leaves->calendar(AuthUser::current(), $from->format('Y-m-d'), $to->format('Y-m-d'), $data['department'] ?? null);
    }

    public function show(string $id): array
    {
        return $this->leaves->get(AuthUser::current(), $id);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['employee' => 'nullable|string|max:40', 'type' => 'required|string|max:40', 'dateFrom' => 'required|date', 'dateTo' => 'nullable|date', 'halfDay' => 'nullable|boolean',
            'reason' => 'nullable|string|max:2000', 'submit' => 'nullable|boolean']);

        return response()->json($this->leaves->create(AuthUser::current(), $data), 201);
    }

    public function update(Request $request, string $id): array
    {
        $data = $request->validate(['type' => 'sometimes|string|max:40', 'dateFrom' => 'sometimes|date', 'dateTo' => 'sometimes|nullable|date', 'halfDay' => 'sometimes|boolean', 'reason' => 'sometimes|nullable|string|max:2000']);

        return $this->leaves->update(AuthUser::current(), $id, $data);
    }

    public function submit(string $id): JsonResponse
    {
        return response()->json($this->leaves->submit(AuthUser::current(), $id), 201);
    }

    public function withdraw(string $id): JsonResponse
    {
        return response()->json($this->leaves->withdraw(AuthUser::current(), $id), 201);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => 'nullable|string|max:500']);

        return response()->json($this->leaves->approve(AuthUser::current(), $id, $data['note'] ?? null), 201);
    }

    public function refuse(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:500']);

        return response()->json($this->leaves->refuse(AuthUser::current(), $id, $data['reason']), 201);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        return response()->json($this->leaves->cancel(AuthUser::current(), $id, $data['reason'] ?? null), 201);
    }

    // ── balances and the ledger

    public function balances(Request $request): array
    {
        $data = $request->validate(['employee' => 'nullable|string|max:40', 'year' => 'nullable|integer|min:2000|max:2100']);

        return $this->ledger->balancesFor(AuthUser::current(), $data['employee'] ?? null, (int) ($data['year'] ?? Carbon::today()->format('Y')));
    }

    public function entries(Request $request): array
    {
        return $this->ledger->entries(AuthUser::current(), Paging::from($request, 25), $request->only(['employee', 'type', 'year', 'kind']));
    }

    public function allocate(Request $request): JsonResponse
    {
        $data = $request->validate(['employee' => 'required|string|max:40', 'type' => 'required|string|max:40', 'year' => 'required|integer|min:2000|max:2100', 'days' => 'required|numeric|min:-366|max:366',
            'kind' => 'nullable|in:allocation,adjustment', 'note' => 'nullable|string|max:500']);

        return response()->json($this->ledger->allocate(AuthUser::current(), $data), 201);
    }

    public function grantYear(Request $request): JsonResponse
    {
        $data = $request->validate(['year' => 'required|integer|min:2000|max:2100', 'type' => 'nullable|string|max:40']);

        return response()->json($this->ledger->grantYear(AuthUser::current(), (int) $data['year'], $data['type'] ?? null), 201);
    }

    public function reconcile(): array
    {
        if (! Access::seesAllLeave(AuthUser::current())) {
            throw Access::denied();
        }

        return $this->ledger->reconcile();
    }
}
