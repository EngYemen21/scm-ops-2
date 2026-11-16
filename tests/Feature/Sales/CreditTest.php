<?php

namespace Tests\Feature\Sales;

use App\Finance\Models\CustomerInvoice;
use App\Finance\Services\CustomerInvoiceService;
use App\Models\Customer;
use App\Models\FulfillmentOrder;
use App\Models\ProofOfDelivery;
use App\Models\ReturnLine;
use App\Models\ReturnOrder;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\TripStop;
use App\Services\Core\SettingsService;
use App\Services\Sales\CreditService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/**
 * Customer credit (2026-11): customers.balance is the customer's exposure — unpaid invoices + accepted orders not yet
 * delivered — rewritten whenever one of those changes. It used to be a counter that only grew: receipts never lowered
 * it, so a customer who had paid everything still hit the credit limit.
 */
class CreditTest extends ApiTestCase
{
    use SalesFixtures;

    private function balance(Customer $c): string
    {
        return Customer::whereKey($c->id)->value('balance');
    }

    public function test_the_balance_follows_orders_invoices_receipts_and_their_reversals(): void
    {
        $customer = $this->makeCustomer(10000);
        $product = $this->makeProduct();
        $this->stock($product, 'A-07-1-B1', 100);
        $card = fn () => $this->expectOk($this->getAs('sales', "/api/customers/{$customer->code}"));
        $this->assertEquals(['limit' => 10000, 'receivables' => 0, 'openOrders' => 0, 'exposure' => 0, 'available' => 10000, 'limited' => true], $card()['credit']);

        $invoice = $this->receivable($customer, 3000);
        $this->assertSame('3000.00', $this->balance($customer));
        $so = $this->expectOk($this->orderFor($customer, [['sku' => $product->sku, 'qty' => 10, 'price' => 100]]));
        $this->assertSame('4150.00', $this->balance($customer));
        // the customer card: limit · receivables · open orders · available
        $seen = $card();
        $this->assertEquals(['limit' => 10000, 'receivables' => 3000, 'openOrders' => 1150, 'exposure' => 4150, 'available' => 5850, 'limited' => true], $seen['credit']);
        $this->assertEquals([4150, 5850], [$seen['balance'], $seen['availableCredit']]);
        $row = collect($this->expectOk($this->getAs('sales', "/api/customers?q={$customer->code}"))['items'])->firstWhere('code', $customer->code);
        $this->assertEquals([4150, 5850], [$row['balance'], $row['availableCredit']]);

        // a receipt lowers it; voiding the receipt brings the debt back
        $receipt = $this->expectOk($this->postAs('finance', '/api/finance/receipts', ['customer' => $customer->code, 'amount' => 1000, 'reference' => 'DEP-1', 'allocations' => [['invoice' => $invoice->number, 'amount' => 1000]]]));
        $this->assertSame('3150.00', $this->balance($customer));
        $this->expectOk($this->postAs('finance', "/api/finance/payments/{$receipt['number']}/void", ['reason' => 'قيد خاطئ للاختبار']));
        $this->assertSame('4150.00', $this->balance($customer));
        $this->expectOk($this->postAs('finance', '/api/finance/receipts', ['customer' => $customer->code, 'amount' => 3000, 'reference' => 'DEP-2', 'allocations' => [['invoice' => $invoice->number, 'amount' => 3000]]]));
        $this->assertSame('1150.00', $this->balance($customer), 'a paid invoice is no longer owed');

        // a cancelled order is no longer owed; nor a cancelled invoice
        $this->expectOk($this->postAs('sales', "/api/sales/orders/{$so['number']}/cancel"));
        $this->assertSame('0.00', $this->balance($customer));
        $wrong = $this->receivable($customer, 500);
        $this->assertSame('500.00', $this->balance($customer));
        $this->expectOk($this->postAs('finance', "/api/finance/invoices/{$wrong->number}/cancel", ['reason' => 'فاتورة صدرت بالخطأ']));
        $this->assertSame('0.00', $this->balance($customer));
        $this->assertEquals(10000, $card()['credit']['available']);
        $this->assertReconciles();
    }

