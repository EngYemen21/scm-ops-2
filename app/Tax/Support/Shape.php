<?php

namespace App\Tax\Support;

use App\Tax\Models\Einvoice;
use App\Tax\Models\EinvoiceAttempt;
use App\Tax\Models\EinvoiceHistory;
use App\Tax\Models\EinvoiceUnit;
use App\Tax\Models\FiscalPosition;
use App\Tax\Models\TaxCode;
use App\Tax\Models\TaxGroup;
use App\Tax\Models\TaxParty;
use App\Tax\Models\TaxProfile;
use App\Tax\Models\VatReturn;
use App\Tax\Models\VatReturnAdjustment;
use App\Tax\Models\VatReturnEntry;
use App\Tax\Models\VatReturnNote;
use App\Tax\Zatca\Codes;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * The JSON of the section (camelCase, the contract the Vue client reads). Money leaves as a decimal text like every
 * amount of the API, a day as `YYYY-MM-DD`, a moment as ISO-8601. Nothing here ever reads a secret of a unit.
 */
final class Shape
{
    public static function group(TaxGroup $g): array
    {
        return ['id' => $g->id, 'nameAr' => $g->name_ar, 'nameEn' => $g->name_en, 'sequence' => $g->sequence, 'active' => $g->active] + Owned::origin($g);
    }

    public static function code(TaxCode $c): array
    {
        $extra = (array) ($c->extra ?? []);

        return [
            'id' => $c->id, 'code' => $c->code, 'nameAr' => $c->name_ar, 'nameEn' => $c->name_en, 'label' => $c->label, 'description' => $c->description,
            'use' => $c->use, 'kind' => $c->kind, 'rate' => Money::rate($c->rate), 'returnBox' => $c->return_box, 'categoryCode' => $c->category_code,
            'exemptionReasonCode' => $c->exemption_reason_code, 'exemptionReasonAr' => $c->exemption_reason_ar, 'exemptionReasonEn' => $c->exemption_reason_en,
            'scope' => $c->scope, 'priceInclude' => $c->price_include, 'sequence' => $c->sequence, 'isDefault' => $c->is_default, 'active' => $c->active,
            'group' => $c->relationLoaded('group') && $c->group ? ['id' => $c->group->id, 'nameAr' => $c->group->name_ar, 'nameEn' => $c->group->name_en] : null,
            'groupId' => $c->group_id,
            // what the source system gives of the two things OPS may otherwise decide itself
            'sourceGives' => ['returnBox' => (bool) ($extra['box_from_source'] ?? false), 'exemptionReason' => (bool) ($extra['reason_from_source'] ?? false)],
            'extra' => array_diff_key($extra, ['box_from_source' => 1, 'reason_from_source' => 1]) ?: null,
            'createdBy' => $c->created_by, 'updatedBy' => $c->updated_by, 'updatedAt' => self::at($c->updated_at),
        ] + Owned::origin($c);
    }

    /** Needs: lines (with from / to). */
    public static function position(FiscalPosition $p): array
    {
        $brief = fn (?TaxCode $c) => $c ? ['id' => $c->id, 'code' => $c->code, 'nameAr' => $c->name_ar, 'nameEn' => $c->name_en, 'rate' => Money::rate($c->rate), 'use' => $c->use] : null;

        return [
            'id' => $p->id, 'nameAr' => $p->name_ar, 'nameEn' => $p->name_en, 'sequence' => $p->sequence, 'autoApply' => $p->auto_apply, 'vatRequired' => $p->vat_required,
            'countryCode' => $p->country_code, 'countryGroup' => $p->country_group, 'zipFrom' => $p->zip_from, 'zipTo' => $p->zip_to, 'foreignVat' => $p->foreign_vat, 'note' => $p->note, 'active' => $p->active,
            'lines' => $p->relationLoaded('lines') ? $p->lines->map(fn ($l) => ['id' => $l->id, 'from' => $brief($l->from), 'to' => $brief($l->to)] + ['sourceSystem' => $l->source_system])->values()->all() : [],
        ] + Owned::origin($p);
    }

