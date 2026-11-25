<?php

namespace App\Banking\Http;

use App\Banking\Services\Banks;
use App\Banking\Services\BookItems;
use App\Banking\Services\MatchingService;
use App\Banking\Services\RulesService;
use App\Banking\Services\SuggestionService;
use App\Http\Controllers\Controller;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The books' side of a bank account, the proposals for a statement line, the matches (confirm, undo) and the write-off rules. */
class MatchingController extends Controller
{
    public function __construct(
        private readonly MatchingService $matching,
        private readonly SuggestionService $suggestions,
        private readonly BookItems $books,
        private readonly Banks $banks,
        private readonly RulesService $rules,
    ) {}

    /** The entries of the bank's ledger account — receipts, payments, expenses, journals — matched or not. */
    public function ledgerItems(Request $request): array
    {
        $filters = Rules::filters($request, ['bank' => 'required|string|max:40', 'state' => 'nullable|in:unmatched,matched,all', 'sign' => 'nullable|in:in,out',
            'kind' => 'nullable|in:receipt,payment,expense,journal,reversal,writeoff', 'from' => ['nullable', Rules::DAY], 'to' => ['nullable', Rules::DAY], 'amount' => ['nullable', Rules::money()]]);

        return $this->books->list($this->banks->find($filters['bank']), Paging::from($request), $filters);
    }

    public function suggestions(Request $request, string $id): array
    {
        $data = $request->validate(['window' => 'nullable|integer|min:0|max:'.SuggestionService::MAX_WINDOW]);

        return $this->suggestions->forLine(AuthUser::current(), $id, (int) ($data['window'] ?? SuggestionService::DEFAULT_WINDOW));
    }

    public function index(Request $request): array
    {
        $filters = Rules::filters($request, ['bank' => 'nullable|string|max:40', 'status' => 'nullable|in:active,undone', 'kind' => 'nullable|in:match,contra,system', 'writeoff' => 'nullable|in:0,1',
            'from' => ['nullable', Rules::DAY], 'to' => ['nullable', Rules::DAY]]);

        return $this->matching->list(AuthUser::current(), Paging::from($request, 25), $filters);
    }

    public function show(string $id): array
    {
        return $this->matching->get(AuthUser::current(), $id);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'statementLines' => 'sometimes|array|max:'.MatchingService::MAX_SIDE, 'statementLines.*' => 'string|max:40', 'ledgerLines' => 'sometimes|array|max:'.MatchingService::MAX_SIDE, 'ledgerLines.*' => 'string|max:40',
            // a match without statement lines (ledger entries that cancel each other) names its bank account
            'bankAccount' => 'required_without:statementLines|nullable|string|max:40', 'writeOff' => 'sometimes|nullable|array', 'writeOff.rule' => 'required_with:writeOff|string|max:40',
            'writeOff.date' => ['nullable', Rules::DAY], 'note' => 'nullable|string|max:1000', 'origin' => 'nullable|in:manual,suggestion',
        ]);

        return response()->json($this->matching->confirm(AuthUser::current(), $data), 201);
    }

    public function undo(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:500', 'date' => ['nullable', Rules::DAY]]);

        return $this->matching->undo(AuthUser::current(), $id, $data['reason'], $data['date'] ?? null);
    }

    // ── write-off rules

    public function rules(Request $request): array
    {
        $filters = Rules::filters($request, ['active' => 'nullable|in:0,1', 'origin' => 'nullable|in:ops,odoo', 'kind' => 'nullable|in:writeoff,matching', 'nature' => 'nullable|in:in,out,both', 'bank' => 'nullable|string|max:40']);

        return $this->rules->list(Rules::ascending($request), $filters);
    }

    public function rule(string $id): array
    {
        return $this->rules->get($id);
    }

    public function storeRule(Request $request): JsonResponse
    {
        $data = $request->validate(['nameAr' => 'required|string|min:1|max:190', 'account' => 'required|string|max:40', 'maxAmount' => ['required', Rules::money(signed: false, zero: false)]] + self::ruleRules());

        return response()->json($this->rules->create(AuthUser::current(), self::typed($data)), 201);
    }

    public function updateRule(Request $request, string $id): array
    {
        $data = $request->validate(['nameAr' => 'sometimes|string|min:1|max:190', 'account' => 'sometimes|nullable|string|max:40', 'maxAmount' => ['sometimes', 'nullable', Rules::money(signed: false, zero: false)],
            'active' => 'sometimes|boolean', 'kind' => 'sometimes|in:writeoff,matching'] + self::ruleRules());

        return $this->rules->update(AuthUser::current(), $id, self::typed($data));
    }

    private static function ruleRules(): array
    {
        return ['nameEn' => 'sometimes|nullable|string|max:190', 'nature' => 'sometimes|in:in,out,both', 'label' => 'sometimes|nullable|string|max:190', 'matchText' => 'sometimes|nullable|string|max:250',
            'bankAccount' => 'sometimes|nullable|string|max:40', 'sequence' => 'sometimes|integer|min:0|max:100000'];
    }

    private static function typed(array $data): array
    {
        if (array_key_exists('active', $data)) {
            $data['active'] = filter_var($data['active'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($data['sequence'])) {
            $data['sequence'] = (int) $data['sequence'];
        }

        return $data;
    }

    public function terms(Request $request): array
    {
        $filters = Rules::filters($request, ['active' => 'nullable|in:0,1', 'origin' => 'nullable|in:ops,odoo']);

        return $this->rules->terms(Rules::ascending($request), $filters);
    }
}
