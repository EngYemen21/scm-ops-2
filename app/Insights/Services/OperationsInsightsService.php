<?php

namespace App\Insights\Services;

use App\Finance\Models\CustomerInvoice;
use App\Finance\Services\FinanceReportsService;
use App\Services\Platform\ReportsService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The operations manager's insights (Odoo's "operations manager dashboard"). What OPS already computes is CALLED, not
 * recomputed, so the figures agree with the existing reports to the halala:
 *   profit and loss     FinanceReportsService::incomeStatement()
 *   customer ageing     FinanceReportsService::aging('customer')            (+ Odoo's open invoices, apart)
 *   customer margin     FinanceReportsService::profitability('customer')
 *   expiry buckets      ReportsService 'expiry' report, one call per bucket
 * What OPS lacked is added here: slow-moving products, invoice counts per month (OPS and Odoo's history), and the
 * ageing of the invoices still open in Odoo. Finance figures need `fin.report` (as the finance reports do); values at
 * cost need `inventory.view_cost`.
 */
class OperationsInsightsService
{
    public const BUCKETS = ['expired', '0-7', '8-30', '31-90', '90+'];

    public function __construct(private readonly FinanceReportsService $finance, private readonly ReportsService $reports) {}

    public function summary(AuthUser $user, ?string $from, ?string $to, int $slowDays = 90): array
    {
        $fin = $user->can('fin.report');
        [$f, $t] = self::range($from, $to);
        $out = ['from' => $f, 'to' => $t, 'finance' => $fin, 'costVisible' => $user->can('inventory.view_cost')];
        $out['expiry'] = $this->expiryBuckets($user);
        $slow = $this->slowMoving($user, new Paging(1, 500, null, null, 'desc'), ['days' => $slowDays]);
        $out['slowMoving'] = ['days' => $slowDays, 'products' => $slow['total'], 'qty' => $slow['totals']['qty']] + (isset($slow['totals']['value']) ? ['value' => $slow['totals']['value']] : []);
        if ($fin) {
            $pl = $this->finance->incomeStatement($f, $t);
            $out['pnl'] = $pl['totals'];
            $ageing = $this->finance->aging('customer');
            $out['ageing'] = $ageing['totals'] + ['customers' => count($ageing['rows'])];
            $out['odooAgeing'] = $this->odooAgeing()['totals'];
            $counts = $this->invoiceCounts($f, $t);
            $out['invoices'] = $counts['totals'];
        }

        return $out;
    }

    /** @return array{asOf:string, buckets:array<int, array{bucket:string, lots:int, qty:float, value?:float}>} */
    public function expiryBuckets(AuthUser $user): array
    {
        $buckets = [];
        foreach (self::BUCKETS as $b) {
            $r = $this->reports->run($user, 'expiry', new Paging(1, 1, null, null, 'desc'), ['status' => $b]);
            $buckets[] = ['bucket' => $b, 'lots' => (int) $r['total'], 'qty' => (float) ($r['totals']['qty'] ?? 0)] + (array_key_exists('value', $r['totals'] ?? []) ? ['value' => round((float) $r['totals']['value'], 2)] : []);
        }

        return ['asOf' => now()->toDateString(), 'buckets' => $buckets, 'report' => 'expiry',
            'definition' => ['ar' => 'من تقرير الصلاحية القائم: الدفعات ذات الرصيد مصنفة بالأيام المتبقية حتى انتهائها.', 'en' => 'From the existing expiry report: lots with stock, grouped by the days left to their expiry.']];
    }

    /**
     * Products with stock that did not leave stock for `days` days: on hand > 0 (in the warehouse when one is given),
     * no outbound movement (out of a bin to no bin) in the last `days` days, and first received more than `days` days
     * ago. Value = on hand × purchase price (as the inventory ageing report), only with `inventory.view_cost`.
     */
    public function slowMoving(AuthUser $user, Paging $page, array $f): array
    {
        $days = max(1, min(3650, (int) ($f['days'] ?? 90)));
        $cut = now()->subDays($days);
        $wh = null;
        if (! empty($f['warehouse'])) {
            $wh = DB::table('warehouses')->where('code', mb_strtoupper($f['warehouse']))->orWhere('id', $f['warehouse'])->value('id')
                ?? throw AppError::notFound('WAREHOUSE_NOT_FOUND', "المستودع {$f['warehouse']} غير موجود", 'Warehouse not found');
        }
        $stock = DB::table('inventory_balances')->when($wh, fn ($q) => $q->where('warehouse_id', $wh))->groupBy('product_id')->havingRaw('sum(on_hand) > 0')
            ->selectRaw('product_id, sum(on_hand) as qty')->pluck('qty', 'product_id');
        $ids = $stock->keys()->all();
        $lastOut = $firstIn = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $m = DB::table('inventory_movements as m')->leftJoin('bins as sb', 'sb.id', '=', 'm.src_bin_id')->leftJoin('bins as db', 'db.id', '=', 'm.dst_bin_id')->whereIn('m.product_id', $chunk);
            $lastOut += (clone $m)->whereNotNull('m.src_bin_id')->whereNull('m.dst_bin_id')->when($wh, fn ($q) => $q->where('sb.warehouse_id', $wh))
                ->groupBy('m.product_id')->selectRaw('m.product_id, max(m.created_at) as at')->pluck('at', 'product_id')->all();
            $firstIn += (clone $m)->whereNotNull('m.dst_bin_id')->when($wh, fn ($q) => $q->where('db.warehouse_id', $wh))
                ->groupBy('m.product_id')->selectRaw('m.product_id, min(m.created_at) as at')->pluck('at', 'product_id')->all();
        }
        $products = DB::table('products as p')->leftJoin('product_categories as c', 'c.id', '=', 'p.category_id')->whereIn('p.id', $ids)
            ->get(['p.id', 'p.sku', 'p.name_ar', 'p.name_en', 'p.brand', 'p.purchase_price', 'c.code as category'])->keyBy('id');
        $cost = $user->can('inventory.view_cost');
        $rows = [];
        foreach ($stock as $pid => $qty) {
            $out = $lastOut[$pid] ?? null;
            $in = $firstIn[$pid] ?? null;
            if (($out && Carbon::parse($out)->gte($cut)) || ($in && Carbon::parse($in)->gte($cut))) {
                continue;
            }
            $p = $products[$pid] ?? null;
            if (! $p) {
                continue;
            }
            if ($page->q && ! str_contains(mb_strtolower($p->sku.' '.$p->name_ar.' '.$p->name_en), mb_strtolower($page->q))) {
                continue;
            }
            $row = ['productId' => $pid, 'sku' => $p->sku, 'nameAr' => $p->name_ar, 'nameEn' => $p->name_en, 'brand' => $p->brand, 'category' => $p->category, 'qty' => (int) $qty,
                'lastOut' => $out ? Carbon::parse($out)->toJSON() : null, 'daysSinceOut' => $out ? (int) Carbon::parse($out)->startOfDay()->diffInDays(now()->startOfDay(), true) : null,
                'firstIn' => $in ? Carbon::parse($in)->toJSON() : null];
            if ($cost) {
                $row['value'] = round($qty * (float) ($p->purchase_price ?? 0), 2);
            }
            $rows[] = $row;
        }
        $sort = $page->sort ?? 'daysSinceOut';
        usort($rows, function ($a, $b) use ($sort, $page) {
            $k = in_array($sort, ['qty', 'value', 'sku', 'daysSinceOut'], true) ? $sort : 'daysSinceOut';
            // never sold first when sorting by days (null = "never"), then the chosen order, then the SKU
            $va = $k === 'daysSinceOut' ? ($a[$k] ?? PHP_INT_MAX) : ($a[$k] ?? 0);
            $vb = $k === 'daysSinceOut' ? ($b[$k] ?? PHP_INT_MAX) : ($b[$k] ?? 0);
            $c = $page->order === 'asc' ? $va <=> $vb : $vb <=> $va;

            return $c ?: strcmp($a['sku'], $b['sku']);
        });
        $totals = ['qty' => array_sum(array_column($rows, 'qty'))] + ($cost ? ['value' => round(array_sum(array_column($rows, 'value')), 2)] : []);

        return $page->wrap(array_slice($rows, ($page->page - 1) * $page->pageSize, $page->pageSize), count($rows)) + ['days' => $days, 'totals' => $totals, 'costVisible' => $cost,
            'definition' => ['ar' => "منتجات لها رصيد ولم تخرج من المخزون منذ {$days} يومًا (ولم يُستلم أول وارد لها خلال هذه المدة).", 'en' => "Products with stock that did not leave stock for {$days} days (and were first received before that)."]];
    }

    public function customerMargin(?string $from, ?string $to): array
    {
        [$f, $t] = self::range($from, $to);

        return $this->finance->profitability('customer', $f, $t) + ['definition' => ['ar' => 'من تقرير الربحية القائم: إيرادات فواتير العمليات ناقص تكلفة مبيعاتها لكل عميل.', 'en' => 'From the existing profitability report: OPS invoice revenue minus its cost of sales, per customer.']];
    }

    public function pnl(?string $from, ?string $to): array
    {
        [$f, $t] = self::range($from, $to);

        return $this->finance->incomeStatement($f, $t);
    }

    /** OPS ageing (the existing report) and, apart, the invoices still open in Odoo. */
    public function ageing(): array
    {
        return ['ops' => $this->finance->aging('customer'), 'odoo' => $this->odooAgeing()];
    }

    /** Odoo's posted customer invoices still open, by customer and bucket of their due date (same buckets as OPS). */
    public function odooAgeing(): array
    {
        $today = now()->startOfDay();
        $rows = [];
        $q = DB::table('ins_odoo_invoices')->where('state', 'posted')->where('move_type', 'out_invoice')->where('residual', '>', 0)->orderBy('odoo_id');
        foreach ($q->get(['odoo_id', 'partner_ref', 'partner_name', 'due_date', 'residual']) as $i) {
            $due = $i->due_date ? Carbon::parse($i->due_date) : null;
            $days = $due ? (int) $due->diffInDays($today, false) : 0;
            $b = ! $due || $days <= 0 ? 'current' : ($days <= 30 ? 'd30' : ($days <= 60 ? 'd60' : ($days <= 90 ? 'd90' : 'd90p')));
            $k = (string) $i->partner_ref;
            $rows[$k] ??= ['partnerRef' => $k, 'name' => $i->partner_name, 'current' => 0.0, 'd30' => 0.0, 'd60' => 0.0, 'd90' => 0.0, 'd90p' => 0.0, 'total' => 0.0, 'invoices' => 0];
            $rows[$k][$b] = round($rows[$k][$b] + (float) $i->residual, 2);
            $rows[$k]['total'] = round($rows[$k]['total'] + (float) $i->residual, 2);
            $rows[$k]['invoices']++;
        }
        $links = DB::table('int_external_refs')->where('system', 'odoo')->where('entity', 'customer')->whereIn('external_id', array_map('strval', array_keys($rows)))->pluck('internal_id', 'external_id');
        $codes = DB::table('customers')->whereIn('id', $links->values())->pluck('code', 'id');
        $rows = array_map(fn ($r) => $r + ['customerCode' => isset($links[$r['partnerRef']]) ? ($codes[$links[$r['partnerRef']]] ?? null) : null], array_values($rows));
        usort($rows, fn ($a, $b) => [$b['total'], $a['partnerRef']] <=> [$a['total'], $b['partnerRef']]);
        $totals = ['current' => 0.0, 'd30' => 0.0, 'd60' => 0.0, 'd90' => 0.0, 'd90p' => 0.0, 'total' => 0.0, 'invoices' => 0];
        foreach ($rows as $r) {
            foreach ($totals as $k => $v) {
                $totals[$k] = $k === 'invoices' ? $v + $r[$k] : round($v + $r[$k], 2);
            }
        }

        return ['asOf' => $today->toDateString(), 'rows' => $rows, 'totals' => $totals,
            'definition' => ['ar' => 'فواتير العملاء المرحّلة في أودو التي ما زال لها رصيد مفتوح، من نسخة أودو المحلية.', 'en' => 'Posted customer invoices in Odoo that still have an open balance, from the local copy of Odoo.']];
    }

    /** Customer invoices and credit notes per month: OPS (by state) and Odoo's history (posted). */
    public function invoiceCounts(?string $from, ?string $to): array
    {
        [$f, $t] = self::range($from, $to);
        $months = [];
        $blank = fn () => ['opsInvoices' => 0, 'opsCreditNotes' => 0, 'opsCancelled' => 0, 'opsAmount' => 0.0, 'odooInvoices' => 0, 'odooCreditNotes' => 0, 'odooAmount' => 0.0];
        foreach (CustomerInvoice::whereBetween('invoice_date', [$f, $t])->get(['kind', 'status', 'invoice_date', 'subtotal', 'discount']) as $i) {
            $m = $i->invoice_date->format('Y-m');
            $months[$m] ??= $blank();
            if ($i->status === 'cancelled') {
                $months[$m]['opsCancelled']++;

                continue;
            }
            $credit = $i->kind === 'credit_note';
            $months[$m][$credit ? 'opsCreditNotes' : 'opsInvoices']++;
            $months[$m]['opsAmount'] = round($months[$m]['opsAmount'] + ($credit ? -1 : 1) * ((float) $i->subtotal - (float) $i->discount), 2);
        }
        foreach (DB::table('ins_odoo_invoices')->where('state', 'posted')->whereBetween('invoice_date', [$f, $t])->get(['move_type', 'invoice_date', 'amount_untaxed']) as $i) {
            $m = substr((string) $i->invoice_date, 0, 7);
            $months[$m] ??= $blank();
            $credit = $i->move_type === 'out_refund';
            $months[$m][$credit ? 'odooCreditNotes' : 'odooInvoices']++;
            $months[$m]['odooAmount'] = round($months[$m]['odooAmount'] + ($credit ? -1 : 1) * (float) $i->amount_untaxed, 2);
        }
        ksort($months);
        $rows = [];
        $totals = $blank();
        foreach ($months as $m => $r) {
            $rows[] = ['month' => $m] + $r;
            foreach ($r as $k => $v) {
                $totals[$k] = is_float($v) ? round($totals[$k] + $v, 2) : $totals[$k] + $v;
            }
        }
        $open = DB::table('fin_customer_invoices')->where('kind', 'invoice')->whereIn('status', CustomerInvoice::OPEN);

        return ['from' => $f, 'to' => $t, 'rows' => $rows, 'totals' => $totals + ['opsOpen' => (clone $open)->count(), 'opsOverdue' => (clone $open)->whereNotNull('due_date')->where('due_date', '<', now()->toDateString())->count()],
            'definition' => ['ar' => 'عدد فواتير العملاء وإشعارات الدائن في كل شهر حسب تاريخ الفاتورة، والمبلغ قبل الضريبة (إشعار الدائن سالب). العمليات حسب الحالة؛ أودو المرحّل فقط.',
                'en' => 'Customer invoices and credit notes per month of their date, and their amount before VAT (credit notes negative). OPS by state; Odoo posted only.']];
    }

    /** @return array{0:string, 1:string} */
    public static function range(?string $from, ?string $to): array
    {
        $t = $to ? Carbon::parse($to) : now();
        $f = $from ? Carbon::parse($from) : $t->copy()->startOfMonth();
        if ($f->gt($t)) {
            throw AppError::validation('RANGE', 'بداية الفترة بعد نهايتها', 'From is after to');
        }

        return [$f->toDateString(), $t->toDateString()];
    }
}
