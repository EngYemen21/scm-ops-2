<?php

namespace App\Pos\Services;

use App\Pos\Models\PosCashMove;
use App\Pos\Models\PosOrder;
use App\Pos\Models\PosOrderLine;
use App\Pos\Models\PosOrderPayment;
use App\Pos\Models\PosPaymentMethod;
use App\Pos\Models\PosRegister;
use App\Pos\Models\PosSession;
use App\Pos\Support\Money;
use App\Support\AppError;
use App\Support\AuthUser;

/**
 * The end-of-day report of a session (Odoo's "Z report"): what was sold and refunded, the VAT, the money by payment
 * method, the cash drawer from float to count, the products, and what is still owed to stock. Every figure is a sum of
 * the session's own documents, in halalas. A closed session's report is the one frozen at closing.
 */
class ReportService
{
    /** Light totals for lists and the session header. */
    public function totals(PosSession $s): array
    {
        $rows = PosOrder::where('session_id', $s->id)->get(['kind', 'subtotal', 'vat_amount', 'total']);
        $sum = fn (string $kind, string $col) => $rows->where('kind', $kind)->sum(fn ($o) => Money::cents($o->{$col}));
        $sales = $sum('sale', 'total');
        $refunds = $sum('refund', 'total');

        return [
            'sales' => $rows->where('kind', 'sale')->count(), 'refunds' => $rows->where('kind', 'refund')->count(),
            'salesTotal' => Money::str($sales), 'refundsTotal' => Money::str($refunds), 'netTotal' => Money::str($sales - $refunds),
            'netVat' => Money::str($sum('sale', 'vat_amount') - $sum('refund', 'vat_amount')),
        ];
    }

    /** The report of a session: frozen for a closed OPS session, computed for an open one (and for Odoo's history). */
    public function forSession(string $id, ?AuthUser $viewer): array
    {
        $s = PosSession::where(fn ($w) => $w->where('id', $id)->orWhere('number', $id))->first() ?? throw AppError::notFound('POS_SESSION_NOT_FOUND', 'الجلسة غير موجودة', 'Session not found');
        $report = $s->state === 'closed' && is_array($s->report) && $s->report ? $s->report + ['frozen' => true] : $this->build($s) + ['frozen' => false];

        return self::forViewer($report, $viewer);
    }

    /** Cost and margin only for those who may see cost. */
    public static function forViewer(array $report, ?AuthUser $viewer): array
    {
        if (! $viewer || ! $viewer->can('inventory.view_cost')) {
            unset($report['margin']);
            foreach ($report['products'] ?? [] as $i => $p) {
                unset($report['products'][$i]['cogs']);
            }
        }

        return $report;
    }

