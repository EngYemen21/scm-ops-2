<?php

namespace App\Portal\Services;

use App\Finance\Services\FinanceReportsService;
use App\Models\Customer;
use App\Portal\Models\PortalBranch;
use App\Portal\Models\Statement;
use App\Support\AppError;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A statement of account worked out from the OPS receivables — READ ONLY: this class never writes to a finance table.
 *
 * WHAT IS ON IT. Every document that moved what the customer owes, on the day it did:
 *   an invoice (debit) · a credit note (credit) · a receipt (credit) — and, because nothing in the books is deleted,
 *   the cancelling of an invoice or a credit note and the voiding of a receipt as a line of its own on the day it
 *   happened (`reversal`). So the statement of any period agrees with the customer's account in the general ledger on
 *   the same days, and a document that was later cancelled is not silently missing from an earlier period.
 * The opening balance is everything before the first day; the closing balance is opening + debit − credit.
 *
 * A BRANCH STATEMENT is the same for one branch: the invoices and credit notes of the orders delivered to the branch's
 * delivery site (`portal_branches.site_id` → `sales_orders.site_id`) and the part of each receipt that was allocated
 * to those invoices. A receipt, or a part of one, that is allocated to no invoice belongs to no branch: it is on the
 * customer's statement only. A branch without a delivery site has no documents of its own to state.
 *
 * `books` compares a customer statement with the receivable account of the general ledger on its last day
 * (`FinanceReportsService::partnerStatement`): they differ only when the ledger holds something that is not a
 * document of this list — an opening entry, a manual journal — and then the statement says so instead of hiding it.
 */
class StatementBuilder
{
    /** Same-day order of the lines: what is owed first, then what settles it. */
    private const RANK = ['invoice' => 1, 'credit_note' => 2, 'payment' => 3, 'reversal' => 4];

    /**
     * @return array{from:string, to:string, opening:float, totalDebit:float, totalCredit:float, closing:float, currency:string, lines:array<int, array<string, mixed>>,
     *               counts:array<string, int>, books:array{closing:?float, agrees:?bool}}
     */
    public function build(Customer $c, ?PortalBranch $branch, Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();
        $siteId = null;
        if ($branch) {
            $siteId = $branch->site_id ?: throw AppError::rule('PORTAL_BRANCH_NO_SITE',
                "الفرع «{$branch->name}» غير مربوط بموقع تسليم في نظام العمليات — اربطه بموقعه أولًا ليُعرف أي الطلبات والفواتير تخصه",
                "Branch \"{$branch->name}\" is not tied to a delivery site in OPS — tie it to its site first, so that its orders and invoices are known");
        }
        $opening = $this->opening($c->id, $siteId, $from);
        $lines = $this->movements($c->id, $siteId, $from, $to);
        if (count($lines) > Statement::MAX_LINES) {
            throw AppError::rule('PORTAL_STATEMENT_TOO_LARGE', 'الفترة تحمل أكثر من '.number_format(Statement::MAX_LINES).' حركة — اختر فترة أقصر', 'The period holds more than '.number_format(Statement::MAX_LINES).' movements — pick a shorter one',
                ['lines' => count($lines), 'max' => Statement::MAX_LINES]);
        }
        // by day; within a day what is owed before what settles it, and each kind in the order it happened
        usort($lines, fn (array $a, array $b) => [$a['date'], self::RANK[$a['kind']], $a['at'], $a['reference']] <=> [$b['date'], self::RANK[$b['kind']], $b['at'], $b['reference']]);
        $balance = $opening;
        $debit = $credit = 0.0;
        $counts = ['invoice' => 0, 'credit_note' => 0, 'payment' => 0, 'reversal' => 0];
        foreach ($lines as $i => $l) {
            $balance = round($balance + $l['debit'] - $l['credit'], 2);
            $debit = round($debit + $l['debit'], 2);
            $credit = round($credit + $l['credit'], 2);
            $counts[$l['kind']]++;
            $lines[$i]['balance'] = $balance;
            $lines[$i]['seq'] = $i + 1;
        }

        return [
            'from' => $from->toDateString(), 'to' => $to->toDateString(), 'opening' => $opening, 'totalDebit' => $debit, 'totalCredit' => $credit, 'closing' => round($opening + $debit - $credit, 2),
            'currency' => 'SAR', 'lines' => $lines, 'counts' => $counts, 'books' => $branch ? ['closing' => null, 'agrees' => null] : $this->books($c, $from, $to, round($opening + $debit - $credit, 2)),
        ];
    }

