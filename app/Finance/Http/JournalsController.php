<?php

namespace App\Finance\Http;

use App\Finance\Services\LedgerService;
use App\Http\Controllers\Controller;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Journals: list / detail / source lookup, manual journals (draft → approve), reversals. */
class JournalsController extends Controller
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function index(Request $request): array
    {
        return $this->ledger->list(Paging::from($request), $request->only(['status', 'kind', 'period', 'from', 'to', 'account', 'sourceType', 'sourceNumber', 'partnerType', 'partnerId']));
    }

    public function show(string $id): array
    {
        return $this->ledger->get($id);
    }

    public function forSource(string $type, string $id): array
    {
        return ['items' => $this->ledger->forSource($type, $id)];
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->ledger->createDraft(AuthUser::current(), $request->validate(self::rules())), 201);
    }

    public function update(Request $request, string $id): array
    {
        return $this->ledger->updateDraft(AuthUser::current(), $id, $request->validate(self::rules()));
    }

    public function destroy(string $id): array
    {
        return $this->ledger->deleteDraft(AuthUser::current(), $id);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => 'nullable|string|max:500']);

        return response()->json($this->ledger->approve(AuthUser::current(), $id, $data['note'] ?? null), 201);
    }

    public function reverse(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:500', 'date' => 'nullable|date']);

        return response()->json($this->ledger->reverse(AuthUser::current(), $id, $data['reason'], $data['date'] ?? null)->load('lines')->toArray(), 201);
    }

    private static function rules(): array
    {
        return [
            'date' => 'required|date', 'memo' => 'required|string|min:3|max:500', 'lines' => 'required|array|min:2',
            'lines.*.account' => 'required|string', 'lines.*.debit' => 'nullable|numeric|min:0', 'lines.*.credit' => 'nullable|numeric|min:0', 'lines.*.description' => 'nullable|string|max:500',
            'lines.*.costCenter' => 'nullable|string', 'lines.*.partnerType' => 'nullable|in:customer,supplier', 'lines.*.partnerId' => 'nullable|string',
        ];
    }
}
