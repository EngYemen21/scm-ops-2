<?php

namespace App\Integration\Odoo;

use App\Integration\Odoo\Models\OdooModel;
use App\Integration\Odoo\Models\OdooRecord;
use App\Integration\Odoo\Models\OdooRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the `odoo_*` tables a current copy of what Odoo holds — by reading only.
 *
 * Nothing is installed or configured in Odoo (no module, no webhook, no automated action), so "live" means: every run
 * asks each model "what was written since my cursor?". Three motions, all resumable and all bounded by the time a run
 * is given (a serverless request has seconds, a command on a workstation has hours):
 *
 *  1. FIRST COPY of a model walks its ids upwards (`id > cursor`, a stable order), remembering the newest write_date
 *     Odoo had when the walk started; whatever is written during the walk is newer than that and is picked up by 2.
 *  2. CHANGES: one question finds the newest write_date at or after the cursor. If it moved, the records of the window
 *     [cursor − overlap, newest] are read by id (again a stable order: a record written while we read leaves the window
 *     and belongs to the next one). The overlap exists because Odoo stamps a record when it is written and commits it
 *     later; every `deep_every_minutes` a much longer overlap covers transactions that stayed open for long.
 *     An unchanged record (same hash) costs no write and produces no change.
 *  3. DELETIONS leave no trace in Odoo. They are found by comparing ids, model by model, on a schedule. The same pass
 *     heals the copy: an id Odoo has and the mirror has not is fetched.
 *
 * Every difference is appended to `odoo_changes` (created / updated with the changed field names / archived /
 * restored / deleted) — the feed the OPS projections consume. The first copy of a model is not a stream of changes
 * and writes none.
 *
 * A model the Odoo user may not read is skipped with that reason; a field Odoo cannot return is dropped with its
 * reason; a network fault backs the model off and the run goes on with the others.
 */
class OdooSync
{
    /** The key of a run's detail that is not a model: what the run applied to the OPS master data. */
    public const MASTER_STEP = '→ OPS master data';

    /** Why a model is not copied (`odoo_models.reason`) — and the mark of a model a person decided to take in. */
    public const TECHNICAL = 'technical model (config odoo.exclude)';

    public const PRIVATE = 'private: people\'s files — pay, contracts, leave, bank accounts (config odoo.private)';

    public const BY_PERSON = 'included by a person';

    private float $deadline = 0.0;

    private ?OdooRun $run = null;

    /** @var array{models:int, pulled:int, created:int, updated:int, deleted:int, errors:int, detail:array<string,array>} */
    private array $stats = ['models' => 0, 'pulled' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0, 'errors' => 0, 'detail' => []];

    public function __construct(private readonly OdooClient $odoo) {}

    // ───────────── a run ─────────────

    /**
     * @param  int  $seconds  how long this run may work
     * @param  ?string[]  $only  restrict the run to these models
     * @return array{ran:bool, reason?:string, runId?:int, models?:int, calls?:int, pulled?:int, created?:int, updated?:int, deleted?:int, errors?:int, ms?:int, pending?:array}
     */
    public function run(int $seconds = 20, string $trigger = 'manual', ?array $only = null): array
    {
        if (! $this->odoo->enabled()) {
            return ['ran' => false, 'reason' => 'disabled'];
        }
        $lock = Cache::lock('odoo:sync', $seconds + 120);
        if (! $lock->get()) {
            return ['ran' => false, 'reason' => 'another run is in progress'];
        }
        $t0 = microtime(true);
        $calls0 = $this->odoo->calls; // the connector may outlive a run (a looping command)
        $this->deadline = $t0 + max(3, $seconds);
        $this->stats = ['models' => 0, 'pulled' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0, 'errors' => 0, 'detail' => []];
        $this->run = OdooRun::create(['trigger' => $trigger, 'started_at' => now()]);
        $fatal = null;
        try {
            if ($only === null && $this->discoveryDue()) {
                $this->discover();
            }
            $this->pass($only);
            $this->project();
            $this->prune();
        } catch (OdooError $e) {
            // sign-in refused or Odoo unreachable before any model was read: nothing else can work in this run
            $fatal = $e->getMessage();
            $this->stats['errors']++;
        } finally {
            $ms = (int) round((microtime(true) - $t0) * 1000);
            $this->run->update(['finished_at' => now(), 'ms' => $ms, 'calls' => $this->odoo->calls - $calls0, 'detail' => $this->detail($fatal)] + array_diff_key($this->stats, ['detail' => 1]));
            $lock->release();
        }

        return ['ran' => true, 'runId' => $this->run->id, 'ms' => $ms, 'calls' => $this->odoo->calls - $calls0, 'error' => $fatal, 'pending' => $this->pending()]
            + array_diff_key($this->stats, ['detail' => 1]);
    }

