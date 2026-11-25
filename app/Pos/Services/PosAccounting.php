<?php

namespace App\Pos\Services;

use App\Finance\Models\CustomerInvoice;
use App\Finance\Models\CustomerInvoiceLine;
use App\Finance\Services\AccountMap;
use App\Finance\Services\CustomerInvoiceService;
use App\Finance\Services\LedgerService;
use App\Finance\Services\PaymentsService;
use App\Models\Customer;
use App\Pos\Models\PosOrder;
use App\Pos\Models\PosOrderLine;
use App\Pos\Models\PosOrderPayment;
use App\Pos\Models\PosPaymentMethod;
use App\Pos\Support\Money;
use App\Services\Core\NumberingService;
use App\Support\AuthUser;
use Illuminate\Support\Collection;

/**
 * The books of the counter: ONE TAX INVOICE + ITS RECEIPTS PER ORDER (chosen over one summary journal per session:
 * each sale is then a document in receivables, VAT and the customer's statement the moment it happens, its receipts
 * are ordinary finance receipts, and the sale is all-or-none with its accounting).
 *
 *  sale    invoice CI-… to the order's customer     Dr 1120 receivables (customer) · Cr 4100 sales per line · Cr 2130 VAT output
 *          receipts RCT-… (PaymentsService::receive) Dr the method's bank / cash account · Cr 1120 — allocated to the invoice
 *          cost of sales                             Dr 5100 cost of goods sold · Cr 1140 goods in transit (the value the
 *                                                    `load` movements moved out of inventory)
 *          what is paid "on account" leaves the invoice open for that much: it is the customer's debt.
 *  refund  credit note CN-… on the invoice           Dr 4200 sales returns · Dr 2130 VAT output · Cr 1120 (customer)
 *          money paid back                           Dr 1120 (customer) · Cr the method's account; a part refunded "to
 *                                                    account" settles that much of the open invoice instead
 *          goods back                                the `ret` movements (Dr 1130 · Cr 5100) — posted by the cost engine
 *
 * Journals go through LedgerService (balanced, open period only — a closed month refuses the whole sale with
 * PERIOD_CLOSED —, idempotent per event key). The invoice and credit note documents are written here because
 * CustomerInvoiceService only issues them from a delivery or a return order (see the report's "wiring").
 */
