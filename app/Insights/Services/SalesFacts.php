<?php

namespace App\Insights\Services;

use App\Finance\Models\CustomerInvoice;
use App\Insights\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The sales of products to customers, line by line, from the two systems that sold them — the ONE source of every
 * brand figure and alert:
 *   OPS   posted customer invoices and credit notes (`fin_customer_invoices` not cancelled + their lines) dated AFTER
 *         the cutover day — the same documents and signs FinanceReportsService::profitability() uses;
 *   Odoo  the posted customer invoices and credit notes of the copy (`ins_odoo_sale_lines`, OdooHistory) dated ON OR
 *         BEFORE the cutover day.
 * One sale is therefore never counted twice. The cutover day is set by a holder of `brandowner.manage`
 * (`ins_settings` history.cutover); left unset it is the day before OPS's first customer invoice — or today when OPS
 * has none yet (then Odoo is the whole history).
 *
 * Amounts are before VAT and after discount; a credit note is negative. Margin (= amount − cost of sales) exists for
 * OPS lines only: Odoo's invoice lines carry no cost.
 */
class SalesFacts
{
    /** @return array{date:string, auto:bool} */
    public function cutover(): array
    {
        $set = Settings::get(Settings::CUTOVER);
        if (is_string($set) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $set)) {
            return ['date' => $set, 'auto' => false];
        }
        $first = CustomerInvoice::where('status', '!=', 'cancelled')->min('invoice_date');

        return ['date' => $first ? Carbon::parse($first)->subDay()->toDateString() : now()->toDateString(), 'auto' => true];
    }

    /** @return array<string, string> Odoo id (text) → OPS id, for `entity` (customer | product) restricted to `$opsIds` */
    public function refs(string $entity, array $opsIds): array
    {
        if (! $opsIds) {
            return [];
        }
        $out = [];
        foreach (array_chunk($opsIds, 1000) as $chunk) {
            foreach (DB::table('int_external_refs')->where('system', 'odoo')->where('entity', $entity)->whereIn('internal_id', $chunk)->get(['external_id', 'internal_id']) as $r) {
                $out[(string) $r->external_id] = (string) $r->internal_id;
            }
        }

        return $out;
    }

    /**
     * Every line of the range, both systems, newest first.
     *
     * @param  string[]  $productIds  OPS product ids (null = every product)
     * @param  string[]  $customerIds  OPS customer ids
     * @param  array{kind?:?string, source?:?string}  $f  kind invoice | credit_note; source ops | odoo
     * @return array<int, array{source:string, date:string, documentId:string, number:string, kind:string, customerId:string, productId:?string, productName:?string, qty:float, amount:float, cogs:?float}>
     */
    public function lines(?array $productIds, array $customerIds, string $from, string $to, array $f = []): array
    {
        if (! $customerIds || ($productIds !== null && ! $productIds)) {
            return [];
        }
        $cut = $this->cutover()['date'];
        $out = [];
        $kind = $f['kind'] ?? null;
        $source = $f['source'] ?? null;
        if ($source !== 'odoo' && $to > $cut) {
            $q = DB::table('fin_customer_invoice_lines as l')->join('fin_customer_invoices as i', 'i.id', '=', 'l.invoice_id')
                ->where('i.status', '!=', 'cancelled')->where('i.invoice_date', '>', $cut)->whereBetween('i.invoice_date', [$from, $to])
                ->whereIn('i.customer_id', $customerIds)
                ->when($productIds !== null, fn ($w) => $w->whereIn('l.product_id', $productIds))
                ->when($kind, fn ($w) => $w->where('i.kind', $kind))
                ->select(['i.id', 'i.number', 'i.kind', 'i.invoice_date', 'i.customer_id', 'l.product_id', 'l.line_no', 'l.qty', 'l.line_total', 'l.cogs']);
            foreach ($q->get() as $r) {
                $sign = $r->kind === 'credit_note' ? -1 : 1;
                $out[] = ['source' => 'ops', 'date' => substr((string) $r->invoice_date, 0, 10), 'documentId' => (string) $r->id, 'number' => (string) $r->number, 'kind' => (string) $r->kind,
                    'customerId' => (string) $r->customer_id, 'productId' => (string) $r->product_id, 'productName' => null, 'line' => (int) $r->line_no,
                    'qty' => (float) ($sign * $r->qty), 'amount' => round($sign * (float) $r->line_total, 2), 'cogs' => round($sign * (float) $r->cogs, 2)];
            }
        }
        if ($source !== 'ops' && $from <= $cut) {
            $partners = $this->refs('customer', $customerIds);
            $products = $productIds === null ? null : $this->refs('product', $productIds);
            if ($partners && ($products === null || $products)) {
                $q = DB::table('ins_odoo_sale_lines as s')->leftJoin('ins_odoo_invoices as m', 'm.odoo_id', '=', 's.move_id')
                    ->where('s.state', 'posted')->whereIn('s.move_type', OdooHistory::TYPES)->whereBetween('s.invoice_date', [$from, min($to, $cut)])
                    ->whereIn('s.partner_ref', array_map('strval', array_keys($partners)))
                    ->when($products !== null, fn ($w) => $w->whereIn('s.product_ref', array_map('strval', array_keys($products))))
                    ->when($kind, fn ($w) => $w->where('s.move_type', $kind === 'credit_note' ? 'out_refund' : 'out_invoice'))
                    ->select(['s.odoo_id', 's.move_id', 's.move_type', 's.invoice_date', 's.partner_ref', 's.product_ref', 's.product_name', 's.qty', 's.amount', 'm.number']);
                foreach ($q->get() as $r) {
                    $out[] = ['source' => 'odoo', 'date' => substr((string) $r->invoice_date, 0, 10), 'documentId' => (string) $r->move_id, 'number' => (string) ($r->number ?? "#{$r->move_id}"),
                        'kind' => $r->move_type === 'out_refund' ? 'credit_note' : 'invoice', 'customerId' => $partners[(string) $r->partner_ref], 'productId' => $products === null ? null : ($products[(string) $r->product_ref] ?? null),
                        'productName' => $r->product_name, 'line' => (int) $r->odoo_id, 'qty' => (float) $r->qty, 'amount' => round((float) $r->amount, 2), 'cogs' => null];
                }
            }
        }
        usort($out, fn ($a, $b) => [$b['date'], $b['number'], $a['line']] <=> [$a['date'], $a['number'], $b['line']]);

        return $out;
    }

    /** The last day each customer bought (an invoice line, either system), over all time. @return array<string, string> customer → Y-m-d */
    public function lastPurchase(array $productIds, array $customerIds): array
    {
        $out = [];
        foreach ($this->lines($productIds, $customerIds, '2000-01-01', now()->toDateString(), ['kind' => 'invoice']) as $l) {
            if ($l['qty'] > 0 && $l['date'] > ($out[$l['customerId']] ?? '')) {
                $out[$l['customerId']] = $l['date'];
            }
        }

        return $out;
    }
}
