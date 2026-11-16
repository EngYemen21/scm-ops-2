<?php

namespace App\Services\Sales;

use App\Finance\Models\CustomerInvoice;
use App\Models\Customer;
use App\Services\Core\SettingsService;
use App\Support\AppError;
use Illuminate\Support\Facades\DB;

/**
 * Customer credit, derived from its sources instead of a counter that only ever grew:
 *
 *   exposure = receivables (issued invoices not yet paid, from finance — less what the customer paid on account)
 *            + open orders (accepted, not yet delivered — delivery turns them into an invoice)
 *
 * `customers.balance` is that figure, rewritten by sync() whenever one of its sources changes (order created /
 * cancelled / short-shipped, invoice issued / cancelled / credited, receipt recorded / voided).
 *
 * What another system sent is not counted, neither as an open order nor — once delivered — as a receivable: an order
 * with a `source_system` and the invoice of such an order. Credit and collection of those orders are that system's
 * (the Sales platform collects through its wallet; OPS records no receipt for them), so its customer keeps a balance
 * of 0 here. An invoice without an order, or of an OPS order, counts.
 *
 * A receipt on account (recorded, not void, not — or only partly — allocated to an invoice) is the customer's money
 * already: its unallocated remainder is taken off the receivables, never below zero (it does not offset open orders).
 */
class CreditService
{
    /** Order statuses whose value has not yet become an invoice (or been dropped). */
    public const OPEN_ORDER_STATES = ['confirmed', 'reserved', 'allocated', 'preparing', 'picking', 'picked', 'packed', 'readydisp', 'loaded', 'outfordel'];

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * `receivables` is net: open invoices (not those of an order another system sent) less the unallocated remainder
     * of the customer's live receipts, floored at 0.
     *
     * @return array{limit:float, receivables:float, openOrders:float, exposure:float, available:?float, limited:bool}
     */
    public function exposure(string $customerId): array
    {
        $limit = (float) Customer::whereKey($customerId)->value('credit_limit');
        $invoiced = (float) CustomerInvoice::where('customer_id', $customerId)->where('kind', 'invoice')->whereIn('status', CustomerInvoice::OPEN)
            ->whereDoesntHave('order', fn ($o) => $o->whereNotNull('source_system'))
            ->selectRaw('coalesce(sum(total - paid_amount), 0) as open')->value('open');
        // a voided receipt is `void` and its allocations are released, so only live money is counted
        $onAccount = (float) DB::table('fin_payments')->where('direction', 'in')->where('partner_type', 'customer')->where('partner_id', $customerId)->where('status', 'posted')
            ->selectRaw('coalesce(sum(amount - allocated_amount), 0) as free')->value('free');
        $receivables = max(0, $invoiced) - max(0, $onAccount);
        $vat = (float) $this->settings->get('sales.vatPct');
        $net = (float) DB::table('sales_order_lines as l')->join('sales_orders as s', 's.id', '=', 'l.so_id')
            ->where('s.customer_id', $customerId)->whereNull('s.source_system')->whereIn('s.status', self::OPEN_ORDER_STATES)
            ->selectRaw('coalesce(sum(l.qty * l.price * (1 - coalesce(l.disc_pct, 0) / 100.0)), 0) as net')->value('net');
        $openOrders = round($net * (1 + $vat / 100), 2);
        $exposure = round(max(0, $receivables) + $openOrders, 2);
        $limited = $limit > 0 || ! (bool) $this->settings->get('sales.zeroCreditLimitUnlimited');

        return [
            'limit' => $limit, 'receivables' => round(max(0, $receivables), 2), 'openOrders' => $openOrders, 'exposure' => $exposure,
            'available' => $limit > 0 ? round($limit - $exposure, 2) : null, 'limited' => $limited,
        ];
    }

    /** Rewrites customers.balance from its sources on a locked row; returns the exposure. Needs no open transaction. */
    public function sync(?string $customerId): float
    {
        if (! $customerId) {
            return 0.0;
        }

        return DB::transaction(function () use ($customerId) {
            $c = Customer::whereKey($customerId)->lockForUpdate()->first();
            if (! $c) {
                return 0.0;
            }
            $x = $this->exposure($customerId);
            if (round((float) $c->balance, 2) !== $x['exposure']) {
                $c->update(['balance' => $x['exposure']]);
            }

            return $x['exposure'];
        });
    }

    /**
     * Rewrites every customer balance from its sources (release step of the 2026-11 hardening, nightly safety net).
     *
     * @param  ?callable(string $code, float $before, float $after): void  $onChange
     * @return array{checked:int, changed:int}
     */
    public function syncAll(?callable $onChange = null): array
    {
        $checked = $changed = 0;
        foreach (Customer::orderBy('code')->get(['id', 'code', 'balance']) as $c) {
            $checked++;
            $before = round((float) $c->balance, 2);
            $after = $this->sync($c->id);
            if ($before !== $after) {
                $changed++;
                if ($onChange) {
                    $onChange($c->code, $before, $after);
                }
            }
        }

        return ['checked' => $checked, 'changed' => $changed];
    }

    /**
     * The credit gate of a new order (called inside the order's transaction, customer row locked by the caller or here).
     * - limit > 0: exposure + this order must not exceed it;
     * - limit = 0: unlimited, unless `sales.zeroCreditLimitUnlimited` is off — then a zero limit means "no credit" and
     *   the order is refused while the customer still owes anything.
     */
    public function assertCanOrder(Customer $c, float $orderTotal): void
    {
        if (! (bool) $this->settings->get('sales.enforceCreditLimit')) {
            return;
        }
        $x = $this->exposure($c->id);
        $fmt = fn (float $n) => number_format($n, 2, '.', ',');
        if ($x['limit'] > 0) {
            if ($x['exposure'] + $orderTotal > $x['limit'] + 0.005) {
                throw AppError::rule('CREDIT_LIMIT',
                    "مرفوض: يتجاوز الحد الائتماني للعميل ({$fmt($x['limit'])}) — المستحق {$fmt($x['receivables'])} + طلبات مفتوحة {$fmt($x['openOrders'])} + هذا الطلب {$fmt($orderTotal)}",
                    'Customer credit limit exceeded', ['limit' => $x['limit'], 'balance' => $x['exposure'], 'receivables' => $x['receivables'], 'openOrders' => $x['openOrders'], 'order' => $orderTotal]);
            }

            return;
        }
        if ($x['limited'] && $x['receivables'] > 0.005) {
            throw AppError::rule('CREDIT_NONE',
                "مرفوض: العميل بلا حد ائتماني وعليه مبلغ غير مسدد ({$fmt($x['receivables'])}) — يُسدَّد أولًا أو يُمنح حدًا ائتمانيًا",
                'Customer has no credit limit and an unpaid balance', ['receivables' => $x['receivables'], 'order' => $orderTotal]);
        }
    }
}
