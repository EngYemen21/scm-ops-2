<?php

namespace App\Integration\Odoo\Archive;

use App\Integration\Odoo\Projection\Val;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Writes the archive's index (`odoo_docs`) from the copy of Odoo (`odoo_records`) — and from nothing else: Odoo is
 * never asked, and the copy is only read.
 *
 * Two ways in, one way of making a row:
 *  - write()  the records the mirror has just written or marked deleted (ArchiveIndexer::index → here);
 *  - build()  everything that is already copied (`scm:odoo-archive-index`): model by model, walking the ids upwards in
 *             steps, each step stored with its position — it can be stopped at any moment and goes on from there.
 *             A model that was built once is only caught up: the records the copy changed since, found by
 *             `changed_at` (deletions carry it too), and of those only the ones the index does not have yet.
 *
 * A row is made by ArchiveKinds::pick (which kind is this record?) and row() (what do its typed columns hold?).
 * Values of other records a kind shows (`via`: a payment's date is its journal entry's) are read with the page and
 * the records that show values of a changed record are written again (`touch`).
 */
#[Singleton]
class ArchiveWriter
{
    public const STAMP = 'odoo-archive:stamp';

    private const RECORD = ['odoo_id', 'display_name', 'write_date', 'active', 'data', 'version', 'deleted_at'];

    /** Every column a row carries besides its identity (model, odoo_id). */
    private const COLUMNS = ['kind', 'live', 'active', 'private', 'number', 'title', 'partner_id', 'partner_name', 'product_id', 'parent_model', 'parent_id', 'seq',
        'doc_date', 'state', 'amount', 'residual', 'currency', 'user_id', 'user_name', 'dim1', 'dim2', 'search', 'rec_version', 'indexed_at'];

    private const MONEY_LIMIT = 9.99e15;

    /** @var ?array<int, string> company id → its currency */
    private ?array $currencies = null;

    /** @var ?array<int, int> product template id → its (first) product */
    private ?array $variants = null;

    private float $variantsAt = 0.0;

    /** @var array<string, true> models this process knows to be on record in odoo_docs_state */
    private array $registered = [];

    private static ?bool $installed = null;

    private static float $installedAt = 0.0;

    public function __construct(private readonly ArchiveKinds $kinds) {}

    /**
     * Does this database have the index's tables? The code reaches a server before its migration runs, and the
     * mirror calls the indexer from its very first page: until the tables exist there is simply nothing to write.
     */
    public static function installed(): bool
    {
        if (self::$installed === true || (self::$installed === false && microtime(true) - self::$installedAt < 30)) {
            return self::$installed;
        }
        self::$installedAt = microtime(true);

        return self::$installed = Schema::hasTable('odoo_docs') && Schema::hasTable('odoo_docs_state');
    }

    /** Forgets what this process learned about the tables (after a migration ran in it; a test that hides them). */
    public static function forgetInstalled(): void
    {
        self::$installed = null;
    }

    // ───────────── the records the mirror has just written ─────────────

