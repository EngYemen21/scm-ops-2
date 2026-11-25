<?php

namespace App\Integration\Odoo\Archive;

use App\Integration\Odoo\Archive\Models\OdooDoc;
use App\Support\AppError;
use App\Support\Paging;
use App\Support\Sql;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The lists of the Odoo archive (docs/odoo/WAVE2.md §3): the catalog of sections and kinds with their counts, the
 * list of one kind with its filters, sorts and totals, the search across kinds, and the CSV of a list.
 *
 * Every list is one range of an index of `odoo_docs` (see its migration): the kind and "still in Odoo" lead, then the
 * filter, then the date. A count or a total over a whole kind comes from ArchiveCounts (taken once per state of the
 * index); over a filtered list it is read from the same index as the list. The search box matches the folded text of
 * the index (`search`), word by word.
 */
#[Singleton]
class ArchiveReader
{
    /** The filters of a list, as they arrive in the query string. */
    public const FILTERS = ['state', 'from', 'to', 'partner', 'product', 'dim1', 'dim2', 'parent', 'active', 'deleted'];

    private const SORTABLE = ['#number' => 'number', '#title' => 'title', '#amount' => 'amount', '#date' => 'doc_date'];

    /** @var array<int, int[]> */
    private array $families = [];

    public function __construct(private readonly ArchiveKinds $kinds, private readonly ArchiveCells $cells, private readonly ArchiveCounts $counts) {}

    // ───────────── the catalog ─────────────

    public function catalog(): array
    {
        if (! ArchiveWriter::installed()) {
            return ['ready' => false, 'indexed' => 0, 'sections' => [], 'lookups' => (object) []];
        }
        $may = $this->cells->mayPrivate();

        // remembered until a model of the index changes: then only the kinds of that model are counted again
        return $this->counts->keep('catalog:'.($may ? 'all' : 'open'), $this->counts->signature(), fn () => $this->describe($may));
    }

    private function describe(bool $may): array
    {
        $totals = $this->counts->kinds();
        $entries = $locked = [];
        $take = function (array $def) use (&$entries, &$locked, $totals, $may) {
            $stored = $def['view']['kind'] ?? $def['key'];
            if ($def['support'] || empty($totals[$stored]['count'])) {
                return;
            }
            if ($def['private'] && ! $may) {
                $locked[$def['section']] = true; // the section says that it holds more than this user may see — not what, nor how much

                return;
            }
            $count = $this->countOf($def, $totals, $may);
            if ($count > 0) {
                $entries[$def['section']][] = $this->entry($def, $count);
            }
        };
        foreach ($this->kinds->explicit() as $def) {
            $take($def);
        }
        // the models nobody described: a plain kind each, the largest first
        $plain = array_diff_key($totals, $this->kinds->explicit());
        uasort($plain, fn ($a, $b) => $b['count'] <=> $a['count']);
        foreach (array_keys($plain) as $key) {
            if ($def = $this->kinds->get((string) $key)) {
                $take($def);
            }
        }
        $sections = [];
        foreach ((array) config('odoo_archive.sections') as $key => $label) {
            if (! empty($entries[$key]) || isset($locked[$key])) {
                $sections[] = ['key' => $key, 'label' => $label[0], 'labelEn' => $label[1], 'locked' => isset($locked[$key]), 'kinds' => $entries[$key] ?? []];
            }
        }

        return [
            // a build went through every model it set out to index; what the mirror writes after it is indexed as it comes
            // (a model first seen through the mirror has no `mark`: it is indexed page by page, and waits for no build)
            'ready' => DB::table('odoo_docs_state')->where('done', true)->exists() && ! DB::table('odoo_docs_state')->where('done', false)->whereNotNull('mark')->exists(),
            'indexed' => array_sum(array_map(fn ($s) => array_sum(array_map(fn ($k) => $k['view'] ? 0 : $k['count'], $s['kinds'])), $sections)),
            'sections' => $sections,
            'lookups' => (object) array_filter((array) config('odoo_archive.lookups'), fn ($kind) => $this->kinds->get((string) $kind) !== null),
        ];
    }