    public function test_a_delivered_order_becomes_a_receivable_once_and_a_credit_note_lowers_it(): void
    {
        $customer = $this->makeCustomer(10000);
        $product = $this->makeProduct();
        $product->update(['purchase_price' => 0]); // no cost of goods: this story is about what the customer owes
        $this->stock($product, 'A-07-1-B2', 20);
        $so = $this->expectOk($this->orderFor($customer, [['sku' => $product->sku, 'qty' => 4, 'price' => 100]]));
        $this->assertSame('460.00', $this->balance($customer));
        $foNumber = $this->expectOk($this->postAs('wm', "/api/sales/orders/{$so['number']}/fulfill"))['number'];
        $trip = $this->makeTrip([$foNumber], $this->makeVehicle(), $this->makeDriver());
        $fo = FulfillmentOrder::with('lines.soLine')->where('number', $foNumber)->firstOrFail();

        // what the delivery does: the order is delivered, then finance issues the invoice in the same transaction
        $invoice = DB::transaction(function () use ($so, $fo, $trip, $customer) {
            SalesOrder::whereKey($so['id'])->update(['status' => 'delivered']);
            $fo->update(['status' => 'delivered']);
            $pod = ProofOfDelivery::create(['number' => self::code('POD-T'), 'trip_id' => $trip->id, 'stop_id' => TripStop::where('trip_id', $trip->id)->value('id'), 'fo_id' => $fo->id,
                'customer_id' => $customer->id, 'result' => 'delivered', 'delivered_qty' => 4, 'returned_qty' => 0]);

            return $this->app->make(CustomerInvoiceService::class)->issueFromDelivery(null, $fo, $pod, [$fo->lines[0]->id => 4]);
        });
        $this->assertSame(['issued', '460.00'], [$invoice->status, $invoice->total]);
        $x = $this->app->make(CreditService::class)->exposure($customer->id);
        $this->assertEquals([460, 0, 460], [$x['receivables'], $x['openOrders'], $x['exposure']], 'the order left the open orders the moment it became an invoice');
        $this->assertSame('460.00', $this->balance($customer), 'owed once: not as an order and as an invoice');

        // one unit comes back after delivery: the credit note lowers what is owed
        $credit = DB::transaction(function () use ($fo, $customer, $product) {
            $rtn = ReturnOrder::create(['number' => self::code('RTN-T'), 'type' => 'cust', 'customer_id' => $customer->id, 'fo_id' => $fo->id, 'warehouse_id' => $fo->warehouse_id, 'status' => 'received', 'attachments' => []]);
            ReturnLine::create(['return_id' => $rtn->id, 'line_no' => 1, 'product_id' => $product->id, 'qty' => 1]);

            return $this->app->make(CustomerInvoiceService::class)->creditFromReturn(null, $rtn->load('lines'));
        });
        $this->assertSame(['credit_note', '115.00'], [$credit->kind, $credit->total]);
        $this->assertSame('partially_paid', CustomerInvoice::whereKey($invoice->id)->value('status'));
        $this->assertSame('345.00', $this->balance($customer));
    }

    public function test_orders_of_another_system_are_not_counted_and_the_sync_command_repairs_a_wrong_balance(): void
    {
        $customer = $this->makeCustomer(5000);
        $product = $this->makeProduct();
        $this->receivable($customer, 700);
        // an order received from the Sales platform: credit is that system's decision, OPS does not count it
        $foreign = SalesOrder::create(['number' => self::code('SO-T'), 'customer_id' => $customer->id, 'warehouse_id' => $this->ryd()->id, 'status' => 'confirmed', 'source_system' => 'sales', 'external_ref' => self::code('ORD')]);
        SalesOrderLine::create(['so_id' => $foreign->id, 'line_no' => 1, 'product_id' => $product->id, 'qty' => 50, 'price' => 100]);
        $this->assertSame(700.0, $this->app->make(CreditService::class)->sync($customer->id));
        $this->assertSame('700.00', $this->balance($customer));

        // the column as the old code left it: a figure nobody can explain
        Customer::whereKey($customer->id)->update(['balance' => 99999]);
        $this->assertSame(0, Artisan::call('scm:credit-sync'));
        $out = Artisan::output();
        $this->assertStringContainsString("{$customer->code}: 99999 → 700", $out);
        $this->assertSame('700.00', $this->balance($customer));
        // a second run has nothing to do for this customer
        $this->assertSame(0, Artisan::call('scm:credit-sync'));
        $this->assertStringNotContainsString($customer->code, Artisan::output());
    }

