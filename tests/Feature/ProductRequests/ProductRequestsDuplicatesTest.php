<?php

namespace Tests\Feature\ProductRequests;

use App\Models\Product;
use App\Models\ProductBarcode;
use App\ProductRequests\Models\ProductRequest;
use App\ProductRequests\Support\NameKey;
use Tests\ApiTestCase;

/**
 * Never a duplicate product: what is found while the form is typed (the barcode, the code, the name with its spelling
 * variants folded), what refuses a request when it is sent and again when it is approved, and what only asks for a
 * person's confirmation.
 */
class ProductRequestsDuplicatesTest extends ApiTestCase
{
    use ProductRequestFixtures;

    private function check(array $query, string $as = 'sales'): array
    {
        return $this->expectOk($this->getAs($as, '/api/product-requests/duplicates?'.http_build_query($query)));
    }

    public function test_names_are_compared_the_way_people_read_them(): void
    {
        $this->assertSame('جبنه شيدر مبشوره', NameKey::of('  جُبْنَة   شيدر، مبشورة '), 'marks, punctuation and spacing are not the name; ة reads as ه');
        $this->assertSame(NameKey::of('ارز بسمتي'), NameKey::of('أرزّ بسمتى'));
        $this->assertSame('olive oil 1 5l', NameKey::of('Olive-Oil 1.5L'));
        $this->assertSame('عصير برتقال', NameKey::core('عصير برتقال (250ml*24pcs)'), 'the pack in parentheses is kept apart from the name');
        $this->assertSame(NameKey::pack('x (250ml*24pcs)'), NameKey::pack('x', '( 250 ML × 24 pcs )'), 'one pack, however it is written');
        $this->assertNotSame(NameKey::pack('x (250ml*24pcs)'), NameKey::pack('x (1ltr*12pcs)'));
        $this->assertSame('', NameKey::pack('اسم بلا عبوة'));
        $this->assertSame(['جبنه', 'شيدر'], NameKey::tokens('جبنه و شيدر'));
        $this->assertEqualsWithDelta(1.0, NameKey::coverage(['جبنه', 'شيد'], ['جبنه', 'شيدر', 'مبشوره']), 0.001, 'a word still being typed matches the start of a longer one');
        $this->assertEqualsWithDelta(0.5, NameKey::coverage(['جبنه', 'زيت'], ['جبنه', 'شيدر']), 0.001);
    }

