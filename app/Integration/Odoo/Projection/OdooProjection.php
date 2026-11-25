<?php

namespace App\Integration\Odoo\Projection;

use App\Integration\IntegrationBootstrap;
use App\Integration\Models\IntExternalRef;
use App\Integration\Odoo\Models\OdooChange;
use App\Integration\Odoo\Models\OdooModel;
use App\Integration\Odoo\Models\OdooRecord;
use App\Integration\Odoo\OdooMasterData;
use App\Integration\Services\ExternalRefs;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * From the Odoo mirror to one OPS section: the records Odoo holds for an area (approval requests, leads, employees…)
 * become records of the OPS module that does the same work, so the section is complete on the day Odoo is switched off
 * — and stays current until then.
 *
 * A subclass names the Odoo models it reads and says how ONE mirrored record becomes OPS data (`project()`); this
 * class does the rest, the same way for every area:
 *   plan()     a whole pass that counts and writes nothing;
 *   apply()    a whole pass that writes (idempotent: a second one changes nothing) and marks the feed as taken;
 *   consume()  only what the change feed reports since the last time, within the time it is given. The runs call it
 *              only for a section that took its first whole load (`applied()`): a person applies the plan once.
 * One record that cannot be applied is counted as `failed` with its reason and never stops the others; a pass that
 * fails again and again is stopped, because then the fault is not the record's.
 *
 * WHO OWNS A RECORD. While the area's switch is on (`config('odoo.project.<key>')`) the records that came from Odoo
 * are Odoo's: OPS shows them and does not edit what Odoo owns. Records created in OPS are OPS's. The day the switch is
 * turned off the area is handed over: everything is OPS's from then on. Links are kept in `int_external_refs`
 * (system `odoo`), by identity only.
 */
abstract class OdooProjection
{
    public const SYSTEM = 'odoo';

    /** A whole-mirror pass counts and writes nothing. */
    protected bool $dry = false;

    /** @var array<string, int> */
    private array $counts = [];

    /** @var array<string, string[]> a few examples per finding */
    private array $notes = [];

    private int $failed = 0;

    /** @var array<string, true> */
    private array $seen = [];

    /** @var array<string, array<int|string, string>> per entity: Odoo id → OPS id */
    private array $links = [];

    private ?AuthUser $actor = null;

    public function __construct(protected readonly ExternalRefs $refs) {}

    /** The area: the name of its switch (`odoo.project.<key>`) and of its line in a run's detail. */
    abstract public function key(): string;

    /** The Odoo models this projection reads, parents before children — the order of a whole pass. @return string[] */
    abstract public function models(): array;

    /**
     * Applies ONE mirrored record (as the mirror holds it now: check `deleted_at` and `active`) to the OPS tables.
     * Idempotent. With `$this->dry` it only decides and counts. It reports through `count()`:
     * created · updated · unchanged · archived · skipped<Why> (with a short note for a person when one is needed).
     */
    abstract protected function project(OdooRecord $record): void;

    /** Called once before a pass (load what the pass looks up again and again). */
    protected function begin(): void {}

    // ───────────── for people and for the runs ─────────────

    public function enabled(): bool
    {
        return (bool) config('odoo.project.'.$this->key());
    }

    /** While true, the records that came from Odoo are Odoo's and OPS does not edit what Odoo owns. */
    public static function owns(string $key): bool
    {
        return (bool) config('odoo.enabled') && (bool) config('odoo.project.'.$key);
    }

    /** The mirror holds everything this projection reads. */
    public function ready(): bool
    {
        return OdooModel::whereIn('model', $this->models())->where('status', 'active')->where('full_state', 'done')->count() === count($this->models());
    }

    /** How many changes of Odoo wait to be applied to this area. */
    public function waiting(): int
    {
        return OdooChange::whereIn('model', $this->models())->where('id', '>', $this->cursor())->count();
    }

    /**
     * Where this section stands in the feed: the id of the last change it took. EVERY section keeps its own place, so
     * two sections that read the same Odoo model (an attachment is a receipt of an expense claim AND a paper of an
     * approval request) each get every change, and none of them takes a change away from the master data, which
     * marks its own (`processed_at`).
     */
    private function cursor(): int
    {
        return self::tracked() ? (int) DB::table('odoo_projections')->where('key', $this->key())->value('cursor_id') : 0;
    }

    /** What apply() would do, without doing it. */
    public function plan(): array
    {
        return $this->walk(true);
    }

