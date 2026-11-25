<?php

namespace App\Hr\Services;

use App\Finance\Models\Expense;
use App\Finance\Services\ExpensesService;
use App\Hr\Models\Claim;
use App\Hr\Support\Hr;
use App\Support\AppError;
use App\Support\AuthUser;

/**
 * The bridge from an approved claim to the books: every line becomes ONE expense voucher of the finance module, made
 * by `App\Finance\Services\ExpensesService` and by nothing else — the ledger is never written from here.
 *
 *   a line the COMPANY paid        → settlement `bank`: Dr expense (+ VAT input) / Cr the bank or cash box it left from
 *   a line the EMPLOYEE paid       → settlement `accrued`: Dr expense (+ VAT input) / Cr accrued expenses (the company
 *                                    owes it back), or — "reimbursed now" — settlement `bank` from the cash box / bank
 *
 * A line keeps the figures the voucher computes (amount, VAT % → VAT, total), so the voucher carries the claim's total
 * to the cent; the voucher's description and reference name the claim.
 *
 * WHAT THE FINANCE SIDE LACKS TODAY (see the wiring notes of the report):
 *   - no payment flow for accrued expenses: paying an accrued claim later is recorded on the claim (reference, date,
 *     account) and the accrual is cleared by finance — `settle()` below is where that call belongs the day it exists;
 *   - no VAT amount on a voucher, only a percentage: a receipt whose VAT is not amount × rate cannot be posted exactly;
 *   - no employee on a voucher or a journal line: the claim number in the reference is the link.
 *
 * Every method runs inside the caller's database transaction.
 */
class ClaimPosting
{
    public function __construct(private readonly ExpensesService $expenses) {}

    /**
     * @param  array{date?:?string, bankAccount?:?string, reimburseNow?:bool, costCenter?:?string, accounts?:array<string,?string>}  $o
     * @return string[] the numbers of the vouchers of the claim
     */
    public function post(AuthUser $user, Claim $claim, array $o): array
    {
        $numbers = [];
        foreach ($claim->lines()->with('category')->get() as $line) {
            if ($line->finance_expense_id) { // already a voucher: never a second one for the same line
                $numbers[] = (string) $line->finance_expense_number;

                continue;
            }
            $account = Hr::text($o['accounts'][$line->id] ?? null, 20) ?? $line->account_code ?? $line->category?->account_code;
            if (! $account) {
                throw AppError::rule('CLAIM_ACCOUNT_REQUIRED', "السطر {$line->line_no} بلا حساب مصروف — اختر حسابه أو اربط فئته بحساب من دليل الحسابات", "Line {$line->line_no} has no expense account — pick one or map its category to an account",
                    ['line' => $line->id, 'lineNo' => $line->line_no]);
            }
            $fromBank = $line->paid_by === 'company' || ! empty($o['reimburseNow']);
            if ($fromBank && empty($o['bankAccount'])) {
                throw AppError::rule('CLAIM_BANK_REQUIRED', $line->paid_by === 'company' ? "السطر {$line->line_no} دفعته الشركة — حدِّد الحساب البنكي أو الصندوق الذي دُفع منه" : 'حدِّد الصندوق أو الحساب البنكي الذي يُصرف منه التعويض',
                    $line->paid_by === 'company' ? "Line {$line->line_no} was paid by the company — name the bank account or cash box it was paid from" : 'Name the cash box or bank account the reimbursement is paid from', ['lineNo' => $line->line_no]);
            }
            $voucher = $this->expenses->create($user, [
                'date' => $o['date'] ?? Hr::day($line->expense_date), 'account' => $account, 'amount' => (float) $line->amount, 'vatPct' => (float) $line->vat_pct,
                'settlement' => $fromBank ? 'bank' : 'accrued', 'bankAccount' => $fromBank ? $o['bankAccount'] : null, 'costCenter' => ($o['costCenter'] ?? null) ?: null,
                'description' => mb_substr("{$claim->number} · {$line->description}", 0, 500), 'reference' => $claim->number,
            ]);
            if (abs((float) $voucher['total'] - (float) $line->total) > 0.005) {
                throw AppError::rule('CLAIM_TOTAL_MISMATCH', "السطر {$line->line_no}: السند يحسب الإجمالي ".number_format((float) $voucher['total'], 2)." والمطالبة تقول {$line->total} — صحِّح مبلغ السطر أو نسبة ضريبته",
                    "Line {$line->line_no}: the voucher computes ".number_format((float) $voucher['total'], 2)." while the claim says {$line->total} — correct the line's amount or VAT rate", ['lineNo' => $line->line_no]);
            }
            $line->update(['finance_expense_id' => $voucher['id'], 'finance_expense_number' => $voucher['number'], 'account_code' => $voucher['account']['code'] ?? $account]);
            $numbers[] = (string) $voucher['number'];
        }

        return $numbers;
    }

    /**
     * Takes the vouchers of a claim back: each one still posted is voided by the finance service (its journal is
     * reversed), and the lines are free to be posted again.
     *
     * @return string[] the numbers of the vouchers that were taken back
     */
    public function reverse(AuthUser $user, Claim $claim, string $reason): array
    {
        $numbers = [];
        foreach ($claim->lines()->get() as $line) {
            if (! $line->finance_expense_id) {
                continue;
            }
            if (Expense::whereKey($line->finance_expense_id)->value('status') === 'posted') { // finance may have voided it from its own screen already
                $this->expenses->void($user, $line->finance_expense_id, mb_substr("عكس ترحيل المطالبة {$claim->number}: {$reason}", 0, 480));
            }
            $numbers[] = (string) $line->finance_expense_number;
            $line->update(['finance_expense_id' => null, 'finance_expense_number' => null]);
        }

        return $numbers;
    }

    /**
     * The reimbursement of an accrued claim was paid. The finance module has no flow for it yet (PaymentsService pays
     * suppliers; nothing settles accrued expenses), so nothing is posted here: the claim records the payment and the
     * accrual stays in the books for finance to clear. The day the finance module offers that call — Dr accrued
     * expenses / Cr the bank account, by claim — it is made from this method and nothing else changes.
     *
     * @return array{posted:bool}
     */
    public function settle(AuthUser $user, Claim $claim, array $payment): array
    {
        return ['posted' => false];
    }
}