    public function test_duplicates_are_found_while_the_form_is_typed(): void
    {
        $tag = self::tag();
        $p = $this->catalogueProduct(['nameAr' => "كمأة برية مجففة {$tag} (2kg*6pcs)", 'nameEn' => "Dried wild truffle {$tag} (2kg*6pcs)", 'barcode' => "62811{$tag}"]);

        // the barcode is one product; so is the code — both block
        $byBarcode = $this->check(['barcode' => " 62811 {$tag} "]);
        $this->assertSame([$p['sku'], $p['id'], true, false, false], [$byBarcode['barcode']['product']['sku'], $byBarcode['barcode']['product']['id'], $byBarcode['blocked'], $byBarcode['needsConfirmation'], $byBarcode['clean']]);
        $byCode = $this->check(['sku' => strtolower($p['sku'])]);
        $this->assertSame([$p['sku'], $p['sku'], true], [$byCode['sku']['value'], $byCode['sku']['product']['sku'], $byCode['blocked']]);
        $free = $this->check(['barcode' => "0000{$tag}", 'sku' => "FREE{$tag}"]);
        $this->assertSame([null, null, false, true], [$free['barcode']['product'], $free['sku']['product'], $free['blocked'], $free['clean']]);

        // the name, spelled another way and without its pack: the same product — asks, does not block
        $byName = $this->check(['nameAr' => "كَمأه  بريه مجففه {$tag}"]);
        $this->assertSame([[$p['sku']], ['exact'], true, false, false], [array_column($byName['names']['products'], 'sku'), array_column($byName['names']['products'], 'match'), $byName['needsConfirmation'], $byName['blocked'], $byName['clean']]);
        // by its English name, whatever the letter case and the punctuation
        $byEnglish = $this->check(['nameAr' => "اسم عربي مختلف {$tag}", 'nameEn' => "DRIED Wild-Truffle {$tag}"]);
        $this->assertSame([[$p['sku']], ['exact']], [array_column($byEnglish['names']['products'], 'sku'), array_column($byEnglish['names']['products'], 'match')]);
        // the same pack written another way is still the same product …
        $samePack = $this->check(['nameAr' => "كمأة برية مجففة {$tag}", 'packText' => '2 KG x 6 pcs']);
        $this->assertSame(['exact', true], [$samePack['names']['products'][0]['match'], $samePack['needsConfirmation']]);
        // … and another pack is another product: shown, asks nothing
        $otherPack = $this->check(['nameAr' => "كمأة برية مجففة {$tag} (500g*12pcs)"]);
        $this->assertSame([[$p['sku']], ['pack'], false, false, false], [array_column($otherPack['names']['products'], 'sku'), array_column($otherPack['names']['products'], 'match'), $otherPack['needsConfirmation'], $otherPack['blocked'], $otherPack['clean']]);
        // half-typed: "is it one of these?"
        $typing = $this->check(['nameAr' => "كمأة بري {$tag}"]);
        $this->assertSame([[$p['sku']], ['similar'], false], [array_column($typing['names']['products'], 'sku'), array_column($typing['names']['products'], 'match'), $typing['needsConfirmation']]);
        $this->assertGreaterThanOrEqual(0.75, $typing['names']['products'][0]['score']);
        // nothing alike
        $none = $this->check(['nameAr' => "مسحوق غسيل {$tag}"]);
        $this->assertSame([[], [], [], true], [$none['names']['products'], $none['names']['requests'], $none['names']['rejected'], $none['clean']]);
        $this->assertTrue($this->check(['nameAr' => 'جب'])['clean'], 'two letters name nothing');

        // ── other requests: the running ones count, a draft does not, a rejected one is shown with its reason
        $draft = $this->raise(['nameAr' => "معكرونة سباغيتي {$tag}", 'barcode' => "62822{$tag}"]);
        $this->assertSame([[], []], [$this->check(['nameAr' => "معكرونه سباغيتي {$tag}"], 'proc')['names']['requests'], $this->check(['barcode' => "62822{$tag}"], 'proc')['barcode']['requests']]);
        $this->expectOk($this->postAs('sales', "/api/product-requests/{$draft['id']}/submit"));
        $running = $this->check(['nameAr' => "معكرونه سباغيتي {$tag}", 'barcode' => "62822{$tag}"], 'proc');
        $this->assertSame([[$draft['number']], ['exact'], [$draft['number']], true, true], [array_column($running['names']['requests'], 'number'), array_column($running['names']['requests'], 'match'),
            array_column($running['barcode']['requests'], 'number'), $running['blocked'], $running['needsConfirmation']]);
        // a request is not its own duplicate
        $own = $this->check(['request' => $draft['number']]);
        $this->assertSame([true, [], []], [$own['clean'], $own['names']['requests'], $own['barcode']['requests']]);
        // … nor while its own form is being typed (the form says which request it edits)
        $typed = $this->check(['nameAr' => "معكرونة سباغيتي {$tag}", 'barcode' => "62822{$tag}", 'except' => $draft['id']]);
        $this->assertSame([[], [], false], [$typed['names']['requests'], $typed['barcode']['requests'], $typed['blocked']]);
        // … while what the reviewer types on top of it is checked: the code he is about to give
        $this->assertSame([$p['sku'], true], [($coded = $this->check(['request' => $draft['id'], 'sku' => $p['sku']]))['sku']['product']['sku'], $coded['blocked']]);
        $this->expectRejected($this->getAs('sales', '/api/product-requests/duplicates?request=PREQ-NOPE'), 'PREQ_NOT_FOUND', [404]);
        $this->expectOk($this->postAs('gm', "/api/product-requests/{$draft['id']}/reject", ['reason' => 'المورد أوقف هذا الصنف']));
        $after = $this->check(['nameAr' => "معكرونة سباغيتي {$tag}", 'barcode' => "62822{$tag}"], 'proc');
        $this->assertSame([[], [], [$draft['number']], 'المورد أوقف هذا الصنف', false, false, false],
            [$after['names']['requests'], $after['barcode']['requests'], array_column($after['names']['rejected'], 'number'), $after['names']['rejected'][0]['rejectReason'], $after['blocked'], $after['needsConfirmation'], $after['clean']]);
    }

