<?php

namespace App\Crm\Services;

use App\Crm\Models\CrmActivity;
use App\Crm\Models\CrmLead;
use App\Crm\Models\CrmLeadEvent;
use App\Crm\Models\CrmLostReason;
use App\Crm\Models\CrmStage;
use App\Crm\Models\CrmTag;
use App\Crm\Models\CrmTeam;
use App\Crm\Support\Owned;
use App\Crm\Support\People;
use App\Crm\Support\Shape;
use App\Crm\Support\Timeline;
use App\Models\Customer;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\NumberingService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Support\Sql;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The pipeline: leads / opportunities, where they stand on the board and how they end.
 *
 * STATE MACHINE. `status` is open → won | lost, and won | lost → open only by a reopening (audited). A lead moves
 * between stages only while it is open; entering a stage marked "won" IS winning it; a lost lead keeps the stage it was
 * lost in and carries a reason. Every move, closing, reopening, change of salesperson and note is one line of the
 * lead's timeline (`crm_lead_events`), so the history of a lead is never rewritten.
 *
 * OWNERSHIP. While the section follows Odoo a lead that came from Odoo is Odoo's (`Owned`): its values, its stage and
 * its closing are refused here with CRM_OWNED_BY_SOURCE; notes, to-dos and the OPS customer it became stay OPS's.
 *
 * Payload arrays are camelCase and already validated by the controller. In an edit, a key that is present with a null
 * value means "sent empty" (clear it) and an absent key means "leave as it is".
 */
class LeadsService
{
    public const WITH = ['stage', 'team', 'owner:id,username,name_ar,name_en', 'customer:id,code,name_ar,name_en,city,vat_no,active', 'lostReason', 'tags'];

    /** The lists of the section: open (default) · won · lost · archived · all; `board` = what the board shows. */
    public const STATES = ['open', 'won', 'lost', 'archived', 'all', 'board'];

    private const SORTS = [
        'createdAt' => 'created_at', 'number' => 'number', 'name' => 'name', 'expectedRevenue' => 'expected_revenue', 'probability' => 'probability', 'priority' => 'priority',
        'stageChangedAt' => 'stage_changed_at', 'closedAt' => 'closed_at', 'nextActivity' => 'next_activity_date', 'deadline' => 'deadline',
    ];

    /** Columns that are often empty: the empty ones come last whichever way the list is sorted. */
    private const SPARSE = ['stage_changed_at', 'closed_at', 'next_activity_date', 'deadline'];

    /** Text of the form: camelCase key → column. Lengths are the controller's business. */
    private const TEXT = [
        'name' => 'name', 'contactName' => 'contact_name', 'companyName' => 'company_name', 'jobTitle' => 'job_title', 'phone' => 'phone', 'mobile' => 'mobile', 'email' => 'email',
        'website' => 'website', 'street' => 'street', 'street2' => 'street2', 'city' => 'city', 'region' => 'region', 'zip' => 'zip', 'country' => 'country',
        'source' => 'source', 'medium' => 'medium', 'campaign' => 'campaign', 'referredBy' => 'referred_by', 'description' => 'description',
    ];

    public function __construct(
        private readonly AuditService $audit,
        private readonly NumberingService $numbering,
        private readonly NotifyService $notify,
        private readonly ActivityBook $book,
        private readonly LeadQuotations $quotations,
    ) {}

    // ───────────── reading ─────────────

    /**
     * @param  array{state?:?string, stage?:?string, owner?:?string, team?:?string, priority?:?string, tag?:?string, kind?:?string, activity?:?string,
     *               customer?:?string, origin?:?string, from?:?string, to?:?string}  $f
     */
    public function list(AuthUser $user, Paging $page, array $f): array
    {
        $query = $this->filtered($user, $f, $page->q)->with(self::WITH);
        $this->order($query, $page->sort, $page->order);
        $today = Carbon::today();

        return $page->paginate($query, fn (CrmLead $l) => Shape::card($l, $today));
    }

