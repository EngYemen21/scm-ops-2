<?php

namespace Tests\Feature\Platform;

use App\Services\Barcodes\BarcodeService;
use Tests\ApiTestCase;

/** Label images: CODE128 (shipping / shelf labels) and QR (runs, customers), rendered as SVG. */
class BarcodesTest extends ApiTestCase
{
    public function test_code128_and_qr_are_served_as_svg_to_signed_in_users_only(): void
    {
        $this->getJson('/api/barcodes/code128?text=A-01-3-B2')->assertStatus(401);
        $this->getJson('/api/barcodes/qr?text=TRP-2026-0031')->assertStatus(401);

        foreach (['/api/barcodes/code128?text=A-01-3-B2', '/api/barcodes/qr?text=TRP-2026-0031'] as $url) {
            $res = $this->getAs('worker', $url); // every role may print a label
            $res->assertOk();
            $this->assertStringStartsWith('image/svg+xml', (string) $res->headers->get('content-type'));
            $this->assertStringContainsString('<svg', $res->getContent());
            $this->assertStringNotContainsString('<script', $res->getContent());
        }
    }

    public function test_the_same_text_always_draws_the_same_bars_and_different_text_does_not(): void
    {
        $svc = app(BarcodeService::class);
        $this->assertSame($svc->code128('SHP-2026-0072'), $svc->code128('SHP-2026-0072'));
        $this->assertNotSame($svc->code128('SHP-2026-0072'), $svc->code128('SHP-2026-0073'));
        $this->assertNotSame($svc->qr('TRP-2026-0031'), $svc->qr('TRP-2026-0032'));

        // a wider module gives a wider image, never different bars: the bar count stays the same
        $bars = fn (string $svg) => substr_count($svg, '<rect');
        $this->assertSame($bars($svc->code128('FO-3311', 64, 1)), $bars($svc->code128('FO-3311', 64, 3)));
        $this->assertGreaterThan(10, $bars($svc->code128('FO-3311')));
    }

    public function test_a_batch_draws_every_label_in_order_in_one_request(): void
    {
        $items = [['type' => 'code128', 'text' => 'A-01-1-B1'], ['type' => 'qr', 'text' => 'TRP-2026-0031'], ['type' => 'code128', 'text' => 'A-01-1-B2']];
        $res = $this->expectOk($this->postAs('worker', '/api/barcodes/batch', ['items' => $items]));
        $this->assertCount(3, $res['items']);
        $svc = app(BarcodeService::class);
        $this->assertSame($svc->code128('A-01-1-B1', 72, 2), $res['items'][0]);
        $this->assertSame($svc->qr('TRP-2026-0031', 260), $res['items'][1]);
        $this->assertSame($svc->code128('A-01-1-B2', 72, 2), $res['items'][2]);

        // one bad label refuses the whole job instead of printing a sheet with a hole in it
        $this->expectRejected($this->postAs('admin', '/api/barcodes/batch', ['items' => [['type' => 'code128', 'text' => 'OK-1'], ['type' => 'code128', 'text' => 'رف']]]), 'BARCODE_ASCII', [400]);
        $this->expectRejected($this->postAs('admin', '/api/barcodes/batch', ['items' => []]), 'INVALID_INPUT', [400]);
        $tooMany = array_fill(0, BarcodeService::BATCH_MAX + 1, ['type' => 'code128', 'text' => 'X']);
        $this->expectRejected($this->postAs('admin', '/api/barcodes/batch', ['items' => $tooMany]), 'INVALID_INPUT', [400]);
    }

    public function test_input_is_validated(): void
    {
        $this->expectRejected($this->getAs('admin', '/api/barcodes/code128'), 'BARCODE_TEXT', [400]);
        $this->expectRejected($this->getAs('admin', '/api/barcodes/code128?text='.str_repeat('9', BarcodeService::CODE128_MAX + 1)), 'BARCODE_TEXT', [400]);
        // CODE128 is ASCII: Arabic must be refused instead of printing a label no scanner reads back
        $this->expectRejected($this->getAs('admin', '/api/barcodes/code128?text='.urlencode('رف أ')), 'BARCODE_ASCII', [400]);
        $this->expectRejected($this->getAs('admin', '/api/barcodes/qr'), 'QR_TEXT', [400]);
        $this->expectRejected($this->getAs('admin', '/api/barcodes/qr?text='.str_repeat('x', BarcodeService::QR_MAX + 1)), 'QR_TEXT', [400]);

        // QR carries UTF-8, so Arabic is fine there
        $this->getAs('admin', '/api/barcodes/qr?text='.urlencode('مطاعم البلدة — فرع العليا'))->assertOk();
    }
}