    public static function profile(TaxProfile $p): array
    {
        return [
            'legalNameAr' => $p->legal_name_ar, 'legalNameEn' => $p->legal_name_en, 'vatNo' => $p->vat_no, 'idScheme' => $p->id_scheme, 'idValue' => $p->id_value,
            'street' => $p->street, 'buildingNo' => $p->building_no, 'additionalNo' => $p->additional_no, 'district' => $p->district, 'city' => $p->city, 'postalCode' => $p->postal_code,
            'province' => $p->province, 'countryCode' => $p->country_code, 'filingFrequency' => $p->filing_frequency, 'einvoiceAuto' => $p->einvoice_auto,
            'einvoiceFrom' => self::day($p->einvoice_from), 'canIssue' => $p->canIssue(), 'updatedBy' => $p->updated_by, 'updatedAt' => self::at($p->updated_at),
        ];
    }

    /** @param  object  $partner  the customer or the supplier (id, code, name_ar, name_en, vat number, cr, city, address) */
    public static function party(string $type, object $partner, ?TaxParty $t): array
    {
        return [
            'partnerType' => $type, 'partnerId' => $partner->id, 'code' => $partner->code, 'nameAr' => $partner->name_ar, 'nameEn' => $partner->name_en,
            'vatNo' => $type === 'customer' ? ($partner->vat_no ?? null) : ($partner->vat ?? null), 'cr' => $partner->cr ?? null, 'masterCity' => $partner->city ?? null, 'masterAddress' => $partner->address ?? null,
            'fiscalPositionId' => $t?->fiscal_position_id, 'defaultCodeId' => $t?->default_code_id, 'invoiceKind' => $t?->invoice_kind ?? 'auto',
            'street' => $t?->street, 'buildingNo' => $t?->building_no, 'additionalNo' => $t?->additional_no, 'district' => $t?->district, 'city' => $t?->city, 'postalCode' => $t?->postal_code,
            'countryCode' => $t?->country_code ?? 'SA', 'idScheme' => $t?->id_scheme, 'idValue' => $t?->id_value, 'hasDetails' => $t !== null, 'updatedBy' => $t?->updated_by, 'updatedAt' => self::at($t?->updated_at),
        ];
    }

    public static function vatReturn(VatReturn $r): array
    {
        return [
            'id' => $r->id, 'periodCode' => $r->period_code, 'frequency' => $r->frequency, 'periodFrom' => self::day($r->period_from), 'periodTo' => self::day($r->period_to), 'status' => $r->status,
            'outputVat' => (string) $r->output_vat, 'inputVat' => (string) $r->input_vat, 'correctionsVat' => (string) $r->corrections_vat, 'creditCarried' => (string) $r->credit_carried, 'netDue' => (string) $r->net_due,
            'ledgerOutput' => (string) $r->ledger_output, 'ledgerInput' => (string) $r->ledger_input, 'unexplained' => (string) $r->unexplained,
            'computedAt' => self::at($r->computed_at), 'reviewedBy' => $r->reviewed_by, 'reviewedAt' => self::at($r->reviewed_at),
            'filedReference' => $r->filed_reference, 'filedOn' => self::day($r->filed_on), 'filedBy' => $r->filed_by, 'filedAt' => self::at($r->filed_at),
            'lockedBy' => $r->locked_by, 'lockedAt' => self::at($r->locked_at), 'cancelledBy' => $r->cancelled_by, 'cancelledAt' => self::at($r->cancelled_at), 'cancelReason' => $r->cancel_reason,
            'notes' => $r->notes, 'createdBy' => $r->created_by, 'createdAt' => self::at($r->created_at),
        ];
    }