    /**
     * The board: one column per stage with its count, its expected revenue (plain and weighted by the chance of each
     * opportunity) and its first cards — the most urgent first. A column holds hundreds of leads: the rest of it is
     * read page by page through the list (`state=board&stage=…&sort=board`).
     */
    public function board(AuthUser $user, array $f, ?string $q, int $per): array
    {
        unset($f['stage']);
        $base = fn (): Builder => $this->filtered($user, ['state' => 'board'] + $f, $q);
        $sums = $base()->toBase()->selectRaw('stage_id, COUNT(*) AS n, COALESCE(SUM(expected_revenue), 0) AS revenue, COALESCE(SUM(expected_revenue * probability / 100), 0) AS weighted')
            ->groupBy('stage_id')->get()->keyBy('stage_id');
        $today = Carbon::today();
        $columns = [];
        // an archived stage that still holds leads (a stage removed in Odoo under them) keeps its column
        $stages = CrmStage::query()->where(fn ($w) => $w->where('active', true)->orWhereIn('id', $sums->keys()->all()))->ordered()->get();
        foreach ($stages as $s) {
            $sum = $sums[$s->id] ?? null;
            $n = (int) ($sum->n ?? 0);
            $cards = $n ? $this->order($base()->where('stage_id', $s->id)->with(self::WITH), 'board', 'desc')->limit($per)->get() : collect();
            $columns[] = Shape::stage($s) + [
                'count' => $n, 'revenue' => round((float) ($sum->revenue ?? 0), 2), 'weighted' => round((float) ($sum->weighted ?? 0), 2),
                'items' => $cards->map(fn (CrmLead $l) => Shape::card($l, $today))->all(), 'more' => $n > $cards->count(),
            ];
        }

        return [
            'stages' => $columns, 'per' => $per,
            'totals' => ['count' => array_sum(array_column($columns, 'count')), 'revenue' => round(array_sum(array_column($columns, 'revenue')), 2), 'weighted' => round(array_sum(array_column($columns, 'weighted')), 2)],
        ];
    }

    public function get(string $idOrNumber): array
    {
        return $this->detail($this->find($idOrNumber));
    }

    /** The filters every list of leads shares (the board and the report read through it too). */
    public function filtered(AuthUser $user, array $f, ?string $q): Builder
    {
        $query = CrmLead::query();
        match ($f['state'] ?? 'open') {
            'won' => $query->where('status', 'won'),
            'lost' => $query->where('status', 'lost'),
            'archived' => $query->where('archived', true),
            'all' => null,
            'board' => $query->whereIn('status', ['open', 'won'])->where('archived', false),
            default => $query->where('status', 'open')->where('archived', false),
        };
        foreach (['stage' => 'stage_id', 'team' => 'team_id', 'kind' => 'kind'] as $key => $column) {
            if (! empty($f[$key])) {
                $query->where($column, $f[$key]);
            }
        }
        if (isset($f['priority']) && $f['priority'] !== '' && $f['priority'] !== null) {
            $query->where('priority', (int) $f['priority']);
        }
        if (! empty($f['owner'])) {
            $owner = (string) $f['owner'];
            match (true) {
                $owner === 'me' => $query->where('owner_id', $user->id),
                $owner === 'none' => $query->whereNull('owner_id')->whereNull('owner_name'),
                str_starts_with($owner, 'name:') => $query->whereNull('owner_id')->where('owner_name', substr($owner, 5)),
                default => $query->where('owner_id', $owner),
            };
        }
        if (! empty($f['tag'])) {
            $query->whereIn('id', DB::table('crm_lead_tags')->where('tag_id', $f['tag'])->select('lead_id'));
        }
        if (! empty($f['customer'])) {
            $query->whereIn('customer_id', Customer::where('id', $f['customer'])->orWhere('code', $f['customer'])->select('id'));
        }
        if (! empty($f['origin'])) {
            $f['origin'] === 'ops' ? $query->whereNull('source_system') : $query->where('source_system', $f['origin']);
        }
        if (! empty($f['activity'])) {
            $today = Carbon::today()->toDateString();
            match ($f['activity']) {
                'overdue' => $query->where('next_activity_date', '<', $today),
                'today' => $query->where('next_activity_date', $today),
                'upcoming' => $query->where('next_activity_date', '>', $today),
                'none' => $query->whereNull('next_activity_date'),
                default => null,
            };
        }
        if (! empty($f['from'])) {
            $query->where('created_at', '>=', Carbon::parse($f['from'])->startOfDay());
        }
        if (! empty($f['to'])) {
            $query->where('created_at', '<', Carbon::parse($f['to'])->startOfDay()->addDay());
        }
        if ($q !== null && $q !== '') {
            $like = '%'.addcslashes($q, '\\%_').'%';
            $op = Sql::like();
            $query->where(function ($w) use ($like, $op, $q) {
                foreach (['number', 'name', 'contact_name', 'company_name', 'phone', 'mobile', 'email', 'city'] as $column) {
                    $w->orWhere($column, $op, $like);
                }
                if (ctype_digit($q)) {
                    $w->orWhere('external_ref', $q); // the number people know the lead by in Odoo
                }
            });
        }

        return $query;
    }

