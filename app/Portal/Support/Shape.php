<?php

namespace App\Portal\Support;

use App\Portal\Models\Application;
use App\Portal\Models\ApplicationDocument;
use App\Portal\Models\FavoriteItem;
use App\Portal\Models\FavoriteList;
use App\Portal\Models\LineChange;
use App\Portal\Models\OrderRequest;
use App\Portal\Models\OrderRequestLine;
use App\Portal\Models\PortalBranch;
use App\Portal\Models\Statement;
use App\Portal\Models\StatementLine;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The JSON of the section (camelCase, the contract the Vue client and the sales platform read). Money leaves as a
 * decimal string like every amount of the API, a quantity without idle zeros, a day as `YYYY-MM-DD`, a moment as
 * ISO-8601. `customer` / `product` are the OPS record or — `linked: false` — the one the source system names.
 * `owned` says the record is Odoo's right now.
 */
final class Shape
{
    // ───────────── branches ─────────────

    /** Needs: customer, site. */
    public static function branch(PortalBranch $b, bool $full = false): array
    {
        $site = $b->site_id && $b->relationLoaded('site') ? $b->getRelation('site') : null;
        $row = [
            'id' => $b->id, 'name' => $b->name, 'code' => $b->code, 'sequence' => $b->sequence, 'customer' => Parties::customerOf($b),
            'city' => $b->city, 'district' => $b->district, 'contactName' => $b->contact_name, 'phone' => $b->phone,
            'windowFrom' => $b->window_from, 'windowTo' => $b->window_to, 'window' => $b->window_from && $b->window_to ? "{$b->window_from}–{$b->window_to}" : null,
            'deliveryDays' => $b->delivery_days ?: null,
            'site' => $site ? ['id' => $site->id, 'name' => $site->name, 'city' => $site->city, 'active' => (bool) $site->active] : null,
            'usersCount' => count($b->portal_users ?? []), 'located' => $b->lat !== null && $b->lng !== null,
            'active' => $b->active, 'createdAt' => self::at($b->created_at), 'updatedAt' => self::at($b->updated_at),
        ] + Owned::origin($b);
        if (! $full) {
            return $row;
        }

        return $row + [
            'email' => $b->email, 'address' => $b->address, 'lat' => $b->lat, 'lng' => $b->lng, 'deliveryNotes' => $b->delivery_notes, 'notes' => $b->notes,
            // the customer's people who order for the branch, each with the same three keys whatever order the database kept them in
            'portalUsers' => array_map(fn (array $u) => ['ref' => $u['ref'] ?? null, 'name' => $u['name'] ?? null, 'role' => $u['role'] ?? null], array_values($b->portal_users ?? [])),
            'archivedAt' => self::at($b->archived_at), 'archivedBy' => $b->archived_by, 'archiveReason' => $b->archive_reason,
            'sourceUpdatedAt' => self::at($b->source_updated_at), 'sourceDeletedAt' => self::at($b->source_deleted_at), 'createdBy' => $b->created_by, 'updatedBy' => $b->updated_by,
        ];
    }

    // ───────────── favourites ─────────────

    /** Needs: customer, branch. */
    public static function list(FavoriteList $l, bool $full = false): array
    {
        $row = [
            'id' => $l->id, 'name' => $l->name, 'customer' => Parties::customerOf($l), 'branch' => Parties::branchOf($l), 'sequence' => $l->sequence,
            'itemsCount' => $l->items_count, 'active' => $l->active, 'createdAt' => self::at($l->created_at), 'updatedAt' => self::at($l->updated_at),
        ] + Owned::origin($l);
        if (! $full) {
            return $row;
        }

        return $row + [
            'notes' => $l->notes, 'archivedAt' => self::at($l->archived_at), 'archivedBy' => $l->archived_by, 'archiveReason' => $l->archive_reason,
            'sourceUpdatedAt' => self::at($l->source_updated_at), 'sourceDeletedAt' => self::at($l->source_deleted_at), 'createdBy' => $l->created_by, 'updatedBy' => $l->updated_by,
        ];
    }

