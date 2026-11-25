<?php

namespace App\Workspace\Support;

use App\Workspace\Models\DocTag;
use App\Workspace\Models\Document;
use App\Workspace\Models\Folder;
use App\Workspace\Models\Message;
use App\Workspace\Models\Project;
use App\Workspace\Models\Task;
use App\Workspace\Models\TaskStage;
use App\Workspace\Models\TaskTag;
use App\Workspace\Models\Ticket;
use App\Workspace\Models\TicketStage;
use App\Workspace\Models\TicketTeam;
use App\Workspace\Models\TicketType;
use App\Workspace\Services\FilesService;
use Illuminate\Support\Carbon;

/**
 * The JSON of the workspace (camelCase, the contract the Vue client reads). A day leaves as `YYYY-MM-DD`, a moment as
 * ISO-8601; `owned` says the record is Odoo's right now; a person is an OPS user or a name kept from Odoo (People).
 */
final class Shape
{
    // ───────────── tickets ─────────────

    /** Needs: members.user, defaultAssignee. `$memberOf` = the teams the caller is a member of. */
    public static function team(TicketTeam $t, array $memberOf = []): array
    {
        return [
            'id' => $t->id, 'nameAr' => $t->name_ar, 'nameEn' => $t->name_en, 'description' => $t->description, 'sequence' => $t->sequence, 'active' => $t->active,
            'visibility' => $t->visibility, 'firstResponseHours' => $t->first_response_hours, 'resolutionHours' => $t->resolution_hours,
            'defaultAssignee' => People::shape($t->default_assignee_id, $t->defaultAssignee, null), 'members' => People::ofRows($t->members),
            'isMember' => in_array((string) $t->id, $memberOf, true),
        ] + Owned::origin($t);
    }

    /** Needs: teams. */
    public static function stage(TicketStage $s): array
    {
        return [
            'id' => $s->id, 'nameAr' => $s->name_ar, 'nameEn' => $s->name_en, 'sequence' => $s->sequence, 'isClosing' => $s->is_closing, 'active' => $s->active,
            'teamIds' => $s->teams->pluck('id')->map(fn ($id) => (string) $id)->values()->all(),
        ] + Owned::origin($s);
    }

    public static function type(TicketType $t): array
    {
        return ['id' => $t->id, 'nameAr' => $t->name_ar, 'nameEn' => $t->name_en, 'sequence' => $t->sequence, 'active' => $t->active] + Owned::origin($t);
    }

    /** A ticket as a row of the list. Needs: team, stage, type, customer, requesterUser, assignee. */
    public static function ticket(Ticket $t, ?Carbon $now = null): array
    {
        $now ??= now();
        $live = $t->status === 'open' && ! $t->archived;
        $named = fn ($row) => $row ? ['id' => $row->id, 'nameAr' => $row->name_ar, 'nameEn' => $row->name_en] : null;

        return [
            'id' => $t->id, 'number' => $t->number, 'subject' => $t->subject, 'status' => $t->status, 'archived' => $t->archived, 'priority' => $t->priority,
            'team' => $named($t->team), 'stage' => $t->stage ? $named($t->stage) + ['isClosing' => $t->stage->is_closing] : null, 'type' => $named($t->type),
            'requester' => [
                'kind' => $t->requester_kind, 'name' => $t->requester_name ?? $t->requesterUser?->name_ar ?? $t->customer?->name_ar, 'email' => $t->requester_email, 'phone' => $t->requester_phone,
                'customer' => $t->customer ? ['id' => $t->customer->id, 'code' => $t->customer->code, 'nameAr' => $t->customer->name_ar, 'nameEn' => $t->customer->name_en] : null,
                'user' => People::shape($t->requester_user_id, $t->requesterUser, null),
            ],
            'assignee' => People::shape($t->assignee_id, $t->assignee, $t->assignee_name),
            'dueAt' => Ws::at($t->due_at), 'firstResponseDueAt' => Ws::at($t->first_response_due_at), 'firstResponseAt' => Ws::at($t->first_response_at),
            'overdue' => $live && $t->due_at !== null && $t->due_at->lt($now),
            'responseOverdue' => $live && $t->first_response_at === null && $t->first_response_due_at !== null && $t->first_response_due_at->lt($now),
            'ref' => RecordLinks::shape($t->ref_type, $t->ref_number), 'messageCount' => $t->message_count, 'lastMessageAt' => Ws::at($t->last_message_at),
            'sourceNumber' => $t->source_number, 'createdAt' => Ws::at($t->created_at), 'createdBy' => $t->created_by, 'closedAt' => Ws::at($t->closed_at),
        ] + Owned::origin($t);
    }

    // ───────────── projects and tasks ─────────────

    public static function taskStage(TaskStage $s): array
    {
        return ['id' => $s->id, 'projectId' => $s->project_id, 'nameAr' => $s->name_ar, 'nameEn' => $s->name_en, 'sequence' => $s->sequence, 'isClosing' => $s->is_closing, 'active' => $s->active]
            + Owned::origin($s);
    }

    /**
     * A project as a row of the list. Needs: manager, members.user.
     *
     * @param  array{open:int, done:int, overdue:int, total:int}  $counts
     */
    public static function project(Project $p, array $counts, ProjectAccess $access): array
    {
        return [
            'id' => $p->id, 'number' => $p->number, 'name' => $p->name, 'description' => $p->description, 'status' => $p->status, 'archived' => $p->archived, 'visibility' => $p->visibility,
            'manager' => People::shape($p->manager_id, $p->manager, $p->manager_name), 'members' => People::ofRows($p->members),
            'startDate' => Ws::day($p->start_date), 'endDate' => Ws::day($p->end_date), 'counts' => $counts,
            'isMember' => in_array((string) $p->id, $access->memberProjectIds(), true), 'canWork' => self::workable($p, $access), 'canManage' => $access->configuresProject($p) && ! Owned::bySource($p),
            'createdAt' => Ws::at($p->created_at),
        ] + Owned::origin($p);
    }

