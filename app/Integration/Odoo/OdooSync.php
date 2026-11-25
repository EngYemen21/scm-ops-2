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

    /** Odoo refused the connection user this model's records. Asked again at every discovery: access can be granted. */
    public const NO_ACCESS = 'no read access for the Odoo user';

    /** Why the copy put a model on hold by itself (see guardHolds()). */
    public const GUARD = 'held: many records disappeared from Odoo at once — resume to confirm they are deleted';

    /** The part that indexes the copy for the archive screens; optional — the mirror works without it. */
    private const INDEXER = 'App\\Integration\\Odoo\\Archive\\ArchiveIndexer';

    /**
     * "Every record": a condition on the id that is always true. Some Odoo models narrow a question that does not
     * mention the id (attachments hide the ones that belong to a field); with it every question means the same thing.
     */
    private const ALL = [['id', '>', 0]];

    private float $deadline = 0.0;

    private ?OdooRun $run = null;

    /** @var array<string, int> per model, the highest id Odoo shows — asked once per run for the models walked by id ranges */
    private array $top = [];

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
        $this->top = [];
        $this->run = OdooRun::create(['trigger' => $trigger, 'started_at' => now()]);
        $fatal = null;
        try {
            if ($only === null && $this->discoveryDue()) {
                $this->discover();
            }
            $this->pass($only);
            // what this run found reaches the OPS sections before it ends: a few seconds of their own, so a run that
            // spent all its time reading Odoo does not leave them a whole run behind
            $this->deadline = max($this->deadline, microtime(true) + max(0.0, (float) config('odoo.project_seconds', 6)));
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
                $m->update(['status' => 'skipped', 'reason' => $e->kind === 'access' ? self::NO_ACCESS : 'not available in this Odoo', 'last_error' => $e->getMessage()]);
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
            $why = self::excluded($model) ? self::TECHNICAL : (self::heldBack($model) ? self::PRIVATE : null);
            OdooModel::create([
                'model' => $model, 'label' => mb_substr((string) ($r['name'] ?? ''), 0, 190) ?: null, 'status' => $why ? 'skipped' : 'new',
                'reason' => $why, 'page_size' => (int) config('odoo.page_size', 500),
                'priority' => isset($priority[$model]) ? ($priority[$model] + 1) * 10 : 1000,
            ]);
            $new++;
        }
        // the rules changed since a model was registered (a deployment now copies people's files, or a model moved into
        // `content`): what the rules no longer keep out is taken in — inspected and copied on the next runs
        $admitted = 0;
        foreach (OdooModel::where('status', 'skipped')->whereIn('reason', [self::TECHNICAL, self::PRIVATE])->get() as $m) {
            if (! self::excluded($m->model) && ! self::heldBack($m->model)) {
                $m->update(['status' => 'new', 'reason' => null, 'full_state' => 'pending', 'full_cursor_id' => 0]);
                $admitted++;
            }
        }
        // …and the models Odoo refused the connection user are asked for again (one small question each): the day an
        // administrator of Odoo grants the access, they enter the copy by themselves
        $granted = 0;
        foreach (OdooModel::where('status', 'skipped')->where('reason', self::NO_ACCESS)->orderBy('model')->get() as $m) {
            if (self::excluded($m->model) || self::heldBack($m->model)) {
                continue;
            }
            try {
                $this->odoo->search($m->model, self::ALL, 1);
            } catch (OdooError $e) {
                if ($e->kind === 'auth') {
                    throw $e;
                }

                continue; // still refused (or Odoo did not answer): asked again at the next discovery
            }
            $m->update(['status' => 'new', 'reason' => null, 'last_error' => null, 'consecutive_errors' => 0, 'next_attempt_at' => null, 'full_state' => 'pending', 'full_cursor_id' => 0]);
            $granted++;
        }
        Cache::put('odoo:discovered', now()->toJSON(), now()->addHours((int) config('odoo.rediscover_hours', 24)));

        return ['listed' => count($rows), 'new' => $new, 'admitted' => $admitted, 'granted' => $granted, 'registered' => OdooModel::count()];
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

    /**
     * A technical model: Odoo's own machinery, or a model this deployment leaves out (ODOO_EXCLUDE). The models of
     * `content` are never technical: they carry the history, the attachments list and the dictionary of the copy.
     */
    public static function excluded(string $model): bool
    {
        return ! self::matches((array) config('odoo.include'), $model) && ! in_array($model, (array) config('odoo.content'), true)
            && self::matches((array) config('odoo.exclude'), $model);
    }

    /** A model that holds people's files (pay, contracts, leave, bank accounts): shown only to holders of `odoo.private`. */
    public static function isPrivate(string $model): bool
    {
        return ! in_array($model, (array) config('odoo.private_allow'), true) && self::matches((array) config('odoo.private'), $model);
    }

    /** People's files, or what people wrote to each other: reading its records needs `odoo.private`. */
    public static function isRestricted(string $model): bool
    {
        return self::isPrivate($model) || in_array($model, (array) config('odoo.restricted'), true);
    }

    /** Where a deployment does not copy people's files (ODOO_COPY_PRIVATE=false): registered, not copied, until a person decides. */
    public static function heldBack(string $model): bool
    {
        return ! config('odoo.copy_private') && self::isPrivate($model) && ! self::matches((array) config('odoo.include'), $model);
    }

    /** The personal part of a record of the staff directory (`personal_fields`), by field name. Null where there is none. */
    public static function personalPattern(string $model): ?string
    {
        return self::matches((array) config('odoo.private'), $model) ? (string) config('odoo.personal_fields') : null;
    }

    /**
     * The field policy changed (config) or Odoo gained fields: reads the model's fields again and, when the set of
     * copied fields is not what the copy was made with, reads every record again — the copy stays in place and
     * readable meanwhile. @return bool the records are being read again
     */
    public function reinspect(OdooModel $m): bool
    {
        if ($m->status !== 'active' || $m->full_state !== 'done') {
            return false;
        }
        $was = (array) $m->fields;
        $this->inspect($m);
        $m->refresh();
        if ($m->status !== 'active' || array_keys((array) $m->fields) == array_keys($was)) {
            return false;
        }
        // the first-copy walk upserts: values that are new are added, the rest is recognised as unchanged
        $m->update(['full_state' => 'pending', 'full_cursor_id' => 0, 'full_started_at' => null, 'full_finished_at' => null]);

        return true;
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
        // a deployment that does not copy people's files, and no person decided otherwise for this model: it stays out
        if ($m->reason !== self::BY_PERSON && self::heldBack($m->model)) {
            $m->update(['status' => 'skipped', 'reason' => self::PRIVATE]);

            return;
        }
        $defs = $this->odoo->fieldsGet($m->model);
        $never = (string) config('odoo.never_fields');
        $personal = config('odoo.copy_private') || $m->reason === self::BY_PERSON ? null : self::personalPattern($m->model);
        $skipTypes = (array) config('odoo.skip_types');
        $skipNames = (array) (config('odoo.skip_fields')[$m->model] ?? []);
        $neverNames = (array) (config('odoo.never_model_fields')[$m->model] ?? []);
        $computedAllow = (array) (config('odoo.computed_allow')[$m->model] ?? []);
        $fields = $skipped = [];
        foreach ($defs as $name => $def) {
            $type = (string) ($def['type'] ?? '');
            if ($name === 'id') {
                continue;
            }
            if (preg_match($never, $name) || in_array($name, $neverNames, true)) {
                $skipped[$name] = 'credential';
            } elseif ($personal && preg_match($personal, $name)) {
                $skipped[$name] = 'personal';
            } elseif (in_array($type, $skipTypes, true)) {
                $skipped[$name] = $type;
            } elseif (in_array($name, $skipNames, true)) {
                $skipped[$name] = 'derived';
            } elseif ($type === 'one2many' && config('odoo.skip_one2many')) {
                $skipped[$name] = 'one2many';
            } elseif (($def['store'] ?? true) || $name === 'display_name' || ! empty($def['company_dependent']) || in_array($name, $computedAllow, true)) {
                // a value Odoo keeps per company (a product's cost, a partner's accounts and terms) is not "stored" on
                // the record, yet it is data people entered: it is copied as the Odoo user's company sees it
                $fields[$name] = $type;
            } else {
                $skipped[$name] = 'computed'; // worked out by Odoo from other values each time it is asked: not data of its own
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
        $m->update($update + ['status' => 'active', 'reason' => $m->reason === self::BY_PERSON ? self::BY_PERSON : null, 'remote_count' => $this->odoo->searchCount($m->model, self::ALL)]);
    }

    // ───────────── 1. first copy ─────────────

    /** @param  ?float  $until  the moment this model must leave the rest of the run to the models behind it */
    private function firstCopy(OdooModel $m, ?float $until = null): void
    {
        if ($m->full_state === 'pending') {
            $top = $this->odoo->searchRead($m->model, self::ALL, ['write_date'], 1, 'write_date desc, id desc');
            $m->update(['full_state' => 'running', 'full_cursor_id' => 0, 'full_started_at' => now(), 'full_high_water' => $top[0]['write_date'] ?? null]);
        }
        $detail = &$this->stats['detail'][$m->model];
        $detail['firstCopy'] = ($detail['firstCopy'] ?? 0);
        do {
            if ($m->ranged) {
                // Odoo trims this model's answers AFTER limiting them (it drops what the user may not read), so a short
                // page says nothing: the ids are walked in fixed ranges up to the highest id Odoo shows
                $top = $this->top[$m->model] ??= $this->topId($m);
                $more = $m->full_cursor_id < $top;
                if ($more) {
                    $span = max(50, $m->page_size);
                    $page = $this->read($m, [['id', '>', $m->full_cursor_id], ['id', '<=', $m->full_cursor_id + $span]], $span);
                    if ($page) {
                        $this->apply($m, $page, feed: false);
                        $detail['firstCopy'] += count($page);
                    }
                    $m->update(['full_cursor_id' => $m->full_cursor_id + $span]);
                }

                continue;
            }
            $asked = $m->page_size; // read() may widen the next page
            $page = $this->read($m, [['id', '>', $m->full_cursor_id]]);
            if ($page) {
                $this->apply($m, $page, feed: false);
                $detail['firstCopy'] += count($page);
                $m->update(['full_cursor_id' => (int) end($page)['id']]);
            }
            $more = count($page) >= $asked;
            if (! $more && $this->odoo->search($m->model, [['id', '>', $m->full_cursor_id]], 50, 'id desc')) {
                // a short page and still records beyond it: this model is one Odoo trims after limiting — walk it by ranges
                $m->update(['ranged' => true]);
                $more = true;
            }
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
            $top = $this->odoo->searchRead($m->model, $since ? [['write_date', '>=', $since], ...self::ALL] : self::ALL, ['write_date'], 1, 'write_date desc, id desc');
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
            // a model Odoo trims after limiting: only an empty page is the end
            $more = $m->ranged ? count($page) > 0 : count($page) >= $asked;
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
            if ($m->ranged) {
                // fixed id ranges, for the same reason as in the first copy: a short answer is not the end of the model
                $top = $this->top[$m->model] ??= max($this->topId($m), (int) OdooRecord::where('model', $m->model)->max('odoo_id'));
                $to = $from + $size;
                $remote = $this->odoo->search($m->model, [['id', '>', $from], ['id', '<=', $to]], $size);
                $more = $to < $top;
            } else {
                $remote = $this->odoo->search($m->model, [['id', '>', $from]], $size);
                $more = count($remote) >= $size;
                $to = $more ? (int) end($remote) : null;
            }
            $local = OdooRecord::where('model', $m->model)->where('odoo_id', '>', $from)->when($to, fn ($q) => $q->where('odoo_id', '<=', $to))
                ->get(['odoo_id', 'deleted_at', 'display_name'])->keyBy('odoo_id');
            $remoteSet = array_flip($remote);

            // gone from Odoo
            $gone = $local->filter(fn ($r) => $r->deleted_at === null && ! isset($remoteSet[$r->odoo_id]));
            if ($gone->isNotEmpty() && $this->guardHolds($m, $gone->count(), $local->filter(fn ($r) => $r->deleted_at === null)->count())) {
                return;
            }
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
                $this->indexed($m->model, $gone->keys()->all());
            }
            // in Odoo and not in the mirror (or marked deleted here): fetch it — the copy heals itself
            $absent = array_values(array_filter($remote, fn ($id) => ! isset($local[$id]) || $local[$id]->deleted_at !== null));
            foreach (array_chunk($absent, max(1, $m->page_size)) as $ids) {
                $page = $this->read($m, [['id', 'in', $ids]]);
                $detail['healed'] = ($detail['healed'] ?? 0) + $this->apply($m, $page, feed: true);
            }
            $m->update(['deletion_cursor_id' => $more ? $to : 0]);
        } while ($more && $this->timeLeft() > 0);
        if (! $more) {
            $m->update(['last_deletion_check_at' => now(), 'remote_count' => $this->odoo->searchCount($m->model, self::ALL),
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
    private function read(OdooModel $m, array $domain, ?int $limit = null): array
    {
        $fields = array_keys((array) $m->fields);
        $t = microtime(true);
        try {
            $page = $this->odoo->searchRead($m->model, $domain, $fields, $limit ?? $m->page_size, 'id asc', 0, 60);
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

            return $this->read($m, $domain, $limit);
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
     * What is kept of a record Odoo returned. Only what has a value: Odoo prints an empty field as `false` (and an empty
     * list as []), so an absent key means "empty in Odoo". A boolean keeps its false — there it is a value.
     *
     * @param  array<string, string>  $types  field name → Odoo type
     */
    public static function keep(array $rec, array $types): array
    {
        $data = [];
        foreach ($rec as $k => $v) {
            if ($k === 'id' || $k === 'display_name' || $v === null || $v === [] || $v === '' || ($v === false && ($types[$k] ?? null) !== 'boolean')) {
                continue;
            }
            $data[$k] = $v;
        }
        ksort($data);

        return $data;
    }

    /**
     * The highest id Odoo shows for a model. The fifty newest are asked for, so that a few records the user may not
     * read at the very top do not hide the rest.
     */
    private function topId(OdooModel $m): int
    {
        $ids = $this->odoo->search($m->model, self::ALL, 50, 'id desc');

        return $ids ? max($ids) : 0;
    }

    /** The archive's index follows the copy (App\Integration\Odoo\Archive\ArchiveIndexer, where that part is installed). */
    private function indexed(string $model, array $ids): void
    {
        if (! $ids || ! class_exists(self::INDEXER)) {
            return;
        }
        try {
            app(self::INDEXER)->index($model, array_map('intval', $ids));
        } catch (\Throwable $e) {
            report($e); // the index can be rebuilt (scm:odoo-archive-index); the copy must never wait for it
        }
    }

    /**
     * Many records of a model gone from Odoo at once is rarely what happened: far more often the Odoo user lost the
     * right to see them, or the database behind the address is not the same one any more. The copy is a backup — it
     * does not follow such a change by itself: nothing is marked, the model is put on hold with the reason, and a
     * person decides (resuming the model from its panel confirms the deletions).
     */
    private function guardHolds(OdooModel $m, int $gone, int $live): bool
    {
        if ($gone < (int) config('odoo.deletion_guard.min', 50) || $gone < (float) config('odoo.deletion_guard.share', 0.3) * max(1, $live)
            || Cache::get("odoo:guard-ok:{$m->model}")) {
            return false;
        }
        $m->update(['status' => 'paused', 'reason' => self::GUARD, 'last_error' => "{$gone} of {$live} records are no longer returned by Odoo"]);
        $this->stats['errors']++;
        $this->stats['detail'][$m->model]['error'] = "held: {$gone} of {$live} records disappeared from Odoo at once — nothing was marked deleted";

        return true;
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
            $data = self::keep($rec, $types);
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
        $this->indexed($m->model, array_column($rows, 'odoo_id'));

        return count($rows);
    }

    /**
     * Master data that changed in Odoo is applied to the OPS master data — only when that projection is switched on.
     * It runs right after the core models were read and again at the end of the run (for what the deletion checks
     * found); it gets a slice of the run's time, and what it cannot finish waits for the next run.
     */
    private function project(): void
    {
        $this->projectMaster();
        // the OPS sections that follow Odoo (config odoo.projections): each takes from the feed what concerns it
        foreach ((array) config('odoo.projections') as $key => $class) {
            $step = "→ OPS {$key}";
            try {
                // not installed here, handed over to OPS, or still waiting for its first whole load (a person applies the
                // plan once: `scm:odoo-project <area> --apply`) — nothing to feed, and that is not an error
                if (! class_exists($class) || ! ($projection = app($class))->enabled() || ! $projection->applied()) {
                    continue;
                }
                if ($this->timeLeft() <= 0) {
                    return;
                }
                if (! $projection->ready()) {
                    continue;
                }
                $out = $projection->consume(500, max(2.0, min(8.0, $this->timeLeft())));
                if ($out['changes']) {
                    $this->stats['errors'] += $out['failed'] ?? 0;
                    $was = $this->stats['detail'][$step] ?? null;
                    if ($was && ! isset($was['error'])) { // the second time in one run: one line for both
                        $out['changes'] += $was['changes'];
                        $out['failed'] = ($out['failed'] ?? 0) + ($was['failed'] ?? 0);
                        foreach ($was['counts'] as $what => $n) {
                            $out['counts'][$what] = ($out['counts'][$what] ?? 0) + $n;
                        }
                    }
                    $this->stats['detail'][$step] = $out;
                }
            } catch (\Throwable $e) {
                report($e);
                $this->stats['errors']++;
                $this->stats['detail'][$step] = ['error' => mb_substr($e->getMessage(), 0, 300)];
            }
        }
    }

    private function projectMaster(): void
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
        $master = array_filter($rows, fn ($key) => str_starts_with((string) $key, '→ '), ARRAY_FILTER_USE_KEY);

        return array_filter(['error' => $fatal, 'models' => $master + array_slice(array_diff_key($rows, $master), 0, 60, true)]);
    }
}
