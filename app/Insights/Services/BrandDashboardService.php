<?php

namespace App\Insights\Services;

use App\Finance\Models\CustomerInvoice;
use App\Insights\Models\BrandAlert;
use App\Insights\Models\BrandOwner;
use App\Insights\Models\Franchisee;
use App\Insights\Support\Access;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard of one brand owner, and the lists behind every one of its figures. Each figure is computed from the
 * list `lines()` gives for it (same scope, same rule, same rounding), and its definition travels with it.
 *
 * Scope: BrandScope (products, franchisees). Sales: SalesFacts (OPS after the cutover day, Odoo's history up to it).
 * What a user sees: Access::flags() — enforced here on every figure and every list, not by the client.
 */
class BrandDashboardService
{
    /** figure → bilingual definition (the client shows it beside the number) */
    public const DEFINITIONS = [
        'purchases' => ['ar' => 'صافي مشتريات الفروع من منتجات العلامة في الفترة: الفواتير ناقص إشعارات الدائن، قبل الضريبة وبعد الخصم. العمليات بعد يوم التحويل، وتاريخ أودو حتى يوم التحويل.',
            'en' => 'Net purchases of the brand\'s products by its franchisees in the period: invoices minus credit notes, before VAT, after discount. OPS after the cutover day, Odoo\'s history up to it.'],
        'invoiced' => ['ar' => 'الفواتير وحدها (دون إشعارات الدائن) لمنتجات العلامة في الفترة، قبل الضريبة.', 'en' => 'Invoices only (no credit notes) of the brand\'s products in the period, before VAT.'],
        'returns' => ['ar' => 'قيمة إشعارات الدائن (المرتجعات) لمنتجات العلامة في الفترة، قبل الضريبة.', 'en' => 'Value of the credit notes (returns) of the brand\'s products in the period, before VAT.'],
        'target' => ['ar' => 'مجموع الأهداف الشهرية للفروع النشطة والضعيفة عن كل شهر من الفترة بدأ الفرع قبل نهايته.', 'en' => 'Sum of the monthly targets of the active and weak franchisees, for each month of the period that ends after the franchisee started.'],
        'achievement' => ['ar' => 'صافي المشتريات ÷ الهدف × 100، لفروع لها هدف.', 'en' => 'Net purchases ÷ target × 100, over franchisees that have a target.'],
        'franchisees' => ['ar' => 'عدد الفروع بحالة نشط أو ضعيف.', 'en' => 'Number of franchisees whose status is active or weak.'],
        'overdue' => ['ar' => 'فواتير مفتوحة للفروع فيها منتجات العلامة وتجاوزت تاريخ استحقاقها اليوم. المبلغ = قيمة بنود العلامة قبل الضريبة × (الرصيد المفتوح ÷ إجمالي الفاتورة)، مقرّبًا مرة لكل فاتورة.',
            'en' => 'Open franchisee invoices carrying the brand\'s products that are past their due date today. Amount = the brand lines\' value before VAT × (open balance ÷ invoice total), rounded once per invoice.'],
        'payments' => ['ar' => 'سندات القبض المرحّلة من الفروع في الفترة (كامل السند، لا يُوزَّع على العلامات). من العمليات فقط.', 'en' => 'Posted receipts from the franchisees in the period (the whole receipt; not split by brand). OPS only.'],
        'margin' => ['ar' => 'هامش الربح = صافي المبيعات − تكلفة المبيعات، لبنود فواتير العمليات فقط (فواتير أودو لا تحمل التكلفة).', 'en' => 'Margin = net sales − cost of sales, OPS invoice lines only (Odoo\'s invoice lines carry no cost).'],
    ];

    public function __construct(private readonly BrandScope $scope, private readonly SalesFacts $facts) {}

    /** The period of a dashboard: whole months, default the last twelve including this one. @return array{0:string, 1:string, 2:string[]} */
    public static function period(?string $from, ?string $to): array
    {
        $t = $to ? Carbon::parse($to)->endOfMonth() : now()->endOfMonth();
        $f = $from ? Carbon::parse($from)->startOfMonth() : $t->copy()->startOfMonth()->subMonths(11);
        if ($f->gt($t)) {
            throw AppError::validation('RANGE', 'بداية الفترة بعد نهايتها', 'From is after to');
        }
        if ($f->diffInMonths($t) > 36) {
            throw AppError::validation('RANGE_TOO_LONG', 'الفترة أطول من ٣٦ شهرًا', 'The period is longer than 36 months');
        }
        $months = [];
        for ($m = $f->copy(); $m->lte($t); $m->addMonth()) {
            $months[] = $m->format('Y-m');
        }

        return [$f->toDateString(), $t->toDateString(), $months];
    }

    public function dashboard(AuthUser $user, string $ownerId, ?string $from, ?string $to): array
    {
        $owner = Access::owner($user, $ownerId);
        $flags = Access::flags($user, $owner);
        [$from, $to, $months] = self::period($from, $to);
        $products = $this->scope->productIds($owner);
        $franchisees = $this->scope->franchisees($owner);
        $customers = array_keys($franchisees);
        $lines = $this->facts->lines($products, $customers, $from, $to);

        $zero = array_fill_keys($months, 0.0);
        $by = [];        // customer → month → net
        $trend = ['purchases' => $zero, 'invoiced' => $zero, 'returns' => $zero];
        $tops = [];
        $tot = ['purchases' => 0.0, 'invoiced' => 0.0, 'returns' => 0.0, 'opsSales' => 0.0, 'opsCogs' => 0.0];
        foreach ($lines as $l) {
            $m = substr($l['date'], 0, 7);
            $by[$l['customerId']][$m] = ($by[$l['customerId']][$m] ?? 0) + $l['amount'];
            $trend['purchases'][$m] += $l['amount'];
            $tot['purchases'] += $l['amount'];
            $key = $l['kind'] === 'credit_note' ? 'returns' : 'invoiced';
            $trend[$key][$m] += $key === 'returns' ? -$l['amount'] : $l['amount'];
            $tot[$key] += $key === 'returns' ? -$l['amount'] : $l['amount'];
            $pk = $l['productId'] ?? 'odoo:'.$l['productName'];
            $tops[$pk] ??= ['productId' => $l['productId'], 'productName' => $l['productName'], 'qty' => 0.0, 'amount' => 0.0];
            $tops[$pk]['qty'] += $l['qty'];
            $tops[$pk]['amount'] += $l['amount'];
            if ($l['source'] === 'ops') {
                $tot['opsSales'] += $l['amount'];
                $tot['opsCogs'] += $l['cogs'];
            }
        }
        // targets: a buying franchisee counts for each month of the period that ends after it started
        $targetByMonth = $zero;
        $rows = [];
        $last = $this->facts->lastPurchase($products, $customers);
        $overdue = $flags['invoices'] ? $this->overdue($owner, $products, $customers) : null;
        $overdueBy = [];
        foreach ($overdue ?? [] as $i) {
            $overdueBy[$i['customerId']] = ['count' => ($overdueBy[$i['customerId']]['count'] ?? 0) + 1, 'amount' => round(($overdueBy[$i['customerId']]['amount'] ?? 0) + $i['openAmount'], 2)];
        }
        foreach ($franchisees as $cid => $fr) {
            $buying = in_array($fr->status, Franchisee::BUYING, true);
            $target = (float) $fr->monthly_target;
            $periodTarget = 0.0;
            $series = [];
            foreach ($months as $m) {
                $counts = $buying && $target > 0 && (! $fr->date_start || $fr->date_start->format('Y-m') <= $m);
                if ($counts) {
                    $periodTarget += $target;
                    $targetByMonth[$m] += $target;
                }
                $series[] = ['month' => $m, 'purchases' => round($by[$cid][$m] ?? 0, 2), 'target' => $counts ? round($target, 2) : null];
            }
            $purchases = round(array_sum($by[$cid] ?? []), 2);
            $rows[] = [
                'id' => $fr->id, 'customer' => self::customer($fr), 'region' => $fr->region, 'city' => $fr->city, 'status' => $fr->status, 'dateStart' => $fr->date_start?->toDateString(),
                'monthlyTarget' => round($target, 2), 'periodTarget' => round($periodTarget, 2), 'purchases' => $purchases,
                'achievementPct' => $periodTarget > 0 ? round($purchases / $periodTarget * 100, 1) : null, 'months' => $series, 'lastPurchase' => $last[$cid] ?? null,
                'overdue' => $flags['invoices'] ? ($overdueBy[$cid] ?? ['count' => 0, 'amount' => 0.0]) : null, 'sourceSystem' => $fr->source_system,
            ];
        }
        usort($rows, fn ($a, $b) => [$b['purchases'], $a['customer']['code']] <=> [$a['purchases'], $b['customer']['code']]);
        $targetTotal = round(array_sum($targetByMonth), 2);
        $withTarget = round(array_sum(array_map(fn ($r) => $r['periodTarget'] > 0 ? $r['purchases'] : 0, $rows)), 2);
        $topProducts = array_values($tops);
        usort($topProducts, fn ($a, $b) => $b['amount'] <=> $a['amount']);
        $names = DB::table('products')->whereIn('id', array_filter(array_column($topProducts, 'productId')))->get(['id', 'sku', 'name_ar', 'name_en'])->keyBy('id');
        $topProducts = array_map(fn ($p) => ['product' => $p['productId'] && isset($names[$p['productId']]) ? ['id' => $p['productId'], 'sku' => $names[$p['productId']]->sku, 'nameAr' => $names[$p['productId']]->name_ar, 'nameEn' => $names[$p['productId']]->name_en] : null,
            'name' => $p['productName'], 'qty' => round($p['qty'], 3), 'amount' => round($p['amount'], 2)], array_slice($topProducts, 0, 10));

        $kpis = [
            'purchases' => round($tot['purchases'], 2), 'invoiced' => round($tot['invoiced'], 2),
            'target' => $targetTotal, 'achievementPct' => $targetTotal > 0 ? round($withTarget / $targetTotal * 100, 1) : null,
            'franchisees' => count(array_filter($franchisees, fn ($f) => in_array($f->status, Franchisee::BUYING, true))),
        ];
        $kpis['returns'] = $flags['returns'] ? round($tot['returns'], 2) : null;
        $kpis['returnsPct'] = $flags['returns'] && $tot['invoiced'] > 0 ? round($tot['returns'] / $tot['invoiced'] * 100, 1) : null;
        $kpis['overdue'] = $overdue === null ? null : ['count' => count($overdue), 'amount' => round(array_sum(array_column($overdue, 'openAmount')), 2)];
        $kpis['payments'] = $flags['payments'] ? $this->paymentsTotal($customers, $from, $to) : null;
        $kpis['margin'] = $flags['margin'] ? ['sales' => round($tot['opsSales'], 2), 'cogs' => round($tot['opsCogs'], 2), 'margin' => round($tot['opsSales'] - $tot['opsCogs'], 2),
            'marginPct' => $tot['opsSales'] > 0 ? round(($tot['opsSales'] - $tot['opsCogs']) / $tot['opsSales'] * 100, 1) : null] : null;

        return [
            'owner' => self::owner($owner), 'access' => $flags, 'period' => ['from' => $from, 'to' => $to, 'months' => $months], 'cutover' => $this->facts->cutover(),
            'kpis' => $kpis,
            'trend' => array_map(fn ($m) => ['month' => $m, 'purchases' => round($trend['purchases'][$m], 2), 'invoiced' => round($trend['invoiced'][$m], 2),
                'returns' => $flags['returns'] ? round($trend['returns'][$m], 2) : null, 'target' => round($targetByMonth[$m], 2)], $months),
            'franchisees' => $rows, 'topProducts' => $topProducts,
            'overdueInvoices' => $overdue === null ? null : array_slice($overdue, 0, 10),
            'alerts' => ['unread' => BrandAlert::where('owner_id', $owner->id)->where('is_read', false)->count()],
            'products' => count($products), 'definitions' => self::DEFINITIONS, 'generatedAt' => now()->toJSON(),
        ];
    }

    /**
     * The list behind a figure — the same rule the figure uses. figure: sales (purchases), returns, overdue, payments.
     *
     * @param  array{figure:string, customer?:?string, product?:?string, month?:?string, from?:?string, to?:?string, source?:?string, kind?:?string}  $f
     */
    public function lines(AuthUser $user, string $ownerId, array $f, Paging $page): array
    {
        $owner = Access::owner($user, $ownerId);
        $flags = Access::flags($user, $owner);
        $products = $this->scope->productIds($owner);
        $franchisees = $this->scope->franchisees($owner);
        $customers = array_keys($franchisees);
        if (! empty($f['customer'])) {
            $customers = array_values(array_filter($customers, fn ($c) => $c === $f['customer'] || ($franchisees[$c]->customer?->code === $f['customer']) || $franchisees[$c]->id === $f['customer']));
        }
        if (! empty($f['month'])) {
            [$from, $to] = [Carbon::parse($f['month'].'-01')->toDateString(), Carbon::parse($f['month'].'-01')->endOfMonth()->toDateString()];
        } else {
            [$from, $to] = self::period($f['from'] ?? null, $f['to'] ?? null);
        }
        $figure = $f['figure'];
        if ($figure === 'overdue') {
            Access::need($flags, 'invoices');
            $items = $this->overdue($owner, $products, $customers);
            $items = array_map(fn ($i) => $i + ['customer' => self::customer($franchisees[$i['customerId']])], $items);

            return $page->wrap(array_slice($items, ($page->page - 1) * $page->pageSize, $page->pageSize), count($items))
                + ['totals' => ['count' => count($items), 'amount' => round(array_sum(array_column($items, 'openAmount')), 2)], 'definition' => self::DEFINITIONS['overdue']];
        }
        if ($figure === 'payments') {
            Access::need($flags, 'payments');
            $q = $this->payments($customers, $from, $to);
            $total = round((float) (clone $q)->sum('amount'), 2);
            $count = (clone $q)->count();
            $rows = $q->orderByDesc('payment_date')->orderByDesc('number')->forPage($page->page, $page->pageSize)->get(['id', 'number', 'payment_date', 'partner_id', 'amount', 'method', 'reference']);

            return $page->wrap($rows->map(fn ($r) => ['id' => $r->id, 'number' => $r->number, 'date' => substr((string) $r->payment_date, 0, 10), 'customer' => self::customer($franchisees[$r->partner_id] ?? null),
                'amount' => round((float) $r->amount, 2), 'method' => $r->method, 'reference' => $r->reference])->all(), $count)
                + ['totals' => ['amount' => $total], 'definition' => self::DEFINITIONS['payments'], 'period' => ['from' => $from, 'to' => $to]];
        }
        if ($figure === 'returns') {
            Access::need($flags, 'returns');
            $f['kind'] = 'credit_note';
        } elseif ($figure !== 'sales') {
            throw AppError::validation('FIGURE_UNKNOWN', 'رقم غير معروف', 'Unknown figure');
        }
        $all = $this->facts->lines($products, $customers, $from, $to, ['kind' => $f['kind'] ?? null, 'source' => $f['source'] ?? null]);
        if (! empty($f['product'])) {
            $all = array_values(array_filter($all, fn ($l) => $l['productId'] === $f['product']));
        }
        $totals = ['qty' => round(array_sum(array_column($all, 'qty')), 3), 'amount' => round(array_sum(array_column($all, 'amount')), 2)];
        if ($flags['margin']) {
            $ops = array_filter($all, fn ($l) => $l['source'] === 'ops');
            $totals['opsAmount'] = round(array_sum(array_column($ops, 'amount')), 2);
            $totals['cogs'] = round(array_sum(array_column($ops, 'cogs')), 2);
        }
        $slice = array_slice($all, ($page->page - 1) * $page->pageSize, $page->pageSize);
        $names = DB::table('products')->whereIn('id', array_filter(array_column($slice, 'productId')))->get(['id', 'sku', 'name_ar', 'name_en'])->keyBy('id');
        $items = array_map(function ($l) use ($flags, $franchisees, $names) {
            $p = $l['productId'] ? ($names[$l['productId']] ?? null) : null;
            $row = ['source' => $l['source'], 'date' => $l['date'], 'kind' => $l['kind'], 'customer' => self::customer($franchisees[$l['customerId']] ?? null),
                'product' => $p ? ['id' => $p->id, 'sku' => $p->sku, 'nameAr' => $p->name_ar, 'nameEn' => $p->name_en] : null, 'productName' => $l['productName'],
                'qty' => $l['qty'], 'amount' => $l['amount']];
            // the document is shown only to whom invoices are open
            if ($flags['invoices']) {
                $row['document'] = ['id' => $l['documentId'], 'number' => $l['number']];
            }
            if ($flags['margin']) {
                $row['cogs'] = $l['cogs'];
                $row['margin'] = $l['cogs'] === null ? null : round($l['amount'] - $l['cogs'], 2);
            }

            return $row;
        }, $slice);

        return $page->wrap($items, count($all)) + ['totals' => $totals, 'definition' => self::DEFINITIONS[$figure === 'returns' ? 'returns' : 'purchases'], 'period' => ['from' => $from, 'to' => $to]];
    }

    /**
     * Open invoices of the franchisees carrying the brand's products that are past due by more than `$days` today —
     * OPS invoices dated after the cutover day, Odoo's up to it. Oldest due first.
     *
     * @return array<int, array{source:string, documentId:string, number:string, customerId:string, date:?string, dueDate:string, daysOverdue:int, total:float, open:float, brandAmount:float, openAmount:float}>
     */
    public function overdue(BrandOwner $owner, array $products, array $customers, int $days = 0): array
    {
        if (! $products || ! $customers) {
            return [];
        }
        $today = now()->startOfDay();
        $limit = $today->copy()->subDays($days)->toDateString();
        $cut = $this->facts->cutover()['date'];
        $out = [];
        $ops = CustomerInvoice::where('kind', 'invoice')->whereIn('status', CustomerInvoice::OPEN)->whereIn('customer_id', $customers)
            ->where('invoice_date', '>', $cut)->whereNotNull('due_date')->where('due_date', '<', $limit)->get(['id', 'number', 'customer_id', 'invoice_date', 'due_date', 'total', 'paid_amount']);
        if ($ops->isNotEmpty()) {
            $brand = DB::table('fin_customer_invoice_lines')->whereIn('invoice_id', $ops->pluck('id'))->whereIn('product_id', $products)
                ->groupBy('invoice_id')->selectRaw('invoice_id, coalesce(sum(line_total), 0) as amount')->pluck('amount', 'invoice_id');
            foreach ($ops as $i) {
                $amount = round((float) ($brand[$i->id] ?? 0), 2);
                $total = (float) $i->total;
                $open = round($total - (float) $i->paid_amount, 2);
                if ($amount <= 0 || $open <= 0) {
                    continue;
                }
                $out[] = ['source' => 'ops', 'documentId' => (string) $i->id, 'number' => $i->number, 'customerId' => (string) $i->customer_id, 'date' => $i->invoice_date?->toDateString(),
                    'dueDate' => $i->due_date->toDateString(), 'daysOverdue' => (int) $i->due_date->diffInDays($today), 'total' => round($total, 2), 'open' => $open, 'brandAmount' => $amount,
                    'openAmount' => $total > 0 ? round($amount * $open / $total, 2) : 0.0];
            }
        }
        $partners = $this->facts->refs('customer', $customers);
        $refs = $this->facts->refs('product', $products);
        if ($partners && $refs) {
            $odoo = DB::table('ins_odoo_invoices')->where('state', 'posted')->where('move_type', 'out_invoice')->where('residual', '>', 0)
                ->whereIn('partner_ref', array_map('strval', array_keys($partners)))->where('invoice_date', '<=', $cut)->whereNotNull('due_date')->where('due_date', '<', $limit)
                ->get(['odoo_id', 'number', 'partner_ref', 'invoice_date', 'due_date', 'amount_total', 'residual']);
            if ($odoo->isNotEmpty()) {
                $brand = DB::table('ins_odoo_sale_lines')->whereIn('move_id', $odoo->pluck('odoo_id'))->whereIn('product_ref', array_map('strval', array_keys($refs)))
                    ->groupBy('move_id')->selectRaw('move_id, coalesce(sum(amount), 0) as amount')->pluck('amount', 'move_id');
                foreach ($odoo as $i) {
                    $amount = round((float) ($brand[$i->odoo_id] ?? 0), 2);
                    $total = (float) $i->amount_total;
                    $open = round((float) $i->residual, 2);
                    if ($amount <= 0) {
                        continue;
                    }
                    $due = Carbon::parse($i->due_date);
                    $out[] = ['source' => 'odoo', 'documentId' => (string) $i->odoo_id, 'number' => $i->number, 'customerId' => $partners[(string) $i->partner_ref], 'date' => substr((string) $i->invoice_date, 0, 10),
                        'dueDate' => $due->toDateString(), 'daysOverdue' => (int) $due->diffInDays($today), 'total' => round($total, 2), 'open' => $open, 'brandAmount' => $amount,
                        'openAmount' => $total > 0 ? round($amount * $open / $total, 2) : 0.0];
                }
            }
        }
        usort($out, fn ($a, $b) => [$a['dueDate'], $a['number']] <=> [$b['dueDate'], $b['number']]);

        return $out;
    }

    private function payments(array $customers, string $from, string $to)
    {
        return DB::table('fin_payments')->where('direction', 'in')->where('partner_type', 'customer')->where('status', 'posted')
            ->whereIn('partner_id', $customers ?: ['-'])->whereBetween('payment_date', [$from, $to]);
    }

    private function paymentsTotal(array $customers, string $from, string $to): array
    {
        $q = $this->payments($customers, $from, $to);

        return ['count' => (clone $q)->count(), 'amount' => round((float) $q->sum('amount'), 2)];
    }

    public static function customer(?Franchisee $f): ?array
    {
        $c = $f?->customer;

        return $c ? ['id' => $c->id, 'code' => $c->code, 'nameAr' => $c->name_ar, 'nameEn' => $c->name_en, 'franchiseeId' => $f->id] : null;
    }

    public static function owner(BrandOwner $o): array
    {
        return ['id' => $o->id, 'name' => $o->name, 'brandName' => $o->brand_name, 'state' => $o->state];
    }
}
