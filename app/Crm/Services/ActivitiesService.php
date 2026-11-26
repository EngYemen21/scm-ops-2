<?php

namespace App\Crm\Services;

use App\Crm\Models\CrmActivity;
use App\Crm\Models\CrmLead;
use App\Crm\Support\People;
use App\Crm\Support\Shape;
use App\Crm\Support\Timeline;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Support\Sql;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * To-dos on leads: a type, a due date and an owner. One is open until it is done (with a note of what happened) or
 * cancelled (with the reason); it is never removed, and a closed one is not reopened — the next step is a new to-do.
 * An open to-do whose day has passed is overdue. To-dos are OPS's own on every lead, also on one that Odoo owns.
 */
class ActivitiesService
{
    /** open (default) · overdue · today · upcoming · done · cancelled · all */
    public const STATES = ['open', 'overdue', 'today', 'upcoming', 'done', 'cancelled', 'all'];

    public function __construct(private readonly AuditService $audit, private readonly ActivityBook $book) {}

    /**
     * The to-do list across leads.
     *
     * @param  array{state?:?string, owner?:?string, type?:?string, lead?:?string}  $f  owner: `me` (default) · a user id · `all`
     */
    public function list(AuthUser $user, Paging $page, array $f): array
    {
        $today = Carbon::today();
        $query = $this->scoped($user, $f, $page->q)->with(['user:id,username,name_ar,name_en', 'lead.stage']);
        $this->state($query, $f['state'] ?? 'open', $today);
        $closed = in_array($f['state'] ?? 'open', ['done', 'cancelled'], true);
        if ($page->sort === 'createdAt' || $page->sort === 'dueDate') {
            $query->orderBy($page->sort === 'createdAt' ? 'created_at' : 'due_date', $page->order)->orderBy('id', $page->order);
        } elseif ($closed) {
            $query->orderByDesc('updated_at')->orderByDesc('id'); // the latest closed first
        } else {
            $query->orderBy('due_date')->orderBy('id'); // what is due first
        }
        $counts = [];
        foreach (['overdue', 'today', 'upcoming'] as $state) {
            $counts[$state] = $this->state($this->scoped($user, $f, $page->q), $state, $today)->count();
        }

        return $page->paginate($query, fn (CrmActivity $a) => Shape::activity($a, $today)) + ['counts' => $counts + ['open' => array_sum($counts)]];
    }

    /** @param  array{type:string, summary?:?string, dueDate:string, userId?:?string, note?:?string}  $dto */
    public function create(AuthUser $user, string $leadIdOrNumber, array $dto): array
    {
        return DB::transaction(function () use ($user, $leadIdOrNumber, $dto) {
            $lead = $this->lead($leadIdOrNumber);
            $owner = ! empty($dto['userId']) ? People::assignable((string) $dto['userId'], 'userId') : People::assignable($user->id, 'userId');
            $a = CrmActivity::create([
                'lead_id' => $lead->id, 'type' => $dto['type'], 'summary' => $this->summary($dto['type'], $dto['summary'] ?? null), 'note' => trim((string) ($dto['note'] ?? '')) ?: null,
                'due_date' => substr((string) $dto['dueDate'], 0, 10), 'user_id' => $owner->id, 'status' => 'open', 'created_by' => $user->username,
            ]);
            $this->book->refreshNext($lead->id);
            $this->audit->log($user, ['action' => 'LEAD.ACTIVITY.CREATE', 'entityType' => 'CrmLead', 'entityId' => $lead->id, 'entityNumber' => $lead->number,
                'newValue' => ['activity' => $a->id, 'type' => $a->type, 'summary' => $a->summary, 'dueDate' => $a->due_date->toDateString(), 'user' => $owner->username]]);
            People::tell($owner->id, $user, $lead->id, $lead->number, "مهمة لك على الفرصة {$lead->number}: {$a->summary} — {$a->due_date->toDateString()}", "A to-do for you on opportunity {$lead->number}: {$a->summary} — {$a->due_date->toDateString()}");

            return $this->shaped($a);
        });
    }

    /** Re-dates, re-types, re-words or hands over an open to-do. */
    public function update(AuthUser $user, string $id, array $dto): array
    {
        return DB::transaction(function () use ($user, $id, $dto) {
            $a = $this->openOne($id);
            $lead = CrmLead::findOrFail($a->lead_id);
            $data = [];
            if (array_key_exists('type', $dto) && $dto['type']) {
                $data['type'] = $dto['type'];
            }
            if (array_key_exists('summary', $dto)) {
                $data['summary'] = $this->summary($data['type'] ?? $a->type, $dto['summary']);
            }
            if (array_key_exists('note', $dto)) {
                $data['note'] = trim((string) $dto['note']) ?: null;
            }
            if (array_key_exists('dueDate', $dto) && $dto['dueDate']) {
                $data['due_date'] = substr((string) $dto['dueDate'], 0, 10);
            }
            if (array_key_exists('userId', $dto) && $dto['userId']) {
                $data['user_id'] = People::assignable((string) $dto['userId'], 'userId')->id;
            }
            $old = $new = [];
            foreach ($data as $column => $value) {
                $current = $column === 'due_date' ? $a->due_date->toDateString() : $a->getAttribute($column);
                if ((string) ($current ?? '') !== (string) ($value ?? '')) {
                    $old[$column] = $current;
                    $new[$column] = $value;
                }
            }
            if ($new) {
                $a->update($new);
                $this->book->refreshNext($lead->id);
                $this->audit->log($user, ['action' => 'LEAD.ACTIVITY.UPDATE', 'entityType' => 'CrmLead', 'entityId' => $lead->id, 'entityNumber' => $lead->number, 'oldValue' => $old, 'newValue' => $new + ['activity' => $a->id]]);
                if (isset($new['user_id'])) {
                    People::tell($a->user_id, $user, $lead->id, $lead->number, "مهمة لك على الفرصة {$lead->number}: {$a->summary} — {$a->due_date->toDateString()}", "A to-do for you on opportunity {$lead->number}: {$a->summary} — {$a->due_date->toDateString()}");
                }
            }

            return $this->shaped($a);
        });
    }

