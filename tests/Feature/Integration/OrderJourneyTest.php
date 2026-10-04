<?php

namespace Tests\Feature\Integration;

use App\Integration\Models\IntException;
use App\Integration\Models\IntInbox;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\OpsException;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Trip;
use App\Models\TripStop;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\ApiTestCase;
use Tests\Feature\Sales\SalesFixtures;

/**
 * Phase 5–7 — the order journey across both systems, through the real gateway and the real OPS flows:
 * Sales order → availability → reservation (or backorder + procurement) → release → pick → pack → load → dispatch →
 * delivery + POD → customer receipt, with every step reported back to Sales as a signed, ordered, correlated event.
 */
class OrderJourneyTest extends ApiTestCase
{
    use IntegrationTestHelpers;
    use SalesFixtures;

    /** Envelopes OPS delivered to Sales, in order. */
    private array $sent = [];

    private static ?array $world = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableSystems();
        $this->sent = [];
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), 'sales.example.test')) {
                $this->sent[] = json_decode($r->body(), true);

                return Http::response(['results' => [['status' => 'processed']]], 202);
            }

            return Http::response('unexpected', 500);
        });
        self::$world ??= $this->world();
    }

    /** A Sales customer + 4 Sales products mapped to OPS SKUs (one left unmapped). */
    private function world(): array
    {
        $cust = 'C'.self::uid();
        $this->send($this->envelope('customer.created', $cust, ['id' => $cust, 'name' => 'مطاعم الرحلة', 'city' => 'الرياض',
            'branches' => [['key' => "{$cust}:olaya", 'name' => 'فرع العليا', 'address' => 'طريق العليا']]], 1));
        $p = [];
        foreach (['A', 'B', 'C', 'X'] as $k) {
            $ext = "P-{$k}".self::uid();
            $this->send($this->envelope('product.created', $ext, ['id' => $ext, 'name' => "منتج {$k}"], 1));
            $p[$k] = ['ext' => $ext, 'ops' => $this->makeProduct()];
            if ($k !== 'X') {
                $this->expectOk($this->postAs('admin', '/api/integration/mappings/product', ['externalId' => $ext, 'internal' => $p[$k]['ops']->sku]));
            }
        }

        return ['customer' => $cust, 'p' => $p];
    }

    private function p(string $k): array
    {
        return self::$world['p'][$k];
    }

    private function order(string $ref, array $lines, int $seq = 1, array $over = []): array
    {
        return $this->envelope('sales_order.confirmed', $ref, array_merge([
            'id' => $ref, 'customerId' => self::$world['customer'], 'branch' => ['key' => self::$world['customer'].':olaya', 'name' => 'فرع العليا'],
            'lines' => array_map(fn ($l, $i) => ['lineNo' => $i + 1, 'productId' => $this->p($l[0])['ext'], 'qty' => $l[1], 'unitPrice' => 25.5, 'discountPct' => 0], $lines, array_keys($lines)),
            'vatPct' => 15, 'totals' => ['net' => 100, 'vat' => 15, 'gross' => 115], 'priority' => 'normal', 'salesRep' => 'سارة', 'notes' => 'التسليم صباحًا',
        ], $over), $seq);
    }

    private function sentTypes(string $ref): array
    {
        return array_values(array_map(fn ($e) => $e['type'], array_filter($this->sent, fn ($e) => $e['subject'] === $ref)));
    }

    public function test_a_fully_available_order_runs_end_to_end_and_reports_every_step(): void
    {
        $this->stock($this->p('A')['ops'], 'A-01-1-B1', 10);
        $this->stock($this->p('B')['ops'], 'A-01-1-B1', 10);
        $ref = 'ORD-J'.self::uid();
        $confirmed = $this->order($ref, [['A', 5], ['B', 3]]);
        $r = $this->send($confirmed)->json('results.0');
        $this->assertSame(['processed', true, 'full', 'RYD'], [$r['status'], $r['result']['created'], $r['result']['availability'], $r['result']['warehouse']]);
        $so = SalesOrder::where('external_ref', $ref)->first();
        $this->assertSame(['allocated', 'full', 'sales', 'svc.sales'], [$so->status, $so->fulfil_status, $so->source_system, $so->created_by]);
        $this->assertSame([5, 3], SalesOrderLine::where('so_id', $so->id)->orderBy('line_no')->pluck('reserved_qty')->all());
        $this->assertSame('25.50', SalesOrderLine::where('so_id', $so->id)->value('price'), 'the commercial price is Sales\' snapshot');
        $this->assertSame(115, $so->commercial['totals']['gross']);
        $this->assertSame(['order.accepted', 'order.reserved'], $this->sentTypes($ref));
        $this->assertSame([1, 2], array_column(array_values(array_filter($this->sent, fn ($e) => $e['subject'] === $ref)), 'sequence'));
        $this->assertSame([$ref, $ref], array_column(array_values(array_filter($this->sent, fn ($e) => $e['subject'] === $ref)), 'correlationId'));

        // the same order sent again — same event (lost response) or a new event id (Sales retried) — never a second SO
        $this->assertSame('duplicate', $this->send($confirmed)->json('results.0.status'));
        $again = $this->send($this->order($ref, [['A', 5], ['B', 3]], 2))->json('results.0');
        $this->assertSame(['processed', false], [$again['status'], $again['result']['created']]);
        $this->assertSame(1, SalesOrder::where('external_ref', $ref)->count());

        // OPS executes: release → pick → pack → trip → load → dispatch
        $fo = $this->packedOrder($so->number);
        $this->assertSame(['order.accepted', 'order.reserved', 'order.released', 'picking.started', 'picking.completed', 'order.packed'],
            array_values(array_unique($this->sentTypes($ref))));
        $driver = Driver::where('code', 'DRV-04')->firstOrFail(); // the demo user `driver`
        $trip = $this->makeTrip([$fo], $this->makeVehicle(), $driver);
        $this->expectOk($this->postAs('worker', "/api/fulfillment/trips/{$trip->number}/load", ['foNumber' => $fo]));
        $this->expectOk($this->postAs('disp', "/api/fulfillment/trips/{$trip->number}/dispatch"));
        $dispatched = collect($this->sent)->last(fn ($e) => $e['type'] === 'shipment.dispatched' && $e['subject'] === $ref);
        $this->assertSame(['out_for_delivery', $trip->number, $driver->name_ar], [$dispatched['data']['status'], $dispatched['data']['trip'], $dispatched['data']['driver']]);

        // the driver delivers with a proof of delivery
        $stop = TripStop::where('trip_id', $trip->id)->firstOrFail();
        $this->expectOk($this->postAs('driver', "/api/delivery/trips/{$trip->number}/start"));
        $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$stop->id}/arrive", ['gps' => ['lat' => 24.7, 'lng' => 46.7]]));
        $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$stop->id}/deliver", ['receiverName' => 'م. ناصر', 'gps' => ['lat' => 24.7, 'lng' => 46.7], 'signature' => 'sig.png']));
        $done = collect($this->sent)->last(fn ($e) => $e['subject'] === $ref);
        $this->assertSame(['delivery.completed', 'delivered', 'م. ناصر', 8, true], [$done['type'], $done['data']['status'], $done['data']['receiver'], $done['data']['deliveredQty'], $done['data']['gps']]);
        $this->assertSame(range(1, count($this->sentTypes($ref))), array_column(array_values(array_filter($this->sent, fn ($e) => $e['subject'] === $ref)), 'sequence'), 'gap-free, ordered');

        // the customer confirms receipt in Sales: kept with the order; a difference from the POD is flagged
        $rcv = $this->send($this->envelope('sales_order.received', $ref, ['id' => $ref, 'result' => 'short',
            'lines' => [['productId' => $this->p('A')['ext'], 'receivedQty' => 5], ['productId' => $this->p('B')['ext'], 'receivedQty' => 2]]], 3))->json('results.0');
        $this->assertSame(['processed', 1], [$rcv['status'], $rcv['result']['mismatches']]);
        $this->assertTrue(IntException::where('code', 'RECEIPT_MISMATCH')->where('entity_ref', $ref)->where('status', 'open')->exists());

        // Sales can read the whole journey by its own order id
        $view = $this->signed('GET', "/api/v1/orders/{$ref}")->assertOk()->json();
        $this->assertSame(['delivered', $so->number, $trip->number, 'م. ناصر'], [$view['status'], $view['opsOrder'], $view['shipment']['trip'], $view['pod']['receiver']]);
        $this->assertContains('delivered', array_column($view['timeline'], 'status'));
        $this->assertSame('delivery.completed', collect($view['published'])->last()['type']);
        $this->assertReconciles();

        $this->expectOk($this->postAs('disp', "/api/transport/trips/{$trip->number}/close"));
    }

    public function test_a_shortage_reserves_what_exists_backorders_the_rest_and_completes_when_stock_arrives(): void
    {
        $c = $this->p('C')['ops'];
        $this->stock($c, 'A-01-1-B1', 2);
        $ref = 'ORD-S'.self::uid();
        $r = $this->send($this->order($ref, [['C', 5]]))->json('results.0');
        $this->assertSame(['processed', 'partial'], [$r['status'], $r['result']['availability']]);
        $so = SalesOrder::where('external_ref', $ref)->first();
        $this->assertSame(['confirmed', 'partial', 2], [$so->status, $so->fulfil_status, (int) SalesOrderLine::where('so_id', $so->id)->value('reserved_qty')]);
        $this->assertSame(['order.accepted', 'order.backordered', 'procurement.required'], $this->sentTypes($ref));
        $backordered = collect($this->sent)->firstWhere('type', 'order.backordered');
        $this->assertEquals([['productId' => $this->p('C')['ext'], 'missing' => 3]], $backordered['data']['lines']);
        $this->assertTrue(OpsException::where('kind', 'shortage')->where('document_number', $so->number)->where('owner_role', 'proc')->exists(), 'buyers see the shortage');
        // a direct OPS order for the same product cannot take the 2 reserved units
        $this->assertSame(0, app(\App\Services\Inventory\InventoryService::class)->availability($c->id)['total']);

        // stock arrives → the next integration cycle completes the backorder first-come-first-served
        $this->stock($c, 'A-01-1-B1', 10);
        $run = $this->signed('POST', '/api/v1/ops/heartbeat', null, system: 'scheduler', keyId: 's1')->assertOk()->json();
        $this->assertGreaterThanOrEqual(1, $run['backordersCompleted']);
        $this->assertSame(['allocated', 'full'], [$so->refresh()->status, $so->fulfil_status]);
        $reserved = collect($this->sent)->last(fn ($e) => $e['subject'] === $ref);
        $this->assertSame(['order.reserved', true], [$reserved['type'], $reserved['data']['completedBackorder']]);
        $this->assertReconciles();
    }

    public function test_cancellation_releases_stock_before_picking_and_needs_a_person_after(): void
    {
        $a = $this->p('A')['ops'];
        $this->stock($a, 'A-01-1-B1', 20);
        $before = $this->reserved($a);
        $ref = 'ORD-X'.self::uid();
        $this->send($this->order($ref, [['A', 4]]));
        $this->assertSame($before + 4, $this->reserved($a));
        $r = $this->send($this->envelope('sales_order.cancelled', $ref, ['id' => $ref, 'reason' => 'العميل ألغى'], 2))->json('results.0');
        $this->assertSame(['processed', true], [$r['status'], $r['result']['cancelled']]);
        $this->assertSame('cancelled', SalesOrder::where('external_ref', $ref)->value('status'));
        $this->assertSame($before, $this->reserved($a), 'reservation released');
        $this->assertSame('order.cancelled', collect($this->sent)->last(fn ($e) => $e['subject'] === $ref)['type']);
        $this->assertSame('0.00', Customer::where('id', SalesOrder::where('external_ref', $ref)->value('customer_id'))->value('balance'), 'OPS balance untouched: credit is Sales\'');

        // picking started: OPS does not silently cancel — a person decides, Sales is told
        $ref2 = 'ORD-Y'.self::uid();
        $this->send($this->order($ref2, [['A', 2]]));
        $this->packedOrder(SalesOrder::where('external_ref', $ref2)->value('number'));
        $late = $this->send($this->envelope('sales_order.cancelled', $ref2, ['id' => $ref2, 'reason' => 'متأخر'], 2))->json('results.0');
        $this->assertSame(['processed', false], [$late['status'], $late['result']['cancelled']]);
        $this->assertSame('packed', SalesOrder::where('external_ref', $ref2)->value('status'));
        $this->assertSame('crit', IntException::where('code', 'CANCEL_TOO_LATE')->where('entity_ref', $ref2)->value('severity'));
        $this->assertSame('order.cancel_rejected', collect($this->sent)->last(fn ($e) => $e['subject'] === $ref2)['type']);
    }

    public function test_orders_wait_for_missing_mappings_and_respect_event_order(): void
    {
        // an unmapped product parks the order; mapping it replays the order automatically
        $this->stock($this->p('X')['ops'], 'A-01-1-B1', 5);
        $ref = 'ORD-M'.self::uid();
        $r = $this->send($this->order($ref, [['X', 1], ['A', 1]]))->json('results.0');
        $this->assertSame(['blocked', 'PRODUCT_UNMAPPED'], [$r['status'], $r['code']]);
        $this->assertSame(0, SalesOrder::where('external_ref', $ref)->count());
        $this->assertTrue(IntException::where('code', 'PRODUCT_UNMAPPED')->where('entity_ref', $this->p('X')['ext'])->where('status', 'open')->exists());
        $m = $this->expectOk($this->postAs('admin', '/api/integration/mappings/product', ['externalId' => $this->p('X')['ext'], 'internal' => $this->p('X')['ops']->sku]));
        $this->assertGreaterThanOrEqual(1, $m['replayed']);
        $this->assertSame(1, SalesOrder::where('external_ref', $ref)->count());

        // the customer's own event arrives after its first order: the order waits, then goes through
        $cust = 'C'.self::uid().'n';
        $ref2 = 'ORD-N'.self::uid();
        $parked = $this->send($this->order($ref2, [['A', 1]], 1, ['customerId' => $cust, 'branch' => null]))->json('results.0');
        $this->assertSame(['blocked', 'CUSTOMER_UNMAPPED'], [$parked['status'], $parked['code']]);
        $this->send($this->envelope('customer.created', $cust, ['id' => $cust, 'name' => 'عميل متأخر'], 1));
        $this->assertSame('processed', IntInbox::where('subject', $ref2)->value('status'));
        $this->assertSame(1, SalesOrder::where('external_ref', $ref2)->count());

        // the cancellation overtakes the confirmation: nothing is created, the late confirmation is stale
        $ref3 = 'ORD-O'.self::uid();
        $c3 = $this->send($this->envelope('sales_order.cancelled', $ref3, ['id' => $ref3], 2))->json('results.0');
        $this->assertSame(['processed', true], [$c3['status'], $c3['result']['cancelled']]);
        $this->assertSame('stale', $this->send($this->order($ref3, [['A', 1]], 1))->json('results.0.status'));
        $this->assertSame(0, SalesOrder::where('external_ref', $ref3)->count());

        // malformed orders are rejected with the reason
        $bad = $this->send($this->order('ORD-BAD'.self::uid(), [['A', 1]], 1, ['lines' => [['productId' => 'P-A', 'qty' => 0, 'unitPrice' => 1]]]))->json('results.0');
        $this->assertSame(['rejected', 'ORDER_LINE_INVALID'], [$bad['status'], $bad['code']]);
        $dup = $this->send($this->order('ORD-DUP'.self::uid(), [['A', 1], ['A', 2]]))->json('results.0');
        $this->assertSame(['rejected', 'ORDER_LINE_DUPLICATE'], [$dup['status'], $dup['code']]);

        // Sales may read only through the signed API
        $this->getJson("/api/v1/orders/{$ref}")->assertStatus(401);
        $this->signed('GET', '/api/v1/orders/ORD-NEVER')->assertStatus(404)->assertJsonPath('code', 'ORDER_NOT_FOUND');
    }

    public function test_sales_reads_available_to_promise_by_its_own_product_ids(): void
    {
        $b = $this->p('B')['ops'];
        $this->stock($b, 'A-01-1-B1', 7);
        $this->stock($b, 'A-01-1-B1', 3, 5);           // expires in 5 days → near expiry
        $this->stock($b, 'A-01-1-B1', 4, null, true);  // quarantined → not sellable
        $res = $this->signed('GET', '/api/v1/inventory/availability?products='.$this->p('B')['ext'].',P-UNKNOWN')->assertOk()->json();
        [$row, $unknown] = $res['items'];
        $this->assertSame([true, $b->sku], [$row['mapped'], $row['sku']]);
        $this->assertSame($row['available'], $row['atp']);
        $this->assertGreaterThanOrEqual(10, $row['available']);
        $this->assertGreaterThanOrEqual(4, $row['quarantine']);
        $this->assertGreaterThanOrEqual(3, $row['nearExpiry']);
        $this->assertSame(['productId' => 'P-UNKNOWN', 'mapped' => false], $unknown);
        $this->signed('GET', '/api/v1/inventory/availability?products=')->assertStatus(400)->assertJsonPath('code', 'PRODUCTS_REQUIRED');
        $this->signed('GET', '/api/v1/inventory/availability?products=X', system: 'scheduler', keyId: 's1')->assertStatus(403);
    }
}
