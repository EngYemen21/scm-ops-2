<?php

namespace App\Integration\Odoo;

use App\Integration\Odoo\Models\OdooChange;
use App\Integration\Odoo\Models\OdooModel;
use App\Integration\Odoo\Models\OdooRecord;
use App\Integration\Odoo\Models\OdooRun;
use App\Integration\Odoo\Models\OdooVerification;
use App\Integration\Odoo\Projection\OdooProjection;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Support\Sql;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * The read side of the Odoo mirror for people (the "Odoo" tab of the integration tower): is the connection alive, how
 * far is the copy, what changed, and what exactly does the mirror hold for one record. Never shows the key.
 */
class OdooMirror
{
    public function __construct(private readonly OdooClient $odoo) {}

    /** The mirror's tables exist on this server (the code can be deployed before its migration has run). */
    public function installed(): bool
    {
        return (bool) (Cache::get('odoo:installed') ?: (Schema::hasTable('odoo_models') && Cache::forever('odoo:installed', true)));
    }

    public function status(bool $fresh = false): array
    {
        if (! $this->installed()) {
            // say so instead of failing on a missing table: the screen then shows one line and asks nothing else
            return ['installed' => false, 'connection' => $this->odoo->describe() + ['ok' => false, 'error' => null, 'version' => null, 'transport' => null, 'user' => null, 'checkedAt' => null]];
        }
        $models = OdooModel::query()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
        $active = OdooModel::where('status', 'active');
        $copied = (clone $active)->where('full_state', 'done')->count();
        $coreOldest = OdooModel::where('status', 'active')->where('full_state', 'done')->where('priority', '<', OdooModel::CORE_BELOW)->min('last_synced_at');
        $since = now()->subDay();
        $iso = fn ($at) => $at ? Carbon::parse($at)->toJSON() : null; // an aggregate comes back as text: every time leaves here in one spelling
        $newest = OdooRun::orderByDesc('id')->first();
        // what people read is the last run that finished; the one working now has no figures yet
        $lastRun = $newest?->finished_at ? $newest : OdooRun::whereNotNull('finished_at')->orderByDesc('id')->first();

        return [
            'installed' => true,
            'connection' => $this->odoo->describe() + $this->connection($fresh),
            // a run that started and has not written its end yet (one that died leaves its lock for a few minutes at most)
            'sync' => ['running' => $newest !== null && $newest->finished_at === null && $newest->started_at->gt(now()->subMinutes(5))],
            'models' => ['active' => (int) ($models['active'] ?? 0), 'new' => (int) ($models['new'] ?? 0), 'skipped' => (int) ($models['skipped'] ?? 0),
                'paused' => (int) ($models['paused'] ?? 0), 'error' => (int) ($models['error'] ?? 0), 'failing' => OdooModel::where('consecutive_errors', '>', 0)->count()],
            'firstCopy' => [
                'done' => $copied, 'total' => (clone $active)->count() + (int) ($models['new'] ?? 0),
                'records' => (int) OdooModel::query()->sum('mirrored_count'), // kept per model by the runs: no count over the whole copy
                'expected' => (int) (clone $active)->sum('remote_count'),
                'complete' => (int) ($models['new'] ?? 0) === 0 && $copied === (clone $active)->count() && $copied > 0,
            ],
            'changes' => [
                'last24h' => OdooChange::where('seen_at', '>=', $since)->count(),
                'byKind' => (object) OdooChange::where('seen_at', '>=', $since)->selectRaw('kind, COUNT(*) AS n')->groupBy('kind')->pluck('n', 'kind')->map(fn ($n) => (int) $n)->all(),
                // changes of products, categories and partners that the OPS master data has not taken yet
                'unprocessed' => OdooChange::whereIn('model', OdooMasterData::MODELS)->whereNull('processed_at')->count(),
                'lastAt' => $iso(OdooChange::max('seen_at')),
            ],
            'freshness' => [
                'coreOldestSyncAt' => $iso($coreOldest),
                'coreLagSeconds' => $coreOldest ? max(0, (int) now()->diffInSeconds(Carbon::parse($coreOldest), true)) : null,
            ],
            'lastRun' => $lastRun?->toArray(),
            // the copy as a backup: when it was last compared with Odoo and with what result, and how much of the
            // attachments' content OPS keeps
            'backup' => [
                'verification' => Schema::hasTable('odoo_verifications') ? OdooVerification::whereNotNull('finished_at')->orderByDesc('id')->first()?->toArray() : null,
                'files' => Schema::hasTable('odoo_files') ? app(OdooFiles::class)->status() : null,
                'onHold' => OdooModel::where('reason', OdooSync::GUARD)->pluck('model')->all(),
            ],
            // the OPS sections that follow Odoo: did each take its first whole load, when was it last fed, what waits
            'sections' => $this->sections(),
            'policy' => [
                'runSeconds' => (int) config('odoo.run_seconds'), 'overlapSeconds' => (int) config('odoo.overlap_seconds'),
                'deepEveryMinutes' => (int) config('odoo.deep_every_minutes'), 'deletionCheckMinutes' => (int) config('odoo.deletion_check_minutes'),
                'deletionCheckMinutesOther' => (int) config('odoo.deletion_check_minutes_other'), 'keepChangesDays' => (int) config('odoo.keep_changes_days'),
                'readOnlyMethods' => OdooClient::READ_METHODS,
            ],
        ];
    }

