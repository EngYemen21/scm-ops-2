<?php

namespace App\Planning\Services;

use App\Planning\Engine\Num;
use App\Pricing\Services\PriceService;
use App\Support\AppError;

/**
 * From whom a product is bought, how long it takes and what it costs — always through the price service of the
 * pricing section (`PriceService::bestSupplierPrice` / `supplierPrice`), never a second copy of its rules.
 *
 * PRICE, in this order, and the source says which:
 *   vendor         the best supplier price that holds on the anchor day (the supplier price lists);
 *   supplier_link  the price kept on the product's link to a supplier (the price service's own first fallback);
 *   last_purchase  the last price paid for it on a purchase order (OPS, then Odoo's history);
 *   cost           the product's own purchase price;
 *   none           nothing known — the purchase is planned without a value and cannot be funded by a budget.
 * LEAD TIME: the chosen supplier's (its price line, else the supplier's own) when the run uses vendor lead times and one
 * is known; otherwise the run's default lead time.
 * VENDOR MINIMUM: when every price of the product starts above one unit, the smallest of those starting quantities
 * (the price is then quoted for that quantity); else the starting quantity of the chosen price.
 */
class PriceReader
{
    public function __construct(private readonly PriceService $prices) {}

    /**
     * @param  array{price:float, day:?string, origin:string, ref:?string}|null  $last
     * @return array{vendor:?array{id:string, code:?string, name:?string}, price:?float, priceSource:string, priceReason:array{ar:string,en:string},
     *               leadDays:float, leadSource:string, moq:float, quotedQty:float}
     */
    public function quote(string $productId, string $day, bool $vendorLead, float $defaultLead, ?array $last, ?object $product): array
    {
        try {
            $q = $this->prices->bestSupplierPrice($productId, 1, $day, true);
            $quotedQty = 1.0;
            if (! $q['found']) {
                $starts = array_map(fn ($o) => (float) ($o['minQty'] ?? 0), array_filter((array) ($q['others'] ?? []), fn ($o) => ($o['outcome'] ?? null) === 'belowMinQty'));
                if ($starts) {
                    $quotedQty = min($starts);
                    $q = $this->prices->bestSupplierPrice($productId, $quotedQty, $day, true);
                }
            }
        } catch (AppError) {
            $q = ['found' => false, 'source' => 'none', 'supplier' => null, 'leadDays' => null, 'best' => null, 'price' => null];
            $quotedQty = 1.0;
        }
        $vendor = ! empty($q['supplier']['id']) ? ['id' => (string) $q['supplier']['id'], 'code' => $q['supplier']['code'] ?? null, 'name' => $q['supplier']['nameAr'] ?? ($q['supplier']['nameEn'] ?? null)] : null;
        $moq = $q['found'] && $q['source'] === 'supplier_price' ? max($quotedQty > 1 ? $quotedQty : 0.0, (float) ($q['best']['minQty'] ?? 0)) : 0.0;
        [$price, $source, $reason] = match (true) {
            $q['found'] && $q['source'] === 'supplier_price' => [(float) $q['price'], 'vendor', $q['reason'] ?? ['ar' => 'قائمة أسعار المورد', 'en' => 'Supplier price list']],
            $q['found'] && $q['source'] === 'product_supplier' => [(float) $q['price'], 'supplier_link', $q['reason']],
            $last !== null && $last['price'] > 0 => [(float) $last['price'], 'last_purchase', ['ar' => 'آخر سعر شراء'.($last['ref'] ? " ({$last['ref']})" : '').($last['day'] ? " في {$last['day']}" : ''),
                'en' => 'Last purchase price'.($last['ref'] ? " ({$last['ref']})" : '').($last['day'] ? " on {$last['day']}" : '')]],
            $product && $product->purchase_price !== null && (float) $product->purchase_price > 0 => [(float) $product->purchase_price, 'cost', ['ar' => 'سعر الشراء المسجل على المنتج', 'en' => "The product's own purchase price"]],
            default => [null, 'none', ['ar' => 'لا سعر شراء معروف لهذا المنتج', 'en' => 'No purchase price is known for this product']],
        };
        $supplierLead = $q['found'] ? ($q['leadDays'] ?? null) : null;
        $useVendor = $vendorLead && $supplierLead !== null;

        return [
            'vendor' => $vendor, 'price' => $price === null ? null : Num::p($price), 'priceSource' => $source, 'priceReason' => $reason,
            'leadDays' => $useVendor ? (float) $supplierLead : max(0.0, $defaultLead), 'leadSource' => $useVendor ? 'vendor' : 'default', 'moq' => Num::q($moq), 'quotedQty' => $quotedQty,
        ];
    }

    /** The price of the chosen vendor for the quantity actually bought (a tier may apply); null = keep the first price. */
    public function priceFor(string $vendorId, string $productId, float $qty, string $day): ?float
    {
        try {
            $q = $this->prices->supplierPrice($vendorId, $productId, max(1.0, $qty), $day, false);
        } catch (AppError) {
            return null;
        }

        return $q['found'] && $q['source'] === 'supplier_price' ? Num::p((float) $q['price']) : null;
    }
}
