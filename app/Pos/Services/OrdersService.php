<?php

namespace App\Pos\Services;

use App\Finance\Models\Journal;
use App\Pos\Models\PosOrder;
use App\Pos\Models\PosOrderLine;
use App\Pos\Models\PosOrderLineLot;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;

/** Reading the orders of the counter: the list with its filters, one order with everything it caused. */
class OrdersService
{
    public function get(string $idOrNumber, ?AuthUser $viewer): array
    {
        $o = PosOrder::with(['lines' => fn ($q) => $q->orderBy('line_no'), 'payments' => fn ($q) => $q->orderBy('line_no'), 'payments.method:id,code,name_ar,name_en,kind',
            'session:id,number,state', 'register:id,code,name_ar,name_en,refund_needs_manager', 'customer:id,code,name_ar,name_en,vat_no', 'refundOf:id,number', 'refunds:id,number,refund_of_id,total,ordered_at,reason'])
            ->where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->first()
            ?? throw AppError::notFound('POS_ORDER_NOT_FOUND', 'الطلب غير موجود', 'Order not found');
        $row = $o->toArray();
        unset($row['payloadHash']);
        $cost = $viewer?->can('inventory.view_cost') ?? false;
        $lots = $cost ? PosOrderLineLot::where('order_id', $o->id)->orderBy('created_at')->get()->groupBy('line_id') : collect();
        $row['lines'] = $o->lines->map(function (PosOrderLine $l) use ($o, $cost, $lots) {
            $r = $l->toArray();
            $r['refundableQty'] = $o->kind === 'sale' && $o->source_system === null ? max(0, (int) $l->qty - (int) $l->refunded_qty) : 0;
            if ($cost) {
                $r['lots'] = ($lots[$l->id] ?? collect())->map(fn ($x) => ['kind' => $x->kind, 'movement' => $x->movement_number, 'qty' => $x->qty, 'returnedQty' => $x->returned_qty, 'unitCost' => $x->unit_cost, 'binId' => $x->bin_id, 'batchId' => $x->batch_id])->values()->all();
            } else {
                unset($r['unitCost'], $r['cogs']);
            }

            return $r;
        })->all();
        if (! $cost) {
            unset($row['cogsTotal']);
        }
        // every journal the order caused: its own (stock, cost of sales, money paid back), its invoice / credit note, its receipts
        $receipts = $o->payments->pluck('fin_payment_id')->filter()->values()->all();
        $row['journals'] = Journal::where(fn ($w) => $w->where(fn ($x) => $x->where('source_type', 'PosOrder')->where('source_id', $o->id))
            ->orWhere(fn ($x) => $x->where('source_type', 'CustomerInvoice')->where('source_id', $o->invoice_id ?? '-'))
            ->orWhere(fn ($x) => $x->where('source_type', 'Payment')->whereIn('source_id', $receipts ?: ['-'])))
            ->orderBy('created_at')->orderBy('id')->get(['id', 'number', 'memo', 'status', 'source_type', 'total_debit'])->map->toArray()->all();
        $row['history'] = $o->source_system !== null;
        $row['refundable'] = ! $row['history'] && $o->kind === 'sale' && collect($row['lines'])->sum('refundableQty') > 0;

        return $row;
    }

    /**
     * @param  array{kind?:?string, session?:?string, register?:?string, customer?:?string, cashier?:?string, from?:?string, to?:?string, pending?:?string, source?:?string}  $f
     */
    public function list(Paging $page, array $f, AuthUser $viewer): array
    {
        $q = PosOrder::query()->with(['customer:id,code,name_ar,name_en', 'session:id,number', 'register:id,code,name_ar,name_en'])->withCount('lines');
        if (! empty($f['kind'])) {
            $q->where('kind', $f['kind']);
        }
        if (! empty($f['session'])) {
            $q->whereHas('session', fn ($s) => $s->where('id', $f['session'])->orWhere('number', $f['session']));
        }
        if (! empty($f['register'])) {
            $q->whereHas('register', fn ($r) => $r->where('id', $f['register'])->orWhere('code', $f['register']));
        }
        if (! empty($f['customer'])) {
            $q->whereHas('customer', fn ($c) => $c->where('id', $f['customer'])->orWhere('code', $f['customer']));
        }
        if (($f['cashier'] ?? null) === 'me') {
            $q->where('cashier_id', $viewer->id);
        }
        if (! empty($f['from'])) {
            $q->where('ordered_at', '>=', $f['from'].' 00:00:00');
        }
        if (! empty($f['to'])) {
            $q->where('ordered_at', '<=', $f['to'].' 23:59:59.999');
        }
        if (($f['pending'] ?? null) === '1') {
            $q->where('pending_qty', '>', 0);
        }
        if (($f['source'] ?? null) === 'odoo') {
            $q->where('source_system', 'odoo');
        } elseif (($f['source'] ?? null) === 'ops') {
            $q->whereNull('source_system');
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('invoice_number', 'like', $like)->orWhere('customer_name', 'like', $like)->orWhere('external_ref', $page->q)
                ->orWhereHas('lines', fn ($l) => $l->where('sku', 'like', $like)));
        }
        $column = match ($page->sort) {
            'number' => 'number', 'total' => 'total', default => 'ordered_at'
        };
        $q->orderBy($column, $page->order)->orderBy('id', $page->order);
        $cost = $viewer->can('inventory.view_cost');

        return $page->paginate($q, function (PosOrder $o) use ($cost) {
            $row = $o->toArray();
            unset($row['payloadHash'], $row['linesCount']);
            if (! $cost) {
                unset($row['cogsTotal']);
            }
            $row['_count'] = ['lines' => (int) $o->lines_count];
            $row['history'] = $o->source_system !== null;

            return $row;
        });
    }
}