    /** open → done, with what happened. The note goes on the lead's timeline. */
    public function done(AuthUser $user, string $id, ?string $note = null): array
    {
        return DB::transaction(function () use ($user, $id, $note) {
            $a = $this->openOne($id);
            $lead = CrmLead::findOrFail($a->lead_id);
            $a->update(['status' => 'done', 'done_at' => now(), 'done_by' => $user->username, 'done_note' => trim((string) $note) ?: null]);
            $this->book->refreshNext($lead->id);
            Timeline::add($lead, 'activity', $user, ['note' => $note, 'data' => ['activity' => $a->id, 'type' => $a->type, 'summary' => $a->summary, 'dueDate' => $a->due_date->toDateString()]]);
            $this->audit->log($user, ['action' => 'LEAD.ACTIVITY.DONE', 'entityType' => 'CrmLead', 'entityId' => $lead->id, 'entityNumber' => $lead->number,
                'newValue' => ['activity' => $a->id, 'type' => $a->type, 'summary' => $a->summary, 'note' => trim((string) $note) ?: null]]);

            return $this->shaped($a);
        });
    }

    /** open → cancelled. */
    public function cancel(AuthUser $user, string $id, ?string $reason = null): array
    {
        return DB::transaction(function () use ($user, $id, $reason) {
            $a = $this->openOne($id);
            $lead = CrmLead::findOrFail($a->lead_id);
            $a->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $user->username, 'cancel_reason' => trim((string) $reason) ?: null]);
            $this->book->refreshNext($lead->id);
            $this->audit->log($user, ['action' => 'LEAD.ACTIVITY.CANCEL', 'entityType' => 'CrmLead', 'entityId' => $lead->id, 'entityNumber' => $lead->number,
                'newValue' => ['activity' => $a->id, 'type' => $a->type, 'summary' => $a->summary, 'reason' => trim((string) $reason) ?: null]]);

            return $this->shaped($a);
        });
    }

    // ───────────── pieces ─────────────

    private function scoped(AuthUser $user, array $f, ?string $q): Builder
    {
        $query = CrmActivity::query();
        $owner = (string) (($f['owner'] ?? '') ?: 'me');
        if ($owner !== 'all') {
            $query->where('user_id', $owner === 'me' ? $user->id : $owner);
        }
        if (! empty($f['type'])) {
            $query->where('type', $f['type']);
        }
        if (! empty($f['lead'])) {
            $query->whereIn('lead_id', CrmLead::where('id', $f['lead'])->orWhere('number', $f['lead'])->select('id'));
        }
        if ($q !== null && $q !== '') {
            $like = '%'.addcslashes($q, '\\%_').'%';
            $op = Sql::like();
            $query->where(fn ($w) => $w->where('summary', $op, $like)
                ->orWhereIn('lead_id', CrmLead::where('number', $op, $like)->orWhere('name', $op, $like)->orWhere('company_name', $op, $like)->orWhere('contact_name', $op, $like)->select('id')));
        }

        return $query;
    }

    private function state(Builder $query, string $state, Carbon $today): Builder
    {
        $day = $today->toDateString();

        return match ($state) {
            'overdue' => $query->where('status', 'open')->where('due_date', '<', $day),
            'today' => $query->where('status', 'open')->where('due_date', $day),
            'upcoming' => $query->where('status', 'open')->where('due_date', '>', $day),
            'done' => $query->where('status', 'done'),
            'cancelled' => $query->where('status', 'cancelled'),
            'all' => $query,
            default => $query->where('status', 'open'),
        };
    }

    private function lead(string $idOrNumber): CrmLead
    {
        return CrmLead::where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->lockForUpdate()->first()
            ?? throw AppError::notFound('LEAD_NOT_FOUND', 'الفرصة غير موجودة', 'Opportunity not found');
    }

    /** The to-do, held for the transaction — and open: what is done or cancelled stays as it ended. */
    private function openOne(string $id): CrmActivity
    {
        $a = CrmActivity::whereKey($id)->lockForUpdate()->first() ?? throw AppError::notFound('CRM_ACTIVITY_NOT_FOUND', 'المهمة غير موجودة', 'To-do not found');
        if ($a->status !== 'open') {
            $state = $a->status === 'done' ? 'منجزة' : 'ملغاة';
            throw AppError::rule('CRM_ACTIVITY_STATE', "المهمة {$state} مسبقًا — أضف مهمة جديدة للخطوة التالية", "The to-do is already {$a->status} — add a new one for the next step", ['status' => $a->status]);
        }

        return $a;
    }

    /** What the to-do says: the text given, or the name of its type. */
    private function summary(string $type, ?string $text): string
    {
        $text = trim((string) $text);

        return $text !== '' ? mb_substr($text, 0, 250) : (CrmActivity::TYPES[$type][0] ?? $type);
    }

    private function shaped(CrmActivity $a): array
    {
        return Shape::activity($a->refresh()->load(['user:id,username,name_ar,name_en', 'lead.stage']));
    }
}