    /** Needs: product (with baseUom when the unit is wanted). `$position` is the item's place in its list, 1-based. */
    public static function item(FavoriteItem $i, ?int $position = null): array
    {
        $product = Parties::productOf($i);
        $uom = $i->product_id && $i->relationLoaded('product') && $i->product?->relationLoaded('baseUom') ? $i->product->baseUom?->code : null;

        return [
            'id' => $i->id, 'position' => $position, 'sequence' => $i->sequence, 'qty' => Text::qty($i->qty), 'product' => $product ? $product + ['uom' => $uom] : null,
            'sourceSystem' => $i->source_system, 'externalRef' => $i->external_ref, 'createdAt' => self::at($i->created_at),
        ];
    }

    // ───────────── onboarding applications ─────────────

    /**
     * Needs: customer. `$private`: the reader decides applications — the owner's identity number is shown in full;
     * anybody else sees its last four characters.
     */
    public static function application(Application $a, bool $full = false, bool $private = false): array
    {
        $row = [
            'id' => $a->id, 'number' => $a->number, 'status' => $a->status, 'sourceState' => $a->source_state, 'companyNameAr' => $a->company_name_ar, 'companyNameEn' => $a->company_name_en,
            'legalEntityType' => $a->legal_entity_type, 'cr' => $a->cr, 'vatNo' => $a->vat_no, 'city' => $a->city, 'mobile' => $a->mobile, 'email' => $a->email,
            'documentsCount' => $a->documents_count, 'customer' => Parties::customerOf($a), 'customerBy' => $a->customer_by,
            'createdAt' => self::at($a->created_at), 'submittedAt' => self::at($a->submitted_at), 'decidedAt' => self::at($a->decided_at), 'decidedBy' => $a->decided_by,
        ] + Owned::origin($a);
        if (! $full) {
            return $row;
        }

        return $row + [
            'language' => $a->language, 'unifiedNo' => $a->unified_no, 'ownerName' => $a->owner_name,
            'ownerNationalId' => $private ? $a->owner_national_id : Text::masked($a->owner_national_id), 'ownerNationalIdMasked' => ! $private && $a->owner_national_id !== null,
            'managerName' => $a->manager_name, 'street' => $a->street, 'buildingNo' => $a->building_no, 'additionalNo' => $a->additional_no, 'district' => $a->district,
            'region' => $a->region, 'postalCode' => $a->postal_code,
            // the people the company names, each with the same four keys whatever order the database kept them in
            'contacts' => array_map(fn (array $c) => ['role' => $c['role'] ?? 'other', 'name' => $c['name'] ?? null, 'mobile' => $c['mobile'] ?? null, 'email' => $c['email'] ?? null], array_values($a->contacts ?? [])),
            'termsAccepted' => $a->terms_accepted, 'termsAcceptedAt' => self::at($a->terms_accepted_at), 'termsVersion' => $a->terms_version, 'signedAt' => self::at($a->signed_at),
            'correctionNote' => $a->correction_note, 'rejectionReason' => $a->rejection_reason, 'approvedAt' => self::at($a->approved_at), 'expiredAt' => self::at($a->expired_at),
            'sourceUpdatedAt' => self::at($a->source_updated_at), 'sourceDeletedAt' => self::at($a->source_deleted_at), 'createdBy' => $a->created_by, 'updatedBy' => $a->updated_by,
            'updatedAt' => self::at($a->updated_at),
        ];
    }