    /** The whole mirror, idempotent. The first one opens the section to the runs: from then on they feed it. */
    public function apply(): array
    {
        // what the feed held when the pass began is covered by the pass; anything later is the runs' business
        $top = (int) OdooChange::max('id');
        $out = $this->walk(false);
        $this->taken(OdooChange::whereIn('model', $this->models())->where('id', '<=', $top));
        $this->stamp(['cursor_id' => max($top, $this->cursor()), 'last_applied_at' => now(), 'last_counts' => json_encode($out['counts'], JSON_UNESCAPED_UNICODE)], first: true);

        return $out;
    }

    /**
     * For people reading the feed: these changes were taken by a section. Never on the models the master data
     * consumes — there `processed_at` is the master data's own mark, and a section must not spend it.
     */
    private function taken($changes): void
    {
        $changes->whereNotIn('model', OdooMasterData::MODELS)->whereNull('processed_at')->update(['processed_at' => now()]);
    }

    /**
     * The section took its first whole load. Until then the runs leave it alone: a person looks at the plan and applies
     * it once (`scm:odoo-project <area> --apply`), so a section is never half-filled by the changes that arrive first.
     */
    public function applied(): bool
    {
        return (self::states()[$this->key()]['firstAppliedAt'] ?? null) !== null;
    }

    /** @return array<string, array{firstAppliedAt:?string, lastAppliedAt:?string, lastFedAt:?string, lastCounts:?array}> by area */
    public static function states(): array
    {
        if (! self::tracked()) {
            return [];
        }
        $out = [];
        foreach (DB::table('odoo_projections')->get() as $row) {
            $out[$row->key] = ['firstAppliedAt' => $row->first_applied_at, 'lastAppliedAt' => $row->last_applied_at, 'lastFedAt' => $row->last_fed_at,
                'lastCounts' => $row->last_counts ? json_decode($row->last_counts, true) : null];
        }

        return $out;
    }

    /** The state table exists (it arrives with a migration; only a "yes" is remembered for the process). */
    private static function tracked(): bool
    {
        static $yes = false;

        return $yes || ($yes = Schema::hasTable('odoo_projections'));
    }

    private function stamp(array $values, bool $first = false): void
    {
        $key = $this->key();
        if (! DB::table('odoo_projections')->where('key', $key)->exists()) {
            DB::table('odoo_projections')->insertOrIgnore(['key' => $key]);
        }
        DB::table('odoo_projections')->where('key', $key)->update($values);
        if ($first) {
            DB::table('odoo_projections')->where('key', $key)->whereNull('first_applied_at')->update(['first_applied_at' => $values['last_applied_at']]);
        }
    }

    /**
     * What changed in Odoo since the last time, oldest first, for at most `$seconds` (one change at least). What was
     * handled is marked as it goes, so a run that is cut short loses nothing.
     *
     * @return array{changes:int, failed?:int, counts:array<string,int>, notes?:array}
     */
    public function consume(int $max = 500, float $seconds = 8.0): array
    {
        $this->reset(false);
        if (! self::tracked()) {
            return ['changes' => 0, 'counts' => []]; // the release's migration has not run here: there is no place to keep
        }
        $until = microtime(true) + $seconds;
        $cursor = $this->cursor();
        $top = (int) OdooChange::max('id'); // read BEFORE the changes: nothing of ours below it can appear afterwards
        $changes = OdooChange::whereIn('model', $this->models())->where('id', '>', $cursor)->orderBy('id')->limit($max)->get(['id', 'model', 'odoo_id']);
        if ($changes->isEmpty()) {
            if ($top > $cursor) {
                // the feed moved on with changes of other models: step over them once, not at every run
                $this->stamp(['cursor_id' => $top]);
            }

            return ['changes' => 0, 'counts' => []];
        }
        $this->begin();
        $done = [];
        $handled = 0;
        $mark = function () use (&$done) {
            if ($done) {
                $this->taken(OdooChange::whereIn('id', $done));
                $this->stamp(['cursor_id' => end($done)]); // kept as it goes: a run that is cut short loses nothing
                $done = [];
            }
        };
        foreach ($changes as $c) {
            if ($handled > 0 && microtime(true) >= $until) {
                break;
            }
            $key = "{$c->model}:{$c->odoo_id}";
            if (! isset($this->seen[$key])) {
                $this->seen[$key] = true;
                if ($r = OdooRecord::where('model', $c->model)->where('odoo_id', $c->odoo_id)->first()) {
                    $this->guarded($r);
                }
            }
            $done[] = $c->id;
            $handled++;
            if (count($done) >= 50) {
                $mark();
            }
        }
        $mark();
        // everything of ours up to `$top` was taken (the list was not cut by its size nor by the clock): stand at the top
        $this->stamp(['last_fed_at' => now()] + ($handled === $changes->count() && $changes->count() < $max ? ['cursor_id' => max($top, (int) $changes->last()->id)] : []));

        return ['changes' => $handled, 'counts' => $this->counts] + ($this->failed ? ['failed' => $this->failed, 'notes' => $this->notes] : []);
    }