    /**
     * The OPS sections built for what Odoo has (config odoo.projections) and where each stands. A section whose code is
     * not installed on this server is left out. Remembered for half a minute: the open screen asks every few seconds.
     *
     * @return array<int, array{key:string, follows:bool, copied:bool, loadedAt:?string, lastLoadAt:?string, fedAt:?string, waiting:int}>
     */
    private function sections(): array
    {
        return Cache::remember('odoo:sections', now()->addSeconds(30), function () {
            $iso = fn ($at) => $at ? Carbon::parse($at)->toJSON() : null;
            $states = OdooProjection::states();
            $out = [];
            foreach ((array) config('odoo.projections') as $key => $class) {
                if (! class_exists($class)) {
                    continue;
                }
                $p = app($class);
                $copied = $p->ready();
                $state = $states[$key] ?? [];
                $out[] = ['key' => (string) $key, 'follows' => $p->enabled(), 'copied' => $copied, 'loadedAt' => $iso($state['firstAppliedAt'] ?? null),
                    'lastLoadAt' => $iso($state['lastAppliedAt'] ?? null), 'fedAt' => $iso($state['lastFedAt'] ?? null), 'waiting' => $copied ? $p->waiting() : 0];
            }

            return $out;
        });
    }

    /** Is Odoo answering, which version, who is the user behind the key — remembered for a minute. */
    private function connection(bool $fresh): array
    {
        if (! $this->odoo->configured()) {
            return ['ok' => false, 'error' => null, 'version' => null, 'transport' => null, 'user' => null, 'checkedAt' => null];
        }
        if ($fresh) {
            Cache::forget('odoo:connection');
        }
        // A run that has just read from Odoo is the proof that the connection works: Odoo is not asked again for it
        // (the question would wait behind the mirror's own reads, and this answer is polled by an open screen).
        $proof = $fresh ? null : OdooRun::whereNotNull('finished_at')->orderByDesc('id')->first();
        if ($proof && $proof->finished_at->gt(now()->subMinutes(5)) && empty($proof->detail['error']) && ($identity = Cache::get('odoo:identity'))) {
            return ['ok' => true, 'error' => null, 'checkedAt' => $proof->finished_at->toJSON()] + $identity;
        }

        return Cache::remember('odoo:connection', now()->addMinute(), function () {
            try {
                $v = $this->odoo->version();
                $identity = ['version' => $v['version'], 'transport' => $v['transport'], 'user' => $this->odoo->whoAmI()];
                Cache::put('odoo:identity', $identity, now()->addDay());

                return ['ok' => true, 'error' => null, 'checkedAt' => now()->toJSON()] + $identity;
            } catch (OdooError $e) {
                return ['ok' => false, 'error' => $e->getMessage(), 'errorKind' => $e->kind, 'version' => null, 'transport' => null, 'user' => null, 'checkedAt' => now()->toJSON()];
            }
        });
    }