    /**
     * @param  array<string, true>  $archived  Odoo attachment ids whose content OPS keeps
     * @param  bool  $private  the reader may open identity papers
     */
    public static function document(ApplicationDocument $d, array $archived, bool $private): array
    {
        $fromOdoo = $d->status === ApplicationDocument::ODOO;
        $restricted = in_array($d->kind, ApplicationDocument::PRIVATE_KINDS, true) && ! $private;
        $kept = $fromOdoo ? isset($archived[(string) $d->external_ref]) : (bool) $d->storage_key;

        return [
            'id' => $d->id, 'kind' => $d->kind, 'fileName' => $d->file_name, 'mime' => $d->mime, 'size' => $d->size, 'origin' => $fromOdoo ? 'odoo' : 'ops',
            // can its content be opened now, by this reader — and when not, why
            'available' => $kept && ! $restricted && $d->removed_at === null,
            'unavailable' => $d->removed_at !== null ? 'removed' : ($restricted ? 'private' : ($kept ? null : 'not_archived')),
            'uploadedBy' => $d->uploaded_by, 'createdAt' => self::at($d->created_at),
            'removedAt' => self::at($d->removed_at), 'removedBy' => $d->removed_by, 'removeReason' => $d->remove_reason,
        ];
    }

    // ───────────── order requests and the log of line changes ─────────────

    /** Needs: customer, branch, quotation. */
    public static function request(OrderRequest $r, bool $full = false): array
    {
        $q = $r->quotation_id && $r->relationLoaded('quotation') ? $r->getRelation('quotation') : null;
        $row = [
            'id' => $r->id, 'number' => $r->number, 'status' => $r->status, 'customer' => Parties::customerOf($r), 'branch' => Parties::branchOf($r),
            'requestedBy' => $r->requested_by, 'requestDate' => self::at($r->request_date), 'amountTotal' => Text::money($r->amount_total), 'currency' => $r->currency,
            'linesCount' => $r->lines_count, 'decidedBy' => $r->decided_by, 'decidedAt' => self::at($r->decided_at),
            'order' => $r->order_ref || $r->order_number ? ['ref' => $r->order_ref, 'number' => $r->order_number] : null,
            'quotation' => $q ? ['id' => $q->id, 'number' => $q->number, 'status' => $q->status] : null,
        ] + Owned::origin($r);
        if (! $full) {
            return $row;
        }

        return $row + [
            'decisionHours' => $r->decision_hours, 'priceList' => $r->price_list, 'note' => $r->note, 'rejectionReason' => $r->rejection_reason,
            'sourceUpdatedAt' => self::at($r->source_updated_at), 'sourceDeletedAt' => self::at($r->source_deleted_at), 'createdBy' => $r->created_by, 'updatedBy' => $r->updated_by,
            'createdAt' => self::at($r->created_at), 'updatedAt' => self::at($r->updated_at),
        ];
    }

    /** Needs: product. */
    public static function requestLine(OrderRequestLine $l): array
    {
        return [
            'id' => $l->id, 'lineNo' => $l->line_no, 'product' => Parties::productOf($l), 'description' => $l->description,
            'qty' => Text::qty($l->qty), 'price' => Text::money($l->price), 'subtotal' => Text::money($l->subtotal),
        ];
    }

    /** Needs: customer, product, branch, request. */
    public static function lineChange(LineChange $c): array
    {
        $r = $c->request_id && $c->relationLoaded('request') ? $c->getRelation('request') : null;

        return [
            'id' => $c->id, 'changedAt' => self::at($c->changed_at), 'changedBy' => $c->changed_by, 'type' => $c->change_type, 'field' => $c->field, 'fieldLabel' => $c->field_label,
            'oldValue' => $c->old_value, 'newValue' => $c->new_value,
            'order' => $c->order_ref || $c->order_number ? ['ref' => $c->order_ref, 'number' => $c->order_number] : null,
            'request' => $r ? ['id' => $r->id, 'number' => $r->number] : null,
            'customer' => Parties::customerOf($c), 'branch' => Parties::branchOf($c), 'product' => Parties::productOf($c),
            // what the product was called when the line was changed — the log keeps it, whatever the product is called now
            'productName' => $c->product_name, 'sourceSystem' => $c->source_system, 'externalRef' => $c->external_ref,
        ];
    }

    // ───────────── statements ─────────────