    /**
     * (Re)writes the rows of these records as the copy holds them now. A record the copy marks deleted keeps its row
     * (`live` false); a record the copy no longer has loses it. @return int rows written
     */
    public function write(string $model, array $odooIds, bool $touch = true): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $odooIds), fn ($id) => $id > 0)));
        $written = 0;
        foreach (array_chunk($ids, max(1, (int) config('odoo_archive.chunk', 1000))) as $chunk) {
            $records = DB::table('odoo_records')->where('model', $model)->whereIn('odoo_id', $chunk)->get(self::RECORD);
            $written += $this->store($model, $records, $touch);
            $gone = array_values(array_diff($chunk, $records->pluck('odoo_id')->map(fn ($id) => (int) $id)->all()));
            if ($gone) {
                DB::table('odoo_docs')->where('model', $model)->whereIn('odoo_id', $gone)->delete();
            }
        }
        if ($ids) {
            $this->stamp($model);
        }
        if ($written && ! isset($this->registered[$model])) {
            // a model first seen through the mirror: the catalog learns of it here; the build command walks it later
            DB::table('odoo_docs_state')->insertOrIgnore(['model' => $model, 'cursor_id' => 0, 'done' => false, 'rows' => 0, 'updated_at' => now()->format('Y-m-d H:i:s.v')]);
            $this->registered[$model] = true;
        }

        return $written;
    }

    /**
     * What every remembered answer of the archive is kept under: the stamp of the model whose rows changed (the
     * figures of its kinds are counted again) and the stamp of the index as a whole.
     */
    public function stamp(?string $model = null): void
    {
        $stamp = (string) hrtime(true);
        Cache::forever(ArchiveCounts::stampKey(), $stamp);
        if ($model !== null) {
            Cache::forever(ArchiveCounts::stampKey($model), $stamp);
        }
        ArchiveCounts::changed($stamp, $model);
    }

    // ───────────── everything that is already copied ─────────────

    /**
     * @param  ?string[]  $only  restrict the build to these models
     * @param  bool  $fresh  drop the rows first and index everything again
     * @param  ?callable(string):void  $say  progress, one line per model
     * @param  ?float  $until  microtime after which the build stops between two steps (it is resumed by the next call)
     * @return array{models:int, written:int, removed:int, complete:bool, seconds:float, failed?:array<string, string>}
     */
    public function build(?array $only = null, bool $fresh = false, ?callable $say = null, ?float $until = null): array
    {
        $t0 = microtime(true);
        $say ??= fn (string $line) => null;
        $copied = DB::table('odoo_records')->select('model')->distinct()->pluck('model')->all();
        $priority = DB::table('odoo_models')->pluck('priority', 'model');
        $models = $only ? array_values(array_intersect($only, $copied)) : $copied;
        usort($models, fn ($a, $b) => [(int) ($priority[$a] ?? 9999), $a] <=> [(int) ($priority[$b] ?? 9999), $b]);
        $out = ['models' => 0, 'written' => 0, 'removed' => 0, 'complete' => true];
        if ($fresh && ! $only) {
            // the whole index again: emptying the two tables is one statement each, whatever their size
            $out['removed'] = DB::table('odoo_docs')->count();
            DB::table('odoo_docs')->truncate();
            DB::table('odoo_docs_state')->truncate();
        }

        // a model the copy no longer holds at all: its rows go with it
        $known = DB::table('odoo_docs_state')->pluck('model')->all();
        foreach (array_diff($only ? array_intersect($only, $known) : $known, $copied) as $model) {
            $out['removed'] += $this->forget($model);
            $say(sprintf('%-46s no longer in the copy: its rows were removed', $model));
        }
        // every model this build is about is on record as "not built yet" before the first one is walked: a build that
        // stops half-way leaves the archive saying that it is not complete
        $at = now()->format('Y-m-d H:i:s.v');
        foreach (array_chunk(array_values(array_diff($models, $known)), 200) as $chunk) {
            DB::table('odoo_docs_state')->insertOrIgnore(array_map(fn ($model) => ['model' => $model, 'cursor_id' => 0, 'done' => false, 'mark' => $at, 'rows' => 0, 'updated_at' => $at], $chunk));
        }
        foreach ($models as $model) {
            // a build with little time still moves: one model is always worked on before the clock is looked at
            if ($until !== null && $out['models'] > 0 && microtime(true) >= $until) {
                $out['complete'] = false;
                break;
            }
            $t = microtime(true);
            $out['models']++;
            try {
                $hash = $this->kinds->hash($model);
                $state = DB::table('odoo_docs_state')->where('model', $model)->first();
                if ($fresh && $state) {
                    $out['removed'] += $this->forget($model);
                    $state = null;
                }
                if (! $state || $state->defs !== $hash) {
                    // new, or its definitions changed: walk it again from the first id (rows are replaced in place)
                    DB::table('odoo_docs_state')->updateOrInsert(['model' => $model], ['cursor_id' => 0, 'done' => false, 'mark' => now()->format('Y-m-d H:i:s.v'), 'defs' => $hash, 'rows' => 0,
                        'built_at' => null, 'updated_at' => now()->format('Y-m-d H:i:s.v')]);
                    $state = DB::table('odoo_docs_state')->where('model', $model)->first();
                }
                [$written, $finished, $how] = $state->done ? [$this->catchUp($model, $state), true, 'caught up'] : [...$this->walk($model, $state, $until), 'built'];
                $removed = $finished ? $this->sweep($model) : 0;
            } catch (\Throwable $e) {
                // one model that cannot be indexed never stops the others; it is tried again by the next run
                report($e);
                $out['complete'] = false;
                $out['failed'][$model] = mb_substr($e->getMessage(), 0, 300);
                $say(sprintf('%-46s FAILED    %s', $model, mb_substr($e->getMessage(), 0, 200)));

                continue;
            }
            $out['written'] += $written;
            $out['removed'] += $removed;
            $out['complete'] = $out['complete'] && $finished;
            $this->stamp($model); // its kinds are counted again, and the catalog learns that it is built
            if ($written || $removed || ! $finished || $how === 'built') {
                $say(sprintf('%-46s %-9s %8d rows%s  %6.1f s%s', $model, $finished ? $how : 'stopped', $written, $removed ? " ({$removed} removed)" : '', microtime(true) - $t, $finished ? '' : ' — run again to continue'));
            }
        }
        $this->stamp();

        return $out + ['seconds' => round(microtime(true) - $t0, 1)];
    }

    /** The first build of a model: its ids upwards, a step at a time. @return array{0:int, 1:bool} rows written, finished */
    private function walk(string $model, object $state, ?float $until): array
    {
        $size = max(1, (int) config('odoo_archive.chunk', 1000));
        $cursor = (int) $state->cursor_id;
        $written = 0;
        do {
            $records = DB::table('odoo_records')->where('model', $model)->where('odoo_id', '>', $cursor)->orderBy('odoo_id')->limit($size)->get(self::RECORD);
            if ($records->isNotEmpty()) {
                $written += $this->store($model, $records, false);
                $cursor = (int) $records->last()->odoo_id;
                DB::table('odoo_docs_state')->where('model', $model)->update(['cursor_id' => $cursor, 'rows' => (int) $state->rows + $written, 'updated_at' => now()->format('Y-m-d H:i:s.v')]);
            }
            $more = $records->count() >= $size;
        } while ($more && ($until === null || microtime(true) < $until));
        if (! $more) {
            DB::table('odoo_docs_state')->where('model', $model)->update(['done' => true, 'built_at' => now()->format('Y-m-d H:i:s.v'), 'updated_at' => now()->format('Y-m-d H:i:s.v')]);
        }

        return [$written, ! $more];
    }

    /** What the copy changed in a model since the index last looked — of that, what the index does not hold yet. */
    private function catchUp(string $model, object $state): int
    {
        // the mirror stamps a record before its transaction commits: look a little before the mark as well
        $from = Carbon::parse($state->mark ?? '2000-01-01')->subSeconds(120)->format('Y-m-d H:i:s.v');
        $newest = null;
        $written = 0;
        $last = null;
        do {
            $batch = DB::table('odoo_records')->where('model', $model)->where('changed_at', '>=', $from)
                ->when($last, fn ($q) => $q->where(fn ($x) => $x->where('changed_at', '>', $last[0])->orWhere(fn ($y) => $y->where('changed_at', $last[0])->where('odoo_id', '>', $last[1]))))
                ->orderBy('changed_at')->orderBy('odoo_id')->limit(2000)->get(['odoo_id', 'version', 'deleted_at', 'changed_at']);
            if ($batch->isEmpty()) {
                break;
            }
            $have = DB::table('odoo_docs')->where('model', $model)->whereIn('odoo_id', $batch->pluck('odoo_id')->all())->get(['odoo_id', 'rec_version', 'live'])->keyBy('odoo_id');
            $stale = $batch->filter(fn ($r) => ! isset($have[$r->odoo_id]) || (int) $have[$r->odoo_id]->rec_version !== (int) $r->version || (bool) $have[$r->odoo_id]->live !== ($r->deleted_at === null));
            if ($stale->isNotEmpty()) {
                $written += $this->write($model, $stale->pluck('odoo_id')->all());
            }
            $last = [$batch->last()->changed_at, (int) $batch->last()->odoo_id];
            $newest = $batch->last()->changed_at;
        } while ($batch->count() >= 2000);
        if ($newest !== null && (string) $newest > (string) $state->mark) {
            DB::table('odoo_docs_state')->where('model', $model)->update(['mark' => $newest, 'rows' => (int) $state->rows + $written, 'updated_at' => now()->format('Y-m-d H:i:s.v')]);
        }

        return $written;
    }

    /** Rows whose record the copy no longer has (a model that was copied again): removed. @return int rows removed */
    private function sweep(string $model): int
    {
        if (DB::table('odoo_docs')->where('model', $model)->count() <= DB::table('odoo_records')->where('model', $model)->count()) {
            return 0;
        }
        $removed = 0;
        $cursor = 0;
        do {
            $ids = DB::table('odoo_docs')->where('model', $model)->where('odoo_id', '>', $cursor)->orderBy('odoo_id')->limit(2000)->pluck('odoo_id')->map(fn ($id) => (int) $id)->all();
            if ($ids) {
                $kept = DB::table('odoo_records')->where('model', $model)->whereIn('odoo_id', $ids)->pluck('odoo_id')->map(fn ($id) => (int) $id)->all();
                $gone = array_values(array_diff($ids, $kept));
                if ($gone) {
                    $removed += DB::table('odoo_docs')->where('model', $model)->whereIn('odoo_id', $gone)->delete();
                }
                $cursor = end($ids);
            }
        } while (count($ids) >= 2000);

        return $removed;
    }

    /** Drops the rows of a model and what the build remembers of it. @return int rows removed */
    private function forget(string $model): int
    {
        $removed = 0;
        do {
            $removed += $n = DB::table('odoo_docs')->where('model', $model)->limit(20000)->delete();
        } while ($n > 0);
        DB::table('odoo_docs_state')->where('model', $model)->delete();

        return $removed;
    }

    // ───────────── from records to rows ─────────────

    /** @param  Collection<int, object>  $records  rows of odoo_records (self::RECORD) of one model */
    private function store(string $model, Collection $records, bool $touch): int
    {
        if ($records->isEmpty()) {
            return 0;
        }
        $data = $defs = $need = [];
        foreach ($records as $r) {
            $id = (int) $r->odoo_id;
            $data[$id] = json_decode((string) $r->data, true) ?: [];
            $defs[$id] = $def = $this->kinds->pick($model, $data[$id]);
            foreach ($def['via'] as $field => $target) {
                if ($far = Val::id($data[$id][$field] ?? null)) {
                    $need[$field][$target][$far] = true;
                }
            }
        }
        // the records a kind reads values of (a payment's journal entry, a product's template), one question per model
        $via = [];
        foreach ($need as $field => $targets) {
            foreach ($targets as $target => $ids) {
                foreach (array_chunk(array_keys($ids), 1000) as $chunk) {
                    foreach (DB::table('odoo_records')->where('model', $target)->whereIn('odoo_id', $chunk)->get(['odoo_id', 'data']) as $far) {
                        $via[$field][(int) $far->odoo_id] = json_decode((string) $far->data, true) ?: [];
                    }
                }
            }
        }
        $now = now()->format('Y-m-d H:i:s.v');
        $rows = [];
        foreach ($records as $r) {
            $id = (int) $r->odoo_id;
            $rows[] = ['model' => $model, 'odoo_id' => $id] + $this->row($defs[$id], $r, $data[$id], $via) + ['indexed_at' => $now];
        }
        foreach (array_chunk($rows, max(20, (int) config('odoo_archive.write', 400))) as $chunk) {
            DB::table('odoo_docs')->upsert($chunk, ['model', 'odoo_id'], self::COLUMNS);
        }
        if ($touch) {
            $this->touch($model, $defs, $data);
        }

        return count($rows);
    }

    /** The records that show values of the ones just written are written again (never further than one step). */
    private function touch(string $model, array $defs, array $data): void
    {
        $again = $parents = [];
        foreach ($defs as $id => $def) {
            foreach ($def['touch'] as $field => $target) {
                if ($far = Val::id($data[$id][$field] ?? null)) {
                    $again[$target][$far] = true;
                }
            }
            if ($def['touchChildren']) {
                $parents[$def['touchChildren']][] = $id;
            }
        }
        foreach ($parents as $child => $ids) {
            foreach (array_chunk($ids, 1000) as $chunk) {
                foreach (DB::table('odoo_docs')->where('parent_model', $model)->whereIn('parent_id', $chunk)->where('model', $child)->pluck('odoo_id') as $far) {
                    $again[$child][(int) $far] = true;
                }
            }
        }
        foreach ($again as $target => $ids) {
            $this->write($target, array_keys($ids), false);
        }
    }

    /** The typed columns of one record, as its kind defines them. */
    private function row(array $def, object $record, array $data, array $via): array
    {
        $ix = $def['ix'];
        $context = ['id' => (int) $record->odoo_id, 'display' => $record->display_name, 'via' => $via, 'variant' => fn (int $template) => $this->variant($template)];
        $raw = fn (?string $spec) => Pick::raw($spec, $data, $context);

        $number = Pick::text($raw($ix['number']), 120) ?? '';
        $title = Pick::text($raw($ix['title']), 255) ?? '';
        [$partnerId, $partnerName] = Pick::ref($raw($ix['partner']));
        [$userId, $userName] = Pick::ref($raw($ix['user']), 190);
        $product = $raw($ix['product']);
        [$parentModel, $parentId] = $this->parent($def, $data);
        $seq = (int) max(-2000000000, min(2000000000, Pick::number($raw($ix['seq'])) ?? 0));
        if ($def['groups']) {
            // the lines of a document in groups (its own lines, then taxes, then the due amount …), each in its own order
            [$field, $order] = $def['groups'];
            $position = array_search($data[$field] ?? null, $order, true);
            $seq = 1000000 * ($position === false ? count($order) : $position) + max(0, min(999999, $seq));
        }
        $amount = Pick::number($raw($ix['amount'])) ?? 0.0;
        $residual = $ix['residual'] ? Pick::number($raw($ix['residual'])) : null;
        $search = null;
        if (! $def['support']) {
            $words = [$number, $title, $partnerName];
            foreach ($ix['search'] as $spec) {
                $words[] = Pick::text($raw($spec), 200);
            }
            // short enough for an index entry on either engine: 360 characters, and never more than 1,000 bytes
            $search = self::fit(mb_substr(Fold::text(implode(' ', array_filter($words, fn ($w) => $w !== null && $w !== ''))), 0, 360), 1000);
        }
        $private = $def['private'] || ($def['hangs'] && $parentModel !== null && $this->kinds->isPrivate($parentModel));
        foreach ((array) $def['private_when'] as $field => $values) {
            $private = $private || in_array($data[$field] ?? null, (array) $values, true);
        }

        return [
            'kind' => $def['key'],
            'live' => $record->deleted_at === null,
            'active' => $record->active === null ? true : (bool) $record->active,
            'private' => $private,
            'number' => $number,
            'title' => $title,
            'partner_id' => $partnerId,
            'partner_name' => $partnerName,
            'product_id' => is_array($product) ? Val::id($product) : (is_numeric($product) && $product > 0 ? (int) $product : null),
            'parent_model' => $parentModel,
            'parent_id' => $parentId,
            'seq' => $seq,
            'doc_date' => Pick::moment($raw($ix['date'])) ?? Pick::moment($data['create_date'] ?? null) ?? Pick::moment($record->write_date) ?? '1970-01-01 00:00:00',
            'state' => self::fit(Pick::text($raw($ix['state']), 64), 240),
            'amount' => self::money($amount),
            'residual' => $residual === null ? null : self::money($residual),
            'currency' => $ix['currency'] === '@company' ? $this->companyCurrency($data['company_id'] ?? null) : Pick::text($raw($ix['currency']), 12),
            'user_id' => $userId,
            'user_name' => $userName,
            'dim1' => self::fit(Pick::text($raw($ix['dim1']), 120), 240),
            'dim2' => self::fit(Pick::text($raw($ix['dim2']), 120), 240),
            'search' => $search,
            'rec_version' => (int) $record->version,
        ];
    }

    /** @return array{0:?string, 1:?int} what the record hangs on */
    private function parent(array $def, array $data): array
    {
        foreach ($def['parent'] as $field => $target) {
            if ($field === '@ref') {
                // Odoo's "any document" pair: the name of a model and an id in it
                $model = $data[$target[0]] ?? null;
                $id = Val::id($data[$target[1]] ?? null);
                if (is_string($model) && $id && preg_match('/^[a-z0-9_.]+$/', $model) && ! ($def['support'] && in_array($model, (array) config('odoo_archive.chat_models'), true))) {
                    return [$model, $id];
                }
            } elseif ($id = Val::id($data[$field] ?? null)) {
                return [(string) $target, $id];
            }
        }

        return [null, null];
    }

    /** A text of an index column within a number of bytes (cut between characters); null when nothing is left. */
    private static function fit(?string $text, int $bytes): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }
        $text = strlen($text) <= $bytes ? $text : rtrim(mb_strcut($text, 0, $bytes, 'UTF-8'));

        return $text === '' ? null : $text;
    }

    private static function money(float $value): string
    {
        return is_finite($value) ? number_format(max(-self::MONEY_LIMIT, min(self::MONEY_LIMIT, $value)), 4, '.', '') : '0.0000';
    }

    /** The currency of a company, as Odoo names it ("SAR"); of the first company where a record names none. */
    private function companyCurrency(mixed $company): ?string
    {
        if ($this->currencies === null) {
            $this->currencies = [];
            foreach (DB::table('odoo_records')->where('model', 'res.company')->orderBy('odoo_id')->get(['odoo_id', 'data']) as $c) {
                $name = Pick::text((json_decode((string) $c->data, true) ?: [])['currency_id'] ?? null, 12);
                if ($name !== null) {
                    $this->currencies[(int) $c->odoo_id] = $name;
                }
            }
        }

        return $this->currencies[Val::id($company) ?? 0] ?? (reset($this->currencies) ?: null);
    }

    /** The product behind a product template (Odoo's documents name products; a few of its tables name templates). */
    private function variant(int $template): ?int
    {
        if ($this->variants === null || (! isset($this->variants[$template]) && microtime(true) - $this->variantsAt > 30)) {
            $this->variants = [];
            $this->variantsAt = microtime(true);
            $cursor = 0;
            do {
                $page = DB::table('odoo_records')->where('model', 'product.product')->where('odoo_id', '>', $cursor)->orderBy('odoo_id')->limit(5000)->get(['odoo_id', 'data']);
                foreach ($page as $p) {
                    $of = Val::id((json_decode((string) $p->data, true) ?: [])['product_tmpl_id'] ?? null);
                    if ($of) {
                        $this->variants[$of] ??= (int) $p->odoo_id;
                    }
                    $cursor = (int) $p->odoo_id;
                }
            } while ($page->count() >= 5000);
        }

        return $this->variants[$template] ?? null;
    }
}