    private function order(Builder $query, ?string $sort, string $order): Builder
    {
        if ($sort === 'board') {
            // what needs attention first: the stars, then the earliest to-do (a lead with none comes after), then the newest
            return $query->orderByDesc('priority')->orderByRaw('next_activity_date IS NULL')->orderBy('next_activity_date')->orderByDesc('created_at')->orderByDesc('id');
        }
        $column = self::SORTS[$sort ?? ''] ?? null;
        if ($column === null) {
            return $query->orderByDesc('created_at')->orderByDesc('id');
        }
        if (in_array($column, self::SPARSE, true)) {
            $query->orderByRaw("{$column} IS NULL");
        }

        return $query->orderBy($column, $order)->orderBy('id', $order);
    }

    private function detail(CrmLead $l): array
    {
        $today = Carbon::today();
        $l->loadMissing(self::WITH);
        // what is still to do first, the soonest on top; then what was done or cancelled, the latest on top
        [$todo, $closed] = CrmActivity::with('user:id,username,name_ar,name_en')->where('lead_id', $l->id)->orderByDesc('created_at')->orderByDesc('id')->limit(300)->get()
            ->partition(fn (CrmActivity $a) => $a->status === 'open');
        $activities = $todo->sortBy(fn (CrmActivity $a) => $a->due_date->format('Ymd').$a->id)->values()
            ->concat($closed->sortByDesc(fn (CrmActivity $a) => ($a->done_at ?? $a->cancelled_at ?? $a->created_at)->format('YmdHisv').$a->id)->values());
        $events = CrmLeadEvent::where('lead_id', $l->id)->orderByDesc('at')->orderByDesc('id')->limit(300)->get();
        $stages = CrmStage::whereIn('id', $events->pluck('from_stage_id')->merge($events->pluck('to_stage_id'))->filter()->unique()->values())->get()->keyBy('id')->all();
        $owned = Owned::bySource($l);
        $open = $l->status === 'open' && ! $l->archived;

        return Shape::lead($l, $today) + [
            'activities' => $activities->map(fn (CrmActivity $a) => Shape::activity($a, $today))->all(),
            'events' => $events->map(fn (CrmLeadEvent $e) => Shape::event($e, $stages))->all(),
            'quotations' => $this->quotations->of($l->id),
            // what may be done to it now — the client shows a button only for what would be accepted
            'can' => [
                'edit' => ! $owned, 'move' => ! $owned && $open, 'win' => ! $owned && $open, 'lose' => ! $owned && $open, 'reopen' => ! $owned && ! $open,
                'convert' => $l->status === 'won' && ! $l->customer_id, 'quote' => (bool) $l->customer_id && $l->status !== 'lost', 'note' => true, 'activity' => true,
            ],
        ];
    }

    // ───────────── writing ─────────────

