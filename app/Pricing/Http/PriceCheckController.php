<?php

namespace App\Pricing\Http;

use App\Http\Controllers\Controller;
use App\Pricing\Services\PriceService;
use App\Pricing\Services\PricingOverview;
use App\Pricing\Support\Cost;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Http\Request;

/**
 * The price service over HTTP — the price check of the page, and what the quotation and purchase forms call to
 * propose a price:
 *   GET  price            ?customer=&product=&qty=&date=   (or ?list= instead of a customer) → the price and why
 *   POST prices           { customer, date?, lines: [{ sku | productId, qty }] }             → one answer per line
 *   GET  supplier-price   ?product=&qty=&date=&supplier=                                     → the best supplier price and every offer
 * plus the lookups and the figures of the page. Reading only.
 */
class PriceCheckController extends Controller
{
    private const DAY = 'date_format:Y-m-d';

    public function __construct(private readonly PriceService $prices, private readonly PricingOverview $overview) {}

    public function lookups(): array
    {
        return $this->overview->lookups(AuthUser::current());
    }

    public function summary(): array
    {
        return $this->overview->summary(AuthUser::current());
    }

    public function price(Request $request): array
    {
        $data = $request->validate([
            'customer' => 'nullable|string|max:120', 'list' => 'nullable|string|max:60', 'product' => 'required|string|min:1|max:120',
            'qty' => 'nullable|numeric|gt:0|max:99999999', 'date' => ['nullable', self::DAY],
        ]);
        $qty = (float) ($data['qty'] ?? 1);
        if (! empty($data['list'])) {
            $out = $this->prices->listPrice($data['list'], $data['product'], $qty, $data['date'] ?? null, true);
        } elseif (! empty($data['customer'])) {
            $out = $this->prices->customerPrice($data['customer'], $data['product'], $qty, $data['date'] ?? null, true);
        } else {
            throw AppError::validation('CUSTOMER_REQUIRED', 'حدد العميل أو قائمة الأسعار', 'Pick the customer or the price list', [['path' => 'customer', 'message' => 'هذا الحقل إلزامي']]);
        }
        // a rule that starts from the cost shows its figure only to those who may see what the company pays
        if (! Cost::visible(AuthUser::current())) {
            $out['steps'] = array_map(fn (array $s) => $s['kind'] === 'cost' ? ['value' => null, 'hidden' => true] + $s : $s, $out['steps'] ?? []);
        }

        return $out;
    }

    public function prices(Request $request): array
    {
        $data = $request->validate([
            'customer' => 'required|string|min:1|max:120', 'date' => ['nullable', self::DAY], 'lines' => 'required|array|min:1|max:300',
            'lines.*.sku' => 'required_without:lines.*.productId|nullable|string|max:120', 'lines.*.productId' => 'nullable|string|max:60', 'lines.*.qty' => 'nullable|numeric|gt:0|max:99999999',
        ]);

        return ['customer' => $data['customer'], 'items' => $this->prices->customerPrices($data['customer'], $data['lines'], $data['date'] ?? null)];
    }

    public function supplierPrice(Request $request): array
    {
        Cost::assertVisible(AuthUser::current());
        $data = $request->validate([
            'product' => 'required|string|min:1|max:120', 'supplier' => 'nullable|string|max:120', 'qty' => 'nullable|numeric|gt:0|max:99999999', 'date' => ['nullable', self::DAY],
            'fallback' => 'nullable|in:0,1,true,false',
        ]);
        $fallback = ! in_array($data['fallback'] ?? '1', ['0', 'false', 0, false], true);
        $qty = (float) ($data['qty'] ?? 1);

        return empty($data['supplier'])
            ? $this->prices->bestSupplierPrice($data['product'], $qty, $data['date'] ?? null, $fallback)
            : $this->prices->supplierPrice($data['supplier'], $data['product'], $qty, $data['date'] ?? null, $fallback);
    }
}
