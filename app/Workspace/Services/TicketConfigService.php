<?php

namespace App\Workspace\Services;

use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Workspace\Models\Ticket;
use App\Workspace\Models\TicketStage;
use App\Workspace\Models\TicketTeam;
use App\Workspace\Models\TicketTeamMember;
use App\Workspace\Models\TicketType;
use App\Workspace\Support\Owned;
use App\Workspace\Support\People;
use App\Workspace\Support\Shape;
use App\Workspace\Support\TicketAccess;
use App\Workspace\Support\Ws;
use Illuminate\Support\Facades\DB;

/**
 * What the help desk is made of — teams, stages, types — kept by the help-desk manager (`ticket.manage`).
 *
 * Nothing is deleted: a team, a stage or a type is switched off and the tickets that carry it keep it.
 * A row that came from Odoo is Odoo's while the section follows Odoo. On such a TEAM the two targets and the default
 * assignee stay open: Odoo holds no such values, they are OPS's own way of giving a ticket its due date.
 */
class TicketConfigService
{
    /** What OPS may set on a team Odoo owns. */
    private const OWN_TEAM_KEYS = ['firstResponseHours', 'resolutionHours', 'defaultAssigneeId'];

    public function __construct(private readonly AuditService $audit) {}

    /** Everything the forms and the filters of the tickets tab pick from (switched-off rows included, flagged). */
    public function lookups(AuthUser $user): array
    {
        $memberOf = (new TicketAccess($user))->memberTeamIds();
        $assignees = People::ofRows(
            DB::table('ws_tickets as t')->leftJoin('users as u', 'u.id', '=', 't.assignee_id')
                ->where(fn ($w) => $w->whereNotNull('t.assignee_id')->orWhereNotNull('t.assignee_name'))
                ->distinct()->get(['t.assignee_id as user_id', 't.assignee_name as person_name', 'u.username', 'u.name_ar', 'u.name_en'])
                ->map(fn ($r) => (object) ['user_id' => $r->user_id, 'person_name' => $r->user_id ? null : $r->person_name, 'user' => $r->user_id ? $r : null])
                ->unique(fn ($r) => $r->user_id ?? 'name:'.$r->person_name)->all());

        return [
            'follows' => Owned::following(),
            'teams' => TicketTeam::with(['members.user:'.People::COLUMNS, 'defaultAssignee:'.People::COLUMNS])->ordered()->get()->map(fn (TicketTeam $t) => Shape::team($t, $memberOf))->all(),
            'stages' => TicketStage::with('teams:id')->ordered()->get()->map(fn (TicketStage $s) => Shape::stage($s))->all(),
            'types' => TicketType::query()->ordered()->get()->map(fn (TicketType $t) => Shape::type($t))->all(),
            'assignees' => $assignees,
        ];
    }

    // ───────────── teams ─────────────

    public function storeTeam(AuthUser $user, array $dto): array
    {
        $id = DB::transaction(function () use ($user, $dto) {
            $team = TicketTeam::create($this->teamValues($dto, null) + ['active' => true, 'created_by' => $user->username]);
            People::sync(TicketTeamMember::class, 'team_id', $team->id, (array) ($dto['memberKeys'] ?? []), 'memberKeys');
            $this->audit->log($user, ['action' => 'TICKET.TEAM.CREATE', 'entityType' => 'WsTicketTeam', 'entityId' => $team->id, 'entityNumber' => $team->name_en, 'newValue' => $dto]);

            return $team->id;
        });

        return $this->team($user, $id);
    }

    public function updateTeam(AuthUser $user, string $id, array $dto): array
    {
        DB::transaction(function () use ($user, $id, $dto) {
            $team = TicketTeam::where('id', $id)->lockForUpdate()->first() ?? throw AppError::notFound('WS_TEAM_NOT_FOUND', 'الفريق غير موجود', 'Team not found');
            if (array_diff(array_keys($dto), self::OWN_TEAM_KEYS)) {
                Owned::refuse($team, 'team'); // on a team of Odoo only the targets and the default assignee are OPS's
            }
            $values = $this->teamValues($dto, $team);
            if (array_key_exists('active', $dto) && $dto['active'] !== null) {
                $values['active'] = (bool) $dto['active'];
            }
            [$old, $new] = Ws::diff($team, $values);
            if ($new) {
                $team->update($new);
            }
            if (array_key_exists('memberKeys', $dto)) {
                $before = People::names(TicketTeamMember::class, 'team_id', $team->id);
                if (People::sync(TicketTeamMember::class, 'team_id', $team->id, (array) $dto['memberKeys'], 'memberKeys')) {
                    $old['members'] = $before;
                    $new['members'] = People::names(TicketTeamMember::class, 'team_id', $team->id);
                }
            }
            if ($new) {
                $this->audit->log($user, ['action' => 'TICKET.TEAM.UPDATE', 'entityType' => 'WsTicketTeam', 'entityId' => $team->id, 'entityNumber' => $team->name_en, 'oldValue' => $old, 'newValue' => $new]);
            }
        });

        return $this->team($user, $id);
    }