    public function create(AuthUser $user, array $dto): array
    {
        $id = DB::transaction(function () use ($user, $dto) {
            $data = $this->values($dto, null);
            $stage = ! empty($dto['stageId']) ? $this->stage($dto['stageId']) : $this->firstOpenStage($data['team_id'] ?? null);
            if ($stage->is_won) {
                throw AppError::rule('CRM_STAGE_IS_WON', 'لا تُنشأ الفرصة في مرحلة كسب مباشرة — أنشئها مفتوحة ثم سجّل كسبها', 'An opportunity is not created in a won stage — create it open, then mark it won');
            }
            $this->stageFitsTeam($stage, $data['team_id'] ?? null);
            if (! array_key_exists('owner_id', $data)) {
                $data['owner_id'] = $user->id; // nobody was named: the person who enters the lead works it
            }
            $l = CrmLead::create($data + [
                'number' => $this->numbering->next('OPP'), 'kind' => 'opportunity', 'stage_id' => $stage->id, 'status' => 'open', 'archived' => false,
                'probability' => $stage->probability ?? 0, 'priority' => 0, 'expected_revenue' => 0, 'opened_at' => now(), 'stage_changed_at' => now(),
                'created_by' => $user->username, 'updated_by' => $user->username,
            ]);
            if (array_key_exists('tagIds', $dto)) {
                $l->tags()->sync($this->tagIds((array) $dto['tagIds']));
            }
            Timeline::add($l, 'created', $user, ['to' => $stage->id]);
            $this->audit->log($user, ['action' => 'LEAD.CREATE', 'entityType' => 'CrmLead', 'entityId' => $l->id, 'entityNumber' => $l->number, 'newValue' => $dto]);
            $this->notify->activity($user, 'CrmLead', $l->id, $l->number, "فرصة جديدة {$l->number} — {$l->name}", "New opportunity {$l->number} — {$l->name}");
            People::tell($l->owner_id, $user, $l->id, $l->number, "أُسندت إليك الفرصة {$l->number} — {$l->name}", "Opportunity {$l->number} was assigned to you — {$l->name}");

            return $l->id;
        });

        return $this->get($id);
    }

    public function update(AuthUser $user, string $idOrNumber, array $dto): array
    {
        $id = DB::transaction(function () use ($user, $idOrNumber, $dto) {
            $l = $this->locked($idOrNumber);
            Owned::refuse($l, 'lead');
            $data = $this->values($dto, $l);
            if (array_key_exists('customer_id', $data) && (string) $data['customer_id'] !== (string) $l->customer_id && DB::table('crm_lead_quotations')->where('lead_id', $l->id)->exists()) {
                throw AppError::rule('CRM_CUSTOMER_LOCKED', 'للفرصة عروض أسعار باسم عميلها الحالي — لا يُغيَّر العميل', 'The opportunity has quotations for its current customer — the customer cannot be changed');
            }
            $old = $new = [];
            foreach ($data as $column => $value) {
                $current = $l->getAttribute($column);
                if (! self::same($current, $value)) {
                    $old[$column] = $current instanceof Carbon ? $current->toDateString() : $current;
                    $new[$column] = $value;
                }
            }
            $tags = null;
            if (array_key_exists('tagIds', $dto)) {
                $tags = $this->tagIds((array) $dto['tagIds']);
                $before = $l->tags()->pluck('crm_tags.id')->sort()->values()->all();
                if ($before === collect($tags)->sort()->values()->all()) {
                    $tags = null;
                } else {
                    $old['tags'] = $before;
                    $new['tags'] = $tags;
                }
            }
            if (! $new) {
                return $l->id;
            }
            $ownerChanged = array_key_exists('owner_id', $new) || array_key_exists('owner_name', $new);
            $ownerBefore = $ownerChanged && ($l->owner_id || $l->owner_name) ? Shape::person($l->owner_id, $l->owner, $l->owner_name)['nameAr'] : null;
            $l->update(array_diff_key($new, ['tags' => 1]) + ['updated_by' => $user->username]);
            if ($tags !== null) {
                $l->tags()->sync($tags);
            }
            if ($ownerChanged) {
                $l->load('owner:id,username,name_ar,name_en');
                Timeline::add($l, 'assigned', $user, ['data' => ['from' => $ownerBefore, 'to' => $l->owner?->name_ar]]);
                People::tell($l->owner_id, $user, $l->id, $l->number, "أُسندت إليك الفرصة {$l->number} — {$l->name}", "Opportunity {$l->number} was assigned to you — {$l->name}");
            }
            $this->audit->log($user, ['action' => 'LEAD.UPDATE', 'entityType' => 'CrmLead', 'entityId' => $l->id, 'entityNumber' => $l->number, 'oldValue' => $old, 'newValue' => $new]);

            return $l->id;
        });

        return $this->get($id);
    }