    public function test_a_zero_credit_limit_means_what_the_policy_says(): void
    {
        $customer = $this->makeCustomer(0);
        $product = $this->makeProduct();
        $this->stock($product, 'A-07-1-B3', 50);
        $invoice = $this->receivable($customer, 400);
        $order = fn () => $this->orderFor($customer, [['sku' => $product->sku, 'qty' => 1, 'price' => 100]]);

        // default: 0 = not limited
        $this->assertTrue($this->expectOk($this->getAs('sales', '/api/sales/policy'))['zeroCreditLimitUnlimited']);
        $this->assertEquals([null, false], [$this->expectOk($this->getAs('sales', "/api/customers/{$customer->code}"))['credit']['available'], $this->expectOk($this->getAs('sales', "/api/customers/{$customer->code}"))['credit']['limited']]);
        $this->expectOk($order());

        // policy off: 0 = no credit — refused while anything is unpaid, accepted once it is settled
        $settings = $this->app->make(SettingsService::class);
        $settings->set('sales.zeroCreditLimitUnlimited', false);
        try {
            $this->assertFalse($this->expectOk($this->getAs('sales', '/api/sales/policy'))['zeroCreditLimitUnlimited']);
            $this->assertTrue($this->expectOk($this->getAs('sales', "/api/customers/{$customer->code}"))['credit']['limited']);
            $body = $this->expectRejected($order(), 'CREDIT_NONE', [422]);
            $this->assertEquals(400, $body['details']['receivables']);
            $this->assertSame(1, SalesOrder::where('customer_id', $customer->id)->count());
            $this->expectOk($this->postAs('finance', '/api/finance/receipts', ['customer' => $customer->code, 'amount' => 400, 'reference' => 'CASH-0', 'allocations' => [['invoice' => $invoice->number, 'amount' => 400]]]));
            $this->expectOk($order());
        } finally {
            $settings->set('sales.zeroCreditLimitUnlimited', true);
        }
        $this->assertReconciles();
    }

    public function test_credit_terms_are_not_tightened_under_what_the_customer_owes(): void
    {
        $c = $this->expectOk($this->postAs('sales', '/api/customers', ['nameAr' => 'عميل ائتمان '.self::uid(), 'zone' => 'العليا', 'contact' => '05x', 'cr' => '4040'.self::uid(), 'terms' => 'آجل 30', 'creditLimit' => 20000]));
        $customer = Customer::findOrFail($c['id']);
        $url = "/api/customers/{$customer->code}";
        $invoice = $this->receivable($customer, 6000);
        $product = $this->makeProduct();
        $this->stock($product, 'A-07-1-B4', 20);
        $this->expectOk($this->orderFor($customer, [['sku' => $product->sku, 'qty' => 10, 'price' => 100]])); // open order 1,150 → exposure 7,150

        // no switch to cash terms or to a zero limit while something is owed (0 would mean "not limited" under the default policy)
        $cash = $this->expectRejected($this->patchAs('sales', $url, ['terms' => 'نقدي / محفظة', 'creditLimit' => 0]), 'CUSTOMER_HAS_BALANCE', [422]);
        $this->assertEquals(['exposure' => 7150, 'receivables' => 6000, 'openOrders' => 1150], $cash['details']);
        $this->expectRejected($this->patchAs('sales', $url, ['terms' => 'نقدي / محفظة']), 'CUSTOMER_HAS_BALANCE', [422]);
        // a credit customer still needs a positive limit (the older rule answers first)
        $this->expectRejected($this->patchAs('sales', $url, ['creditLimit' => 0]), 'CUSTOMER_CREDIT_LIMIT', [422]);
        // lowering the limit under the exposure is refused with the real figure — not the stored one
        Customer::whereKey($customer->id)->update(['balance' => 1]);
        $low = $this->expectRejected($this->patchAs('sales', $url, ['creditLimit' => 7000]), 'CUSTOMER_LIMIT_BELOW_BALANCE', [422]);
        $this->assertEquals([7150, 7000], [$low['details']['exposure'], $low['details']['limit']]);
        $this->assertStringContainsString('7150', $low['message']);
        $this->assertEquals(20000, Customer::find($customer->id)->credit_limit);
        // lowering it to what is owed or above, and everything else, is fine
        $this->expectOk($this->patchAs('sales', $url, ['creditLimit' => 7150]));
        $this->expectOk($this->patchAs('sales', $url, ['creditLimit' => 7150, 'city' => 'الدمام']));
        $this->expectOk($this->patchAs('sales', $url, ['creditLimit' => 9000]));

        // once nothing is owed the customer may become a cash customer
        $this->expectOk($this->postAs('finance', '/api/finance/receipts', ['customer' => $customer->code, 'amount' => 6000, 'reference' => 'DEP-9', 'allocations' => [['invoice' => $invoice->number, 'amount' => 6000]]]));
        $this->expectRejected($this->patchAs('sales', $url, ['terms' => 'نقدي / محفظة', 'creditLimit' => 0]), 'CUSTOMER_HAS_BALANCE', [422]); // the open order is still owed
        $this->expectOk($this->postAs('sales', '/api/sales/orders/'.SalesOrder::where('customer_id', $customer->id)->value('number').'/cancel'));
        $this->assertSame(2, $this->expectOk($this->patchAs('sales', $url, ['terms' => 'نقدي / محفظة', 'creditLimit' => 0]))['changed']);
        $this->assertReconciles();
    }