    /** What the customer (or the branch) owed before the first day. */
    private function opening(string $customerId, ?string $siteId, Carbon $from): float
    {
        $day = $from->toDateString();
        $sum = 0.0;
        foreach (['invoice' => 1, 'credit_note' => -1] as $kind => $sign) {
            $sum += $sign * (float) $this->invoices($customerId, $siteId)->where('i.kind', $kind)->where('i.invoice_date', '<', $day)->sum('i.total');
            // … of which what was cancelled before the first day is no longer owed
            $sum -= $sign * (float) $this->invoices($customerId, $siteId)->where('i.kind', $kind)->where('i.status', 'cancelled')->whereNotNull('i.cancelled_at')->where('i.cancelled_at', '<', $from)->sum('i.total');
        }
        if ($siteId === null) {
            $sum -= (float) $this->receipts($customerId)->where('p.payment_date', '<', $day)->sum('p.amount');
            $sum += (float) $this->receipts($customerId)->where('p.status', 'void')->whereNotNull('p.voided_at')->where('p.voided_at', '<', $from)->sum('p.amount');
        } else {
            $sum -= (float) $this->allocations($customerId, $siteId)->where('p.payment_date', '<', $day)->sum('a.amount');
            $sum += (float) $this->allocations($customerId, $siteId)->where('p.status', 'void')->whereNotNull('p.voided_at')->where('p.voided_at', '<', $from)->sum('a.amount');
        }

        return round($sum, 2);
    }

    /** @return array<int, array<string, mixed>> the documents of the period, unsorted */
    private function movements(string $customerId, ?string $siteId, Carbon $from, Carbon $to): array
    {
        [$first, $last, $after] = [$from->toDateString(), $to->toDateString(), $to->copy()->addDay()];
        $limit = Statement::MAX_LINES + 1;
        $out = [];
        $columns = ['i.id', 'i.number', 'i.kind', 'i.invoice_date', 'i.total', 'i.status', 'i.created_at', 'i.cancelled_at', 'i.cancel_reason', 'i.notes', 'so.number as so_number'];

        foreach ($this->invoices($customerId, $siteId)->whereBetween('i.invoice_date', [$first, $last])->orderBy('i.invoice_date')->orderBy('i.number')->limit($limit)->get($columns) as $i) {
            $credit = $i->kind === 'credit_note';
            $out[] = self::line(substr((string) $i->invoice_date, 0, 10), $credit ? 'credit_note' : 'invoice', $i->number,
                $credit ? (trim((string) $i->notes) ?: 'إشعار دائن') : ('فاتورة'.($i->so_number ? " — أمر البيع {$i->so_number}" : '')),
                $credit ? 0.0 : (float) $i->total, $credit ? (float) $i->total : 0.0, 'CustomerInvoice', $i->id, (string) $i->created_at);
        }
        foreach ($this->invoices($customerId, $siteId)->where('i.status', 'cancelled')->whereNotNull('i.cancelled_at')->where('i.cancelled_at', '>=', $from)->where('i.cancelled_at', '<', $after)
            ->orderBy('i.cancelled_at')->orderBy('i.number')->limit($limit)->get($columns) as $i) {
            $credit = $i->kind === 'credit_note';
            $out[] = self::line(substr((string) $i->cancelled_at, 0, 10), 'reversal', $i->number,
                ($credit ? 'إلغاء الإشعار الدائن' : 'إلغاء الفاتورة').(trim((string) $i->cancel_reason) !== '' ? ' — '.trim((string) $i->cancel_reason) : ''),
                $credit ? (float) $i->total : 0.0, $credit ? 0.0 : (float) $i->total, 'CustomerInvoice', $i->id, (string) $i->cancelled_at);
        }

        $paid = $siteId === null
            ? $this->receipts($customerId)->select(['p.id', 'p.number', 'p.payment_date', 'p.amount', 'p.method', 'p.reference', 'p.status', 'p.created_at', 'p.voided_at', 'p.void_reason'])
            : $this->allocations($customerId, $siteId)->select(['p.id', 'p.number', 'p.payment_date', 'a.amount', 'p.method', 'p.reference', 'p.status', 'p.created_at', 'p.voided_at', 'p.void_reason', 'i.number as invoice_number']);
        foreach ((clone $paid)->whereBetween('p.payment_date', [$first, $last])->orderBy('p.payment_date')->orderBy('p.number')->limit($limit)->get() as $p) {
            $out[] = self::line(substr((string) $p->payment_date, 0, 10), 'payment', $p->number,
                'سند قبض'.(isset($p->invoice_number) ? " — على الفاتورة {$p->invoice_number}" : '').(trim((string) $p->reference) !== '' ? ' — مرجع '.trim((string) $p->reference) : ''),
                0.0, (float) $p->amount, 'Payment', $p->id, (string) $p->created_at);
        }
        foreach ((clone $paid)->where('p.status', 'void')->whereNotNull('p.voided_at')->where('p.voided_at', '>=', $from)->where('p.voided_at', '<', $after)
            ->orderBy('p.voided_at')->orderBy('p.number')->limit($limit)->get() as $p) {
            $out[] = self::line(substr((string) $p->voided_at, 0, 10), 'reversal', $p->number,
                'إلغاء سند القبض'.(trim((string) $p->void_reason) !== '' ? ' — '.trim((string) $p->void_reason) : ''), (float) $p->amount, 0.0, 'Payment', $p->id, (string) $p->voided_at);
        }

        return $out;
    }