    /** What is still to be done after a run — a caller that has time may simply run again. */
    public function pending(): array
    {
        return [
            'toInspect' => OdooModel::where('status', 'new')->count(),
            'firstCopy' => OdooModel::where('status', 'active')->where('full_state', '!=', 'done')->count(),
            'windows' => OdooModel::where('status', 'active')->whereNotNull('window_high')->count(),
        ];
    }

    private function pass(?array $only): void
    {
        $scope = fn () => OdooModel::query()->when($only, fn ($q) => $q->whereIn('model', $only))
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()));

        // 1. what changed in the models people work in all day — every run, in their order
        foreach ($scope()->where('status', 'active')->where('full_state', 'done')->where('priority', '<', OdooModel::CORE_BELOW)->orderBy('priority')->orderBy('id')->get() as $m) {
            if (! $this->step($m, fn () => $this->changes($m))) {
                return;
            }
        }
        // what those changes mean for the OPS master data reaches the OPS screens now, before the long work of this run
        $this->project();
        // 2. models not copied yet: inspect them, then copy them — the important ones first, then the small ones.
        //    One very large model must not hold hundreds of small ones back for hours: while others wait behind it, a
        //    model takes at most 60% of the time that is left, and the rest of the run goes to the ones after it.
        $waiting = $scope()->whereIn('status', ['new', 'active'])->where('full_state', '!=', 'done')->orderBy('priority')->orderByRaw('COALESCE(remote_count, 0)')->orderBy('id')->get();
        foreach ($waiting as $i => $m) {
            $until = $i < count($waiting) - 1 ? microtime(true) + 0.6 * max(0.0, $this->deadline - microtime(true)) : null;
            if (! $this->step($m, function () use ($m, $until) {
                if ($m->status === 'new') {
                    $this->inspect($m);
                }
                if ($m->status === 'active') {
                    $this->firstCopy($m, $until);
                }
            })) {
                return;
            }
        }
        // 3. the other models take turns, the one that waited longest first
        foreach ($scope()->where('status', 'active')->where('full_state', 'done')->where('priority', '>=', OdooModel::CORE_BELOW)->orderByRaw('last_synced_at IS NOT NULL')->orderBy('last_synced_at')->orderBy('id')->get() as $m) {
            if (! $this->step($m, fn () => $this->changes($m))) {
                return;
            }
        }
        // 4. deletions, where a check is due
        foreach ($scope()->where('status', 'active')->where('full_state', 'done')->orderByRaw('last_deletion_check_at IS NOT NULL')->orderBy('last_deletion_check_at')->orderBy('priority')->get() as $m) {
            $every = (int) config($m->core() ? 'odoo.deletion_check_minutes' : 'odoo.deletion_check_minutes_other');
            if ($m->deletion_cursor_id === 0 && $m->last_deletion_check_at && $m->last_deletion_check_at->gt(now()->subMinutes($every))) {
                continue;
            }
            if (! $this->step($m, fn () => $this->deletions($m))) {
                return;
            }
        }
    }

    /** Runs one motion of one model; a fault stays with that model. @return bool false when the run's time is over */
    private function step(OdooModel $m, callable $work): bool
    {
        if ($this->timeLeft() <= 0) {
            return false;
        }
        $this->stats['models']++;
        try {
            $work();
            if ($m->consecutive_errors > 0 || $m->last_error) {
                $m->update(['consecutive_errors' => 0, 'last_error' => null, 'next_attempt_at' => null]);
            }
        } catch (OdooError $e) {
            if ($e->kind === 'auth') {
                throw $e;
            }
            $this->stats['errors']++;
            $this->stats['detail'][$m->model]['error'] = $e->getMessage();
            if ($e->kind === 'access' || ($e->kind === 'missing' && $m->full_state !== 'done')) {
                $m->update(['status' => 'skipped', 'reason' => $e->kind === 'access' ? 'no read access for the Odoo user' : 'not available in this Odoo', 'last_error' => $e->getMessage()]);
            } else {
                $n = $m->consecutive_errors + 1;
                $steps = (array) config('odoo.backoff_minutes');
                $m->update(['consecutive_errors' => $n, 'last_error' => $e->getMessage(), 'next_attempt_at' => now()->addMinutes((int) ($steps[min($n, count($steps)) - 1] ?? 60)),
                    'status' => $n >= 8 ? 'error' : $m->status]);
            }
        }

        return $this->timeLeft() > 0;
    }

    // ───────────── discovery ─────────────

    private function discoveryDue(): bool
    {
        return ! OdooModel::query()->exists() || Cache::add('odoo:discovered', now()->toJSON(), now()->addHours((int) config('odoo.rediscover_hours', 24)));
    }

    /** Lists Odoo's models and registers the ones not known yet. Technical models are registered as skipped, with the reason. */
    public function discover(): array
    {
        $rows = $this->odoo->searchRead('ir.model', [['transient', '=', false]], ['model', 'name'], 5000, 'model asc', 0, 60);
        $known = OdooModel::pluck('id', 'model');
        $priority = array_flip(array_values((array) config('odoo.priority')));
        $new = 0;
        foreach ($rows as $r) {
            $model = (string) ($r['model'] ?? '');
            if ($model === '' || isset($known[$model]) || ! preg_match('/^[a-z0-9_.]+$/', $model)) {
                continue;
            }
            $why = self::excluded($model) ? self::TECHNICAL : (self::isPrivate($model) ? self::PRIVATE : null);
            OdooModel::create([
                'model' => $model, 'label' => mb_substr((string) ($r['name'] ?? ''), 0, 190) ?: null, 'status' => $why ? 'skipped' : 'new',
                'reason' => $why, 'page_size' => (int) config('odoo.page_size', 500),
                'priority' => isset($priority[$model]) ? ($priority[$model] + 1) * 10 : 1000,
            ]);
            $new++;
        }
        Cache::put('odoo:discovered', now()->toJSON(), now()->addHours((int) config('odoo.rediscover_hours', 24)));

        return ['listed' => count($rows), 'new' => $new, 'registered' => OdooModel::count()];
    }

    /** A pattern ends with "." or "_" (prefix), starts with "." (suffix), or is a model name (the model and what hangs under it). */
    private static function matches(array $patterns, string $model): bool
    {
        foreach ($patterns as $p) {
            $p = (string) $p;
            if ($p === '') {
                continue;
            }
            if (str_ends_with($p, '.') || str_ends_with($p, '_') ? str_starts_with($model, $p) : (str_starts_with($p, '.') ? str_ends_with($model, $p) : ($model === $p || str_starts_with($model, $p.'.')))) {
                return true;
            }
        }

        return false;
    }

    /** A technical model: Odoo's own machinery, or a model this deployment leaves out (ODOO_EXCLUDE). */
    public static function excluded(string $model): bool
    {
        return ! self::matches((array) config('odoo.include'), $model) && self::matches((array) config('odoo.exclude'), $model);
    }

    /** A model that holds people's files (pay, contracts, leave, bank accounts): not copied unless a person decides so. */
    public static function isPrivate(string $model): bool
    {
        return ! in_array($model, (array) config('odoo.private_allow'), true) && ! self::matches((array) config('odoo.include'), $model)
            && self::matches((array) config('odoo.private'), $model);
    }

    /**
     * Drops the copy of a model and registers it for a fresh one — the way a changed field policy reaches a model that
     * was already copied. Nothing in Odoo is touched. @return int records removed from the mirror
     */
    public function recopy(OdooModel $m): int
    {
        $removed = 0;
        do {
            $removed += $n = OdooRecord::where('model', $m->model)->limit(20000)->delete();
        } while ($n > 0);
        DB::table('odoo_changes')->where('model', $m->model)->delete();
        $m->update(['status' => $m->status === 'paused' ? 'paused' : 'new', 'fields' => null, 'skipped_fields' => null, 'remote_count' => null, 'mirrored_count' => 0,
            'full_state' => 'pending', 'full_cursor_id' => 0, 'full_high_water' => null, 'full_started_at' => null, 'full_finished_at' => null,
            'cursor_write_date' => null, 'window_since' => null, 'window_high' => null, 'window_cursor_id' => 0, 'window_deep' => false,
            'last_synced_at' => null, 'last_deep_at' => null, 'last_changed_at' => now(), 'deletion_cursor_id' => 0, 'last_deletion_check_at' => null,
            'consecutive_errors' => 0, 'last_error' => null, 'next_attempt_at' => null]);

        return $removed;
    }

    /** Reads the model's fields and size and decides what is copied. `new` → `active` or `skipped`. */
    public function inspect(OdooModel $m): void
    {
        // registered before the rule existed, or never decided by a person: people's files stay out
        if ($m->reason !== self::BY_PERSON && self::isPrivate($m->model)) {
            $m->update(['status' => 'skipped', 'reason' => self::PRIVATE]);

            return;
        }
        $defs = $this->odoo->fieldsGet($m->model);
        $never = (string) config('odoo.never_fields');
        $personal = self::matches((array) config('odoo.private'), $m->model) ? (string) config('odoo.personal_fields') : null;
        $skipTypes = (array) config('odoo.skip_types');
        $fields = $skipped = [];
        foreach ($defs as $name => $def) {
            $type = (string) ($def['type'] ?? '');
            if ($name === 'id') {
                continue;
            }
            if (preg_match($never, $name)) {
                $skipped[$name] = 'credential';
            } elseif ($personal && $m->reason !== self::BY_PERSON && preg_match($personal, $name)) {
                $skipped[$name] = 'personal';
            } elseif (in_array($type, $skipTypes, true)) {
                $skipped[$name] = $type;
            } elseif ($type === 'one2many' && config('odoo.skip_one2many')) {
                $skipped[$name] = 'one2many';
            } elseif (($def['store'] ?? true) || $name === 'display_name') {
                $fields[$name] = $type;
            }
        }
        ksort($fields);
        $hasWriteDate = isset($defs['write_date']) && ($defs['write_date']['store'] ?? true);
        $update = ['fields' => $fields, 'skipped_fields' => $skipped ?: null, 'has_write_date' => $hasWriteDate, 'has_active' => ($defs['active']['type'] ?? null) === 'boolean'];
        if (! $hasWriteDate) {
            $m->update($update + ['status' => 'skipped', 'reason' => 'no write_date: a report view, not stored records']);

            return;
        }
        // a person's decision to take the model in is kept: a later re-copy must not undo it
        $m->update($update + ['status' => 'active', 'reason' => $m->reason === self::BY_PERSON ? self::BY_PERSON : null, 'remote_count' => $this->odoo->searchCount($m->model)]);
    }

    // ───────────── 1. first copy ─────────────

    /** @param  ?float  $until  the moment this model must leave the rest of the run to the models behind it */
    private function firstCopy(OdooModel $m, ?float $until = null): void
    {
        if ($m->full_state === 'pending') {
            $top = $this->odoo->searchRead($m->model, [], ['write_date'], 1, 'write_date desc, id desc');
            $m->update(['full_state' => 'running', 'full_cursor_id' => 0, 'full_started_at' => now(), 'full_high_water' => $top[0]['write_date'] ?? null]);
        }
        $detail = &$this->stats['detail'][$m->model];
        $detail['firstCopy'] = ($detail['firstCopy'] ?? 0);
        do {
            $asked = $m->page_size; // read() may widen the next page
            $page = $this->read($m, [['id', '>', $m->full_cursor_id]]);
            if ($page) {
                $this->apply($m, $page, feed: false);
                $detail['firstCopy'] += count($page);
                $m->update(['full_cursor_id' => (int) end($page)['id']]);
            }
            $more = count($page) >= $asked;
        } while ($more && $this->timeLeft() > 0 && ($until === null || microtime(true) < $until));
        if (! $more) {
            // an empty model has no high-water mark: its cursor starts "now", one overlap back covers the walk itself
            $m->update(['full_state' => 'done', 'full_finished_at' => now(), 'cursor_write_date' => $m->full_high_water ?: $this->stamp(now()->subMinutes(5)),
                'last_synced_at' => now(), 'mirrored_count' => OdooRecord::where('model', $m->model)->whereNull('deleted_at')->count()]);
        }
    }

    // ───────────── 2. changes ─────────────

    private function changes(OdooModel $m): void
    {
        if ($m->window_high === null) {
            $deep = ! $m->last_deep_at || $m->last_deep_at->lte(now()->subMinutes((int) config('odoo.deep_every_minutes', 15)));
            $overlap = (int) config($deep ? 'odoo.deep_overlap_seconds' : 'odoo.overlap_seconds');
            $since = $m->cursor_write_date ? $this->shift($m->cursor_write_date, -$overlap) : null;
            $top = $this->odoo->searchRead($m->model, $since ? [['write_date', '>=', $since]] : [], ['write_date'], 1, 'write_date desc, id desc');
            $high = $top[0]['write_date'] ?? null;
            // nothing newer than the cursor: only a deep pass re-reads (it is the one that catches late commits)
            if ($high === null || (! $deep && $m->cursor_write_date && $high <= $m->cursor_write_date)) {
                $m->update(['last_synced_at' => now()] + ($deep ? ['last_deep_at' => now()] : []));

                return;
            }
            $m->update(['window_since' => $since, 'window_high' => $high, 'window_cursor_id' => 0, 'window_deep' => $deep]);
        }
        $detail = &$this->stats['detail'][$m->model];
        $domain = array_values(array_filter([
            $m->window_since ? ['write_date', '>=', $m->window_since] : null,
            ['write_date', '<', $this->shift($m->window_high, 1)], // write_date is printed in seconds: include the whole second
        ]));
        do {
            $asked = $m->page_size;
            $page = $this->read($m, [...$domain, ['id', '>', $m->window_cursor_id]]);
            if ($page) {
                $found = $this->apply($m, $page, feed: true);
                $detail['changed'] = ($detail['changed'] ?? 0) + $found;
                $m->update(['window_cursor_id' => (int) end($page)['id']]);
            }
            $more = count($page) >= $asked;
        } while ($more && $this->timeLeft() > 0);
        if (! $more) {
            $m->update(['cursor_write_date' => max((string) $m->cursor_write_date, (string) $m->window_high), 'last_synced_at' => now(),
                'window_since' => null, 'window_high' => null, 'window_cursor_id' => 0, 'window_deep' => false] + ($m->window_deep ? ['last_deep_at' => now()] : []));
        }
    }

    // ───────────── 3. deletions (and healing) ─────────────

    private function deletions(OdooModel $m): void
    {
        $size = (int) config('odoo.deletion_page', 5000);
        $detail = &$this->stats['detail'][$m->model];
        do {
            $from = $m->deletion_cursor_id;
            $remote = $this->odoo->search($m->model, [['id', '>', $from]], $size);
            $more = count($remote) >= $size;
            $to = $more ? (int) end($remote) : null;
            $local = OdooRecord::where('model', $m->model)->where('odoo_id', '>', $from)->when($to, fn ($q) => $q->where('odoo_id', '<=', $to))
                ->get(['odoo_id', 'deleted_at', 'display_name'])->keyBy('odoo_id');
            $remoteSet = array_flip($remote);

            // gone from Odoo
            $gone = $local->filter(fn ($r) => $r->deleted_at === null && ! isset($remoteSet[$r->odoo_id]));
            if ($gone->isNotEmpty()) {
                $now = now();
                foreach ($gone->chunk(500) as $chunk) {
                    OdooRecord::where('model', $m->model)->whereIn('odoo_id', $chunk->keys()->all())->update(['deleted_at' => $now, 'changed_at' => $now]);
                    DB::table('odoo_changes')->insert($chunk->map(fn ($r) => ['model' => $m->model, 'odoo_id' => $r->odoo_id, 'kind' => 'deleted', 'display_name' => $r->display_name,
                        'run_id' => $this->run?->id, 'seen_at' => $now])->values()->all());
                }
                $this->stats['deleted'] += $gone->count();
                $detail['deleted'] = ($detail['deleted'] ?? 0) + $gone->count();
                $m->update(['last_changed_at' => $now]);
            }
            // in Odoo and not in the mirror (or marked deleted here): fetch it — the copy heals itself
            $absent = array_values(array_filter($remote, fn ($id) => ! isset($local[$id]) || $local[$id]->deleted_at !== null));
            foreach (array_chunk($absent, max(1, $m->page_size)) as $ids) {
                $page = $this->read($m, [['id', 'in', $ids]]);
                $detail['healed'] = ($detail['healed'] ?? 0) + $this->apply($m, $page, feed: true);
            }
            $m->update(['deletion_cursor_id' => $to ?? 0]);
        } while ($more && $this->timeLeft() > 0);
        if (! $more) {
            $m->update(['last_deletion_check_at' => now(), 'remote_count' => $this->odoo->searchCount($m->model),
                'mirrored_count' => OdooRecord::where('model', $m->model)->whereNull('deleted_at')->count()]);
        }
    }

    // ───────────── reading and writing ─────────────

    /**
     * One page of the model, ordered by id. A field Odoo cannot return is found, dropped and remembered, and the page is
     * read again; the page size follows how long Odoo takes to answer.
     *
     * @return array<int, array<string, mixed>>
     */
    private function read(OdooModel $m, array $domain): array
    {
        $fields = array_keys((array) $m->fields);
        $t = microtime(true);
        try {
            $page = $this->odoo->searchRead($m->model, $domain, $fields, $m->page_size, 'id asc', 0, 60);
        } catch (OdooError $e) {
            if (! in_array($e->kind, ['odoo', 'missing'], true)) {
                throw $e;
            }
            $bad = $this->badFields($m, $domain, $fields);
            if (! $bad) {
                if ($m->page_size > 20) {
                    $m->update(['page_size' => max(20, intdiv($m->page_size, 4))]); // a record Odoo cannot serialise: narrow the page, the backoff retries
                }
                throw $e;
            }
            $m->update(['fields' => array_diff_key((array) $m->fields, array_flip($bad)), 'skipped_fields' => ((array) $m->skipped_fields) + array_fill_keys($bad, 'Odoo cannot return it')]);

            return $this->read($m, $domain);
        }
        $took = microtime(true) - $t;
        $max = (int) config('odoo.max_page_size', 2000);
        if ($took > 8 && $m->page_size > 50) {
            $m->update(['page_size' => max(50, intdiv($m->page_size, 2))]);
        } elseif ($took < 1.5 && count($page) >= $m->page_size && $m->page_size < $max) {
            $m->page_size = min($max, $m->page_size * 2); // takes effect on the next page; saved with the cursor
        }
        $this->stats['pulled'] += count($page);

        return $page;
    }

    /** The fields that make a read of this model fail, found by halving. @return string[] */
    private function badFields(OdooModel $m, array $domain, array $fields): array
    {
        if (count($fields) <= 1) {
            return $fields;
        }
        $bad = [];
        foreach (array_chunk($fields, (int) ceil(count($fields) / 2)) as $half) {
            try {
                $this->odoo->searchRead($m->model, $domain, $half, 1, 'id asc', 0, 60);
            } catch (OdooError $e) {
                if (! in_array($e->kind, ['odoo', 'missing'], true)) {
                    throw $e;
                }
                array_push($bad, ...$this->badFields($m, $domain, $half));
            }
        }

        return $bad;
    }

    /**
     * Writes a page into the mirror and, when asked, into the change feed. @return int records that were new or different
     */
    private function apply(OdooModel $m, array $page, bool $feed): int
    {
        if (! $page) {
            return 0;
        }
        $ids = array_map(fn ($r) => (int) $r['id'], $page);
        $existing = OdooRecord::where('model', $m->model)->whereIn('odoo_id', $ids)->get(['odoo_id', 'hash', 'active', 'deleted_at', 'version', 'first_seen_at'])->keyBy('odoo_id');
        $now = now()->format('Y-m-d H:i:s.v');
        $rows = $changes = $changedIds = $fresh = [];
        $types = (array) $m->fields;
        foreach ($page as $rec) {
            $id = (int) $rec['id'];
            $name = isset($rec['display_name']) && is_string($rec['display_name']) ? mb_substr($rec['display_name'], 0, 255) : null;
            // Only what has a value is kept: Odoo prints an empty field as `false` (and an empty list as []), so an absent
            // key means "empty in Odoo". A boolean keeps its false — there it is a value.
            $data = [];
            foreach ($rec as $k => $v) {
                if ($k === 'id' || $k === 'display_name' || $v === null || $v === [] || $v === '' || ($v === false && ($types[$k] ?? null) !== 'boolean')) {
                    continue;
                }
                $data[$k] = $v;
            }
            ksort($data);
            $json = json_encode($data ?: new \stdClass, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
            $hash = sha1($json);
            $was = $existing[$id] ?? null;
            if ($was && $was->hash === $hash && $was->deleted_at === null) {
                continue;
            }
            $active = $m->has_active && array_key_exists('active', $rec) ? (bool) $rec['active'] : null;
            $writeDate = is_string($rec['write_date'] ?? null) ? $rec['write_date'] : null;
            $rows[] = ['model' => $m->model, 'odoo_id' => $id, 'display_name' => $name, 'write_date' => $writeDate, 'active' => $active, 'data' => $json, 'hash' => $hash,
                'version' => $was ? $was->version + 1 : 1, 'first_seen_at' => $was ? $was->first_seen_at->format('Y-m-d H:i:s.v') : $now, 'changed_at' => $now, 'deleted_at' => null];
            $fresh[$id] = ['data' => $data, 'active' => $active, 'name' => $name, 'write_date' => $writeDate];
            if ($was) {
                $changedIds[] = $id;
            } elseif ($feed) {
                $changes[] = ['model' => $m->model, 'odoo_id' => $id, 'kind' => 'created', 'fields' => null, 'display_name' => $name, 'write_date' => $writeDate, 'run_id' => $this->run?->id, 'seen_at' => $now];
            }
        }
        if (! $rows) {
            return 0;
        }
        if ($feed && $changedIds) {
            foreach (OdooRecord::where('model', $m->model)->whereIn('odoo_id', $changedIds)->get(['odoo_id', 'data']) as $old) {
                $new = $fresh[$old->odoo_id];
                $before = (array) $old->data;
                $diff = [];
                foreach ($new['data'] + $before as $k => $_) {
                    if (($new['data'][$k] ?? null) != ($before[$k] ?? null) || array_key_exists($k, $new['data']) !== array_key_exists($k, $before)) {
                        $diff[] = $k;
                    }
                }
                $was = $existing[$old->odoo_id];
                $kind = $was->deleted_at !== null ? 'restored' : ($was->active && $new['active'] === false ? 'archived' : ($was->active === false && $new['active'] ? 'restored' : 'updated'));
                sort($diff);
                $changes[] = ['model' => $m->model, 'odoo_id' => $old->odoo_id, 'kind' => $kind, 'fields' => json_encode(array_values(array_diff($diff, ['write_date', '__last_update']))),
                    'display_name' => $new['name'], 'write_date' => $new['write_date'], 'run_id' => $this->run?->id, 'seen_at' => $now];
            }
        }
        DB::transaction(function () use ($rows, $changes) {
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('odoo_records')->upsert($chunk, ['model', 'odoo_id'], ['display_name', 'write_date', 'active', 'data', 'hash', 'version', 'changed_at', 'deleted_at']);
            }
            foreach (array_chunk($changes, 500) as $chunk) {
                DB::table('odoo_changes')->insert($chunk);
            }
        });
        $created = count($rows) - count($changedIds);
        $this->stats['created'] += $created;
        $this->stats['updated'] += count($changedIds);
        $m->update(['last_changed_at' => $now, 'mirrored_count' => $m->mirrored_count + $created, 'page_size' => $m->page_size]);

        return count($rows);
    }

    /**
     * Master data that changed in Odoo is applied to the OPS master data — only when that projection is switched on.
     * It runs right after the core models were read and again at the end of the run (for what the deletion checks
     * found); it gets a slice of the run's time, and what it cannot finish waits for the next run.
     */
    private function project(): void
    {
        $master = app(OdooMasterData::class);
        if (! $master->continuous() || $this->timeLeft() <= 0 || ! $master->ready()) {
            return;
        }
        try {
            $out = $master->consume(500, max(2.0, min(10.0, $this->timeLeft())));
            if ($out['changes']) {
                $this->stats['errors'] += $out['failed'] ?? 0;
                $was = $this->stats['detail'][self::MASTER_STEP] ?? null;
                if ($was && ! isset($was['error'])) { // the second time in one run: one line for both
                    $out['changes'] += $was['changes'];
                    $out['failed'] = ($out['failed'] ?? 0) + ($was['failed'] ?? 0);
                    foreach ($was['counts'] as $entity => $actions) {
                        foreach ($actions as $what => $n) {
                            $out['counts'][$entity][$what] = ($out['counts'][$entity][$what] ?? 0) + $n;
                        }
                    }
                }
                $this->stats['detail'][self::MASTER_STEP] = $out;
            }
        } catch (\Throwable $e) {
            report($e);
            $this->stats['errors']++;
            $this->stats['detail'][self::MASTER_STEP] = ['error' => mb_substr($e->getMessage(), 0, 300)];
        }
    }

    // ───────────── small things ─────────────

    private function prune(): void
    {
        $days = (int) config('odoo.keep_changes_days', 30);
        if ($days > 0 && $this->timeLeft() > 0 && Cache::add('odoo:pruned', 1, now()->addDay())) {
            DB::table('odoo_changes')->where('seen_at', '<', now()->subDays($days))->delete();
            DB::table('odoo_runs')->where('started_at', '<', now()->subDays($days))->delete();
        }
    }

    /** The run's clock (a test substitutes it to stop a run between two pages). */
    protected function timeLeft(): float
    {
        return $this->deadline - microtime(true);
    }

    /** Odoo prints UTC times as "Y-m-d H:i:s". */
    private function stamp(Carbon $at): string
    {
        return $at->copy()->utc()->format('Y-m-d H:i:s');
    }

    private function shift(string $odooTime, int $seconds): string
    {
        return $this->stamp(Carbon::parse($odooTime, 'UTC')->addSeconds($seconds));
    }

    private function detail(?string $fatal): array
    {
        $rows = array_filter($this->stats['detail'], fn ($d) => array_filter((array) $d));
        // the detail keeps the first 60 models; what the run did to the OPS master data is never the part that is cut
        $master = array_intersect_key($rows, [self::MASTER_STEP => 1]);

        return array_filter(['error' => $fatal, 'models' => $master + array_slice(array_diff_key($rows, $master), 0, 60, true)]);
    }
}
