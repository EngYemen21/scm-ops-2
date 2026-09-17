<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * List endpoints share one contract: query ?page&pageSize&q&sort&order and the envelope
 * { items, total, page, pageSize, pages }.
 */
final class Paging
{
    public function __construct(
        public readonly int $page,
        public readonly int $pageSize,
        public readonly ?string $q,
        public readonly ?string $sort,
        public readonly string $order,
    ) {}

    public static function from(Request $request, int $defaultSize = 50): self
    {
        $page = max(1, (int) $request->query('page', 1));
        $size = min(500, max(1, (int) $request->query('pageSize', $defaultSize)));
        $q = trim((string) $request->query('q', ''));
        $order = strtolower((string) $request->query('order', 'desc')) === 'asc' ? 'asc' : 'desc';

        return new self($page, $size, $q === '' ? null : $q, $request->query('sort') ?: null, $order);
    }

    /** Runs count + page on the builder and wraps the result. $map transforms each model (optional). */
    public function paginate(Builder $query, ?callable $map = null): array
    {
        $total = (clone $query)->toBase()->getCountForPagination();
        $items = $query->forPage($this->page, $this->pageSize)->get();

        return $this->wrap($map ? $items->map($map)->values()->all() : $items->values()->all(), $total);
    }

    public function wrap(array $items, int $total): array
    {
        return [
            'items' => $items,
            'total' => $total,
            'page' => $this->page,
            'pageSize' => $this->pageSize,
            'pages' => max(1, (int) ceil($total / $this->pageSize)),
        ];
    }
}