    /** @param  array{status?:?string, state?:?string, hasRecords?:?string}  $f  hasRecords=1: only models the mirror holds records of */
    public function models(Paging $page, array $f): array
    {
        $q = OdooModel::query()->when($f['status'] ?? null, fn ($x, $v) => $x->where('status', $v))
            ->when(($f['hasRecords'] ?? null) === '1', fn ($x) => $x->where('mirrored_count', '>', 0))
            ->when(($f['state'] ?? null) === 'copying', fn ($x) => $x->where('status', 'active')->where('full_state', '!=', 'done'))
            ->when(($f['state'] ?? null) === 'failing', fn ($x) => $x->where('consecutive_errors', '>', 0));
        if ($page->q) {
            $like = '%'.$page->q.'%';
            $q->where(fn ($x) => $x->where('model', Sql::like(), $like)->orWhere('label', Sql::like(), $like));
        }
        $sort = in_array($page->sort, ['model', 'mirrored_count', 'remote_count', 'last_synced_at', 'last_changed_at', 'priority'], true) ? $page->sort : 'priority';

        return $page->paginate($q->orderBy($sort, $page->sort ? $page->order : 'asc')->orderBy('model'), fn (OdooModel $m) => $this->modelRow($m));
    }

    public function model(string $model): array
    {
        $m = $this->find($model);
        $open = ! OdooSync::isRestricted($m->model) || $this->mayReadPrivate();

        return $this->modelRow($m) + ['fields' => $m->fields, 'skippedFields' => $m->skipped_fields, 'restricted' => OdooSync::isRestricted($m->model), 'readable' => $open,
            'recentChanges' => $open ? OdooChange::where('model', $m->model)->orderByDesc('id')->limit(20)->get()->toArray() : []];
    }

    // ───────────── people's files ─────────────

    /**
     * People's files (pay, contracts, leave, bank accounts) and what people wrote to each other are part of the copy —
     * it is the company's backup — and are shown only to a user who holds `odoo.private`.
     */
    public function mayReadPrivate(): bool
    {
        return (bool) AuthUser::currentOrNull()?->can('odoo.private');
    }

    private function guard(string $model): void
    {
        if (OdooSync::isRestricted($model) && ! $this->mayReadPrivate()) {
            throw AppError::forbidden('ODOO_PRIVATE', 'سجلات هذا النموذج ملفات أشخاص أو مراسلات — عرضها يحتاج صلاحية odoo.private', 'These records are people\'s files or correspondence — reading them needs odoo.private');
        }
    }

    /** The registered models whose records the current user may not read ([] for a holder of `odoo.private`). @return string[] */
    private function closedModels(): array
    {
        return $this->mayReadPrivate() ? [] : OdooModel::pluck('model')->filter(fn ($m) => OdooSync::isRestricted($m))->values()->all();
    }

    /**
     * A record without what is personal in it, for a user who may not read people's files: the personal part of the
     * staff directory, and identity and bank-account numbers in whatever model they stand (`odoo.identity_fields`).
     */
    private function withoutPersonal(string $model, array $data): array
    {
        if ($this->mayReadPrivate()) {
            return $data;
        }
        $patterns = array_values(array_filter([OdooSync::personalPattern($model), (string) config('odoo.identity_fields')]));

        return $patterns ? array_filter($data, fn ($key) => ! array_filter($patterns, fn (string $p) => preg_match($p, (string) $key)), ARRAY_FILTER_USE_KEY) : $data;
    }

    /** Pause, resume or (re)prioritise one model. A skipped model is taken into the mirror by setting it active. */
    public function setModel(string $model, array $dto): array
    {
        $m = $this->find($model);
        $update = [];
        if (isset($dto['status'])) {
            if ($dto['status'] === 'active' && in_array($m->status, ['skipped', 'error'], true)) {
                // inspected on the next run; taking a skipped model in is a person's decision and is remembered as one
                $update += ['status' => 'new', 'reason' => $m->status === 'skipped' ? OdooSync::BY_PERSON : $m->reason, 'consecutive_errors' => 0, 'last_error' => null, 'next_attempt_at' => null];
            } elseif (in_array($dto['status'], ['active', 'paused'], true) && in_array($m->status, ['active', 'paused', 'new'], true)) {
                if ($dto['status'] === 'active' && $m->reason === OdooSync::GUARD) {
                    // a person looked: the records that disappeared from Odoo are to be marked deleted on the next check
                    Cache::put("odoo:guard-ok:{$m->model}", 1, now()->addHours(6));
                    $update += ['last_error' => null, 'deletion_cursor_id' => 0];
                }
                $update += ['status' => $dto['status'] === 'paused' ? 'paused' : ($m->fields ? 'active' : 'new'), 'reason' => $dto['status'] === 'paused' ? 'paused by a person' : null];
            }
        }
        if (array_key_exists('core', $dto)) {
            $update['priority'] = $dto['core'] ? min($m->priority, 900) : max($m->priority, 1000);
        }
        if ($update) {
            $m->update($update);
        }

        return $this->model($m->model);
    }

