<?php

namespace App\Integration\Odoo\Archive;

use App\Support\AuthUser;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * How many documents each kind holds, in which states, with which values of its dimensions — the figures of the
 * archive's home, the choices of its filters and the totals of its lists.
 *
 * Each figure is one pass over an index of `odoo_docs`, taken once per state of what it counts: the writer stamps a
 * model whenever it writes rows of it, and every answer here is kept with the stamp of its model. So what the mirror
 * changes is counted again — the journal items after a new invoice — and everything else is remembered: a run of
 * the mirror never makes the archive count two million rows again. The cache holds one entry per figure and one per
 * user for what they asked last (see remember() and last()): it does not grow with the life of the installation.
 */
#[Singleton]
class ArchiveCounts
{
    /** A figure is kept until its model changes — and made again once a day at the latest. */
    private const KEEP_SECONDS = 86400;

    /** The answer to what one user asked last: kept while they page through it. */
    private const LAST_SECONDS = 900;

    /** @var array<string, array{0:string, 1:mixed}> what this request read or made: cache key → [stamp, answer] */
    private array $memo = [];

    /** @var array<string, array{0:string, 1:float}> the stamps this process knows, and when it read them */
    private static array $stamps = [];

    public function __construct(private readonly ArchiveKinds $kinds) {}

    // ───────────── stamps ─────────────

    /** The cache key of the stamp of one model — or, without a model, of the index as a whole. */
    public static function stampKey(?string $model = null): string
    {
        return ArchiveWriter::STAMP.($model === null ? '' : ':'.$model);
    }

    /**
     * The stamp of a model (or of the whole index) as this process knows it: read from the cache at most every two
     * seconds (a request asks many times), and told directly when this very process writes the index.
     */
    public static function stampOf(?string $model = null): string
    {
        $known = self::$stamps[$model ?? '*'] ?? null;
        if ($known === null || microtime(true) - $known[1] > 2.0) {
            $known = self::$stamps[$model ?? '*'] = [(string) Cache::get(self::stampKey($model), '0'), microtime(true)];
        }

        return $known[0];
    }

    /** The whole index: changes whenever any row does. */
    public static function current(): string
    {
        return self::stampOf();
    }

    /** Said by the writer of this process: rows of this model were written just now. */
    public static function changed(string $stamp, ?string $model = null): void
    {
        self::$stamps['*'] = [$stamp, microtime(true)];
        if ($model !== null) {
            self::$stamps[$model] = [$stamp, microtime(true)];
        }
    }

    /**
     * Remembers an answer for as long as the rows of its model (without one: any row of the index) do not change.
     * The key is the same whatever the state of the index — the stamp travels with the answer, and an answer of
     * another stamp is made again and written over. So the cache holds ONE entry per figure, however often the
     * mirror writes: only use this for figures whose names are few (a kind, a dimension of a kind).
     */
    public function remember(string $key, callable $make, ?string $model = null): mixed
    {
        return $this->stamped('odoo-archive:'.$key, self::stampOf($model), $make, self::KEEP_SECONDS);
    }

    /**
     * The last answer of its sort for the current user (the totals of the list on screen, the matches of the search
     * typed): paging through the same list reads it, another list replaces it. One entry per user and sort — what
     * people ask is endless, what the cache keeps of it is not.
     *
     * @param  string  $signature  what was asked; the answer is made again when it differs, or when the model changed
     */
    public function last(string $slot, string $signature, callable $make, ?string $model = null): mixed
    {
        $user = AuthUser::currentOrNull()?->id ?? 'none';

        return $this->stamped("odoo-archive:last:{$slot}:{$user}", self::stampOf($model).'|'.$signature, $make, self::LAST_SECONDS);
    }

    private function stamped(string $key, string $stamp, callable $make, int $seconds): mixed
    {
        $held = $this->memo[$key] ?? Cache::get($key);
        if (! is_array($held) || ($held[0] ?? null) !== $stamp) {
            $held = [$stamp, $make()];
            Cache::put($key, $held, $seconds);
        }
        $this->memo[$key] = $held;

        return $held[1];
    }

    // ───────────── figures ─────────────

    /**
     * One kind (a stored one, not a view): what Odoo still has of it.
     *
     * @return array{count:int, amount:float, states:array<string,int>}
     */
    public function kind(string $kind): array
    {
        return $this->remember("kind:{$kind}", fn () => $this->count($kind), $this->kinds->get($kind)['model'] ?? null);
    }

