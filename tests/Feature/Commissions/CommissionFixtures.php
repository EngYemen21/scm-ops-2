<?php

namespace Tests\Feature\Commissions;

use App\Finance\Models\CustomerInvoice;
use App\Finance\Services\CustomerInvoiceService;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProofOfDelivery;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Flows\FlowFixtures;
use Tests\Feature\Flows\ReturnsFixtures;

/**
 * What the commission tests start from. The DOCUMENTS ARE REAL: an invoice is issued by the finance module's own
 * service exactly as a proof of delivery does it, a receipt goes through `POST /finance/receipts`, a credit note comes
 * out of the returns flow. Only two things are arranged by hand, because the tests need them in a given month: who
 * entered the order (or which representative the sales platform named on it), and the DATE of an invoice / credit note.
 *
 * Every test works in months of its own with people of its own, and its plans are valid in those months only — so
 * what one test calculates never reaches the documents of another.
 */
trait CommissionFixtures
{
    use FlowFixtures;
    use ReturnsFixtures;

    protected const API = '/api/commissions';

    protected function userId(string $username): string
    {
        return (string) User::where('username', $username)->value('id');
    }

    /** A salesperson who exists for this test only (he earns; he never signs in). */
    protected function person(string $tag): User
    {
        $name = strtolower(self::code("cm-{$tag}"));

        return User::create(['username' => $name, 'email' => "{$name}@example.test", 'password_hash' => 'x', 'name_ar' => "مندوب {$tag}", 'name_en' => "Rep {$tag}", 'active' => true])->refresh();
    }

    protected function customer(string $tag): Customer
    {
        $code = self::code("CMC-{$tag}");

        return Customer::create(['code' => $code, 'name_ar' => "عميل عمولات {$code}", 'name_en' => "Commission customer {$code}", 'city' => 'الرياض', 'zone' => 'شمال الرياض'])->refresh();
    }

    protected function category(string $tag, ?ProductCategory $parent = null): ProductCategory
    {
        $code = strtolower(self::code("cmcat-{$tag}"));

        return ProductCategory::create(['code' => $code, 'name_ar' => "فئة {$tag}", 'name_en' => "Category {$tag}", 'parent_id' => $parent?->id, 'active' => true])->refresh();
    }

    protected function item(string $tag, ?ProductCategory $category = null): Product
    {
        return Product::create(['sku' => self::code("CM-{$tag}"), 'name_ar' => "صنف عمولات {$tag}", 'name_en' => "Commission item {$tag}", 'storage_class' => 'ambient', 'tracks_expiry' => false, 'category_id' => $category?->id]);
    }

    /**
     * A delivered and invoiced order of this customer. The invoice is the one the finance module issues from a proof of
     * delivery: quantity × the order's price, VAT 15 %, the cost of goods taken from the load.
     *
     * @param  array<int, array{0: Product, 1: int, 2?: float, 3?: float}>  $lines  [product, quantity, unit price = 10, unit cost = 6]
     * @param  array{by?: ?string, rep?: mixed, date?: ?string}  $o  by: the user who entered the order · rep: the representative the sales platform named · date: the invoice's date
     */
    protected function invoiced(Customer $customer, array $lines, array $o = []): CustomerInvoice
    {
        $wh = $this->ryd();
        foreach ($lines as $l) {
            $this->stockAt($l[0], 'A-07-1-B1', $l[1], (float) ($l[3] ?? 6.0));
        }
        [$trip, $stops, $fos] = $this->dispatchedTrip($this->demoDriver(), [array_map(fn (array $l) => [$l[0], $l[1]], $lines)]);
        $by = ! empty($o['by']) ? User::where('username', $o['by'])->firstOrFail() : null;
        $so = SalesOrder::findOrFail($fos[0]->so_id);
        $so->update(['customer_id' => $customer->id, 'created_by_id' => $by?->id, 'created_by' => $by?->username, 'commercial' => array_key_exists('rep', $o) ? ['salesRep' => $o['rep']] : null]);
        foreach ($lines as $i => $l) {
            SalesOrderLine::where('so_id', $so->id)->where('line_no', $i + 1)->update(['price' => $l[2] ?? 10]);
        }
        $fos[0]->update(['customer_id' => $customer->id]);
        $stops[0]->update(['customer_id' => $customer->id, 'customer_ar' => $customer->name_ar, 'customer_en' => $customer->name_en]);
        $fo = $fos[0]->refresh()->load(['lines.soLine', 'lines.product']);
        $invoice = DB::transaction(function () use ($wh, $trip, $stops, $fo) {
            foreach ($fo->lines as $line) {
                $this->inv()->post(null, null, ['type' => 'load', 'productId' => $line->product_id, 'qty' => $line->qty,
                    'from' => ['warehouseId' => $wh->id, 'binId' => $this->inv()->bin($wh->id, 'A-07-1-B1')->id], 'reference' => ['type' => 'FulfillmentOrder', 'id' => $fo->id, 'number' => $fo->number]]);
            }
            $pod = ProofOfDelivery::create(['number' => self::code('POD-T'), 'trip_id' => $trip->id, 'stop_id' => $stops[0]->id, 'fo_id' => $fo->id, 'customer_id' => $fo->customer_id,
                'result' => 'delivered', 'delivered_qty' => (int) $fo->lines->sum('qty'), 'returned_qty' => 0]);

            return $this->app->make(CustomerInvoiceService::class)->issueFromDelivery($this->actor('driver'), $fo, $pod, $fo->lines->mapWithKeys(fn ($l) => [$l->id => $l->qty])->all());
        });
        $fo->update(['status' => 'delivered']);
        $trip->update(['status' => 'closed', 'closed_at' => now()]);
        if (! empty($o['date'])) {
            CustomerInvoice::whereKey($invoice->id)->update(['invoice_date' => $o['date'], 'due_date' => $o['date']]);   // the month under test
        }

        return $invoice->refresh();
    }