    /** Tasks can be added to the project and worked by this caller: it is alive and he is one of its people. */
    public static function workable(Project $p, ProjectAccess $access): bool
    {
        return ! $p->archived && $p->status !== 'done' && $access->worksProject($p);
    }

    public static function tag(TaskTag $t): array
    {
        return ['id' => $t->id, 'name' => $t->name, 'color' => $t->color, 'active' => $t->active] + Owned::origin($t);
    }

    /**
     * A task as a card of the board and a row of the list. Needs: project, stage, assignees.user, tags and the counts
     * `checklist_total` / `checklist_done`.
     */
    public static function task(Task $t, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $live = $t->status === 'open' && ! $t->archived && $t->deadline !== null;

        return [
            'id' => $t->id, 'number' => $t->number, 'title' => $t->title, 'status' => $t->status, 'archived' => $t->archived, 'priority' => $t->priority,
            'deadline' => Ws::day($t->deadline), 'overdue' => $live && $t->deadline->lt($today), 'dueToday' => $live && $t->deadline->isSameDay($today),
            'project' => $t->project ? ['id' => $t->project->id, 'number' => $t->project->number, 'name' => $t->project->name] : null,
            'stage' => $t->stage ? ['id' => $t->stage->id, 'nameAr' => $t->stage->name_ar, 'nameEn' => $t->stage->name_en, 'isClosing' => $t->stage->is_closing] : null,
            'assignees' => People::ofRows($t->assignees),
            'tags' => $t->tags->map(fn (TaskTag $tag) => ['id' => $tag->id, 'name' => $tag->name, 'color' => $tag->color])->values()->all(),
            'checklist' => ['done' => (int) ($t->checklist_done ?? 0), 'total' => (int) ($t->checklist_total ?? 0)], 'commentCount' => $t->comment_count,
            'ref' => RecordLinks::shape($t->ref_type, $t->ref_number), 'createdAt' => Ws::at($t->created_at), 'createdBy' => $t->created_by, 'doneAt' => Ws::at($t->done_at),
        ] + Owned::origin($t);
    }

    // ───────────── documents ─────────────

    public static function folder(Folder $f, FolderAccess $access, int $documents): array
    {
        $parent = $f->parent_id !== null ? $access->folder((string) $f->parent_id) : null;

        return [
            'id' => $f->id, 'parentId' => $f->parent_id, 'name' => $f->name, 'description' => $f->description, 'sequence' => $f->sequence, 'archived' => $f->archived,
            'readScope' => $f->read_scope, 'readRoles' => array_values((array) ($f->read_roles ?? [])), 'writeScope' => $f->write_scope, 'writeRoles' => array_values((array) ($f->write_roles ?? [])),
            'ownRead' => $f->own_read, 'ownWrite' => $f->own_write, 'documentCount' => $documents,
            'canWrite' => ! $f->archived && $access->canWrite($f), 'canManage' => $access->manages(),
            // a folder whose parent the caller may not read is shown at the root of his tree
            'parentHidden' => $f->parent_id !== null && ($parent === null || ! $access->canRead($parent)), 'extra' => $f->extra ?: null,
        ] + Owned::origin($f);
    }

    public static function docTag(DocTag $t): array
    {
        return ['id' => $t->id, 'name' => $t->name, 'group' => $t->group_name, 'color' => $t->color, 'folderId' => $t->folder_id, 'sequence' => $t->sequence, 'active' => $t->active] + Owned::origin($t);
    }

    /**
     * A document as a row of the list. Needs: folder, owner, tags, currentFile.
     *
     * @param  array<string, true>|null  $kept  the Odoo attachments whose content the backup holds
     */
    public static function document(Document $d, FolderAccess $access, ?array $kept = null): array
    {
        return [
            'id' => $d->id, 'number' => $d->number, 'name' => $d->name, 'kind' => $d->kind, 'url' => $d->kind === 'link' ? $d->url : null,
            'folder' => $d->folder ? ['id' => $d->folder->id, 'name' => $d->folder->name] : null, 'owner' => People::shape($d->owner_id, $d->owner, $d->owner_name),
            'tags' => $d->tags->map(fn (DocTag $t) => ['id' => $t->id, 'name' => $t->name, 'color' => $t->color, 'group' => $t->group_name])->values()->all(),
            'mime' => $d->mime, 'size' => (int) $d->size, 'version' => $d->version, 'file' => $d->currentFile ? FilesService::shape($d->currentFile, false, $kept) : null,
            'ref' => RecordLinks::shape($d->ref_type, $d->ref_number), 'archived' => $d->archived,
            'createdAt' => Ws::at($d->created_at), 'updatedAt' => Ws::at($d->updated_at ?? $d->created_at), 'createdBy' => $d->created_by,
            'canWrite' => ! Owned::bySource($d) && $access->canWriteDocument($d),
        ] + Owned::origin($d);
    }

    // ───────────── threads ─────────────

    /** @param  array<int, array>  $files  the shaped files of the message */
    public static function message(Message $m, array $files = []): array
    {
        return [
            'id' => $m->id, 'kind' => $m->kind, 'event' => $m->event, 'body' => $m->body, 'data' => $m->data ?: null,
            'author' => People::shape($m->author_id, $m->author, $m->author_name), 'at' => Ws::at($m->at), 'origin' => $m->source_system, 'files' => $files,
        ];
    }
}
