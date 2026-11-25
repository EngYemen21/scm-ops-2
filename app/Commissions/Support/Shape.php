<?php

namespace App\Commissions\Support;

use App\Commissions\Models\CommissionAssignment;
use App\Commissions\Models\CommissionLine;
use App\Commissions\Models\CommissionPlan;
use App\Commissions\Models\CommissionPlanRule;
use App\Commissions\Models\CommissionStatement;
use DateTimeInterface;

/**
 * The JSON of the section (camelCase, the contract the Vue client reads). Money leaves as a decimal string like every
 * amount of the API, a day as `YYYY-MM-DD`, a moment as ISO-8601; `owned` says the record is Odoo's right now.
 */
final class Shape
{
    /**
     * @param  array<string, object>  $users  users by id (People::byId)
     * @param  array<string, array{id:string, code:string, nameAr:string, nameEn:string}>  $scopeNames  categories / customers of the rules by id
     */
    public static function plan(CommissionPlan $p, array $users = [], array $scopeNames = []): array
    {
        return [
            'id' => $p->id, 'code' => $p->code, 'nameAr' => $p->name_ar, 'nameEn' => $p->name_en, 'earnerType' => $p->earner_type,
            'beneficiary' => People::shape($p->beneficiary_id, $users[$p->beneficiary_id] ?? null, $p->beneficiary_name),
            'everyone' => (bool) $p->everyone, 'earnOn' => $p->earn_on, 'base' => $p->base_kind, 'netOfReturns' => (bool) $p->net_of_returns,
            'validFrom' => self::day($p->valid_from), 'validTo' => self::day($p->valid_to), 'status' => $p->status, 'notes' => $p->notes,
            'members' => $p->relationLoaded('members') ? $p->members->map(fn ($m) => People::shape($m->user_id, $users[$m->user_id] ?? null, $m->person_name))->filter()->values()->all() : null,
            'rules' => $p->relationLoaded('rules') ? $p->rules->map(fn (CommissionPlanRule $r) => self::rule($r, $scopeNames))->values()->all() : null,
            'createdBy' => $p->created_by, 'createdAt' => self::at($p->created_at), 'activatedAt' => self::at($p->activated_at), 'activatedBy' => $p->activated_by,
            'archivedAt' => self::at($p->archived_at), 'archivedBy' => $p->archived_by,
        ] + self::origin($p);
    }

    public static function rule(CommissionPlanRule $r, array $scopeNames = []): array
    {
        $ids = array_values((array) ($r->scope_ids ?? []));

        return [
            'id' => $r->id, 'sequence' => $r->sequence, 'scope' => $r->scope, 'scopeIds' => $ids,
            'scopeNames' => array_values(array_filter(array_map(fn ($id) => $scopeNames[$id] ?? null, $ids))),
            'rateType' => $r->rate_type, 'rate' => $r->rate === null ? null : (float) $r->rate, 'tierMode' => $r->tier_mode, 'tiers' => $r->tiers ?: null, 'label' => $r->label,
        ];
    }

    /** The terms a statement keeps of its plan, so that it explains itself after the plan changes. */
    public static function terms(CommissionPlan $p): array
    {
        return [
            'planCode' => $p->code, 'planNameAr' => $p->name_ar, 'planNameEn' => $p->name_en, 'earnerType' => $p->earner_type, 'earnOn' => $p->earn_on, 'base' => $p->base_kind,
            'netOfReturns' => (bool) $p->net_of_returns, 'everyone' => (bool) $p->everyone, 'validFrom' => self::day($p->valid_from), 'validTo' => self::day($p->valid_to),
            'rules' => $p->rules->map(fn (CommissionPlanRule $r) => ['id' => $r->id, 'sequence' => $r->sequence, 'scope' => $r->scope, 'scopeIds' => array_values((array) ($r->scope_ids ?? [])),
                'rateType' => $r->rate_type, 'rate' => $r->rate === null ? null : (float) $r->rate, 'tierMode' => $r->tier_mode, 'tiers' => $r->tiers ?: null, 'label' => $r->label])->values()->all(),
        ];
    }

    /** A statement as a row of the list. @param array<string, object> $users */
    public static function statement(CommissionStatement $s, array $users = []): array
    {
        return [
            'id' => $s->id, 'number' => $s->number, 'reference' => $s->reference, 'period' => $s->period, 'dateFrom' => self::day($s->date_from), 'dateTo' => self::day($s->date_to),
            'status' => $s->status, 'earnerType' => $s->earner_type,
            'beneficiary' => People::shape($s->beneficiary_id, $users[$s->beneficiary_id] ?? null, $s->beneficiary_name),
            'plan' => $s->relationLoaded('plan') && $s->plan ? ['id' => $s->plan->id, 'code' => $s->plan->code, 'nameAr' => $s->plan->name_ar, 'nameEn' => $s->plan->name_en] : null,
            'linesCount' => (int) $s->lines_count, 'baseTotal' => self::money($s->base_total), 'commissionTotal' => self::money($s->commission_total),
            'adjustmentsTotal' => self::money($s->adjustments_total), 'total' => self::money($s->total), 'currency' => $s->currency,
            'calculatedAt' => self::at($s->calculated_at), 'submittedAt' => self::at($s->submitted_at), 'approvedAt' => self::at($s->approved_at), 'paidAt' => self::at($s->paid_at),
            'payoutReference' => $s->payout_reference, 'createdAt' => self::at($s->created_at),
        ] + self::origin($s);
    }