    public function test_a_taken_barcode_or_code_refuses_and_the_same_name_asks(): void
    {
        $tag = self::tag();
        $p = $this->catalogueProduct(['nameAr' => "تونة خفيفة {$tag}", 'nameEn' => "Light tuna {$tag}", 'barcode' => "62833{$tag}"]);
        $products = Product::count();

        // ── when it is sent
        $r = $this->raise(['nameAr' => "تونة بالزيت {$tag}", 'barcode' => "62833{$tag}"] + self::physical());
        $refused = $this->expectRejected($this->postAs('sales', "/api/product-requests/{$r['id']}/submit"), 'PREQ_BARCODE_EXISTS', [409]);
        $this->assertSame($p['sku'], $refused['details']['product']['sku']);
        $this->expectRejected($this->postAs('sales', "/api/product-requests/{$r['id']}/submit", ['confirmDuplicate' => true]), 'PREQ_BARCODE_EXISTS', [409]); // a barcode is not a matter of opinion
        $this->expectOk($this->patchAs('sales', "/api/product-requests/{$r['id']}", ['barcode' => null, 'proposedSku' => strtolower($p['sku'])]));
        $this->assertSame($p['sku'], $this->expectRejected($this->postAs('sales', "/api/product-requests/{$r['id']}/submit"), 'PREQ_SKU_EXISTS', [409])['details']['product']['sku']);
        $this->expectOk($this->patchAs('sales', "/api/product-requests/{$r['id']}", ['proposedSku' => null, 'nameAr' => "تونه خفيفه {$tag}"]));
        $same = $this->expectRejected($this->postAs('sales', "/api/product-requests/{$r['id']}/submit"), 'PREQ_DUPLICATE_NAME', [409]);
        $this->assertSame([[$p['sku']], []], [array_column($same['details']['products'], 'sku'), $same['details']['requests']]);
        $this->assertSame(['draft', ['created', 'edited', 'edited']], [$this->show($r['id'])['status'], self::story($this->show($r['id']))], 'a refused sending changes nothing');
        // a person says it is another product: sent, and the history says what he was shown
        $sent = $this->expectOk($this->postAs('sales', "/api/product-requests/{$r['id']}/submit", ['confirmDuplicate' => true]));
        $this->assertSame(['submitted', [$p['sku']]], [$sent['status'], $sent['events'][0]['meta']['confirmedDuplicateOf']]);

        // raised and sent in one call: refused whole — no request is left behind — then confirmed
        $requests = ProductRequest::count();
        $this->expectRejected($this->postAs('proc', '/api/product-requests', ['nameAr' => "تونة خفيفة {$tag}", 'nameEn' => "Light Tuna {$tag}", 'submit' => true]), 'PREQ_DUPLICATE_NAME', [409]);
        $this->assertSame($requests, ProductRequest::count());
        $second = $this->expectOk($this->postAs('proc', '/api/product-requests', ['nameAr' => "تونة خفيفة {$tag}", 'nameEn' => "Light Tuna {$tag}", 'submit' => true, 'confirmDuplicate' => true] + self::physical()));
        $this->assertSame(['submitted', [$p['sku'], $sent['number']]], [$second['status'], $second['events'][0]['meta']['confirmedDuplicateOf']], 'the product and the running request of that name');

        // two running requests cannot carry one barcode
        $first = $this->waiting(['barcode' => "62844{$tag}"]);
        $twin = $this->raise(['barcode' => "62844{$tag}"], 'proc');
        $this->assertSame($first['number'], $this->expectRejected($this->postAs('proc', "/api/product-requests/{$twin['id']}/submit"), 'PREQ_DUPLICATE_REQUEST', [409])['details']['request']['number']);

        // ── and again when it is approved
        $this->expectRejected($this->postAs('gm', "/api/product-requests/{$r['id']}/approve"), 'PREQ_DUPLICATE_NAME', [409]);
        $this->expectRejected($this->postAs('gm', "/api/product-requests/{$r['id']}/approve", ['confirmDuplicate' => true, 'sku' => $p['sku']]), 'PREQ_SKU_EXISTS', [409]);
        $this->assertSame([$products, 'submitted', null], [Product::count(), $this->row($r['id'])->status, $this->row($r['id'])->product_id], 'never a duplicate: nothing was created');
        $done = $this->expectOk($this->postAs('gm', "/api/product-requests/{$r['id']}/approve", ['confirmDuplicate' => true, 'sku' => "TUNA{$tag}"]));
        $this->assertSame(['approved', "TUNA{$tag}", [$p['sku'], $second['number']]], [$done['status'], $done['productSku'], $done['events'][0]['meta']['confirmedDuplicateOf']]);
        $this->assertSame($products + 1, Product::count());

        // the barcode was taken between the sending and the approval: refused, corrected by the reviewer, approved
        $late = $this->waiting(['barcode' => "62855{$tag}"]);
        $taker = $this->catalogueProduct(['barcode' => "62855{$tag}"]);
        $count = Product::count();
        $this->assertSame($taker['sku'], $this->expectRejected($this->postAs('gm', "/api/product-requests/{$late['id']}/approve"), 'PREQ_BARCODE_EXISTS', [409])['details']['product']['sku']);
        $this->assertSame([$count, 'submitted'], [Product::count(), $this->row($late['id'])->status]);
        $fixed = $this->expectOk($this->postAs('gm', "/api/product-requests/{$late['id']}/approve", ['changes' => ['barcode' => "62866{$tag}"]]));
        $this->assertSame(['approved', "62866{$tag}"], [$fixed['status'], $fixed['barcode']]);
        $this->assertSame($fixed['product']['id'], ProductBarcode::where('barcode', "62866{$tag}")->value('product_id'));
    }
}
