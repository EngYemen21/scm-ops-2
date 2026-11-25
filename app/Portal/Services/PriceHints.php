<?php

namespace App\Portal\Services;

use App\Pricing\Services\PriceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The price to start from when a document is made for a customer out of the portal's data (a favourite list ordered
 * again, an order request). A hint, never a decision: the person making the quotation sees where it comes from and
 * types over it, and the sales module applies its own rules to what is sent.
 *
 * Sources, the surest first — all read-only:
 *   0. `pricelist` the customer's price list («قوائم الأسعار»: the price of the product for this customer, one unit);
 *   1. `invoice`   the unit price of the customer's latest invoice that carries the product (what it really paid);
 *   2. `quotation` the unit price of the latest quotation made for the customer that carries the product.
 * A product with none of the three has no hint.
 */
class PriceHints
{
    /** How far back the two sources are read: enough for any customer, bounded for a very busy one. */
    private const ROWS = 4000;

    /**
     * @param  string[]  $productIds
     * @return array<string, array{price:float, source:string, ref:?string, at:?string}> product id → hint
     */
    public function for(string $customerId, array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter($productIds)));
        if (! $productIds) {
            return [];
        }
        $out = $this->fromPriceList($customerId, $productIds);
        $productIds = array_values(array_diff($productIds, array_keys($out)));
        if (! $productIds) {
            return $out;
        }
        $invoiced = DB::table('fin_customer_invoice_lines as l')->join('fin_customer_invoices as i', 'i.id', '=', 'l.invoice_id')
            ->where('i.customer_id', $customerId)->where('i.kind', 'invoice')->where('i.status', '!=', 'cancelled')->whereIn('l.product_id', $productIds)
            ->orderByDesc('i.invoice_date')->orderByDesc('i.id')->limit(self::ROWS)->get(['l.product_id', 'l.price', 'i.number', 'i.invoice_date']);
        foreach ($invoiced as $row) {
            $out[$row->product_id] ??= ['price' => (float) $row->price, 'source' => 'invoice', 'ref' => $row->number, 'at' => substr((string) $row->invoice_date, 0, 10)];
        }
        $missing = array_values(array_diff($productIds, array_keys($out)));
        if ($missing) {
            $quoted = DB::table('quotation_lines as l')->join('quotations as q', 'q.id', '=', 'l.quotation_id')
                ->where('q.customer_id', $customerId)->whereNotIn('q.status', ['rejected'])->whereIn('l.product_id', $missing)
                ->orderByDesc('q.date')->orderByDesc('q.id')->limit(self::ROWS)->get(['l.product_id', 'l.price', 'q.number', 'q.date']);
            foreach ($quoted as $row) {
                $out[$row->product_id] ??= ['price' => (float) $row->price, 'source' => 'quotation', 'ref' => $row->number, 'at' => substr((string) $row->date, 0, 10)];
            }
        }

        return $out;
    }

    /**
     * Source 0, the surest of all where it exists: the customer's price list («قوائم الأسعار») — the price agreed with
     * him, for one unit. A product the list has no price for falls through to what he was invoiced or quoted.
     *
     * @param  string[]  $productIds
     * @return array<string, array{price:float, source:string, ref:?string, at:?string}>
     */
    private function fromPriceList(string $customerId, array $productIds): array
    {
        if (! Schema::hasTable('price_lists')) {
            return []; // the price lists arrive with their own migration
        }
        $out = [];
        foreach (array_chunk($productIds, 200) as $chunk) {
            $answers = app(PriceService::class)->customerPrices($customerId, array_map(fn (string $id) => ['productId' => $id, 'qty' => 1], $chunk));
            foreach ($answers as $i => $a) {
                if (($a['found'] ?? false) && (float) ($a['price'] ?? 0) > 0) {
                    $out[$chunk[$i]] = ['price' => round((float) $a['price'], 2), 'source' => 'pricelist', 'ref' => $a['priceList']['name'] ?? null, 'at' => null];
                }
            }
        }

        return $out;
    }
}