    private function countOf(array $def, array $totals, bool $may): int
    {
        $stored = $def['view']['kind'] ?? $def['key'];
        if ($def['view']) {
            $where = $def['view']['where'];

            return $this->counts->ofDim($stored, (string) array_key_first($where), (string) reset($where));
        }

        // a kind that hangs on any document: the rows that hang on people's files are not counted for who may not read them
        return $def['hangs'] && ! $may ? $this->counts->open($stored) : (int) ($totals[$stored]['count'] ?? 0);
    }

    private function entry(array $def, int $count): array
    {
        return [
            'key' => $def['key'], 'label' => $def['label'][0], 'labelEn' => $def['label'][1], 'one' => $def['one'][0], 'oneEn' => $def['one'][1],
            'model' => $def['model'], 'count' => $count, 'private' => $def['private'], 'plain' => $def['plain'], 'line' => $def['line'], 'view' => $def['view'] !== null,
            'columns' => $this->cells->columnsOut($def, $def['columns']),
            'filters' => $this->filters($def),
            'sorts' => $this->sorts($def, $count), 'defaultSort' => 'date', 'defaultOrder' => 'desc',
            'totals' => $this->totalled($def),
        ];
    }

    /** The filters of a kind: `key` is the name of the query parameter (a date range is sent as `from` and `to`). */
    private function filters(array $def): array
    {
        $labels = $def['labels'];
        $out = [];
        $filter = fn (string $key, array $label, string $type, ?array $options = null) => ['key' => $key, 'label' => $label[0], 'labelEn' => $label[1] ?? $label[0], 'type' => $type]
            + ($options === null ? [] : ['options' => $options]);
        if ($def['ix']['state'] && ($options = $this->cells->stateOptions($def))) {
            $out[] = $filter('state', $labels['state'], 'choice', $options);
        }
        $out[] = $filter('date', $labels['date'], 'daterange');
        if ($def['ix']['partner']) {
            $out[] = $filter('partner', $labels['partner'], 'partner');
        }
        if ($def['ix']['product'] && ! str_contains((string) $def['ix']['product'], '@id')) {
            $out[] = $filter('product', $labels['product'], 'product');
        }
        foreach ($def['dims'] as $dim => $d) {
            if (! isset($def['view']['where'][$dim])) {
                $options = $this->cells->dimOptions($def, $dim);
                $out[] = $filter($dim, $d['label'], $options === null ? 'text' : 'choice', $options);
            }
        }

        return $out;
    }

    /**
     * The keys a list may be sorted by: `date`, and its columns that are a typed column of the index. The date, the
     * number and the amount are read in order from an index; the title is sorted on the spot, so it is offered where
     * a kind is small enough for that (master data — not a list of a hundred thousand lines).
     *
     * @return string[]
     */
    private function sorts(array $def, int $count): array
    {
        $out = ['date'];
        foreach ($def['columns'] as $c) {
            if (isset(self::SORTABLE[$c['from']]) && ($c['from'] !== '#amount' || $def['ix']['amount']) && ($c['from'] !== '#title' || $count <= (int) config('odoo_archive.title_sort_rows', 50000))) {
                $out[] = $c['key'];
            }
        }

        return array_values(array_unique($out));
    }

    /** Does a list of this kind carry a total (it shows the kind's amount as money)? */
    private function totalled(array $def): bool
    {
        foreach ($def['columns'] as $c) {
            if ($c['from'] === '#amount' && $c['type'] === 'money' && $def['ix']['amount']) {
                return true;
            }
        }

        return false;
    }

    // ───────────── one kind ─────────────

    /** A kind a person may list, by its key — refused where it is people's files and the user may not read them. */
    public function kind(string $key): array
    {
        $def = $this->kinds->get($key);
        if (! $def || $def['support']) {
            throw AppError::notFound('ODOO_KIND_NOT_FOUND', "لا يوجد نوع مستندات بهذا الاسم في أرشيف أودو: {$key}", "No such kind of document in the Odoo archive: {$key}");
        }
        $this->guard($def['private']);

        return $def;
    }

