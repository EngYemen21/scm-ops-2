<?php

namespace App\Commissions\Services;

use App\Commissions\Support\Doc;
use App\Commissions\Support\Shape;
use App\Finance\Models\CustomerInvoice;
use App\Finance\Models\CustomerInvoiceLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the commission engine reads of the finance module: customer invoices, the credit notes that take part of them
 * back, and when an invoice was fully settled. READ ONLY — the documents are the finance module's
 * (`fin_customer_invoices`, `fin_customer_invoice_lines`, `fin_payments`, `fin_payment_allocations`).
 *
 *   invoiced    an invoice counts on its own date, as soon as it is issued and for as long as it is not cancelled;
 *   collected   an invoice counts on the day of its last settlement — a posted receipt or a credit note — once it is
 *               fully paid (`status = paid`); a partly paid invoice has earned nothing yet.
 */
class Documents
{
    /** How far back a calculation looks for documents no statement has taken yet (late invoices, late collections). */
    public const LOOK_BACK_MONTHS = 12;

    private const CHUNK = 1000;

    /**
     * The invoices that count on a day of [$lower, $upper] (`Y-m-d`, both included).
     *
     * @return array<string, Doc> by invoice id
     */
    public function invoices(string $earnOn, string $lower, string $upper): array
    {
        if ($earnOn !== 'collected') {
            return $this->docs(CustomerInvoice::where('kind', 'invoice')->where('status', '!=', 'cancelled')
                ->whereDate('invoice_date', '>=', $lower)->whereDate('invoice_date', '<=', $upper)->orderBy('invoice_date')->orderBy('number')->get());
        }
        // something settled them in the window: a posted receipt, or a credit note
        $ids = DB::table('fin_payment_allocations as a')->join('fin_payments as p', 'p.id', '=', 'a.payment_id')
            ->where('a.invoice_type', 'customer')->where('p.status', 'posted')->whereDate('p.payment_date', '>=', $lower)->whereDate('p.payment_date', '<=', $upper)
            ->distinct()->pluck('a.invoice_id')->all();
        $ids = array_merge($ids, CustomerInvoice::where('kind', 'credit_note')->where('status', '!=', 'cancelled')->whereNotNull('credit_of_id')
            ->whereDate('invoice_date', '>=', $lower)->whereDate('invoice_date', '<=', $upper)->pluck('credit_of_id')->all());
        $ids = array_values(array_unique(array_map('strval', $ids)));
        $rows = new Collection;
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $rows = $rows->concat(CustomerInvoice::whereIn('id', $chunk)->where('kind', 'invoice')->where('status', 'paid')->get());
        }
        // … and the LAST settlement is in the window: one dated later means the invoice was not fully paid by then
        $settled = $this->settledOn($rows->pluck('id')->all());
        $rows = $rows->filter(function (CustomerInvoice $i) use ($settled, $lower, $upper) {
            $day = $settled[$i->id] ?? Shape::day($i->invoice_date);

            return $day >= $lower && $day <= $upper;
        })->sortBy(fn (CustomerInvoice $i) => ($settled[$i->id] ?? Shape::day($i->invoice_date)).'|'.$i->number)->values();