    public function test_money_on_account_lowers_what_is_owed_and_invoices_of_another_system_are_not_counted(): void
    {
        $customer = $this->makeCustomer(10000);
        $invoice = $this->receivable($customer, 3000);
        $credit = fn () => $this->app->make(CreditService::class)->exposure($customer->id);
        $receipt = fn (float $amount, string $ref, array $allocations = []) => $this->expectOk($this->postAs('finance', '/api/finance/receipts',
            ['customer' => $customer->code, 'amount' => $amount, 'reference' => $ref] + ($allocations ? ['allocations' => $allocations] : [])));
        $this->assertSame('3000.00', $this->balance($customer));

        // a receipt on account — recorded, allocated to no invoice — is the customer's money already
        $advance = $receipt(1000, 'ADV-1');
        $this->assertSame(['1000.00', '0.00'], [$advance['amount'], $advance['allocatedAmount']]);
        $this->assertEquals([2000, 2000, 8000], [$credit()['receivables'], $credit()['exposure'], $credit()['available']]);
        $this->assertSame('2000.00', $this->balance($customer), 'recording the receipt rewrote the balance');

        // partly allocated: 500 settles the invoice, only the other 300 is on account — nothing is counted twice
        $receipt(800, 'ADV-2', [['invoice' => $invoice->number, 'amount' => 500]]);
        $this->assertSame('1200.00', $this->balance($customer), '2,500 open − (1,000 + 300) on account');

        // a voided receipt is no money: the debt it covered is back
        $this->expectOk($this->postAs('finance', "/api/finance/payments/{$advance['number']}/void", ['reason' => 'سند مكرر للاختبار']));
        $this->assertSame('2200.00', $this->balance($customer));

        // more on account than is owed: receivables stop at zero, and the surplus does not pay for open orders
        $receipt(5000, 'ADV-3');
        $this->assertEquals([0, 0, 10000], [$credit()['receivables'], $credit()['exposure'], $credit()['available']]);
        $product = $this->makeProduct();
        $this->stock($product, 'A-07-1-B1', 20);
        $this->expectOk($this->orderFor($customer, [['sku' => $product->sku, 'qty' => 10, 'price' => 100]]));
        $this->assertEquals([0, 1150, 1150], [$credit()['receivables'], $credit()['openOrders'], $credit()['exposure']]);
        $this->assertSame('1150.00', $this->balance($customer));

        // the invoice of an order ANOTHER system sent is that system's to collect: its customer keeps a zero balance here
        $theirs = $this->makeCustomer(5000);
        $order = fn (?string $source) => SalesOrder::create(['number' => self::code('SO-T'), 'customer_id' => $theirs->id, 'warehouse_id' => $this->ryd()->id, 'status' => 'delivered',
            'source_system' => $source, 'external_ref' => $source ? self::code('ORD') : null]);
        $invoiced = function (float $amount, ?SalesOrder $so) use ($theirs) {
            $inv = $this->receivable($theirs, $amount);
            $inv->update(['so_id' => $so?->id]);

            return $this->app->make(CreditService::class)->sync($theirs->id);
        };
        $this->assertSame(0.0, $invoiced(234.60, $order('sales')));
        $this->assertSame('0.00', $this->balance($theirs), 'OPS balance untouched: credit is the other system\'s');
        // an invoice of an OPS order, and one with no order at all, are owed here
        $this->assertSame(100.0, $invoiced(100, $order(null)));
        $this->assertSame(350.0, $invoiced(250, null));
        $this->assertEquals([350, 4650], [$this->app->make(CreditService::class)->exposure($theirs->id)['receivables'], $this->app->make(CreditService::class)->exposure($theirs->id)['available']]);
        $this->assertReconciles();
    }
}