    private function count(string $kind): array
    {
        $out = ['count' => 0, 'amount' => 0.0, 'states' => []];
        foreach (DB::table('odoo_docs')->where('kind', $kind)->where('live', true)->selectRaw('state, COUNT(*) AS n, SUM(amount) AS total')->groupBy('state')->get() as $r) {
            $out['count'] += (int) $r->n;
            $out['amount'] += (float) $r->total;
            if ($r->state !== null) {
                $out['states'][(string) $r->state] = (int) $r->n;
            }
        }

        return $out;
    }

    /**
     * Every kind that holds rows, for the catalog: the kinds of the models the index knows, each counted again only
     * when its model changed. The stamps and the remembered figures are read from the cache in two questions.
     *
     * @return array<string, array{count:int, amount:float, states:array<string,int>}>
     */
    public function kinds(): array
    {
        $models = DB::table('odoo_docs_state')->pluck('model')->all();
        $this->learn($models);
        $kinds = [];
        foreach ($models as $model) {
            foreach ($this->kinds->forModel((string) $model) as $def) {
                $kinds[] = $def['key'];
            }
        }
        $unread = array_values(array_diff(array_map(fn ($kind) => "odoo-archive:kind:{$kind}", $kinds), array_keys($this->memo)));
        foreach ($unread ? Cache::many($unread) : [] as $key => $held) {
            if (is_array($held)) {
                $this->memo[$key] = $held; // kind() takes it when its stamp is still the model's, and counts again when not
            }
        }
        $out = [];
        foreach ($kinds as $kind) {
            $figures = $this->kind($kind);
            if ($figures['count'] > 0) {
                $out[$kind] = $figures;
            }
        }

        return $out;
    }

    /** One value that changes when any model of the index changes, or is built (what the catalog is remembered under). */
    public function signature(): string
    {
        $models = DB::table('odoo_docs_state')->orderBy('model')->pluck('done', 'model')->all();
        $this->learn(array_keys($models));

        return sha1(json_encode([$models, array_map(fn ($model) => self::stampOf((string) $model), array_keys($models))]));
    }

    /** Remembers an answer under a name, for as long as the given stamp says nothing changed. */
    public function keep(string $key, string $stamp, callable $make): mixed
    {
        return $this->stamped('odoo-archive:'.$key, $stamp, $make, self::KEEP_SECONDS);
    }

    /** Reads the stamps of many models in one question. */
    private function learn(array $models): void
    {
        $now = microtime(true);
        $stale = array_values(array_filter($models, fn ($model) => ! isset(self::$stamps[$model]) || $now - self::$stamps[$model][1] > 2.0));
        if ($stale) {
            foreach (Cache::many(array_map(fn ($model) => self::stampKey((string) $model), $stale)) as $key => $stamp) {
                self::$stamps[substr((string) $key, strlen(ArchiveWriter::STAMP) + 1)] = [(string) ($stamp ?? '0'), $now];
            }
        }
    }

    /**
     * The values one dimension of a kind takes, with their counts — or null when there are more than a filter can
     * offer as choices. @return ?array<string, int>
     */
    public function dim(string $kind, string $dim, int $limit = 60): ?array
    {
        $dim = $dim === 'dim2' ? 'dim2' : 'dim1';
        $found = $this->remember("dim:{$kind}:{$dim}:{$limit}", function () use ($kind, $dim, $limit) {
            $rows = DB::table('odoo_docs')->where('kind', $kind)->where('live', true)->whereNotNull($dim)->selectRaw("{$dim} AS v, COUNT(*) AS n")
                ->groupBy($dim)->orderBy($dim)->limit($limit + 1)->get();

            // "too many" is kept as false: a cache does not keep null
            return $rows->count() > $limit ? false : $rows->mapWithKeys(fn ($r) => [(string) $r->v => (int) $r->n])->all();
        }, $this->kinds->get($kind)['model'] ?? null);

        return $found === false ? null : $found;
    }

    /** The rows of a kind that do not hang on people's files (what a user without odoo.private may read of it). */
    public function open(string $kind): int
    {
        return (int) $this->remember("open:{$kind}", fn () => DB::table('odoo_docs')->where('kind', $kind)->where('live', true)->where('private', false)->count(), $this->kinds->get($kind)['model'] ?? null);
    }

    /** One value of a dimension of a kind: how many rows (the count of a `view` kind). */
    public function ofDim(string $kind, string $dim, string $value): int
    {
        $dim = $dim === 'dim2' ? 'dim2' : 'dim1';

        return (int) $this->remember("of:{$kind}:{$dim}:{$value}", fn () => DB::table('odoo_docs')->where('kind', $kind)->where('live', true)->where($dim, $value)->count(), $this->kinds->get($kind)['model'] ?? null);
    }
}