class PosAccounting
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly PaymentsService $payments,
        private readonly CustomerInvoiceService $invoices,
        private readonly NumberingService $numbering,
        private readonly AccountMap $map,
    ) {}

    /**
     * The tax invoice of a sale and its journal.
     *
     * @param  Collection<int, PosOrderLine>  $lines
     */
    public function invoice(?AuthUser $user, PosOrder $order, Collection $lines, Customer $customer, ?int $termsDays): CustomerInvoice
    {
        $date = now()->startOfDay();
        $inv = CustomerInvoice::create([
            'number' => $this->numbering->next('CI'), 'kind' => 'invoice', 'customer_id' => $customer->id, 'invoice_date' => $date->toDateString(),
            'due_date' => $date->copy()->addDays($termsDays ?? 0)->toDateString(), 'status' => 'issued',
            'subtotal' => $order->subtotal, 'discount' => $order->discount_total, 'vat_pct' => (float) $order->vat_pct, 'vat_amount' => $order->vat_amount, 'total' => $order->total,
            'paid_amount' => 0, 'cogs_total' => 0, 'payment_terms' => 'نقطة البيع · POS', 'notes' => "نقطة البيع {$order->number} · POS sale", 'created_by' => $user?->username ?? 'system',
        ]);
        foreach ($lines as $l) {
            CustomerInvoiceLine::create(['invoice_id' => $inv->id, 'line_no' => $l->line_no, 'product_id' => $l->product_id, 'qty' => (int) $l->qty, 'price' => $l->unit_price,
                'disc_pct' => (float) $l->discount_pct, 'line_total' => $l->line_total, 'unit_cost' => 0, 'cogs' => 0]);
        }
        $dims = ['referenceType' => 'CustomerInvoice', 'referenceId' => $inv->id, 'warehouseId' => $order->warehouse_id];
        $salesCc = $this->map->costCenter('SALES')?->id;
        $journal = $this->ledger->post($user, [
            'eventKey' => "pos:ci:{$order->id}", 'source' => ['type' => 'CustomerInvoice', 'id' => $inv->id, 'number' => $inv->number],
            'memo' => "فاتورة نقطة البيع {$inv->number} — {$order->number} — {$customer->name_ar}",
            'lines' => array_merge(
                [['role' => 'ar', 'debit' => $order->total, 'description' => "{$inv->number} · {$customer->name_ar}", 'partnerType' => 'customer', 'partnerId' => $customer->id] + $dims],
                $lines->map(fn (PosOrderLine $l) => ['role' => 'revenue', 'credit' => $l->line_total, 'description' => "{$l->sku} × ".(int) $l->qty, 'productId' => $l->product_id, 'costCenterId' => $salesCc] + $dims)->all(),
                Money::cents($order->vat_amount) > 0 ? [['role' => 'vat_output', 'credit' => $order->vat_amount, 'description' => "ضريبة {$order->vat_pct}% · {$inv->number}"] + $dims] : [],
            ),
        ]);
        $inv->update(['journal_id' => $journal->id]);

        return $inv;
    }

    /**
     * Cost of sales of what a sale (or a later issue of owed stock) took out: Dr cost of goods sold per line,
     * Cr goods in transit — the value the `load` movements put there. Writes the cost on the order's and the invoice's
     * lines. `$costs`: line id → [qty issued now, cost in halalas].
     *
     * @param  array<string, array{0:int, 1:int}>  $costs
     */
    public function costOfSales(?AuthUser $user, PosOrder $order, Collection $lines, array $costs, string $eventKey): ?string
    {
        $total = array_sum(array_map(fn ($c) => $c[1], $costs));
        $salesCc = $this->map->costCenter('SALES')?->id;
        $dims = ['referenceType' => 'PosOrder', 'referenceId' => $order->id, 'warehouseId' => $order->warehouse_id];
        $journalId = null;
        if ($total > 0) {
            $journal = $this->ledger->post($user, [
                'eventKey' => $eventKey, 'source' => ['type' => 'PosOrder', 'id' => $order->id, 'number' => $order->number],
                'memo' => "تكلفة مبيعات نقطة البيع {$order->number}",
                'lines' => array_merge(
                    $lines->filter(fn (PosOrderLine $l) => ($costs[$l->id][1] ?? 0) > 0)
                        ->map(fn (PosOrderLine $l) => ['role' => 'cogs', 'debit' => Money::str($costs[$l->id][1]), 'description' => "{$l->sku} × {$costs[$l->id][0]}", 'productId' => $l->product_id, 'costCenterId' => $salesCc] + $dims)->values()->all(),
                    [['role' => 'goods_in_transit', 'credit' => Money::str($total), 'description' => "خروج بضاعة {$order->number} من نقطة البيع إلى العميل"] + $dims],
                ),
            ]);
            $journalId = $journal->id;
        }
        // the cost of each line = what its movements were valued at, so far
        foreach ($lines as $l) {
            if (! isset($costs[$l->id])) {
                continue;
            }
            $cogs = Money::cents($l->cogs) + $costs[$l->id][1];
            $issued = max(1, (int) $l->issued_qty);
            $l->update(['cogs' => Money::str($cogs), 'unit_cost' => round($cogs / 100 / $issued, 4)]);
            if ($order->invoice_id) {
                CustomerInvoiceLine::where('invoice_id', $order->invoice_id)->where('line_no', $l->line_no)->update(['cogs' => Money::str($cogs), 'unit_cost' => round($cogs / 100 / $issued, 4)]);
            }
        }
        $orderCogs = Money::cents($order->cogs_total) + $total;
        $order->update(['cogs_total' => Money::str($orderCogs)]);
        if ($order->invoice_id) {
            CustomerInvoice::whereKey($order->invoice_id)->update(['cogs_total' => Money::str($orderCogs)] + ($journalId && ! $order->cogs_journal_id ? ['cogs_journal_id' => $journalId] : []));
        }
        if ($journalId && ! $order->cogs_journal_id) {
            $order->update(['cogs_journal_id' => $journalId]);
        }

        return $journalId;
    }

    /**
     * The finance receipts of a sale's payments, each allocated to the invoice (on-account payments get none: the
     * invoice stays open for them). One receipt per payment, through the finance receipts service.
     *
     * @param  Collection<int, PosOrderPayment>  $payments
     */
    public function receipts(AuthUser $user, PosOrder $order, CustomerInvoice $invoice, Customer $customer, Collection $payments, array $methods): void
    {
        foreach ($payments as $p) {
            /** @var PosPaymentMethod $m */
            $m = $methods[$p->method_id];
            if ($p->kind === 'account' || Money::cents($p->applied) <= 0) {
                continue;
            }
            $r = $this->payments->receive($user, [
                'customer' => $customer->id, 'amount' => $p->applied, 'method' => PosPaymentMethod::FINANCE_METHOD[$p->kind], 'bankAccount' => $m->bank_account_id,
                'reference' => $p->reference ?: $order->number, 'notes' => "نقطة البيع {$order->number}",
                'allocations' => [['invoice' => $invoice->id, 'amount' => $p->applied]],
            ]);
            $p->update(['fin_payment_id' => $r['id'], 'fin_payment_number' => $r['number']]);
        }
    }

    /**
     * The credit note of a refund and its journal (Dr sales returns, Dr VAT output, Cr receivables).
     *
     * @param  Collection<int, PosOrderLine>  $lines  the refund's lines
     */
    public function creditNote(?AuthUser $user, PosOrder $refund, Collection $lines, CustomerInvoice $invoice, Customer $customer): CustomerInvoice
    {
        $cn = CustomerInvoice::create([
            'number' => $this->numbering->next('CN'), 'kind' => 'credit_note', 'customer_id' => $customer->id, 'credit_of_id' => $invoice->id,
            'invoice_date' => now()->toDateString(), 'due_date' => now()->toDateString(), 'status' => 'paid',
            'subtotal' => $refund->subtotal, 'discount' => $refund->discount_total, 'vat_pct' => (float) $refund->vat_pct, 'vat_amount' => $refund->vat_amount, 'total' => $refund->total,
            'paid_amount' => $refund->total, 'cogs_total' => $refund->cogs_total, 'payment_terms' => 'نقطة البيع · POS',
            'notes' => "مرتجع نقطة البيع {$refund->number} على الفاتورة {$invoice->number}", 'created_by' => $user?->username ?? 'system',
        ]);
        foreach ($lines as $l) {
            CustomerInvoiceLine::create(['invoice_id' => $cn->id, 'line_no' => $l->line_no, 'product_id' => $l->product_id, 'qty' => (int) $l->qty, 'price' => $l->unit_price,
                'disc_pct' => (float) $l->discount_pct, 'line_total' => $l->line_total, 'unit_cost' => $l->unit_cost, 'cogs' => $l->cogs]);
        }
        $dims = ['referenceType' => 'CustomerInvoice', 'referenceId' => $cn->id, 'warehouseId' => $refund->warehouse_id];
        $journal = $this->ledger->post($user, [
            'eventKey' => "pos:cn:{$refund->id}", 'source' => ['type' => 'CustomerInvoice', 'id' => $cn->id, 'number' => $cn->number],
            'memo' => "إشعار دائن {$cn->number} — مرتجع نقطة البيع {$refund->number} — {$customer->name_ar}",
            'lines' => array_merge(
                [['role' => 'sales_returns', 'debit' => $refund->subtotal, 'description' => "مردودات {$invoice->number}"] + $dims],
                Money::cents($refund->vat_amount) > 0 ? [['role' => 'vat_output', 'debit' => $refund->vat_amount, 'description' => "ضريبة مردودات {$cn->number}"] + $dims] : [],
                [['role' => 'ar', 'credit' => $refund->total, 'description' => "{$cn->number} · {$customer->name_ar}", 'partnerType' => 'customer', 'partnerId' => $customer->id] + $dims],
            ),
        ]);
        $cn->update(['journal_id' => $journal->id]);

        return $cn;
    }

    /**
     * The money of a refund: the part paid back leaves the methods' accounts (Dr receivables, Cr bank / cash); the part
     * refunded to the customer's account settles that much of the sale's open invoice.
     *
     * @param  Collection<int, PosOrderPayment>  $payments
     */
    public function payout(?AuthUser $user, PosOrder $refund, CustomerInvoice $invoice, Customer $customer, Collection $payments, array $methods): ?string
    {
        $dims = ['referenceType' => 'PosOrder', 'referenceId' => $refund->id];
        $out = [];
        $toAccount = 0;
        foreach ($payments as $p) {
            if ($p->kind === 'account') {
                $toAccount += Money::cents($p->amount);

                continue;
            }
            $out[] = ['account' => $methods[$p->method_id]->bankAccount->account_id, 'credit' => $p->amount, 'description' => "{$refund->number} · {$methods[$p->method_id]->name_ar}".($p->reference ? " · {$p->reference}" : '')] + $dims;
        }
        if ($toAccount > 0) {
            $this->invoices->applyToInvoice($invoice->id, Money::float($toAccount));
        }
        $paid = array_sum(array_map(fn ($l) => Money::cents($l['credit']), $out));
        if ($paid <= 0) {
            return null;
        }
        $journal = $this->ledger->post($user, [
            'eventKey' => "pos:payout:{$refund->id}", 'source' => ['type' => 'PosOrder', 'id' => $refund->id, 'number' => $refund->number],
            'memo' => "رد مبلغ مرتجع نقطة البيع {$refund->number} — {$customer->name_ar}",
            'lines' => array_merge([['role' => 'ar', 'debit' => Money::str($paid), 'description' => "{$refund->number} · {$customer->name_ar}", 'partnerType' => 'customer', 'partnerId' => $customer->id] + $dims], $out),
        ]);

        return $journal->id;
    }
}