    /** To another stage of the board. Only an open lead moves; entering a won stage wins it. */
    public function move(AuthUser $user, string $idOrNumber, string $stageId, ?string $note = null): array
    {
        $id = DB::transaction(function () use ($user, $idOrNumber, $stageId, $note) {
            $l = $this->locked($idOrNumber);
            Owned::refuse($l, 'lead');
            $this->mustBeOpen($l);
            $to = $this->stage($stageId);
            $this->stageFitsTeam($to, $l->team_id);
            if ($to->id === $l->stage_id) {
                return $l->id; // it is there already
            }
            if ($to->is_won) {
                $this->toWon($user, $l, $to, $note);

                return $l->id;
            }
            $from = CrmStage::find($l->stage_id);
            $l->update(['stage_id' => $to->id, 'stage_changed_at' => now(), 'probability' => $to->probability ?? $l->probability, 'updated_by' => $user->username]);
            Timeline::add($l, 'stage', $user, ['from' => $from?->id, 'to' => $to->id, 'note' => $note]);
            $this->audit->log($user, ['action' => 'LEAD.MOVE', 'entityType' => 'CrmLead', 'entityId' => $l->id, 'entityNumber' => $l->number, 'field' => 'stage', 'oldValue' => $from?->name_ar, 'newValue' => $to->name_ar]);

            return $l->id;
        });

        return $this->get($id);
    }

    /** open → won, into the given won stage or the first one of the board. */
    public function win(AuthUser $user, string $idOrNumber, ?string $stageId = null, ?string $note = null): array
    {
        $id = DB::transaction(function () use ($user, $idOrNumber, $stageId, $note) {
            $l = $this->locked($idOrNumber);
            Owned::refuse($l, 'lead');
            $this->mustBeOpen($l);
            if ($stageId) {
                $to = $this->stage($stageId);
                if (! $to->is_won) {
                    throw AppError::rule('CRM_STAGE_NOT_WON', "المرحلة «{$to->name_ar}» ليست مرحلة كسب", "The stage \"{$to->name_en}\" is not a won stage");
                }
            } else {
                $to = CrmStage::where('active', true)->where('is_won', true)->where(fn ($w) => $w->whereNull('team_id')->orWhere('team_id', $l->team_id))->ordered()->first()
                    ?? throw AppError::rule('CRM_NO_WON_STAGE', 'لا توجد في المسار مرحلة كسب — أضف مرحلة مكسوبة من الإعدادات', 'The pipeline has no won stage — add one in the settings');
            }
            $this->stageFitsTeam($to, $l->team_id);
            $this->toWon($user, $l, $to, $note);

            return $l->id;
        });

        return $this->get($id);
    }

    /** open → lost, with the reason. What was still planned for the lead is cancelled with it. */
    public function lose(AuthUser $user, string $idOrNumber, string $reasonId, ?string $note = null): array
    {
        $id = DB::transaction(function () use ($user, $idOrNumber, $reasonId, $note) {
            $l = $this->locked($idOrNumber);
            Owned::refuse($l, 'lead');
            $this->mustBeOpen($l);
            $reason = CrmLostReason::find($reasonId) ?? throw AppError::notFound('CRM_REASON_NOT_FOUND', 'سبب الخسارة غير موجود', 'Lost reason not found');
            if (! $reason->active) {
                throw AppError::rule('CRM_REASON_INACTIVE', "سبب الخسارة «{$reason->name_ar}» مؤرشف — اختر سببًا آخر", "The lost reason \"{$reason->name_en}\" is archived — pick another one");
            }
            $before = (float) $l->probability;
            $l->update(['status' => 'lost', 'probability' => 0, 'closed_at' => now(), 'lost_reason_id' => $reason->id, 'lost_note' => trim((string) $note) ?: null, 'updated_by' => $user->username]);
            Timeline::add($l, 'lost', $user, ['note' => $note, 'from' => $l->stage_id, 'data' => ['reasonId' => $reason->id, 'reasonAr' => $reason->name_ar, 'reasonEn' => $reason->name_en, 'probabilityBefore' => $before]]);
            $this->audit->status($user, 'CrmLead', $l->id, $l->number, 'open', 'lost', trim($reason->name_ar.(trim((string) $note) !== '' ? ' — '.trim((string) $note) : '')));
            $this->book->cancelOpenOf($l, $user->username, 'أُغلقت الفرصة كخاسرة');
            $this->notify->activity($user, 'CrmLead', $l->id, $l->number, "خُسرت الفرصة {$l->number} — {$l->name} ({$reason->name_ar})", "Opportunity {$l->number} lost — {$l->name} ({$reason->name_en})");

            return $l->id;
        });

        return $this->get($id);
    }

