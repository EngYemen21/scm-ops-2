<?php

namespace App\Commissions\Services;

use App\Commissions\Models\CommissionStatement;
use App\Finance\Models\Account;
use App\Finance\Models\BankAccount;
use App\Finance\Models\CostCenter;
use App\Finance\Models\Expense;
use App\Finance\Services\ExpensesService;
use App\Support\AuthUser;

/**
 * The bridge from an approved statement to the books. The payout is ONE expense voucher of the finance module, made by
 * `App\Finance\Services\ExpensesService` and by nothing else — the ledger is never written from here:
 *
 *   payout   ExpensesService::create(settlement `bank`)   Dr the commission expense account / Cr the bank account or
 *            cash box it left from, cost center SALES, dated the day of the transfer, its reference on the voucher;
 *   reverse  ExpensesService::void                         the voucher's journal is reversed, both stay in the ledger.
 *
 * Every method runs inside the caller's database transaction: a ledger refusal (a closed period, an account that does
 * not take postings) leaves the statement exactly as it was.
 *
 * WHAT THE FINANCE SIDE LACKS TODAY (the wiring notes of the report):
 *   - no "sales commissions" account in the default chart and no posting role for it: the person who releases the
 *     payout picks the expense account (the last one used is offered);
 *   - no payment flow for accrued expenses: recognising the expense on approval (settlement `accrued`) and paying it
 *     later needs a finance call that settles an accrual. The day it exists, approval calls it from `accrue()` here and
 *     `payout()` settles instead of posting — nothing else changes;
 *   - no person on a voucher or a journal line: the statement number in the description is the link.
 */
class CommissionPosting
{
    /** The cost center commissions are charged to, when the chart has it. */
    public const COST_CENTER = 'SALES';

    public function __construct(private readonly ExpensesService $expenses) {}

    /**
     * @param  array{account:string, bankAccount:string, reference:string, date?:?string}  $o
     * @return array<string, mixed> the voucher as the finance module returns it (id, number, total, account, journal …)
     */
    public function payout(AuthUser $user, CommissionStatement $s, string $beneficiaryName, array $o): array
    {
        return $this->expenses->create($user, [
            'date' => $o['date'] ?? null, 'account' => $o['account'], 'amount' => (float) $s->total, 'vatPct' => 0, 'settlement' => 'bank', 'bankAccount' => $o['bankAccount'],
            'costCenter' => CostCenter::where('code', self::COST_CENTER)->exists() ? self::COST_CENTER : null,
            'description' => mb_substr("عمولة مبيعات — الكشف {$s->number} — {$beneficiaryName} — {$s->period}", 0, 500), 'reference' => $o['reference'],
        ]);
    }

    /** Takes the voucher of a paid statement back. @return string|null the number of the voucher */
    public function reverse(AuthUser $user, CommissionStatement $s, string $reason): ?string
    {
        if (! $s->finance_expense_id) {
            return null;
        }
        if (Expense::whereKey($s->finance_expense_id)->value('status') === 'posted') {   // finance may have voided it from its own screen already
            $this->expenses->void($user, $s->finance_expense_id, mb_substr("عكس صرف كشف العمولة {$s->number}: {$reason}", 0, 480));
        }

        return $s->finance_expense_number;
    }

    /**
     * What the payout form offers: the expense accounts and the bank accounts / cash boxes of the chart, and the pair
     * the last payout used.
     *
     * @return array{accounts: array<int, array<string, mixed>>, banks: array<int, array<string, mixed>>, defaults: array{account: ?string, bankAccount: ?string}, costCenter: ?string}
     */
    public function options(): array
    {
        $accounts = Account::where('type', 'expense')->where('is_postable', true)->where('active', true)->orderBy('code')->get(['code', 'name_ar', 'name_en']);
        $banks = BankAccount::where('active', true)->orderBy('code')->get(['code', 'name_ar', 'name_en', 'kind']);
        $last = CommissionStatement::whereNotNull('payout_account')->whereNotNull('finance_expense_id')->orderByDesc('paid_at')->first(['payout_account', 'payout_bank']);
        $named = $accounts->first(fn (Account $a) => str_contains((string) $a->name_ar, 'عمول') || str_contains(mb_strtolower((string) $a->name_en), 'commission'));

        return [
            'accounts' => $accounts->map(fn (Account $a) => ['code' => $a->code, 'nameAr' => $a->name_ar, 'nameEn' => $a->name_en])->values()->all(),
            'banks' => $banks->map(fn (BankAccount $b) => ['code' => $b->code, 'nameAr' => $b->name_ar, 'nameEn' => $b->name_en, 'kind' => $b->kind])->values()->all(),
            'defaults' => [
                'account' => $last && $accounts->contains('code', $last->payout_account) ? $last->payout_account : $named?->code,
                'bankAccount' => $last && $banks->contains('code', $last->payout_bank) ? $last->payout_bank : ($banks->firstWhere('code', 'BANK')?->code ?? $banks->first()?->code),
            ],
            'costCenter' => CostCenter::where('code', self::COST_CENTER)->exists() ? self::COST_CENTER : null,
        ];
    }
}