    public function guard(bool $private): void
    {
        if ($private && ! $this->cells->mayPrivate()) {
            throw AppError::forbidden('ODOO_PRIVATE', 'هذه السجلات من ملفات الأشخاص — عرضها يحتاج صلاحية odoo.private', 'These records are people\'s files — reading them needs odoo.private');
        }
    }

    /** @param  array<string, ?string>  $f  self::FILTERS */
    public function list(string $kind, Paging $page, array $f): array
    {
        $def = $this->kind($kind);
        $query = $this->query($def, $f, $page->q);
        if ($matches = $this->matches($def, $query, $page, $f)) {
            // a search with few matches: one pass over the index found them all — the page and the totals are cut from it
            [$totals, $ids] = [$matches['totals'], array_slice($matches['ids'], ($page->page - 1) * $page->pageSize, $page->pageSize)];
        } else {
            $totals = $this->totals($def, $query, $f, $page->q);
            // the page in two steps: which records (read from the index alone, however deep the page), then their rows
            $ids = $this->ordered($def, clone $query, $page, $f)->forPage($page->page, $page->pageSize)->pluck('odoo_id')->map(fn ($id) => (int) $id)->all();
        }
        $found = $ids ? OdooDoc::query()->where('model', $def['model'])->whereIn('odoo_id', $ids)->get()->keyBy('odoo_id') : collect();
        $rows = array_values(array_filter(array_map(fn ($id) => $found[$id] ?? null, $ids)));

        // the currency of the total: looked up where a kind may mix currencies, otherwise the one its rows carry
        $currency = array_key_exists('currency', $totals) ? $totals['currency'] : ($rows ? $rows[0]->currency : null);

        return $page->wrap($this->cells->items($def, $rows, $def['columns']), $totals['count'])
            + ['totals' => ['amount' => $totals['amount'], 'currency' => $totals['amount'] === null ? null : $currency], 'kind' => $def['key']];
    }

    /** The rows of a kind that pass the filters and the search box. */
    public function query(array $def, array $f, ?string $q): Builder
    {
        $query = OdooDoc::query()->where('kind', $def['view']['kind'] ?? $def['key'])->where('live', ($f['deleted'] ?? null) !== '1');
        foreach ($def['view']['where'] ?? [] as $column => $value) {
            $query->where($column, $value);
        }
        if ($def['hangs'] && ! $this->cells->mayPrivate()) {
            $query->where('private', false);
        }
        if (($f['state'] ?? '') !== '' && $f['state'] !== null) {
            $query->where('state', (string) $f['state']);
        }
        foreach (['dim1', 'dim2'] as $dim) {
            if (isset($f[$dim]) && $f[$dim] !== '' && ! isset($def['view']['where'][$dim])) {
                $query->where($dim, (string) $f[$dim]);
            }
        }
        if (isset($f['partner']) && $f['partner'] !== '') {
            $family = $this->family((int) $f['partner']);
            count($family) === 1 ? $query->where('partner_id', $family[0]) : $query->whereIn('partner_id', $family);
        }
        if (isset($f['product']) && $f['product'] !== '') {
            $query->where('product_id', (int) $f['product']);
        }
        if (! empty($f['parent']) && preg_match('/^([a-z0-9_.]+):(\d+)$/', (string) $f['parent'], $m)) {
            $query->where('parent_model', $m[1])->where('parent_id', (int) $m[2]);
        }
        if (isset($f['active']) && $f['active'] !== '') {
            $query->where('active', $f['active'] === '1');
        }
        [$from, $to] = $this->range($def, $f['from'] ?? null, $f['to'] ?? null);
        if ($from) {
            $query->where('doc_date', '>=', $from);
        }
        if ($to) {
            $query->where('doc_date', '<', $to);
        }
        $words = Fold::terms($q);
        foreach ($words as $word) {
            $query->where('search', Sql::like(), Fold::like($word));
        }
        // A "contains" search has to look at every document of the kind. The list index carries the search text and
        // the filters of a kind, so it is read there and not row by row; MySQL is told so (it would otherwise walk the
        // rows in date order hoping for early matches), PostgreSQL has no such hint and needs none. A partner, a
        // product or a parent narrows the list far more than any text: there their own index leads.
        if ($words && ! Sql::pg() && empty($f['partner']) && empty($f['product']) && empty($f['parent'])) {
            $query->forceIndex('odoo_docs_list_ix');
        }

        return $query;
    }