    /** Customer invoices and credit notes — of the whole customer, or of the orders delivered to one site. */
    private function invoices(string $customerId, ?string $siteId): Builder
    {
        $q = DB::table('fin_customer_invoices as i')->leftJoin('sales_orders as so', 'so.id', '=', 'i.so_id')->where('i.customer_id', $customerId);

        return $siteId === null ? $q : $q->where('so.site_id', $siteId);
    }

    /** Money received from the customer. */
    private function receipts(string $customerId): Builder
    {
        return DB::table('fin_payments as p')->where('p.direction', 'in')->where('p.partner_type', 'customer')->where('p.partner_id', $customerId);
    }

    /** The parts of the customer's receipts that settle invoices of the orders delivered to one site. */
    private function allocations(string $customerId, string $siteId): Builder
    {
        return DB::table('fin_payment_allocations as a')->join('fin_payments as p', 'p.id', '=', 'a.payment_id')
            ->join('fin_customer_invoices as i', 'i.id', '=', 'a.invoice_id')->join('sales_orders as so', 'so.id', '=', 'i.so_id')
            ->where('a.invoice_type', 'customer')->where('p.direction', 'in')->where('p.partner_type', 'customer')->where('p.partner_id', $customerId)->where('so.site_id', $siteId);
    }

    /** @return array{closing:?float, agrees:?bool} what the general ledger says the customer owes on the last day */
    private function books(Customer $c, Carbon $from, Carbon $to, float $closing): array
    {
        try {
            $ledger = app(FinanceReportsService::class)->partnerStatement('customer', $c->code, $from->toDateString(), $to->toDateString());
            $books = round((float) $ledger['closing'], 2);

            return ['closing' => $books, 'agrees' => abs($books - $closing) < 0.01];
        } catch (Throwable $e) {
            report($e);

            return ['closing' => null, 'agrees' => null]; // the statement stands on its documents; the comparison is simply not shown
        }
    }

    /** @return array<string, mixed> */
    private static function line(string $date, string $kind, string $reference, string $description, float $debit, float $credit, string $docType, string $docId, string $at): array
    {
        return ['date' => $date, 'kind' => $kind, 'reference' => $reference, 'description' => mb_substr($description, 0, 500), 'debit' => round($debit, 2), 'credit' => round($credit, 2),
            'docType' => $docType, 'docId' => $docId, 'at' => $at];
    }
}
