<?php

namespace App\Commissions\Http;

use App\Commissions\Services\CalculationService;
use App\Commissions\Services\StatementsService;
use App\Http\Controllers\Controller;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Commission statements and their lines. Thin: validate, call the service, return. Who sees which statement, and which
 * step is open in which state, is decided by the service.
 */
class StatementsController extends Controller
{
    public function __construct(private readonly StatementsService $statements, private readonly CalculationService $calculation) {}

    public function index(Request $request): array
    {
        return $this->statements->list(AuthUser::current(), Paging::from($request, 25), $request->only(['status', 'period', 'from', 'to', 'beneficiary', 'plan', 'source', 'mine']));
    }

    public function show(string $id): array
    {
        return $this->statements->get(AuthUser::current(), $id);
    }

    public function lines(Request $request, string $id): array
    {
        return $this->statements->lines(AuthUser::current(), $id, Paging::from($request, 50), $request->only(['kind', 'excluded', 'customer', 'salesperson', 'from', 'to']));
    }

    public function allLines(Request $request): array
    {
        return $this->statements->allLines(AuthUser::current(), Paging::from($request, 50), $request->only(['kind', 'excluded', 'customer', 'salesperson', 'from', 'to', 'period', 'status', 'beneficiary']));
    }

    public function recalculate(string $id): array
    {
        $user = AuthUser::current();
        $statement = $this->statements->find($id);
        $run = $this->calculation->recalculate($user, $statement);
        // a draft that nothing justifies any more is withdrawn by the run: then there is no statement to show
        $withdrawn = collect($run['statements'])->contains(fn (array $x) => $x['outcome'] === 'removed' && $x['id'] === $statement->id);

        return ['run' => $run, 'statement' => $withdrawn ? null : $this->statements->get($user, $id)];
    }

    public function adjust(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['amount' => 'required|numeric|between:-99999999,99999999', 'reason' => 'required|string|min:5|max:1000']);

        return response()->json($this->statements->adjust(AuthUser::current(), $id, (float) $data['amount'], $data['reason']), 201);
    }

    public function voidLine(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:1000']);

        return $this->statements->voidAdjustment(AuthUser::current(), $id, $data['reason']);
    }

    public function excludeLine(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:250']);

        return $this->statements->exclude(AuthUser::current(), $id, $data['reason']);
    }

    public function includeLine(string $id): array
    {
        return $this->statements->includeAgain(AuthUser::current(), $id);
    }

    public function submit(Request $request, string $id): array
    {
        $data = $request->validate(['note' => 'nullable|string|max:1000']);

        return $this->statements->submit(AuthUser::current(), $id, $data['note'] ?? null);
    }

    public function approve(Request $request, string $id): array
    {
        $data = $request->validate(['note' => 'nullable|string|max:1000']);

        return $this->statements->approve(AuthUser::current(), $id, $data['note'] ?? null);
    }

    public function sendBack(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:1000']);

        return $this->statements->sendBack(AuthUser::current(), $id, $data['reason']);
    }

    public function payout(Request $request, string $id): array
    {
        $data = $request->validate(['account' => 'nullable|string|max:40', 'bankAccount' => 'nullable|string|max:40', 'reference' => 'nullable|string|max:80', 'date' => 'nullable|date_format:Y-m-d']);

        return $this->statements->payout(AuthUser::current(), $id, $data);
    }

    public function voidPayout(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:500']);

        return $this->statements->voidPayout(AuthUser::current(), $id, $data['reason']);
    }
}