    /**
     * A partner and the contacts under it (its addresses and people): Odoo writes a company's documents on any of
     * them, so "the documents of a partner" — its page, and the partner filter of a list — means all of them.
     *
     * @return int[] the partner's own id first
     */
    public function family(int $partner): array
    {
        return $this->families[$partner] ??= array_values(array_unique(array_merge([$partner], OdooDoc::query()->where('partner_id', $partner)->where('live', true)
            ->where('kind', $this->kinds->primary((string) config('odoo_archive.partner_page.model', 'res.partner'))['key'])->limit(200)->pluck('odoo_id')->map(fn ($id) => (int) $id)->all())));
    }

    /**
     * The bounds of a date range in the index's time. A kind dated by day is compared by day; a kind dated by moment
     * is compared with the days of the business time zone (an order confirmed at 01:00 in Riyadh belongs to that day).
     *
     * @return array{0:?string, 1:?string} from (inclusive), to (exclusive)
     */
    private function range(array $def, ?string $from, ?string $to): array
    {
        $zone = $def['dateType'] === 'date' ? 'UTC' : (string) config('app.business_timezone', 'UTC');
        $at = fn (?string $day, int $plus) => $day && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)
            ? Carbon::createFromFormat('Y-m-d H:i:s', "{$day} 00:00:00", $zone)->addDays($plus)->utc()->format('Y-m-d H:i:s') : null;

