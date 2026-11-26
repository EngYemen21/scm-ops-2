<?php

namespace App\Crm\Support;

use App\Crm\Models\CrmActivity;
use App\Crm\Models\CrmLead;
use App\Crm\Models\CrmLeadEvent;
use App\Crm\Models\CrmLostReason;
use App\Crm\Models\CrmStage;
use App\Crm\Models\CrmTag;
use App\Crm\Models\CrmTeam;
use App\Models\Customer;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * The JSON of the section (camelCase, the contract the Vue client reads). Money leaves as a decimal string like every
 * amount of the API, a day as `YYYY-MM-DD`, a moment as ISO-8601; `owned` says the record is Odoo's right now.
 */
final class Shape
{
    public static function stage(CrmStage $s): array
    {
        return [
            'id' => $s->id, 'nameAr' => $s->name_ar, 'nameEn' => $s->name_en, 'sequence' => $s->sequence, 'isWon' => $s->is_won, 'fold' => $s->fold,
            'probability' => $s->probability, 'requirements' => $s->requirements, 'teamId' => $s->team_id, 'active' => $s->active,
        ] + self::origin($s);
    }

    public static function team(CrmTeam $t): array
    {
        return [
            'id' => $t->id, 'nameAr' => $t->name_ar, 'nameEn' => $t->name_en, 'sequence' => $t->sequence, 'pipeline' => $t->pipeline, 'active' => $t->active,
            'leader' => $t->leader_id || $t->leader_name ? self::person($t->leader_id, $t->relationLoaded('leader') ? $t->leader : null, $t->leader_name) : null,
        ] + self::origin($t);
    }

    public static function reason(CrmLostReason $r): array
    {
        return ['id' => $r->id, 'nameAr' => $r->name_ar, 'nameEn' => $r->name_en, 'active' => $r->active] + self::origin($r);
    }

    public static function tag(CrmTag $t): array
    {
        return ['id' => $t->id, 'name' => $t->name, 'color' => $t->color, 'active' => $t->active] + self::origin($t);
    }

    /** @return array{sourceSystem:?string, externalRef:?string, owned:bool} */
    private static function origin(CrmStage|CrmTeam|CrmLostReason|CrmTag|CrmLead $row): array
    {
        return ['sourceSystem' => $row->source_system, 'externalRef' => $row->external_ref, 'owned' => Owned::bySource($row)];
    }

    /**
     * A salesperson / a leader: an OPS user, or a name kept from Odoo. `key` is what the lists filter by
     * (the user id, or `name:<the name>`).
     */
    public static function person(?string $userId, ?object $user, ?string $name): array
    {
        if ($userId) {
            return ['key' => $userId, 'id' => $userId, 'username' => $user?->username, 'nameAr' => $user?->name_ar ?? $name ?? '—', 'nameEn' => $user?->name_en ?? $name ?? '—', 'external' => false];
        }

        return ['key' => 'name:'.$name, 'id' => null, 'username' => null, 'nameAr' => (string) $name, 'nameEn' => (string) $name, 'external' => true];
    }

    public static function customer(?Customer $c): ?array
    {
        return $c ? ['id' => $c->id, 'code' => $c->code, 'nameAr' => $c->name_ar, 'nameEn' => $c->name_en, 'city' => $c->city, 'vatNo' => $c->vat_no, 'active' => (bool) $c->active] : null;
    }

    /** A lead as a card of the board and a row of the list. Needs: stage, team, owner, customer, lostReason, tags. */
    public static function card(CrmLead $l, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $next = $l->next_activity_date;

        return [
            'id' => $l->id, 'number' => $l->number, 'name' => $l->name, 'kind' => $l->kind, 'status' => $l->status, 'archived' => $l->archived,
            'priority' => $l->priority, 'expectedRevenue' => (string) $l->expected_revenue, 'probability' => (float) $l->probability,
            'stage' => $l->stage ? ['id' => $l->stage->id, 'nameAr' => $l->stage->name_ar, 'nameEn' => $l->stage->name_en, 'isWon' => $l->stage->is_won] : null,
            'team' => $l->team ? ['id' => $l->team->id, 'nameAr' => $l->team->name_ar, 'nameEn' => $l->team->name_en] : null,
            'owner' => $l->owner_id || $l->owner_name ? self::person($l->owner_id, $l->owner, $l->owner_name) : null,
            'contactName' => $l->contact_name, 'companyName' => $l->company_name, 'phone' => $l->phone, 'mobile' => $l->mobile, 'email' => $l->email, 'city' => $l->city,
            'customer' => $l->customer ? ['id' => $l->customer->id, 'code' => $l->customer->code, 'nameAr' => $l->customer->name_ar, 'nameEn' => $l->customer->name_en] : null,
            'tags' => $l->tags->map(fn (CrmTag $t) => ['id' => $t->id, 'name' => $t->name, 'color' => $t->color])->values()->all(),
            'nextActivity' => $next ? ['date' => $next->toDateString(), 'type' => $l->next_activity_type, 'summary' => $l->next_activity_summary, 'overdue' => $next->lt($today), 'today' => $next->isSameDay($today)] : null,
            'lostReason' => $l->lostReason ? ['id' => $l->lostReason->id, 'nameAr' => $l->lostReason->name_ar, 'nameEn' => $l->lostReason->name_en] : null,
            'deadline' => self::day($l->deadline), 'createdAt' => self::at($l->created_at), 'stageChangedAt' => self::at($l->stage_changed_at), 'closedAt' => self::at($l->closed_at),
        ] + self::origin($l);
    }