    private function team(AuthUser $user, string $id): array
    {
        return Shape::team(TicketTeam::with(['members.user:'.People::COLUMNS, 'defaultAssignee:'.People::COLUMNS])->findOrFail($id), (new TicketAccess($user))->memberTeamIds());
    }

    /** @return array<string, mixed> the columns a payload sets (in an edit: only what was sent) */
    private function teamValues(array $dto, ?TicketTeam $team): array
    {
        $out = [];
        if (array_key_exists('nameAr', $dto) && Ws::text($dto['nameAr']) !== null) {
            $out['name_ar'] = Ws::text($dto['nameAr'], 120);
        }
        if (array_key_exists('nameEn', $dto) || ! $team) {
            $out['name_en'] = Ws::text($dto['nameEn'] ?? null, 120) ?? $out['name_ar'] ?? $team?->name_en;
        }
        if (array_key_exists('description', $dto)) {
            $out['description'] = Ws::text($dto['description'], 2000);
        }
        if (! empty($dto['visibility'])) {
            $out['visibility'] = (string) $dto['visibility'];
        }
        foreach (['firstResponseHours' => 'first_response_hours', 'resolutionHours' => 'resolution_hours'] as $key => $column) {
            if (array_key_exists($key, $dto)) {
                $out[$column] = $dto[$key] === null || $dto[$key] === '' || (float) $dto[$key] <= 0 ? null : round((float) $dto[$key], 2);
            }
        }
        if (array_key_exists('defaultAssigneeId', $dto)) {
            $out['default_assignee_id'] = ! empty($dto['defaultAssigneeId']) ? (string) People::assignable((string) $dto['defaultAssigneeId'], 'defaultAssigneeId')->id : null;
        }
        if (array_key_exists('sequence', $dto) && $dto['sequence'] !== null) {
            $out['sequence'] = (int) $dto['sequence'];
        }

        return $out;
    }

    // ───────────── stages ─────────────

    public function storeStage(AuthUser $user, array $dto): array
    {
        $id = DB::transaction(function () use ($user, $dto) {
            $nameAr = Ws::text($dto['nameAr'], 120);
            $stage = TicketStage::create([
                'name_ar' => $nameAr, 'name_en' => Ws::text($dto['nameEn'] ?? null, 120) ?? $nameAr, 'is_closing' => (bool) ($dto['isClosing'] ?? false), 'active' => true,
                'sequence' => isset($dto['sequence']) ? (int) $dto['sequence'] : ((int) TicketStage::max('sequence')) + 10, 'created_by' => $user->username,
            ]);
            $stage->teams()->sync($this->teamIds((array) ($dto['teamIds'] ?? [])));
            $this->audit->log($user, ['action' => 'TICKET.STAGE.CREATE', 'entityType' => 'WsTicketStage', 'entityId' => $stage->id, 'entityNumber' => $stage->name_en, 'newValue' => $dto]);

            return $stage->id;
        });

        return Shape::stage(TicketStage::with('teams:id')->findOrFail($id));
    }

