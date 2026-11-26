<?php

namespace App\Commissions\Services;

use App\Commissions\Models\CommissionPlan;
use App\Commissions\Models\CommissionPlanMember;
use App\Commissions\Models\CommissionPlanRule;
use App\Commissions\Models\CommissionStatement;
use App\Commissions\Support\Owned;
use App\Commissions\Support\People;
use App\Commissions\Support\Shape;
use App\Commissions\Support\Tiers;
use App\Services\Core\AuditService;
use App\Services\Core\NumberingService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Support\Sql;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Commission plans: who earns, on what, at which rate, from when to when.
 *
 * ONE PLAN DECIDES for an earner and a month. A person is named by at most one active plan at a time (as the manager
 * of a manager plan, or as a member of a salesperson plan); activating a second one whose validity overlaps is refused.
 * A salesperson plan for "everyone" is the default for whoever is named by no plan — one such default at a time.
 *
 * TERMS ARE KEPT. Once a statement calculated from a plan has left the draft state, the plan's terms (who, base, rates,
 * start) can no longer be edited: the plan is ended and a copy carries the new terms, so every paid statement can be
 * explained by the plan it names. Its name, notes, end date and members stay editable.
 */
class PlansService
{
    public function __construct(private readonly AuditService $audit, private readonly NumberingService $numbering) {}

    // ───────────────────────────── reads ─────────────────────────────

    /** @param  array{status?:?string, earnerType?:?string, source?:?string}  $f */
    public function list(Paging $page, array $f): array
    {
        $q = CommissionPlan::query()->with(['rules', 'members'])->withCount('statements');
        if (! empty($f['status'])) {
            $q->whereIn('status', array_map('trim', explode(',', $f['status'])));
        }
        if (! empty($f['earnerType'])) {
            $q->where('earner_type', $f['earnerType']);
        }
        if (($f['source'] ?? null) === 'odoo') {
            $q->where('source_system', 'odoo');
        } elseif (($f['source'] ?? null) === 'ops') {
            $q->whereNull('source_system');
        }
        if ($page->q) {
            $like = '%'.addcslashes($page->q, '\\%_').'%';
            $op = Sql::like();
            $q->where(fn ($w) => $w->where('code', $op, $like)->orWhere('name_ar', $op, $like)->orWhere('name_en', $op, $like)->orWhere('beneficiary_name', $op, $like)
                ->orWhereIn('beneficiary_id', fn ($s) => $s->from('users')->select('id')->where(fn ($u) => $u->where('name_ar', $op, $like)->orWhere('name_en', $op, $like)->orWhere('username', $op, $like))));
        }
        $column = match ($page->sort) {
            'code' => 'code', 'validFrom' => 'valid_from', 'name' => 'name_ar', 'status' => 'status', default => 'created_at'
        };
        $q->orderBy($column, $page->order)->orderBy('id', $page->order);
        $out = $page->paginate($q);
        $plans = collect($out['items']);
        $users = People::byId($plans->flatMap(fn (CommissionPlan $p) => $p->members->pluck('user_id')->push($p->beneficiary_id))->all());
        $names = $this->scopeNames($plans->flatMap(fn (CommissionPlan $p) => $p->rules)->all());
        $out['items'] = $plans->map(fn (CommissionPlan $p) => Shape::plan($p, $users, $names) + ['statements' => (int) $p->statements_count])->all();

        return $out;
    }

    public function get(string $idOrCode): array
    {
        return $this->present($this->find($idOrCode));
    }

    public function find(string $idOrCode, bool $lock = false): CommissionPlan
    {
        $q = CommissionPlan::where(fn ($w) => $w->where('id', $idOrCode)->orWhere('code', $idOrCode));

        return ($lock ? $q->lockForUpdate() : $q)->first()
            ?? throw AppError::notFound('COMMISSION_PLAN_NOT_FOUND', "خطة العمولات {$idOrCode} غير موجودة", "Commission plan {$idOrCode} not found");
    }