    public static function entry(VatReturnEntry $e): array
    {
        return [
            'id' => $e->id, 'journalId' => $e->journal_id, 'journalNumber' => $e->journal_number, 'date' => self::day($e->entry_date), 'docType' => $e->doc_type, 'docId' => $e->doc_id, 'docNumber' => $e->doc_number,
            'docKind' => $e->doc_kind, 'reversal' => $e->reversal, 'late' => $e->late, 'side' => $e->side, 'box' => $e->box, 'column' => $e->col, 'base' => (string) $e->base, 'vat' => (string) $e->vat,
            'docVat' => (string) $e->doc_vat, 'mismatch' => (string) $e->vat !== (string) $e->doc_vat, 'rate' => Money::rate($e->rate), 'codeId' => $e->code_id, 'code' => $e->code, 'classified' => $e->classified,
            'notional' => $e->classified !== 'none' && $e->box === 9,
            'partner' => $e->partner_id ? ['type' => $e->partner_type, 'id' => $e->partner_id, 'name' => $e->partner_name] : null, 'lateOfPeriod' => $e->late_of_period,
        ];
    }

    public static function adjustment(VatReturnAdjustment $a): array
    {
        return [
            'id' => $a->id, 'box' => $a->box, 'base' => (string) $a->base, 'vat' => (string) $a->vat, 'reason' => $a->reason, 'ledgerLineId' => $a->ledger_line_id,
            'createdBy' => $a->created_by, 'createdAt' => self::at($a->created_at), 'voided' => $a->voided_at !== null, 'voidedBy' => $a->voided_by, 'voidedAt' => self::at($a->voided_at), 'voidReason' => $a->void_reason,
        ];
    }

    public static function ledgerNote(VatReturnNote $n): array
    {
        return ['id' => $n->id, 'journalLineId' => $n->journal_line_id, 'treatment' => $n->treatment, 'reason' => $n->reason, 'createdBy' => $n->created_by, 'createdAt' => self::at($n->created_at),
            'voided' => $n->voided_at !== null, 'voidedBy' => $n->voided_by, 'voidedAt' => self::at($n->voided_at)];
    }

    /** A unit as the screens see it. The key, the request and the certificates are told as "held: yes / no" — never shown. */
    public static function unit(EinvoiceUnit $u): array
    {
        $raw = $u->getAttributes(); // the stored (encrypted) columns: only asked whether they hold something
        $held = fn (string $column) => ($raw[$column] ?? null) !== null && $raw[$column] !== '';

        return [
            'id' => $u->id, 'name' => $u->name, 'serial' => $u->serial, 'environment' => $u->environment,
            'environmentLabel' => isset(Codes::ENVIRONMENTS[$u->environment]) ? ['ar' => Codes::ENVIRONMENTS[$u->environment]['ar'], 'en' => Codes::ENVIRONMENTS[$u->environment]['en']] : null,
            'invoiceTypes' => $u->invoice_types, 'standard' => ($u->invoice_types[0] ?? '0') === '1', 'simplified' => ($u->invoice_types[1] ?? '0') === '1',
            'branchName' => $u->branch_name, 'location' => $u->location, 'industry' => $u->industry, 'status' => $u->status, 'onboarded' => $u->onboarded(), 'isDefault' => $u->is_default, 'sending' => $u->sending,
            'icv' => $u->icv, 'lastHash' => $u->last_hash,
            'holds' => ['key' => $held('private_key'), 'request' => $held('csr'), 'complianceCertificate' => $held('compliance_token'), 'productionCertificate' => $held('production_token')],
            'certificate' => $u->cert_serial ? ['serial' => $u->cert_serial, 'issuer' => $u->cert_issuer, 'subject' => $u->cert_subject, 'notBefore' => self::at($u->cert_not_before), 'notAfter' => self::at($u->cert_not_after),
                'expired' => $u->cert_not_after !== null && $u->cert_not_after->isPast(), 'daysLeft' => $u->cert_not_after ? (int) floor(Carbon::now()->diffInDays($u->cert_not_after, false)) : null] : null,
            'complianceChecks' => $u->compliance_checks ?: null,
            'retiredAt' => self::at($u->retired_at), 'retiredBy' => $u->retired_by, 'retireReason' => $u->retire_reason, 'createdBy' => $u->created_by, 'createdAt' => self::at($u->created_at),
        ];
    }