    /** Everything a statement holds besides its lines. */
    public static function statementDetail(CommissionStatement $s, array $users = []): array
    {
        return self::statement($s, $users) + [
            'terms' => $s->terms, 'calculatedBy' => $s->calculated_by, 'submittedBy' => $s->submitted_by, 'approvedBy' => $s->approved_by, 'approvalNote' => $s->approval_note,
            'returned' => $s->returned_at ? ['at' => self::at($s->returned_at), 'by' => $s->returned_by, 'reason' => $s->return_reason] : null,
            'paidBy' => $s->paid_by,
            'payout' => $s->status === 'paid' ? [
                'date' => self::day($s->payout_date), 'reference' => $s->payout_reference, 'bankAccount' => $s->payout_bank, 'account' => $s->payout_account,
                'expense' => $s->finance_expense_id ? ['id' => $s->finance_expense_id, 'number' => $s->finance_expense_number] : null,
            ] : null,
            'notes' => $s->notes, 'sourceUpdatedAt' => self::at($s->source_updated_at), 'sourceDeletedAt' => self::at($s->source_deleted_at), 'createdBy' => $s->created_by,
        ];
    }

    /**
     * @param  array<string, object>  $users
     * @param  array<string, object>  $customers  customers by id (id, code, name_ar, name_en)
     */
    public static function line(CommissionLine $l, array $users = [], array $customers = []): array
    {
        $c = $l->customer_id ? ($customers[$l->customer_id] ?? null) : null;

        return [
            'id' => $l->id, 'statementId' => $l->statement_id, 'kind' => $l->kind, 'sourceType' => $l->source_type, 'sourceId' => $l->source_id,
            'docNumber' => $l->doc_number, 'docDate' => self::day($l->doc_date), 'earnedOn' => self::day($l->earned_on),
            'customer' => $c ? ['id' => (string) $c->id, 'code' => $c->code, 'nameAr' => $c->name_ar, 'nameEn' => $c->name_en]
                : ($l->customer_name ? ['id' => null, 'code' => null, 'nameAr' => $l->customer_name, 'nameEn' => $l->customer_name] : null),
            'salesperson' => People::shape($l->salesperson_id, $users[$l->salesperson_id] ?? null, $l->salesperson_name), 'salespersonVia' => $l->salesperson_via,
            'invoiceTotal' => $l->invoice_total === null ? null : self::money($l->invoice_total), 'baseAmount' => self::money($l->base_amount),
            'rate' => $l->rate === null ? null : (float) $l->rate, 'commission' => self::money($l->commission, 4),
            'ruleId' => $l->rule_id, 'ruleLabel' => $l->rule_label, 'explain' => $l->explain, 'reversesId' => $l->reverses_id,
            'excluded' => (bool) $l->excluded, 'excludeReason' => $l->exclude_reason, 'paymentState' => $l->payment_state, 'reason' => $l->reason,
            'voided' => $l->voided_at ? ['at' => self::at($l->voided_at), 'by' => $l->voided_by, 'reason' => $l->void_reason] : null,
            'processedAt' => self::at($l->processed_at), 'createdBy' => $l->created_by, 'createdAt' => self::at($l->created_at),
        ] + self::origin($l);
    }

    /** @param array<string, object> $users */
    public static function assignment(CommissionAssignment $a, array $users = []): array
    {
        return [
            'id' => $a->id, 'kind' => $a->kind, 'subject' => $a->subject, 'label' => $a->subject_label ?? $a->subject,
            'user' => People::shape($a->user_id, $users[$a->user_id] ?? null, null), 'createdBy' => $a->created_by, 'createdAt' => self::at($a->created_at),
        ];
    }

    /** @return array{sourceSystem:?string, externalRef:?string, owned:bool} */
    private static function origin(CommissionPlan|CommissionStatement|CommissionLine $row): array
    {
        return ['sourceSystem' => $row->source_system, 'externalRef' => $row->external_ref, 'owned' => Owned::bySource($row)];
    }

    public static function money(mixed $v, int $decimals = 2): string
    {
        return number_format((float) $v, $decimals, '.', '');
    }

    public static function day(mixed $v): ?string
    {
        return $v instanceof DateTimeInterface ? $v->format('Y-m-d') : (is_string($v) && $v !== '' ? substr($v, 0, 10) : null);
    }

    public static function at(mixed $v): ?string
    {
        return $v instanceof DateTimeInterface ? $v->format(DATE_ATOM) : (is_string($v) && $v !== '' ? $v : null);
    }
}
