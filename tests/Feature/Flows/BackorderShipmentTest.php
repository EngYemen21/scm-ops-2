<?php

namespace Tests\Feature\Flows;

use App\Models\AuditLog;
use App\Models\InboundShipment;
use App\Models\InventoryMovement;
use Tests\ApiTestCase;

/**
 * The follow-up ("backorder") shipment: what is still undelivered on a purchase order after a partial receipt — or
 * after the expected shipment was cancelled — must be receivable, exactly once, and never more than what is open.
 */
class BackorderShipmentTest extends ApiTestCase
{
    use FlowFixtures;

    private function receive(string $number, array $lines): array
    {
        $url = "/api/inbound/shipments/{$number}";
        $this->expectOk($this->postAs('wm', "{$url}/arrive", ['carrier' => 'BO-TEST']));
        $this->expectOk($this->postAs('wm', "{$url}/inspect"));

        return $this->expectOk($this->postAs('wm', "{$url}/grn", ['lines' => $lines]));
    }

    public function test_partial_receipt_then_the_rest_arrives_on_a_follow_up_shipment(): void
    {
        $a = $this->product('BO-A');
        $b = $this->product('BO-B');
        [$first, $po] = $this->expectedShipment([[$a, 100], [$b, 40]]);

        // while the first shipment is still waiting for goods, a second one is refused
        $waiting = $this->expectOk($this->getAs('wm', "/api/inbound/shipments/{$first->number}"))['backorder'];
        $this->assertFalse($waiting['allowed']);
        $this->assertSame($first->number, $waiting['shipment']);
        $this->expectRejected($this->postAs('wm', '/api/inbound/shipments/backorder', ['po' => $po->number]), 'SHIPMENT_OPEN_EXISTS', [409]);

        // 60 of 100 arrive (5 of them damaged), line B arrives in full
        $this->receive($first->number, [['lineNo' => 1, 'acceptedQty' => 55, 'damagedQty' => 5], ['lineNo' => 2, 'acceptedQty' => 40]]);
        $this->assertSame('partial', $po->refresh()->status);
        $state = $this->expectOk($this->getAs('wm', "/api/inbound/shipments/{$first->number}"))['backorder'];
        $this->assertSame(['allowed' => true, 'openQty' => 40, 'openLines' => 1], array_intersect_key($state, array_flip(['allowed', 'openQty', 'openLines'])));

        // RBAC: opening a shipment is a receiving action
        $security = AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->count();
        $this->expectRejected($this->postAs('sales', '/api/inbound/shipments/backorder', ['po' => $po->number]), 'FORBIDDEN', [403]);
        $this->assertSame($security + 1, AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->count());
        $this->expectRejected($this->postAs('wm', '/api/inbound/shipments/backorder', []), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('wm', '/api/inbound/shipments/backorder', ['po' => 'PO-NOPE']), 'PO_NOT_FOUND', [404]);

        // the follow-up shipment carries ONLY what is still open: one line, 40 units
        $movements = InventoryMovement::count();
        $second = $this->expectOk($this->postAs('wm', '/api/inbound/shipments/backorder', ['po' => $po->number, 'eta' => '2026-10-05']));
        $this->assertSame('expected', $second['status']);
        $this->assertNotSame($first->number, $second['number']);
        $this->assertCount(1, $second['lines']);
        $this->assertSame($a->sku, $second['lines'][0]['product']['sku']);
        $this->assertSame(40, $second['lines'][0]['orderedQty']);
        $this->assertSame(40, $second['totals']['open']);
        $this->assertStringStartsWith('2026-10-05', (string) $second['eta']);
        $this->assertSame($movements, InventoryMovement::count(), 'opening a shipment moves no stock');
        $this->assertSame(1, AuditLog::where('action', 'SHIPMENT.BACKORDER')->where('entity_number', $second['number'])->count());

        // only one waiting shipment per order
        $this->expectRejected($this->postAs('wm', '/api/inbound/shipments/backorder', ['po' => $po->number]), 'SHIPMENT_OPEN_EXISTS', [409]);

        // no over-receipt on the follow-up either
        $url = "/api/inbound/shipments/{$second['number']}";
        $this->expectOk($this->postAs('wm', "{$url}/arrive"));
        $this->expectOk($this->postAs('wm', "{$url}/inspect"));
        $this->expectRejected($this->postAs('wm', "{$url}/grn", ['lines' => [['lineNo' => 1, 'acceptedQty' => 41]]]), 'OVER_RECEIPT', [422]);
        $this->expectOk($this->postAs('wm', "{$url}/grn", ['lines' => [['lineNo' => 1, 'acceptedQty' => 40]]]));

        // the order is now fully received: nothing left to open
        $this->assertSame('received', $po->refresh()->status);
        $this->assertSame(0, (int) $po->open_qty);
        $this->expectRejected($this->postAs('wm', '/api/inbound/shipments/backorder', ['po' => $po->number]), 'PO_NOTHING_OPEN', [422]);
        $this->assertSame(2, InboundShipment::where('po_id', $po->id)->count());
    }

    public function test_a_cancelled_expected_shipment_can_be_reopened_for_the_full_quantity(): void
    {
        [$first, $po] = $this->expectedShipment([[$this->product('BO-C'), 30]]);
        $this->expectOk($this->postAs('admin', "/api/inbound/shipments/{$first->number}/cancel"));
        $this->assertSame('cancelled', $first->refresh()->status);

        $again = $this->expectOk($this->postAs('wm', '/api/inbound/shipments/backorder', ['po' => $po->number]));
        $this->assertSame(30, $again['lines'][0]['orderedQty']);
        $this->assertSame('expected', $again['status']);
    }

    public function test_the_same_request_twice_opens_one_shipment(): void
    {
        [$first, $po] = $this->expectedShipment([[$this->product('BO-D'), 20]]);
        $this->expectOk($this->postAs('admin', "/api/inbound/shipments/{$first->number}/cancel"));

        $key = 'bo-'.self::uid();
        $one = $this->withHeader('Idempotency-Key', $key)->postAs('wm', '/api/inbound/shipments/backorder', ['po' => $po->number]);
        $two = $this->withHeader('Idempotency-Key', $key)->postAs('wm', '/api/inbound/shipments/backorder', ['po' => $po->number]);
        $this->assertSame($this->expectOk($one)['number'], $this->expectOk($two)['number'], 'a double click replays the first answer');
        $this->assertSame(1, InboundShipment::where('po_id', $po->id)->where('status', 'expected')->count());
    }
}