        return [$at($from, 0), $at($to, 1)];
    }

    /** The column of the index a list is asked to be sorted by; null: not asked (newest first, or a document's own order). */
    private function sortColumn(array $def, Paging $page): ?string
    {
        if ($page->sort === 'date') {
            return 'doc_date';
        }
        foreach ($page->sort === null ? [] : $def['columns'] as $c) {
            if ($c['key'] === $page->sort && isset(self::SORTABLE[$c['from']])) {
                return self::SORTABLE[$c['from']];
            }
        }

        return null;
    }

    private function ordered(array $def, Builder $query, Paging $page, array $f): Builder
    {
        $column = $this->sortColumn($def, $page);
        if ($column === null && ! empty($f['parent'])) {
            return $query->orderBy('seq')->orderBy('odoo_id'); // the lines of a document, in the document's order
        }
        // Newest first with no filter that has an index of its own is a range of the list index, read from its end.
        // MySQL has to be told: left alone it walks that index from the end of the TABLE, through every other kind,
        // "hoping for early matches" (measured: 1.1 s instead of 1 ms on the largest kind). PostgreSQL reads the range.
        $narrow = $def['view'] || array_filter(array_intersect_key($f, array_flip(['state', 'dim1', 'dim2', 'partner', 'product', 'parent'])), fn ($v) => $v !== null && $v !== '');
        if (($column ?? 'doc_date') === 'doc_date' && ! $narrow && ! Sql::pg()) {
            $query->forceIndex('odoo_docs_list_ix');
        }

        return $query->orderBy($column ?? 'doc_date', $page->order)->orderBy('odoo_id', $page->order);
    }

    /**
     * A search that few documents match, answered by ONE pass over the list index: every match with its date and
     * amount — so the count, the total and any page of the list come from that pass, and it is remembered until the
     * kind changes. Null where it does not apply: no search, a sort the index does not carry, or more matches than
     * `search_matches` (then matches are frequent, and the ordinary ordered read finds a page of them at once).
     *
     * @return ?array{totals:array{count:int, amount:?float, currency?:?string}, ids:int[]}
     */
    private function matches(array $def, Builder $query, Paging $page, array $f): ?array
    {
        $column = $this->sortColumn($def, $page) ?? (empty($f['parent']) ? 'doc_date' : null);
        $most = (int) config('odoo_archive.search_matches', 2000);
        if (! Fold::terms($page->q) || ! in_array($column, ['doc_date', 'amount'], true) || $most <= 0) {
            return null;
        }
        $asked = sha1(json_encode([$def['key'], $f, Fold::terms($page->q), $this->cells->mayPrivate()]));
        $found = $this->counts->last('matches', $asked, function () use ($query, $most) {
            $rows = (clone $query)->toBase()->limit($most + 1)->get(['odoo_id', 'doc_date', 'amount', 'currency']);

            return $rows->count() > $most ? false : $rows->map(fn ($r) => [(int) $r->odoo_id, (string) $r->doc_date, (float) $r->amount, $r->currency])->all();
        }, $def['model']);
        if ($found === false) {
            return null;
        }
        $by = $column === 'amount' ? 2 : 1;
        $down = $page->order === 'desc' ? -1 : 1;
        usort($found, fn ($a, $b) => $down * (($a[$by] <=> $b[$by]) ?: ($a[0] <=> $b[0])));
        $money = $this->totalled($def);
        $currencies = array_values(array_unique(array_column($found, 3)));
        $single = count($currencies) <= 1 || in_array($def['ix']['currency'], [null, '@company'], true);
        $totals = ['count' => count($found), 'amount' => $money && $single ? round(array_sum(array_column($found, 2)), 2) : null];
        if ($money && ! in_array($def['ix']['currency'], [null, '@company'], true)) {
            $totals['currency'] = $single ? ($currencies[0] ?? null) : null; // documents in more than one currency have no single total
        }

        return ['totals' => $totals, 'ids' => array_column($found, 0)];
    }

    /** @return array{count:int, amount:?float, currency?:?string} over the whole filtered list */
    private function totals(array $def, Builder $query, array $f, ?string $q): array
    {
        $stored = $def['view']['kind'] ?? $def['key'];
        $money = $this->totalled($def);
        $fixed = ! $money || in_array($def['ix']['currency'], [null, '@company'], true); // one currency by definition: nothing to look up
        $filtered = (bool) array_filter(array_intersect_key($f, array_flip(self::FILTERS)), fn ($v) => $v !== null && $v !== '') || Fold::terms($q) || $def['view'] || $def['hangs'];
        if (! $filtered && $fixed) {
            $all = $this->counts->kind($stored); // the whole kind: counted once per change of its model

            return ['count' => (int) $all['count'], 'amount' => $money ? round((float) $all['amount'], 2) : null];
        }
        $asked = sha1(json_encode([$def['key'], $f, Fold::terms($q), $this->cells->mayPrivate()]));

        return $this->counts->last('totals', $asked, function () use ($query, $money, $fixed) {
            $r = (clone $query)->toBase()->selectRaw('COUNT(*) AS n'.($money ? ', SUM(amount) AS total' : '').($fixed ? '' : ', MIN(currency) AS low, MAX(currency) AS high'))->first();
            $out = ['count' => (int) $r->n, 'amount' => $money ? round((float) $r->total, 2) : null];
            if (! $fixed) {
                // documents in more than one currency have no single total
                $out['currency'] = $r->low === $r->high ? $r->low : null;
                $out['amount'] = $r->low === $r->high ? $out['amount'] : null;
            }

            return $out;
        }, $def['model']);
    }

    // ───────────── the search across kinds ─────────────

    public function search(?string $q, int $first = 5): array
    {
        $words = Fold::terms($q);
        if (! $words || mb_strlen(implode('', $words)) < 2) {
            return ['q' => (string) $q, 'groups' => []];
        }
        $may = $this->cells->mayPrivate();
        $defs = array_filter($this->kinds->explicit(), fn ($d) => $d['global'] && $d['view'] === null && ($may || ! $d['private']));
        $match = function (Builder $query) use ($words, $may) {
            foreach ($words as $word) {
                $query->where('search', Sql::like(), Fold::like($word));
            }
            if (! Sql::pg()) {
                $query->forceIndex('odoo_docs_list_ix'); // the search text is read in the list index, not row by row (see query())
            }

            return $may ? $query : $query->where('private', false);
        };
        // one pass over the documents and master data (lines are found through their document): how many of each kind
        $found = $match(OdooDoc::query()->whereIn('kind', array_keys($defs))->where('live', true))->toBase()->selectRaw('kind, COUNT(*) AS n')->groupBy('kind')->pluck('n', 'kind');
        $groups = [];
        foreach ($defs as $key => $def) {
            if (empty($found[$key])) {
                continue;
            }
            $rows = $match(OdooDoc::query()->where('kind', $key)->where('live', true))->orderByDesc('doc_date')->orderByDesc('odoo_id')->limit($first)->get()->all();
            $section = $this->kinds->sectionLabel($def['section']);
            $groups[] = ['kind' => $key, 'label' => $def['label'][0], 'labelEn' => $def['label'][1], 'section' => $def['section'], 'sectionLabel' => $section[0], 'sectionLabelEn' => $section[1],
                'total' => (int) $found[$key], 'more' => (int) $found[$key] > count($rows), 'items' => $this->cells->items($def, $rows, $def['columns']), 'query' => ['q' => (string) $q]];
        }

        return ['q' => (string) $q, 'groups' => $groups];
    }

    // ───────────── the CSV of a list ─────────────

    /** The list as a file: the columns of the list, the same filters and order, UTF-8 with BOM, at most `export_rows` rows. */
    public function export(string $kind, Paging $page, array $f, string $lang = 'ar'): StreamedResponse
    {
        $def = $this->kind($kind);
        $query = $this->ordered($def, $this->query($def, $f, $page->q), $page, $f)->limit(max(1, (int) config('odoo_archive.export_rows', 50000)));
        $columns = $def['columns'];
        $options = [];
        foreach ($columns as $c) {
            if ($c['type'] === 'state') {
                $options[$c['key']] = array_column($this->cells->columnOptions($def, $c), $lang === 'en' ? 'labelEn' : 'label', 'value');
            }
        }
        $zone = (string) config('app.business_timezone', 'UTC');
        $yes = $lang === 'en' ? ['Yes', 'No'] : ['نعم', 'لا'];
        $text = function (array $c, mixed $v) use ($options, $zone, $yes): string {
            return match (true) {
                $v === null => '',
                $c['type'] === 'state' => (string) ($options[$c['key']][(string) $v] ?? $v),
                $c['type'] === 'partner', $c['type'] === 'product' => (string) ($v['name'] ?? ''),
                $c['type'] === 'bool' => $v ? $yes[0] : $yes[1],
                $c['type'] === 'datetime' => Carbon::parse((string) $v)->setTimezone($zone)->format('Y-m-d H:i'),
                is_float($v) => rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.'),
                default => (string) $v,
            };
        };

        return response()->streamDownload(function () use ($query, $def, $columns, $text, $lang) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_map(fn ($c) => $lang === 'en' ? ($c['label'][1] ?? $c['label'][0]) : $c['label'][0], $columns));
            $write = function (array $rows) use ($out, $def, $columns, $text) {
                foreach ($this->cells->items($def, $rows, $columns) as $item) {
                    $cells = (array) $item['cells'];
                    fputcsv($out, array_map(fn ($c) => $text($c, $cells[$c['key']] ?? null), $columns));
                }
            };
            $batch = [];
            foreach ($query->toBase()->cursor() as $row) {
                $batch[] = $row;
                if (count($batch) >= 500) {
                    $write($batch);
                    $batch = [];
                }
            }
            $write($batch);
            fclose($out);
        }, $def['key'].'-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=utf-8']);
    }
}
