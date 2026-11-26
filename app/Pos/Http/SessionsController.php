<?php

namespace App\Pos\Http;

use App\Http\Controllers\Controller;
use App\Pos\Services\HeldSalesService;
use App\Pos\Services\ReportService;
use App\Pos\Services\SessionsService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Sessions (open, cash in / out, close, the end-of-day report) and the sales held in them. */
class SessionsController extends Controller
{
    public function __construct(private readonly SessionsService $sessions, private readonly ReportService $reports, private readonly HeldSalesService $held) {}

    public function index(Request $request): array
    {
        return $this->sessions->list(Paging::from($request), $request->only(['state', 'register', 'cashier', 'from', 'to', 'source']), AuthUser::current());
    }

    public function current(): JsonResponse
    {
        return response()->json(['session' => $this->sessions->current(AuthUser::current())]);
    }

    public function show(string $id): array
    {
        return $this->sessions->get($id);
    }

    public function report(string $id): array
    {
        return $this->reports->forSession($id, AuthUser::current());
    }

    public function open(Request $request): JsonResponse
    {
        $data = $request->validate(['register' => 'required|string|max:60', 'openingCash' => 'nullable|numeric|min:0|max:10000000', 'note' => 'nullable|string|max:500'] + self::countRules('counts', false));

        return response()->json($this->sessions->open(AuthUser::current(), $data), 201);
    }

    public function close(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500', 'note' => 'nullable|string|max:500', 'discardHeld' => 'sometimes|boolean'] + self::countRules('counts', false));

        return $this->sessions->close(AuthUser::current(), $id, $data);
    }

    public function cashMove(Request $request, string $id): array
    {
        $data = $request->validate(['direction' => 'required|in:in,out', 'amount' => 'required|numeric|min:0.01|max:10000000', 'reason' => 'required|string|max:500', 'bankAccount' => 'required|string|max:60']);

        return $this->sessions->cashMove(AuthUser::current(), $id, $data);
    }

    private static function countRules(string $key, bool $required): array
    {
        return [$key => ($required ? 'required' : 'nullable').'|array|max:40', "{$key}.*.value" => 'required|numeric|min:0.01', "{$key}.*.count" => 'required|integer|min:0|max:1000000'];
    }

    // ───────────── held sales ─────────────

    public function held(string $id): array
    {
        return $this->held->list($id);
    }

    public function hold(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session' => 'required|string|max:60', 'label' => 'nullable|string|max:120', 'customer' => 'nullable|string|max:60', 'clientKey' => 'nullable|string|max:64', 'total' => 'nullable|numeric',
            'lines' => 'required|array|min:1|max:300', 'lines.*.productId' => 'required|string|max:60', 'lines.*.qty' => 'required|integer|min:1|max:100000', 'lines.*.discountPct' => 'nullable|numeric|min:0|max:100',
        ]);

        return response()->json($this->held->hold(AuthUser::current(), $data), 201);
    }

    public function recall(string $id): array
    {
        return $this->held->recall(AuthUser::current(), $id);
    }

    public function discard(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'nullable|string|max:250']);

        return $this->held->discard(AuthUser::current(), $id, $data['reason'] ?? null);
    }
}
