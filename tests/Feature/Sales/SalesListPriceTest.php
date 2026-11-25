<?php

namespace Tests\Feature\Sales;

use App\Models\Quotation;
use Tests\ApiTestCase;
use Tests\Feature\Pricing\PricingFixtures;

/**
 * Sales documents and the price lists: a line sent without a price takes the customer's list price; a price a person
 * typed always wins; a product the list has no price for is refused, and the person types the price.
 */
class SalesListPriceTest extends ApiTestCase
{
    use PricingFixtures;

    public function test_a_line_without_a_price_takes_the_customers_list_price(): void
    {
        $this->followOdoo(false);
        $customer = $this->customer('مطعم قائمة الأسعار');
        $listed = $this->product('أرز بسمتي 10 كجم');
        $unlisted = $this->product('صنف بلا سعر');
        $this->listPriceOf($listed, 184.5);
        $body = fn (array $lines) => ['customerCode' => $customer->code, 'validUntil' => now()->addDays(5)->toDateString(), 'lines' => $lines];

        // no price on the line: the price list answers
        $qt = $this->expectOk($this->postAs('sales', '/api/sales/quotations', $body([['sku' => $listed->sku, 'qty' => 4]])));
        $line = Quotation::where('number', $qt['number'])->firstOrFail()->lines()->firstOrFail();
        $this->assertSame([184.5, 4], [(float) $line->price, (int) $line->qty]);

        // a typed price always wins over the list
        $qt = $this->expectOk($this->postAs('sales', '/api/sales/quotations', $body([['sku' => $listed->sku, 'qty' => 4, 'price' => 170]])));
        $this->assertSame(170.0, (float) Quotation::where('number', $qt['number'])->firstOrFail()->lines()->firstOrFail()->price);

        // the list has nothing for the product: refused by line, nothing saved, and a typed price saves it
        $before = Quotation::count();
        $refused = $this->postAs('sales', '/api/sales/quotations', $body([['sku' => $listed->sku, 'qty' => 1], ['sku' => $unlisted->sku, 'qty' => 2]]));
        $this->expectRejected($refused, 'PRICE_REQUIRED', [400]);
        $this->assertSame('lines.1.price', $refused->json('details.field'));
        $this->assertSame($before, Quotation::count());
        $this->expectOk($this->postAs('sales', '/api/sales/quotations', $body([['sku' => $unlisted->sku, 'qty' => 2, 'price' => 12.25]])));

        // a price that is sent must still be a real one
        $this->expectRejected($this->postAs('sales', '/api/sales/quotations', $body([['sku' => $listed->sku, 'qty' => 1, 'price' => 0]])), 'INVALID_INPUT', [400]);
    }
}
