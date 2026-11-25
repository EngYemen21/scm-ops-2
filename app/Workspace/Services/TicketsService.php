<?php

namespace App\Workspace\Services;

use App\Models\Customer;
use App\Models\User;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\NumberingService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Support\Sql;
use App\Workspace\Models\Ticket;
use App\Workspace\Models\TicketStage;
use App\Workspace\Models\TicketTeam;
use App\Workspace\Models\TicketTeamMember;
use App\Workspace\Models\TicketType;
use App\Workspace\Models\WsFile;
use App\Workspace\Support\Owned;
use App\Workspace\Support\People;
use App\Workspace\Support\RecordLinks;
use App\Workspace\Support\Shape;
use App\Workspace\Support\TicketAccess;
use App\Workspace\Support\Ws;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The help desk: tickets from the moment somebody asks until the answer is recorded.
 *
 * STATE MACHINE. `status` is open → closed only by a closing (it needs a resolution and puts the ticket in a closing
 * stage) and closed → open only by a reopening. A ticket moves between the open stages of its team while it is open;
 * a closing stage is never entered by a plain move. `archived` puts a ticket away (a duplicate, a mistake) — nothing
 * is deleted. Every step is one line of the ticket's thread, so its history is never rewritten.
 *
 * TARGETS. The team gives a ticket two moments when it is opened: the first answer is due after
 * `first_response_hours`, the closing after `resolution_hours` (calendar hours — the desk has no working calendar).
 * The first reply of somebody who is not the requester stops the first clock. A person may set the due date by hand;
 * from then on the team's target no longer moves it.
 *
 * WHO. Anybody who opens the section raises a ticket. It is worked — edited, assigned, moved, closed, archived, given
 * internal notes — by the members of its team, its assignee and the help-desk manager (TicketAccess); its requester
 * answers on it and may reopen it.
 *
 * OWNERSHIP. While the section follows Odoo a ticket that came from Odoo is Odoo's (`Owned`): every change of it is
 * refused with WORKSPACE_OWNED_BY_SOURCE; replies and notes written here are OPS's own and stay open.
 *
 * Payload arrays are camelCase and already validated by the controller. In an edit a key that is present with a null
 * value means "sent empty" (clear it) and an absent key means "leave as it is".
 */
class TicketsService
{
    public const WITH = ['team:id,name_ar,name_en', 'stage:id,name_ar,name_en,is_closing', 'type:id,name_ar,name_en', 'customer:id,code,name_ar,name_en',
        'requesterUser:'.People::COLUMNS, 'assignee:'.People::COLUMNS];

    public const SCOPES = ['all', 'mine', 'assigned', 'requested', 'team'];

    public const STATES = ['open', 'closed', 'archived', 'all'];

    public const TRANSITIONS = ['open' => ['closed'], 'closed' => ['open']];

    private const SORTS = ['createdAt' => 'created_at', 'number' => 'number', 'subject' => 'subject', 'priority' => 'priority', 'dueAt' => 'due_at', 'lastMessageAt' => 'last_message_at', 'closedAt' => 'closed_at'];

    /** Columns that are often empty: the empty ones come last whichever way the list is sorted. */
    private const SPARSE = ['due_at', 'last_message_at', 'closed_at'];

    public function __construct(
        private readonly AuditService $audit,
        private readonly NumberingService $numbering,
        private readonly NotifyService $notify,
        private readonly FilesService $files,
        private readonly Conversation $conversation,
    ) {}

    // ───────────── reading ─────────────

    /**
     * @param  array{scope?:?string, state?:?string, team?:?string, stage?:?string, type?:?string, priority?:mixed, assignee?:?string, customer?:?string,
     *               overdue?:mixed, origin?:?string}  $f
     */
    public function list(AuthUser $user, Paging $page, array $f): array
    {
        $query = $this->filtered($user, $f, $page->q)->with(self::WITH);
        $column = self::SORTS[$page->sort ?? ''] ?? null;
        if ($column === null) {
            $query->orderByDesc('created_at')->orderByDesc('id');
        } else {
            if (in_array($column, self::SPARSE, true)) {
                $query->orderByRaw("{$column} IS NULL");
            }
            $query->orderBy($column, $page->order)->orderBy('id', $page->order);
        }
        $now = now();

        return $page->paginate($query, fn (Ticket $t) => Shape::ticket($t, $now));
    }

    public function get(AuthUser $user, string $idOrNumber): array
    {
        $t = $this->find($idOrNumber);
        $access = new TicketAccess($user);
        $this->mustSee($access, $t);

        return $this->detail($user, $t, $access);
    }

