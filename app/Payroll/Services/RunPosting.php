<?php

namespace App\Payroll\Services;

use App\Finance\Models\Account;
use App\Finance\Models\BankAccount;
use App\Finance\Models\Journal;
use App\Finance\Services\LedgerService;
use App\Payroll\Models\Component;
use App\Payroll\Models\PayAccount;
use App\Payroll\Models\PayslipLine;
use App\Payroll\Models\Run;
use App\Payroll\Support\Money;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Support\Facades\DB;

/**
 * The bridge from an approved run to the books. Two journals, both written by the finance module's ledger service and
 * by nothing else:
 *
 *   POSTING the run (the month's cost and what is owed)
 *     Dr  salaries / allowances / overtime        what was earned, less the deductions for time not worked
 *     Dr  employer's social insurance             the company's share
 *     Dr  expense reimbursements owed             the accrual the posted claims left (one line per claim)
 *         Cr  salaries payable                    the net pay of every payslip
 *         Cr  social insurance payable            both shares
 *         Cr  other payroll deductions            penalties, loan instalments …
 *   PAYING it
 *     Dr  salaries payable   /   Cr  the bank account or cash box the money left from
 *
 * Amounts are summed in halalas from the payslip lines themselves, so the journal balances by construction — the
 * ledger checks it again. Which account each kind of amount goes to is the posting map (pay_accounts), a component may
 * name an account of its own; an amount whose account is not set is a refusal that names the key.
 * No line names a person: descriptions carry the run, the period and the component.
 */
class RunPosting
{
    /** key → [side, Arabic, English] */
    public const KEYS = [
        'salaries' => ['expense', 'الرواتب الأساسية', 'Basic salaries'],
        'allowances' => ['expense', 'البدلات والمكافآت', 'Allowances and bonuses'],
        'overtime' => ['expense', 'العمل الإضافي', 'Overtime'],
        'gosi_expense' => ['expense', 'حصة المنشأة في التأمينات الاجتماعية', 'Employer\'s social insurance'],
        'payable' => ['liability', 'رواتب مستحقة الدفع', 'Salaries payable'],
        'gosi_payable' => ['liability', 'تأمينات اجتماعية مستحقة', 'Social insurance payable'],
        'deductions' => ['liability', 'استقطاعات أخرى من الرواتب', 'Other payroll deductions'],
        'reimbursements' => ['liability', 'تعويضات مصروفات مستحقة للموظفين', 'Expense reimbursements owed to employees'],
    ];

    private const DEFAULT_KEY = ['basic' => 'salaries', 'allowance' => 'allowances', 'deduction' => 'deductions', 'company' => 'gosi_expense', 'reimbursement' => 'reimbursements'];

    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * What posting the run writes: the ledger lines, or the keys that still lack an account.
     *
     * @return array{lines: array<int, array<string, mixed>>, missing: string[], debit: string, credit: string, balanced: bool}
     */
    public function preview(Run $run): array
    {
        $map = PayAccount::pluck('account_code', 'key')->all();
        $components = Component::all()->keyBy('id');
        $missing = [];
        $resolve = function (?Component $k, string $category) use ($map, &$missing): ?string {
            $key = $k?->account_key ?: (self::DEFAULT_KEY[$category] ?? 'deductions');
            $code = $k?->account_code ?: ($map[$key] ?? null);
            if (! $code) {
                $missing[$key] = true;
            }

            return $code;
        };
        $key = function (string $key) use ($map, &$missing): ?string {
            if (empty($map[$key])) {
                $missing[$key] = true;
            }

            return $map[$key] ?? null;
        };
        $net = []; // account code → halalas, a debit when positive
        $text = [];
        $bump = function (?string $code, int $halalas, string $what) use (&$net, &$text) {
            if ($code !== null && $halalas !== 0) {
                $net[$code] = ($net[$code] ?? 0) + $halalas;
                $text[$code][$what] = true;
            }
        };
        $claims = [];
        $slipIds = $run->payslips()->pluck('id')->all();
        foreach (PayslipLine::whereIn('payslip_id', $slipIds ?: ['-'])->whereIn('category', ['basic', 'allowance', 'deduction', 'company', 'reimbursement'])->orderBy('payslip_id')->orderBy('sequence')->get() as $line) {
            $k = $line->component_id ? ($components[$line->component_id] ?? null) : null;
            $total = Money::h($line->total);
            if ($line->category === 'reimbursement') {
                $claims[] = ['code' => $resolve($k, 'reimbursement'), 'amount' => $total, 'claim' => $line->claim_id, 'label' => $line->explain['claim'] ?? $line->code];

                continue;
            }
            $bump($resolve($k, $line->category), $total, $k?->name_ar ?? $line->name_ar); // a deduction is negative: it lands on the credit side of its account
            if ($line->category === 'company') {
                $bump($key('gosi_payable'), -$total, 'حصة المنشأة');
            }
        }
        $bump($key('payable'), -Money::h($run->net), 'صافي الرواتب');

        $about = "رواتب {$run->period} · {$run->number}";
        $lines = [];
        $debit = $credit = 0;
        ksort($net);
        foreach ($net as $code => $halalas) {
            if ($halalas === 0) {
                continue;
            }
            $lines[] = ['account' => (string) $code, $halalas > 0 ? 'debit' : 'credit' => Money::s(abs($halalas)), 'description' => mb_substr($about.' — '.implode('، ', array_keys($text[$code])), 0, 480),
                'referenceType' => 'PayrollRun', 'referenceId' => $run->id];
            $halalas > 0 ? $debit += $halalas : $credit += -$halalas;
        }
        foreach ($claims as $c) {
            if ($c['code'] !== null && $c['amount'] > 0) {
                $lines[] = ['account' => (string) $c['code'], 'debit' => Money::s($c['amount']), 'description' => "{$about} — تعويض المطالبة {$c['label']}", 'referenceType' => 'ExpenseClaim', 'referenceId' => $c['claim']];
                $debit += $c['amount'];
            }
        }

        return ['lines' => $lines, 'missing' => array_keys($missing), 'debit' => Money::s($debit), 'credit' => Money::s($credit), 'balanced' => $debit === $credit];
    }

