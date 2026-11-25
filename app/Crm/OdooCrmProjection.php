<?php

namespace App\Crm;

use App\Crm\Models\CrmLead;
use App\Crm\Models\CrmLostReason;
use App\Crm\Models\CrmStage;
use App\Crm\Models\CrmTag;
use App\Crm\Models\CrmTeam;
use App\Crm\Services\ActivityBook;
use App\Crm\Support\People;
use App\Crm\Support\Timeline;
use App\Integration\Odoo\Models\OdooRecord;
use App\Integration\Odoo\Projection\OdooProjection;
use App\Integration\Odoo\Projection\Val;
use App\Integration\Services\ExternalRefs;
use App\Models\Customer;
use App\Services\Core\AuditService;
use App\Services\Core\NumberingService;
use App\Support\AppError;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * From the copy of Odoo's CRM to the OPS pipeline: Odoo's stages, sales teams, lost reasons, tags and leads /
 * opportunities become records of «الفرص والعملاء المحتملون», marked `source_system = 'odoo'` with the Odoo id in
 * `external_ref`. Idempotent: a second pass changes nothing.
 *
 * WHAT IS ODOO'S on such a record: everything Odoo holds — the names, the stage, won / lost and its reason, the
 * contact, the revenue, the chance, the salesperson, the team, the tags. WHAT IS OPS'S: the notes and to-dos people add
 * here, the quotations, and the OPS customer a person converted the opportunity into. A pass never touches those.
 *
 * LINKING is by identity only. A stage, a team, a reason or a tag that OPS already has under exactly the same name is
 * the same thing (adopted, not doubled). A salesperson is the OPS user whose login or e-mail is the Odoo user's login
 * or e-mail — one user, or none: then the lead keeps the name Odoo gives. A lead's customer is the OPS customer its
 * Odoo partner (or that partner's company) is linked to (`int_external_refs`, system `odoo`, entity `customer`).
 *
 * A record that was deleted in Odoo is archived here and keeps its history: nothing of the pipeline is removed.
 * plan() also runs where the section's tables do not exist yet (it then counts everything as new) — it never writes.
 */
class OdooCrmProjection extends OdooProjection
{
    private const CLASSES = ['stages' => CrmStage::class, 'teams' => CrmTeam::class, 'reasons' => CrmLostReason::class, 'tags' => CrmTag::class];

    private const MODELS = ['stages' => 'crm.stage', 'teams' => 'crm.team', 'reasons' => 'crm.lost.reason', 'tags' => 'crm.tag'];

    private const AUDIT_TYPE = ['stages' => 'CrmStage', 'teams' => 'CrmTeam', 'reasons' => 'CrmLostReason', 'tags' => 'CrmTag'];

    /**
     * Arabic for the names Odoo ships in English (its own stages, teams and lost reasons). The English name stays
     * exactly Odoo's; a name people typed themselves is kept as it is in both languages.
     */
    private const ARABIC = [
        'new' => 'جديدة', 'qualified' => 'مؤهَّلة', 'proposition' => 'عرض سعر', 'won' => 'مكسوبة', 'lost' => 'خاسرة',
        'sales' => 'المبيعات', 'website' => 'الموقع الإلكتروني', 'point of sale' => 'نقاط البيع', 'pre-sales' => 'ما قبل البيع',
        'too expensive' => 'السعر مرتفع', "we don't have people/skills" => 'لا نملك الكوادر أو المهارات', 'not enough stock' => 'المخزون لا يكفي',
    ];

    /** What Odoo holds on a lead and OPS has no column for — kept as it is, when it holds something. */
    private const EXTRA = ['lead_import_id', 'email_cc', 'color', 'day_open', 'day_close', 'date_conversion', 'recurring_revenue', 'email_state', 'phone_state'];

    /** A whole-mirror pass (plan / apply): the OPS leads are read once, not one by one. */
    private bool $whole = false;

    /** The section's tables exist (a plan may run before the migration). */
    private bool $installed = true;

    /** @var array<string, array<int|string, Model>> per entity: Odoo id → the OPS row */
    private array $rows = [];

    /** @var array<string, array<int, true>> per entity: Odoo ids a plan decided to create */
    private array $planned = [];

    /** @var array<int|string, CrmLead>|null OPS leads by Odoo id (a whole-mirror pass) */
    private ?array $leads = null;

    /** @var array<int|string, string> OPS user id by lower-case username */
    private array $userByName = [];

    /** @var array<int|string, string> OPS user id by lower-case e-mail */
    private array $userByEmail = [];

    /** @var array<int, array{0:?string, 1:?string}> Odoo user id → [OPS user id, name kept] */
    private array $people = [];

    /** @var array<int, array{parent:?int, vat:?string}> what a pass needs of an Odoo partner */
    private array $partners = [];

    /** @var array<string, bool> OPS customer ids a pass has checked */
    private array $customers = [];

    public function __construct(
        ExternalRefs $refs,
        private readonly AuditService $audit,
        private readonly NumberingService $numbering,
        private readonly ActivityBook $book,
    ) {
        parent::__construct($refs);
    }

    public function key(): string
    {
        return 'crm';
    }

    /** Parents before children: a stage may belong to a team; a lead points at a stage, a team, a reason and tags. */
    public function models(): array
    {
        return ['crm.team', 'crm.stage', 'crm.lost.reason', 'crm.tag', 'crm.lead'];
    }

    public function plan(): array
    {
        return $this->wholePass(fn () => parent::plan());
    }

    public function apply(): array
    {
        return $this->wholePass(fn () => parent::apply());
    }

    private function wholePass(callable $pass): array
    {
        $this->whole = true;
        try {
            return $pass();
        } finally {
            $this->whole = false;
            $this->leads = null;
        }
    }

    protected function begin(): void
    {
        $this->installed = Schema::hasTable('crm_leads');
        if (! $this->installed && ! $this->dry) {
            throw AppError::rule('CRM_NOT_INSTALLED', 'جداول قسم الفرص لم تُنشأ بعد — شغّل الترحيل أولًا (php artisan migrate)', 'The tables of the CRM section do not exist yet — run the migration first');
        }
        $this->planned = $this->people = $this->partners = $this->customers = $this->userByName = $this->userByEmail = [];
        foreach (self::CLASSES as $entity => $class) {
            $this->rows[$entity] = $this->installed ? $class::where('source_system', self::SYSTEM)->get()->keyBy('external_ref')->all() : [];
        }
        $this->leads = $this->whole ? ($this->installed ? CrmLead::where('source_system', self::SYSTEM)->with('tags:id')->get()->keyBy('external_ref')->all() : []) : null;
        foreach (DB::table('users')->where('username', 'not like', People::SERVICE_PREFIX.'%')->get(['id', 'username', 'email']) as $u) {
            $this->userByName[mb_strtolower((string) $u->username)] = (string) $u->id;
            if ($u->email) {
                $this->userByEmail[mb_strtolower((string) $u->email)] = (string) $u->id;
            }
        }
    }

    protected function project(OdooRecord $r): void
    {
        match ($r->model) {
            'crm.stage' => $this->dictionary('stages', $r, $this->stageValues($r)),
            'crm.team' => $this->dictionary('teams', $r, $this->teamValues($r)),
            'crm.lost.reason' => $this->dictionary('reasons', $r, self::names($r) + ['active' => $this->alive($r)]),
            'crm.tag' => $this->dictionary('tags', $r, ['name' => self::cut(Val::text($r->data['name'] ?? null) ?? $r->display_name ?? "#{$r->odoo_id}", 80), 'color' => max(0, min(CrmTag::COLORS - 1, Val::int($r->data['color'] ?? 0))), 'active' => $this->alive($r)]),
            'crm.lead' => $this->lead($r),
            default => null,
        };
    }

    // ───────────── stages, teams, lost reasons, tags ─────────────

    private function stageValues(OdooRecord $r): array
    {
        $d = (array) $r->data;
        $won = Val::bool($d['is_won'] ?? false);

        return self::names($r) + [
            'sequence' => Val::int($d['sequence'] ?? 0), 'is_won' => $won, 'fold' => Val::bool($d['fold'] ?? false), 'requirements' => Val::text($d['requirements'] ?? null, 2000),
            'team_id' => $this->ref('teams', Val::id($d['team_id'] ?? null)), 'active' => $this->alive($r),
        ] + ($won ? ['probability' => 100.0] : []);
    }

    private function teamValues(OdooRecord $r): array
    {
        $d = (array) $r->data;
        [$leaderId, $leaderName] = $this->person($d['user_id'] ?? null);

        return self::names($r) + [
            'leader_id' => $leaderId, 'leader_name' => $leaderName, 'sequence' => Val::int($d['sequence'] ?? 0),
            'pipeline' => array_key_exists('use_opportunities', $d) ? Val::bool($d['use_opportunities']) : true, 'active' => $this->alive($r),
        ];
    }

    /**
     * One record of a small table. `$values` are the columns Odoo owns, as they should be now.
     *
     * @return Model|null the OPS row (null in a plan that would create it, and for a record OPS never had and Odoo deleted)
     */
    private function dictionary(string $entity, OdooRecord $r, array $values): ?Model
    {
        $row = $this->rows[$entity][$r->odoo_id] ?? null;
        $label = (string) ($values['name_ar'] ?? $values['name'] ?? $r->display_name);
        if (! $row) {
            if ($r->deleted_at !== null) {
                $this->count("{$entity}.skippedDeleted");

                return null;
            }
            // OPS already has it under exactly this name: the same thing, adopted — never a second one beside it
            $twin = $this->installed ? $this->twin($entity, $values) : null;
            $this->count($entity.($twin ? '.linked' : '.created'), $twin ? $label : null);
            if ($this->dry) {
                $this->planned[$entity][$r->odoo_id] = true;

                return null;
            }
            $row = DB::transaction(function () use ($entity, $r, $values, $twin, $label) {
                $origin = ['source_system' => self::SYSTEM, 'external_ref' => (string) $r->odoo_id];
                if ($twin) {
                    $twin->update($values + $origin);
                    $row = $twin;
                } else {
                    $row = (self::CLASSES[$entity])::create($values + $origin + ['created_by' => $this->actor()->username]);
                }
                $this->audit->log($this->actor(), ['action' => 'CRM.'.strtoupper(rtrim($entity, 's')).($twin ? '.LINK' : '.CREATE'), 'entityType' => self::AUDIT_TYPE[$entity], 'entityId' => $row->getKey(), 'entityNumber' => $label,
                    'newValue' => ['origin' => 'from Odoo '.self::MODELS[$entity]." #{$r->odoo_id}"] + $values]);

                return $row;
            });
            $this->rows[$entity][$r->odoo_id] = $row;

            return $row;
        }
        if ($r->deleted_at !== null) {
            $values = ['active' => false]; // gone from Odoo: put away here, the leads that carry it keep it
        }
        $changes = self::changes($row, $values);
        if (! $changes) {
            $this->count("{$entity}.unchanged");

            return $row;
        }
        $this->count($entity.(($changes['active'] ?? null) === false ? '.archived' : '.updated'));
        if ($this->dry) {
            return $row;
        }
        DB::transaction(function () use ($entity, $row, $changes, $label) {
            $old = array_map(fn (string $column) => $row->getAttribute($column), array_combine(array_keys($changes), array_keys($changes)));
            $row->update($changes);
            $this->audit->log($this->actor(), ['action' => 'CRM.'.strtoupper(rtrim($entity, 's')).'.UPDATE', 'entityType' => self::AUDIT_TYPE[$entity], 'entityId' => $row->getKey(), 'entityNumber' => $label,
                'oldValue' => $old, 'newValue' => $changes + ['origin' => 'Odoo']]);
        });

        return $row;
    }

    /** The OPS-made row of exactly that name (letter case aside — the database compares that way), not linked yet. */
    private function twin(string $entity, array $values): ?Model
    {
        $query = (self::CLASSES[$entity])::query()->whereNull('source_system');
        if ($entity === 'tags') {
            return $query->where('name', $values['name'])->orderBy('created_at')->first();
        }

        return $query->where(fn ($w) => $w->where('name_en', $values['name_en'])->orWhere('name_ar', $values['name_en'])->orWhere('name_ar', $values['name_ar']))->orderBy('created_at')->first();
    }

    /**
     * The OPS id of an Odoo stage / team / reason / tag. One that the pass has not met yet (a change of the feed that
     * names a brand-new stage) is made from the copy on the spot. In a plan: the id, or a marker for "will be made".
     */
    private function ref(string $entity, ?int $odooId): ?string
    {
        if (! $odooId) {
            return null;
        }
        if ($row = $this->rows[$entity][$odooId] ?? null) {
            return (string) $row->getKey();
        }
        if (isset($this->planned[$entity][$odooId])) {
            return "new:{$entity}:{$odooId}";
        }
        if ($r = $this->mirrored(self::MODELS[$entity], $odooId)) {
            $this->project($r);
        }
        if ($row = $this->rows[$entity][$odooId] ?? null) {
            return (string) $row->getKey();
        }

        return isset($this->planned[$entity][$odooId]) ? "new:{$entity}:{$odooId}" : null;
    }

    // ───────────── leads ─────────────

    private function lead(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $ref = (string) $r->odoo_id;
        $lead = $this->leads !== null ? ($this->leads[$r->odoo_id] ?? null) : ($this->installed ? CrmLead::where('source_system', self::SYSTEM)->where('external_ref', $ref)->with('tags:id')->first() : null);
        $gone = $r->deleted_at !== null;
        if (! $lead && $gone) {
            $this->count('leads.skippedDeleted');

            return;
        }
        $stageOdoo = Val::id($d['stage_id'] ?? null);
        $stageId = $this->ref('stages', $stageOdoo);
        if (! $stageId) {
            // Odoo lets a lead stand in no stage: it is kept — in the lists, not on the board
            $this->count('leads.noStage', (string) ($r->display_name ?? "#{$ref}"));
        }
        $stageWon = (bool) (($this->rows['stages'][$stageOdoo] ?? null)?->getAttribute('is_won') ?? Val::bool($this->mirrored('crm.stage', $stageOdoo)?->data['is_won'] ?? false));
        $status = match ($d['won_status'] ?? null) {
            'won' => 'won', 'lost' => 'lost', 'pending' => 'open',
            // an Odoo without `won_status`: won = alive in a won stage; lost = archived with no chance left
            default => $stageWon && $r->active !== false ? 'won' : ($r->active === false && Val::float($d['probability'] ?? 0) <= 0 ? 'lost' : 'open'),
        };
        $partnerId = Val::id($d['partner_id'] ?? null);
        [$ownerId, $ownerName] = $this->person($d['user_id'] ?? null);
        $values = [
            'name' => self::cut(Val::text($d['name'] ?? null) ?? Val::text($r->display_name) ?? "#{$ref}", 250),
            'kind' => ($d['type'] ?? null) === 'lead' ? 'lead' : 'opportunity', 'stage_id' => $stageId, 'status' => $status,
            // lost is a state of its own; "archived" is what was put away without being lost — or no longer exists in Odoo
            'archived' => $gone || ($r->active === false && $status !== 'lost'),
            'priority' => max(0, min(CrmLead::MAX_PRIORITY, Val::int($d['priority'] ?? 0))),
            'expected_revenue' => round(Val::float($d['expected_revenue'] ?? 0), 2), 'probability' => round(max(0, min(100, Val::float($d['probability'] ?? 0))), 2),
            'contact_name' => Val::text($d['contact_name'] ?? null, 190), 'company_name' => Val::text($d['partner_name'] ?? null, 190) ?? self::cut(Val::label($d['partner_id'] ?? null), 190),
            'job_title' => Val::text($d['function'] ?? null, 120), 'phone' => Val::text($d['phone'] ?? null, 40), 'mobile' => Val::text($d['mobile'] ?? null, 40),
            'email' => Val::text($d['email_from'] ?? null, 190), 'website' => Val::text($d['website'] ?? null, 500),
            'street' => Val::text($d['street'] ?? null, 250), 'street2' => Val::text($d['street2'] ?? null, 250), 'city' => Val::text($d['city'] ?? null, 120),
            'region' => self::cut(Val::label($d['state_id'] ?? null), 120), 'zip' => Val::text($d['zip'] ?? null, 20), 'country' => self::cut(Val::label($d['country_id'] ?? null), 120),
            'vat_no' => $partnerId ? $this->partner($partnerId)['vat'] : null, 'partner_ref' => $partnerId ? (string) $partnerId : null,
            'owner_id' => $ownerId, 'owner_name' => $ownerName, 'team_id' => $this->ref('teams', Val::id($d['team_id'] ?? null)),
            'source' => self::cut(Val::label($d['source_id'] ?? null), 120), 'medium' => self::cut(Val::label($d['medium_id'] ?? null), 120),
            'campaign' => self::cut(Val::label($d['campaign_id'] ?? null), 190), 'referred_by' => Val::text($d['referred'] ?? null, 190),
            'description' => Val::plain($d['description'] ?? null, 20000), 'lost_reason_id' => $this->ref('reasons', Val::id($d['lost_reason_id'] ?? null)),
            'deadline' => Val::date($d['date_deadline'] ?? null), 'opened_at' => Val::at($d['date_open'] ?? null), 'stage_changed_at' => Val::at($d['date_last_stage_update'] ?? null),
            'closed_at' => $status === 'open' ? null : Val::at($d['date_closed'] ?? null),
            'extra' => array_filter(array_intersect_key($d, array_flip(self::EXTRA)), fn ($v) => $v !== false && $v !== null && $v !== '' && $v !== 0 && $v !== 0.0) ?: null,
            'source_updated_at' => Val::at($r->write_date), 'source_deleted_at' => $gone ? $r->deleted_at : null,
        ];
        // the customer follows Odoo's partner — unless a person converted the opportunity here: that decision is OPS's
        $linked = $this->customer($partnerId);
        if ($lead?->converted_at) {
            if ($linked && $linked !== $lead->customer_id) {
                $this->count('leads.customerConflict', "{$lead->number}: أودو يدل على عميل آخر غير الذي حُوّلت إليه هنا");
            }
        } else {
            $values['customer_id'] = $linked;
        }
        if ($linked) {
            $this->count('leads.withCustomer');
        }
        $tagIds = array_values(array_filter(array_map(fn (int $id) => $this->ref('tags', $id), Val::ids($d['tag_ids'] ?? []))));
        sort($tagIds);

        if (! $lead) {
            $this->count('leads.created');
            if ($this->dry) {
                return;
            }
            $lead = DB::transaction(function () use ($d, $ref, $values, $tagIds, $status) {
                $created = Val::at($d['create_date'] ?? null) ?? now();
                $by = Val::label($d['create_uid'] ?? null);
                $lead = CrmLead::create($values + [
                    'number' => $this->numbering->next('OPP'), 'source_system' => self::SYSTEM, 'external_ref' => $ref, 'created_by' => $by, 'created_at' => $created,
                ]);
                if ($tagIds) {
                    $lead->tags()->sync($tagIds);
                }
                Timeline::add($lead, 'created', null, ['to' => $lead->stage_id, 'origin' => self::SYSTEM, 'at' => $created, 'username' => $by ?? self::SYSTEM]);
                if ($status !== 'open') {
                    Timeline::add($lead, $status, null, ['from' => $lead->stage_id, 'to' => $status === 'won' ? $lead->stage_id : null, 'origin' => self::SYSTEM, 'at' => $lead->closed_at ?? $lead->stage_changed_at ?? $created,
                        'username' => Val::label($d['write_uid'] ?? null) ?? self::SYSTEM]);
                }
                $this->audit->log($this->actor(), ['action' => 'LEAD.CREATE', 'entityType' => 'CrmLead', 'entityId' => $lead->id, 'entityNumber' => $lead->number,
                    'newValue' => ['origin' => "from Odoo crm.lead #{$ref}", 'name' => $lead->name, 'status' => $status]]);

                return $lead;
            });
            if ($this->leads !== null) {
                $this->leads[$r->odoo_id] = $lead->setRelation('tags', $lead->tags()->get(['crm_tags.id']));
            }

            return;
        }

        $changes = self::changes($lead, $values);
        $tagsBefore = $lead->tags->pluck('id')->map(fn ($id) => (string) $id)->sort()->values()->all();
        $tagsChanged = $tagsBefore !== $tagIds;
        if (! $changes && ! $tagsChanged) {
            $this->count('leads.unchanged');

            return;
        }
        $this->count(($changes['archived'] ?? null) === true ? 'leads.archived' : 'leads.updated');
        if ($this->dry) {
            return;
        }
        DB::transaction(function () use ($lead, $d, $changes, $tagIds, $tagsChanged, $tagsBefore, $gone) {
            $old = [];
            foreach (array_keys($changes) as $column) {
                $current = $lead->getAttribute($column);
                $old[$column] = $current instanceof DateTimeInterface ? $current->format('Y-m-d H:i:s') : $current;
            }
            $from = ['stage' => $lead->stage_id, 'status' => $lead->status, 'archived' => $lead->archived, 'owner' => $lead->owner_id ?: $lead->owner_name];
            $lead->update($changes);
            if ($tagsChanged) {
                $lead->tags()->sync($tagIds);
                $lead->load('tags:id');
            }
            // what happened in Odoo is told on the lead's timeline, at the time Odoo gives for it (else: when it was written there)
            $written = $lead->source_updated_at;
            $by = ['origin' => self::SYSTEM, 'username' => Val::label($d['write_uid'] ?? null) ?? self::SYSTEM];
            if (isset($changes['status'])) {
                $kind = $lead->status === 'open' ? 'reopened' : $lead->status;
                Timeline::add($lead, $kind, null, $by + ['from' => $from['stage'], 'to' => $lead->status === 'lost' ? null : $lead->stage_id, 'at' => $kind === 'reopened' ? $written : ($lead->closed_at ?? $written),
                    'data' => $kind === 'reopened' ? ['from' => $from['status']] : []]);
                $this->audit->status($this->actor(), 'CrmLead', $lead->id, $lead->number, $from['status'], $lead->status, 'Odoo');
                if ($lead->status === 'lost') {
                    $this->book->cancelOpenOf($lead, $this->actor()->username, 'أُغلقت الفرصة كخاسرة في أودو');
                }
            } elseif (array_key_exists('stage_id', $changes)) {
                Timeline::add($lead, 'stage', null, $by + ['from' => $from['stage'], 'to' => $lead->stage_id, 'at' => $lead->stage_changed_at ?? $written]);
            }
            if (array_key_exists('owner_id', $changes) || array_key_exists('owner_name', $changes)) {
                Timeline::add($lead, 'assigned', null, $by + ['at' => $written, 'data' => ['from' => $from['owner'] ? $this->ownerLabel($from['owner']) : null, 'to' => $this->ownerLabel($lead->owner_id ?: $lead->owner_name)]]);
            }
            if (isset($changes['archived'])) {
                Timeline::add($lead, $lead->archived ? 'archived' : 'restored', null, $by + ['at' => $gone ? $lead->source_deleted_at : $written, 'note' => $gone ? 'حُذفت من أودو — محفوظة هنا بسجلها' : null]);
            }
            $this->audit->log($this->actor(), ['action' => 'LEAD.UPDATE', 'entityType' => 'CrmLead', 'entityId' => $lead->id, 'entityNumber' => $lead->number,
                'oldValue' => $old + ($tagsChanged ? ['tags' => $tagsBefore] : []), 'newValue' => array_map(fn ($v) => $v instanceof DateTimeInterface ? $v->format('Y-m-d H:i:s') : $v, $changes) + ($tagsChanged ? ['tags' => $tagIds] : []) + ['origin' => 'Odoo']]);
        });
    }

    /** A user id as the name people know, for the timeline (a name kept from Odoo is its own label). */
    private function ownerLabel(?string $idOrName): ?string
    {
        return $idOrName === null ? null : (DB::table('users')->where('id', $idOrName)->value('name_ar') ?? $idOrName);
    }

    // ───────────── people, partners, customers ─────────────

    /**
     * The OPS user an Odoo user is — by login or e-mail, and only when exactly one user answers — or the name to keep.
     *
     * @return array{0:?string, 1:?string} [OPS user id, name kept when there is none]
     */
    private function person(mixed $many2one): array
    {
        $odooId = Val::id($many2one);
        if (! $odooId) {
            return [null, null];
        }
        if (! isset($this->people[$odooId])) {
            $name = Val::label($many2one);
            $keys = [];
            if ($u = $this->mirrored('res.users', $odooId)) {
                $keys[] = Val::text($u->data['login'] ?? null);
                $keys[] = Val::text($u->data['email'] ?? null);
                // the e-mail of an Odoo user lives on its partner
                $keys[] = Val::text($this->mirrored('res.partner', Val::id($u->data['partner_id'] ?? null))?->data['email'] ?? null);
                $name ??= Val::text($u->display_name);
            }
            $found = [];
            foreach (array_filter($keys) as $key) {
                $key = mb_strtolower($key);
                foreach ([$this->userByEmail[$key] ?? null, $this->userByName[$key] ?? null] as $id) {
                    if ($id) {
                        $found[$id] = true;
                    }
                }
            }
            $id = count($found) === 1 ? (string) array_key_first($found) : null;
            $this->people[$odooId] = [$id, $id ? null : self::cut($name ?? "#{$odooId}", 190)];
            $this->count($id ? 'people.matched' : 'people.byName', $id ? null : $this->people[$odooId][1]);
        }

        return $this->people[$odooId];
    }

    /** @return array{parent:?int, vat:?string} */
    private function partner(int $odooId): array
    {
        return $this->partners[$odooId] ??= (function () use ($odooId) {
            $data = (array) ($this->mirrored('res.partner', $odooId)?->data ?? []);
            $vat = is_string($data['vat'] ?? null) ? substr(preg_replace('/\D+/', '', $data['vat']), 0, 40) : '';

            return ['parent' => Val::id($data['parent_id'] ?? null), 'vat' => $vat === '' ? null : $vat];
        })();
    }

    /** The OPS customer the Odoo partner is linked to; for a contact of a company, the company's. */
    private function customer(?int $partnerId): ?string
    {
        if (! $partnerId) {
            return null;
        }
        $id = $this->linked('customer', $partnerId) ?? (($parent = $this->partner($partnerId)['parent']) ? $this->linked('customer', $parent) : null);
        if (! $id) {
            return null;
        }
        // a link to a customer that is no longer there names nobody
        $this->customers[$id] ??= Customer::whereKey($id)->exists();

        return $this->customers[$id] ? (string) $id : null;
    }

    // ───────────── helpers ─────────────

    /** @return array{name_ar:string, name_en:string} */
    private static function names(OdooRecord $r): array
    {
        $name = self::cut(Val::text($r->data['name'] ?? null) ?? Val::text($r->display_name) ?? "#{$r->odoo_id}", 120);
        $arabic = preg_match('/\p{Arabic}/u', $name) ? $name : (self::ARABIC[mb_strtolower($name)] ?? $name);

        return ['name_ar' => $arabic, 'name_en' => $name];
    }

    private static function cut(?string $s, int $max): ?string
    {
        return $s === null ? null : mb_substr($s, 0, $max);
    }

    /**
     * The values that differ from what the row holds. A moment equals the same moment, a day its `YYYY-MM-DD`, 5 equals
     * "5.00", and a marker of a plan ("new:…") always differs.
     *
     * @return array<string, mixed>
     */
    private static function changes(Model $row, array $values): array
    {
        $out = [];
        foreach ($values as $column => $value) {
            $current = $row->getAttribute($column);
            if ($current instanceof DateTimeInterface || $value instanceof DateTimeInterface) {
                $format = is_string($value) && strlen($value) === 10 ? 'Y-m-d' : 'Y-m-d H:i:s';
                $same = ($current instanceof DateTimeInterface ? $current->format($format) : $current) === ($value instanceof DateTimeInterface ? $value->format($format) : $value);
            } elseif (is_bool($current) || is_bool($value)) {
                $same = $current !== null && $value !== null && (bool) $current === (bool) $value;
            } elseif (is_array($current) || is_array($value)) {
                // a JSON column gives its keys back in the database's order, and 3 for 3.0: compared by content
                [$a, $b] = [(array) ($current ?: []), (array) ($value ?: [])];
                ksort($a);
                ksort($b);
                $same = $a == $b;
            } elseif (is_numeric($current) && is_numeric($value) && (is_int($value) || is_float($value) || is_int($current) || is_float($current))) {
                $same = abs((float) $current - (float) $value) < 0.005;
            } else {
                $same = (string) ($current ?? '') === (string) ($value ?? '');
            }
            if (! $same) {
                $out[$column] = $value;
            }
        }

        return $out;
    }
}
