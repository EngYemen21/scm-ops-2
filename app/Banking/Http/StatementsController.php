<?php

namespace App\Banking\Http;

use App\Banking\Models\Statement;
use App\Banking\Services\ImportService;
use App\Banking\Services\MatchingService;
use App\Banking\Services\StatementsService;
use App\Banking\Services\SuggestionService;
use App\Banking\Support\Sheet\SheetFile;
use App\Http\Controllers\Controller;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Bank statements, their lines, the import of a bank's file and the column mappings kept per bank account.
 * Thin: validate (camelCase keys, exactly as the client sends them), call a service, return.
 */
class StatementsController extends Controller
{
    public function __construct(private readonly StatementsService $statements, private readonly ImportService $imports, private readonly MatchingService $matching) {}

    public function index(Request $request): array
    {
        $filters = Rules::filters($request, ['bank' => 'nullable|string|max:40', 'journal' => 'nullable|string|max:40', 'status' => 'nullable|string|max:60', 'origin' => 'nullable|in:ops,odoo',
            'from' => ['nullable', Rules::DAY], 'to' => ['nullable', Rules::DAY]]);

        return $this->statements->list(Paging::from($request, 25), $filters);
    }

    public function show(string $id): array
    {
        return $this->statements->get(AuthUser::current(), $id);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bankAccount' => 'required|string|max:40', 'name' => 'nullable|string|max:190', 'dateFrom' => ['required', Rules::DAY], 'dateTo' => ['required', Rules::DAY],
            'openingBalance' => ['required', Rules::money()], 'closingBalance' => ['required', Rules::money()], 'notes' => 'nullable|string|max:2000',
        ]);

        return response()->json($this->statements->create(AuthUser::current(), $data), 201);
    }

    public function update(Request $request, string $id): array
    {
        $data = $request->validate([
            'name' => 'sometimes|nullable|string|max:190', 'dateFrom' => ['sometimes', Rules::DAY], 'dateTo' => ['sometimes', Rules::DAY], 'openingBalance' => ['sometimes', Rules::money()],
            'closingBalance' => ['sometimes', Rules::money()], 'notes' => 'sometimes|nullable|string|max:2000',
        ]);

        return $this->statements->update(AuthUser::current(), $id, $data);
    }

    public function void(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:500']);

        return $this->statements->void(AuthUser::current(), $id, $data['reason']);
    }

    public function reconcile(string $id): array
    {
        return $this->statements->reconcile(AuthUser::current(), $id);
    }

    public function reopen(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:500']);

        return $this->statements->reopen(AuthUser::current(), $id, $data['reason']);
    }

    public function autoMatch(Request $request, string $id): array
    {
        $data = $request->validate(['window' => 'nullable|integer|min:0|max:'.SuggestionService::MAX_WINDOW]);

        return $this->matching->auto(AuthUser::current(), $id, (int) ($data['window'] ?? SuggestionService::DEFAULT_WINDOW));
    }

    // ── lines

    public function lines(Request $request): array
    {
        $filters = Rules::filters($request, ['statement' => 'required_without:bank|nullable|string|max:40', 'bank' => 'required_without:statement|nullable|string|max:40',
            'state' => 'nullable|in:'.implode(',', StatementsService::LINE_STATES), 'sign' => 'nullable|in:in,out', 'from' => ['nullable', Rules::DAY], 'to' => ['nullable', Rules::DAY], 'amount' => ['nullable', Rules::money()]]);

        return $this->statements->lines(AuthUser::current(), Rules::ascending($request), $filters);
    }

    public function line(string $id): array
    {
        return $this->statements->line(AuthUser::current(), $id);
    }

    public function storeLines(Request $request, string $id): JsonResponse
    {
        // one line (its fields at the top) or several (`lines`)
        $rules = self::lineRules('required');
        if ($request->has('lines')) {
            $data = $request->validate(['lines' => 'required|array|min:1|max:500'] + collect($rules)->mapWithKeys(fn ($rule, $key) => ["lines.*.{$key}" => $rule])->all());
            $lines = $data['lines'];
        } else {
            $lines = [$request->validate($rules)];
        }

        return response()->json($this->statements->addLines(AuthUser::current(), $id, $lines), 201);
    }

    public function updateLine(Request $request, string $id): array
    {
        return $this->statements->updateLine(AuthUser::current(), $id, $request->validate(self::lineRules('sometimes')));
    }

    public function destroyLine(string $id): array
    {
        return $this->statements->removeLine(AuthUser::current(), $id);
    }

    private static function lineRules(string $presence): array
    {
        return [
            'date' => [$presence, Rules::DAY], 'amount' => [$presence, Rules::money(zero: false)], 'description' => 'sometimes|nullable|string|max:500', 'reference' => 'sometimes|nullable|string|max:120',
            'valueDate' => ['sometimes', 'nullable', Rules::DAY], 'partnerName' => 'sometimes|nullable|string|max:190', 'partnerAccount' => 'sometimes|nullable|string|max:64', 'type' => 'sometimes|nullable|string|max:60',
            'balanceAfter' => ['sometimes', 'nullable', Rules::money()],
        ];
    }

    // ── import

    public function preview(Request $request): array
    {
        $data = $request->validate(SheetFile::rules() + ['bankAccount' => 'nullable|string|max:40', 'profile' => 'nullable|string|max:40'] + self::readingRules());

        return $this->imports->preview(['fileName' => $data['fileName'], 'base64' => $data['base64']], $data);
    }

    public function import(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(SheetFile::rules() + ['profile' => 'nullable|string|max:40', 'dryRun' => 'sometimes|boolean', 'saveAs' => 'nullable|string|max:120', 'makeDefault' => 'sometimes|boolean'] + self::readingRules());
        $dryRun = filter_var($data['dryRun'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (array_key_exists('makeDefault', $data)) {
            $data['makeDefault'] = filter_var($data['makeDefault'], FILTER_VALIDATE_BOOLEAN);
        }

        return response()->json($this->imports->run(AuthUser::current(), $id, ['fileName' => $data['fileName'], 'base64' => $data['base64']], $data, $dryRun), $dryRun ? 200 : 201);
    }

    /** How a file is read: the options and the mapping (both optional — a kept mapping or the file's own titles stand in). */
    private static function readingRules(): array
    {
        return [
            'options' => 'sometimes|array', 'options.sheet' => 'nullable|string|max:120', 'options.delimiter' => ['nullable', Rule::in([',', ';', '|', 'tab'])], 'options.encoding' => 'nullable|in:utf-8,utf-16,windows-1256,windows-1252',
            'options.headerRow' => 'nullable|integer|min:0|max:200', 'options.dateOrder' => 'nullable|in:dmy,mdy,ymd', 'options.decimal' => ['nullable', Rule::in(['.', ','])], 'options.amountMode' => 'nullable|in:signed,debit_credit,amount_type',
            'options.invert' => 'sometimes|boolean', 'options.order' => 'nullable|in:auto,asc,desc', 'mapping' => 'sometimes|array', 'mapping.*' => 'nullable',
        ];
    }

    public function profiles(Request $request): array
    {
        $data = $request->validate(['bank' => 'nullable|string|max:40', 'all' => 'sometimes|boolean']);

        return ['items' => $this->imports->profiles($data['bank'] ?? null, filter_var($data['all'] ?? false, FILTER_VALIDATE_BOOLEAN))];
    }

    public function storeProfile(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|min:1|max:120', 'bankAccount' => 'nullable|string|max:40', 'mapping' => 'required|array', 'mapping.*' => 'nullable', 'isDefault' => 'sometimes|boolean'] + self::readingRules());

        return response()->json($this->imports->saveProfile(AuthUser::current(), self::flags($data)), 201);
    }

    public function updateProfile(Request $request, string $id): array
    {
        $data = $request->validate(['name' => 'sometimes|string|min:1|max:120', 'bankAccount' => 'sometimes|nullable|string|max:40', 'isDefault' => 'sometimes|boolean', 'active' => 'sometimes|boolean'] + self::readingRules());

        return $this->imports->saveProfile(AuthUser::current(), self::flags($data), $id);
    }

    private static function flags(array $data): array
    {
        foreach (['isDefault', 'active'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $data[$flag] = filter_var($data[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $data;
    }

    /** The statuses a statement may have, for the filter of the list. */
    public static function statuses(): array
    {
        return Statement::STATUSES;
    }
}