    /** The preview with the names of the accounts, for a person to read before posting. */
    public function shown(Run $run): array
    {
        $p = $this->preview($run);
        $accounts = Account::whereIn('code', array_column($p['lines'], 'account') ?: ['-'])->get(['code', 'name_ar', 'name_en', 'type', 'is_postable', 'active'])->keyBy('code');

        return ['run' => $run->number, 'period' => $run->period, 'debit' => $p['debit'], 'credit' => $p['credit'], 'balanced' => $p['balanced'],
            'missing' => array_map(fn (string $k) => ['key' => $k, 'nameAr' => self::KEYS[$k][1] ?? $k, 'nameEn' => self::KEYS[$k][2] ?? $k], $p['missing']),
            'lines' => array_map(function (array $l) use ($accounts) {
                $a = $accounts[$l['account']] ?? null;

                return ['account' => ['code' => $l['account'], 'nameAr' => $a?->name_ar, 'nameEn' => $a?->name_en, 'usable' => (bool) ($a && $a->is_postable && $a->active)],
                    'debit' => $l['debit'] ?? '0.00', 'credit' => $l['credit'] ?? '0.00', 'description' => $l['description']];
            }, $p['lines'])];
    }

    /** Posts the run's journal (once: the event key is the run). Inside the caller's transaction. */
    public function post(AuthUser $user, Run $run, string $date): Journal
    {
        $p = $this->preview($run);
        if ($p['missing']) {
            $names = implode('، ', array_map(fn (string $k) => self::KEYS[$k][1] ?? $k, $p['missing']));
            throw AppError::rule('PAYROLL_ACCOUNT_UNMAPPED', "لا حساب في دليل الحسابات مربوط بـ: {$names} — اربطه في إعدادات الرواتب ◂ التوجيه المحاسبي قبل الترحيل",
                'No account of the chart is mapped to: '.implode(', ', $p['missing']).' — map it in the payroll settings before posting', ['keys' => $p['missing']]);
        }
        if (! $p['lines'] || ! $p['balanced']) {
            throw AppError::rule('PAYROLL_JOURNAL_UNBALANCED', "قيد المسير {$run->number} لا يتوازن ({$p['debit']} ≠ {$p['credit']}) — أعِد حساب المسير", "The journal of run {$run->number} does not balance ({$p['debit']} ≠ {$p['credit']})");
        }

        return $this->ledger->post($user, ['eventKey' => "payrun:{$run->id}", 'date' => $date, 'source' => ['type' => 'PayrollRun', 'id' => $run->id, 'number' => $run->number],
            'memo' => "مسير رواتب {$run->period} — {$run->number}", 'lines' => $p['lines']]);
    }

    /** The payment of the run: the net pay leaves the bank account or cash box. Inside the caller's transaction. */
    public function pay(AuthUser $user, Run $run, BankAccount $bank, string $date, string $reference): Journal
    {
        $payable = PayAccount::where('key', 'payable')->value('account_code') ?? throw AppError::rule('PAYROLL_ACCOUNT_UNMAPPED', 'حساب «رواتب مستحقة الدفع» غير مربوط في إعدادات الرواتب', 'The salaries-payable account is not mapped', ['keys' => ['payable']]);
        $amount = Money::s(Money::h($run->net));
        $refs = ['referenceType' => 'PayrollRun', 'referenceId' => $run->id];

        return $this->ledger->post($user, ['date' => $date, 'source' => ['type' => 'PayrollRun', 'id' => $run->id, 'number' => $run->number],
            'memo' => mb_substr("صرف رواتب {$run->period} — {$run->number} — مرجع {$reference}", 0, 480),
            'lines' => [
                ['account' => $payable, 'debit' => $amount, 'description' => "صرف {$run->number}"] + $refs,
                ['account' => $bank->account_id, 'credit' => $amount, 'description' => "صرف {$run->number} · {$bank->name_ar}"] + $refs,
            ]]);
    }

    /** The journals the books hold for a run, oldest first. */
    public static function journals(Run $run): array
    {
        return DB::table('fin_journals')->where('source_type', 'PayrollRun')->where('source_id', $run->id)->orderBy('created_at')->orderBy('id')
            ->get(['id', 'number', 'journal_date', 'status', 'kind', 'memo', 'total_debit'])->map(fn ($j) => ['id' => $j->id, 'number' => $j->number, 'date' => substr((string) $j->journal_date, 0, 10),
                'status' => $j->status, 'kind' => $j->kind, 'memo' => $j->memo, 'amount' => number_format((float) $j->total_debit, 2, '.', '')])->all();
    }
}