    /** @param  array{model?:?string, deleted?:?string}  $f */
    public function records(Paging $page, array $f): array
    {
        if (empty($f['model'])) {
            throw AppError::validation('MODEL_REQUIRED', 'اختر نموذج أودو أولًا', 'Pick an Odoo model first');
        }
        $this->guard((string) $f['model']);
        $q = OdooRecord::query()->where('model', $f['model'])
            ->when(($f['deleted'] ?? null) === '1', fn ($x) => $x->whereNotNull('deleted_at'), fn ($x) => $x->whereNull('deleted_at'));
        if ($page->q) {
            $q->where(fn ($x) => $x->where('display_name', Sql::like(), '%'.$page->q.'%')->when(ctype_digit($page->q), fn ($y) => $y->orWhere('odoo_id', (int) $page->q)));
        }
        $sort = $page->sort === 'odooId' ? 'odoo_id' : 'changed_at';
        $q->select(['id', 'model', 'odoo_id', 'display_name', 'write_date', 'active', 'version', 'first_seen_at', 'changed_at', 'deleted_at'])->orderBy($sort, $page->order)->orderByDesc('id');
        if ($page->q || ($f['deleted'] ?? null) === '1') {
            return $page->paginate($q);
        }

        // the whole model: the runs keep its size, so hundreds of thousands of records are not counted for every page
        return $page->wrap($q->forPage($page->page, $page->pageSize)->get()->toArray(), (int) OdooModel::where('model', $f['model'])->value('mirrored_count'));
    }

    public function record(string $model, int $odooId): array
    {
        $this->guard($model);
        $r = OdooRecord::where('model', $model)->where('odoo_id', $odooId)->first()
            ?? throw AppError::notFound('ODOO_RECORD_NOT_FOUND', "السجل {$model} #{$odooId} غير موجود في النسخة", "{$model} #{$odooId} is not in the mirror");
        $row = $r->toArray();
        $row['data'] = $this->withoutPersonal($model, (array) $r->data);

        return $row + ['fieldTypes' => OdooModel::where('model', $model)->value('fields'),
            'history' => OdooChange::where('model', $model)->where('odoo_id', $odooId)->orderByDesc('id')->limit(50)->get()->toArray()];
    }

    /** @param  array{model?:?string, kind?:?string}  $f */
    public function changes(Paging $page, array $f): array
    {
        if (! empty($f['model'])) {
            $this->guard((string) $f['model']);
        }
        $q = OdooChange::query()->when($f['model'] ?? null, fn ($x, $v) => $x->where('model', $v))->when($f['kind'] ?? null, fn ($x, $v) => $x->where('kind', $v))
            ->when($this->closedModels(), fn ($x, $closed) => $x->whereNotIn('model', $closed)); // the feed of all models leaves people's files out for who may not read them
        if ($page->q) {
            $q->where(fn ($x) => $x->where('display_name', Sql::like(), '%'.$page->q.'%')->when(ctype_digit($page->q), fn ($y) => $y->orWhere('odoo_id', (int) $page->q)));
        }

        return $page->paginate($q->orderByDesc('id'));
    }

    public function runs(Paging $page): array
    {
        return $page->paginate(OdooRun::query()->orderByDesc('id'));
    }

    private function find(string $model): OdooModel
    {
        return OdooModel::where('model', $model)->first()
            ?? throw AppError::notFound('ODOO_MODEL_NOT_FOUND', "نموذج أودو {$model} غير مسجل", "Odoo model {$model} is not registered");
    }

    private function modelRow(OdooModel $m): array
    {
        $row = array_diff_key($m->toArray(), ['fields' => 1, 'skippedFields' => 1]);

        return $row + [
            'core' => $m->core(), 'fieldCount' => count((array) $m->fields), 'skippedFieldCount' => count((array) $m->skipped_fields),
            'copyPct' => $m->full_state === 'done' ? 100 : ($m->remote_count ? min(99, (int) floor($m->mirrored_count / max(1, $m->remote_count) * 100)) : 0),
        ];
    }
}
