<?php

namespace App\Workspace;

use App\Integration\Odoo\Models\OdooChange;
use App\Integration\Odoo\Models\OdooModel;
use App\Integration\Odoo\Models\OdooRecord;
use App\Integration\Odoo\Projection\OdooProjection;
use App\Integration\Odoo\Projection\Val;
use App\Integration\Services\ExternalRefs;
use App\Models\BaseModel;
use App\Models\Customer;
use App\Services\Core\AuditService;
use App\Services\Core\NumberingService;
use App\Support\AppError;
use App\Workspace\Models\DocTag;
use App\Workspace\Models\Document;
use App\Workspace\Models\Folder;
use App\Workspace\Models\Message;
use App\Workspace\Models\Project;
use App\Workspace\Models\State;
use App\Workspace\Models\Task;
use App\Workspace\Models\TaskAssignee;
use App\Workspace\Models\TaskStage;
use App\Workspace\Models\TaskTag;
use App\Workspace\Models\Ticket;
use App\Workspace\Models\TicketStage;
use App\Workspace\Models\TicketTeam;
use App\Workspace\Models\TicketTeamMember;
use App\Workspace\Models\TicketType;
use App\Workspace\Models\WsFile;
use App\Workspace\Support\People;
use App\Workspace\Support\Ws;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * From the copy of Odoo's Helpdesk, Project and Documents apps to the OPS workspace. Odoo's records become records of
 * «الدعم والمشاريع والمستندات», marked `source_system = 'odoo'` with the Odoo id in `external_ref`. Idempotent: a
 * second pass changes nothing.
 *
 *   helpdesk.team · helpdesk.stage · helpdesk.ticket.type · helpdesk.ticket   → teams (+ members) · stages · types · tickets
 *   project.tags · project.project · project.task.type · project.task         → task tags · projects · their stages · tasks
 *   documents.folder · documents.tag · documents.document                     → folders · document tags · documents
 *   and, read beside them (never as models of their own): mail.message → the conversation of a ticket / the comments of
 *   a task; ir.attachment → the files of a ticket, a message, a task and the versions of a document.
 *
 * WHAT IS ODOO'S on such a record: everything Odoo holds. WHAT IS OPS'S and is never touched by a pass: the replies,
 * notes and comments people write here and their files; the two targets and the default assignee of a team; the members
 * of a project; WHO READS AND WRITES A FOLDER BY ROLE (Odoo says it with its own groups: a folder restricted there
 * arrives closed to everybody but the documents manager, who names the OPS roles; the group names are kept beside it);
 * and the link of a record to another record of OPS.
 *
 * PEOPLE are linked by identity only: an Odoo user is the OPS user whose login or e-mail is the Odoo user's login or
 * e-mail — one user, or none: then the name Odoo gives is kept. A ticket's customer is the OPS customer its Odoo
 * partner (or that partner's company) is linked to (`int_external_refs`, system `odoo`, entity `customer`).
 *
 * FILES are linked, never copied: a file row carries the Odoo attachment id; its content is the Odoo backup's
 * (`odoo_files`) and is served from there once it has been archived.
 *
 * A STAGE OF A PROJECT. Odoo ties a task stage to several projects; in OPS a stage is a column of ONE project's board:
 * a stage shared by two projects becomes one column in each (`external_ref` = "<stage id>@<project id>"). A personal
 * stage (somebody's own kanban of his private tasks) is not a column of any project and is kept on the task by name.
 *
 * THE CONVERSATION is read beside the tickets and the tasks. A whole pass (plan / apply) reads it once from the copy —
 * only when the copy of Odoo's messages is complete — and remembers how far the change feed had got; after that every
 * run reads from the feed what was written in Odoo since (`ws_state`).
 *
 * A record deleted or archived in Odoo is archived here and keeps its history: nothing is removed. Once the section is
 * handed over (the switch is off) a pass only adds what is missing and leaves every existing record as OPS has it.
 * plan() also runs where the section's tables do not exist yet (it then counts everything as new) — it never writes.
 */
class OdooWorkspaceProjection extends OdooProjection
{
    /** OPS class → [what a pass calls it, the Odoo model, the audit name] */
    private const ENTITY = [
        TicketTeam::class => ['teams', 'helpdesk.team', 'WsTicketTeam'], TicketStage::class => ['stages', 'helpdesk.stage', 'WsTicketStage'],
        TicketType::class => ['types', 'helpdesk.ticket.type', 'WsTicketType'], Ticket::class => ['tickets', 'helpdesk.ticket', 'WsTicket'],
        TaskTag::class => ['taskTags', 'project.tags', 'WsTaskTag'], Project::class => ['projects', 'project.project', 'WsProject'],
        TaskStage::class => ['taskStages', 'project.task.type', 'WsTaskStage'], Task::class => ['tasks', 'project.task', 'WsTask'],
        Folder::class => ['folders', 'documents.folder', 'WsFolder'], DocTag::class => ['docTags', 'documents.tag', 'WsDocTag'],
        Document::class => ['documents', 'documents.document', 'WsDocument'],
    ];

    /** The threads Odoo's messages are read for: Odoo model → [thread type, OPS class]. */
    private const THREADS = ['helpdesk.ticket' => ['ticket', Ticket::class], 'project.task' => ['task', Task::class]];

    /** Where the pass remembers how far the change feed of Odoo's messages was read (`ws_state`). */
    public const CURSOR = 'odoo.messages.cursor';

    /** Arabic for the names Odoo ships in English. The English name stays exactly Odoo's; a name people typed is kept as it is. */
    private const ARABIC = [
        'new' => 'جديدة', 'in progress' => 'قيد المعالجة', 'on hold' => 'معلّقة', 'solved' => 'تم الحل', 'cancelled' => 'ملغاة', 'canceled' => 'ملغاة', 'done' => 'مكتملة',
        'to do' => 'للتنفيذ', 'customer care' => 'خدمة العملاء', 'question' => 'استفسار', 'issue' => 'مشكلة', 'waiting' => 'بانتظار', 'internal' => 'داخلي',
    ];

    /** A whole-mirror pass (plan / apply) reads the OPS rows and the conversation once; the feed works record by record. */
    private bool $whole = false;

    /** Odoo still owns what came from it: changes in Odoo replace the values here. */
    private bool $follows = true;

    /** The section's tables exist (a plan may run before the migration). */
    private bool $installed = true;

    /** begin() ran for the pass in hand. */
    private bool $begun = false;

    /** @var array<class-string, array<string, BaseModel>> whole pass: the OPS rows that came from Odoo, by Odoo reference */
    private array $rows = [];

    /** @var array<class-string, array<string, true>> a plan: the records it would create */
    private array $planned = [];

    /** @var array<string, true> records this pass has applied or is applying ("model:id") */
    private array $done = [];

    /** @var array<int, array{0:?string, 1:?string}> Odoo user id → [OPS user id, name kept] */
    private array $people = [];

    /** @var array{name: array<string, string[]>, email: array<string, string[]>}|null OPS users by what identifies them */
    private ?array $opsUsers = null;

    /** @var array<int, int>|null Odoo partner id → the Odoo user it is the contact of */
    private ?array $userOfPartner = null;

    /** @var array<int, array{parent:?int}> what a pass needs of an Odoo partner */
    private array $partners = [];

    /** @var array<string, bool> OPS customer ids a pass has checked */
    private array $customers = [];

    /** @var array<int, int[]>|null Odoo project id → the ids of its task stages */
    private ?array $projectStages = null;

    /** @var array<string, array<int, array<int, OdooRecord>>>|null Odoo model → record id → its messages (a whole pass) */
    private ?array $messages = null;

    /** @var array<string, array<int, array<int, array>>>|null Odoo model → record id → attachment id → what the copy says of it */
    private ?array $attachments = null;

    /** @var array<int, bool> Odoo message subtype id → is it internal (a note) */
    private array $subtypes = [];

    /** @var array<string, int>|null counts of the conversation step of a feed run (they are not part of the base counts) */
    private ?array $side = null;

    public function __construct(ExternalRefs $refs, private readonly AuditService $audit, private readonly NumberingService $numbering)
    {
        parent::__construct($refs);
    }

    public function key(): string
    {
        return 'workspace';
    }

    /** Parents before children — the order of a whole pass. */
    public function models(): array
    {
        return ['helpdesk.team', 'helpdesk.stage', 'helpdesk.ticket.type', 'helpdesk.ticket', 'project.tags', 'project.project', 'project.task.type', 'project.task',
            'documents.folder', 'documents.tag', 'documents.document'];
    }

    public function plan(): array
    {
        return $this->wholePass(fn () => parent::plan(), false);
    }

    public function apply(): array
    {
        return $this->wholePass(fn () => parent::apply(), true);
    }

    private function wholePass(callable $pass, bool $writes): array
    {
        $this->whole = true;
        $this->begun = false;
        // what the feed holds now was written before this pass read the copy: the next runs start after it
        $feed = (int) OdooChange::max('id');
        try {
            $out = $pass();
            // the conversation was read whole (the copy of the messages is complete): from here on the runs follow the feed
            if ($writes && $this->messagesReady()) {
                State::write(self::CURSOR, (string) $feed);
            }

            return $out;
        } finally {
            $this->whole = false;
            $this->rows = [];
            $this->messages = $this->attachments = null;
        }
    }

    /**
     * What changed in Odoo since the last time — and, after it, what people wrote there on tickets and tasks.
     */
    public function consume(int $max = 500, float $seconds = 8.0): array
    {
        $started = microtime(true);
        $this->whole = false;
        $this->begun = false;
        $out = parent::consume($max, $seconds);
        try {
            $more = $this->newMessages(max(1.0, $seconds - (microtime(true) - $started)));
        } catch (\Throwable $e) {
            report($e); // the conversation waits for the next run; what the feed brought is applied
            $more = ['changes' => 0, 'counts' => []];
        }
        if ($more['changes']) {
            $out['changes'] += $more['changes'];
            foreach ($more['counts'] as $what => $n) {
                $out['counts'][$what] = ($out['counts'][$what] ?? 0) + $n;
            }
        }

        return $out;
    }

    protected function begin(): void
    {
        $this->begun = true;
        $this->follows = self::owns($this->key());
        $this->installed = Schema::hasTable('ws_tickets');
        if (! $this->installed && ! $this->dry) {
            throw AppError::rule('WORKSPACE_NOT_INSTALLED', 'جداول قسم الدعم والمشاريع والمستندات لم تُنشأ بعد — شغّل الترحيل أولًا (php artisan migrate)', 'The tables of the workspace section do not exist yet — run the migration first');
        }
        $this->rows = $this->planned = $this->done = $this->people = $this->partners = $this->customers = $this->subtypes = [];
        $this->opsUsers = $this->userOfPartner = $this->projectStages = $this->messages = $this->attachments = null;
    }

    protected function project(OdooRecord $r): void
    {
        $key = "{$r->model}:{$r->odoo_id}";
        if (isset($this->done[$key])) {
            return; // a record that points at it brought it along in this pass
        }
        $this->done[$key] = true;
        try {
            $work = fn () => match ($r->model) {
                'helpdesk.team' => $this->team($r),
                'helpdesk.stage' => $this->ticketStage($r),
                'helpdesk.ticket.type' => $this->dictionary(TicketType::class, $r, self::names($r) + ['sequence' => Val::int($r->data['sequence'] ?? 0), 'active' => $this->alive($r)]),
                'helpdesk.ticket' => $this->ticket($r),
                'project.tags' => $this->dictionary(TaskTag::class, $r, ['name' => self::label($r, 80), 'color' => self::color($r->data['color'] ?? 0), 'active' => $this->alive($r)]),
                'project.project' => $this->projectRecord($r),
                'project.task.type' => $this->taskStages($r),
                'project.task' => $this->task($r),
                'documents.folder' => $this->folder($r),
                'documents.tag' => $this->docTag($r),
                'documents.document' => $this->document($r),
                default => null,
            };
            $this->dry ? $work() : DB::transaction($work);
        } catch (\Throwable $e) {
            unset($this->done[$key]); // nothing of it was written: it does not count as applied
            throw $e;
        }
    }

    // ───────────── tickets ─────────────

    private function team(OdooRecord $r): void
    {
        $d = (array) $r->data;
        [$team, $outcome] = $this->store(TicketTeam::class, (string) $r->odoo_id, $r, self::names($r) + [
            'description' => Val::plain($d['description'] ?? null, 2000), 'visibility' => ($d['privacy_visibility'] ?? null) === 'invited_internal' ? 'members' : 'all',
            'sequence' => Val::int($d['sequence'] ?? 0), 'active' => $this->alive($r),
        ]);
        if ($outcome === 'skipped' || $outcome === 'kept') {
            return;
        }
        // the members of the team as Odoo lists them (its targets and its default assignee are OPS's own and stay)
        $wanted = [];
        foreach (Val::ids($d['member_ids'] ?? []) as $userId) {
            [$id, $name] = $this->personById($userId);
            $wanted[$id ? "u:{$id}" : "n:{$name}"] = ['user_id' => $id, 'person_name' => $id ? null : $name, 'person_ref' => (string) $userId];
        }
        $this->people(TicketTeamMember::class, 'team_id', $team, $wanted, 'teams');
    }

    private function ticketStage(OdooRecord $r): void
    {
        $d = (array) $r->data;
        [$stage, $outcome] = $this->store(TicketStage::class, (string) $r->odoo_id, $r, self::names($r) + [
            'sequence' => Val::int($d['sequence'] ?? 0), 'is_closing' => Val::bool($d['fold'] ?? false), 'active' => $this->alive($r),
        ]);
        if ($outcome === 'skipped' || $outcome === 'kept') {
            return;
        }
        // the teams it is for (none = every team)
        $teams = array_values(array_filter(array_map(fn (int $id) => $this->ref(TicketTeam::class, $id), Val::ids($d['team_ids'] ?? []))));
        if ($this->dry || ! $stage) {
            return;
        }
        $teams = array_values(array_filter($teams, fn (string $id) => ! str_starts_with($id, 'new:')));
        sort($teams);
        $now = $stage->teams()->pluck('ws_ticket_teams.id')->map(fn ($x) => (string) $x)->sort()->values()->all();
        if ($now !== $teams) {
            $stage->teams()->sync($teams);
            if ($outcome === 'unchanged') {
                $this->recount('stages');
            }
        }
    }

    private function ticket(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $ref = (string) $r->odoo_id;
        $gone = $r->deleted_at !== null;
        $teamId = $this->ref(TicketTeam::class, Val::id($d['team_id'] ?? null));
        $existing = $this->row(Ticket::class, $ref);
        if (! $teamId && ! $existing) {
            $this->tally($gone ? 'tickets.skippedDeleted' : 'tickets.skippedNoTeam', $gone ? null : self::label($r, 80));

            return;
        }
        $stageOdoo = Val::id($d['stage_id'] ?? null);
        $stageId = $this->ref(TicketStage::class, $stageOdoo);
        $closing = $stageOdoo ? Val::bool($this->mirrored('helpdesk.stage', $stageOdoo)?->data['fold'] ?? false) : false;
        $closed = $closing || Val::at($d['close_date'] ?? null) !== null;
        $archived = $gone || $r->active === false;
        [$assigneeId, $assigneeName] = $this->person($d['user_id'] ?? null);
        [$creatorId, $creatorName] = $this->person($d['create_uid'] ?? null);
        $values = $this->requester($d, $creatorId, $creatorName) + [
            'subject' => self::label($r, 250), 'description' => Val::plain($d['description'] ?? null, 20000),
            'stage_id' => $stageId, 'type_id' => $this->ref(TicketType::class, Val::id($d['ticket_type_id'] ?? null)), 'priority' => max(0, min(Ticket::MAX_PRIORITY, Val::int($d['priority'] ?? 0))),
            'status' => $closed ? 'closed' : 'open', 'archived' => $archived, 'archived_by' => $archived ? 'Odoo' : null,
            'archived_at' => $archived ? ($gone ? $r->deleted_at : Val::at($r->write_date)) : null,
            'assignee_id' => $assigneeId, 'assignee_name' => $assigneeName, 'due_at' => Val::at($d['sla_deadline'] ?? null),
            'closed_at' => $closed ? (Val::at($d['close_date'] ?? null) ?? Val::at($d['date_last_stage_update'] ?? null)) : null,
            'source_number' => Val::text($d['ticket_ref'] ?? null, 60),
            'extra' => self::extra($d, ['kanban_state', 'email_cc', 'assign_date', 'assign_hours', 'close_hours', 'first_response_hours', 'properties'], ['sale_order_id', 'company_id']),
            'source_updated_at' => Val::at($r->write_date), 'source_deleted_at' => $gone ? $r->deleted_at : null,
        ] + ($teamId ? ['team_id' => $teamId] : []);
        $before = $existing ? ['status' => $existing->status, 'stage' => $existing->stage_id, 'assignee' => $existing->assignee_id ?: $existing->assignee_name, 'archived' => $existing->archived] : null;
        [$ticket, $outcome] = $this->store(Ticket::class, $ref, $r, $values, fn () => [
            'number' => $this->numbering->next('TKT'), 'created_by' => $creatorName ?? Val::label($d['create_uid'] ?? null), 'created_by_id' => $creatorId, 'created_at' => Val::at($d['create_date'] ?? null) ?? now(),
        ]);
        if ($outcome === 'created' && $ticket) {
            $this->event('ticket', $ticket->id, 'created', $ticket->created_at, $creatorId, $ticket->created_by);
            if ($closed) {
                $this->event('ticket', $ticket->id, 'closed', $ticket->closed_at ?? $ticket->created_at, null, Val::label($d['write_uid'] ?? null));
            }
        } elseif ($outcome === 'updated' && $ticket && $before) {
            // what happened in Odoo is told on the ticket's thread, at the time Odoo gives for it
            $at = $ticket->source_updated_at;
            $by = Val::label($d['write_uid'] ?? null);
            if ($before['status'] !== $ticket->status) {
                $this->event('ticket', $ticket->id, $ticket->status === 'closed' ? 'closed' : 'reopened', $ticket->status === 'closed' ? ($ticket->closed_at ?? $at) : $at, null, $by);
            } elseif ($before['stage'] !== $ticket->stage_id) {
                $this->event('ticket', $ticket->id, 'moved', $at, null, $by, ['to' => $this->stageName(TicketStage::class, $ticket->stage_id)]);
            }
            if ($before['assignee'] !== ($ticket->assignee_id ?: $ticket->assignee_name)) {
                $this->event('ticket', $ticket->id, 'assigned', $at, null, $by, ['to' => $assigneeName ?? $this->userName($assigneeId)]);
            }
            if ($before['archived'] !== $ticket->archived) {
                $this->event('ticket', $ticket->id, $ticket->archived ? 'archived' : 'restored', $gone ? $ticket->source_deleted_at : $at, null, $by, ['reason' => $gone ? 'حُذفت من أودو — محفوظة هنا بسجلها' : null]);
            }
        }
        if ($outcome !== 'skipped' && $outcome !== 'kept') {
            $this->thread('helpdesk.ticket', $r->odoo_id, $ticket);
        }
    }

    /**
     * Who asks, from Odoo's partner: an OPS customer when the partner (or its company) is linked to one, a member of
     * staff when it is the contact of an Odoo user who is an OPS user, otherwise the name Odoo holds. A ticket nobody
     * was named on was raised by whoever entered it.
     *
     * @return array<string, mixed>
     */
    private function requester(array $d, ?string $creatorId, ?string $creatorName): array
    {
        $partnerId = Val::id($d['partner_id'] ?? null);
        $name = Val::text($d['partner_name'] ?? null, 190) ?? self::cut(Val::label($d['partner_id'] ?? null), 190);
        $contact = ['requester_email' => Val::text($d['partner_email'] ?? null, 190), 'requester_phone' => Val::text($d['partner_phone'] ?? null, 40)];
        if ($partnerId && ($customerId = $this->customer($partnerId))) {
            $this->tally('tickets.withCustomer');

            return ['requester_kind' => 'customer', 'customer_id' => $customerId, 'requester_user_id' => null, 'requester_name' => $name] + $contact;
        }
        if ($partnerId && ($userId = $this->userOfPartner($partnerId)) && ($staff = $this->personById($userId))[0]) {
            return ['requester_kind' => 'staff', 'customer_id' => null, 'requester_user_id' => $staff[0], 'requester_name' => null] + $contact;
        }
        if (! $partnerId && $name === null && $contact['requester_email'] === null) {
            return $creatorId
                ? ['requester_kind' => 'staff', 'customer_id' => null, 'requester_user_id' => $creatorId, 'requester_name' => null] + $contact
                : ['requester_kind' => 'other', 'customer_id' => null, 'requester_user_id' => null, 'requester_name' => $creatorName] + $contact;
        }

        return ['requester_kind' => 'other', 'customer_id' => null, 'requester_user_id' => null, 'requester_name' => $name ?? $contact['requester_email']] + $contact;
    }

    // ───────────── projects and tasks ─────────────

    private function projectRecord(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $gone = $r->deleted_at !== null;
        [$managerId, $managerName] = $this->person($d['user_id'] ?? null);
        [$project, $outcome] = $this->store(Project::class, (string) $r->odoo_id, $r, [
            'name' => self::label($r, 250), 'description' => Val::plain($d['description'] ?? null, 20000),
            'status' => match ($d['last_update_status'] ?? null) {
                'on_hold' => 'on_hold', 'done' => 'done', default => 'active'
            },
            'archived' => $gone || $r->active === false, 'archived_by' => $gone || $r->active === false ? 'Odoo' : null,
            'visibility' => ($d['privacy_visibility'] ?? null) === 'followers' ? 'members' : 'all', 'manager_id' => $managerId, 'manager_name' => $managerName,
            'start_date' => Val::date($d['date_start'] ?? null), 'end_date' => Val::date($d['date'] ?? null),
            'extra' => self::extra($d, ['label_tasks', 'last_update_status', 'allow_milestones'], ['documents_folder_id', 'partner_id', 'company_id']),
            'source_updated_at' => Val::at($r->write_date), 'source_deleted_at' => $gone ? $r->deleted_at : null,
        ], fn () => ['number' => $this->numbering->next('PRJ'), 'created_by' => Val::label($d['create_uid'] ?? null), 'created_at' => Val::at($d['create_date'] ?? null) ?? now()]);
        if ($outcome === 'skipped' || $outcome === 'kept') {
            return;
        }
        // the columns of its board: the stages Odoo ties to it
        $this->projectStages = null;
        foreach (Val::ids($d['type_ids'] ?? []) as $typeId) {
            if ($type = $this->mirrored('project.task.type', $typeId)) {
                $this->taskStage($type, $r->odoo_id, $project);
            }
        }
    }

    /** One Odoo task stage → a column in every project it is tied to. */
    private function taskStages(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $projects = array_values(array_unique(array_merge(Val::ids($d['project_ids'] ?? []), array_keys(array_filter($this->projectStages(), fn (array $types) => in_array($r->odoo_id, $types, true))))));
        if (! $projects) {
            // a personal stage is somebody's own kanban of his private tasks: no project has such a column
            $this->tally(Val::id($d['user_id'] ?? null) ? 'taskStages.skippedPersonal' : 'taskStages.skippedNoProject');
        }
        foreach ($projects as $projectId) {
            $this->taskStage($r, $projectId, null);
        }
        // a column of a project the stage is no longer tied to is switched off (the tasks that stand in it keep it)
        if ($this->installed && $this->follows) {
            $keep = array_map(fn (int $p) => "{$r->odoo_id}@{$p}", $projects);
            foreach (TaskStage::where('source_system', self::SYSTEM)->where('external_ref', 'like', "{$r->odoo_id}@%")->where('active', true)->get() as $stale) {
                if (! in_array((string) $stale->external_ref, $keep, true)) {
                    $this->tally('taskStages.archived');
                    if (! $this->dry) {
                        $stale->update(['active' => false]);
                    }
                }
            }
        }
    }

    private function taskStage(OdooRecord $type, int $projectOdooId, ?Project $project): ?string
    {
        $ref = "{$type->odoo_id}@{$projectOdooId}";
        if (isset($this->done["stage:{$ref}"])) {
            return $this->row(TaskStage::class, $ref)?->getKey() ?? (isset($this->planned[TaskStage::class][$ref]) ? "new:{$ref}" : null);
        }
        $projectId = $project?->getKey() ?? $this->ref(Project::class, $projectOdooId);
        if (! $projectId) {
            return null;
        }
        $this->done["stage:{$ref}"] = true;
        $d = (array) $type->data;
        [$stage] = $this->store(TaskStage::class, $ref, $type, self::names($type) + [
            'project_id' => $projectId, 'sequence' => Val::int($d['sequence'] ?? 0), 'is_closing' => Val::bool($d['fold'] ?? false), 'active' => $this->alive($type),
        ]);

        return $stage?->getKey() ?? (isset($this->planned[TaskStage::class][$ref]) ? "new:{$ref}" : null);
    }

    private function task(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $ref = (string) $r->odoo_id;
        $gone = $r->deleted_at !== null;
        $projectOdoo = Val::id($d['project_id'] ?? null);
        $projectId = $this->ref(Project::class, $projectOdoo);
        $stageOdoo = Val::id($d['stage_id'] ?? null);
        $stageRecord = $stageOdoo ? $this->mirrored('project.task.type', $stageOdoo) : null;
        $stageId = $projectOdoo && $projectId && $stageRecord ? $this->taskStage($stageRecord, $projectOdoo, null) : null;
        $status = match (true) {
            in_array($d['state'] ?? null, ['1_done'], true) => 'done',
            in_array($d['state'] ?? null, ['1_canceled'], true) => 'cancelled',
            // an Odoo without task states: a task that stands in a folded stage is finished
            ! array_key_exists('state', $d) && $stageRecord && Val::bool($stageRecord->data['fold'] ?? false) => 'done',
            default => 'open',
        };
        [$creatorId, $creatorName] = $this->person($d['create_uid'] ?? null);
        $archived = $gone || $r->active === false;
        $personal = array_values(array_filter(array_map(fn (int $id) => Val::text($this->mirrored('project.task.type', $id)?->data['name'] ?? null, 120), Val::ids($d['personal_stage_type_ids'] ?? []))));
        $values = [
            'title' => self::label($r, 250), 'description' => Val::plain($d['description'] ?? null, 20000), 'project_id' => $projectId, 'stage_id' => $stageId,
            'priority' => max(0, min(Task::MAX_PRIORITY, Val::int($d['priority'] ?? 0))), 'status' => $status, 'archived' => $archived, 'archived_by' => $archived ? 'Odoo' : null,
            'deadline' => Val::date($d['date_deadline'] ?? null), 'done_at' => $status === 'done' ? (Val::at($d['date_end'] ?? null) ?? Val::at($d['date_last_stage_update'] ?? null)) : null,
            'stage_changed_at' => Val::at($d['date_last_stage_update'] ?? null),
            'extra' => (self::extra($d, ['state', 'allocated_hours', 'date_assign'], ['parent_id', 'milestone_id', 'partner_id']) ?? []) + ($personal ? ['personalStages' => $personal] : []) ?: null,
            'source_updated_at' => Val::at($r->write_date), 'source_deleted_at' => $gone ? $r->deleted_at : null,
        ];
        $existing = $this->row(Task::class, $ref);
        $before = $existing ? ['status' => $existing->status, 'stage' => $existing->stage_id, 'archived' => $existing->archived] : null;
        [$task, $outcome] = $this->store(Task::class, $ref, $r, $values, fn () => [
            'number' => $this->numbering->next('TSK'), 'created_by' => $creatorName ?? Val::label($d['create_uid'] ?? null), 'created_by_id' => $creatorId, 'created_at' => Val::at($d['create_date'] ?? null) ?? now(),
        ]);
        if ($outcome === 'skipped' || $outcome === 'kept') {
            return;
        }
        $wanted = [];
        foreach (Val::ids($d['user_ids'] ?? []) as $userId) {
            [$id, $name] = $this->personById($userId);
            $wanted[$id ? "u:{$id}" : "n:{$name}"] = ['user_id' => $id, 'person_name' => $id ? null : $name, 'person_ref' => (string) $userId];
        }
        $this->people(TaskAssignee::class, 'task_id', $task, $wanted, 'tasks');
        $tags = array_values(array_filter(array_map(fn (int $id) => $this->ref(TaskTag::class, $id), Val::ids($d['tag_ids'] ?? []))));
        if ($task && ! $this->dry) {
            $tags = array_values(array_filter($tags, fn (string $id) => ! str_starts_with($id, 'new:')));
            sort($tags);
            if ($task->tags()->pluck('ws_task_tags.id')->map(fn ($x) => (string) $x)->sort()->values()->all() !== $tags) {
                $task->tags()->sync($tags);
                if ($outcome === 'unchanged') {
                    $this->recount('tasks');
                }
            }
            if ($outcome === 'created') {
                $this->event('task', $task->id, 'created', $task->created_at, $creatorId, $task->created_by);
                if ($status !== 'open') {
                    $this->event('task', $task->id, $status, $task->done_at ?? $task->source_updated_at ?? $task->created_at, null, Val::label($d['write_uid'] ?? null));
                }
            } elseif ($outcome === 'updated' && $before) {
                $by = Val::label($d['write_uid'] ?? null);
                if ($before['status'] !== $task->status) {
                    $this->event('task', $task->id, ['done' => 'done', 'cancelled' => 'cancelled', 'open' => 'reopened'][$task->status], $task->done_at ?? $task->source_updated_at, null, $by);
                } elseif ($before['stage'] !== $task->stage_id) {
                    $this->event('task', $task->id, 'moved', $task->stage_changed_at ?? $task->source_updated_at, null, $by, ['to' => $this->stageName(TaskStage::class, $task->stage_id)]);
                }
                if ($before['archived'] !== $task->archived) {
                    $this->event('task', $task->id, $task->archived ? 'archived' : 'restored', $gone ? $task->source_deleted_at : $task->source_updated_at, null, $by, ['reason' => $gone ? 'حُذفت من أودو — محفوظة هنا بسجلها' : null]);
                }
            }
        }
        $this->thread('project.task', $r->odoo_id, $task);
    }

    // ───────────── documents ─────────────

    private function folder(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $gone = $r->deleted_at !== null;
        $restricted = Val::ids($d['group_ids'] ?? []) || Val::ids($d['read_group_ids'] ?? []);
        $groups = fn (string $field) => array_values(array_filter(array_map(fn (int $id) => Val::text($this->mirrored('res.groups', $id)?->display_name, 190) ?? "#{$id}", Val::ids($d[$field] ?? []))));
        $parent = Val::id($d['parent_folder_id'] ?? null);
        [, $outcome] = $this->store(Folder::class, (string) $r->odoo_id, $r, [
            'parent_id' => $parent && $parent !== $r->odoo_id ? $this->ref(Folder::class, $parent) : null, 'name' => self::label($r, 190), 'description' => Val::plain($d['description'] ?? null, 2000),
            'sequence' => Val::int($d['sequence'] ?? 0), 'own_read' => Val::bool($d['user_specific'] ?? false), 'own_write' => Val::bool($d['user_specific_write'] ?? false),
            'archived' => $gone || $r->active === false, 'archived_by' => $gone || $r->active === false ? 'Odoo' : null,
            // who reads and writes it in Odoo, in Odoo's own words — for whoever names the OPS roles
            'extra' => array_filter(['odooWriteGroups' => $groups('group_ids'), 'odooReadGroups' => $groups('read_group_ids')]) ?: null,
        ], fn () => [
            // Odoo restricts it to some of its groups, which mean nothing here: it arrives closed until the documents manager names the OPS roles
            'read_scope' => $restricted ? 'roles' : 'all', 'write_scope' => $restricted ? 'roles' : 'all', 'read_roles' => null, 'write_roles' => null,
            'created_by' => Val::label($d['create_uid'] ?? null), 'created_at' => Val::at($d['create_date'] ?? null) ?? now(),
        ]);
        if ($outcome === 'created' && $restricted) {
            $this->tally('folders.restricted', self::label($r, 80));
        }
    }

    private function docTag(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $this->dictionary(DocTag::class, $r, [
            'name' => self::label($r, 120), 'group_name' => self::cut(Val::label($d['facet_id'] ?? null), 120), 'folder_id' => $this->ref(Folder::class, Val::id($d['folder_id'] ?? null)),
            'sequence' => Val::int($d['sequence'] ?? 0), 'active' => $this->alive($r),
        ]);
    }

    private function document(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $ref = (string) $r->odoo_id;
        $gone = $r->deleted_at !== null;
        $folderId = $this->ref(Folder::class, Val::id($d['folder_id'] ?? null));
        $existing = $this->row(Document::class, $ref);
        if (! $folderId && ! $existing) {
            $this->tally($gone ? 'documents.skippedDeleted' : 'documents.skippedNoFolder', $gone ? null : self::label($r, 80));

            return;
        }
        $link = ($d['type'] ?? null) === 'url';
        [$ownerId, $ownerName] = $this->person($d['owner_id'] ?? null);
        $archived = $gone || $r->active === false;
        $record = ($d['res_model'] ?? null) && $d['res_model'] !== 'documents.document' ? ['resModel' => Val::text($d['res_model'], 120), 'resId' => Val::id($d['res_id'] ?? null)] : [];
        [$document, $outcome] = $this->store(Document::class, $ref, $r, [
            'name' => self::label($r, 250), 'kind' => $link ? 'link' : 'file', 'url' => $link ? Val::text($d['url'] ?? null, 2000) : null, 'owner_id' => $ownerId, 'owner_name' => $ownerName,
            'archived' => $archived, 'archived_by' => $archived ? 'Odoo' : null,
            'extra' => (self::extra($d, ['handler', 'is_multipage'], ['partner_id']) ?? []) + $record ?: null,
            'source_updated_at' => Val::at($r->write_date), 'source_deleted_at' => $gone ? $r->deleted_at : null,
        ] + ($folderId ? ['folder_id' => $folderId] : []), fn () => [
            'number' => $this->numbering->next('DOC'), 'created_by' => Val::label($d['create_uid'] ?? null), 'created_at' => Val::at($d['create_date'] ?? null) ?? now(),
        ]);
        if ($outcome === 'skipped' || $outcome === 'kept') {
            return;
        }
        // its versions: what Odoo kept of the earlier uploads, then the attachment in force
        $versions = [];
        foreach (array_merge(Val::ids($d['previous_attachment_ids'] ?? []), array_filter([Val::id($d['attachment_id'] ?? null)])) as $attachmentId) {
            $versions[$attachmentId] = true;
        }
        $wanted = [];
        $no = 0;
        foreach (array_keys($versions) as $attachmentId) {
            $a = (array) ($this->mirrored('ir.attachment', $attachmentId)?->data ?? []);
            $wanted[(string) $attachmentId] = ['version_no' => ++$no, 'name' => Val::text($a['name'] ?? null, 255) ?? self::label($r, 250), 'mime' => Val::text($a['mimetype'] ?? null, 150),
                'size' => Val::int($a['file_size'] ?? ($attachmentId === Val::id($d['attachment_id'] ?? null) ? ($d['file_size'] ?? 0) : 0)), 'at' => Val::at($a['create_date'] ?? null), 'by' => Val::label($a['create_uid'] ?? null)];
        }
        $files = $this->files('document', $document, $wanted);
        $tags = array_values(array_filter(array_map(fn (int $id) => $this->ref(DocTag::class, $id), Val::ids($d['tag_ids'] ?? []))));
        if (! $document || $this->dry) {
            return;
        }
        $current = $files[(string) (Val::id($d['attachment_id'] ?? null) ?? '')] ?? null;
        [, $head] = Ws::diff($document, ['current_file_id' => $current?->id, 'version' => $current?->version_no ?? 0, 'mime' => $current?->mime, 'size' => (int) ($current?->size ?? 0)]);
        $tags = array_values(array_filter($tags, fn (string $id) => ! str_starts_with($id, 'new:')));
        sort($tags);
        $tagged = $document->tags()->pluck('ws_doc_tags.id')->map(fn ($x) => (string) $x)->sort()->values()->all() !== $tags;
        if ($head) {
            $document->timestamps = false;
            $document->forceFill($head)->save();
        }
        if ($tagged) {
            $document->tags()->sync($tags);
        }
        if (($head || $tagged) && $outcome === 'unchanged') {
            $this->recount('documents');
        }
    }

    // ───────────── the conversation and the files ─────────────

    /**
     * What people wrote in Odoo on a ticket or a task, and the files attached to it there. A whole pass reads the
     * messages of every thread once; a run of the feed leaves them to newMessages().
     */
    private function thread(string $model, int $odooId, ?BaseModel $record): void
    {
        [$type] = self::THREADS[$model];
        $messages = $this->whole ? ($this->messagesOf($model)[$odooId] ?? []) : null;
        $inMessages = [];
        if ($messages !== null) {
            foreach ($messages as $m) {
                $inMessages += $this->message($m, $type, $record);
            }
        }
        // the files attached to the record itself (those a message carries hang on that message)
        $direct = [];
        foreach ($this->attachmentsOf($model)[$odooId] ?? [] as $attachmentId => $a) {
            if (! isset($inMessages[$attachmentId]) && ($messages !== null || ! $this->onMessage($attachmentId))) {
                $direct[(string) $attachmentId] = $a;
            }
        }
        $this->files($type, $record, $direct);
        if ($messages !== null && $record && ! $this->dry) {
            $this->recountThread($type, $record);
        }
    }

    /**
     * One message of Odoo → a line of the thread. System notifications (a stage changed, a record was created) carry
     * no words of a person: the thread tells those itself. @return array<int, true> the attachments the message carries
     */
    private function message(OdooRecord $m, string $type, ?BaseModel $record): array
    {
        $d = (array) $m->data;
        $body = Val::plain($d['body'] ?? null, 20000);
        $attachments = Val::ids($d['attachment_ids'] ?? []);
        $existing = $this->installed ? Message::where('source_system', self::SYSTEM)->where('external_ref', (string) $m->odoo_id)->first() : null;
        if ($m->deleted_at !== null) {
            if ($existing && $existing->removed_at === null) {
                $this->tally('messages.removed');
                if (! $this->dry) {
                    $existing->forceFill(['removed_at' => $m->deleted_at])->save();
                }
            }

            return [];
        }
        if (($d['message_type'] ?? null) === 'notification' || ($body === null && ! $attachments)) {
            $this->tally('messages.skippedSystem');

            return [];
        }
        $kind = $type === 'task' ? 'comment' : ($this->internal($d) ? 'note' : 'reply');
        $authorPartner = Val::id($d['author_id'] ?? null);
        [$authorId, $authorName] = $authorPartner && ($userId = $this->userOfPartner($authorPartner)) ? $this->personById($userId, Val::label($d['author_id'])) : [null, null];
        $values = ['kind' => $kind, 'body' => $body, 'author_id' => $authorId, 'author_name' => $authorId ? null : ($authorName ?? self::cut(Val::label($d['author_id'] ?? null) ?? Val::text($d['email_from'] ?? null), 190)),
            'at' => Val::at($d['date'] ?? null) ?? Val::at($d['create_date'] ?? null) ?? now(), 'removed_at' => null];
        $row = $existing;
        if (! $existing) {
            $this->tally('messages.created');
            if (! $this->dry && $record) {
                $row = Message::create($values + ['thread_type' => $type, 'thread_id' => $record->getKey(), 'source_system' => self::SYSTEM, 'external_ref' => (string) $m->odoo_id]);
            }
        } elseif (! $this->follows) {
            $this->tally('messages.kept');
        } else {
            [, $changed] = Ws::diff($existing, $values);
            $this->tally($changed ? 'messages.updated' : 'messages.unchanged');
            if ($changed && ! $this->dry) {
                $existing->forceFill(array_intersect_key($values, $changed))->save();
            }
        }
        $carried = [];
        $files = [];
        foreach ($attachments as $attachmentId) {
            $carried[$attachmentId] = true;
            $files[(string) $attachmentId] = self::attachment($attachmentId, (array) ($this->mirrored('ir.attachment', $attachmentId)?->data ?? []));
        }
        $this->files('message', $row, $files);
        // a run of the feed may have met the attachment on the record before it met the message that carries it: the file hangs on the message
        if ($carried && $record && $row && ! $this->dry) {
            foreach (WsFile::where('owner_type', $type)->where('owner_id', $record->getKey())->where('source_system', self::SYSTEM)->whereIn('external_ref', array_map('strval', array_keys($carried)))->whereNull('removed_at')->get() as $loose) {
                $this->tally('files.moved');
                $loose->timestamps = false;
                $loose->forceFill(['removed_at' => now(), 'removed_by' => 'Odoo', 'remove_reason' => 'الملف مرفق برسالة على السجل'])->save();
            }
        }

        return $carried;
    }

    /**
     * The files Odoo holds on one owner (a ticket, a message, a task, a document), linked by attachment id: added,
     * brought in step, and taken off when Odoo no longer lists them. Files added in OPS are not looked at.
     *
     * @param  array<string, array>  $wanted  attachment id → name, mime, size (+ version_no, at, by)
     * @return array<string, WsFile> the rows by attachment id
     */
    private function files(string $ownerType, ?BaseModel $owner, array $wanted): array
    {
        $known = $owner && $this->installed ? WsFile::where('owner_type', $ownerType)->where('owner_id', $owner->getKey())->where('source_system', self::SYSTEM)->get()->keyBy('external_ref') : collect();
        $out = [];
        foreach ($wanted as $attachmentId => $a) {
            $row = $known->get((string) $attachmentId);
            $values = ['name' => $a['name'], 'mime' => $a['mime'], 'size' => $a['size'], 'version_no' => $a['version_no'] ?? null, 'removed_at' => null];
            if (! $row) {
                $this->tally('files.created');
                if (! $this->dry && $owner) {
                    $row = new WsFile;
                    $row->timestamps = false;
                    $row->forceFill($values + ['owner_type' => $ownerType, 'owner_id' => $owner->getKey(), 'storage' => 'odoo', 'source_system' => self::SYSTEM, 'external_ref' => (string) $attachmentId,
                        'created_by' => $a['by'] ?? null, 'created_at' => $a['at'] ?? now()])->save();
                }
            } elseif (! $this->follows) {
                $this->tally('files.kept');
            } else {
                [, $changed] = Ws::diff($row, $values);
                $this->tally($changed ? 'files.updated' : 'files.unchanged');
                if ($changed && ! $this->dry) {
                    $row->timestamps = false;
                    $row->forceFill(array_intersect_key($values, $changed) + (array_key_exists('removed_at', $changed) ? ['removed_by' => null, 'remove_reason' => null] : []))->save();
                }
            }
            if ($row) {
                $out[(string) $attachmentId] = $row;
            }
        }
        if ($this->follows) {
            foreach ($known as $ref => $row) {
                if (! isset($wanted[(string) $ref]) && $row->removed_at === null) {
                    $this->tally('files.removed');
                    if (! $this->dry) {
                        $row->timestamps = false;
                        $row->forceFill(['removed_at' => now(), 'removed_by' => 'Odoo', 'remove_reason' => 'لم يعد المرفق على السجل في أودو'])->save();
                    }
                }
            }
        }

        return $out;
    }

    /** The messages of tickets and tasks in the copy, read once per whole pass — when the copy of Odoo's messages is complete. */
    private function messagesOf(string $model): array
    {
        if ($this->messages === null) {
            $this->messages = [];
            if (! $this->messagesReady()) {
                $this->tally('conversation.waitingForCopy', 'نسخ رسائل أودو لم يكتمل بعد — تُحمَّل المحادثات في مرور لاحق (scm:odoo-project workspace --apply)');
                $this->messages = array_fill_keys(array_keys(self::THREADS), []);

                return $this->messages[$model] ?? [];
            }
            foreach (OdooRecord::where('model', 'mail.message')->whereIn('data->model', array_keys(self::THREADS))->orderBy('odoo_id')->get() as $m) {
                if ($recordId = Val::id($m->data['res_id'] ?? null)) {
                    $this->messages[(string) $m->data['model']][$recordId][] = $m;
                }
            }
        }

        return $this->messages[$model] ?? [];
    }

    private function messagesReady(): bool
    {
        return OdooModel::where('model', 'mail.message')->where('status', 'active')->where('full_state', 'done')->exists();
    }

    /** The attachments of tickets and tasks in the copy (which lists them without their content), read once per pass. */
    private function attachmentsOf(string $model): array
    {
        if ($this->attachments === null) {
            $this->attachments = [];
            foreach (OdooRecord::where('model', 'ir.attachment')->whereIn('data->res_model', array_keys(self::THREADS))->whereNull('deleted_at')->orderBy('odoo_id')->get(['odoo_id', 'data']) as $a) {
                $d = (array) $a->data;
                $recordId = Val::id($d['res_id'] ?? null);
                // a file that fills a field of the record, or a link, is not an attachment people added
                if ($recordId && empty($d['res_field']) && ($d['type'] ?? 'binary') === 'binary') {
                    $this->attachments[(string) $d['res_model']][$recordId][$a->odoo_id] = self::attachment($a->odoo_id, $d);
                }
            }
        }

        return $this->attachments[$model] ?? [];
    }

    /** Is the attachment already a file of a message here? (a run of the feed does not read the messages again) */
    private function onMessage(int $attachmentId): bool
    {
        return WsFile::where('owner_type', 'message')->where('source_system', self::SYSTEM)->where('external_ref', (string) $attachmentId)->exists();
    }

    /** @return array{name:string, mime:?string, size:int, at:?Carbon, by:?string} */
    private static function attachment(int $id, array $d): array
    {
        return ['name' => Val::text($d['name'] ?? null, 255) ?? "attachment-{$id}", 'mime' => Val::text($d['mimetype'] ?? null, 150), 'size' => Val::int($d['file_size'] ?? 0),
            'at' => Val::at($d['create_date'] ?? null), 'by' => Val::label($d['create_uid'] ?? null)];
    }

    /** An internal note (Odoo's "Note" subtype) or a message people outside may read. */
    private function internal(array $d): bool
    {
        if ($subtype = Val::id($d['subtype_id'] ?? null)) {
            return $this->subtypes[$subtype] ??= Val::bool($this->mirrored('mail.message.subtype', $subtype)?->data['internal'] ?? ($d['is_internal'] ?? false));
        }

        return Val::bool($d['is_internal'] ?? false);
    }

    /** The two figures a thread keeps on its record, counted again from its lines. */
    private function recountThread(string $type, BaseModel $record): void
    {
        $kinds = $type === 'ticket' ? ['reply', 'note'] : ['comment'];
        $query = fn () => Message::where('thread_type', $type)->where('thread_id', $record->getKey())->whereIn('kind', $kinds)->whereNull('removed_at');
        $changes = $type === 'ticket' ? ['message_count' => $query()->count(), 'last_message_at' => $query()->max('at')] : ['comment_count' => $query()->count()];
        [, $changed] = Ws::diff($record, $changes);
        if ($changed) {
            $record->timestamps = false;
            $record->forceFill($changed)->save();
        }
    }

    /**
     * What was written in Odoo on tickets and tasks since the last look, from the change feed. It starts only after a
     * whole pass loaded the conversation (and set the cursor), so nothing is half-read.
     *
     * @return array{changes:int, counts:array<string,int>}
     */
    private function newMessages(float $seconds): array
    {
        $none = ['changes' => 0, 'counts' => []];
        if (! Schema::hasTable('ws_state') || ($cursor = State::read(self::CURSOR)) === null || ! $this->messagesReady()) {
            return $none;
        }
        $changes = OdooChange::where('model', 'mail.message')->where('id', '>', (int) $cursor)->orderBy('id')->limit(1000)->get(['id', 'odoo_id']);
        if ($changes->isEmpty()) {
            return $none;
        }
        if (! $this->begun) {
            $this->begin();
        }
        $this->side = [];
        $until = microtime(true) + $seconds;
        $handled = 0;
        $last = (int) $cursor;
        $touched = [];
        try {
            foreach ($changes as $c) {
                if ($handled > 0 && microtime(true) >= $until) {
                    break;
                }
                $last = (int) $c->id;
                $m = OdooRecord::where('model', 'mail.message')->where('odoo_id', $c->odoo_id)->first();
                [$type, $class] = self::THREADS[(string) ($m?->data['model'] ?? '')] ?? [null, null];
                if (! $m || ! $type || ! ($recordId = Val::id($m->data['res_id'] ?? null))) {
                    continue; // a message of another document of Odoo
                }
                $record = $class::where('source_system', self::SYSTEM)->where('external_ref', (string) $recordId)->first();
                if (! $record) {
                    continue; // its ticket / task is not here (yet): the next whole pass brings both
                }
                try {
                    DB::transaction(fn () => $this->message($m, $type, $record));
                    $touched["{$type}:{$record->getKey()}"] = [$type, $record];
                } catch (\Throwable $e) {
                    report($e); // one message that cannot be applied never stops the others
                    $this->tally('messages.failed');
                }
                $handled++;
            }
            foreach ($touched as [$type, $record]) {
                $this->recountThread($type, $record->refresh());
            }
        } finally {
            State::write(self::CURSOR, (string) $last);
            $counts = $this->side;
            $this->side = null;
        }

        return ['changes' => $handled, 'counts' => $counts];
    }

    /** One line of a thread that happened in Odoo (no words of a person: an event). */
    private function event(string $type, string $threadId, string $event, ?DateTimeInterface $at, ?string $authorId, ?string $authorName, array $data = []): void
    {
        Message::create(['thread_type' => $type, 'thread_id' => $threadId, 'kind' => 'event', 'event' => $event, 'data' => array_filter($data, fn ($v) => $v !== null && $v !== '') ?: null,
            'author_id' => $authorId, 'author_name' => $authorId ? null : $authorName, 'source_system' => self::SYSTEM, 'at' => $at ?? now()]);
    }

    // ───────────── people, partners, customers ─────────────

    /** @return array{0:?string, 1:?string} [OPS user id, name kept when there is none] */
    private function person(mixed $many2one): array
    {
        $odooId = Val::id($many2one);

        return $odooId ? $this->personById($odooId, Val::label($many2one)) : [null, null];
    }

    /**
     * The OPS user an Odoo user is — by login or e-mail, and only when exactly one user answers — or the name to keep.
     *
     * @return array{0:?string, 1:?string}
     */
    private function personById(int $odooId, ?string $label = null): array
    {
        if (! isset($this->people[$odooId])) {
            $keys = [];
            $name = $label;
            if ($u = $this->mirrored('res.users', $odooId)) {
                $keys[] = Val::text($u->data['login'] ?? null);
                $keys[] = Val::text($u->data['email'] ?? null);
                // the e-mail and the name of an Odoo user live on its partner
                $partner = $this->mirrored('res.partner', Val::id($u->data['partner_id'] ?? null));
                $keys[] = Val::text($partner?->data['email'] ?? null);
                $name ??= Val::text($u->display_name) ?? Val::text($partner?->data['name'] ?? null) ?? Val::label($u->data['partner_id'] ?? null);
            }
            if ($this->opsUsers === null) {
                $this->opsUsers = ['name' => [], 'email' => []];
                foreach (DB::table('users')->where('username', 'not like', People::SERVICE_PREFIX.'%')->get(['id', 'username', 'email']) as $user) {
                    $this->opsUsers['name'][mb_strtolower((string) $user->username)][] = (string) $user->id;
                    if ($user->email) {
                        $this->opsUsers['email'][mb_strtolower((string) $user->email)][] = (string) $user->id;
                    }
                }
            }
            $found = [];
            foreach (array_filter($keys) as $key) {
                $key = mb_strtolower($key);
                foreach (array_merge($this->opsUsers['email'][$key] ?? [], $this->opsUsers['name'][$key] ?? []) as $id) {
                    $found[$id] = true;
                }
            }
            $id = count($found) === 1 ? (string) array_key_first($found) : null;
            $this->people[$odooId] = [$id, $id ? null : self::cut($name ?? "#{$odooId}", 190)];
            $this->tally($id ? 'people.matched' : 'people.byName');
        }

        return $this->people[$odooId];
    }

    /** The Odoo user a partner is the contact of. */
    private function userOfPartner(int $partnerId): ?int
    {
        if ($this->userOfPartner === null) {
            $this->userOfPartner = [];
            foreach (OdooRecord::where('model', 'res.users')->get(['odoo_id', 'data']) as $u) {
                if ($p = Val::id($u->data['partner_id'] ?? null)) {
                    $this->userOfPartner[$p] ??= $u->odoo_id;
                }
            }
        }

        return $this->userOfPartner[$partnerId] ?? null;
    }

    /** The OPS customer the Odoo partner is linked to; for a contact of a company, the company's. */
    private function customer(int $partnerId): ?string
    {
        $parent = ($this->partners[$partnerId] ??= ['parent' => Val::id($this->mirrored('res.partner', $partnerId)?->data['parent_id'] ?? null)])['parent'];
        $id = $this->linked('customer', $partnerId) ?? ($parent ? $this->linked('customer', $parent) : null);
        if (! $id) {
            return null;
        }
        // a link to a customer that is no longer there names nobody
        $this->customers[$id] ??= Customer::whereKey($id)->exists();

        return $this->customers[$id] ? (string) $id : null;
    }

    private function userName(?string $userId): ?string
    {
        return $userId ? DB::table('users')->where('id', $userId)->value('name_ar') : null;
    }

    /** @return array{ar:string, en:string}|null */
    private function stageName(string $class, ?string $id): ?array
    {
        $s = $id ? $class::find($id) : null;

        return $s ? ['ar' => $s->name_ar, 'en' => $s->name_en] : null;
    }

    // ───────────── the mechanics ─────────────

    /** The OPS row an Odoo record became. */
    private function row(string $class, string $ref): ?BaseModel
    {
        if (! $this->installed) {
            return null;
        }
        if (! $this->whole) {
            return $class::where('source_system', self::SYSTEM)->where('external_ref', $ref)->first();
        }
        $this->rows[$class] ??= $class::where('source_system', self::SYSTEM)->get()->keyBy('external_ref')->all();

        return $this->rows[$class][$ref] ?? null;
    }

    /**
     * The OPS id of the record a child points at. One that the pass has not met yet (a child of the feed that names a
     * brand-new parent, a folder listed before its parent) is made from the copy on the spot. In a plan: the id, or a
     * marker for "will be made".
     */
    private function ref(string $class, ?int $odooId): ?string
    {
        if (! $odooId) {
            return null;
        }
        $ref = (string) $odooId;
        $known = fn () => $this->row($class, $ref)?->getKey() ?? (isset($this->planned[$class][$ref]) ? "new:{$ref}" : null);
        if ($id = $known()) {
            return (string) $id;
        }
        $model = self::ENTITY[$class][1];
        if (isset($this->done["{$model}:{$ref}"]) || ! ($r = $this->mirrored($model, $odooId)) || $r->deleted_at !== null) {
            return null; // already tried in this pass, unknown to the copy, or gone from Odoo
        }
        $this->project($r);
        $id = $known();

        return $id !== null ? (string) $id : null;
    }

    /**
     * Creates the OPS row of an Odoo record or brings it in step; counts what it did (`<entity>.created | updated |
     * archived | unchanged | kept | skippedDeleted`). In a plan it only counts.
     *
     * @param  class-string<BaseModel>  $class
     * @param  array<string, mixed>  $values  the columns Odoo owns, as they should be now
     * @param  (callable(): array<string, mixed>)|null  $onCreate  what a new row needs besides (its number, its dates) — asked for only when a row is made
     * @return array{0:?BaseModel, 1:string} the row (null in a plan that would create it) and the outcome
     */
    private function store(string $class, string $ref, OdooRecord $r, array $values, ?callable $onCreate = null): array
    {
        [$entity, $model, $auditType] = self::ENTITY[$class];
        $existing = $this->row($class, $ref);
        $label = (string) ($values['subject'] ?? $values['title'] ?? $values['name'] ?? $values['name_en'] ?? $r->display_name ?? $ref);
        if (! $existing) {
            if ($r->deleted_at !== null) {
                $this->tally("{$entity}.skippedDeleted");

                return [null, 'skipped'];
            }
            $this->tally("{$entity}.created");
            if ($this->dry) {
                $this->planned[$class][$ref] = true;

                return [null, 'created'];
            }
            $row = new $class;
            $more = $onCreate ? $onCreate() : [];
            $dated = $row->usesTimestamps();
            $row->timestamps = false; // the dates are Odoo's, not the moment of the copy
            $created = $more['created_at'] ?? now();
            unset($more['created_at']);
            $row->forceFill($values + $more + ['source_system' => self::SYSTEM, 'external_ref' => $ref]
                + ($dated && $row->getCreatedAtColumn() ? [$row->getCreatedAtColumn() => $created] : [])
                + ($dated && $row->getUpdatedAtColumn() ? [$row->getUpdatedAtColumn() => $values['source_updated_at'] ?? $created] : []))->save();
            $this->audit->log($this->actor(), ['action' => 'WS.ODOO.CREATE', 'entityType' => $auditType, 'entityId' => $row->getKey(), 'entityNumber' => $row->getAttribute('number') ?? mb_substr($label, 0, 120),
                'newValue' => ['origin' => "from Odoo {$model} #{$ref}"]]);
            if (isset($this->rows[$class])) {
                $this->rows[$class][$ref] = $row;
            }

            return [$row, 'created'];
        }
        if (! $this->follows) {
            $this->tally("{$entity}.kept"); // handed over: the record is OPS's now

            return [$existing, 'kept'];
        }
        [$old, $changed] = Ws::diff($existing, $values);
        if (! $changed) {
            $this->tally("{$entity}.unchanged");

            return [$existing, 'unchanged'];
        }
        $away = ($changed['archived'] ?? null) === true || ($changed['active'] ?? null) === false;
        $this->tally($entity.($away ? '.archived' : '.updated'));
        if (! $this->dry) {
            $existing->timestamps = false;
            $stamp = $existing->usesTimestamps() && $existing->getUpdatedAtColumn() && isset($values['source_updated_at']) ? [$existing->getUpdatedAtColumn() => $values['source_updated_at']] : [];
            $existing->forceFill(array_intersect_key($values, $changed) + $stamp)->save();
            $this->audit->log($this->actor(), ['action' => 'WS.ODOO.UPDATE', 'entityType' => $auditType, 'entityId' => $existing->getKey(), 'entityNumber' => $existing->getAttribute('number') ?? mb_substr($label, 0, 120),
                'oldValue' => $old, 'newValue' => $changed + ['origin' => 'Odoo']]);
        }

        return [$existing, 'updated'];
    }

    /** A record of a small table whose values are all Odoo's. */
    private function dictionary(string $class, OdooRecord $r, array $values): void
    {
        $this->store($class, (string) $r->odoo_id, $r, $values);
    }

    /**
     * Makes a people list of an Odoo record (members of a team, assignees of a task) what Odoo says now.
     *
     * @param  array<string, array{user_id:?string, person_name:?string, person_ref:string}>  $wanted
     */
    private function people(string $class, string $foreignKey, ?BaseModel $owner, array $wanted, string $entity): void
    {
        if (! $owner || $this->dry) {
            return;
        }
        $changed = false;
        foreach ($class::where($foreignKey, $owner->getKey())->get() as $row) {
            $key = $row->user_id ? "u:{$row->user_id}" : "n:{$row->person_name}";
            if (isset($wanted[$key])) {
                unset($wanted[$key]);
            } else {
                $row->delete();
                $changed = true;
            }
        }
        foreach ($wanted as $person) {
            $class::create([$foreignKey => $owner->getKey()] + $person);
            $changed = true;
        }
        if ($changed && $owner->wasRecentlyCreated === false) {
            $this->recount($entity);
        }
    }

    /** A record counted as unchanged turned out to have changed in what hangs on it (its people, its tags, its file): counted as updated instead. */
    private function recount(string $entity): void
    {
        $this->tally("{$entity}.relinked");
    }

    /** One more record of this outcome — into the counts of the pass, or of the conversation step of a feed run. */
    private function tally(string $what, ?string $note = null): void
    {
        if ($this->side !== null) {
            $this->side[$what] = ($this->side[$what] ?? 0) + 1;

            return;
        }
        $this->count($what, $note);
    }

    /** @return array<int, int[]> Odoo project id → the ids of its task stages */
    private function projectStages(): array
    {
        if ($this->projectStages === null) {
            $this->projectStages = [];
            foreach (OdooRecord::where('model', 'project.project')->whereNull('deleted_at')->get(['odoo_id', 'data']) as $p) {
                $this->projectStages[$p->odoo_id] = Val::ids($p->data['type_ids'] ?? []);
            }
        }

        return $this->projectStages;
    }

    // ───────────── helpers ─────────────

    /** @return array{name_ar:string, name_en:string} */
    private static function names(OdooRecord $r): array
    {
        $name = self::label($r, 120);
        $arabic = preg_match('/\p{Arabic}/u', $name) ? $name : (self::ARABIC[mb_strtolower($name)] ?? $name);

        return ['name_ar' => $arabic, 'name_en' => $name];
    }

    /** What the record is called in Odoo. */
    private static function label(OdooRecord $r, int $max): string
    {
        return (string) self::cut(Val::text($r->data['name'] ?? null, 1000) ?? Val::text($r->display_name, 1000) ?? "#{$r->odoo_id}", $max);
    }

    private static function cut(?string $s, int $max): ?string
    {
        return $s === null ? null : mb_substr($s, 0, $max);
    }

    private static function color(mixed $v): int
    {
        return max(0, min(TaskTag::COLORS - 1, Val::int($v)));
    }

    /**
     * What Odoo holds on a record and OPS has no column for — kept as it is, when it holds something.
     *
     * @param  string[]  $plain  fields kept as they are
     * @param  string[]  $links  many2one fields kept by their label
     */
    private static function extra(array $d, array $plain, array $links = []): ?array
    {
        $out = array_filter(array_intersect_key($d, array_flip($plain)), fn ($v) => $v !== false && $v !== null && $v !== '' && $v !== 0 && $v !== 0.0 && $v !== [] && $v !== 'normal' && $v !== 'to_define');
        foreach ($links as $field) {
            if ($label = Val::label($d[$field] ?? null)) {
                $out[$field] = $label;
            }
        }

        return $out ?: null;
    }
}
