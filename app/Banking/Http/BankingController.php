<?php

namespace App\Banking\Http;

use App\Banking\Models\Statement;
use App\Banking\Models\WriteoffRule;
use App\Banking\Services\Banks;
use App\Banking\Services\ImportService;
use App\Banking\Services\ReconciliationService;
use App\Banking\Services\SourceJournalsService;
use App\Banking\Services\SuggestionService;
use App\Banking\Support\Mask;
use App\Banking\Support\Owned;
use App\Banking\Support\Shape;
use App\Banking\Support\Sheet\ColumnMap;
use App\Finance\Models\Account;
use App\Finance\Models\BankAccount;
use App\Finance\Services\LedgerService;
use App\Http\Controllers\Controller;
use App\Support\AuthUser;
use Illuminate\Http\Request;

/** The section's home: its lookups, the bank accounts with where each stands, the reconciliation statement, and the source system's journals. */
class BankingController extends Controller
{
    public function __construct(
        private readonly ReconciliationService $reconciliation,
        private readonly SourceJournalsService $journals,
        private readonly ImportService $imports,
        private readonly Banks $banks,
    ) {}

    /** Everything the forms and the filters of the section offer, in one request. */
    public function lookups(): array
    {
        $user = AuthUser::current();
        $full = Mask::sees($user);
        $bankLedgers = BankAccount::pluck('account_id')->all();

        return [
            // the section follows Odoo: what came from Odoo is read-only here, OPS adds the links and its own work
            'follows' => Owned::following(), 'seesNumbers' => $full,
            'banks' => BankAccount::with('account:id,code,name_ar,name_en')->orderByDesc('active')->orderBy('code')->get()
                ->map(fn (BankAccount $b) => Shape::bank($b, $full) + ['sharedWith' => array_column($this->banks->sharing($b), 'code')])->all(),
            'rules' => WriteoffRule::with(['account:id,code,name_ar,name_en,is_postable,active', 'bankAccount:id,code,name_ar,name_en,kind'])->where('active', true)->orderBy('sequence')->orderBy('created_at')->orderBy('id')->get()
                ->map(fn (WriteoffRule $r) => Shape::rule($r))->all(),
            // the accounts a write-off rule may post to: postable, not a customers / suppliers control account, not a bank's own account
            'accounts' => Account::where('is_postable', true)->where('active', true)->whereNotIn('id', $bankLedgers)
                ->where(fn ($w) => $w->whereNull('subtype')->orWhereNotIn('subtype', array_keys(LedgerService::CONTROL_SUBTYPES)))->orderBy('code')->get(['id', 'code', 'name_ar', 'name_en', 'type'])
                ->map(fn (Account $a) => ['id' => $a->id, 'code' => $a->code, 'nameAr' => $a->name_ar, 'nameEn' => $a->name_en, 'type' => $a->type])->all(),
            'profiles' => $this->imports->profiles(null),
            'statementStatuses' => Statement::STATUSES, 'natures' => WriteoffRule::NATURES, 'amountModes' => ColumnMap::AMOUNT_MODES, 'importFields' => ColumnMap::FIELDS,
            'window' => ['default' => SuggestionService::DEFAULT_WINDOW, 'max' => SuggestionService::MAX_WINDOW],
            'can' => ['statements' => $user->can('bankstatement.manage'), 'reconcile' => $user->can('bank.reconcile'), 'createBank' => $user->can('bank.manage')],
        ];
    }

    public function accounts(): array
    {
        return ['items' => $this->reconciliation->accounts(AuthUser::current())];
    }

    public function reconciliation(Request $request): array
    {
        $data = $request->validate(['bank' => 'required|string|max:40', 'date' => ['nullable', Rules::DAY]]);

        return $this->reconciliation->at(AuthUser::current(), $data['bank'], $data['date'] ?? null);
    }

    // ── the source system's bank and cash journals

    public function journals(Request $request): array
    {
        $filters = Rules::filters($request, ['linked' => 'nullable|in:0,1', 'kind' => 'nullable|in:bank,cash', 'active' => 'nullable|in:0,1']);

        return $this->journals->list(AuthUser::current(), Rules::ascending($request), $filters);
    }

    public function linkJournal(Request $request, string $id): array
    {
        $data = $request->validate(['bankAccount' => 'required|string|max:40']);

        return $this->journals->link(AuthUser::current(), $id, $data['bankAccount']);
    }

    public function unlinkJournal(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:500']);

        return $this->journals->unlink(AuthUser::current(), $id, $data['reason']);
    }

    public function adoptJournal(Request $request, string $id): array
    {
        $data = $request->validate(['code' => 'nullable|string|min:2|max:20', 'nameAr' => 'nullable|string|max:190', 'nameEn' => 'nullable|string|max:190']);

        return $this->journals->adopt(AuthUser::current(), $id, $data);
    }
}
