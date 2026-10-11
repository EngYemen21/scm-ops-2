<?php

namespace App\Services\Procurement;

use App\Finance\Services\LedgerService;
use App\Finance\Services\PaymentsService;
use App\Models\GrnLine;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceLine;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\NumberingService;
use App\Services\Core\SettingsService;
use App\Services\Procurement\ProcurementHelpers as H;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Supplier invoices and the three-way match (docs/procurement/GAP-ANALYSIS.ar.md §8).
 *
 *   register → matched | disputed → approved (for payment) → paid
 *
 * The match compares every invoice line with the purchase order (price) and the goods receipts (accepted quantity
 * not yet invoiced). A difference beyond `procurement.invoiceTolerancePct` makes the invoice `disputed`: it can still
 * be approved, but only with a written decision. Registering an invoice never touches stock or the PO; paying it
 * records the fact (date, reference) — the bank transfer itself happens in the finance system.
 */
class SupplierInvoiceService
{
    private const OPEN = ['matched', 'disputed', 'approved'];

    public function __construct(
        private readonly NumberingService $numbering,
        private readonly AuditService $audit,
        private readonly NotifyService $notify,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @param  array{po:string, supplierInvoiceNo:string, invoiceDate:string, dueDate?:?string, discount?:float|int|string|null, shippingCost?:float|int|string|null,
     *               vatPct?:float|int|string|null, notes?:?string, lines:array<int, array{lineNo:int|string, qty:int|string, price:float|int|string}>}  $dto
     */
    public function register(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            $po = PurchaseOrder::with(['supplier', 'lines' => fn ($q) => $q->orderBy('line_no'), 'lines.product:id,sku,name_ar,name_en'])
                ->where(fn ($w) => $w->where('id', $dto['po'])->orWhere('number', $dto['po']))->lockForUpdate()->first()
                ?? throw AppError::notFound('PO_NOT_FOUND', "أمر الشراء {$dto['po']} غير موجود", "PO {$dto['po']} not found");
            if (in_array($po->status, ['draft', 'pending', 'approved', 'cancelled'], true)) {
                throw AppError::rule('INVOICE_PO_STATE', "لا تُسجَّل فاتورة على {$po->number} قبل إرساله للمورد (الحالة: {$po->status})", "Invoices need a sent purchase order (status {$po->status})");
            }
            $no = trim((string) $dto['supplierInvoiceNo']);
            if (SupplierInvoice::where('supplier_id', $po->supplier_id)->where('supplier_invoice_no', $no)->where('status', '!=', 'cancelled')->exists()) {
                throw AppError::conflict('INVOICE_DUPLICATE', "الفاتورة {$no} مسجّلة مسبقًا لهذا المورد", "Invoice {$no} already registered for this supplier");
            }
            $tolerance = (float) ($this->settings->get('procurement.invoiceTolerancePct') ?? 2);
            $vatPct = isset($dto['vatPct']) && $dto['vatPct'] !== null && $dto['vatPct'] !== '' ? (float) $dto['vatPct'] : (float) ($po->vat_pct ?? 15);

            // accepted on GRNs and already invoiced, per PO line
            $received = GrnLine::whereIn('po_line_id', $po->lines->pluck('id'))->selectRaw('po_line_id, sum(accepted_qty) as qty')->groupBy('po_line_id')->pluck('qty', 'po_line_id');
            $invoiced = SupplierInvoiceLine::whereIn('po_line_id', $po->lines->pluck('id'))
                ->whereHas('invoice', fn ($q) => $q->where('status', '!=', 'cancelled'))
                ->selectRaw('po_line_id, sum(qty) as qty')->groupBy('po_line_id')->pluck('qty', 'po_line_id');

            $lines = [];
            $variances = [];
            $subtotal = 0.0;
            foreach (array_values($dto['lines']) as $i => $l) {
                $poLine = $po->lines->first(fn ($x) => (int) $x->line_no === (int) $l['lineNo'])
                    ?? throw AppError::validation('INVOICE_LINE', "البند {$l['lineNo']} غير موجود في {$po->number}", "Line {$l['lineNo']} is not on {$po->number}");
                $qty = (int) $l['qty'];
                $price = H::round2((float) $l['price']);
                if ($qty <= 0 || $price < 0) {
                    throw AppError::validation('INVOICE_LINE', 'الكمية يجب أن تكون أكبر من صفر والسعر لا يكون سالبًا', 'Quantity must be positive and price non-negative');
                }
                $rec = (int) ($received[$poLine->id] ?? 0);
                $prev = (int) ($invoiced[$poLine->id] ?? 0);
                $match = 'ok';
                $name = $poLine->product->name_ar;
                // quantity: only what was received and not yet invoiced may be billed
                if ($qty > $rec - $prev) {
                    $match = 'qty';
                    $variances[] = ['lineNo' => $poLine->line_no, 'kind' => 'qty', 'product' => $name, 'expected' => max(0, $rec - $prev), 'actual' => $qty, 'diff' => $qty - max(0, $rec - $prev),
                        'textAr' => "{$name}: الفاتورة {$qty} والمستلم غير المفوتَر {$rec}−{$prev}", 'textEn' => "{$name}: invoiced {$qty}, received not yet invoiced ".max(0, $rec - $prev)];
                }
                // price: the PO price within the tolerance
                $poPrice = (float) $poLine->price;
                if ($poPrice > 0 && abs($price - $poPrice) / $poPrice * 100 > $tolerance + 1e-9) {
                    $match = $match === 'qty' ? 'both' : 'price';
                    $pct = round(($price - $poPrice) / $poPrice * 100, 1);
                    $variances[] = ['lineNo' => $poLine->line_no, 'kind' => 'price', 'product' => $name, 'expected' => $poPrice, 'actual' => $price, 'diff' => H::round2($price - $poPrice),
                        'textAr' => "{$name}: سعر الفاتورة ".PoService::num($price).' مقابل '.PoService::num($poPrice)." في الأمر ({$pct}%)", 'textEn' => "{$name}: invoiced ".PoService::num($price).' vs PO '.PoService::num($poPrice)." ({$pct}%)"];
                }
                $subtotal += $qty * $price;
                $lines[] = ['line_no' => $i + 1, 'po_line_id' => $poLine->id, 'product_id' => $poLine->product_id, 'qty' => $qty, 'price' => $price, 'po_price' => $poPrice,
                    'received_qty' => $rec, 'previously_invoiced_qty' => $prev, 'match' => $match];
            }
            $subtotal = H::round2($subtotal);
            $discount = H::round2((float) ($dto['discount'] ?? 0));
            $shipping = H::round2((float) ($dto['shippingCost'] ?? 0));
            if ($discount < 0 || $shipping < 0 || $discount > $subtotal) {
                throw AppError::validation('INVOICE_TOTALS', 'الخصم والشحن لا يكونان سالبين والخصم لا يتجاوز المجموع', 'Discount and shipping must be non-negative and the discount may not exceed the subtotal');
            }
            $vatAmount = H::round2(($subtotal - $discount + $shipping) * $vatPct / 100);
            $total = H::round2($subtotal - $discount + $shipping + $vatAmount);
            // header: the PO's own charges are the reference for shipping / discount
            if (abs($shipping - (float) $po->shipping_cost) > 0.005 || abs($discount - (float) $po->discount) > 0.005) {
                $variances[] = ['lineNo' => null, 'kind' => 'charges', 'expected' => ['discount' => (float) $po->discount, 'shipping' => (float) $po->shipping_cost], 'actual' => ['discount' => $discount, 'shipping' => $shipping],
                    'textAr' => 'الخصم أو الشحن يختلف عن أمر الشراء', 'textEn' => 'Discount or shipping differs from the purchase order'];
            }
            $status = $variances ? 'disputed' : 'matched';
            $dueDate = ! empty($dto['dueDate']) ? H::parseDate($dto['dueDate']) : self::dueFromTerms(H::parseDate($dto['invoiceDate']), (string) ($po->payment_terms ?: $po->supplier->terms));

            $number = $this->numbering->next('INV');
            $inv = SupplierInvoice::create([
                'number' => $number, 'po_id' => $po->id, 'supplier_id' => $po->supplier_id, 'supplier_invoice_no' => $no, 'invoice_date' => H::parseDate($dto['invoiceDate']), 'due_date' => $dueDate,
                'subtotal' => $subtotal, 'discount' => $discount, 'shipping_cost' => $shipping, 'vat_pct' => $vatPct, 'vat_amount' => $vatAmount, 'total' => $total,
                'status' => $status, 'variances' => $variances ?: null, 'notes' => ($dto['notes'] ?? null) ?: null, 'registered_by' => $user->username,
            ]);
            foreach ($lines as $l) {
                SupplierInvoiceLine::create($l + ['invoice_id' => $inv->id]);
            }
            $this->audit->log($user, ['action' => 'INVOICE.REGISTER', 'entityType' => 'SupplierInvoice', 'entityId' => $inv->id, 'entityNumber' => $number,
                'newValue' => ['po' => $po->number, 'supplier' => $po->supplier->code, 'supplierInvoiceNo' => $no, 'total' => $total, 'status' => $status, 'variances' => count($variances)]]);
            $this->audit->status($user, 'SupplierInvoice', $inv->id, $number, null, $status);
            $totalText = PoService::num($total);
            $this->notify->activity($user, 'SupplierInvoice', $inv->id, $number,
                $status === 'matched' ? "سُجّلت فاتورة {$po->supplier->name_ar} {$no} على {$po->number} بقيمة {$totalText} ر.س — مطابقة، بانتظار اعتماد الصرف"
                    : "فاتورة {$po->supplier->name_ar} {$no} على {$po->number} ({$totalText} ر.س) مختلف عليها — ".count($variances).' فرق يحتاج قرارًا',
                $status === 'matched' ? "Invoice {$no} on {$po->number} registered — matched, awaiting payment approval" : "Invoice {$no} on {$po->number} disputed — ".count($variances).' variance(s)',
                ['finance']);

            return $this->get($inv->id);
        });
    }

    /** matched | disputed → approved. A disputed invoice needs a written decision. */
    public function approve(AuthUser $user, string $id, ?string $note = null): array
    {
        return DB::transaction(function () use ($user, $id, $note) {
            $inv = $this->lock($id);
            if (! in_array($inv->status, ['matched', 'disputed'], true)) {
                throw AppError::rule('INVOICE_STATE', "الفاتورة {$inv->number} ليست بانتظار الاعتماد (الحالة: {$inv->status})", "Invoice {$inv->number} is not awaiting approval");
            }
            if ($inv->status === 'disputed' && mb_strlen(trim((string) $note)) < 5) {
                throw AppError::validation('INVOICE_DECISION_REQUIRED', 'الفاتورة مختلف عليها — اكتب قرار الاعتماد وسببه', 'Disputed invoice: write the approval decision and its reason');
            }
            if ($inv->registered_by === $user->username && ! (bool) $this->settings->get('procurement.allowSelfApproval')) {
                throw AppError::rule('INVOICE_SELF_APPROVAL', 'لا يعتمد مسجّل الفاتورة فاتورته (فصل المسؤوليات)', 'The person who registered the invoice cannot approve it');
            }
            $from = $inv->status;
            $inv->update(['status' => 'approved', 'approved_by' => $user->username, 'approved_at' => now(), 'approval_note' => $note ?: null]);
            $inv->update(['journal_id' => $this->postApproval($user, $inv)->id]); // finance: GRNI → AP, price variances, VAT input
            $this->audit->log($user, ['action' => 'INVOICE.APPROVE', 'entityType' => 'SupplierInvoice', 'entityId' => $inv->id, 'entityNumber' => $inv->number, 'oldValue' => $from, 'newValue' => ['status' => 'approved', 'note' => $note]]);
            $this->audit->status($user, 'SupplierInvoice', $inv->id, $inv->number, $from, 'approved', $note);
            $this->notify->activity($user, 'SupplierInvoice', $inv->id, $inv->number, "اعتُمدت فاتورة {$inv->number} للصرف".($from === 'disputed' ? " رغم الفروق — {$note}" : ''), "Invoice {$inv->number} approved for payment", ['finance']);

            return $this->get($inv->id);
        });
    }

    /** approved → paid: records the payment fact (date + reference); the transfer itself is done in the finance system. */
    public function pay(AuthUser $user, string $id, array $dto): array
    {
        return DB::transaction(function () use ($user, $id, $dto) {
            $inv = $this->lock($id);
            if ($inv->status !== 'approved') {
                throw AppError::rule('INVOICE_STATE', "الفاتورة {$inv->number} ليست معتمدة للصرف (الحالة: {$inv->status})", "Invoice {$inv->number} is not approved for payment");
            }
            $ref = trim((string) ($dto['reference'] ?? ''));
            if ($ref === '') {
                throw AppError::validation('PAYMENT_REF_REQUIRED', 'مرجع السداد (رقم الحوالة) مطلوب', 'Payment reference is required');
            }
            // finance: the payment voucher (bank → supplier), partial or full; the invoice turns `paid` when fully settled
            $remaining = round((float) $inv->total - (float) $inv->paid_amount, 2);
            $amount = isset($dto['amount']) && $dto['amount'] !== null && $dto['amount'] !== '' ? round((float) $dto['amount'], 2) : $remaining;
            app(PaymentsService::class)->pay($user, ['supplier' => $inv->supplier_id, 'amount' => $amount, 'date' => $dto['paidAt'] ?? null, 'reference' => $ref,
                'bankAccount' => $dto['bankAccount'] ?? null, 'method' => $dto['method'] ?? null, 'allocations' => [['invoice' => $inv->id, 'amount' => min($amount, $remaining)]]]);
            $inv->refresh();
            $this->audit->log($user, ['action' => 'INVOICE.PAY', 'entityType' => 'SupplierInvoice', 'entityId' => $inv->id, 'entityNumber' => $inv->number, 'newValue' => ['amount' => $amount, 'reference' => $ref, 'paidAmount' => $inv->paid_amount]]);
            $this->notify->activity($user, 'SupplierInvoice', $inv->id, $inv->number, ($inv->status === 'paid' ? "سُدّدت فاتورة {$inv->number}" : "سداد جزئي لفاتورة {$inv->number} (".number_format($amount, 2).' ر.س)')." — مرجع {$ref}", "Invoice {$inv->number} payment — ref {$ref}", ['proc']);

            return $this->get($inv->id);
        });
    }

    public function cancel(AuthUser $user, string $id, string $reason): array
    {
        return DB::transaction(function () use ($user, $id, $reason) {
            $inv = $this->lock($id);
            if ($inv->status === 'paid') {
                throw AppError::rule('INVOICE_PAID', "الفاتورة {$inv->number} مسدَّدة ولا تُلغى", "Invoice {$inv->number} is paid and cannot be cancelled");
            }
            if ($inv->status === 'cancelled') {
                throw AppError::rule('INVOICE_STATE', 'الفاتورة ملغاة مسبقًا', 'Invoice already cancelled');
            }
            $from = $inv->status;
            if ((float) $inv->paid_amount > 0) {
                throw AppError::rule('INVOICE_PAID', "الفاتورة {$inv->number} عليها سداد جزئي — ألغِ سند السداد أولًا", 'Invoice has payments');
            }
            if ($inv->journal_id) {
                app(LedgerService::class)->reverse($user, $inv->journal_id, "إلغاء فاتورة المورد {$inv->number}: {$reason}");
            }
            $inv->update(['status' => 'cancelled', 'notes' => trim(($inv->notes ? $inv->notes."\n" : '')."إلغاء: {$reason}")]);
            $this->audit->status($user, 'SupplierInvoice', $inv->id, $inv->number, $from, 'cancelled', $reason);

            return $this->get($inv->id);
        });
    }

    /** @param  array{status?:?string, supplier?:?string, po?:?string, due?:?string}  $filters  due: overdue | week */
    public function list(Paging $page, array $filters): array
    {
        $q = SupplierInvoice::with(['supplier:id,code,name_ar,name_en', 'po:id,number,status'])->withCount('lines');
        if (! empty($filters['status'])) {
            $q->whereIn('status', H::csv($filters['status']));
        }
        if (! empty($filters['supplier'])) {
            $q->whereHas('supplier', fn ($s) => $s->where('code', $filters['supplier']));
        }
        if (! empty($filters['po'])) {
            $q->whereHas('po', fn ($p) => $p->where('number', $filters['po'])->orWhere('id', $filters['po']));
        }
        if (($filters['due'] ?? null) === 'overdue') {
            $q->whereIn('status', self::OPEN)->where('due_date', '<', H::dayStart());
        } elseif (($filters['due'] ?? null) === 'week') {
            $q->whereIn('status', self::OPEN)->whereBetween('due_date', [H::dayStart(), H::dayStart()->addDays(7)]);
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('supplier_invoice_no', 'like', $like)
                ->orWhereHas('po', fn ($p) => $p->where('number', 'like', $like))
                ->orWhereHas('supplier', fn ($s) => $s->where('name_ar', 'like', $like)->orWhere('name_en', 'like', $like)->orWhere('code', 'like', $like)));
        }
        $column = match ($page->sort) {
            'dueDate' => 'due_date', 'total' => 'total', default => 'created_at'
        };
        $q->orderBy($column, $page->order)->orderBy('id', $page->order);

        return $page->paginate($q, function (SupplierInvoice $i) {
            $i->supplier?->makeHidden('id');
            $i->po?->makeHidden('id');
            $row = $i->toArray();
            $row['_count'] = ['lines' => (int) $i->lines_count];
            unset($row['linesCount']);
            $row['overdue'] = in_array($i->status, self::OPEN, true) && $i->due_date !== null && $i->due_date->lt(H::dayStart());

            return $row;
        });
    }

    public function get(string $id): array
    {
        $inv = SupplierInvoice::with(['supplier:id,code,name_ar,name_en,terms', 'po:id,number,status,total,discount,shipping_cost,vat_pct,gross_total,payment_terms',
            'lines' => fn ($q) => $q->orderBy('line_no'), 'lines.product:id,sku,name_ar,name_en', 'lines.poLine:id,line_no,qty,price,received_qty'])
            ->where(fn ($w) => $w->where('id', $id)->orWhere('number', $id))->first()
            ?? throw AppError::notFound('INVOICE_NOT_FOUND', "الفاتورة {$id} غير موجودة", "Invoice {$id} not found");
        $row = $inv->toArray();
        $row['overdue'] = in_array($inv->status, self::OPEN, true) && $inv->due_date !== null && $inv->due_date->lt(H::dayStart());

        return $row;
    }

    /**
     * The approval journal: goods at the PO price close the GRNI accrual, the price difference is a purchase price variance,
     * shipping is freight-in, VAT input is recoverable, and the supplier is owed the invoice total.
     */
    private function postApproval(AuthUser $user, SupplierInvoice $inv): \App\Finance\Models\Journal
    {
        $inv->loadMissing(['lines', 'supplier', 'po']);
        $grni = round($inv->lines->sum(fn ($l) => (int) $l->qty * (float) $l->po_price), 2);
        $goods = round($inv->lines->sum(fn ($l) => (int) $l->qty * (float) $l->price), 2);
        $variance = round($goods - $grni - (float) $inv->discount, 2); // price above PO (+) / below PO or discount (−)
        $dims = ['referenceType' => 'SupplierInvoice', 'referenceId' => $inv->id, 'warehouseId' => $inv->po?->warehouse_id, 'costCenterId' => app(\App\Finance\Services\AccountMap::class)->forWarehouse($inv->po?->warehouse_id)?->id];
        $lines = [];
        if ($grni > 0) {
            $lines[] = ['role' => 'grni', 'debit' => $grni, 'description' => "إقفال بضاعة مستلمة غير مفوترة — {$inv->po?->number}"] + $dims;
        }
        if (abs($variance) >= 0.01) {
            $lines[] = ['role' => 'ppv', $variance > 0 ? 'debit' : 'credit' => abs($variance), 'description' => 'فرق سعر الفاتورة عن أمر الشراء'.((float) $inv->discount > 0 ? ' (بعد الخصم)' : '')] + $dims;
        }
        if ((float) $inv->shipping_cost > 0) {
            $lines[] = ['role' => 'freight_in', 'debit' => (float) $inv->shipping_cost, 'description' => 'شحن وارد'] + $dims;
        }
        if ((float) $inv->vat_amount > 0) {
            $lines[] = ['role' => 'vat_input', 'debit' => (float) $inv->vat_amount, 'description' => "ضريبة مدخلات {$inv->vat_pct}%"] + $dims;
        }
        $lines[] = ['role' => 'ap', 'credit' => (float) $inv->total, 'description' => "{$inv->number} · {$inv->supplier?->name_ar} · {$inv->supplier_invoice_no}", 'partnerType' => 'supplier', 'partnerId' => $inv->supplier_id] + $dims;

        return app(LedgerService::class)->post($user, [
            'eventKey' => "si:{$inv->id}", 'date' => $inv->invoice_date, 'source' => ['type' => 'SupplierInvoice', 'id' => $inv->id, 'number' => $inv->number],
            'memo' => "فاتورة مورد {$inv->number} — {$inv->supplier?->name_ar} — {$inv->supplier_invoice_no}", 'lines' => $lines,
        ]);
    }

    /** Payables snapshot for the dashboard. */
    public function summary(): array
    {
        $open = SupplierInvoice::whereIn('status', self::OPEN)->get(['status', 'total', 'due_date']);
        $today = H::dayStart();

        return [
            'disputed' => $open->where('status', 'disputed')->count(),
            'awaitingApproval' => $open->whereIn('status', ['matched', 'disputed'])->count(),
            'openTotal' => H::round2($open->sum(fn ($i) => (float) $i->total)),
            'overdue' => ['count' => $open->filter(fn ($i) => $i->due_date && $i->due_date->lt($today))->count(), 'total' => H::round2($open->filter(fn ($i) => $i->due_date && $i->due_date->lt($today))->sum(fn ($i) => (float) $i->total))],
            'dueThisWeek' => ['count' => $open->filter(fn ($i) => $i->due_date && $i->due_date->gte($today) && $i->due_date->lt($today->copy()->addDays(7)))->count(),
                'total' => H::round2($open->filter(fn ($i) => $i->due_date && $i->due_date->gte($today) && $i->due_date->lt($today->copy()->addDays(7)))->sum(fn ($i) => (float) $i->total))],
        ];
    }

    /** "آجل 30 يومًا" / "net 45" → invoice date + N days; cash / unknown → the invoice date. */
    public static function dueFromTerms(?Carbon $invoiceDate, string $terms): ?Carbon
    {
        if (! $invoiceDate) {
            return null;
        }
        $t = strtr($terms, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        if (preg_match('/(\d{1,3})/', $t, $m) && preg_match('/آجل|net|يوم|day/iu', $t)) {
            return $invoiceDate->copy()->addDays((int) $m[1]);
        }

        return $invoiceDate->copy();
    }

    private function lock(string $id): SupplierInvoice
    {
        $invId = SupplierInvoice::where('id', $id)->orWhere('number', $id)->value('id')
            ?? throw AppError::notFound('INVOICE_NOT_FOUND', "الفاتورة {$id} غير موجودة", "Invoice {$id} not found");

        return SupplierInvoice::whereKey($invId)->lockForUpdate()->firstOrFail();
    }
}