    /**
     * won | lost | archived → open. A lead that was won goes back to the stage it was won from (or the one named, or
     * the first open stage); one that was lost stays in the stage it was lost in unless another is named.
     */
    public function reopen(AuthUser $user, string $idOrNumber, ?string $stageId = null, ?string $note = null): array
    {
        $id = DB::transaction(function () use ($user, $idOrNumber, $stageId, $note) {
            $l = $this->locked($idOrNumber);
            Owned::refuse($l, 'lead');
            if ($l->status === 'open' && ! $l->archived) {
                throw AppError::rule('CRM_ALREADY_OPEN', "الفرصة {$l->number} مفتوحة أصلًا", "Opportunity {$l->number} is already open");
            }
            $from = $l->status === 'open' ? 'archived' : $l->status;
            // how it was closed the last time: where it was won from, and the chance it had then
            $closing = $l->status === 'open' ? null : CrmLeadEvent::where('lead_id', $l->id)->where('kind', $l->status)->orderByDesc('at')->orderByDesc('id')->first();
            if ($stageId) {
                $to = $this->stage($stageId);
            } else {
                $current = CrmStage::find($l->stage_id);
                $earlier = $closing?->kind === 'won' && $closing->from_stage_id ? CrmStage::where('id', $closing->from_stage_id)->where('active', true)->where('is_won', false)->first() : null;
                $to = $current && $current->active && ! $current->is_won ? $current : ($earlier ?? $this->firstOpenStage($l->team_id));
            }
            if ($to->is_won) {
                throw AppError::rule('CRM_STAGE_IS_WON', 'لا تُعاد الفرصة إلى مرحلة كسب — اختر مرحلة مفتوحة', 'An opportunity is not reopened into a won stage — pick an open stage');
            }
            $this->stageFitsTeam($to, $l->team_id);
            $stageFrom = $l->stage_id;
            // the chance it had before it was closed; else the stage's; else half for one that had been won
            $probability = $closing?->data['probabilityBefore'] ?? $to->probability ?? ($l->status === 'won' ? 50 : (float) $l->probability);
            $l->update([
                'status' => 'open', 'archived' => false, 'stage_id' => $to->id, 'closed_at' => null, 'lost_reason_id' => null, 'lost_note' => null, 'probability' => $probability,
                'stage_changed_at' => $to->id !== $stageFrom ? now() : $l->stage_changed_at, 'updated_by' => $user->username,
            ]);
            Timeline::add($l, 'reopened', $user, ['note' => $note, 'from' => $stageFrom, 'to' => $to->id, 'data' => ['from' => $from]]);
            if ($from === 'archived') {
                $this->audit->log($user, ['action' => 'LEAD.RESTORE', 'entityType' => 'CrmLead', 'entityId' => $l->id, 'entityNumber' => $l->number, 'oldValue' => 'archived', 'newValue' => 'open']);
            } else {
                $this->audit->status($user, 'CrmLead', $l->id, $l->number, $from, 'open', trim((string) $note) ?: null);
            }
            $this->notify->activity($user, 'CrmLead', $l->id, $l->number, "أُعيد فتح الفرصة {$l->number} — {$l->name}", "Opportunity {$l->number} reopened — {$l->name}");

            return $l->id;
        });

        return $this->get($id);
    }

    /** A note on the timeline. OPS's own on every lead — also on one that Odoo owns. */
    public function note(AuthUser $user, string $idOrNumber, string $text): array
    {
        $id = DB::transaction(function () use ($user, $idOrNumber, $text) {
            $l = $this->locked($idOrNumber);
            Timeline::add($l, 'note', $user, ['note' => $text]);
            $this->audit->log($user, ['action' => 'LEAD.NOTE', 'entityType' => 'CrmLead', 'entityId' => $l->id, 'entityNumber' => $l->number, 'newValue' => mb_substr(trim($text), 0, 300)]);

            return $l->id;
        });

        return $this->get($id);
    }

    // ───────────── pieces ─────────────

    public function find(string $idOrNumber, array $with = self::WITH): CrmLead
    {
        return CrmLead::with($with)->where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->first()
            ?? throw AppError::notFound('LEAD_NOT_FOUND', 'الفرصة غير موجودة', 'Opportunity not found');
    }