    /** Needs: customer, branch. */
    public static function statement(Statement $s, bool $full = false): array
    {
        $row = [
            'id' => $s->id, 'number' => $s->number, 'status' => $s->status, 'customer' => Parties::customerOf($s), 'branch' => Parties::branchOf($s),
            'dateFrom' => self::day($s->date_from), 'dateTo' => self::day($s->date_to), 'openingBalance' => Text::money($s->opening_balance), 'totalDebit' => Text::money($s->total_debit),
            'totalCredit' => Text::money($s->total_credit), 'closingBalance' => Text::money($s->closing_balance), 'currency' => $s->currency, 'linesCount' => $s->lines_count,
            'issuedAt' => self::at($s->issued_at), 'issuedBy' => $s->issued_by, 'createdAt' => self::at($s->created_at),
        ] + Owned::origin($s);
        if (! $full) {
            return $row;
        }

        return $row + [
            'note' => $s->note, 'booksClosing' => $s->books_closing === null ? null : Text::money($s->books_closing),
            'voidedAt' => self::at($s->voided_at), 'voidedBy' => $s->voided_by, 'voidReason' => $s->void_reason,
            'sourceUpdatedAt' => self::at($s->source_updated_at), 'sourceDeletedAt' => self::at($s->source_deleted_at), 'createdBy' => $s->created_by,
        ];
    }

    /** Needs: branch. */
    public static function statementLine(StatementLine $l): array
    {
        return [
            'id' => $l->id, 'date' => self::day($l->date), 'kind' => $l->kind, 'reference' => $l->reference, 'description' => $l->description,
            'debit' => Text::money($l->debit), 'credit' => Text::money($l->credit), 'balance' => Text::money($l->balance),
            'branch' => Parties::branchOf($l), 'docType' => $l->doc_type, 'docId' => $l->doc_id,
        ];
    }

    // ───────────── helpers ─────────────

    public static function at(?DateTimeInterface $d): ?string
    {
        return $d?->format(DATE_ATOM);
    }

    public static function day(?DateTimeInterface $d): ?string
    {
        return $d?->format('Y-m-d');
    }

    public static function camel(string $column): string
    {
        return Str::camel($column);
    }

    /**
     * The values that differ from what the row holds. A moment equals the same moment, a day its `YYYY-MM-DD`, 5 equals
     * "5.00", and a list equals the same list whatever the order of its keys.
     *
     * @return array<string, mixed>
     */
    public static function changes(Model $row, array $values): array
    {
        $out = [];
        foreach ($values as $column => $value) {
            if (! self::same($row->getAttribute($column), $value)) {
                $out[$column] = $value;
            }
        }

        return $out;
    }

    public static function same(mixed $current, mixed $value): bool
    {
        if ($current instanceof DateTimeInterface || $value instanceof DateTimeInterface) {
            // a day (`YYYY-MM-DD`) is compared as a day, a moment to the second
            $dayOnly = (is_string($value) && strlen($value) === 10) || (is_string($current) && strlen($current) === 10);
            $text = fn (mixed $v) => $v instanceof DateTimeInterface ? $v->format($dayOnly ? 'Y-m-d' : 'Y-m-d H:i:s') : ($v === null ? null : substr((string) $v, 0, $dayOnly ? 10 : 19));

            return $text($current) === $text($value);
        }
        if (is_bool($current) || is_bool($value)) {
            return $current !== null && $value !== null && (bool) $current === (bool) $value;
        }
        if (is_array($current) || is_array($value)) {
            return self::normal((array) ($current ?: [])) == self::normal((array) ($value ?: []));
        }
        if (is_numeric($current) && is_numeric($value) && (is_int($value) || is_float($value) || is_int($current) || is_float($current))) {
            return abs((float) $current - (float) $value) < 0.0005;
        }

        return (string) ($current ?? '') === (string) ($value ?? '');
    }

    /** A JSON value with its maps sorted by key at every depth (the database gives keys back in its own order). */
    private static function normal(array $v): array
    {
        foreach ($v as $k => $x) {
            if (is_array($x)) {
                $v[$k] = self::normal($x);
            }
        }
        if (! array_is_list($v)) {
            ksort($v);
        }

        return $v;
    }
}
