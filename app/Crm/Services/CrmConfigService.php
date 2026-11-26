<?php

namespace App\Crm\Services;

use App\Crm\Models\CrmActivity;
use App\Crm\Models\CrmLead;
use App\Crm\Models\CrmLostReason;
use App\Crm\Models\CrmStage;
use App\Crm\Models\CrmTag;
use App\Crm\Models\CrmTeam;
use App\Crm\Support\Owned;
use App\Crm\Support\People;
use App\Crm\Support\Shape;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * What the pipeline is made of: stages, sales teams, lost reasons and tags — and the lookups every screen of the
 * section starts from. Nothing is deleted: a stage, a team, a reason or a tag that is no longer wanted is archived
 * (`active = false`) and stays on the leads that carry it. Payloads are camelCase and already validated; a key that
 * is absent means "leave as it is".
 */
class CrmConfigService
{
    public const PRIORITIES = [0 => ['عادية', 'Normal'], 1 => ['متوسطة', 'Medium'], 2 => ['عالية', 'High'], 3 => ['عالية جدًا', 'Very high']];

    public function __construct(private readonly AuditService $audit) {}

    /** Everything the forms and the filters of the section offer. */
    public function lookups(): array
    {
        $owners = CrmLead::query()->toBase()->selectRaw('owner_id, owner_name, COUNT(*) AS n')->where(fn ($w) => $w->whereNotNull('owner_id')->orWhereNotNull('owner_name'))
            ->groupBy('owner_id', 'owner_name')->get();
        $users = DB::table('users')->whereIn('id', $owners->pluck('owner_id')->filter()->unique()->values())->get(['id', 'username', 'name_ar', 'name_en'])->keyBy('id');

        return [
            // the section follows Odoo: what came from Odoo is read-only here, OPS adds notes and to-dos
            'follows' => Owned::following(),
            'stages' => CrmStage::query()->ordered()->get()->map(fn (CrmStage $s) => Shape::stage($s))->all(),
            'teams' => CrmTeam::with('leader:id,username,name_ar,name_en')->orderBy('sequence')->orderBy('name_ar')->orderBy('id')->get()->map(fn (CrmTeam $t) => Shape::team($t))->all(),
            'lostReasons' => CrmLostReason::orderBy('name_ar')->orderBy('id')->get()->map(fn (CrmLostReason $r) => Shape::reason($r))->all(),
            'tags' => CrmTag::orderBy('name')->orderBy('id')->get()->map(fn (CrmTag $t) => Shape::tag($t))->all(),
            // who may be given a lead or a to-do …
            'users' => People::salespeople(),
            // … and who holds leads today (OPS users and the salespeople Odoo names), for the filter
            'owners' => $owners->map(fn ($o) => Shape::person($o->owner_id, $o->owner_id ? ($users[$o->owner_id] ?? null) : null, $o->owner_name) + ['count' => (int) $o->n])
                ->sortByDesc('count')->values()->all(),
            'activityTypes' => collect(CrmActivity::TYPES)->map(fn (array $l, string $k) => ['key' => $k, 'ar' => $l[0], 'en' => $l[1]])->values()->all(),
            'priorities' => collect(self::PRIORITIES)->map(fn (array $l, int $k) => ['key' => $k, 'ar' => $l[0], 'en' => $l[1]])->values()->all(),
            'sources' => CrmLead::whereNotNull('source')->distinct()->orderBy('source')->limit(60)->pluck('source')->all(),
        ];
    }

    // ───────────── stages ─────────────