    /** The lead, its row held for the rest of the transaction (two people cannot close or move it at once). */
    public function locked(string $idOrNumber): CrmLead
    {
        return CrmLead::where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->lockForUpdate()->first()
            ?? throw AppError::notFound('LEAD_NOT_FOUND', 'الفرصة غير موجودة', 'Opportunity not found');
    }

    private function mustBeOpen(CrmLead $l): void
    {
        if ($l->status !== 'open' || $l->archived) {
            $state = $l->status !== 'open' ? ['won' => 'مكسوبة', 'lost' => 'خاسرة'][$l->status] : 'مؤرشفة';
            throw AppError::rule('CRM_NOT_OPEN', "الفرصة {$l->number} {$state} — أعد فتحها أولًا", "Opportunity {$l->number} is ".($l->status !== 'open' ? $l->status : 'archived').' — reopen it first', ['status' => $l->status, 'archived' => $l->archived]);
        }
    }

    private function toWon(AuthUser $user, CrmLead $l, CrmStage $to, ?string $note): void
    {
        $from = $l->stage_id;
        $before = (float) $l->probability;
        $l->update([
            'stage_id' => $to->id, 'status' => 'won', 'probability' => 100, 'closed_at' => now(), 'lost_reason_id' => null, 'lost_note' => null,
            'stage_changed_at' => $to->id !== $from ? now() : $l->stage_changed_at, 'updated_by' => $user->username,
        ]);
        Timeline::add($l, 'won', $user, ['note' => $note, 'from' => $from, 'to' => $to->id, 'data' => ['probabilityBefore' => $before, 'expectedRevenue' => (float) $l->expected_revenue]]);
        $this->audit->status($user, 'CrmLead', $l->id, $l->number, 'open', 'won', trim((string) $note) ?: null);
        $revenue = (float) $l->expected_revenue > 0 ? ' — '.number_format((float) $l->expected_revenue, 2).' ر.س' : '';
        $this->notify->activity($user, 'CrmLead', $l->id, $l->number, "كُسبت الفرصة {$l->number} — {$l->name}{$revenue}", "Opportunity {$l->number} won — {$l->name}");
        People::tell($l->owner_id, $user, $l->id, $l->number, "سُجّل كسب فرصتك {$l->number} — {$l->name}", "Your opportunity {$l->number} was marked won — {$l->name}");
    }

    private function stage(string $id): CrmStage
    {
        $s = CrmStage::find($id) ?? throw AppError::notFound('CRM_STAGE_NOT_FOUND', 'المرحلة غير موجودة', 'Stage not found');
        if (! $s->active) {
            throw AppError::rule('CRM_STAGE_INACTIVE', "المرحلة «{$s->name_ar}» مؤرشفة", "The stage \"{$s->name_en}\" is archived");
        }

        return $s;
    }

    private function firstOpenStage(?string $teamId): CrmStage
    {
        return CrmStage::where('active', true)->where('is_won', false)->where(fn ($w) => $w->whereNull('team_id')->when($teamId, fn ($x) => $x->orWhere('team_id', $teamId)))->ordered()->first()
            ?? throw AppError::rule('CRM_NO_STAGE', 'لا توجد مرحلة مفتوحة في المسار — أضف مرحلة من الإعدادات', 'The pipeline has no open stage — add one in the settings');
    }

    /** A stage that belongs to one team takes only that team's leads. */
    private function stageFitsTeam(CrmStage $stage, ?string $teamId): void
    {
        if ($stage->team_id && $stage->team_id !== $teamId) {
            throw AppError::rule('CRM_STAGE_TEAM', "المرحلة «{$stage->name_ar}» خاصة بفريق آخر", "The stage \"{$stage->name_en}\" belongs to another team");
        }
    }

