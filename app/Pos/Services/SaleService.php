<?php

namespace App\Pos\Services;

use App\Models\Customer;
use App\Pos\Models\PosHeldSale;
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
use App\Services\Core\NumberingService;
use App\Services\Inventory\InventoryService;
use App\Services\Sales\CreditService;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A counter sale — ONE transaction: the order, the goods out of the warehouse, the tax invoice, the cost of sales and
 * the receipts are all written or none is (a failure at any step leaves nothing: no stock moved, no number taken, no
 * journal). Idempotent by the client's key: the selling screen makes one key per sale and sends it again on a retry
 * (a lost answer, a double tap, a reload) — the same sale comes back, never a second one; the same key with a
 * different cart is refused.
 */
class SaleService
{
    public function __construct(
        private readonly PosPricing $pricing,
        private readonly PosStock $stock,
        private readonly PosAccounting $books,
        private readonly OrdersService $orders,
        private readonly NumberingService $numbering,
        private readonly InventoryService $inventory,
        private readonly AuditService $audit,
        private readonly CreditService $credit,
    ) {}

    // ───────────── quote: what the screen shows before payment ─────────────

    /** @param  array{session:string, customer?:?string, lines:array}  $dto */
    public function quote(AuthUser $user, array $dto): array
    {
        $session = $this->session($dto['session']);
        $register = PosRegister::findOrFail($session->register_id);
        $customer = $this->customer($register, $dto['customer'] ?? null);
        $priced = $this->pricing->price($register, $customer, $dto['lines']);
        $stock = [];
        foreach ($priced['lines'] as $l) {
            $stock[$l['productId']] = ($stock[$l['productId']] ?? 0) + $l['qty'];
        }
        $short = [];
        foreach ($stock as $productId => $qty) {
            $available = $this->inventory->availability($productId, $register->warehouse_id)['total'];
            if ($qty > $available) {
                $short[] = ['productId' => $productId, 'requested' => $qty, 'available' => $available];
            }
        }

        return PosPricing::shape($priced) + ['customer' => ['id' => $customer->id, 'code' => $customer->code, 'nameAr' => $customer->name_ar, 'nameEn' => $customer->name_en],
            'stockShort' => $short, 'allowNegativeStock' => (bool) $register->allow_negative_stock];
    }

    // ───────────── the sale ─────────────

    /**
     * @param  array{session:string, clientKey:string, customer?:?string, lines:array, payments:array, expectedTotal?:?string, note?:?string, heldId?:?string}  $dto
     */
    public function sell(AuthUser $user, array $dto): array
    {
        $hash = self::fingerprint($dto);
        if ($replay = $this->replay($dto['clientKey'], $hash)) {
            return $replay;
        }
        try {
            $id = DB::transaction(fn () => $this->write($user, $dto, $hash));
        } catch (UniqueConstraintViolationException $e) {
            // a concurrent request with the same key won the race: answer with its sale
            if ($replay = $this->replay($dto['clientKey'], $hash)) {
                return $replay;
            }
            throw $e;
        }

        return $this->orders->get($id, $user) + ['replayed' => false];
    }

