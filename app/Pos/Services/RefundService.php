<?php

namespace App\Pos\Services;

use App\Finance\Models\CustomerInvoice;
use App\Models\Customer;
use App\Pos\Models\PosOrder;
use App\Pos\Models\PosOrderLine;
use App\Pos\Models\PosOrderPayment;
use App\Pos\Models\PosPaymentMethod;
use App\Pos\Models\PosRegister;
use App\Pos\Models\PosSession;
use App\Pos\Support\Checkpoint;
use App\Pos\Support\Money;
use App\Pos\Support\Owned;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\NumberingService;
use App\Services\Inventory\InventoryService;
use App\Services\Sales\CreditService;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * A refund against a sale — one transaction, like the sale: the refund order, the goods back through the inventory
 * engine, the credit note and the money paid back. Never more than was sold: each sale line is locked and its
 * `refunded_qty` can only reach its `qty`. The amounts are the sale's own, in proportion; the refund that takes the
 * last unit of the sale takes exactly what is left of its net and VAT, so a fully refunded sale nets to the halala.
 * What a sale still owed to stock (a register that sells ahead of stock) is cancelled first — those units never
 * left the books — and only the rest comes back to a bin.
 */
class RefundService
{
    public function __construct(
        private readonly PosStock $stock,
        private readonly PosAccounting $books,
        private readonly OrdersService $orders,
        private readonly SessionsService $sessions,
        private readonly SaleService $sales,
        private readonly NumberingService $numbering,
        private readonly InventoryService $inventory,
        private readonly AuditService $audit,
        private readonly NotifyService $notify,
        private readonly CreditService $credit,
    ) {}

    /**
     * @param  array{session:string, clientKey:string, reason:string, lines:array<int, array{lineId:string, qty:int|string}>, payments:array}  $dto
     */
    public function refund(AuthUser $user, string $orderIdOrNumber, array $dto): array
    {
        $hash = sha1(json_encode([$orderIdOrNumber, (string) $dto['session'], array_map(fn ($l) => [(string) $l['lineId'], (int) $l['qty']], $dto['lines']),
            array_map(fn ($p) => [(string) $p['method'], Money::str(Money::cents($p['amount'] ?? 0))], $dto['payments'] ?? [])]));
        if ($replay = $this->replay($dto['clientKey'], $hash, $user)) {
            return $replay;
        }
        try {
            $id = DB::transaction(fn () => $this->write($user, $orderIdOrNumber, $dto, $hash));
        } catch (UniqueConstraintViolationException $e) {
            if ($replay = $this->replay($dto['clientKey'], $hash, $user)) {
                return $replay;
            }
            throw $e;
        }

        return $this->orders->get($id, $user) + ['replayed' => false];
    }

