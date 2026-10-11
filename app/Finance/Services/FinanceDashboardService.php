<?php

namespace App\Finance\Services;

use App\Finance\Models\Account;
use App\Finance\Models\CustomerInvoice;
use App\Finance\Models\Journal;
use App\Finance\Models\Period;
use App\Models\SupplierInvoice;
use App\Services\Procurement\SupplierInvoiceService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** The CFO board: cash, receivables, payables, month-to-date P&L, VAT position, period state, controls needing attention. */
class FinanceDashboardService
{
    public function __construct(
        private readonly FinanceReportsService $reports,
        private readonly CustomerInvoiceService $customerInvoices,
        private readonly SupplierInvoiceService $supplierInvoices,
        private readonly CostingService $costing,
    ) {}

    public function board(): array
    {
        $today = now();
        $monthStart = $today->copy()->startOfMonth();
        $balanceOf = function (array $roles): float {
            $ids = Account::whereIn('role', $roles)->pluck('id');
            $r = DB::table('fin_journal_lines as l')->join('fin_journals as j', 'j.id', '=', 'l.journal_id')->where('j.status', '!=', 'draft')->whereIn('l.account_id', $ids)
                ->selectRaw('coalesce(sum(l.debit), 0) as d, coalesce(sum(l.credit), 0) as c')->first();

            return round((float) $r->d - (float) $r->c, 2);
        };
        $cashIds = Account::whereIn('subtype', ['bank', 'cash'])->pluck('id');
        $cashRow = DB::table('fin_journal_lines as l')->join('fin_journals as j', 'j.id', '=', 'l.journal_id')->where('j.status', '!=', 'draft')->whereIn('l.account_id', $cashIds)->selectRaw('coalesce(sum(l.debit), 0) as d, coalesce(sum(l.credit), 0) as c')->first();
        $pl = $this->reports->incomeStatement($monthStart->toDateString(), $today->toDateString());
        $ar = $this->customerInvoices->summary();
        $ap = $this->supplierInvoices->summary();
        $inventory = $this->reports->inventoryValuation();
        $vat = $this->reports->vatReturn($monthStart->toDateString(), $today->toDateString());
        $period = Period::where('code', $today->format('Y-m'))->first();
        $openPeriods = Period::where('status', 'open')->where('code', '<', $today->format('Y-m'))->orderBy('code')->pluck('code')->all();
        $drafts = Journal::where('status', 'draft')->count();

        return [
            'cash' => round((float) $cashRow->d - (float) $cashRow->c, 2),
            'receivables' => ['gl' => $balanceOf(['ar']), 'open' => $ar['openTotal'], 'overdue' => $ar['overdue'], 'count' => $ar['openCount']],
            'payables' => ['gl' => -$balanceOf(['ap']), 'open' => $ap['openTotal'], 'overdue' => $ap['overdue'], 'awaitingApproval' => $ap['awaitingApproval'], 'disputed' => $ap['disputed'], 'grni' => -$balanceOf(['grni']), 'accrued' => -$balanceOf(['accrued_expenses'])],
            'monthToDate' => ['from' => $monthStart->toDateString(), 'revenue' => $pl['totals']['revenue'], 'cogs' => $pl['totals']['cogs'], 'grossProfit' => $pl['totals']['grossProfit'], 'grossMarginPct' => $pl['totals']['grossMarginPct'], 'opex' => $pl['totals']['opex'], 'netIncome' => $pl['totals']['netIncome']],
            'inventory' => ['subLedger' => $inventory['subLedger'], 'glBalance' => $inventory['glBalance'], 'difference' => $inventory['difference'], 'unvaluedProducts' => $inventory['unvaluedProducts'], 'goodsInTransit' => $balanceOf(['goods_in_transit']), 'inTransit' => $balanceOf(['inventory_in_transit'])],
            'vat' => ['outputVat' => $vat['outputVat'], 'inputVat' => $vat['inputVat'], 'netPayable' => $vat['netPayable']],
            'period' => ['current' => $period?->code ?? $today->format('Y-m'), 'status' => $period?->status ?? 'open', 'earlierOpen' => $openPeriods, 'draftJournals' => $drafts],
            'journalsToday' => Journal::whereDate('journal_date', $today->toDateString())->where('status', '!=', 'draft')->count(),
            'generatedAt' => Carbon::now()->toJSON(),
        ];
    }

    /** Sub-ledger ↔ general-ledger reconciliation (the control the auditor asks for first). */
    public function reconciliation(): array
    {
        $glOf = function (string $role): float {
            $id = Account::where('role', $role)->value('id');
            $r = DB::table('fin_journal_lines as l')->join('fin_journals as j', 'j.id', '=', 'l.journal_id')->where('j.status', '!=', 'draft')->where('l.account_id', $id)->selectRaw('coalesce(sum(l.debit), 0) as d, coalesce(sum(l.credit), 0) as c')->first();

            return round((float) $r->d - (float) $r->c, 2);
        };
        $arSub = round((float) CustomerInvoice::where('kind', 'invoice')->where('status', '!=', 'cancelled')->selectRaw('coalesce(sum(total - paid_amount), 0) as b')->value('b'), 2);
        // receipts not yet allocated reduce the sub-ledger balance (they sit on the customer's account)
        $unallocIn = round((float) DB::table('fin_payments')->where('direction', 'in')->where('status', 'posted')->selectRaw('coalesce(sum(amount - allocated_amount), 0) as b')->value('b'), 2);
        $apSub = round((float) SupplierInvoice::whereIn('status', ['approved', 'paid'])->selectRaw('coalesce(sum(total - paid_amount), 0) as b')->value('b'), 2);
        $unallocOut = round((float) DB::table('fin_payments')->where('direction', 'out')->where('status', 'posted')->selectRaw('coalesce(sum(amount - allocated_amount), 0) as b')->value('b'), 2);
        $inv = $this->reports->inventoryValuation();
        $checks = [
            ['key' => 'ar', 'labelAr' => 'ذمم العملاء', 'labelEn' => 'Accounts receivable', 'subLedger' => round($arSub - $unallocIn, 2), 'gl' => $glOf('ar')],
            ['key' => 'ap', 'labelAr' => 'ذمم الموردين', 'labelEn' => 'Accounts payable', 'subLedger' => round($apSub - $unallocOut, 2), 'gl' => -$glOf('ap')],
            ['key' => 'inventory', 'labelAr' => 'المخزون', 'labelEn' => 'Inventory', 'subLedger' => $inv['subLedger'], 'gl' => $inv['glBalance']],
        ];
        foreach ($checks as &$c) {
            $c['difference'] = round($c['gl'] - $c['subLedger'], 2);
            $c['ok'] = abs($c['difference']) < 0.01;
        }
        $tb = $this->reports->trialBalance('2000-01-01', now()->toDateString());

        return ['checks' => $checks, 'trialBalanceBalanced' => $tb['balanced'], 'unvaluedProducts' => $inv['unvaluedProducts'], 'generatedAt' => now()->toJSON()];
    }
}
