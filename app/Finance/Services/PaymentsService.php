<?php

namespace App\Finance\Services;

use App\Finance\Models\BankAccount;
use App\Finance\Models\CustomerInvoice;
use App\Finance\Models\Payment;
use App\Finance\Models\PaymentAllocation;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\NumberingService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Money movements: customer receipts (RCT-) and supplier payments (PMT-) through a bank account or cash box, allocated
 * to invoices in full or in part. The unallocated remainder stays on the partner's account (shown on the statement).
 * Voiding reverses the journal and releases the allocations. The bank transfer itself happens at the bank; this is its
 * accounting record.
 */
class PaymentsService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly CustomerInvoiceService $customerInvoices,
        private readonly NumberingService $numbering,
        private readonly AuditService $audit,
        private readonly NotifyService $notify,
    ) {}

    /** @param  array{customer:string, amount:float|int|string, date?:?string, method?:?string, bankAccount?:?string, reference?:?string, notes?:?string, allocations?:array<int, array{invoice:string, amount:float|int|string}>}  $dto */
    public function receive(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            $customer = Customer::where('code', $dto['customer'])->orWhere('id', $dto['customer'])->first()
                ?? throw AppError::notFound('CUSTOMER_NOT_FOUND', "العميل {$dto['customer']} غير موجود", 'Customer not found');
            $amount = $this->amount($dto['amount']);
            $bank = $this->bank($dto['bankAccount'] ?? null);
            $date = $this->date($dto['date'] ?? null);
            $p = Payment::create([
                'number' => $this->numbering->next('RCT'), 'direction' => 'in', 'partner_type' => 'customer', 'partner_id' => $customer->id, 'payment_date' => $date->toDateString(),
                'amount' => $amount, 'method' => $this->method($dto['method'] ?? null, $bank), 'bank_account_id' => $bank->id, 'reference' => ($dto['reference'] ?? null) ?: null, 'notes' => ($dto['notes'] ?? null) ?: null,
                'status' => 'posted', 'created_by' => $user->username,
            ]);
            $allocated = 0.0;
            foreach ($dto['allocations'] ?? [] as $a) {
                $inv = CustomerInvoice::where(fn ($w) => $w->where('number', $a['invoice'])->orWhere('id', $a['invoice']))->lockForUpdate()->first()
                    ?? throw AppError::notFound('CI_NOT_FOUND', "الفاتورة {$a['invoice']} غير موجودة", 'Invoice not found');
                if ($inv->customer_id !== $customer->id || $inv->kind !== 'invoice') {
                    throw AppError::rule('ALLOC_PARTNER', "الفاتورة {$inv->number} ليست لهذا العميل", 'Invoice belongs to another customer');
                }
                if (! in_array($inv->status, CustomerInvoice::OPEN, true)) {
                    throw AppError::rule('ALLOC_STATE', "الفاتورة {$inv->number} ليست مفتوحة (الحالة: {$inv->status})", 'Invoice is not open');
                }
                $x = $this->amount($a['amount']);
                $remaining = round((float) $inv->total - (float) $inv->paid_amount, 2);
                if ($x > $remaining + 0.005) {
                    throw AppError::rule('ALLOC_EXCEEDS', "التوزيع على {$inv->number} ({$x}) يتجاوز المتبقي عليها ({$remaining})", 'Allocation exceeds the invoice balance');
                }
                PaymentAllocation::create(['payment_id' => $p->id, 'invoice_type' => 'customer', 'invoice_id' => $inv->id, 'amount' => $x]);
                $this->customerInvoices->applyToInvoice($inv->id, $x);
                $allocated = round($allocated + $x, 2);
            }
            if ($allocated > $amount + 0.005) {
                throw AppError::rule('ALLOC_TOTAL', 'مجموع التوزيع أكبر من المبلغ المقبوض', 'Allocations exceed the amount received');
            }
            $journal = $this->ledger->post($user, [
                'eventKey' => "pay:{$p->id}", 'date' => $date, 'source' => ['type' => 'Payment', 'id' => $p->id, 'number' => $p->number],
                'memo' => "تحصيل {$p->number} من {$customer->name_ar}".($p->reference ? " — مرجع {$p->reference}" : ''),
                'lines' => [
                    ['account' => $bank->account_id, 'debit' => $amount, 'description' => "{$p->number} · {$bank->name_ar}", 'referenceType' => 'Payment', 'referenceId' => $p->id],
                    ['role' => 'ar', 'credit' => $amount, 'description' => "{$p->number} · {$customer->name_ar}", 'partnerType' => 'customer', 'partnerId' => $customer->id, 'referenceType' => 'Payment', 'referenceId' => $p->id],
                ],
            ]);
            $p->update(['allocated_amount' => $allocated, 'journal_id' => $journal->id]);
            $this->audit->log($user, ['action' => 'RECEIPT.CREATE', 'entityType' => 'Payment', 'entityId' => $p->id, 'entityNumber' => $p->number, 'newValue' => ['customer' => $customer->code, 'amount' => $amount, 'allocated' => $allocated, 'journal' => $journal->number]]);
            $this->notify->activity($user, 'Payment', $p->id, $p->number, "قُبض {$p->number} من {$customer->name_ar} بمبلغ ".number_format($amount, 2).' ر.س', "Receipt {$p->number} from {$customer->name_en}", ['finance']);

            return $this->get($p->id);
        });
    }

    /** @param  array{supplier:string, amount?:float|int|string|null, date?:?string, method?:?string, bankAccount?:?string, reference?:?string, notes?:?string, allocations?:array<int, array{invoice:string, amount:float|int|string}>}  $dto */
    public function pay(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            $supplier = Supplier::where('code', $dto['supplier'])->orWhere('id', $dto['supplier'])->first()
                ?? throw AppError::notFound('SUPPLIER_NOT_FOUND', "المورد {$dto['supplier']} غير موجود", 'Supplier not found');
            $bank = $this->bank($dto['bankAccount'] ?? null);
            $date = $this->date($dto['date'] ?? null);
            $allocations = [];
            $allocated = 0.0;
            foreach ($dto['allocations'] ?? [] as $a) {
                $inv = SupplierInvoice::where(fn ($w) => $w->where('number', $a['invoice'])->orWhere('id', $a['invoice']))->lockForUpdate()->first()
                    ?? throw AppError::notFound('INVOICE_NOT_FOUND', "الفاتورة {$a['invoice']} غير موجودة", 'Invoice not found');
                if ($inv->supplier_id !== $supplier->id) {
                    throw AppError::rule('ALLOC_PARTNER', "الفاتورة {$inv->number} ليست لهذا المورد", 'Invoice belongs to another supplier');
                }
                if ($inv->status !== 'approved') {
                    throw AppError::rule('INVOICE_STATE', "الفاتورة {$inv->number} ليست معتمدة للصرف (الحالة: {$inv->status})", "Invoice {$inv->number} is not approved for payment");
                }
                $remaining = round((float) $inv->total - (float) $inv->paid_amount, 2);
                $x = isset($a['amount']) && $a['amount'] !== null && $a['amount'] !== '' ? $this->amount($a['amount']) : $remaining;
                if ($x > $remaining + 0.005) {
                    throw AppError::rule('ALLOC_EXCEEDS', "السداد على {$inv->number} ({$x}) يتجاوز المتبقي عليها ({$remaining})", 'Allocation exceeds the invoice balance');
                }
                $allocations[] = [$inv, $x];
                $allocated = round($allocated + $x, 2);
            }
            $amount = isset($dto['amount']) && $dto['amount'] !== null && $dto['amount'] !== '' ? $this->amount($dto['amount']) : $allocated;
            if ($amount <= 0) {
                throw AppError::validation('AMOUNT_REQUIRED', 'حدد مبلغ السداد أو الفواتير المسدَّدة', 'Amount or allocations required');
            }
            if ($allocated > $amount + 0.005) {
                throw AppError::rule('ALLOC_TOTAL', 'مجموع التوزيع أكبر من المبلغ المسدَّد', 'Allocations exceed the amount paid');
            }
            $ref = trim((string) ($dto['reference'] ?? ''));
            if ($ref === '') {
                throw AppError::validation('PAYMENT_REF_REQUIRED', 'مرجع السداد (رقم الحوالة / الشيك) مطلوب', 'Payment reference is required');
            }
            $p = Payment::create([
                'number' => $this->numbering->next('PMT'), 'direction' => 'out', 'partner_type' => 'supplier', 'partner_id' => $supplier->id, 'payment_date' => $date->toDateString(),
                'amount' => $amount, 'allocated_amount' => $allocated, 'method' => $this->method($dto['method'] ?? null, $bank), 'bank_account_id' => $bank->id, 'reference' => $ref, 'notes' => ($dto['notes'] ?? null) ?: null,
                'status' => 'posted', 'created_by' => $user->username,
            ]);
            foreach ($allocations as [$inv, $x]) {
                PaymentAllocation::create(['payment_id' => $p->id, 'invoice_type' => 'supplier', 'invoice_id' => $inv->id, 'amount' => $x]);
                $paid = round((float) $inv->paid_amount + $x, 2);
                $full = $paid >= (float) $inv->total - 0.005;
                $inv->update(['paid_amount' => $paid] + ($full ? ['status' => 'paid', 'paid_at' => $date, 'paid_by' => $user->username, 'payment_ref' => $ref] : []));
                if ($full) {
                    $this->audit->status($user, 'SupplierInvoice', $inv->id, $inv->number, 'approved', 'paid', $ref);
                }
            }
            $journal = $this->ledger->post($user, [
                'eventKey' => "pay:{$p->id}", 'date' => $date, 'source' => ['type' => 'Payment', 'id' => $p->id, 'number' => $p->number],
                'memo' => "سداد {$p->number} إلى {$supplier->name_ar} — مرجع {$ref}",
                'lines' => [
                    ['role' => 'ap', 'debit' => $amount, 'description' => "{$p->number} · {$supplier->name_ar}", 'partnerType' => 'supplier', 'partnerId' => $supplier->id, 'referenceType' => 'Payment', 'referenceId' => $p->id],
                    ['account' => $bank->account_id, 'credit' => $amount, 'description' => "{$p->number} · {$bank->name_ar}", 'referenceType' => 'Payment', 'referenceId' => $p->id],
                ],
            ]);
            $p->update(['journal_id' => $journal->id]);
            $this->audit->log($user, ['action' => 'PAYMENT.CREATE', 'entityType' => 'Payment', 'entityId' => $p->id, 'entityNumber' => $p->number, 'newValue' => ['supplier' => $supplier->code, 'amount' => $amount, 'allocated' => $allocated, 'reference' => $ref, 'journal' => $journal->number]]);
            $this->notify->activity($user, 'Payment', $p->id, $p->number, "سُدّد {$p->number} إلى {$supplier->name_ar} بمبلغ ".number_format($amount, 2)." ر.س — مرجع {$ref}", "Payment {$p->number} to {$supplier->name_en}", ['finance', 'proc']);

            return $this->get($p->id);
        });
    }

    /** Void: reversal journal, allocations released, invoices re-opened. */
    public function void(AuthUser $user, string $idOrNumber, string $reason): array
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw AppError::validation('REASON_REQUIRED', 'اكتب سبب الإلغاء (5 أحرف على الأقل)', 'A reason is required');
        }

        return DB::transaction(function () use ($user, $idOrNumber, $reason) {
            $p = Payment::with('allocations')->where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->lockForUpdate()->first()
                ?? throw AppError::notFound('PAYMENT_NOT_FOUND', "السند {$idOrNumber} غير موجود", 'Payment not found');
            if ($p->status === 'void') {
                throw AppError::rule('PAYMENT_STATE', 'السند ملغى مسبقًا', 'Already void');
            }
            foreach ($p->allocations as $a) {
                if ($a->invoice_type === 'customer') {
                    $this->customerInvoices->applyToInvoice($a->invoice_id, -(float) $a->amount);
                } else {
                    $inv = SupplierInvoice::whereKey($a->invoice_id)->lockForUpdate()->first();
                    if ($inv) {
                        $paid = max(0, round((float) $inv->paid_amount - (float) $a->amount, 2));
                        $inv->update(['paid_amount' => $paid] + ($inv->status === 'paid' ? ['status' => 'approved', 'paid_at' => null, 'paid_by' => null, 'payment_ref' => null] : []));
                    }
                }
            }
            if ($p->journal_id) {
                $this->ledger->reverse($user, $p->journal_id, "إلغاء {$p->number}: {$reason}");
            }
            $p->update(['status' => 'void', 'voided_by' => $user->username, 'voided_at' => now(), 'void_reason' => $reason, 'allocated_amount' => 0]);
            $this->audit->status($user, 'Payment', $p->id, $p->number, 'posted', 'void', $reason);

            return $this->get($p->id);
        });
    }

    public function get(string $idOrNumber): array
    {
        $p = Payment::with(['allocations', 'bankAccount:id,code,name_ar,name_en,kind', 'journal:id,number'])->where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->first()
            ?? throw AppError::notFound('PAYMENT_NOT_FOUND', "السند {$idOrNumber} غير موجود", 'Payment not found');
        $row = $p->toArray();
        $row['partner'] = $this->partner($p);
        $row['allocations'] = $p->allocations->map(function (PaymentAllocation $a) {
            $inv = $a->invoice_type === 'customer' ? CustomerInvoice::find($a->invoice_id) : SupplierInvoice::find($a->invoice_id);

            return $a->toArray() + ['invoice' => $inv ? ['number' => $inv->number, 'total' => $inv->total, 'status' => $inv->status] : null];
        })->all();

        return $row;
    }

    /** @param  array{direction?:?string, partnerType?:?string, partner?:?string, status?:?string, from?:?string, to?:?string, bankAccount?:?string}  $f */
    public function list(Paging $page, array $f): array
    {
        $q = Payment::with(['bankAccount:id,code,name_ar,name_en,kind']);
        foreach (['direction', 'status', 'partner_type' => 'partnerType'] as $col => $key) {
            $k = is_int($col) ? $key : $key;
            $c = is_int($col) ? $key : $col;
            if (! empty($f[$k])) {
                $q->where($c, $f[$k]);
            }
        }
        if (! empty($f['partner'])) {
            $ids = array_merge(Customer::where('code', $f['partner'])->orWhere('id', $f['partner'])->pluck('id')->all(), Supplier::where('code', $f['partner'])->orWhere('id', $f['partner'])->pluck('id')->all());
            $q->whereIn('partner_id', $ids ?: ['-']);
        }
        if (! empty($f['bankAccount'])) {
            $q->whereHas('bankAccount', fn ($b) => $b->where('code', $f['bankAccount']));
        }
        if (! empty($f['from'])) {
            $q->whereDate('payment_date', '>=', $f['from']);
        }
        if (! empty($f['to'])) {
            $q->whereDate('payment_date', '<=', $f['to']);
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('reference', 'like', $like));
        }
        $q->orderBy($page->sort === 'amount' ? 'amount' : 'payment_date', $page->order)->orderBy('id', $page->order);

        return $page->paginate($q, fn (Payment $p) => $p->toArray() + ['partner' => $this->partner($p)]);
    }

    public function bankAccounts(): array
    {
        return BankAccount::with('account:id,code,name_ar,name_en')->orderBy('code')->get()->map->toArray()->all();
    }

    private function partner(Payment $p): ?array
    {
        $m = $p->partner_type === 'customer' ? Customer::find($p->partner_id) : Supplier::find($p->partner_id);

        return $m ? ['type' => $p->partner_type, 'code' => $m->code, 'nameAr' => $m->name_ar, 'nameEn' => $m->name_en] : null;
    }

    private function bank(?string $code): BankAccount
    {
        $b = $code ? BankAccount::where('code', $code)->orWhere('id', $code)->first() : BankAccount::where('code', 'BANK')->first();
        if (! $b) {
            throw AppError::notFound('BANK_ACCOUNT_NOT_FOUND', 'الحساب البنكي غير موجود — أضفه من المالية ← البنوك', 'Bank account not found');
        }
        if (! $b->active) {
            throw AppError::rule('BANK_ACCOUNT_INACTIVE', "الحساب البنكي {$b->code} غير نشط", 'Bank account inactive');
        }

        return $b;
    }

    private function method(?string $m, BankAccount $bank): string
    {
        $m = $m ?: ($bank->kind === 'cash' ? 'cash' : 'bank');
        if (! in_array($m, Payment::METHODS, true)) {
            throw AppError::validation('PAYMENT_METHOD', 'طريقة السداد غير معروفة', 'Unknown payment method');
        }

        return $m;
    }

    private function amount(mixed $v): float
    {
        $a = round((float) $v, 2);
        if ($a <= 0) {
            throw AppError::validation('AMOUNT_INVALID', 'المبلغ يجب أن يكون أكبر من صفر', 'Amount must be positive');
        }

        return $a;
    }

    private function date(?string $d): Carbon
    {
        $x = $d ? Carbon::parse($d)->startOfDay() : Carbon::now()->startOfDay();
        if ($x->gt(Carbon::now()->endOfDay())) {
            throw AppError::validation('DATE_FUTURE', 'تاريخ السند لا يكون في المستقبل', 'Date cannot be in the future');
        }

        return $x;
    }
}