    public function present(CommissionPlan $plan): array
    {
        $plan->load(['rules', 'members']);
        $users = People::byId($plan->members->pluck('user_id')->push($plan->beneficiary_id)->all());
        $byStatus = CommissionStatement::where('plan_id', $plan->id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return Shape::plan($plan, $users, $this->scopeNames($plan->rules->all())) + [
            'statements' => (int) $byStatus->sum(), 'statementsByStatus' => $byStatus->map(fn ($n) => (int) $n)->all(), 'locked' => $this->locked($plan),
        ];
    }

    // ───────────────────────────── changes ─────────────────────────────

    public function create(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            $n = $this->normalise($dto);
            $plan = CommissionPlan::create($n['attrs'] + ['code' => $this->numbering->next('CPL'), 'status' => 'draft', 'created_by' => $user->username]);
            $this->writeRules($plan, $n['rules']);
            $this->writeMembers($plan, $n['members']);
            $this->audit->log($user, ['action' => 'COMMISSION.PLAN.CREATE', 'entityType' => 'CommissionPlan', 'entityId' => $plan->id, 'entityNumber' => $plan->code, 'newValue' => $this->summary($plan->refresh())]);

            return $this->present($plan);
        });
    }

    public function update(AuthUser $user, string $idOrCode, array $dto): array
    {
        return DB::transaction(function () use ($user, $idOrCode, $dto) {
            $plan = $this->find($idOrCode, true);
            Owned::refuse($plan, 'plan');
            if ($plan->status === 'archived') {
                throw AppError::rule('COMMISSION_PLAN_STATE', "الخطة {$plan->code} مؤرشفة — أعد تفعيلها أو انسخها لتعديلها", "Plan {$plan->code} is archived — reactivate or duplicate it to change it");
            }
            $plan->load(['rules', 'members']);
            $before = $this->summary($plan);
            $n = $this->normalise($dto);
            if ($this->locked($plan)) {
                $changed = array_keys(array_filter([
                    'earnerType' => $n['attrs']['earner_type'] !== $plan->earner_type, 'beneficiary' => ($n['attrs']['beneficiary_id'] ?? null) !== $plan->beneficiary_id,
                    'everyone' => $n['attrs']['everyone'] !== (bool) $plan->everyone, 'earnOn' => $n['attrs']['earn_on'] !== $plan->earn_on, 'base' => $n['attrs']['base_kind'] !== $plan->base_kind,
                    'netOfReturns' => $n['attrs']['net_of_returns'] !== (bool) $plan->net_of_returns, 'validFrom' => $n['attrs']['valid_from'] !== Shape::day($plan->valid_from),
                    'rules' => self::rulesKey($n['rules']) !== self::rulesKey($plan->rules->map(fn (CommissionPlanRule $r) => $r->only(['scope', 'scope_ids', 'rate_type', 'rate', 'tier_mode', 'tiers']))->all()),
                ]));
                if ($changed) {
                    throw AppError::rule('COMMISSION_PLAN_LOCKED',
                        "كشوف صادرة من الخطة {$plan->code} تجاوزت المسودة: شروطها لا تُعدَّل — أنهِ الخطة بتاريخ نهاية وانسخها بالشروط الجديدة",
                        "Statements of plan {$plan->code} have left the draft state: its terms cannot change — end the plan and duplicate it with the new terms", ['fields' => $changed]);
                }
            }
            $plan->update($n['attrs'] + ['updated_by' => $user->username]);
            $this->writeRules($plan, $n['rules']);
            $this->writeMembers($plan, $n['members']);
            $plan->refresh()->load(['rules', 'members']);
            if ($plan->status === 'active') {
                $this->assertActivatable($plan);
            }
            $this->audit->log($user, ['action' => 'COMMISSION.PLAN.UPDATE', 'entityType' => 'CommissionPlan', 'entityId' => $plan->id, 'entityNumber' => $plan->code, 'oldValue' => $before, 'newValue' => $this->summary($plan)]);

            return $this->present($plan);
        });
    }

    /** draft → active, archived → active. From then on the plan decides for its earners in the months it covers. */
    public function activate(AuthUser $user, string $idOrCode): array
    {
        return DB::transaction(function () use ($user, $idOrCode) {
            $plan = $this->find($idOrCode, true);
            Owned::refuse($plan, 'plan');
            $this->move($plan, 'active');
            $plan->load(['rules', 'members']);
            $this->assertActivatable($plan);
            $from = $plan->status;
            $plan->update(['status' => 'active', 'activated_at' => now(), 'activated_by' => $user->username, 'archived_at' => null, 'archived_by' => null, 'updated_by' => $user->username]);
            $this->audit->status($user, 'CommissionPlan', $plan->id, $plan->code, $from, 'active');

            return $this->present($plan);
        });
    }

    /** active → archived: the plan decides no more. The statements it produced keep naming it. */
    public function archive(AuthUser $user, string $idOrCode, ?string $reason = null): array
    {
        return DB::transaction(function () use ($user, $idOrCode, $reason) {
            $plan = $this->find($idOrCode, true);
            Owned::refuse($plan, 'plan');
            $this->move($plan, 'archived');
            $plan->update(['status' => 'archived', 'archived_at' => now(), 'archived_by' => $user->username, 'updated_by' => $user->username]);
            $this->audit->status($user, 'CommissionPlan', $plan->id, $plan->code, 'active', 'archived', $reason);

            return $this->present($plan);
        });
    }

    /** A draft copy that OPS owns: the way to change the terms of a plan that is locked, or to take over a plan that came from Odoo. */
    public function duplicate(AuthUser $user, string $idOrCode): array
    {
        return DB::transaction(function () use ($user, $idOrCode) {
            $src = $this->find($idOrCode);
            $src->load(['rules', 'members']);
            $plan = CommissionPlan::create([
                'code' => $this->numbering->next('CPL'), 'name_ar' => mb_substr($src->name_ar.' — نسخة', 0, 190), 'name_en' => mb_substr($src->name_en.' — copy', 0, 190),
                'earner_type' => $src->earner_type, 'beneficiary_id' => $src->beneficiary_id, 'beneficiary_name' => $src->beneficiary_id ? null : $src->beneficiary_name,
                'everyone' => $src->everyone, 'earn_on' => $src->earn_on, 'base_kind' => $src->base_kind, 'net_of_returns' => $src->net_of_returns,
                'valid_from' => Carbon::today()->toDateString(), 'valid_to' => null, 'status' => 'draft', 'notes' => $src->notes, 'created_by' => $user->username,
            ]);
            foreach ($src->rules as $r) {
                CommissionPlanRule::create(['plan_id' => $plan->id] + $r->only(['sequence', 'scope', 'scope_ids', 'rate_type', 'rate', 'tier_mode', 'tiers', 'label']));
            }
            foreach ($src->members as $m) {
                CommissionPlanMember::create(['plan_id' => $plan->id, 'user_id' => $m->user_id, 'person_name' => $m->user_id ? null : $m->person_name, 'person_ref' => $m->user_id ? null : $m->person_ref]);
            }
            $this->audit->log($user, ['action' => 'COMMISSION.PLAN.DUPLICATE', 'entityType' => 'CommissionPlan', 'entityId' => $plan->id, 'entityNumber' => $plan->code, 'newValue' => ['from' => $src->code] + $this->summary($plan->refresh())]);

            return $this->present($plan);
        });
    }

    // ───────────────────────────── rules of the plans ─────────────────────────────

    /** Statements of the plan that are no longer drafts exist: its terms are history. */
    public function locked(CommissionPlan $plan): bool
    {
        return CommissionStatement::where('plan_id', $plan->id)->whereNull('source_system')->whereIn('status', CommissionStatement::FROZEN)->exists();
    }

    private function move(CommissionPlan $plan, string $to): void
    {
        if (! in_array($to, CommissionPlan::TRANSITIONS[$plan->status] ?? [], true)) {
            throw AppError::rule('COMMISSION_PLAN_TRANSITION', "انتقال غير مسموح للخطة {$plan->code}: {$plan->status} ← {$to}", "Invalid transition for plan {$plan->code}: {$plan->status} -> {$to}");
        }
    }

    /** What an active plan must be: it has a rate, real earners, and nobody it names is named by another active plan in the same months. */
    private function assertActivatable(CommissionPlan $plan): void
    {
        if ($plan->rules->isEmpty()) {
            throw AppError::rule('COMMISSION_PLAN_NO_RULE', "الخطة {$plan->code} بلا نسبة — أضف قاعدة واحدة على الأقل", "Plan {$plan->code} has no rate — add at least one rule");
        }
        $earners = $plan->earner_type === 'manager' ? array_filter([$plan->beneficiary_id]) : $plan->members->pluck('user_id')->filter()->values()->all();
        if ($plan->earner_type === 'manager' ? ! $earners : (! $plan->everyone && ! $earners)) {
            throw AppError::rule('COMMISSION_PLAN_NO_EARNER',
                "الخطة {$plan->code} لا تسمّي مستخدمًا في النظام يستحق العمولة — اختر المستفيد أو المندوبين من مستخدمي النظام",
                "Plan {$plan->code} names no OPS user who earns — pick the beneficiary or the salespeople among the OPS users");
        }
        $from = Shape::day($plan->valid_from);
        $to = Shape::day($plan->valid_to);
        $others = CommissionPlan::with('members')->where('status', 'active')->where('id', '!=', $plan->id)
            ->where(fn ($w) => $w->whereNull('valid_to')->orWhereDate('valid_to', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('valid_from', '<=', $to))->orderBy('code')->get();
        foreach ($others as $o) {
            $theirs = $o->earner_type === 'manager' ? array_filter([$o->beneficiary_id]) : $o->members->pluck('user_id')->filter()->values()->all();
            $both = array_values(array_intersect($earners, $theirs));
            if ($both) {
                $who = implode('، ', array_map(fn ($u) => $u->name_ar, People::byId($both)));
                throw AppError::rule('COMMISSION_PLAN_OVERLAP', "{$who}: مشمول في الخطة الفعّالة {$o->code} في الفترة نفسها — خطة واحدة تحكم المندوب في الشهر",
                    "Already named by the active plan {$o->code} in the same period — one plan decides for a person and a month", ['plan' => $o->code, 'users' => $both]);
            }
            if ($plan->earner_type === 'salesperson' && $plan->everyone && $o->earner_type === 'salesperson' && $o->everyone) {
                throw AppError::rule('COMMISSION_PLAN_OVERLAP', "الخطة العامة {$o->code} فعّالة في الفترة نفسها — خطة عامة واحدة لكل المندوبين في وقت واحد",
                    "The general plan {$o->code} is active in the same period — one plan for every salesperson at a time", ['plan' => $o->code, 'users' => []]);
            }
        }
    }

    /**
     * What a person sent, checked and brought to the columns of the tables.
     *
     * @return array{attrs: array<string, mixed>, rules: array<int, array<string, mixed>>, members: string[]}
     */
    private function normalise(array $dto): array
    {
        $type = (string) $dto['earnerType'];
        $everyone = (bool) ($dto['everyone'] ?? false);
        $beneficiary = null;
        if ($type === 'manager') {
            if (empty($dto['beneficiary'])) {
                throw AppError::validation('COMMISSION_BENEFICIARY_REQUIRED', 'خطة المدير تحتاج المستفيد: من يستحق العمولة عن مبيعات الفريق', 'A manager plan needs its beneficiary: who earns on the team’s sales', [['path' => 'beneficiary', 'message' => 'اختر المستفيد']]);
            }
            $beneficiary = People::earner((string) $dto['beneficiary'], 'beneficiary');
        }
        $members = [];
        if (! $everyone) {
            foreach (array_values((array) ($dto['members'] ?? [])) as $i => $m) {
                $members[] = (string) People::earner((string) $m, "members.{$i}")->id;
            }
            $members = array_values(array_unique($members));
            if (! $members) {
                throw AppError::validation('COMMISSION_MEMBERS_REQUIRED', $type === 'manager' ? 'اختر مندوبي الفريق، أو اجعل الخطة على كل الفواتير' : 'اختر المندوبين المشمولين، أو اجعل الخطة لكل المندوبين',
                    $type === 'manager' ? 'Pick the salespeople of the team, or make the plan cover every invoice' : 'Pick the salespeople of the plan, or make it the plan of every salesperson', [['path' => 'members', 'message' => 'اختر مندوبًا واحدًا على الأقل']]);
            }
        }
        $from = Carbon::parse((string) $dto['validFrom'])->startOfDay();
        $to = ! empty($dto['validTo']) ? Carbon::parse((string) $dto['validTo'])->startOfDay() : null;
        if ($to && $to->lt($from)) {
            throw AppError::validation('COMMISSION_VALIDITY', 'تاريخ نهاية الخطة قبل بدايتها', 'The plan ends before it starts', [['path' => 'validTo', 'message' => 'بعد تاريخ البداية']]);
        }
        $rules = [];
        $list = array_values((array) ($dto['rules'] ?? []));
        foreach ($list as $i => $r) {
            $rules[] = $this->normaliseRule((array) $r, $i);
            if ($rules[$i]['scope'] === 'all' && $i < count($list) - 1) {
                throw AppError::validation('COMMISSION_RULE_UNREACHABLE', 'قاعدة «كل المبيعات» تكون الأخيرة: ما بعدها لا يُطبَّق أبدًا', 'The “all sales” rule must be the last one: nothing after it would ever apply', [['path' => "rules.{$i}.scope", 'message' => 'انقلها إلى آخر القائمة']]);
            }
        }
        if (! $rules) {
            throw AppError::validation('COMMISSION_RULES_REQUIRED', 'أضف نسبة واحدة على الأقل', 'Add at least one rate', [['path' => 'rules', 'message' => 'قاعدة واحدة على الأقل']]);
        }
        $nameAr = trim((string) $dto['nameAr']);

        return [
            'attrs' => [
                'name_ar' => mb_substr($nameAr, 0, 190), 'name_en' => mb_substr(trim((string) ($dto['nameEn'] ?? '')) ?: $nameAr, 0, 190), 'earner_type' => $type,
                'beneficiary_id' => $beneficiary?->id, 'beneficiary_name' => null, 'beneficiary_ref' => null, 'everyone' => $everyone,
                'earn_on' => (string) ($dto['earnOn'] ?? 'invoiced'), 'base_kind' => (string) ($dto['base'] ?? 'net'), 'net_of_returns' => (bool) ($dto['netOfReturns'] ?? true),
                'valid_from' => $from->toDateString(), 'valid_to' => $to?->toDateString(), 'notes' => isset($dto['notes']) && trim((string) $dto['notes']) !== '' ? mb_substr(trim((string) $dto['notes']), 0, 2000) : null,
            ],
            'rules' => $rules, 'members' => $members,
        ];
    }

    /** @return array<string, mixed> */
    private function normaliseRule(array $r, int $i): array
    {
        $scope = (string) ($r['scope'] ?? 'all');
        $ids = [];
        if ($scope !== 'all') {
            $ids = array_values(array_unique(array_map('strval', array_filter((array) ($r['scopeIds'] ?? [])))));
            if (! $ids) {
                throw AppError::validation('COMMISSION_SCOPE_REQUIRED', $scope === 'category' ? 'اختر فئة منتجات واحدة على الأقل للقاعدة' : 'اختر عميلًا واحدًا على الأقل للقاعدة',
                    $scope === 'category' ? 'Pick at least one product category for the rule' : 'Pick at least one customer for the rule', [['path' => "rules.{$i}.scopeIds", 'message' => 'مطلوب']]);
            }
            $found = DB::table($scope === 'category' ? 'product_categories' : 'customers')->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (string) $id)->all();
            if (count($found) !== count($ids)) {
                throw AppError::validation('COMMISSION_SCOPE_UNKNOWN', $scope === 'category' ? 'فئة منتجات غير موجودة في القاعدة' : 'عميل غير موجود في القاعدة',
                    $scope === 'category' ? 'A product category of the rule does not exist' : 'A customer of the rule does not exist', [['path' => "rules.{$i}.scopeIds", 'message' => 'غير موجود']]);
            }
            sort($ids);
        }
        $rateType = (string) ($r['rateType'] ?? 'flat');
        $rate = null;
        $tiers = null;
        $mode = null;
        if ($rateType === 'tiered') {
            $tiers = Tiers::normalise((array) ($r['tiers'] ?? []), "rules.{$i}.tiers");
            $mode = in_array($r['tierMode'] ?? null, CommissionPlanRule::TIER_MODES, true) ? (string) $r['tierMode'] : 'whole';
        } else {
            if (! is_numeric($r['rate'] ?? null) || (float) $r['rate'] < 0 || (float) $r['rate'] > 100) {
                throw AppError::validation('COMMISSION_RATE', 'نسبة العمولة بين 0 و100', 'A commission rate is between 0 and 100', [['path' => "rules.{$i}.rate", 'message' => 'بين 0 و100']]);
            }
            $rate = round((float) $r['rate'], 4);
        }

        return [
            'sequence' => $i + 1, 'scope' => $scope, 'scope_ids' => $ids ?: null, 'rate_type' => $rateType, 'rate' => $rate, 'tier_mode' => $mode, 'tiers' => $tiers,
            'label' => isset($r['label']) && trim((string) $r['label']) !== '' ? mb_substr(trim((string) $r['label']), 0, 190) : null,
        ];
    }

    /** Rules are rewritten in place, by position: a rule that did not change keeps its id (lines point at it). */
    private function writeRules(CommissionPlan $plan, array $rules): void
    {
        $existing = CommissionPlanRule::where('plan_id', $plan->id)->orderBy('sequence')->orderBy('id')->get()->values();
        foreach ($rules as $i => $values) {
            if ($row = $existing[$i] ?? null) {
                $row->update($values);
            } else {
                CommissionPlanRule::create(['plan_id' => $plan->id] + $values);
            }
        }
        foreach ($existing->slice(count($rules)) as $surplus) {
            $surplus->delete();
        }
    }

    /** @param string[] $userIds */
    private function writeMembers(CommissionPlan $plan, array $userIds): void
    {
        $have = CommissionPlanMember::where('plan_id', $plan->id)->get();
        foreach ($have as $m) {
            if (! $m->user_id || ! in_array((string) $m->user_id, $userIds, true)) {
                $m->delete();
            }
        }
        foreach (array_diff($userIds, $have->pluck('user_id')->filter()->map(fn ($id) => (string) $id)->all()) as $id) {
            CommissionPlanMember::create(['plan_id' => $plan->id, 'user_id' => $id]);
        }
    }

    /** The plan in a few words, for the audit trail. */
    private function summary(CommissionPlan $plan): array
    {
        $plan->load(['rules', 'members']);

        return [
            'name' => $plan->name_ar, 'earnerType' => $plan->earner_type, 'beneficiary' => $plan->beneficiary_id ?? $plan->beneficiary_name, 'everyone' => (bool) $plan->everyone,
            'members' => $plan->members->map(fn ($m) => $m->user_id ?? $m->person_name)->values()->all(), 'earnOn' => $plan->earn_on, 'base' => $plan->base_kind, 'netOfReturns' => (bool) $plan->net_of_returns,
            'validFrom' => Shape::day($plan->valid_from), 'validTo' => Shape::day($plan->valid_to), 'status' => $plan->status,
            'rules' => $plan->rules->map(fn (CommissionPlanRule $r) => ['scope' => $r->scope, 'ids' => $r->scope_ids, 'rateType' => $r->rate_type, 'rate' => $r->rate, 'tierMode' => $r->tier_mode, 'tiers' => $r->tiers])->all(),
        ];
    }

    /** Rules as one comparable text (order matters: the first rule that covers a line decides). */
    private static function rulesKey(array $rules): string
    {
        return json_encode(array_map(function (array $r) {
            $ids = array_values(array_map('strval', (array) ($r['scope_ids'] ?? [])));
            sort($ids);

            return [$r['scope'], $ids, $r['rate_type'], $r['rate'] === null ? null : round((float) $r['rate'], 4), $r['tier_mode'] ?? null,
                array_map(fn ($t) => [isset($t['upTo']) ? round((float) $t['upTo'], 2) : null, round((float) $t['rate'], 4)], (array) ($r['tiers'] ?? []))];
        }, array_values($rules)));
    }

    /**
     * The categories and customers the rules name, by id.
     *
     * @param  CommissionPlanRule[]  $rules
     * @return array<string, array{id:string, code:string, nameAr:string, nameEn:string}>
     */
    private function scopeNames(array $rules): array
    {
        $ids = ['category' => [], 'customer' => []];
        foreach ($rules as $r) {
            if (isset($ids[$r->scope])) {
                $ids[$r->scope] = array_merge($ids[$r->scope], (array) ($r->scope_ids ?? []));
            }
        }
        $out = [];
        foreach (['category' => 'product_categories', 'customer' => 'customers'] as $scope => $table) {
            if ($ids[$scope]) {
                foreach (DB::table($table)->whereIn('id', array_values(array_unique($ids[$scope])))->get(['id', 'code', 'name_ar', 'name_en']) as $row) {
                    $out[(string) $row->id] = ['id' => (string) $row->id, 'code' => (string) $row->code, 'nameAr' => (string) $row->name_ar, 'nameEn' => (string) $row->name_en];
                }
            }
        }

        return $out;
    }
}