    private function write(AuthUser $user, array $dto, string $hash): string
    {
        $session = PosSession::whereKey($dto['session'])->lockForUpdate()->first() ?? throw AppError::notFound('POS_SESSION_NOT_FOUND', 'الجلسة غير موجودة', 'Session not found');
        self::assertSells($user, $session);
        $register = PosRegister::with('methods.bankAccount')->findOrFail($session->register_id);
        $customer = $this->customer($register, $dto['customer'] ?? null);
        $priced = $this->pricing->price($register, $customer, $dto['lines']);
        if (isset($dto['expectedTotal']) && $dto['expectedTotal'] !== null && $dto['expectedTotal'] !== '' && Money::cents($dto['expectedTotal']) !== $priced['total']) {
            throw AppError::conflict('POS_TOTAL_CHANGED', 'تغيّر إجمالي البيع ('.Money::str($priced['total']).') عما عُرض على الشاشة — راجع السلة ثم ادفع', 'The total changed to '.Money::str($priced['total']).' — review the cart and pay again',
                ['total' => Money::str($priced['total'])]);
        }
        if ($priced['total'] <= 0) {
            throw AppError::rule('POS_ZERO_TOTAL', 'لا يُسجَّل بيع بإجمالي صفر', 'A sale with a zero total is not recorded');
        }
        [$payments, $methods, $change, $onAccount, $tendered] = $this->settle($register, $customer, $dto['payments'], $priced['total']);

        // ── the order (the unique client key is taken first: a concurrent twin stops here)
        $order = PosOrder::create([
            'number' => $this->numbering->next('POS'), 'kind' => 'sale', 'client_key' => $dto['clientKey'], 'payload_hash' => $hash,
            'session_id' => $session->id, 'register_id' => $register->id, 'warehouse_id' => $register->warehouse_id, 'cashier_id' => $user->id, 'cashier_name' => $user->nameAr,
            'customer_id' => $customer->id, 'customer_name' => $customer->name_ar, 'ordered_at' => now(), 'status' => 'done',
            'subtotal' => Money::str($priced['subtotal']), 'discount_total' => Money::str($priced['discount']), 'vat_pct' => Money::str($priced['vatBp']), 'vat_amount' => Money::str($priced['vat']),
            'total' => Money::str($priced['total']), 'tendered' => Money::str($tendered), 'change_due' => Money::str($change), 'on_account' => Money::str($onAccount),
            'cogs_total' => '0.00', 'refunded_total' => '0.00', 'pending_qty' => 0, 'note' => $dto['note'] ?? null, 'created_by' => $user->username,
        ]);
        $lines = collect($priced['lines'])->map(fn ($l) => PosOrderLine::create([
            'order_id' => $order->id, 'line_no' => $l['lineNo'], 'product_id' => $l['productId'], 'sku' => $l['sku'], 'name' => $l['nameAr'], 'name_en' => $l['nameEn'], 'qty' => $l['qty'],
            'unit_price' => Money::str($l['unitCents']), 'discount_pct' => Money::str($l['discountBp']), 'line_total' => Money::str($l['netCents']),
            'price_source' => $l['priceSource'], 'issued_qty' => 0, 'pending_qty' => 0, 'refunded_qty' => 0, 'unit_cost' => 0, 'cogs' => 0,
        ]));
        $rows = collect($payments)->map(fn ($p, $i) => PosOrderPayment::create(['order_id' => $order->id, 'line_no' => $i + 1] + $p));
        Checkpoint::pass('order');

        // ── the goods
        $wanted = [];
        foreach ($lines as $l) {
            $wanted[$l->product_id] = ($wanted[$l->product_id] ?? 0) + (int) $l->qty;
        }
        $available = $this->stock->lockAvailable($register->warehouse_id, $wanted);
        $this->stock->assertCovered($wanted, $available, (bool) $register->allow_negative_stock);
        $tx = $this->inventory->newTxId();
        $costs = [];
        $pending = 0;
        foreach ($lines as $l) {
            ['issued' => $issued, 'cost' => $cost] = $this->stock->issue($user, $tx, $order, $l, $register->warehouse_id, (int) $l->qty);
            $owed = (int) $l->qty - $issued;
            $l->update(['issued_qty' => $issued, 'pending_qty' => $owed]);
            $pending += $owed;
            $costs[$l->id] = [$issued, $cost];
        }
        $order->update(['pending_qty' => $pending, 'stock_tx' => $tx]);
        Checkpoint::pass('stock');

        // ── the books
        $invoice = $this->books->invoice($user, $order, $lines, $customer, $onAccount > 0 && $customer->payment_terms_days !== null ? (int) $customer->payment_terms_days : null);
        $order->update(['invoice_id' => $invoice->id, 'invoice_number' => $invoice->number]);
        Checkpoint::pass('invoice');
        $this->books->costOfSales($user, $order, $lines, $costs, "pos:cogs:{$order->id}:0");
        Checkpoint::pass('cogs');
        $this->books->receipts($user, $order, $invoice, $customer, $rows, $methods);
        if ($onAccount > 0) {
            $this->credit->sync($customer->id); // the part on account is now the customer's debt
        }
        Checkpoint::pass('payments');

        if (! empty($dto['heldId'])) {
            PosHeldSale::whereKey($dto['heldId'])->where('session_id', $session->id)->whereIn('status', ['held', 'recalled'])
                ->update(['status' => 'recalled', 'recalled_at' => now(), 'order_id' => $order->id, 'updated_at' => now()]);
        }
        $this->audit->log($user, ['action' => 'POS.SALE', 'entityType' => 'PosOrder', 'entityId' => $order->id, 'entityNumber' => $order->number, 'transactionId' => $tx,
            'newValue' => ['session' => $session->number, 'customer' => $customer->code, 'lines' => $lines->count(), 'total' => $order->total, 'vat' => $order->vat_amount,
                'payments' => $rows->map(fn ($p) => ['method' => $methods[$p->method_id]->code, 'amount' => $p->amount, 'applied' => $p->applied])->all(),
                'change' => $order->change_due, 'onAccount' => $order->on_account, 'pendingQty' => $pending, 'invoice' => $invoice->number]]);
        Checkpoint::pass('commit');

        return $order->id;
    }