    /** What the top of the page counts, among the tickets the caller may see. @return array{open:int, mine:int, unassigned:int, overdue:int} */
    public function summary(AuthUser $user): array
    {
        $access = new TicketAccess($user);
        $open = fn (): Builder => $access->scope(Ticket::query())->where('status', 'open')->where('archived', false);

        return [
            'open' => $open()->count(), 'mine' => $open()->where('assignee_id', $user->id)->count(),
            'unassigned' => $open()->whereNull('assignee_id')->whereNull('assignee_name')->count(), 'overdue' => self::late($open())->count(),
        ];
    }

    /** The filters every list of tickets shares. */
    public function filtered(AuthUser $user, array $f, ?string $q): Builder
    {
        $access = new TicketAccess($user);
        $query = $access->scope(Ticket::query());
        match ($f['state'] ?? 'open') {
            'closed' => $query->where('status', 'closed')->where('archived', false),
            'archived' => $query->where('archived', true),
            'all' => null,
            default => $query->where('status', 'open')->where('archived', false),
        };
        $me = $user->id;
        match ($f['scope'] ?? 'all') {
            'mine' => $query->where(fn (Builder $w) => $w->where('assignee_id', $me)->orWhere('requester_user_id', $me)->orWhere('created_by_id', $me)),
            'assigned' => $query->where('assignee_id', $me),
            'requested' => $query->where(fn (Builder $w) => $w->where('requester_user_id', $me)->orWhere('created_by_id', $me)),
            'team' => $query->whereIn('team_id', $access->memberTeamIds()),
            default => null,
        };
        foreach (['team' => 'team_id', 'stage' => 'stage_id', 'type' => 'type_id'] as $key => $column) {
            if (! empty($f[$key])) {
                $query->where($column, $f[$key]);
            }
        }
        if (isset($f['priority']) && $f['priority'] !== '' && $f['priority'] !== null) {
            $query->where('priority', (int) $f['priority']);
        }
        if (! empty($f['assignee'])) {
            People::filter($query, (string) $f['assignee'], 'assignee_id', 'assignee_name', $user);
        }
        if (! empty($f['customer'])) {
            $query->whereIn('customer_id', Customer::where('id', $f['customer'])->orWhere('code', $f['customer'])->select('id'));
        }
        if (! empty($f['origin'])) {
            $f['origin'] === 'ops' ? $query->whereNull('source_system') : $query->where('source_system', $f['origin']);
        }
        if (filter_var($f['overdue'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            self::late($query->where('status', 'open')->where('archived', false));
        }
        Ws::search($query, ['number', 'subject', 'requester_name', 'requester_email', 'requester_phone', 'assignee_name', 'source_number', 'ref_number'], $q, function (Builder $w, string $term) {
            $like = '%'.addcslashes($term, '\\%_').'%';
            $op = Sql::like();
            $w->orWhereIn('customer_id', Customer::where('name_ar', $op, $like)->orWhere('name_en', $op, $like)->orWhere('code', $op, $like)->select('id'));
            if (ctype_digit($term)) {
                $w->orWhere('external_ref', $term); // the id people know the ticket by in Odoo
            }
        });

        return $query;
    }

    /** Open tickets that are past one of their two targets. */
    private static function late(Builder $query): Builder
    {
        $now = now();

        return $query->where(fn (Builder $w) => $w->where('due_at', '<', $now)
            ->orWhere(fn (Builder $o) => $o->whereNull('first_response_at')->where('first_response_due_at', '<', $now)));
    }

    private function detail(AuthUser $user, Ticket $t, TicketAccess $access): array
    {
        $t->loadMissing(self::WITH);
        $owned = Owned::bySource($t);
        $works = $access->works($t);
        $asked = $access->requested($t);
        $open = $t->status === 'open';
        $live = ! $t->archived;
        $removable = fn (WsFile $f) => FilesService::removableBy($user, $f, $access->manages());

        return Shape::ticket($t) + [
            'description' => $t->description, 'resolution' => $t->resolution, 'closedBy' => $t->closed_by, 'dueManual' => $t->due_manual, 'extra' => $t->extra ?: null,
            'updatedAt' => Ws::at($t->updated_at), 'updatedBy' => $t->updated_by, 'archivedAt' => Ws::at($t->archived_at), 'archivedBy' => $t->archived_by, 'archiveReason' => $t->archive_reason,
            'files' => $this->files->of('ticket', [$t->id], $removable)[(string) $t->id] ?? [],
            'messages' => $this->conversation->thread($user, 'ticket', $t->id, $access->manages()),
            // what may be done to it now — the client shows a button only for what would be accepted
            'can' => [
                'edit' => ! $owned && $works && $live, 'assign' => ! $owned && $works && $live, 'move' => ! $owned && $works && $open && $live,
                'close' => ! $owned && $works && $open && $live, 'reopen' => ! $owned && ($works || $asked) && ! $open && $live,
                'archive' => ! $owned && $works && $live, 'restore' => ! $owned && $works && ! $live,
                'reply' => ($works || $asked) && $live, 'note' => $works && $live,
            ],
        ];
    }

    // ───────────── writing ─────────────

    public function create(AuthUser $user, array $dto): array
    {
        $files = $this->files->decode($dto['files'] ?? null);
        $id = DB::transaction(function () use ($user, $dto, $files) {
            $team = $this->team((string) $dto['teamId']);
            if (! $team->active) {
                throw AppError::validation('WS_TEAM_UNKNOWN', 'هذا الفريق متوقف — اختر فريقًا نشطًا', 'This team is not active — pick an active one', [['path' => 'teamId', 'message' => 'فريق غير نشط']]);
            }
            $stage = $this->firstStage($team->id, false)
                ?? throw AppError::rule('WS_NO_OPEN_STAGE', 'لا مرحلة مفتوحة معرَّفة لهذا الفريق — يضيفها مدير الدعم من الإعدادات', 'This team has no open stage — the help-desk manager adds one in the settings');
            $assignee = ! empty($dto['assigneeId']) ? People::assignable((string) $dto['assigneeId'], 'assigneeId')
                : ($team->default_assignee_id ? User::where('id', $team->default_assignee_id)->where('active', true)->first() : null);
            [$refType, $refNumber] = RecordLinks::check($dto['refType'] ?? null, $dto['refNumber'] ?? null);
            $now = now();
            $t = Ticket::create($this->requester($dto, $user, null) + [
                'number' => $this->numbering->next('TKT'), 'subject' => trim((string) $dto['subject']), 'description' => Ws::text($dto['description'] ?? null),
                'team_id' => $team->id, 'stage_id' => $stage->id, 'type_id' => ! empty($dto['typeId']) ? $this->type((string) $dto['typeId'])->id : null,
                'priority' => (int) ($dto['priority'] ?? 0), 'status' => 'open', 'assignee_id' => $assignee?->id,
                'first_response_due_at' => self::after($now, $team->first_response_hours), 'due_at' => self::after($now, $team->resolution_hours),
                'ref_type' => $refType, 'ref_number' => $refNumber, 'created_by' => $user->username, 'created_by_id' => $user->id, 'updated_by' => $user->username,
            ]);
            $this->files->keepAll($user, 'ticket', $t->id, $files);
            $this->conversation->event('ticket', $t->id, 'created', $user, ['to' => ['ar' => $stage->name_ar, 'en' => $stage->name_en], 'assignee' => $assignee?->name_ar]);
            $this->audit->log($user, ['action' => 'TICKET.CREATE', 'entityType' => 'WsTicket', 'entityId' => $t->id, 'entityNumber' => $t->number,
                'newValue' => array_diff_key($dto, ['files' => 1]) + ['files' => count($files)]]);
            $this->notify->activity($user, 'WsTicket', $t->id, $t->number, "تذكرة جديدة {$t->number} — {$t->subject}", "New ticket {$t->number} — {$t->subject}");
            if ($assignee) {
                People::tell((string) $assignee->id, $user, 'WsTicket', $t->id, $t->number, "أُسندت إليك التذكرة {$t->number} — {$t->subject}", "Ticket {$t->number} was assigned to you — {$t->subject}");
            } else {
                // nobody was named and the team has no default: its members are told a ticket waits
                foreach (TicketTeamMember::where('team_id', $team->id)->whereNotNull('user_id')->pluck('user_id') as $member) {
                    People::tell((string) $member, $user, 'WsTicket', $t->id, $t->number, "تذكرة جديدة لفريقك بلا مسؤول: {$t->number} — {$t->subject}", "A new unassigned ticket for your team: {$t->number} — {$t->subject}");
                }
            }

            return $t->id;
        });

        return $this->get($user, $id);
    }

    public function update(AuthUser $user, string $idOrNumber, array $dto): array
    {
        $this->mustWork($user, $this->find($idOrNumber));
        $id = DB::transaction(function () use ($user, $idOrNumber, $dto) {
            $t = $this->locked($idOrNumber);
            Owned::refuse($t, 'ticket');
            $this->mustBeLive($t);
            $data = [];
            if (array_key_exists('subject', $dto)) {
                $data['subject'] = trim((string) $dto['subject']);
            }
            if (array_key_exists('description', $dto)) {
                $data['description'] = Ws::text($dto['description']);
            }
            if (array_key_exists('priority', $dto) && $dto['priority'] !== null) {
                $data['priority'] = (int) $dto['priority'];
            }
            if (array_key_exists('typeId', $dto)) {
                $data['type_id'] = ! empty($dto['typeId']) ? $this->type((string) $dto['typeId'])->id : null;
            }
            if (array_intersect(array_keys($dto), ['requesterKind', 'customerId', 'requesterUserId', 'requesterName', 'requesterEmail', 'requesterPhone'])) {
                $data += $this->requester($dto, $user, $t);
            }
            if (array_key_exists('refType', $dto) || array_key_exists('refNumber', $dto)) {
                [$data['ref_type'], $data['ref_number']] = RecordLinks::check(array_key_exists('refType', $dto) ? $dto['refType'] : $t->ref_type, array_key_exists('refNumber', $dto) ? $dto['refNumber'] : $t->ref_number);
            }
            $team = null;
            if (! empty($dto['teamId']) && (string) $dto['teamId'] !== (string) $t->team_id) {
                $team = $this->team((string) $dto['teamId']);
                $data['team_id'] = $team->id;
                // the stage must be one of the new team's: otherwise the ticket enters the first stage of its kind there
                $stage = $t->stage_id ? TicketStage::with('teams:id')->find($t->stage_id) : null;
                if (! $stage || ! $stage->fits($team->id)) {
                    $data['stage_id'] = $this->firstStage($team->id, $t->status === 'closed')?->id ?? $t->stage_id;
                }
            }
            // the due date: set by hand, given back to the team's target, or following a change of team
            $targetsOf = $team ?? TicketTeam::find($t->team_id);
            if (array_key_exists('dueAt', $dto)) {
                $data['due_manual'] = $dto['dueAt'] !== null && $dto['dueAt'] !== '';
                $data['due_at'] = $data['due_manual'] ? Carbon::parse((string) $dto['dueAt']) : self::after($t->created_at, $targetsOf?->resolution_hours);
            } elseif ($team && ! $t->due_manual) {
                $data['due_at'] = self::after($t->created_at, $team->resolution_hours);
            }
            if ($team && $t->first_response_at === null) {
                $data['first_response_due_at'] = self::after($t->created_at, $team->first_response_hours);
            }
            [$old, $new] = Ws::diff($t, $data);
            if (! $new) {
                return $t->id;
            }
            $t->update($new + ['updated_by' => $user->username]);
            $this->conversation->event('ticket', $t->id, 'edited', $user, ['fields' => self::fieldNames(array_keys($new))]);
            $this->audit->log($user, ['action' => 'TICKET.UPDATE', 'entityType' => 'WsTicket', 'entityId' => $t->id, 'entityNumber' => $t->number, 'oldValue' => $old, 'newValue' => $new]);

            return $t->id;
        });

        return $this->get($user, $id);
    }

    /** Gives the ticket to somebody, or to nobody. */
    public function assign(AuthUser $user, string $idOrNumber, ?string $assigneeId): array
    {
        $this->mustWork($user, $this->find($idOrNumber));
        $id = DB::transaction(function () use ($user, $idOrNumber, $assigneeId) {
            $t = $this->locked($idOrNumber);
            Owned::refuse($t, 'ticket');
            $this->mustBeLive($t);
            $to = $assigneeId !== null && $assigneeId !== '' ? People::assignable($assigneeId, 'assigneeId') : null;
            if ((string) ($to?->id ?? '') === (string) ($t->assignee_id ?? '') && ($to !== null || $t->assignee_name === null)) {
                return $t->id; // he has it already
            }
            $t->loadMissing('assignee:'.People::COLUMNS);
            $from = People::shape($t->assignee_id, $t->assignee, $t->assignee_name)['nameAr'] ?? null;
            $t->update(['assignee_id' => $to?->id, 'assignee_name' => null, 'updated_by' => $user->username]);
            $this->conversation->event('ticket', $t->id, 'assigned', $user, ['from' => $from, 'to' => $to?->name_ar]);
            $this->audit->log($user, ['action' => 'TICKET.ASSIGN', 'entityType' => 'WsTicket', 'entityId' => $t->id, 'entityNumber' => $t->number, 'field' => 'assignee', 'oldValue' => $from, 'newValue' => $to?->name_ar]);
            People::tell($to?->id ? (string) $to->id : null, $user, 'WsTicket', $t->id, $t->number, "أُسندت إليك التذكرة {$t->number} — {$t->subject}", "Ticket {$t->number} was assigned to you — {$t->subject}");

            return $t->id;
        });

        return $this->get($user, $id);
    }

    /** To another open stage of its team. A closing stage is entered through close(). */
    public function move(AuthUser $user, string $idOrNumber, string $stageId): array
    {
        $this->mustWork($user, $this->find($idOrNumber));
        $id = DB::transaction(function () use ($user, $idOrNumber, $stageId) {
            $t = $this->locked($idOrNumber);
            Owned::refuse($t, 'ticket');
            $this->mustBeLive($t);
            if ($t->status !== 'open') {
                throw AppError::rule('WS_TICKET_TRANSITION', 'التذكرة مغلقة — أعد فتحها قبل نقلها بين المراحل', 'The ticket is closed — reopen it before moving it between stages');
            }
            $to = $this->stage($stageId, $t->team_id);
            if ($to->is_closing) {
                throw AppError::rule('WS_STAGE_IS_CLOSING', 'هذه مرحلة إغلاق — أغلق التذكرة مع كتابة الحل', 'This is a closing stage — close the ticket with its resolution instead');
            }
            if ($to->id === $t->stage_id) {
                return $t->id; // it is there already
            }
            $from = $t->stage_id ? TicketStage::find($t->stage_id) : null;
            $t->update(['stage_id' => $to->id, 'updated_by' => $user->username]);
            $this->conversation->event('ticket', $t->id, 'moved', $user, ['from' => $from ? ['ar' => $from->name_ar, 'en' => $from->name_en] : null, 'to' => ['ar' => $to->name_ar, 'en' => $to->name_en]]);
            $this->audit->log($user, ['action' => 'TICKET.MOVE', 'entityType' => 'WsTicket', 'entityId' => $t->id, 'entityNumber' => $t->number, 'field' => 'stage', 'oldValue' => $from?->name_en, 'newValue' => $to->name_en]);

            return $t->id;
        });

        return $this->get($user, $id);
    }

    /** Closes the ticket with what was done about it. */
    public function close(AuthUser $user, string $idOrNumber, string $resolution, ?string $stageId = null): array
    {
        $this->mustWork($user, $this->find($idOrNumber));
        $id = DB::transaction(function () use ($user, $idOrNumber, $resolution, $stageId) {
            $t = $this->locked($idOrNumber);
            Owned::refuse($t, 'ticket');
            $this->mustBeLive($t);
            Ws::transition(self::TRANSITIONS, $t->status, 'closed', 'WS_TICKET_TRANSITION');
            $to = $stageId ? $this->stage($stageId, $t->team_id) : ($this->firstStage($t->team_id, true)
                ?? throw AppError::rule('WS_NO_CLOSING_STAGE', 'لا مرحلة إغلاق معرَّفة لهذا الفريق — يضيفها مدير الدعم من الإعدادات', 'This team has no closing stage — the help-desk manager adds one in the settings'));
            if (! $to->is_closing) {
                throw AppError::rule('WS_STAGE_NOT_CLOSING', 'المرحلة المختارة ليست مرحلة إغلاق', 'The chosen stage does not close a ticket');
            }
            $t->update(['status' => 'closed', 'stage_id' => $to->id, 'closed_at' => now(), 'closed_by' => $user->username, 'resolution' => trim($resolution), 'updated_by' => $user->username]);
            $this->conversation->event('ticket', $t->id, 'closed', $user, ['to' => ['ar' => $to->name_ar, 'en' => $to->name_en]], $resolution);
            $this->audit->status($user, 'WsTicket', $t->id, $t->number, 'open', 'closed', mb_substr(trim($resolution), 0, 500));
            $this->notify->activity($user, 'WsTicket', $t->id, $t->number, "أُغلقت التذكرة {$t->number} — {$t->subject}", "Ticket {$t->number} closed — {$t->subject}");
            People::tell($t->requester_user_id, $user, 'WsTicket', $t->id, $t->number, "أُغلقت تذكرتك {$t->number} — {$t->subject}", "Your ticket {$t->number} was closed — {$t->subject}");

            return $t->id;
        });

        return $this->get($user, $id);
    }

    /** Opens a closed ticket again: by whoever works it, or by its requester when the answer did not settle it. */
    public function reopen(AuthUser $user, string $idOrNumber, ?string $stageId = null, ?string $note = null): array
    {
        $t = $this->find($idOrNumber);
        $access = new TicketAccess($user);
        $this->mustSee($access, $t);
        if (! $access->works($t) && ! $access->requested($t)) {
            Ws::deny($user, 'WS_TICKET_FORBIDDEN', 'يعيد فتح التذكرة صاحب الطلب أو أحد أعضاء فريقها', 'A ticket is reopened by its requester or by a member of its team');
        }
        $id = DB::transaction(function () use ($user, $idOrNumber, $stageId, $note) {
            $t = $this->locked($idOrNumber);
            Owned::refuse($t, 'ticket');
            $this->mustBeLive($t);
            Ws::transition(self::TRANSITIONS, $t->status, 'open', 'WS_TICKET_TRANSITION');
            $to = $stageId ? $this->stage($stageId, $t->team_id) : ($this->firstStage($t->team_id, false)
                ?? throw AppError::rule('WS_NO_OPEN_STAGE', 'لا مرحلة مفتوحة معرَّفة لهذا الفريق', 'This team has no open stage'));
            if ($to->is_closing) {
                throw AppError::rule('WS_STAGE_IS_CLOSING', 'التذكرة المعاد فتحها تدخل مرحلة مفتوحة', 'A reopened ticket enters an open stage');
            }
            $t->update(['status' => 'open', 'stage_id' => $to->id, 'closed_at' => null, 'closed_by' => null, 'updated_by' => $user->username]);
            $this->conversation->event('ticket', $t->id, 'reopened', $user, ['to' => ['ar' => $to->name_ar, 'en' => $to->name_en], 'reason' => Ws::text($note, 1000)]);
            $this->audit->status($user, 'WsTicket', $t->id, $t->number, 'closed', 'open', Ws::text($note, 500));
            People::tell($t->assignee_id, $user, 'WsTicket', $t->id, $t->number, "أُعيد فتح التذكرة {$t->number} — {$t->subject}", "Ticket {$t->number} was reopened — {$t->subject}");

            return $t->id;
        });

        return $this->get($user, $id);
    }

    /** Puts a ticket away (a duplicate, a mistake). It stays on record and can be brought back. */
    public function archive(AuthUser $user, string $idOrNumber, ?string $reason = null): array
    {
        return $this->shelve($user, $idOrNumber, true, $reason);
    }

    public function restore(AuthUser $user, string $idOrNumber): array
    {
        return $this->shelve($user, $idOrNumber, false, null);
    }

    private function shelve(AuthUser $user, string $idOrNumber, bool $away, ?string $reason): array
    {
        $this->mustWork($user, $this->find($idOrNumber));
        $id = DB::transaction(function () use ($user, $idOrNumber, $away, $reason) {
            $t = $this->locked($idOrNumber);
            Owned::refuse($t, 'ticket');
            if ($t->archived === $away) {
                return $t->id;
            }
            $reason = Ws::text($reason, 1000);
            $t->update(['archived' => $away, 'archived_at' => $away ? now() : null, 'archived_by' => $away ? $user->username : null, 'archive_reason' => $away ? $reason : null, 'updated_by' => $user->username]);
            $this->conversation->event('ticket', $t->id, $away ? 'archived' : 'restored', $user, ['reason' => $reason]);
            $this->audit->log($user, ['action' => $away ? 'TICKET.ARCHIVE' : 'TICKET.RESTORE', 'entityType' => 'WsTicket', 'entityId' => $t->id, 'entityNumber' => $t->number, 'newValue' => array_filter(['reason' => $reason])]);

            return $t->id;
        });

        return $this->get($user, $id);
    }

    /**
     * A reply (what was answered to the requester — or what he wrote back) or an internal note, with files.
     * Allowed on a ticket Odoo owns too: what is written here is OPS's own.
     *
     * @param  array<int, array>|null  $uploads
     */
    public function message(AuthUser $user, string $idOrNumber, string $kind, ?string $body, ?array $uploads): array
    {
        $t = $this->find($idOrNumber);
        $access = new TicketAccess($user);
        $this->mustSee($access, $t);
        $works = $access->works($t);
        if (! $works && ! ($kind === 'reply' && $access->requested($t))) {
            Ws::deny($user, 'WS_TICKET_FORBIDDEN', $kind === 'note' ? 'الملاحظات الداخلية يكتبها أعضاء فريق التذكرة' : 'يرد على التذكرة صاحب الطلب أو أحد أعضاء فريقها',
                $kind === 'note' ? 'Internal notes are written by the members of the ticket\'s team' : 'A ticket is answered by its requester or by a member of its team');
        }
        $files = $this->files->decode($uploads);
        if (Ws::text($body) === null && ! $files) {
            throw AppError::validation('WS_MESSAGE_EMPTY', 'اكتب نصًّا أو أرفق ملفًّا', 'Write something or attach a file', [['path' => 'body', 'message' => 'الرسالة فارغة']]);
        }
        $id = DB::transaction(function () use ($user, $idOrNumber, $kind, $body, $files, $works, $access) {
            $t = $this->locked($idOrNumber);
            $this->mustBeLive($t);
            $message = $this->conversation->post($user, 'ticket', $t->id, $kind, $body, $files);
            $asked = $access->requested($t);
            $changes = ['message_count' => $t->message_count + 1, 'last_message_at' => $message->at];
            // the first answer of somebody who is not the requester stops the clock of the first-response target
            if ($kind === 'reply' && $works && ! $asked && $t->first_response_at === null) {
                $changes['first_response_at'] = $message->at;
            }
            $t->timestamps = false; // a message is not an edit of the ticket
            $t->update($changes);
            $this->audit->log($user, ['action' => $kind === 'note' ? 'TICKET.NOTE' : 'TICKET.REPLY', 'entityType' => 'WsTicket', 'entityId' => $t->id, 'entityNumber' => $t->number,
                'newValue' => ['messageId' => $message->id, 'files' => count($files)]]);
            if ($kind === 'reply' && ! $asked) {
                People::tell($t->requester_user_id, $user, 'WsTicket', $t->id, $t->number, "رد جديد على تذكرتك {$t->number} — {$t->subject}", "A new reply on your ticket {$t->number} — {$t->subject}");
            } else {
                People::tell($t->assignee_id, $user, 'WsTicket', $t->id, $t->number,
                    $kind === 'note' ? "ملاحظة داخلية على التذكرة {$t->number} — {$t->subject}" : "رد من صاحب الطلب على التذكرة {$t->number} — {$t->subject}",
                    $kind === 'note' ? "An internal note on ticket {$t->number} — {$t->subject}" : "The requester answered on ticket {$t->number} — {$t->subject}");
            }

            return $t->id;
        });

        return $this->get($user, $id);
    }

    // ───────────── helpers ─────────────

    public function find(string $idOrNumber): Ticket
    {
        return Ticket::where('id', $idOrNumber)->orWhere('number', $idOrNumber)->first()
            ?? throw AppError::notFound('WS_TICKET_NOT_FOUND', 'التذكرة غير موجودة', 'Ticket not found');
    }

    private function locked(string $idOrNumber): Ticket
    {
        return Ticket::where(fn (Builder $w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->lockForUpdate()->first()
            ?? throw AppError::notFound('WS_TICKET_NOT_FOUND', 'التذكرة غير موجودة', 'Ticket not found');
    }

    private function mustSee(TicketAccess $access, Ticket $t): void
    {
        if (! $access->sees($t)) {
            Ws::deny($access->user, 'WS_TICKET_FORBIDDEN', 'هذه التذكرة لفريق لا تنتمي إليه', 'This ticket belongs to a team you are not a member of');
        }
    }

    private function mustWork(AuthUser $user, Ticket $t): void
    {
        $access = new TicketAccess($user);
        $this->mustSee($access, $t);
        if (! $access->works($t)) {
            Ws::deny($user, 'WS_TICKET_FORBIDDEN', 'يعمل على التذكرة أعضاء فريقها أو المسؤول عنها أو مدير الدعم', 'A ticket is worked by the members of its team, its assignee or the help-desk manager');
        }
    }

    private function mustBeLive(Ticket $t): void
    {
        if ($t->archived) {
            throw AppError::rule('WS_ARCHIVED', 'التذكرة مؤرشفة — استعدها أولًا', 'The ticket is archived — restore it first');
        }
    }

    private function team(string $id): TicketTeam
    {
        return TicketTeam::find($id) ?? throw AppError::validation('WS_TEAM_UNKNOWN', 'الفريق المختار غير موجود', 'The chosen team does not exist', [['path' => 'teamId', 'message' => 'فريق غير موجود']]);
    }

    private function type(string $id): TicketType
    {
        return TicketType::find($id) ?? throw AppError::validation('WS_TYPE_UNKNOWN', 'نوع التذكرة المختار غير موجود', 'The chosen type does not exist', [['path' => 'typeId', 'message' => 'نوع غير موجود']]);
    }

    /** An active stage that is for this team. */
    private function stage(string $id, string $teamId): TicketStage
    {
        $stage = TicketStage::with('teams:id')->where('id', $id)->where('active', true)->first()
            ?? throw AppError::notFound('WS_STAGE_NOT_FOUND', 'المرحلة غير موجودة أو متوقفة', 'The stage does not exist or is not active');
        if (! $stage->fits($teamId)) {
            throw AppError::rule('WS_STAGE_NOT_OF_TEAM', 'هذه المرحلة ليست من مراحل فريق التذكرة', 'This stage is not one of the ticket\'s team');
        }

        return $stage;
    }

    /** The first active stage of a team's path that is open — or, with `$closing`, that closes. */
    private function firstStage(string $teamId, bool $closing): ?TicketStage
    {
        return TicketStage::with('teams:id')->where('active', true)->where('is_closing', $closing)->ordered()->get()->first(fn (TicketStage $s) => $s->fits($teamId));
    }

    /**
     * Who asks, as columns. In an edit (`$t` given) the kind may stay and only what was sent changes.
     *
     * @return array<string, mixed>
     */
    private function requester(array $dto, AuthUser $user, ?Ticket $t): array
    {
        $kind = (string) ($dto['requesterKind'] ?? $t?->requester_kind ?? 'staff');
        $pick = fn (string $key, ?string $current, int $max) => array_key_exists($key, $dto) ? Ws::text($dto[$key], $max) : $current;
        $contact = ['requester_email' => $pick('requesterEmail', $t?->requester_email, 190), 'requester_phone' => $pick('requesterPhone', $t?->requester_phone, 40)];
        if ($kind === 'customer') {
            $customerId = $dto['customerId'] ?? $t?->customer_id;
            $customer = $customerId ? Customer::where('id', $customerId)->orWhere('code', $customerId)->first() : null;
            $customer ?? throw AppError::validation('WS_CUSTOMER_UNKNOWN', 'اختر العميل صاحب الطلب', 'Pick the customer who asks', [['path' => 'customerId', 'message' => 'العميل غير موجود']]);

            return ['requester_kind' => 'customer', 'customer_id' => $customer->id, 'requester_user_id' => null, 'requester_name' => $pick('requesterName', $t?->requester_name, 190)] + $contact;
        }
        if ($kind === 'other') {
            $name = $pick('requesterName', $t?->requester_name, 190)
                ?? throw AppError::validation('INVALID_INPUT', 'اكتب اسم صاحب الطلب', 'Type the name of the person who asks', [['path' => 'requesterName', 'message' => 'الاسم مطلوب']]);

            return ['requester_kind' => 'other', 'customer_id' => null, 'requester_user_id' => null, 'requester_name' => $name] + $contact;
        }
        $staffId = ! empty($dto['requesterUserId']) ? (string) People::assignable((string) $dto['requesterUserId'], 'requesterUserId')->id
            : ($t?->requester_kind === 'staff' && $t->requester_user_id ? (string) $t->requester_user_id : $user->id);

        return ['requester_kind' => 'staff', 'customer_id' => null, 'requester_user_id' => $staffId, 'requester_name' => null, 'requester_email' => null, 'requester_phone' => null];
    }

    /** `$hours` after a moment, or null when the team has no such target. */
    private static function after(?\DateTimeInterface $from, ?float $hours): ?Carbon
    {
        return $from !== null && $hours !== null && $hours > 0 ? Carbon::parse($from)->addSeconds((int) round($hours * 3600)) : null;
    }

    /** Columns that changed → what people call them, for the line of the thread. @return array<int, array{ar:string, en:string}> */
    private static function fieldNames(array $columns): array
    {
        $names = [
            'subject' => ['العنوان', 'Subject'], 'description' => ['الوصف', 'Description'], 'priority' => ['الأولوية', 'Priority'], 'type_id' => ['النوع', 'Type'], 'team_id' => ['الفريق', 'Team'],
            'stage_id' => ['المرحلة', 'Stage'], 'due_at' => ['تاريخ الاستحقاق', 'Due date'], 'ref_type' => ['السجل المرتبط', 'Linked record'], 'ref_number' => ['السجل المرتبط', 'Linked record'],
            'requester_kind' => ['صاحب الطلب', 'Requester'], 'customer_id' => ['صاحب الطلب', 'Requester'], 'requester_user_id' => ['صاحب الطلب', 'Requester'], 'requester_name' => ['صاحب الطلب', 'Requester'],
            'requester_email' => ['بريد صاحب الطلب', 'Requester e-mail'], 'requester_phone' => ['هاتف صاحب الطلب', 'Requester phone'],
        ];
        $out = [];
        foreach ($columns as $column) {
            if (isset($names[$column])) {
                $out[$names[$column][1]] = ['ar' => $names[$column][0], 'en' => $names[$column][1]];
            }
        }

        return array_values($out);
    }
}
