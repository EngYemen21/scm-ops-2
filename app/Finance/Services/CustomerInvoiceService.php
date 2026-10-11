<?php

namespace App\Finance\Services;

use App\Finance\Models\CustomerInvoice;
use App\Finance\Models\CustomerInvoiceLine;
use App\Models\Customer;
use App\Models\FulfillmentOrder;
use App\Models\Product;
use App\Models\ProofOfDelivery;
use App\Models\ReturnOrder;
use App\Models\SalesOrder;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\NumberingService;
use App\Services\Core\SettingsService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Accounts receivable documents.
 *  - Invoice: issued automatically when a proof of delivery closes a stop (control transfers to the customer): the
 *    delivered quantity of every line at the order's price snapshot, VAT from the order's commercial terms, and the
 *    cost of goods sold taken from the load movements. Revenue, AR and COGS post in the delivery's own transaction.
 *  - Credit note: issued when a customer return of already-invoiced goods is received (post-delivery return); it is
 *    applied to the invoice it credits.
 * Prices are never recomputed here: the order (Sales platform or OPS) is the commercial source of truth.
 */
class CustomerInvoiceService
{
    public const POD_RETURN_REASONS = ['partial', 'del_fail', 'cust_reject'];

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly CostingService $costing,
        private readonly NumberingService $numbering,
        private readonly SettingsService $settings,
        private readonly AuditService $audit,
        private readonly NotifyService $notify,
    ) {}

    /**
     * Called by DeliveryService::closeStop inside its transaction. Returns null when nothing was delivered or the order
     * carries no prices (then nothing is invoiced and the delivery still succeeds).
     *
     * @param  array<string, int>  $deliveredByLineId  fo_line id → delivered qty
     */
    public function issueFromDelivery(?AuthUser $user, FulfillmentOrder $fo, ProofOfDelivery $pod, array $deliveredByLineId): ?CustomerInvoice
    {
        if (CustomerInvoice::where('pod_id', $pod->id)->exists()) {
            return CustomerInvoice::where('pod_id', $pod->id)->first(); // idempotent per POD
        }
        $customerId = $fo->customer_id ?: $fo->so?->customer_id;
        if (! $customerId) {
            return null;
        }
        $so = $fo->so_id ? SalesOrder::find($fo->so_id) : null;
        $vatPct = (float) (($so?->commercial['vatPct'] ?? null) ?? $this->settings->get('sales.vatPct') ?? 15);
        $lines = [];
        foreach ($fo->lines as $l) {
            $dq = (int) ($deliveredByLineId[$l->id] ?? 0);
            $sol = $l->soLine;
            if ($dq <= 0 || ! $sol || $sol->price === null) {
                continue;
            }
            $price = (float) $sol->price;
            $disc = (float) ($sol->disc_pct ?? 0);
            $lineTotal = round($dq * $price * (1 - $disc / 100), 2);
            $product = Product::find($l->product_id);
            $unitCost = $this->costing->loadCost($fo->id, $l->product_id) ?? ($product?->avg_cost !== null ? (float) $product->avg_cost : (float) ($product?->purchase_price ?? 0));
            $lines[] = ['so_line_id' => $sol->id, 'product_id' => $l->product_id, 'qty' => $dq, 'price' => $price, 'disc_pct' => $disc, 'line_total' => $lineTotal, 'unit_cost' => round($unitCost, 4), 'cogs' => round($unitCost * $dq, 2), 'sku' => $product?->sku];
        }
        if (! $lines) {
            return null;
        }
        $customer = Customer::findOrFail($customerId);
        $subtotal = round(array_sum(array_column($lines, 'line_total')), 2);
        $vat = round($subtotal * $vatPct / 100, 2);
        $total = round($subtotal + $vat, 2);
        $cogs = round(array_sum(array_column($lines, 'cogs')), 2);
        $date = Carbon::now()->startOfDay();
        $termsDays = $customer->payment_terms_days !== null ? (int) $customer->payment_terms_days : null;

        $inv = CustomerInvoice::create([
            'number' => $this->numbering->next('CI'), 'kind' => 'invoice', 'customer_id' => $customer->id, 'so_id' => $fo->so_id, 'fo_id' => $fo->id, 'pod_id' => $pod->id,
            'invoice_date' => $date->toDateString(), 'due_date' => $date->copy()->addDays($termsDays ?? 0)->toDateString(), 'status' => 'issued',
            'subtotal' => $subtotal, 'discount' => 0, 'vat_pct' => $vatPct, 'vat_amount' => $vat, 'total' => $total, 'paid_amount' => 0, 'cogs_total' => $cogs,
            'payment_terms' => $so?->commercial['paymentTerms'] ?? $customer->terms, 'created_by' => $user?->username ?? 'system',
        ]);
        foreach ($lines as $i => $l) {
            unset($l['sku']);
            CustomerInvoiceLine::create(['invoice_id' => $inv->id, 'line_no' => $i + 1] + $l);
        }
        $partner = ['partnerType' => 'customer', 'partnerId' => $customer->id];
        $dims = ['referenceType' => 'CustomerInvoice', 'referenceId' => $inv->id, 'warehouseId' => $fo->warehouse_id];
        $salesCc = app(AccountMap::class)->costCenter('SALES')?->id;
        $journal = $this->ledger->post($user, [
            'eventKey' => "ci:{$inv->id}", 'source' => ['type' => 'CustomerInvoice', 'id' => $inv->id, 'number' => $inv->number],
            'memo' => "فاتورة عميل {$inv->number} — {$customer->name_ar} — تسليم {$fo->number} ({$pod->number})",
            'lines' => array_merge(
                [['role' => 'ar', 'debit' => $total, 'description' => "{$inv->number} · {$customer->name_ar}"] + $partner + $dims],
                array_map(fn ($l) => ['role' => 'revenue', 'credit' => $l['line_total'], 'description' => "{$l['sku']} × {$l['qty']}", 'productId' => $l['product_id'], 'costCenterId' => $salesCc] + $dims, $lines),
                $vat > 0 ? [['role' => 'vat_output', 'credit' => $vat, 'description' => "ضريبة {$vatPct}% · {$inv->number}"] + $dims] : [],
            ),
        ]);
        $cogsJournal = null;
        if ($cogs > 0) {
            $cogsJournal = $this->ledger->post($user, [
                'eventKey' => "cogs:{$inv->id}", 'source' => ['type' => 'CustomerInvoice', 'id' => $inv->id, 'number' => $inv->number],
                'memo' => "تكلفة البضاعة المباعة — {$inv->number} — {$fo->number}",
                'lines' => array_merge(
                    array_map(fn ($l) => ['role' => 'cogs', 'debit' => $l['cogs'], 'description' => "{$l['sku']} × {$l['qty']} @ {$l['unit_cost']}", 'productId' => $l['product_id'], 'costCenterId' => $salesCc] + $dims, array_filter($lines, fn ($l) => $l['cogs'] > 0)),
                    [['role' => 'goods_in_transit', 'credit' => $cogs, 'description' => "خروج بضاعة {$fo->number} من الطريق إلى العميل"] + $dims],
                ),
            ]);
        }
        $inv->update(['journal_id' => $journal->id, 'cogs_journal_id' => $cogsJournal?->id]);
        $this->audit->log($user, ['action' => 'CI.ISSUE', 'entityType' => 'CustomerInvoice', 'entityId' => $inv->id, 'entityNumber' => $inv->number,
            'newValue' => ['customer' => $customer->code, 'fo' => $fo->number, 'pod' => $pod->number, 'total' => $total, 'cogs' => $cogs, 'journal' => $journal->number]]);
        $this->notify->activity($user, 'CustomerInvoice', $inv->id, $inv->number, "صدرت فاتورة {$inv->number} للعميل {$customer->name_ar} بقيمة ".number_format($total, 2).' ر.س عن تسليم '.$fo->number,
            "Invoice {$inv->number} issued to {$customer->name_en} for {$fo->number}", ['finance']);

        return $inv->refresh();
    }

    /** Credit note for a post-delivery customer return (received goods of an invoiced fulfilment order). Returns null when no invoice covers it. */
    public function creditFromReturn(?AuthUser $user, ReturnOrder $r): ?CustomerInvoice
    {
        if (! $r->fo_id || CustomerInvoice::where('return_id', $r->id)->exists()) {
            return CustomerInvoice::where('return_id', $r->id)->first();
        }
        $invoice = CustomerInvoice::with('lines')->where('fo_id', $r->fo_id)->where('kind', 'invoice')->where('status', '!=', 'cancelled')->orderBy('created_at')->first();
        if (! $invoice) {
            return null;
        }
        $credited = CustomerInvoiceLine::whereIn('invoice_id', CustomerInvoice::where('credit_of_id', $invoice->id)->where('status', '!=', 'cancelled')->pluck('id'))
            ->selectRaw('product_id, coalesce(sum(qty), 0) as qty')->groupBy('product_id')->pluck('qty', 'product_id');
        $lines = [];
        foreach ($r->lines as $rl) {
            $il = $invoice->lines->firstWhere('product_id', $rl->product_id);
            if (! $il) {
                continue;
            }
            $remaining = max(0, (int) $il->qty - (int) ($credited[$rl->product_id] ?? 0));
            $qty = min((int) $rl->qty, $remaining);
            if ($qty <= 0) {
                continue;
            }
            $lineTotal = round($qty * (float) $il->price * (1 - (float) $il->disc_pct / 100), 2);
            $lines[] = ['so_line_id' => $il->so_line_id, 'product_id' => $il->product_id, 'qty' => $qty, 'price' => (float) $il->price, 'disc_pct' => (float) $il->disc_pct, 'line_total' => $lineTotal, 'unit_cost' => (float) $il->unit_cost, 'cogs' => round((float) $il->unit_cost * $qty, 2)];
        }
        if (! $lines) {
            return null;
        }
        $subtotal = round(array_sum(array_column($lines, 'line_total')), 2);
        $vat = round($subtotal * (float) $invoice->vat_pct / 100, 2);
        $total = round($subtotal + $vat, 2);
        $customer = Customer::findOrFail($invoice->customer_id);
        $cn = CustomerInvoice::create([
            'number' => $this->numbering->next('CN'), 'kind' => 'credit_note', 'customer_id' => $customer->id, 'so_id' => $invoice->so_id, 'fo_id' => $invoice->fo_id, 'return_id' => $r->id,
            'credit_of_id' => $invoice->id, 'invoice_date' => now()->toDateString(), 'due_date' => now()->toDateString(), 'status' => 'paid',
            'subtotal' => $subtotal, 'vat_pct' => $invoice->vat_pct, 'vat_amount' => $vat, 'total' => $total, 'paid_amount' => $total, 'cogs_total' => round(array_sum(array_column($lines, 'cogs')), 2),
            'notes' => "إشعار دائن عن المرتجع {$r->number} على الفاتورة {$invoice->number}", 'created_by' => $user?->username ?? 'system',
        ]);
        foreach ($lines as $i => $l) {
            CustomerInvoiceLine::create(['invoice_id' => $cn->id, 'line_no' => $i + 1] + $l);
        }
        $dims = ['referenceType' => 'CustomerInvoice', 'referenceId' => $cn->id];
        $journal = $this->ledger->post($user, [
            'eventKey' => "cn:{$cn->id}", 'source' => ['type' => 'CustomerInvoice', 'id' => $cn->id, 'number' => $cn->number],
            'memo' => "إشعار دائن {$cn->number} — {$customer->name_ar} — مرتجع {$r->number}",
            'lines' => array_merge(
                [['role' => 'sales_returns', 'debit' => $subtotal, 'description' => "مردودات {$invoice->number}"] + $dims],
                $vat > 0 ? [['role' => 'vat_output', 'debit' => $vat, 'description' => "ضريبة مردودات {$cn->number}"] + $dims] : [],
                [['role' => 'ar', 'credit' => $total, 'description' => "{$cn->number} · {$customer->name_ar}", 'partnerType' => 'customer', 'partnerId' => $customer->id] + $dims],
            ),
        ]);
        $cn->update(['journal_id' => $journal->id]);
        // the credit note settles that much of the invoice it credits
        $this->applyToInvoice($invoice->id, $total);
        $this->audit->log($user, ['action' => 'CN.ISSUE', 'entityType' => 'CustomerInvoice', 'entityId' => $cn->id, 'entityNumber' => $cn->number, 'newValue' => ['invoice' => $invoice->number, 'return' => $r->number, 'total' => $total]]);
        $this->notify->activity($user, 'CustomerInvoice', $cn->id, $cn->number, "صدر إشعار دائن {$cn->number} بقيمة ".number_format($total, 2)." ر.س للعميل {$customer->name_ar} عن المرتجع {$r->number}", "Credit note {$cn->number} issued for return {$r->number}", ['finance']);

        return $cn->refresh();
    }

    /** Adds a settlement (receipt allocation or credit note) to an invoice and derives its status. */
    public function applyToInvoice(string $invoiceId, float $amount): CustomerInvoice
    {
        $inv = CustomerInvoice::whereKey($invoiceId)->lockForUpdate()->firstOrFail();
        $paid = round((float) $inv->paid_amount + $amount, 2);
        $inv->update(['paid_amount' => $paid, 'status' => $inv->status === 'cancelled' ? 'cancelled' : ($paid >= (float) $inv->total - 0.005 ? 'paid' : ($paid > 0 ? 'partially_paid' : 'issued'))]);

        return $inv;
    }

    public function cancel(AuthUser $user, string $idOrNumber, string $reason): array
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw AppError::validation('REASON_REQUIRED', 'اكتب سبب الإلغاء (5 أحرف على الأقل)', 'A reason is required');
        }

        return DB::transaction(function () use ($user, $idOrNumber, $reason) {
            $inv = CustomerInvoice::where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->lockForUpdate()->first()
                ?? throw AppError::notFound('CI_NOT_FOUND', "الفاتورة {$idOrNumber} غير موجودة", 'Invoice not found');
            if ($inv->status === 'cancelled') {
                throw AppError::rule('CI_STATE', 'الفاتورة ملغاة مسبقًا', 'Already cancelled');
            }
            if ((float) $inv->paid_amount > 0) {
                throw AppError::rule('CI_SETTLED', "الفاتورة {$inv->number} عليها تحصيل/إشعار دائن — ألغِ التسوية أولًا", 'Invoice has settlements');
            }
            foreach (array_filter([$inv->journal_id, $inv->cogs_journal_id]) as $jid) {
                $this->ledger->reverse($user, $jid, "إلغاء {$inv->number}: {$reason}");
            }
            $inv->update(['status' => 'cancelled', 'cancelled_by' => $user->username, 'cancelled_at' => now(), 'cancel_reason' => $reason]);
            $this->audit->status($user, 'CustomerInvoice', $inv->id, $inv->number, 'issued', 'cancelled', $reason);

            return $this->get($inv->id);
        });
    }

    public function get(string $idOrNumber): array
    {
        $inv = CustomerInvoice::with(['lines' => fn ($q) => $q->orderBy('line_no'), 'lines.product:id,sku,name_ar,name_en', 'customer:id,code,name_ar,name_en,terms,vat_no', 'order:id,number,status', 'journal:id,number', 'creditOf:id,number'])
            ->where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->first()
            ?? throw AppError::notFound('CI_NOT_FOUND', "الفاتورة {$idOrNumber} غير موجودة", 'Invoice not found');
        $row = $inv->toArray();
        $row['balance'] = round((float) $inv->total - (float) $inv->paid_amount, 2);
        $row['overdue'] = in_array($inv->status, CustomerInvoice::OPEN, true) && $inv->due_date && $inv->due_date->lt(now()->startOfDay());
        $row['creditNotes'] = CustomerInvoice::where('credit_of_id', $inv->id)->get(['id', 'number', 'total', 'status', 'invoice_date'])->map->toArray()->all();
        $row['fo'] = $inv->fo_id ? FulfillmentOrder::whereKey($inv->fo_id)->first(['id', 'number', 'status'])?->toArray() : null;

        return $row;
    }

    /** @param  array{status?:?string, kind?:?string, customer?:?string, due?:?string, from?:?string, to?:?string}  $f */
    public function list(Paging $page, array $f): array
    {
        $q = CustomerInvoice::with(['customer:id,code,name_ar,name_en', 'order:id,number'])->withCount('lines');
        if (! empty($f['kind'])) {
            $q->where('kind', $f['kind']);
        }
        if (! empty($f['status'])) {
            $q->whereIn('status', array_map('trim', explode(',', $f['status'])));
        }
        if (! empty($f['customer'])) {
            $q->whereHas('customer', fn ($c) => $c->where('code', $f['customer'])->orWhere('id', $f['customer']));
        }
        if (($f['due'] ?? null) === 'overdue') {
            $q->whereIn('status', CustomerInvoice::OPEN)->where('due_date', '<', now()->startOfDay());
        } elseif (($f['due'] ?? null) === 'week') {
            $q->whereIn('status', CustomerInvoice::OPEN)->whereBetween('due_date', [now()->startOfDay(), now()->startOfDay()->addDays(7)]);
        }
        if (! empty($f['from'])) {
            $q->whereDate('invoice_date', '>=', $f['from']);
        }
        if (! empty($f['to'])) {
            $q->whereDate('invoice_date', '<=', $f['to']);
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('number', 'like', $like)->orWhereHas('customer', fn ($c) => $c->where('name_ar', 'like', $like)->orWhere('name_en', 'like', $like)->orWhere('code', 'like', $like))
                ->orWhereHas('order', fn ($o) => $o->where('number', 'like', $like)));
        }
        $column = match ($page->sort) {
            'dueDate' => 'due_date', 'total' => 'total', 'date' => 'invoice_date', default => 'created_at'
        };
        $q->orderBy($column, $page->order)->orderBy('id', $page->order);

        return $page->paginate($q, function (CustomerInvoice $i) {
            $row = $i->toArray();
            $row['_count'] = ['lines' => (int) $i->lines_count];
            unset($row['linesCount']);
            $row['balance'] = round((float) $i->total - (float) $i->paid_amount, 2);
            $row['overdue'] = in_array($i->status, CustomerInvoice::OPEN, true) && $i->due_date && $i->due_date->lt(now()->startOfDay());

            return $row;
        });
    }

    /** Receivables snapshot for the finance dashboard. */
    public function summary(): array
    {
        $open = CustomerInvoice::where('kind', 'invoice')->whereIn('status', CustomerInvoice::OPEN)->get(['total', 'paid_amount', 'due_date']);
        $today = now()->startOfDay();
        $bal = fn ($i) => (float) $i->total - (float) $i->paid_amount;
        $overdue = $open->filter(fn ($i) => $i->due_date && $i->due_date->lt($today));

        return [
            'openCount' => $open->count(), 'openTotal' => round($open->sum($bal), 2),
            'overdue' => ['count' => $overdue->count(), 'total' => round($overdue->sum($bal), 2)],
            'dueThisWeek' => ['count' => $open->filter(fn ($i) => $i->due_date && $i->due_date->gte($today) && $i->due_date->lt($today->copy()->addDays(7)))->count()],
        ];
    }
}