    public function updateStage(AuthUser $user, string $id, array $dto): array
    {
        DB::transaction(function () use ($user, $id, $dto) {
            $stage = TicketStage::where('id', $id)->lockForUpdate()->first() ?? throw AppError::notFound('WS_STAGE_NOT_FOUND', 'المرحلة غير موجودة', 'Stage not found');
            Owned::refuse($stage, 'stage');
            $values = self::names($dto, $stage);
            foreach (['isClosing' => 'is_closing', 'active' => 'active'] as $key => $column) {
                if (array_key_exists($key, $dto) && $dto[$key] !== null) {
                    $values[$column] = (bool) $dto[$key];
                }
            }
            [$old, $new] = Ws::diff($stage, $values);
            // tickets stand in it: it cannot change what it means under them, nor leave while open tickets wait in it
            if (array_key_exists('is_closing', $new) && Ticket::where('stage_id', $stage->id)->exists()) {
                throw AppError::rule('WS_STAGE_IN_USE', 'في هذه المرحلة تذاكر — لا يتغيّر كونها مرحلة إغلاق. أضف مرحلة جديدة وانقل التذاكر إليها', 'Tickets stand in this stage — whether it closes cannot change. Add a new stage and move them');
            }
            if (($new['active'] ?? null) === false && Ticket::where('stage_id', $stage->id)->where('status', 'open')->where('archived', false)->exists()) {
                throw AppError::rule('WS_STAGE_IN_USE', 'في هذه المرحلة تذاكر مفتوحة — انقلها إلى مرحلة أخرى قبل إيقافها', 'Open tickets stand in this stage — move them before switching it off');
            }
            if ($new) {
                $stage->update($new);
            }
            if (array_key_exists('teamIds', $dto)) {
                $before = $stage->teams()->pluck('ws_ticket_teams.id')->map(fn ($x) => (string) $x)->sort()->values()->all();
                $after = collect($this->teamIds((array) $dto['teamIds']))->sort()->values()->all();
                if ($before !== $after) {
                    $stage->teams()->sync($after);
                    $old['teams'] = $before;
                    $new['teams'] = $after;
                }
            }
            if ($new) {
                $this->audit->log($user, ['action' => 'TICKET.STAGE.UPDATE', 'entityType' => 'WsTicketStage', 'entityId' => $stage->id, 'entityNumber' => $stage->name_en, 'oldValue' => $old, 'newValue' => $new]);
            }
        });

        return Shape::stage(TicketStage::with('teams:id')->findOrFail($id));
    }

    /** @param  array<int, mixed>  $ids  @return string[] the ids of teams that exist */
    private function teamIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));
        $known = TicketTeam::whereIn('id', $ids)->pluck('id')->map(fn ($x) => (string) $x)->all();
        if (count($known) !== count($ids)) {
            throw AppError::validation('WS_TEAM_UNKNOWN', 'أحد الفرق المختارة غير موجود', 'One of the chosen teams does not exist', [['path' => 'teamIds', 'message' => 'فريق غير موجود']]);
        }

        return $known;
    }

    // ───────────── types ─────────────

    public function storeType(AuthUser $user, array $dto): array
    {
        $nameAr = Ws::text($dto['nameAr'], 120);
        $type = DB::transaction(function () use ($user, $dto, $nameAr) {
            $type = TicketType::create(['name_ar' => $nameAr, 'name_en' => Ws::text($dto['nameEn'] ?? null, 120) ?? $nameAr, 'active' => true,
                'sequence' => isset($dto['sequence']) ? (int) $dto['sequence'] : ((int) TicketType::max('sequence')) + 10, 'created_by' => $user->username]);
            $this->audit->log($user, ['action' => 'TICKET.TYPE.CREATE', 'entityType' => 'WsTicketType', 'entityId' => $type->id, 'entityNumber' => $type->name_en, 'newValue' => $dto]);

            return $type;
        });

        return Shape::type($type->refresh());
    }

    public function updateType(AuthUser $user, string $id, array $dto): array
    {
        $type = DB::transaction(function () use ($user, $id, $dto) {
            $type = TicketType::where('id', $id)->lockForUpdate()->first() ?? throw AppError::notFound('WS_TYPE_NOT_FOUND', 'نوع التذكرة غير موجود', 'Ticket type not found');
            Owned::refuse($type, 'type');
            $values = self::names($dto, $type);
            if (array_key_exists('active', $dto) && $dto['active'] !== null) {
                $values['active'] = (bool) $dto['active'];
            }
            [$old, $new] = Ws::diff($type, $values);
            if ($new) {
                $type->update($new);
                $this->audit->log($user, ['action' => 'TICKET.TYPE.UPDATE', 'entityType' => 'WsTicketType', 'entityId' => $type->id, 'entityNumber' => $type->name_en, 'oldValue' => $old, 'newValue' => $new]);
            }

            return $type;
        });

        return Shape::type($type->refresh());
    }

    /** The two names and the order of a dictionary row, from what an edit sent. @return array<string, mixed> */
    private static function names(array $dto, object $row): array
    {
        $out = [];
        if (array_key_exists('nameAr', $dto) && Ws::text($dto['nameAr']) !== null) {
            $out['name_ar'] = Ws::text($dto['nameAr'], 120);
        }
        if (array_key_exists('nameEn', $dto)) {
            $out['name_en'] = Ws::text($dto['nameEn'], 120) ?? $out['name_ar'] ?? $row->name_ar;
        }
        if (array_key_exists('sequence', $dto) && $dto['sequence'] !== null) {
            $out['sequence'] = (int) $dto['sequence'];
        }

        return $out;
    }
}