    /** A row of the e-invoice register (never the XML: that has its own endpoint). */
    public static function einvoice(Einvoice $e, bool $detail = false): array
    {
        $out = [
            'id' => $e->id, 'unitId' => $e->unit_id, 'icv' => $e->icv, 'uuid' => $e->uuid, 'docType' => $e->doc_type, 'docId' => $e->doc_id, 'docNumber' => $e->doc_number, 'docKind' => $e->doc_kind,
            'typeCode' => $e->type_code, 'transactionCode' => $e->transaction_code, 'profile' => $e->profile, 'issueAt' => self::at($e->issue_at), 'customerId' => $e->customer_id, 'buyerName' => $e->buyer_name,
            'buyerVat' => $e->buyer_vat, 'net' => (string) $e->net, 'vat' => (string) $e->vat, 'total' => (string) $e->total, 'currency' => $e->currency, 'hash' => $e->hash, 'previousHash' => $e->prev_hash,
            'signed' => $e->signed, 'isCurrent' => $e->is_current, 'attemptNo' => $e->attempt_no, 'supersededById' => $e->superseded_by_id, 'status' => $e->status, 'via' => $e->via, 'warnings' => $e->warnings,
            'httpStatus' => $e->http_status, 'sentAt' => self::at($e->sent_at), 'acceptedAt' => self::at($e->accepted_at), 'attempts' => $e->attempts, 'nextAttemptAt' => self::at($e->next_attempt_at),
            'lastError' => $e->last_error, 'issueCount' => ['errors' => count(array_filter((array) $e->issues, fn ($i) => ($i['level'] ?? null) === 'error')), 'warnings' => count(array_filter((array) $e->issues, fn ($i) => ($i['level'] ?? null) === 'warning'))],
            'createdBy' => $e->created_by, 'createdAt' => self::at($e->created_at),
        ];
        if ($detail) {
            $out += ['qr' => $e->qr, 'issues' => array_values((array) $e->issues), 'messages' => array_values((array) $e->messages), 'hasClearedXml' => (bool) $e->getAttribute('has_cleared_xml'),
                'unit' => $e->relationLoaded('unit') && $e->unit ? ['id' => $e->unit->id, 'name' => $e->unit->name, 'status' => $e->unit->status, 'environment' => $e->unit->environment, 'onboarded' => $e->unit->onboarded()] : null,
                'log' => $e->relationLoaded('log') ? $e->log->map(fn (EinvoiceAttempt $a) => self::attempt($a))->values()->all() : []];
        }

        return $out;
    }

    public static function attempt(EinvoiceAttempt $a): array
    {
        return ['id' => $a->id, 'at' => self::at($a->at), 'api' => $a->api, 'environment' => $a->environment, 'httpStatus' => $a->http_status, 'outcome' => $a->outcome, 'messages' => array_values((array) $a->messages),
            'error' => $a->error, 'durationMs' => $a->duration_ms, 'by' => $a->by];
    }

    public static function history(EinvoiceHistory $h, bool $fileHeld = false): array
    {
        return [
            'id' => $h->id, 'externalRef' => $h->external_ref, 'sourceSystem' => $h->source_system, 'formatCode' => $h->format_code, 'formatName' => $h->format_name,
            'moveRef' => $h->move_ref, 'moveNumber' => $h->move_number, 'moveType' => $h->move_type, 'archiveKind' => $h->archive_kind,
            'partnerName' => $h->partner_name, 'partnerRef' => $h->partner_ref, 'customerId' => $h->customer_id, 'invoiceDate' => self::day($h->invoice_date),
            'amountTotal' => $h->amount_total === null ? null : (string) $h->amount_total, 'amountTax' => $h->amount_tax === null ? null : (string) $h->amount_tax,
            'uuid' => $h->uuid, 'chainIndex' => $h->chain_index, 'confirmedAt' => self::at($h->confirmed_at), 'state' => $h->state, 'status' => $h->status, 'blockingLevel' => $h->blocking_level, 'error' => $h->error,
            'attachmentRef' => $h->attachment_ref, 'attachmentName' => $h->attachment_name, 'fileUrl' => $fileHeld && $h->attachment_ref ? "/api/odoo-backup/files/{$h->attachment_ref}" : null,
            'sourceCreatedAt' => self::at($h->source_created_at), 'sourceUpdatedAt' => self::at($h->source_updated_at), 'sourceDeletedAt' => self::at($h->source_deleted_at),
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