        return $this->docs($rows, $settled);
    }

    /**
     * The credit notes (not cancelled, dated on or before `$upper`) of these invoices.
     *
     * @param  string[]  $invoiceIds
     * @return array<string, Doc> by credit note id
     */
    public function creditNotesOf(array $invoiceIds, string $upper): array
    {
        $rows = new Collection;
        foreach (array_chunk(array_values(array_unique($invoiceIds)), self::CHUNK) as $chunk) {
            $rows = $rows->concat(CustomerInvoice::where('kind', 'credit_note')->where('status', '!=', 'cancelled')->whereIn('credit_of_id', $chunk)
                ->whereDate('invoice_date', '<=', $upper)->orderBy('invoice_date')->orderBy('number')->get());
        }

        return $this->docs($rows);
    }

    /**
     * The credit notes (not cancelled) dated in [$lower, $upper], whatever invoice they credit.
     *
     * @return array<string, Doc> by credit note id
     */
    public function creditNotesIn(string $lower, string $upper): array
    {
        return $this->docs(CustomerInvoice::where('kind', 'credit_note')->where('status', '!=', 'cancelled')->whereNotNull('credit_of_id')
            ->whereDate('invoice_date', '>=', $lower)->whereDate('invoice_date', '<=', $upper)->orderBy('invoice_date')->orderBy('number')->get());
    }

    /**
     * What a document is now — for the lines of earlier statements that may no longer hold.
     *
     * @param  string[]  $ids
     * @return array<string, array{kind:string, status:string, creditOfId:?string}>
     */
    public function states(array $ids): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($ids)), self::CHUNK) as $chunk) {
            foreach (CustomerInvoice::whereIn('id', $chunk)->get(['id', 'kind', 'status', 'credit_of_id']) as $i) {
                $out[(string) $i->id] = ['kind' => (string) $i->kind, 'status' => (string) $i->status, 'creditOfId' => $i->credit_of_id ? (string) $i->credit_of_id : null];
            }
        }

        return $out;
    }

    /**
     * The day of the last settlement of each invoice: its latest posted receipt or credit note.
     *
     * @param  string[]  $invoiceIds
     * @return array<string, string> invoice id → `Y-m-d` (an invoice nothing settled is absent)
     */
    public function settledOn(array $invoiceIds): array
    {
        $out = [];
        $keep = function (iterable $rows) use (&$out) {
            foreach ($rows as $r) {
                $day = Shape::day(is_string($r->d) ? $r->d : (string) $r->d);
                if ($day !== null && $day > ($out[(string) $r->id] ?? '')) {
                    $out[(string) $r->id] = $day;
                }
            }
        };
        foreach (array_chunk(array_values(array_unique($invoiceIds)), self::CHUNK) as $chunk) {
            $keep(DB::table('fin_payment_allocations as a')->join('fin_payments as p', 'p.id', '=', 'a.payment_id')
                ->where('a.invoice_type', 'customer')->where('p.status', 'posted')->whereIn('a.invoice_id', $chunk)
                ->groupBy('a.invoice_id')->selectRaw('a.invoice_id as id, max(p.payment_date) as d')->get());
            $keep(DB::table('fin_customer_invoices')->where('kind', 'credit_note')->where('status', '!=', 'cancelled')->whereIn('credit_of_id', $chunk)
                ->groupBy('credit_of_id')->selectRaw('credit_of_id as id, max(invoice_date) as d')->get());
        }

        return $out;
    }

    /**
     * @param  Collection<int, CustomerInvoice>  $rows
     * @param  array<string, string>  $settled  invoice id → the day it counts (absent = its own date)
     * @return array<string, Doc>
     */
    private function docs(Collection $rows, array $settled = []): array
    {
        if ($rows->isEmpty()) {
            return [];
        }
        $lines = [];
        $products = [];
        foreach (array_chunk($rows->pluck('id')->all(), self::CHUNK) as $chunk) {
            foreach (CustomerInvoiceLine::whereIn('invoice_id', $chunk)->orderBy('line_no')->get(['invoice_id', 'product_id', 'line_total', 'cogs']) as $l) {
                $lines[(string) $l->invoice_id][] = ['productId' => (string) $l->product_id, 'categoryId' => null, 'net' => (float) $l->line_total, 'cogs' => (float) $l->cogs];
                $products[(string) $l->product_id] = true;
            }
        }
        $categories = [];
        foreach (array_chunk(array_keys($products), self::CHUNK) as $chunk) {
            $categories += DB::table('products')->whereIn('id', $chunk)->pluck('category_id', 'id')->all();
        }
        $out = [];
        foreach ($rows as $i) {
            $id = (string) $i->id;
            $day = Shape::day($i->invoice_date);
            $out[$id] = new Doc($id, (string) $i->kind, (string) $i->number, $day, $settled[$id] ?? $day, (string) $i->customer_id,
                $i->so_id ? (string) $i->so_id : null, $i->credit_of_id ? (string) $i->credit_of_id : null, (string) $i->status,
                (float) $i->subtotal, (float) $i->vat_amount, (float) $i->total, (float) $i->cogs_total,
                array_map(fn (array $l) => ['categoryId' => isset($categories[$l['productId']]) ? (string) $categories[$l['productId']] : null] + $l, $lines[$id] ?? []));
        }

        return $out;
    }
}