    private function walk(bool $dry): array
    {
        if (! $this->ready()) {
            throw AppError::rule('ODOO_COPY_INCOMPLETE', 'نسخ بيانات هذا القسم من أودو لم يكتمل بعد', 'The copy of this area from Odoo is not complete yet');
        }
        $this->reset($dry);
        $this->begin();
        foreach ($this->models() as $model) {
            foreach (OdooRecord::where('model', $model)->orderBy('odoo_id')->lazy(500) as $r) {
                $this->guarded($r);
            }
        }

        return ['dryRun' => $dry, 'counts' => $this->counts, 'notes' => $this->notes] + ($this->failed ? ['failed' => $this->failed] : []);
    }

    private function guarded(OdooRecord $r): void
    {
        try {
            $this->project($r);
        } catch (\Throwable $e) {
            if (++$this->failed > 40) {
                throw $e;
            }
            report($e);
            $this->count('failed', "{$r->model} #{$r->odoo_id}: ".mb_substr($e instanceof AppError ? $e->messageAr : $e->getMessage(), 0, 160));
        }
    }

    private function reset(bool $dry): void
    {
        $this->dry = $dry;
        $this->counts = $this->notes = $this->seen = $this->links = [];
        $this->failed = 0;
    }

    // ───────────── for the subclass ─────────────

    /** One more record of this outcome; `$note` is an example a person may need (the first fifteen are kept). */
    protected function count(string $what, ?string $note = null): void
    {
        $this->counts[$what] = ($this->counts[$what] ?? 0) + 1;
        if ($note !== null && count($this->notes[$what] ?? []) < 15) {
            $this->notes[$what][] = $note;
        }
    }

    /** The OPS id an Odoo record of `$entity` is linked to (the links of an entity are read once per pass). */
    protected function linked(string $entity, int|string|null $odooId): ?string
    {
        if ($odooId === null || $odooId === '' || $odooId === 0) {
            return null;
        }
        $this->links[$entity] ??= IntExternalRef::where('system', self::SYSTEM)->where('entity', $entity)->pluck('internal_id', 'external_id')->all();

        return $this->links[$entity][$odooId] ?? null;
    }

    /** Records that an Odoo record IS this OPS record. One-to-one in both directions (ExternalRefs refuses otherwise). */
    protected function link(string $entity, int|string $odooId, string $opsId, ?string $code = null): void
    {
        $this->refs->link(self::SYSTEM, $entity, (string) $odooId, $opsId, $code, null, 'svc.odoo');
        $this->linked($entity, $odooId);
        $this->links[$entity][$odooId] = $opsId;
    }

    /** The mirrored record of another model (a parent, a stage, a category), or null. */
    protected function mirrored(string $model, ?int $odooId): ?OdooRecord
    {
        return $odooId ? OdooRecord::where('model', $model)->where('odoo_id', $odooId)->first() : null;
    }

    /** Is the Odoo record still alive (not deleted in Odoo, not archived)? */
    protected function alive(OdooRecord $r): bool
    {
        return $r->deleted_at === null && $r->active !== false;
    }

    /** The service user every change of the projections is attributed to (it cannot sign in). */
    protected function actor(): AuthUser
    {
        if ($this->actor) {
            return $this->actor;
        }
        if (! DB::table('users')->where('username', 'svc.odoo')->exists()) {
            IntegrationBootstrap::ensure(); // the `integration` role
            $id = (string) Str::ulid();
            DB::table('users')->insert(['id' => $id, 'username' => 'svc.odoo', 'password_hash' => Hash::make(Str::random(64)), 'name_ar' => 'تكامل — أودو', 'name_en' => 'Integration — Odoo',
                'initials' => 'OD', 'active' => true, 'must_change_password' => false, 'created_at' => now(), 'updated_at' => now()]);
            if ($role = DB::table('roles')->where('key', 'integration')->value('id')) {
                DB::table('user_roles')->insert(['user_id' => $id, 'role_id' => $role]);
            }
        }

        return $this->actor = IntegrationBootstrap::actor('odoo');
    }
}
