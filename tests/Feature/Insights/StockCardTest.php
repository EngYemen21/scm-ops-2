<?php

namespace Tests\Feature\Insights;

use Tests\ApiTestCase;
use Tests\Feature\Inventory\InventoryFixtures;

/**
 * The stock card of one of this story's products: opening, in, out, running balance and closing — for the company and
 * for one warehouse — agreeing with the inventory balances; internal moves shown only on request; export and print.
 */
class StockCardTest extends ApiTestCase
{
    use InsightsFixtures;
    use InventoryFixtures;

    public function test_opening_in_out_balance_and_closing(): void
    {
        $tag = self::tag();
        $ryd = $this->makeZone('RYD', "S{$tag}", ["S{$tag}-1", "S{$tag}-2"]);
        $jed = $this->makeZone('JED', "S{$tag}", ["S{$tag}-J"]);
        ['product' => $p, 'batch' => $b] = $this->makeProduct("SC{$tag}");
        $this->stock($p, $b, $ryd['wh'], $ryd['bins']["S{$tag}-1"], 40, "OPEN-{$tag}");
        $this->expectOk($this->postAs('wm', '/api/inventory/move', ['sku' => $p->sku, 'warehouseCode' => 'RYD', 'fromBin' => "S{$tag}-1", 'toBin' => "S{$tag}-2", 'batchNo' => $b->batch_no, 'qty' => 5, 'reason' => 'slot']));
        $this->expectOk($this->postAs('wm', '/api/inventory/adjust', ['sku' => $p->sku, 'warehouseCode' => 'RYD', 'binCode' => "S{$tag}-2", 'batchNo' => $b->batch_no, 'qtyDelta' => -3, 'reason' => 'تالف']));
        $this->stock($p, $b, $jed['wh'], $jed['bins']["S{$tag}-J"], 7, "OPEN-J-{$tag}");
        $today = now()->toDateString();

        $all = $this->expectOk($this->getAs('wm', "/api/insights/stock-card?sku={$p->sku}&from={$today}&to={$today}"));
        $this->assertSame([0, 47, 3, 44, 44], [$all['opening'], $all['in'], $all['out'], $all['closing'], $all['onHandNow']]);
        $this->assertSame(3, $all['total'], 'the move between two bins is internal and hidden');
        $this->assertSame([40, 37, 44], array_column($all['items'], 'balance'));
        $this->assertSame([40, 0, 7], array_column($all['items'], 'in'));
        $this->assertSame("OPEN-{$tag}", $all['items'][0]['reference']['number']);
        $withMoves = $this->expectOk($this->getAs('wm', "/api/insights/stock-card?sku={$p->sku}&from={$today}&to={$today}&internal=1"));
        $this->assertSame(4, $withMoves['total']);
        $this->assertSame([0, 0], [$withMoves['items'][1]['in'], $withMoves['items'][1]['out']]);

        $r = $this->expectOk($this->getAs('wm', "/api/insights/stock-card?sku={$p->sku}&warehouse=RYD&from={$today}&to={$today}"));
        $this->assertSame([0, 40, 3, 37, 37], [$r['opening'], $r['in'], $r['out'], $r['closing'], $r['onHandNow']]);
        $j = $this->expectOk($this->getAs('wm', "/api/insights/stock-card?sku={$p->sku}&warehouse=JED&from={$today}&to={$today}"));
        $this->assertSame([7, 7], [$j['closing'], $j['onHandNow']]);
        // a later period opens with what the earlier one closed
        $tomorrow = now()->addDay()->toDateString();
        $next = $this->expectOk($this->getAs('wm', "/api/insights/stock-card?sku={$p->sku}&from={$tomorrow}&to={$tomorrow}"));
        $this->assertSame([44, 0, 44], [$next['opening'], $next['total'], $next['closing']]);
        // paging keeps the running balance
        $page2 = $this->expectOk($this->getAs('wm', "/api/insights/stock-card?sku={$p->sku}&from={$today}&to={$today}&pageSize=2&page=2"));
        $this->assertSame([44], array_column($page2['items'], 'balance'));

        $this->expectRejected($this->getAs('wm', '/api/insights/stock-card?sku=NOPE-'.$tag), 'PRODUCT_NOT_FOUND', [404]);
        $this->expectRejected($this->getAs('wm', "/api/insights/stock-card?sku={$p->sku}&from={$tomorrow}&to={$today}"), 'RANGE', [400]);
        // export needs report.export
        $noExport = $this->makeUser(['page.insights']);
        $this->expectRejected($this->getAs($noExport->username, "/api/insights/stock-card/export?sku={$p->sku}"), 'FORBIDDEN', [403]);
        $csv = $this->getAs('wm', "/api/insights/stock-card/export?sku={$p->sku}&from={$today}&to={$today}");
        $csv->assertOk();
        $body = $csv->getContent();
        $this->assertStringStartsWith("\u{FEFF}", $body);
        $this->assertStringContainsString('رصيد افتتاحي', $body);
        $this->assertStringContainsString('رصيد ختامي', $body);
        $this->assertSame(7, substr_count(trim($body), "\r\n") + 1, 'header, opening, four movements, closing');
        $this->assertReconciled();
    }
}