    public function createStage(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            $won = (bool) ($dto['isWon'] ?? false);
            $s = CrmStage::create([
                'name_ar' => trim($dto['nameAr']), 'name_en' => trim((string) ($dto['nameEn'] ?? '')) ?: trim($dto['nameAr']),
                'sequence' => $dto['sequence'] ?? ((int) CrmStage::max('sequence') + 1), 'is_won' => $won, 'fold' => (bool) ($dto['fold'] ?? false),
                'probability' => $won ? 100 : ($dto['probability'] ?? null), 'requirements' => ($dto['requirements'] ?? null) ?: null, 'active' => true, 'created_by' => $user->username,
            ]);
            $this->audit->log($user, ['action' => 'CRM.STAGE.CREATE', 'entityType' => 'CrmStage', 'entityId' => $s->id, 'entityNumber' => $s->name_ar, 'newValue' => $dto]);

            return Shape::stage($s->refresh());
        });
    }

    /**
     * A stage that holds opportunities is not archived and does not change its meaning (won / not won) under them;
     * the pipeline always keeps one open stage and the archive never takes the last one.
     */
    public function updateStage(AuthUser $user, string $id, array $dto): array
    {
        return DB::transaction(function () use ($user, $id, $dto) {
            $s = CrmStage::whereKey($id)->lockForUpdate()->first() ?? throw AppError::notFound('CRM_STAGE_NOT_FOUND', 'المرحلة غير موجودة', 'Stage not found');
            Owned::refuse($s, 'stage');
            $data = [];
            foreach (['nameAr' => 'name_ar', 'nameEn' => 'name_en', 'sequence' => 'sequence', 'isWon' => 'is_won', 'fold' => 'fold', 'probability' => 'probability', 'requirements' => 'requirements', 'active' => 'active'] as $key => $column) {
                // an order and a yes / no cannot be "emptied": sent empty they are left as they are
                if (array_key_exists($key, $dto) && ! (in_array($key, ['sequence', 'isWon', 'fold', 'active'], true) && $dto[$key] === null)) {
                    $data[$column] = is_string($dto[$key]) ? (trim($dto[$key]) === '' ? null : trim($dto[$key])) : $dto[$key];
                }
            }
            if (array_key_exists('name_ar', $data) && $data['name_ar'] === null) {
                throw AppError::validation('CRM_STAGE_NAME', 'اسم المرحلة إلزامي', 'The stage needs a name', [['path' => 'nameAr', 'message' => 'هذا الحقل إلزامي']]);
            }
            if (array_key_exists('name_en', $data) && $data['name_en'] === null) {
                $data['name_en'] = $data['name_ar'] ?? $s->name_ar;
            }
            $archiving = array_key_exists('active', $data) && ! $data['active'] && $s->active;
            $flipping = array_key_exists('is_won', $data) && (bool) $data['is_won'] !== $s->is_won;
            if ($archiving || $flipping) {
                $held = CrmLead::where('stage_id', $s->id)->where('archived', false)->whereIn('status', $archiving ? ['open'] : ['open', 'won'])->count();
                if ($held > 0) {
                    throw AppError::rule('CRM_STAGE_IN_USE', "في هذه المرحلة {$held} فرصة — انقلها إلى مرحلة أخرى أولًا", "{$held} opportunities are in this stage — move them to another stage first", ['leads' => $held]);
                }
            }
            $openAfter = ($data['active'] ?? $s->active) && ! ($data['is_won'] ?? $s->is_won);
            if (($archiving || $flipping) && ! $openAfter && ! CrmStage::where('id', '!=', $s->id)->where('active', true)->where('is_won', false)->exists()) {
                throw AppError::rule('CRM_STAGE_LAST', 'هذه آخر مرحلة مفتوحة في المسار — أضف مرحلة أخرى قبل أرشفتها أو جعلها مرحلة كسب', 'This is the last open stage of the pipeline — add another one first');
            }
            if (($data['is_won'] ?? $s->is_won) === true) {
                $data['probability'] = 100;
            }

            return $this->save($user, $s, $data, 'CRM.STAGE.UPDATE', 'CrmStage', $s->name_ar, fn (CrmStage $x) => Shape::stage($x));
        });
    }

    // ───────────── teams ─────────────

    public function createTeam(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            $leader = ! empty($dto['leaderId']) ? People::assignable($dto['leaderId'], 'leaderId') : null;
            $t = CrmTeam::create([
                'name_ar' => trim($dto['nameAr']), 'name_en' => trim((string) ($dto['nameEn'] ?? '')) ?: trim($dto['nameAr']), 'leader_id' => $leader?->id,
                'sequence' => $dto['sequence'] ?? ((int) CrmTeam::max('sequence') + 1), 'pipeline' => true, 'active' => true, 'created_by' => $user->username,
            ]);
            $this->audit->log($user, ['action' => 'CRM.TEAM.CREATE', 'entityType' => 'CrmTeam', 'entityId' => $t->id, 'entityNumber' => $t->name_ar, 'newValue' => $dto]);

            return Shape::team($t->refresh()->load('leader:id,username,name_ar,name_en'));
        });
    }

    public function updateTeam(AuthUser $user, string $id, array $dto): array
    {
        return DB::transaction(function () use ($user, $id, $dto) {
            $t = CrmTeam::whereKey($id)->lockForUpdate()->first() ?? throw AppError::notFound('CRM_TEAM_NOT_FOUND', 'الفريق غير موجود', 'Team not found');
            Owned::refuse($t, 'team');
            $data = $this->names($dto, 'CRM_TEAM_NAME', $t);
            foreach (['sequence' => 'sequence', 'active' => 'active', 'pipeline' => 'pipeline'] as $key => $column) {
                if (array_key_exists($key, $dto)) {
                    $data[$column] = $dto[$key];
                }
            }
            if (array_key_exists('leaderId', $dto)) {
                $data['leader_id'] = $dto['leaderId'] ? People::assignable($dto['leaderId'], 'leaderId')->id : null;
                $data['leader_name'] = null; // a person of OPS was named: the name kept from Odoo no longer stands in
            }

            return $this->save($user, $t, $data, 'CRM.TEAM.UPDATE', 'CrmTeam', $t->name_ar, fn (CrmTeam $x) => Shape::team($x->load('leader:id,username,name_ar,name_en')));
        });
    }

    // ───────────── lost reasons ─────────────

    public function createReason(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            $r = CrmLostReason::create(['name_ar' => trim($dto['nameAr']), 'name_en' => trim((string) ($dto['nameEn'] ?? '')) ?: trim($dto['nameAr']), 'active' => true, 'created_by' => $user->username]);
            $this->audit->log($user, ['action' => 'CRM.REASON.CREATE', 'entityType' => 'CrmLostReason', 'entityId' => $r->id, 'entityNumber' => $r->name_ar, 'newValue' => $dto]);

            return Shape::reason($r->refresh());
        });
    }

    public function updateReason(AuthUser $user, string $id, array $dto): array
    {
        return DB::transaction(function () use ($user, $id, $dto) {
            $r = CrmLostReason::whereKey($id)->lockForUpdate()->first() ?? throw AppError::notFound('CRM_REASON_NOT_FOUND', 'سبب الخسارة غير موجود', 'Lost reason not found');
            Owned::refuse($r, 'reason');
            $data = $this->names($dto, 'CRM_REASON_NAME', $r) + (array_key_exists('active', $dto) ? ['active' => $dto['active']] : []);

            return $this->save($user, $r, $data, 'CRM.REASON.UPDATE', 'CrmLostReason', $r->name_ar, fn (CrmLostReason $x) => Shape::reason($x));
        });
    }

    // ───────────── tags ─────────────

    public function createTag(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            $name = trim($dto['name']);
            $this->tagNameFree($name, null);
            $t = CrmTag::create(['name' => $name, 'color' => $dto['color'] ?? 0, 'active' => true, 'created_by' => $user->username]);
            $this->audit->log($user, ['action' => 'CRM.TAG.CREATE', 'entityType' => 'CrmTag', 'entityId' => $t->id, 'entityNumber' => $t->name, 'newValue' => $dto]);

            return Shape::tag($t->refresh());
        });
    }

    public function updateTag(AuthUser $user, string $id, array $dto): array
    {
        return DB::transaction(function () use ($user, $id, $dto) {
            $t = CrmTag::whereKey($id)->lockForUpdate()->first() ?? throw AppError::notFound('CRM_TAG_NOT_FOUND', 'الوسم غير موجود', 'Tag not found');
            Owned::refuse($t, 'tag');
            $data = [];
            if (array_key_exists('name', $dto)) {
                $name = trim((string) $dto['name']);
                if ($name === '') {
                    throw AppError::validation('CRM_TAG_NAME', 'اسم الوسم إلزامي', 'The tag needs a name', [['path' => 'name', 'message' => 'هذا الحقل إلزامي']]);
                }
                $this->tagNameFree($name, $t->id);
                $data['name'] = $name;
            }
            foreach (['color', 'active'] as $key) {
                if (array_key_exists($key, $dto)) {
                    $data[$key] = $dto[$key];
                }
            }

            return $this->save($user, $t, $data, 'CRM.TAG.UPDATE', 'CrmTag', $t->name, fn (CrmTag $x) => Shape::tag($x));
        });
    }

    /** Two tags of one name are one tag: the second is refused (letter case aside — the database compares that way). */
    private function tagNameFree(string $name, ?string $exceptId): void
    {
        if (CrmTag::where('name', $name)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists()) {
            throw AppError::rule('CRM_TAG_EXISTS', "الوسم «{$name}» موجود مسبقًا", "The tag \"{$name}\" already exists");
        }
    }

    // ───────────── shared ─────────────

    /** nameAr / nameEn of a payload as columns: the Arabic name is never emptied, an emptied English name follows it. */
    private function names(array $dto, string $code, Model $row): array
    {
        $data = [];
        if (array_key_exists('nameAr', $dto)) {
            $data['name_ar'] = trim((string) $dto['nameAr']);
            if ($data['name_ar'] === '') {
                throw AppError::validation($code, 'الاسم إلزامي', 'The name is required', [['path' => 'nameAr', 'message' => 'هذا الحقل إلزامي']]);
            }
        }
        if (array_key_exists('nameEn', $dto)) {
            $data['name_en'] = trim((string) $dto['nameEn']) ?: ($data['name_ar'] ?? $row->getAttribute('name_ar'));
        }

        return $data;
    }

    /** Writes what really changed, audits it (old → new) and returns the record's JSON. */
    private function save(AuthUser $user, Model $row, array $data, string $action, string $type, string $label, callable $shape): array
    {
        $old = $new = [];
        foreach ($data as $column => $value) {
            $current = $row->getAttribute($column);
            $same = is_bool($value) || is_bool($current) ? (bool) $current === (bool) $value : (is_numeric($value) && is_numeric($current) ? abs((float) $value - (float) $current) < 1e-9 : (string) ($current ?? '') === (string) ($value ?? ''));
            if (! $same) {
                $old[$column] = $current;
                $new[$column] = $value;
            }
        }
        if ($new) {
            $row->update($new);
            $this->audit->log($user, ['action' => $action, 'entityType' => $type, 'entityId' => $row->getKey(), 'entityNumber' => $label, 'oldValue' => $old, 'newValue' => $new]);
        }

        return $shape($row->refresh()) + ['changed' => count($new)];
    }
}