    private function write(AuthUser $user, string $orderIdOrNumber, array $dto, string $hash): string
    {
        $reason = trim((string) $dto['reason']);
        if (mb_strlen($reason) < 5) {
            throw AppError::validation('REASON_REQUIRED', 'اكتب سبب الاسترجاع (5 أحرف على الأقل)', 'A reason is required (at least 5 characters)');
        }
        $sale = PosOrder::where(fn ($w) => $w->where('id', $orderIdOrNumber)->orWhere('number', $orderIdOrNumber))->lockForUpdate()->first()
            ?? throw AppError::notFound('POS_ORDER_NOT_FOUND', 'الطلب غير موجود', 'Order not found');
        Owned::refuseHistory($sale, 'order');
        if ($sale->kind !== 'sale') {
            throw AppError::rule('POS_REFUND_OF_REFUND', 'لا يُسترجع مرتجع — الاسترجاع على طلب البيع نفسه', 'A refund cannot be refunded');
        }
        $saleRegister = PosRegister::findOrFail($sale->register_id);
        if ($saleRegister->refund_needs_manager && ! $user->can('pos.manage')) {
            throw AppError::forbidden('POS_REFUND_NEEDS_MANAGER', 'الاسترجاع في هذا الصندوق يحتاج مدير نقطة البيع', 'Refunds on this register need a point-of-sale manager');
        }
        $session = PosSession::whereKey($dto['session'])->lockForUpdate()->first() ?? throw AppError::notFound('POS_SESSION_NOT_FOUND', 'الجلسة غير موجودة', 'Session not found');
        SaleService::assertSells($user, $session);
        $register = PosRegister::with('methods.bankAccount')->findOrFail($session->register_id);
        $customer = Customer::findOrFail($sale->customer_id);
        $invoice = CustomerInvoice::whereKey($sale->invoice_id)->lockForUpdate()->first()
            ?? throw AppError::rule('POS_REFUND_NO_INVOICE', 'لا فاتورة لهذا الطلب — لا يُسترجع', 'This order has no invoice to credit');
        if ($invoice->status === 'cancelled') {
            throw AppError::rule('POS_REFUND_INVOICE_CANCELLED', "فاتورة الطلب {$invoice->number} ملغاة في المالية — لا يُسترجع عليها", "Invoice {$invoice->number} is cancelled");
        }

        // ── what comes back, never more than was sold
        $sold = PosOrderLine::where('order_id', $sale->id)->orderBy('line_no')->lockForUpdate()->get()->keyBy('id');
        $asked = [];
        foreach (array_values($dto['lines']) as $i => $l) {
            $line = $sold[(string) $l['lineId']] ?? throw AppError::rule('POS_REFUND_LINE', 'السطر '.($i + 1).' ليس من هذا الطلب', 'Line '.($i + 1).' is not a line of this order');
            $qty = $l['qty'];
            if (! is_int($qty) && ! (is_string($qty) && ctype_digit($qty))) {
                throw AppError::validation('POS_QTY', 'كمية الاسترجاع عدد صحيح', 'Refund quantity must be a whole number');
            }
            $asked[$line->id] = ($asked[$line->id] ?? 0) + (int) $qty;
        }
        $asked = array_filter($asked, fn ($q) => $q > 0);
        if (! $asked) {
            throw AppError::validation('POS_EMPTY', 'حدد كمية سطر واحد على الأقل', 'Choose a quantity on at least one line');
        }
        $priorNet = $this->priorNet($sale->id);
        $lines = [];
        $subtotal = $gross = 0;
        foreach ($asked as $lineId => $qty) {
            $line = $sold[$lineId];
            $left = (int) $line->qty - (int) $line->refunded_qty;
            if ($qty > $left) {
                throw AppError::rule('POS_REFUND_EXCEEDS', "لا يُسترجع من {$line->sku} أكثر مما بقي من المبيع ({$left})", "Cannot refund more of {$line->sku} than is left of the sale ({$left})",
                    ['lineId' => $lineId, 'sku' => $line->sku, 'left' => $left, 'asked' => $qty]);
            }
            // in proportion; the last units take what is left of the line's net to the halala
            $lineNet = Money::cents($line->line_total);
            $net = $qty === $left ? $lineNet - ($priorNet[$lineId] ?? 0) : Money::mulDiv($lineNet, $qty, (int) $line->qty);
            $subtotal += $net;
            $gross += $qty * Money::cents($line->unit_price);
            $lines[] = [$line, $qty, $net];
        }
        $everything = $sold->every(fn (PosOrderLine $l) => (int) $l->qty - (int) $l->refunded_qty === ($asked[$l->id] ?? 0));
        $vatBp = Money::bp($sale->vat_pct);
        $priorVat = (int) round(PosOrder::where('refund_of_id', $sale->id)->get(['vat_amount'])->sum(fn ($o) => Money::cents($o->vat_amount)));
        $vat = $everything ? Money::cents($sale->vat_amount) - $priorVat : Money::vat($subtotal, $vatBp);
        $total = $subtotal + $vat;
        [$payments, $methods] = $this->money($register, $sale, $invoice, $session, $dto['payments'], $total);

        $refund = PosOrder::create([
            'number' => $this->numbering->next('POSR'), 'kind' => 'refund', 'refund_of_id' => $sale->id, 'client_key' => $dto['clientKey'], 'payload_hash' => $hash,
            'session_id' => $session->id, 'register_id' => $register->id, 'warehouse_id' => $sale->warehouse_id, 'cashier_id' => $user->id, 'cashier_name' => $user->nameAr,
            'customer_id' => $customer->id, 'customer_name' => $customer->name_ar, 'ordered_at' => now(), 'status' => 'done', 'reason' => mb_substr($reason, 0, 500),
            'subtotal' => Money::str($subtotal), 'discount_total' => Money::str($gross - $subtotal), 'vat_pct' => $sale->vat_pct, 'vat_amount' => Money::str($vat), 'total' => Money::str($total),
            'tendered' => Money::str($total), 'change_due' => '0.00', 'on_account' => Money::str(array_sum(array_map(fn ($p) => $p['kind'] === 'account' ? Money::cents($p['amount']) : 0, $payments))),
            'cogs_total' => '0.00', 'refunded_total' => '0.00', 'pending_qty' => 0, 'invoice_id' => null, 'created_by' => $user->username,
        ]);
        $refundLines = collect();
        foreach ($lines as $i => [$line, $qty, $net]) {
            $refundLines->push(PosOrderLine::create([
                'order_id' => $refund->id, 'line_no' => $i + 1, 'refund_of_line_id' => $line->id, 'product_id' => $line->product_id, 'sku' => $line->sku, 'name' => $line->name, 'name_en' => $line->name_en,
                'qty' => $qty, 'unit_price' => $line->unit_price, 'discount_pct' => $line->discount_pct, 'line_total' => Money::str($net), 'price_source' => 'refund',
                'issued_qty' => 0, 'pending_qty' => 0, 'refunded_qty' => 0, 'unit_cost' => 0, 'cogs' => 0,
            ]));
            $line->update(['refunded_qty' => (int) $line->refunded_qty + $qty]);
        }
        $rows = collect($payments)->map(fn ($p, $i) => PosOrderPayment::create(['order_id' => $refund->id, 'line_no' => $i + 1] + $p));
        $sale->update(['refunded_total' => Money::str(Money::cents($sale->refunded_total) + $total)]);
        Checkpoint::pass('refund.order');

        // ── the goods: what was still owed is cancelled, the rest comes back to a bin
        $tx = $this->inventory->newTxId();
        $cost = 0;
        $cancelledPending = 0;
        foreach ($lines as $i => [$line, $qty]) {
            $line->refresh();
            $cancel = min($qty, (int) $line->pending_qty);
            if ($cancel > 0) {
                $line->update(['pending_qty' => $line->pending_qty - $cancel]);
                $cancelledPending += $cancel;
            }
            $back = $qty - $cancel;
            $lineCost = $back > 0 ? $this->stock->giveBack($user, $tx, $refund, $refundLines[$i], $line, $back, $saleRegister->refund_restock ?: 'returns') : 0;
            $refundLines[$i]->update(['issued_qty' => $back, 'cogs' => Money::str($lineCost), 'unit_cost' => $back > 0 ? round($lineCost / 100 / $back, 4) : 0]);
            $cost += $lineCost;
        }
        if ($cancelledPending > 0) {
            $sale->update(['pending_qty' => max(0, (int) $sale->pending_qty - $cancelledPending)]);
        }
        $refund->update(['cogs_total' => Money::str($cost), 'stock_tx' => $tx]);
        Checkpoint::pass('refund.stock');

        // ── the books
        $cn = $this->books->creditNote($user, $refund, $refundLines->each->refresh(), $invoice, $customer);
        $refund->update(['invoice_id' => $cn->id, 'invoice_number' => $cn->number]);
        Checkpoint::pass('refund.credit');
        $payout = $this->books->payout($user, $refund, $invoice, $customer, $rows, $methods);
        $refund->update(['refund_journal_id' => $payout]);
        $this->credit->sync($customer->id);
        Checkpoint::pass('refund.payout');

        $this->audit->log($user, ['action' => 'POS.REFUND', 'entityType' => 'PosOrder', 'entityId' => $refund->id, 'entityNumber' => $refund->number, 'transactionId' => $tx,
            'newValue' => ['sale' => $sale->number, 'reason' => $reason, 'total' => $refund->total, 'vat' => $refund->vat_amount, 'creditNote' => $cn->number,
                'lines' => array_map(fn ($x) => ['sku' => $x[0]->sku, 'qty' => $x[1]], $lines), 'pendingCancelled' => $cancelledPending, 'restock' => $saleRegister->refund_restock]]);
        $this->notify->activity($user, 'PosOrder', $refund->id, $refund->number, "استرجاع {$refund->number} على {$sale->number} بمبلغ ".Money::str($total).' ر.س — '.$reason,
            "Refund {$refund->number} on {$sale->number}: ".Money::str($total).' SAR', ['finance']);
        Checkpoint::pass('refund.commit');

        return $refund->id;
    }