    /**
     * Checks the payments against what is due and works out the change.
     * Cash may exceed what is due — the change comes out of the cash; card, transfer and account may not.
     *
     * @return array{0: array<int, array>, 1: array<string, PosPaymentMethod>, 2: int, 3: int, 4: int} rows to write, methods by id, change, on account, tendered
     */
    private function settle(PosRegister $register, Customer $customer, array $payments, int $total): array
    {
        if (! $payments) {
            throw AppError::validation('POS_UNDERPAID', 'لم يُسجَّل أي دفع', 'No payment was given');
        }
        $bound = $register->methods->keyBy('id');
        $rows = [];
        $methods = [];
        $cash = $other = $onAccount = 0;
        foreach (array_values($payments) as $i => $p) {
            /** @var ?PosPaymentMethod $m */
            $m = $bound[(string) $p['method']] ?? $register->methods->firstWhere('code', (string) $p['method']);
            if (! $m) {
                throw AppError::rule('POS_METHOD_NOT_ON_REGISTER', 'طريقة الدفع غير مربوطة بهذا الصندوق', 'This payment method is not available on this register', ['payment' => $i + 1]);
            }
            $this->assertMethodUsable($m, $i + 1);
            $amount = Money::cents($p['amount']);
            if ($amount <= 0) {
                throw AppError::validation('POS_AMOUNT', 'مبلغ الدفع يجب أن يكون أكبر من صفر', 'Payment amount must be positive', ['payment' => $i + 1]);
            }
            $reference = trim((string) ($p['reference'] ?? ''));
            if ($m->requires_reference && $reference === '') {
                throw AppError::validation('POS_REFERENCE_REQUIRED', "مرجع الدفع مطلوب لـ {$m->name_ar} (رقم العملية من جهاز الشبكة أو الحوالة)", "A reference is required for {$m->name_en}", ['payment' => $i + 1]);
            }
            if ($m->kind === 'account') {
                if ($customer->id === $register->default_customer_id) {
                    throw AppError::rule('POS_ACCOUNT_NEEDS_CUSTOMER', 'البيع على الحساب يحتاج عميلًا مسمّى — اختر العميل أولًا', 'A sale on account needs a named customer');
                }
                $onAccount += $amount;
            } elseif ($m->kind === 'cash') {
                $cash += $amount;
            } else {
                $other += $amount;
            }
            $methods[$m->id] = $m;
            $rows[] = ['method_id' => $m->id, 'method_code' => $m->code, 'kind' => $m->kind, 'amount' => $amount, 'reference' => $reference === '' ? null : mb_substr($reference, 0, 80)];
        }
        if ($other + $onAccount > $total) {
            throw AppError::rule('POS_OVERPAID', 'المدفوع بالشبكة / التحويل / الحساب أكبر من المستحق — الباقي يُعطى من النقد فقط', 'Card, transfer and account payments may not exceed what is due');
        }
        $tendered = $cash + $other + $onAccount;
        if ($tendered < $total) {
            throw AppError::rule('POS_UNDERPAID', 'المدفوع ('.Money::str($tendered).') أقل من المستحق ('.Money::str($total).')', 'Paid '.Money::str($tendered).' is less than the total '.Money::str($total),
                ['due' => Money::str($total), 'paid' => Money::str($tendered)]);
        }
        $change = $tendered - $total;
        if ($onAccount > 0) {
            $this->credit->assertCanOrder(Customer::whereKey($customer->id)->lockForUpdate()->first(), Money::float($onAccount));
        }
        // the change comes out of the cash, the last cash payment first
        $left = $change;
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            $applied = $rows[$i]['amount'];
            if ($rows[$i]['kind'] === 'cash' && $left > 0) {
                $take = min($left, $applied);
                $applied -= $take;
                $left -= $take;
            }
            $rows[$i]['applied'] = Money::str($applied);
            $rows[$i]['amount'] = Money::str($rows[$i]['amount']);
        }

