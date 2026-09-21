<?php

namespace App\Http\Controllers\Api\Barcodes;

use App\Http\Controllers\Controller;
use App\Services\Barcodes\BarcodeService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Label images for printing. Any signed-in user may render one: it only draws the text it is given. */
class BarcodesController extends Controller
{
    public function __construct(private readonly BarcodeService $barcodes) {}

    public function code128(Request $request): Response
    {
        return $this->svg($this->barcodes->code128((string) $request->query('text', ''), (int) $request->query('height', 64), (int) $request->query('module', 2)));
    }

    public function qr(Request $request): Response
    {
        return $this->svg($this->barcodes->qr((string) $request->query('text', ''), (int) $request->query('size', 240)));
    }

    /** Up to BATCH_MAX labels in one call, so printing the labels of a zone is one round trip, not hundreds. */
    public function batch(Request $request): array
    {
        $data = $request->validate([
            'items' => 'required|array|min:1|max:'.BarcodeService::BATCH_MAX,
            'items.*.type' => 'required|in:code128,qr',
            'items.*.text' => 'required|string',
        ]);

        return ['items' => array_map(
            fn (array $it) => $it['type'] === 'qr' ? $this->barcodes->qr($it['text'], 260) : $this->barcodes->code128($it['text'], 72, 2),
            $data['items'],
        )];
    }

    private function svg(string $body): Response
    {
        // the same text always draws the same image: let the browser keep it for the working day
        return response($body, 200, ['Content-Type' => 'image/svg+xml; charset=utf-8', 'Cache-Control' => 'private, max-age=28800']);
    }
}