    public function build(PosSession $s): array
    {
        $register = PosRegister::find($s->register_id);
        $orders = PosOrder::where('session_id', $s->id)->orderBy('ordered_at')->get();
        $sales = $orders->where('kind', 'sale');
        $refunds = $orders->where('kind', 'refund');
        $c = fn ($set, string $col) => (int) $set->sum(fn ($o) => Money::cents($o->{$col}));

        // ── money by method
        $methods = PosPaymentMethod::whereIn('id', PosOrderPayment::whereIn('order_id', $orders->pluck('id'))->distinct()->pluck('method_id'))->get()->keyBy('id');
        $byMethod = [];
        foreach (PosOrderPayment::whereIn('order_id', $orders->pluck('id'))->get() as $p) {
            $kind = $orders->firstWhere('id', $p->order_id)?->kind;
            $m = $methods[$p->method_id] ?? null;
            $key = $p->method_id ?? 'unknown';
            $byMethod[$key] ??= ['methodId' => $p->method_id, 'code' => $m?->code ?? $p->method_code, 'nameAr' => $m?->name_ar ?? $p->method_code, 'nameEn' => $m?->name_en ?? $p->method_code,
                'kind' => $p->kind, 'sales' => 0, 'refunds' => 0, 'count' => 0];
            $byMethod[$key][$kind === 'refund' ? 'refunds' : 'sales'] += Money::cents($p->applied ?? $p->amount);
            $byMethod[$key]['count']++;
        }
        $byMethod = array_values(array_map(fn ($r) => array_merge($r, ['sales' => Money::str($r['sales']), 'refunds' => Money::str($r['refunds']), 'net' => Money::str($r['sales'] - $r['refunds'])]), $byMethod));

        // ── the drawer
        $cashKind = fn ($kind) => (int) PosOrderPayment::whereIn('order_id', $orders->where('kind', $kind)->pluck('id'))->where('kind', 'cash')->get(['applied', 'amount'])->sum(fn ($p) => Money::cents($p->applied ?? $p->amount));
        $moves = PosCashMove::where('session_id', $s->id)->get(['direction', 'amount', 'reason', 'created_by', 'created_at']);
        $in = (int) $moves->where('direction', 'in')->sum(fn ($m) => Money::cents($m->amount));
        $out = (int) $moves->where('direction', 'out')->sum(fn ($m) => Money::cents($m->amount));
        $expected = Money::cents($s->opening_cash) + $cashKind('sale') - $cashKind('refund') + $in - $out;

        // ── VAT by rate
        $vat = [];
        foreach ($orders as $o) {
            $rate = Money::str(Money::cents($o->vat_pct ?? 0));
            $sign = $o->kind === 'refund' ? -1 : 1;
            $vat[$rate] ??= ['ratePct' => $rate, 'base' => 0, 'vat' => 0];
            $vat[$rate]['base'] += $sign * Money::cents($o->subtotal);
            $vat[$rate]['vat'] += $sign * Money::cents($o->vat_amount);
        }
        $vat = array_values(array_map(fn ($r) => ['ratePct' => $r['ratePct'], 'base' => Money::str($r['base']), 'vat' => Money::str($r['vat'])], $vat));

        // ── products
        $lines = PosOrderLine::whereIn('order_id', $orders->pluck('id'))->get(['order_id', 'product_id', 'sku', 'name', 'name_en', 'qty', 'line_total', 'cogs', 'pending_qty']);
        $products = [];
        foreach ($lines as $l) {
            $refund = $orders->firstWhere('id', $l->order_id)?->kind === 'refund';
            $k = $l->product_id ?? $l->sku ?? $l->name;
            $products[$k] ??= ['productId' => $l->product_id, 'sku' => $l->sku, 'nameAr' => $l->name, 'nameEn' => $l->name_en ?? $l->name, 'qtySold' => 0, 'qtyRefunded' => 0, 'net' => 0, 'cogs' => 0];
            $q = (int) round((float) $l->qty);
            $products[$k][$refund ? 'qtyRefunded' : 'qtySold'] += $q;
            $products[$k]['net'] += ($refund ? -1 : 1) * Money::cents($l->line_total);
            $products[$k]['cogs'] += ($refund ? -1 : 1) * Money::cents($l->cogs);
        }
        usort($products, fn ($a, $b) => [$b['net'], $a['sku']] <=> [$a['net'], $b['sku']]);
        $products = array_map(fn ($p) => array_merge($p, ['net' => Money::str($p['net']), 'cogs' => Money::str($p['cogs'])]), $products);

        $pending = [];
        foreach ($lines->where('pending_qty', '>', 0) as $l) {
            $pending[] = ['order' => $orders->firstWhere('id', $l->order_id)?->number, 'sku' => $l->sku, 'nameAr' => $l->name, 'qty' => (int) $l->pending_qty];
        }
        $salesNet = $c($sales, 'subtotal');
        $refundNet = $c($refunds, 'subtotal');
        $cogs = $c($sales, 'cogs_total') - $c($refunds, 'cogs_total');

        return [
            'session' => ['id' => $s->id, 'number' => $s->number, 'state' => $s->state, 'register' => $register ? ['code' => $register->code, 'nameAr' => $register->name_ar, 'nameEn' => $register->name_en] : null,
                'cashier' => $s->cashier_name, 'openedAt' => $s->opened_at?->toIso8601String(), 'closedAt' => $s->closed_at?->toIso8601String(), 'history' => $s->source_system !== null],
            'sales' => ['count' => $sales->count(), 'gross' => Money::str($salesNet + $c($sales, 'discount_total')), 'discount' => Money::str($c($sales, 'discount_total')), 'net' => Money::str($salesNet),
                'vat' => Money::str($c($sales, 'vat_amount')), 'total' => Money::str($c($sales, 'total')), 'onAccount' => Money::str($c($sales, 'on_account')), 'change' => Money::str($c($sales, 'change_due'))],
            'refunds' => ['count' => $refunds->count(), 'net' => Money::str($refundNet), 'vat' => Money::str($c($refunds, 'vat_amount')), 'total' => Money::str($c($refunds, 'total'))],
            'net' => ['net' => Money::str($salesNet - $refundNet), 'vat' => Money::str($c($sales, 'vat_amount') - $c($refunds, 'vat_amount')), 'total' => Money::str($c($sales, 'total') - $c($refunds, 'total'))],
            'byMethod' => $byMethod,
            'cash' => [
                'opening' => Money::str(Money::cents($s->opening_cash)), 'sales' => Money::str($cashKind('sale')), 'refunds' => Money::str($cashKind('refund')), 'in' => Money::str($in), 'out' => Money::str($out),
                'expected' => Money::str($expected), 'counted' => $s->state === 'closed' ? $s->counted_cash : null, 'difference' => $s->state === 'closed' ? $s->difference : null,
                'reason' => $s->difference_reason, 'counts' => $s->closing_counts ?? [],
                'moves' => $moves->map(fn ($m) => ['direction' => $m->direction, 'amount' => $m->amount, 'reason' => $m->reason, 'by' => $m->created_by, 'at' => $m->created_at?->toIso8601String()])->values()->all(),
            ],
            'vat' => $vat,
            'products' => $products,
            'pending' => $pending,
            'margin' => ['cogs' => Money::str($cogs), 'gross' => Money::str($salesNet - $refundNet - $cogs)],
            'generatedAt' => now()->toIso8601String(),
        ];
    }
}
