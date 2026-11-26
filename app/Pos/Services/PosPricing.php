<?php

namespace App\Pos\Services;

use App\Models\Customer;
use App\Models\Product;
use App\Pos\Models\PosRegister;
use App\Pos\Support\Money;
use App\Pricing\Services\PriceService;
use App\Services\Core\SettingsService;
use App\Support\AppError;

/**
 * Prices a cart the one way the counter knows: the unit price of each line is the price service's answer for the
 * customer, the quantity and today (rounded once to the halala, before VAT); the line discount is within what the
 * register allows; the VAT rate is the one of every sales document (`sales.vatPct`), applied once to the document's
 * net. The quote the screen shows and the sale that is recorded come from this same code.
 */
class PosPricing
{
    public function __construct(private readonly PriceService $prices, private readonly SettingsService $settings) {}

    /**
     * @param  array<int, array{productId:string, qty:int|string, discountPct?:float|int|string|null}>  $lines
     * @return array{lines:array<int, array>, subtotal:int, discount:int, vatBp:int, vat:int, total:int, gross:int}
     *                                                                                                              amounts in halalas
     */
    public function price(PosRegister $register, Customer $customer, array $lines): array
    {
        if (! $lines) {
            throw AppError::validation('POS_EMPTY', 'السلة فارغة — أضف صنفًا واحدًا على الأقل', 'The cart is empty');
        }
        $maxBp = Money::bp($register->max_discount_pct ?? 0);
        $products = Product::whereIn('id', array_values(array_unique(array_map(fn ($l) => (string) $l['productId'], $lines))))->get()->keyBy('id');
        $quotes = $this->prices->customerPrices($customer->id, array_map(fn ($l) => ['productId' => (string) $l['productId'], 'qty' => (int) $l['qty']], $lines));
        $out = [];
        $subtotal = $gross = 0;
        foreach (array_values($lines) as $i => $l) {
            $p = $products[(string) $l['productId']] ?? null;
            if (! $p) {
                throw AppError::notFound('PRODUCT_NOT_FOUND', "الصنف {$l['productId']} غير موجود", 'Product not found', ['line' => $i + 1]);
            }
            if (! $p->active) {
                throw AppError::rule('POS_PRODUCT_INACTIVE', "الصنف {$p->sku} موقوف — لا يُباع", "Product {$p->sku} is inactive", ['line' => $i + 1, 'sku' => $p->sku]);
            }
            $qty = $l['qty'];
            if (! is_int($qty) && ! (is_string($qty) && ctype_digit($qty))) {
                throw AppError::validation('POS_QTY', 'الكمية في السطر '.($i + 1).' يجب أن تكون عددًا صحيحًا', 'Quantity must be a whole number', ['line' => $i + 1]);
            }
            $qty = (int) $qty;
            if ($qty < 1 || $qty > 100000) {
                throw AppError::validation('POS_QTY', 'الكمية بين 1 و100000', 'Quantity must be between 1 and 100000', ['line' => $i + 1]);
            }
            $discBp = Money::bp($l['discountPct'] ?? 0);
            if ($discBp < 0 || $discBp > 10000) {
                throw AppError::validation('POS_DISCOUNT', 'الخصم نسبة بين 0 و100', 'Discount is a percentage between 0 and 100', ['line' => $i + 1]);
            }
            if ($discBp > $maxBp) {
                throw AppError::rule('POS_DISCOUNT_LIMIT', 'خصم السطر '.($i + 1).' ('.Money::str($discBp).'%) يتجاوز المسموح في هذا الصندوق ('.Money::str($maxBp).'%)',
                    'Line discount '.Money::str($discBp).'% exceeds the register limit of '.Money::str($maxBp).'%', ['line' => $i + 1, 'max' => Money::float($maxBp)]);
            }
            $q = $quotes[$i] ?? null;
            if (! ($q['found'] ?? false) || $q['price'] === null) {
                throw AppError::rule('POS_NO_PRICE', "لا سعر للصنف {$p->sku} لهذا العميل — أضفه في قائمة الأسعار", "No price for {$p->sku} for this customer", ['line' => $i + 1, 'sku' => $p->sku, 'reason' => $q['reason'] ?? null]);
            }
            $unit = Money::cents($q['price']);   // the price service answers with up to four decimals: rounded once, here
            if ($unit <= 0) {
                throw AppError::rule('POS_NO_PRICE', "سعر الصنف {$p->sku} صفر — لا يُباع بلا سعر", "Price of {$p->sku} is zero", ['line' => $i + 1, 'sku' => $p->sku]);
            }
            $net = Money::lineNet($qty, $unit, $discBp);
            $subtotal += $net;
            $gross += $qty * $unit;
            $out[] = [
                'lineNo' => $i + 1, 'productId' => $p->id, 'sku' => $p->sku, 'nameAr' => $p->name_ar, 'nameEn' => $p->name_en, 'qty' => $qty, 'unitCents' => $unit, 'discountBp' => $discBp,
                'netCents' => $net, 'priceSource' => $q['source'] ?? null, 'priceList' => $q['priceList']['code'] ?? ($q['priceList']['name'] ?? null), 'reason' => $q['reason'] ?? null,
            ];
        }
        $vatBp = Money::bp($this->settings->get('sales.vatPct') ?? 15);
        $vat = Money::vat($subtotal, $vatBp);

        return ['lines' => $out, 'subtotal' => $subtotal, 'discount' => $gross - $subtotal, 'gross' => $gross, 'vatBp' => $vatBp, 'vat' => $vat, 'total' => $subtotal + $vat];
    }

    /** The priced cart as the API speaks it (decimal text). */
    public static function shape(array $priced): array
    {
        return [
            'lines' => array_map(fn ($l) => [
                'lineNo' => $l['lineNo'], 'productId' => $l['productId'], 'sku' => $l['sku'], 'nameAr' => $l['nameAr'], 'nameEn' => $l['nameEn'], 'qty' => $l['qty'],
                'unitPrice' => Money::str($l['unitCents']), 'discountPct' => Money::str($l['discountBp']), 'lineTotal' => Money::str($l['netCents']),
                'lineVat' => Money::str(Money::vat($l['netCents'], $priced['vatBp'])), 'priceSource' => $l['priceSource'], 'priceList' => $l['priceList'], 'reason' => $l['reason'],
            ], $priced['lines']),
            'subtotal' => Money::str($priced['subtotal']), 'discountTotal' => Money::str($priced['discount']), 'vatPct' => Money::str($priced['vatBp']),
            'vatAmount' => Money::str($priced['vat']), 'total' => Money::str($priced['total']),
        ];
    }
}
