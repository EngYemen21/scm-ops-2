<?php

namespace Tests\Feature\Sales;

use Tests\ApiTestCase;

/** Read-only checks on the imported demo documents (ids are the old system's cuids): every read endpoint serves them. */
class SeededDocumentsTest extends ApiTestCase
{
    public function test_lists_share_the_paging_envelope_and_are_open_to_every_signed_in_user(): void
    {
        foreach (['/api/sales/quotations', '/api/sales/orders', '/api/sales/consolidations', '/api/fulfillment/orders', '/api/fulfillment/pick-lists'] as $url) {
            $body = $this->expectOk($this->getAs('driver', $url.'?pageSize=3&page=1'));
            $this->assertSame(['items', 'total', 'page', 'pageSize', 'pages'], array_keys($body), $url);
            $this->assertLessThanOrEqual(3, count($body['items']), $url);
            $this->assertSame(3, $body['pageSize']);
        }
        $this->assertSame(401, $this->getJson('/api/sales/orders')->getStatusCode());
    }

    public function test_seeded_documents_are_served_by_number(): void
    {
        $qt = $this->expectOk($this->getAs('sales', '/api/sales/quotations/QT-2026-00036'));
        $this->assertArrayHasKey('total', $qt['totals']);
        $this->assertNotEmpty($qt['lines']);
        $this->assertArrayHasKey('history', $qt);

        $fo = $this->expectOk($this->getAs('worker', '/api/fulfillment/orders/FO-3311'));
        $this->assertNotEmpty($fo['pickLists'][0]['tasks']);
        $this->assertSame('TRP-2026-0031', $fo['trip']['number']);
        $so = $this->expectOk($this->getAs('sales', "/api/sales/orders/{$fo['so']['number']}"));
        $this->assertContains('FO-3311', array_column($so['fos'], 'number'));
        $this->assertSame(['sub', 'vatPct', 'vat', 'total'], array_keys($so['totals']));

        $oc = $this->expectOk($this->getAs('disp', '/api/sales/consolidations/OC-2026-00007'));
        $this->assertNotEmpty($oc['orders']);

        $plan = $this->expectOk($this->getAs('disp', '/api/fulfillment/trips/TRP-2026-0031/loading'));
        $this->assertSame(['trip', 'rows', 'totals', 'nextToLoad'], array_keys($plan));
        $this->assertSame(['stopSeq', 'customer', 'fo', 'status', 'loaded', 'loadedAt', 'cartons', 'kg', 'cbm', 'packages', 'ready'], array_keys($plan['rows'][0]));
        $this->assertSame(['kg', 'cbm', 'loadedKg', 'loadedCbm', 'utilKg', 'utilCbm'], array_keys($plan['totals']));
        $seqs = array_column($plan['rows'], 'stopSeq');
        $sorted = $seqs;
        rsort($sorted);
        $this->assertSame($sorted, $seqs, 'last stop first');
    }
}
