<?php

namespace App\Finance\Services;

use App\Finance\Models\Account;
use App\Finance\Models\Budget;
use App\Finance\Models\CostCenter;
use App\Finance\Models\CustomerInvoice;
use App\Finance\Models\CustomerInvoiceLine;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Models\Warehouse;
use App\Support\AppError;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Financial statements and analyses, all computed from posted journal lines (never from cached totals) so every
 * figure drills down to the journals and the source documents that produced it.
 */
class FinanceReportsService
{
    public function __construct(private readonly CostingService $costing, private readonly LedgerService $ledger) {}

    // ───────────── general ledger ─────────────

    /** Posted journal lines joined with their journal (status posted/reversed only — never drafts). */
    private function lines(): Builder
    {
        return DB::table('fin_journal_lines as l')->join('fin_journals as j', 'j.id', '=', 'l.journal_id')->where('j.status', '!=', 'draft');
    }

    /** Trial balance for a date range: opening (before `from`), period movement, closing per account. */
    public function trialBalance(?string $from, ?string $to): array
    {
        [$from, $to] = $this->range($from, $to);
        $accounts = Account::where('is_postable', true)->orderBy('code')->get();
        $opening = $this->lines()->where('j.journal_date', '<', $from->toDateString())->selectRaw('l.account_id, coalesce(sum(l.debit), 0) as d, coalesce(sum(l.credit), 0) as c')->groupBy('l.account_id')->get()->keyBy('account_id');
        $period = $this->lines()->whereBetween('j.journal_date', [$from->toDateString(), $to->toDateString()])->selectRaw('l.account_id, coalesce(sum(l.debit), 0) as d, coalesce(sum(l.credit), 0) as c')->groupBy('l.account_id')->get()->keyBy('account_id');
        $rows = [];
        $tot = ['openingDebit' => 0, 'openingCredit' => 0, 'debit' => 0, 'credit' => 0, 'closingDebit' => 0, 'closingCredit' => 0];
        foreach ($accounts as $a) {
            $od = (float) ($opening[$a->id]->d ?? 0);
            $oc = (float) ($opening[$a->id]->c ?? 0);
            $pd = (float) ($period[$a->id]->d ?? 0);
            $pc = (float) ($period[$a->id]->c ?? 0);
            if ($od == 0 && $oc == 0 && $pd == 0 && $pc == 0) {
                continue;
            }
            $open = round($od - $oc, 2);
            $close = round($open + $pd - $pc, 2);
            $row = ['code' => $a->code, 'nameAr' => $a->name_ar, 'nameEn' => $a->name_en, 'type' => $a->type,
                'openingDebit' => $open > 0 ? $open : 0, 'openingCredit' => $open < 0 ? -$open : 0, 'debit' => round($pd, 2), 'credit' => round($pc, 2),
                'closingDebit' => $close > 0 ? $close : 0, 'closingCredit' => $close < 0 ? -$close : 0];
            foreach ($tot as $k => $v) {
                $tot[$k] = round($v + $row[$k], 2);
            }
            $rows[] = $row;
        }

        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'rows' => $rows, 'totals' => $tot, 'balanced' => abs($tot['closingDebit'] - $tot['closingCredit']) < 0.01 && abs($tot['debit'] - $tot['credit']) < 0.01];
    }

    /** Account ledger: opening balance then every posted line in the range with running balance. */
    public function accountLedger(string $code, ?string $from, ?string $to, ?string $costCenter = null): array
    {
        $a = Account::where('code', $code)->orWhere('id', $code)->first() ?? throw AppError::notFound('ACCOUNT_NOT_FOUND', "الحساب {$code} غير موجود", 'Account not found');
        [$from, $to] = $this->range($from, $to);
        $cc = $costCenter ? CostCenter::where('code', $costCenter)->first() : null;
        $base = fn () => tap($this->lines()->where('l.account_id', $a->id), fn ($q) => $cc ? $q->where('l.cost_center_id', $cc->id) : null);
        $o = $base()->where('j.journal_date', '<', $from->toDateString())->selectRaw('coalesce(sum(l.debit), 0) as d, coalesce(sum(l.credit), 0) as c')->first();
        $running = LedgerService::natural($a, (float) $o->d, (float) $o->c);
        $opening = $running;
        $rows = $base()->whereBetween('j.journal_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('j.journal_date')->orderBy('j.number')->orderBy('l.line_no')
            ->get(['l.id', 'l.debit', 'l.credit', 'l.description', 'l.partner_type', 'l.partner_id', 'l.cost_center_id', 'l.reference_type', 'l.reference_id', 'j.number as journal', 'j.journal_date as date', 'j.memo', 'j.source_type', 'j.source_number', 'j.status'])
            ->map(function ($r) use (&$running, $a) {
                $running = round($running + LedgerService::natural($a, (float) $r->debit, (float) $r->credit), 2);

                return ['journal' => $r->journal, 'date' => $r->date, 'memo' => $r->memo, 'description' => $r->description, 'debit' => (float) $r->debit, 'credit' => (float) $r->credit, 'balance' => $running,
                    'sourceType' => $r->source_type, 'sourceNumber' => $r->source_number, 'partnerType' => $r->partner_type, 'partnerId' => $r->partner_id, 'status' => $r->status];
            })->all();
        $rows = $this->ledger->withPartnerNames($rows);

        return ['account' => ['code' => $a->code, 'nameAr' => $a->name_ar, 'nameEn' => $a->name_en, 'type' => $a->type], 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'opening' => $opening, 'closing' => $running, 'rows' => $rows];
    }

    // ───────────── statements ─────────────

    /** Income statement for a range, grouped revenue / cost of sales / operating expenses. */
    public function incomeStatement(?string $from, ?string $to, ?string $costCenter = null): array
    {
        [$from, $to] = $this->range($from, $to);
        $cc = $costCenter ? CostCenter::where('code', $costCenter)->first() : null;
        $q = $this->lines()->whereBetween('j.journal_date', [$from->toDateString(), $to->toDateString()])->join('fin_accounts as a', 'a.id', '=', 'l.account_id')->whereIn('a.type', ['revenue', 'expense']);
        if ($cc) {
            $q->where('l.cost_center_id', $cc->id);
        }
        $sums = $q->selectRaw('a.id, a.code, a.name_ar, a.name_en, a.type, a.subtype, a.parent_id, coalesce(sum(l.debit), 0) as d, coalesce(sum(l.credit), 0) as c')->groupBy('a.id', 'a.code', 'a.name_ar', 'a.name_en', 'a.type', 'a.subtype', 'a.parent_id')->orderBy('a.code')->get();
        $section = fn ($r) => $r->type === 'revenue' ? 'revenue' : (in_array($r->subtype, ['cogs'], true) ? 'cogs' : 'opex');
        $out = ['revenue' => [], 'cogs' => [], 'opex' => []];
        $tot = ['revenue' => 0.0, 'cogs' => 0.0, 'opex' => 0.0];
        foreach ($sums as $r) {
            $amount = $r->type === 'revenue' ? round((float) $r->c - (float) $r->d, 2) : round((float) $r->d - (float) $r->c, 2);
            $sec = $section($r);
            $out[$sec][] = ['code' => $r->code, 'nameAr' => $r->name_ar, 'nameEn' => $r->name_en, 'amount' => $amount];
            $tot[$sec] = round($tot[$sec] + $amount, 2);
        }
        $gross = round($tot['revenue'] - $tot['cogs'], 2);
        $net = round($gross - $tot['opex'], 2);

        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'costCenter' => $cc?->code, 'sections' => $out, 'totals' => $tot + ['grossProfit' => $gross, 'netIncome' => $net, 'grossMarginPct' => $tot['revenue'] > 0 ? round($gross / $tot['revenue'] * 100, 1) : null]];
    }

    /** Balance sheet as of a date (retained earnings = net income to date of every period, current and prior). */
    public function balanceSheet(?string $asOf): array
    {
        $asOf = $asOf ? Carbon::parse($asOf)->endOfDay() : now()->endOfDay();
        $sums = $this->lines()->where('j.journal_date', '<=', $asOf->toDateString())->join('fin_accounts as a', 'a.id', '=', 'l.account_id')
            ->selectRaw('a.id, a.code, a.name_ar, a.name_en, a.type, a.subtype, coalesce(sum(l.debit), 0) as d, coalesce(sum(l.credit), 0) as c')->groupBy('a.id', 'a.code', 'a.name_ar', 'a.name_en', 'a.type', 'a.subtype')->orderBy('a.code')->get();
        $out = ['assets' => [], 'liabilities' => [], 'equity' => []];
        $tot = ['assets' => 0.0, 'liabilities' => 0.0, 'equity' => 0.0];
        $income = 0.0;
        foreach ($sums as $r) {
            $bal = LedgerService::natural(['type' => $r->type], (float) $r->d, (float) $r->c);
            if ($r->type === 'revenue' || $r->type === 'expense') {
                $income = round($income + ($r->type === 'revenue' ? $bal : -$bal), 2);

                continue;
            }
            $sec = $r->type === 'asset' ? 'assets' : ($r->type === 'liability' ? 'liabilities' : 'equity');
            if (abs($bal) < 0.005) {
                continue;
            }
            $out[$sec][] = ['code' => $r->code, 'nameAr' => $r->name_ar, 'nameEn' => $r->name_en, 'amount' => $bal];
            $tot[$sec] = round($tot[$sec] + $bal, 2);
        }
        $out['equity'][] = ['code' => '—', 'nameAr' => 'صافي الدخل (غير مرحَّل للأرباح المبقاة)', 'nameEn' => 'Net income (current)', 'amount' => $income];
        $tot['equity'] = round($tot['equity'] + $income, 2);

        return ['asOf' => $asOf->toDateString(), 'sections' => $out, 'totals' => $tot + ['liabilitiesAndEquity' => round($tot['liabilities'] + $tot['equity'], 2)], 'balanced' => abs($tot['assets'] - $tot['liabilities'] - $tot['equity']) < 0.01];
    }

    /** Cash flow (direct method, from bank/cash account lines) for a range. */
    public function cashFlow(?string $from, ?string $to): array
    {
        [$from, $to] = $this->range($from, $to);
        $cashAccounts = Account::whereIn('subtype', ['bank', 'cash'])->pluck('id');
        $opening = $this->lines()->whereIn('l.account_id', $cashAccounts)->where('j.journal_date', '<', $from->toDateString())->selectRaw('coalesce(sum(l.debit), 0) - coalesce(sum(l.credit), 0) as b')->value('b');
        $moves = $this->lines()->whereIn('l.account_id', $cashAccounts)->whereBetween('j.journal_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('j.source_type, coalesce(sum(l.debit), 0) as inflow, coalesce(sum(l.credit), 0) as outflow')->groupBy('j.source_type')->get();
        $label = ['Payment' => ['ar' => 'تحصيلات العملاء ومدفوعات الموردين', 'en' => 'Customer receipts and supplier payments'], 'Expense' => ['ar' => 'مصروفات مدفوعة', 'en' => 'Expenses paid'], 'FixedAsset' => ['ar' => 'أصول ثابتة', 'en' => 'Fixed assets']];
        $rows = $moves->map(fn ($m) => ['source' => $m->source_type, 'label' => $label[$m->source_type] ?? ['ar' => $m->source_type ?: 'قيود يدوية', 'en' => $m->source_type ?: 'Manual journals'], 'inflow' => round((float) $m->inflow, 2), 'outflow' => round((float) $m->outflow, 2), 'net' => round((float) $m->inflow - (float) $m->outflow, 2)])->all();
        $net = round(array_sum(array_column($rows, 'net')), 2);

        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'opening' => round((float) $opening, 2), 'rows' => $rows, 'net' => $net, 'closing' => round((float) $opening + $net, 2)];
    }

    // ───────────── sub-ledgers ─────────────

    /** Customer / supplier statement from the control account lines (opening, movements, running balance). */
    public function partnerStatement(string $type, string $code, ?string $from, ?string $to): array
    {
        $partner = $type === 'customer' ? Customer::where('code', $code)->orWhere('id', $code)->first() : Supplier::where('code', $code)->orWhere('id', $code)->first();
        if (! $partner) {
            throw AppError::notFound('PARTNER_NOT_FOUND', 'الطرف غير موجود', 'Partner not found');
        }
        [$from, $to] = $this->range($from, $to);
        $role = $type === 'customer' ? 'ar' : 'ap';
        $sign = $type === 'customer' ? 1 : -1; // customer owes us (debit), we owe the supplier (credit)
        $base = fn () => $this->lines()->where('l.partner_type', $type)->where('l.partner_id', $partner->id);
        $o = $base()->where('j.journal_date', '<', $from->toDateString())->selectRaw('coalesce(sum(l.debit), 0) as d, coalesce(sum(l.credit), 0) as c')->first();
        $running = round($sign * ((float) $o->d - (float) $o->c), 2);
        $opening = $running;
        $rows = $base()->whereBetween('j.journal_date', [$from->toDateString(), $to->toDateString()])->orderBy('j.journal_date')->orderBy('j.number')->orderBy('l.line_no')
            ->get(['l.debit', 'l.credit', 'l.description', 'j.number as journal', 'j.journal_date as date', 'j.memo', 'j.source_type', 'j.source_number', 'j.status'])
            ->map(function ($r) use (&$running, $sign) {
                $running = round($running + $sign * ((float) $r->debit - (float) $r->credit), 2);

                return ['date' => $r->date, 'journal' => $r->journal, 'sourceType' => $r->source_type, 'sourceNumber' => $r->source_number, 'description' => $r->description ?: $r->memo, 'debit' => (float) $r->debit, 'credit' => (float) $r->credit, 'balance' => $running, 'status' => $r->status];
            })->all();

        return ['partner' => ['type' => $type, 'code' => $partner->code, 'nameAr' => $partner->name_ar, 'nameEn' => $partner->name_en], 'role' => $role, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'opening' => $opening, 'closing' => $running, 'rows' => $rows];
    }

    /** Aging of open customer invoices (AR) or approved supplier invoices (AP) as of today, by partner and bucket. */
    public function aging(string $type): array
    {
        $today = now()->startOfDay();
        $bucket = fn (?Carbon $due) => ! $due || $due->gte($today) ? 'current' : ($due->diffInDays($today) <= 30 ? 'd30' : ($due->diffInDays($today) <= 60 ? 'd60' : ($due->diffInDays($today) <= 90 ? 'd90' : 'd90p')));
        $rows = [];
        if ($type === 'customer') {
            foreach (CustomerInvoice::with('customer:id,code,name_ar,name_en')->where('kind', 'invoice')->whereIn('status', CustomerInvoice::OPEN)->get() as $i) {
                $k = $i->customer_id;
                $rows[$k] ??= ['code' => $i->customer?->code, 'nameAr' => $i->customer?->name_ar, 'nameEn' => $i->customer?->name_en, 'current' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'd90p' => 0, 'total' => 0, 'invoices' => 0];
                $bal = round((float) $i->total - (float) $i->paid_amount, 2);
                $rows[$k][$bucket($i->due_date ? Carbon::parse($i->due_date) : null)] += $bal;
                $rows[$k]['total'] = round($rows[$k]['total'] + $bal, 2);
                $rows[$k]['invoices']++;
            }
        } else {
            foreach (SupplierInvoice::with('supplier:id,code,name_ar,name_en')->where('status', 'approved')->get() as $i) {
                $k = $i->supplier_id;
                $rows[$k] ??= ['code' => $i->supplier?->code, 'nameAr' => $i->supplier?->name_ar, 'nameEn' => $i->supplier?->name_en, 'current' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'd90p' => 0, 'total' => 0, 'invoices' => 0];
                $bal = round((float) $i->total - (float) $i->paid_amount, 2);
                $rows[$k][$bucket($i->due_date ? Carbon::parse($i->due_date) : null)] += $bal;
                $rows[$k]['total'] = round($rows[$k]['total'] + $bal, 2);
                $rows[$k]['invoices']++;
            }
        }
        $rows = array_values($rows);
        usort($rows, fn ($a, $b) => $b['total'] <=> $a['total']);
        $totals = ['current' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'd90p' => 0, 'total' => 0];
        foreach ($rows as $r) {
            foreach ($totals as $k => $v) {
                $totals[$k] = round($v + $r[$k], 2);
            }
        }

        return ['type' => $type, 'asOf' => $today->toDateString(), 'rows' => $rows, 'totals' => $totals];
    }

    /** VAT return figures for a range: output (sales) vs input (purchases / expenses) from the tax accounts. */
    public function vatReturn(?string $from, ?string $to): array
    {
        [$from, $to] = $this->range($from, $to);
        $acct = fn (string $role) => Account::where('role', $role)->value('id');
        $sum = fn ($id) => $this->lines()->where('l.account_id', $id)->whereBetween('j.journal_date', [$from->toDateString(), $to->toDateString()])->selectRaw('coalesce(sum(l.debit), 0) as d, coalesce(sum(l.credit), 0) as c')->first();
        $out = $sum($acct('vat_output'));
        $in = $sum($acct('vat_input'));
        $output = round((float) $out->c - (float) $out->d, 2);
        $input = round((float) $in->d - (float) $in->c, 2);

        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'outputVat' => $output, 'inputVat' => $input, 'netPayable' => round($output - $input, 2),
            'note' => 'أرقام من حسابات الضريبة في الأستاذ؛ الإقرار الرسمي والفوترة الإلكترونية (ZATCA) غير مربوطين — Integration Pending'];
    }

    // ───────────── analyses ─────────────

    /** Inventory sub-ledger (qty × average cost per warehouse) against the GL inventory account. */
    public function inventoryValuation(): array
    {
        $byWh = collect($this->costing->valuation());
        $names = Warehouse::whereIn('id', $byWh->pluck('warehouseId'))->get(['id', 'code', 'name_ar', 'name_en'])->keyBy('id');
        $rows = $byWh->map(fn ($r) => $r + ['code' => $names[$r['warehouseId']]->code ?? '?', 'nameAr' => $names[$r['warehouseId']]->name_ar ?? '?', 'nameEn' => $names[$r['warehouseId']]->name_en ?? '?'])->values()->all();
        $sub = round(array_sum(array_column($rows, 'value')), 2);
        $gl = Account::where('role', 'inventory')->first();
        $g = $gl ? $this->lines()->where('l.account_id', $gl->id)->selectRaw('coalesce(sum(l.debit), 0) as d, coalesce(sum(l.credit), 0) as c')->first() : null;
        $glBalance = $g ? round((float) $g->d - (float) $g->c, 2) : 0.0;
        $unvalued = Product::whereNull('avg_cost')->whereHas('balances', fn ($q) => $q->where('on_hand', '>', 0))->count();

        return ['rows' => $rows, 'subLedger' => $sub, 'glBalance' => $glBalance, 'difference' => round($glBalance - $sub, 2), 'unvaluedProducts' => $unvalued,
            'note' => $unvalued ? "{$unvalued} صنف برصيد ولم يُقيَّم بعد (لا رصيد افتتاحي) — شغّل scm:finance-open-inventory بعد اعتماد المسؤول المالي" : null];
    }

    /** Gross profit by product or by customer from invoice lines in a range. */
    public function profitability(string $by, ?string $from, ?string $to): array
    {
        [$from, $to] = $this->range($from, $to);
        $q = CustomerInvoiceLine::query()->join('fin_customer_invoices as i', 'i.id', '=', 'fin_customer_invoice_lines.invoice_id')->where('i.status', '!=', 'cancelled')->whereBetween('i.invoice_date', [$from->toDateString(), $to->toDateString()]);
        $sign = "case when i.kind = 'credit_note' then -1 else 1 end";
        if ($by === 'customer') {
            $rows = $q->selectRaw("i.customer_id as k, coalesce(sum({$sign} * fin_customer_invoice_lines.qty), 0) as qty, coalesce(sum({$sign} * fin_customer_invoice_lines.line_total), 0) as revenue, coalesce(sum({$sign} * fin_customer_invoice_lines.cogs), 0) as cogs")->groupBy('i.customer_id')->get();
            $names = Customer::whereIn('id', $rows->pluck('k'))->get(['id', 'code', 'name_ar', 'name_en'])->keyBy('id');
        } else {
            $rows = $q->selectRaw("fin_customer_invoice_lines.product_id as k, coalesce(sum({$sign} * fin_customer_invoice_lines.qty), 0) as qty, coalesce(sum({$sign} * fin_customer_invoice_lines.line_total), 0) as revenue, coalesce(sum({$sign} * fin_customer_invoice_lines.cogs), 0) as cogs")->groupBy('fin_customer_invoice_lines.product_id')->get();
            $names = Product::whereIn('id', $rows->pluck('k'))->get(['id', 'sku as code', 'name_ar', 'name_en'])->keyBy('id');
        }
        $out = $rows->map(function ($r) use ($names) {
            $rev = round((float) $r->revenue, 2);
            $cogs = round((float) $r->cogs, 2);
            $n = $names[$r->k] ?? null;

            return ['code' => $n?->code, 'nameAr' => $n?->name_ar, 'nameEn' => $n?->name_en, 'qty' => (int) $r->qty, 'revenue' => $rev, 'cogs' => $cogs, 'grossProfit' => round($rev - $cogs, 2), 'marginPct' => $rev > 0 ? round(($rev - $cogs) / $rev * 100, 1) : null];
        })->sortByDesc('grossProfit')->values()->all();

        return ['by' => $by, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'rows' => $out, 'totals' => ['revenue' => round(array_sum(array_column($out, 'revenue')), 2), 'cogs' => round(array_sum(array_column($out, 'cogs')), 2), 'grossProfit' => round(array_sum(array_column($out, 'grossProfit')), 2)]];
    }

    /** Cost per vehicle (fuel, maintenance, other expenses, depreciation) and per trip from journal lines in a range. */
    public function fleetCosts(?string $from, ?string $to): array
    {
        [$from, $to] = $this->range($from, $to);
        $rows = $this->lines()->join('fin_accounts as a', 'a.id', '=', 'l.account_id')->whereNotNull('l.vehicle_id')->where('a.type', 'expense')->whereBetween('j.journal_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('l.vehicle_id, a.role, coalesce(sum(l.debit), 0) - coalesce(sum(l.credit), 0) as amount')->groupBy('l.vehicle_id', 'a.role')->get();
        $vehicles = Vehicle::whereIn('id', $rows->pluck('vehicle_id')->unique())->get(['id', 'code', 'plate_ar', 'kind'])->keyBy('id');
        $out = [];
        foreach ($rows as $r) {
            $v = $vehicles[$r->vehicle_id] ?? null;
            $out[$r->vehicle_id] ??= ['code' => $v?->code, 'plateAr' => $v?->plate_ar, 'fuel' => 0, 'maintenance' => 0, 'depreciation' => 0, 'other' => 0, 'total' => 0];
            $k = in_array($r->role, ['fuel', 'maintenance', 'depreciation'], true) ? $r->role : 'other';
            $out[$r->vehicle_id][$k] = round($out[$r->vehicle_id][$k] + (float) $r->amount, 2);
            $out[$r->vehicle_id]['total'] = round($out[$r->vehicle_id]['total'] + (float) $r->amount, 2);
        }
        $trips = $this->lines()->join('fin_accounts as a', 'a.id', '=', 'l.account_id')->whereNotNull('l.trip_id')->where('a.type', 'expense')->whereBetween('j.journal_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('l.trip_id, coalesce(sum(l.debit), 0) - coalesce(sum(l.credit), 0) as amount')->groupBy('l.trip_id')->get();
        $tripNames = Trip::whereIn('id', $trips->pluck('trip_id'))->get(['id', 'number', 'km'])->keyBy('id');
        $tripRows = $trips->map(fn ($t) => ['trip' => $tripNames[$t->trip_id]->number ?? '?', 'km' => $tripNames[$t->trip_id]->km ?? null, 'cost' => round((float) $t->amount, 2), 'costPerKm' => ($tripNames[$t->trip_id]->km ?? 0) > 0 ? round((float) $t->amount / $tripNames[$t->trip_id]->km, 2) : null])->values()->all();

        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'vehicles' => array_values($out), 'trips' => $tripRows, 'total' => round(array_sum(array_column($out, 'total')), 2)];
    }

    /** Budget vs actual per account for a period code (YYYY-MM) or a year. */
    public function budgetVsActual(string $period): array
    {
        $isYear = strlen($period) === 4;
        $from = $isYear ? Carbon::createFromDate((int) $period, 1, 1)->startOfDay() : Carbon::createFromFormat('Y-m', $period)->startOfMonth();
        $to = $isYear ? $from->copy()->endOfYear() : $from->copy()->endOfMonth();
        $budgets = Budget::query()->when($isYear, fn ($q) => $q->where('period_code', 'like', "{$period}-%"), fn ($q) => $q->where('period_code', $period))->selectRaw('account_id, coalesce(sum(amount), 0) as amount')->groupBy('account_id')->get()->keyBy('account_id');
        $actual = $this->lines()->join('fin_accounts as a', 'a.id', '=', 'l.account_id')->whereIn('a.type', ['revenue', 'expense'])->whereBetween('j.journal_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('a.id, coalesce(sum(l.debit), 0) as d, coalesce(sum(l.credit), 0) as c')->groupBy('a.id')->get()->keyBy('id');
        $accounts = Account::whereIn('type', ['revenue', 'expense'])->where('is_postable', true)->orderBy('code')->get();
        $rows = [];
        foreach ($accounts as $a) {
            $b = (float) ($budgets[$a->id]->amount ?? 0);
            $act = isset($actual[$a->id]) ? LedgerService::natural($a, (float) $actual[$a->id]->d, (float) $actual[$a->id]->c) : 0.0;
            if ($b == 0 && $act == 0) {
                continue;
            }
            $rows[] = ['code' => $a->code, 'nameAr' => $a->name_ar, 'nameEn' => $a->name_en, 'type' => $a->type, 'budget' => round($b, 2), 'actual' => round($act, 2), 'variance' => round($act - $b, 2), 'usedPct' => $b > 0 ? round($act / $b * 100, 1) : null];
        }

        return ['period' => $period, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'rows' => $rows];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function range(?string $from, ?string $to): array
    {
        $t = $to ? Carbon::parse($to)->endOfDay() : now()->endOfDay();
        $f = $from ? Carbon::parse($from)->startOfDay() : $t->copy()->startOfMonth();
        if ($f->gt($t)) {
            throw AppError::validation('RANGE', 'بداية الفترة بعد نهايتها', 'From is after to');
        }

        return [$f, $t];
    }
}