    /**
     * The columns a payload sets. For a new lead (`$l` null) empty values are simply left out.
     *
     * @return array<string, mixed>
     */
    private function values(array $dto, ?CrmLead $l): array
    {
        $data = [];
        foreach (self::TEXT as $key => $column) {
            if (! array_key_exists($key, $dto)) {
                continue;
            }
            $value = is_string($dto[$key]) ? trim($dto[$key]) : $dto[$key];
            $data[$column] = $value === '' ? null : $value;
        }
        if (array_key_exists('name', $data) && $data['name'] === null) {
            throw AppError::validation('LEAD_NAME', 'عنوان الفرصة إلزامي', 'The opportunity needs a title', [['path' => 'name', 'message' => 'هذا الحقل إلزامي']]);
        }
        if (array_key_exists('vatNo', $dto)) {
            $data['vat_no'] = self::digits($dto['vatNo']);
        }
        if (array_key_exists('kind', $dto) && $dto['kind']) {
            $data['kind'] = $dto['kind'];
        }
        if (array_key_exists('priority', $dto) && $dto['priority'] !== null) {
            $data['priority'] = (int) $dto['priority'];
        }
        if (array_key_exists('expectedRevenue', $dto)) {
            $data['expected_revenue'] = round((float) ($dto['expectedRevenue'] ?? 0), 2);
        }
        if (array_key_exists('probability', $dto) && $dto['probability'] !== null) {
            $data['probability'] = round((float) $dto['probability'], 2);
        }
        if (array_key_exists('deadline', $dto)) {
            $data['deadline'] = $dto['deadline'] ? substr((string) $dto['deadline'], 0, 10) : null;
        }
        if (array_key_exists('ownerId', $dto)) {
            $data['owner_id'] = $dto['ownerId'] ? People::assignable((string) $dto['ownerId'], 'ownerId')->id : null;
            $data['owner_name'] = null; // a person of OPS was named (or nobody): a name kept from Odoo no longer stands in
        }
        if (array_key_exists('teamId', $dto)) {
            if ($dto['teamId']) {
                $team = CrmTeam::find($dto['teamId']) ?? throw AppError::validation('CRM_TEAM_UNKNOWN', 'الفريق غير موجود', 'Unknown team', [['path' => 'teamId', 'message' => 'الفريق غير موجود']]);
                if (! $team->active && $team->id !== $l?->team_id) {
                    throw AppError::rule('CRM_TEAM_INACTIVE', "الفريق «{$team->name_ar}» مؤرشف", "The team \"{$team->name_en}\" is archived");
                }
                $data['team_id'] = $team->id;
            } else {
                $data['team_id'] = null;
            }
        }
        if (array_key_exists('customerId', $dto)) {
            if ($dto['customerId']) {
                $data['customer_id'] = (Customer::where('id', $dto['customerId'])->orWhere('code', $dto['customerId'])->first()
                    ?? throw AppError::validation('CUSTOMER_NOT_FOUND', 'العميل غير موجود', 'Customer not found', [['path' => 'customerId', 'message' => 'العميل غير موجود']]))->id;
            } else {
                $data['customer_id'] = null;
            }
        }
        if ($l === null) {
            $data = array_filter($data, fn ($v) => $v !== null);
        }

        return $data;
    }

    /** Ids of existing tags, in the order given; an unknown one refuses the whole request. @return string[] */
    private function tagIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));
        if (! $ids) {
            return [];
        }
        $known = CrmTag::whereIn('id', $ids)->pluck('id')->map(fn ($id) => (string) $id)->all();
        if ($missing = array_values(array_diff($ids, $known))) {
            throw AppError::validation('CRM_TAG_UNKNOWN', 'وسم غير موجود', 'Unknown tag', [['path' => 'tagIds', 'message' => 'وسم غير موجود: '.implode(', ', $missing)]]);
        }

        return $ids;
    }

    /** The digits of a number people type with spaces, dashes or letters around it (a VAT number). */
    public static function digits(mixed $v): ?string
    {
        $s = is_string($v) || is_int($v) ? substr(preg_replace('/\D+/', '', (string) $v), 0, 40) : '';

        return $s === '' ? null : $s;
    }

    /** Is the stored value the value that was sent? A day equals its `YYYY-MM-DD`, 5 equals "5.00". */
    private static function same(mixed $current, mixed $value): bool
    {
        if ($current instanceof Carbon) {
            return is_string($value) && $current->toDateString() === substr($value, 0, 10);
        }
        if (is_bool($current) || is_bool($value)) {
            return (bool) $current === (bool) $value && ($current === null) === ($value === null);
        }
        if (is_numeric($current) && is_numeric($value) && (is_int($value) || is_float($value) || is_int($current) || is_float($current))) {
            return abs((float) $current - (float) $value) < 1e-9;
        }

        return (string) ($current ?? '') === (string) ($value ?? '');
    }
}