        return [$rows, $methods, $change, $onAccount, $tendered];
    }

    /** A method is bound to an active finance account (except "on account", which moves no money). */
    public function assertMethodUsable(PosPaymentMethod $m, int $line = 1): void
    {
        if (! $m->active) {
            throw AppError::rule('POS_METHOD_INACTIVE', "طريقة الدفع {$m->name_ar} موقوفة", "Payment method {$m->name_en} is inactive", ['payment' => $line]);
        }
        if ($m->kind !== 'account' && (! $m->bankAccount || ! $m->bankAccount->active)) {
            throw AppError::rule('POS_METHOD_UNBOUND', "طريقة الدفع {$m->name_ar} غير مربوطة بحساب بنكي أو صندوق نشط في المالية", "Payment method {$m->name_en} is not bound to an active bank account or cash box", ['payment' => $line]);
        }
    }

    // ───────────── stock owed by a sale ─────────────

    /** Issues what a sale still owes to stock, as far as the shelf now covers it (pos.manage). */
    public function issuePending(AuthUser $user, string $idOrNumber): array
    {
        $id = DB::transaction(function () use ($user, $idOrNumber) {
            $order = PosOrder::where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->lockForUpdate()->first()
                ?? throw AppError::notFound('POS_ORDER_NOT_FOUND', 'الطلب غير موجود', 'Order not found');
            if ($order->kind !== 'sale' || $order->pending_qty <= 0) {
                throw AppError::rule('POS_NOTHING_PENDING', 'لا كمية مستحقة على المخزون في هذا الطلب', 'Nothing is owed to stock on this order');
            }
            $lines = PosOrderLine::where('order_id', $order->id)->where('pending_qty', '>', 0)->orderBy('line_no')->lockForUpdate()->get();
            $tx = $this->inventory->newTxId();
            $costs = [];
            $issuedNow = 0;
            foreach ($lines as $l) {
                ['issued' => $issued, 'cost' => $cost] = $this->stock->issue($user, $tx, $order, $l, $order->warehouse_id, (int) $l->pending_qty);
                if ($issued > 0) {
                    $l->update(['issued_qty' => $l->issued_qty + $issued, 'pending_qty' => $l->pending_qty - $issued]);
                    $costs[$l->id] = [$issued, $cost];
                    $issuedNow += $issued;
                }
            }
            if ($issuedNow === 0) {
                throw AppError::rule('POS_STOCK_SHORT', 'لا يزال المخزون لا يغطي الكمية المستحقة', 'Stock still does not cover what is owed');
            }
            $seq = (int) PosOrder::whereKey($order->id)->value('pending_issues') + 1;
            $this->books->costOfSales($user, $order, $lines, $costs, "pos:cogs:{$order->id}:{$seq}");
            $left = (int) PosOrderLine::where('order_id', $order->id)->sum('pending_qty');
            $order->update(['pending_qty' => $left, 'pending_issues' => $seq]);
            $this->audit->log($user, ['action' => 'POS.ISSUE_PENDING', 'entityType' => 'PosOrder', 'entityId' => $order->id, 'entityNumber' => $order->number, 'transactionId' => $tx,
                'newValue' => ['issued' => $issuedNow, 'stillPending' => $left]]);

            return $order->id;
        });

        return $this->orders->get($id, $user);
    }

    // ───────────── shared ─────────────

    /** The open session the user may sell in. */
    public static function assertSells(AuthUser $user, PosSession $session): void
    {
        Owned::refuseHistory($session, 'session'); // a session of Odoo's history is never sold in, whatever its state
        if ($session->state !== 'open') {
            throw AppError::rule('POS_SESSION_CLOSED', "الجلسة {$session->number} مغلقة — لا بيع فيها", "Session {$session->number} is closed");
        }
        if ($session->cashier_id !== $user->id && ! $user->can('pos.manage')) {
            throw AppError::forbidden('POS_NOT_YOUR_SESSION', 'هذه الجلسة لكاشير آخر — افتح جلستك على الصندوق', 'This session belongs to another cashier');
        }
    }

    private function session(string $id): PosSession
    {
        return PosSession::whereKey($id)->orWhere('number', $id)->first() ?? throw AppError::notFound('POS_SESSION_NOT_FOUND', 'الجلسة غير موجودة', 'Session not found');
    }

    /** The customer of the sale: the one chosen, else the register's walk-in customer. */
    public function customer(PosRegister $register, ?string $given): Customer
    {
        $id = $given ?: $register->default_customer_id;
        if (! $id) {
            throw AppError::rule('POS_REGISTER_NOT_READY', 'لا عميل افتراضي لهذا الصندوق — حدده في إعداد الصندوق', 'The register has no default customer');
        }
        $c = Customer::where('id', $id)->orWhere('code', $id)->first() ?? throw AppError::notFound('CUSTOMER_NOT_FOUND', 'العميل غير موجود', 'Customer not found');
        if (! $c->active) {
            throw AppError::rule('POS_CUSTOMER_INACTIVE', "العميل {$c->name_ar} موقوف", "Customer {$c->code} is inactive");
        }

        return $c;
    }

    /** What the sale says, in a stable order: the same cart gives the same fingerprint. */
    public static function fingerprint(array $dto): string
    {
        $lines = array_map(fn ($l) => [(string) $l['productId'], (int) $l['qty'], Money::str(Money::bp($l['discountPct'] ?? 0))], $dto['lines'] ?? []);
        $payments = array_map(fn ($p) => [(string) $p['method'], Money::str(Money::cents($p['amount'] ?? 0)), trim((string) ($p['reference'] ?? ''))], $dto['payments'] ?? []);

        return sha1(json_encode([(string) $dto['session'], (string) ($dto['customer'] ?? ''), $lines, $payments, (string) ($dto['refundOf'] ?? '')]));
    }

    /** The sale already recorded under this key, or null; the same key with another cart is refused. */
    private function replay(string $key, string $hash): ?array
    {
        $existing = PosOrder::where('client_key', $key)->first(['id', 'payload_hash', 'cashier_id']);
        if (! $existing) {
            return null;
        }
        if ($existing->payload_hash !== $hash) {
            throw AppError::conflict('POS_KEY_REUSED', 'مفتاح العملية استُخدم لبيع آخر — ابدأ بيعًا جديدًا', 'This client key was used for a different sale', ['order' => PosOrder::whereKey($existing->id)->value('number')]);
        }

        return $this->orders->get($existing->id, AuthUser::currentOrNull()) + ['replayed' => true];
    }

    /** @return Collection<int, PosPaymentMethod> */
    public function methodsOf(PosRegister $register): Collection
    {
        return $register->methods()->with('bankAccount')->orderBy('sequence')->get();
    }
}