    /** A posted customer receipt, allocated to the invoice, on the given day. */
    protected function collect(CustomerInvoice $invoice, float $amount, string $date): array
    {
        return $this->expectOk($this->postAs('finance', '/api/finance/receipts', ['customer' => Customer::findOrFail($invoice->customer_id)->code, 'amount' => $amount, 'date' => $date,
            'bankAccount' => 'BANK', 'allocations' => [['invoice' => $invoice->number, 'amount' => $amount]]]));
    }

    /** A credit note of the invoice, as the returns flow issues it when the goods come back; dated the given day. */
    protected function creditNote(CustomerInvoice $invoice, Product $product, int $qty, ?string $date = null): CustomerInvoice
    {
        $r = $this->expectOk($this->postAs('sales', '/api/returns', ['type' => 'cust', 'source' => 'عميل', 'warehouseCode' => 'RYD', 'reasonCode' => 'cust_reject', 'invoiceNumber' => $invoice->number,
            'lines' => [['sku' => $product->sku, 'qty' => $qty]]]));
        $this->expectOk($this->postAs('wm', "/api/returns/{$r['number']}/approve"));
        $received = $this->expectOk($this->postAs('wm', "/api/returns/{$r['number']}/receive", ['lines' => [['lineNo' => 1, 'receivedQty' => $qty]]]));
        $note = CustomerInvoice::where('number', $received['creditNote']['number'])->firstOrFail();
        if ($date) {
            CustomerInvoice::whereKey($note->id)->update(['invoice_date' => $date, 'due_date' => $date]);
        }

        return $note->refresh();
    }

    /** A plan as the manager types it; `$over` replaces the defaults (salespeople, on invoices, before tax, 2 %). */
    protected function planBody(array $over = []): array
    {
        return $over + ['nameAr' => 'خطة اختبار '.self::code('P'), 'earnerType' => 'salesperson', 'everyone' => false, 'members' => [], 'earnOn' => 'invoiced', 'base' => 'net',
            'netOfReturns' => true, 'validFrom' => '2026-08-01', 'validTo' => '2026-08-31', 'rules' => [['scope' => 'all', 'rateType' => 'flat', 'rate' => 2]]];
    }

    protected function draftPlan(array $over = []): array
    {
        return $this->expectOk($this->postAs('finance', self::API.'/plans', $this->planBody($over)));
    }

    protected function activePlan(array $over = []): array
    {
        return $this->expectOk($this->postAs('finance', self::API.'/plans/'.$this->draftPlan($over)['code'].'/activate'));
    }

    /** The manager calculates a month. */
    protected function calc(string $period, ?string $beneficiary = null): array
    {
        return $this->expectOk($this->postAs('finance', self::API.'/calculate', ['period' => $period] + ($beneficiary ? ['beneficiary' => $beneficiary] : [])));
    }

    /** What a run says about one earner's statement. */
    protected function outcomeOf(array $run, string $userId): ?array
    {
        return collect($run['statements'])->first(fn (array $s) => ($s['beneficiary']['id'] ?? null) === $userId);
    }

    /** One earner's statement of a month, as the manager reads it. */
    protected function statementOf(string $userId, string $period): ?array
    {
        $found = $this->expectOk($this->getAs('finance', self::API."/statements?beneficiary={$userId}&period={$period}"))['items'];

        return $found ? $this->expectOk($this->getAs('finance', self::API.'/statements/'.$found[0]['number'])) : null;
    }

    /** The lines of a statement, by document number: [kind, base, rate, commission] (two rules on one document: one entry per rule, in rule order). */
    protected function linesOf(array $statement, string $as = 'finance'): array
    {
        return $this->expectOk($this->getAs($as, self::API."/statements/{$statement['number']}/lines?pageSize=200"))['items'];
    }

    /** `[document number => commission]` of a statement's lines (a document with several lines: their sum). */
    protected function commissionByDocument(array $statement): array
    {
        $out = [];
        foreach ($this->linesOf($statement) as $l) {
            $out[$l['docNumber']] = round(($out[$l['docNumber']] ?? 0) + (float) $l['commission'], 2);
        }

        return $out;
    }
}