    /** Everything a lead holds (the card + the rest of the form). */
    public static function lead(CrmLead $l, ?Carbon $today = null): array
    {
        return self::card($l, $today) + [
            'jobTitle' => $l->job_title, 'website' => $l->website, 'street' => $l->street, 'street2' => $l->street2, 'region' => $l->region, 'zip' => $l->zip, 'country' => $l->country,
            'vatNo' => $l->vat_no, 'partnerRef' => $l->partner_ref, 'source' => $l->source, 'medium' => $l->medium, 'campaign' => $l->campaign, 'referredBy' => $l->referred_by,
            'description' => $l->description, 'lostNote' => $l->lost_note, 'openedAt' => self::at($l->opened_at), 'convertedAt' => self::at($l->converted_at), 'convertedBy' => $l->converted_by,
            'extra' => $l->extra ?: null, 'sourceUpdatedAt' => self::at($l->source_updated_at), 'sourceDeletedAt' => self::at($l->source_deleted_at),
            'createdBy' => $l->created_by, 'updatedBy' => $l->updated_by, 'updatedAt' => self::at($l->updated_at),
        ];
    }

    /** A to-do. Needs: user; `lead` (with its stage) when the list is not the lead's own. */
    public static function activity(CrmActivity $a, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $open = $a->status === 'open';
        $out = [
            'id' => $a->id, 'leadId' => $a->lead_id, 'type' => $a->type, 'summary' => $a->summary, 'note' => $a->note, 'dueDate' => self::day($a->due_date), 'status' => $a->status,
            'overdue' => $open && $a->due_date->lt($today), 'today' => $open && $a->due_date->isSameDay($today),
            'user' => People::shape($a->user), 'doneAt' => self::at($a->done_at), 'doneBy' => $a->done_by, 'doneNote' => $a->done_note,
            'cancelledAt' => self::at($a->cancelled_at), 'cancelledBy' => $a->cancelled_by, 'cancelReason' => $a->cancel_reason,
            'createdBy' => $a->created_by, 'createdAt' => self::at($a->created_at),
        ];
        if ($a->relationLoaded('lead') && $a->lead) {
            $l = $a->lead;
            $out['lead'] = [
                'id' => $l->id, 'number' => $l->number, 'name' => $l->name, 'companyName' => $l->company_name, 'contactName' => $l->contact_name, 'phone' => $l->phone ?: $l->mobile, 'status' => $l->status,
                'stage' => $l->relationLoaded('stage') && $l->stage ? ['id' => $l->stage->id, 'nameAr' => $l->stage->name_ar, 'nameEn' => $l->stage->name_en] : null,
            ];
        }

        return $out;
    }

    /** @param  array<string, CrmStage>  $stages  by id, to name the two ends of a move */
    public static function event(CrmLeadEvent $e, array $stages): array
    {
        $stage = fn (?string $id) => $id && isset($stages[$id]) ? ['id' => $id, 'nameAr' => $stages[$id]->name_ar, 'nameEn' => $stages[$id]->name_en] : null;

        return [
            'id' => $e->id, 'kind' => $e->kind, 'at' => self::at($e->at), 'by' => $e->username, 'origin' => $e->origin, 'note' => $e->note, 'data' => $e->data ?: null,
            'fromStage' => $stage($e->from_stage_id), 'toStage' => $stage($e->to_stage_id),
        ];
    }

    public static function day(?DateTimeInterface $d): ?string
    {
        return $d?->format('Y-m-d');
    }

    public static function at(?DateTimeInterface $d): ?string
    {
        return $d instanceof Carbon ? $d->toJSON() : $d?->format(DATE_ATOM);
    }
}
