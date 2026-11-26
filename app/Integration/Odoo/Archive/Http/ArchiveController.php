<?php

namespace App\Integration\Odoo\Archive\Http;

use App\Integration\Odoo\Archive\ArchiveDocument;
use App\Integration\Odoo\Archive\ArchiveReader;
use App\Support\Paging;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * «أرشيف أودو» — everything the copy of Odoo holds, as documents people can read (docs/odoo/WAVE2.md §3).
 * Reading needs page.odoo; a kind built on people's files also needs odoo.private (403 ODOO_PRIVATE, decided where
 * the kind is known); the CSV of a list also needs report.export. Nothing here writes: not to OPS, not to the copy,
 * and Odoo is never asked.
 */
class ArchiveController
{
    private const FILTERS = [
        'state' => 'nullable|string|max:64', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'partner' => 'nullable|integer|min:1',
        'product' => 'nullable|integer|min:1', 'dim1' => 'nullable|string|max:120', 'dim2' => 'nullable|string|max:120', 'parent' => ['nullable', 'regex:/^[a-z0-9_.]+:\d+$/'],
        'active' => 'nullable|in:0,1', 'deleted' => 'nullable|in:0,1', 'q' => 'nullable|string|max:200',
    ];

    public function catalog(ArchiveReader $reader): array
    {
        return $reader->catalog();
    }

    public function docs(Request $request, string $kind, ArchiveReader $reader): array
    {
        return $reader->list($kind, Paging::from($request), $this->filters($request));
    }

    public function export(Request $request, string $kind, ArchiveReader $reader): StreamedResponse
    {
        return $reader->export($kind, Paging::from($request), $this->filters($request), $request->query('lang') === 'en' ? 'en' : 'ar');
    }

    public function doc(string $kind, int $odooId, ArchiveDocument $document): array
    {
        return $document->show($kind, $odooId);
    }

    public function partner(int $odooId, ArchiveDocument $document): array
    {
        return $document->partner($odooId);
    }

    public function product(int $odooId, ArchiveDocument $document): array
    {
        return $document->product($odooId);
    }

    public function search(Request $request, ArchiveReader $reader): array
    {
        return $reader->search($request->validate(['q' => 'nullable|string|max:200'])['q'] ?? null);
    }

    /** @return array<string, ?string> the filters of a list, checked */
    private function filters(Request $request): array
    {
        return array_map(fn ($v) => $v === null ? null : (string) $v, array_intersect_key($request->validate(self::FILTERS), array_flip(ArchiveReader::FILTERS)));
    }
}