    /** Net already refunded per sale line, in halalas. @return array<string, int> */
    private function priorNet(string $saleId): array
    {
        $out = [];
        $refunds = PosOrder::where('refund_of_id', $saleId)->pluck('id');
        foreach (PosOrderLine::whereIn('order_id', $refunds)->get(['refund_of_line_id', 'line_total']) as $l) {
            $out[$l->refund_of_line_id] = ($out[$l->refund_of_line_id] ?? 0) + Money::cents($l->line_total);
        }

        return $out;
    }

    /**
     * The money of the refund: exactly its total, through methods of the refunding register. Cash cannot exceed what
     * the drawer holds; "to account" cannot exceed what the customer still owes on the sale's invoice.
     *
     * @return array{0: array<int, array>, 1: array<string, PosPaymentMethod>}
     */
    private function money(PosRegister $register, PosOrder $sale, CustomerInvoice $invoice, PosSession $session, array $payments, int $total): array
    {
        $rows = [];
        $methods = [];
        $sum = $cash = $account = 0;
        foreach (array_values($payments) as $i => $p) {
            $m = $register->methods->firstWhere('id', (string) $p['method']) ?? $register->methods->firstWhere('code', (string) $p['method'])
                ?? throw AppError::rule('POS_METHOD_NOT_ON_REGISTER', 'طريقة الدفع غير مربوطة بهذا الصندوق', 'This payment method is not available on this register', ['payment' => $i + 1]);
            $this->sales->assertMethodUsable($m, $i + 1);
            $amount = Money::cents($p['amount']);
            if ($amount <= 0) {
                throw AppError::validation('POS_AMOUNT', 'المبلغ يجب أن يكون أكبر من صفر', 'Amount must be positive', ['payment' => $i + 1]);
            }
            $reference = trim((string) ($p['reference'] ?? ''));
            if ($m->requires_reference && $reference === '') {
                throw AppError::validation('POS_REFERENCE_REQUIRED', "مرجع الرد مطلوب لـ {$m->name_ar}", "A reference is required for {$m->name_en}", ['payment' => $i + 1]);
            }
            $sum += $amount;
            $cash += $m->kind === 'cash' ? $amount : 0;
            $account += $m->kind === 'account' ? $amount : 0;
            $methods[$m->id] = $m;
            $rows[] = ['method_id' => $m->id, 'method_code' => $m->code, 'kind' => $m->kind, 'amount' => Money::str($amount), 'applied' => Money::str($amount), 'reference' => $reference === '' ? null : mb_substr($reference, 0, 80)];
        }
        if ($sum !== $total) {
            throw AppError::rule('POS_REFUND_PAYMENTS', 'مجموع المبالغ المردودة ('.Money::str($sum).') يجب أن يساوي مبلغ الاسترجاع ('.Money::str($total).')',
                'Refunded money '.Money::str($sum).' must equal the refund total '.Money::str($total), ['total' => Money::str($total), 'paid' => Money::str($sum)]);
        }
        if ($account > 0) {
            $open = Money::cents($invoice->total) - Money::cents($invoice->paid_amount);
            if ($account > $open) {
                throw AppError::rule('POS_REFUND_ACCOUNT', 'المردود إلى الحساب أكبر مما بقي على العميل من هذا البيع ('.Money::str(max(0, $open)).')', 'Refund to account exceeds what is still owed on this sale');
            }
        }
        if ($cash > 0) {
            $inDrawer = $this->sessions->expectedCash($session);
            if ($cash > $inDrawer) {
                throw AppError::rule('POS_CASH_SHORT', 'النقد في الدرج ('.Money::str($inDrawer).') لا يكفي لرد '.Money::str($cash), 'The drawer holds '.Money::str($inDrawer).', not enough to pay back '.Money::str($cash));
            }
        }

        return [$rows, $methods];
    }

    private function replay(string $key, string $hash, AuthUser $user): ?array
    {
        $existing = PosOrder::where('client_key', $key)->first(['id', 'payload_hash']);
        if (! $existing) {
            return null;
        }
        if ($existing->payload_hash !== $hash) {
            throw AppError::conflict('POS_KEY_REUSED', 'مفتاح العملية استُخدم لعملية أخرى — ابدأ من جديد', 'This client key was used for a different operation');
        }

        return $this->orders->get($existing->id, $user) + ['replayed' => true];
    }
}
