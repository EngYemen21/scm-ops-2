<?php

namespace App\Tax;

use App\Integration\Odoo\Models\OdooRecord;
use App\Integration\Odoo\Projection\OdooProjection;
use App\Integration\Odoo\Projection\Val;
use App\Integration\Services\ExternalRefs;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Tax\Models\EinvoiceHistory;
use App\Tax\Models\FiscalPosition;
use App\Tax\Models\FiscalPositionLine;
use App\Tax\Models\TaxCode;
use App\Tax\Models\TaxGroup;
use App\Tax\Support\Boxes;
use App\Tax\Support\Money;
use App\Tax\Zatca\Codes;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * From the copy of Odoo's accounting to the tax section: the tax groups, the taxes (as tax codes), the fiscal
 * positions with their replacements, and every e-invoice document Odoo made (`account.edi.document`) as the e-invoice
 * history of the invoices the archive holds. Idempotent: a second pass changes nothing.
 *
 * A TAX OF ODOO → A TAX CODE. Use and rate are Odoo's own; the line of the VAT return is read from the tag of its base
 * repartition line for invoices ("+1. Standard Rates 15% (Base)" → line 1, the tags of the Saudi localisation); the
 * kind follows from that line (1 / 7 standard, 2-4 / 10 zero, 5 / 11 exempt, 8 import, 9 reverse charge); a tax of the
 * withholding group, or with no line of the return, is withholding or out of scope. The e-invoice category follows from
 * the kind. Odoo holds no exemption reason for its zero-rated and exempt sales taxes: that stays OPS's to give.
 *
 * WHAT IS ODOO'S on such a code: names, use, kind, rate, group, scope, the line of the return when Odoo gives one,
 * active. WHAT IS OPS'S: the short code people type, whether it is the default, and the line / exemption reason where
 * Odoo has none. A pass never touches those.
 *
 * LINKING is by identity only. A tax code OPS made itself (the first dictionary of the section) with exactly the same
 * use, kind, rate and line is the same treatment: it is adopted, never doubled. A history row's customer is the OPS
 * customer the Odoo partner of the invoice (or that partner's company) is linked to (`int_external_refs`).
 *
 * The e-invoice history is a record of what happened in Odoo: it is written from the copy and never edited in OPS; a
 * document deleted in Odoo keeps its row with the day it was deleted. plan() also runs where the section's tables do
 * not exist yet (it then counts everything as new) — it never writes.
 */
class OdooTaxProjection extends OdooProjection
{
    private const GROUP_AR = ['vat taxes' => 'ضرائب القيمة المضافة', 'other taxes' => 'ضرائب أخرى', 'withholding tax' => 'ضريبة الاستقطاع'];

    private const POSITION_AR = ['ksa' => 'داخل المملكة', 'gcc' => 'دول مجلس التعاون الخليجي', 'non-gcc' => 'خارج دول مجلس التعاون'];

    /** The OPS kind of an Odoo tax, in Arabic, for the name of the code. */
    private const KIND_AR = ['standard' => 'النسبة الأساسية', 'zero' => 'نسبة صفرية', 'exempt' => 'معفاة', 'out_of_scope' => 'خارج النطاق', 'reverse_charge' => 'احتساب عكسي',
        'import' => 'استيراد (الجمارك)', 'withholding' => 'ضريبة استقطاع'];

    private bool $installed = true;

    private bool $whole = false;

    /** @var array<string, array<int|string, Model>> entity → Odoo id → OPS row */
    private array $rows = [];

    /** @var array<string, array<int, true>> entity → Odoo ids a plan decided to create */
    private array $planned = [];

    /** @var array<int, string> tag id → name */
    private array $tags = [];

    /** @var array<int, array<int, array>> tax id → its repartition lines */
    private array $repartition = [];

    /** @var array<int, array{code:?string, name:?string}> */
    private array $formats = [];

    /** @var array<string, true> short codes taken (a plan must not hand the same one out twice) */
    private array $codesTaken = [];

    public function __construct(ExternalRefs $refs, private readonly AuditService $audit)
    {
        parent::__construct($refs);
    }

    public function key(): string
    {
        return 'tax';
    }

    /** Parents before children: a tax points at its group and its tags; a replacement at a position and two taxes; a document at its format. */
    public function models(): array
    {
        return ['account.tax.group', 'account.account.tag', 'account.tax', 'account.tax.repartition.line', 'account.fiscal.position', 'account.fiscal.position.tax', 'account.edi.format', 'account.edi.document'];
    }

    public function plan(): array
    {
        return $this->wholePass(fn () => parent::plan());
    }

    public function apply(): array
    {
        $out = $this->wholePass(fn () => parent::apply());
        $this->audit->log($this->actor(), ['action' => 'TAX.ODOO.APPLY', 'entityType' => 'OdooProjection', 'entityId' => 'tax', 'entityNumber' => 'tax', 'newValue' => $out['counts']]);

        return $out;
    }

    private function wholePass(callable $pass): array
    {
        $this->whole = true;
        try {
            return $pass();
        } finally {
            $this->whole = false;
        }
    }

    protected function begin(): void
    {
        $this->installed = Schema::hasTable('tax_einvoice_history');
        if (! $this->installed && ! $this->dry) {
            throw AppError::rule('TAX_NOT_INSTALLED', 'جداول قسم الضريبة لم تُنشأ بعد — شغّل الترحيل أولًا (php artisan migrate)', 'The tables of the tax section do not exist yet — run the migration first');
        }
        $this->planned = $this->codesTaken = [];
        $this->rows = [
            'groups' => $this->installed ? TaxGroup::where('source_system', self::SYSTEM)->get()->keyBy('external_ref')->all() : [],
            'codes' => $this->installed ? TaxCode::where('source_system', self::SYSTEM)->get()->keyBy('external_ref')->all() : [],
            'positions' => $this->installed ? FiscalPosition::where('source_system', self::SYSTEM)->get()->keyBy('external_ref')->all() : [],
            'lines' => $this->installed ? FiscalPositionLine::where('source_system', self::SYSTEM)->get()->keyBy('external_ref')->all() : [],
            // the history of a whole pass is read once; a change of the feed reads its one row
            'history' => $this->installed && $this->whole ? EinvoiceHistory::where('source_system', self::SYSTEM)->get()->keyBy('external_ref')->all() : [],
        ];
        if ($this->installed) {
            foreach (TaxCode::pluck('code') as $code) {
                $this->codesTaken[(string) $code] = true;
            }
        }
        $this->tags = $this->repartition = $this->formats = [];
        foreach (OdooRecord::where('model', 'account.account.tag')->whereNull('deleted_at')->get(['odoo_id', 'data']) as $t) {
            $this->tags[(int) $t->odoo_id] = (string) ($t->data['name'] ?? '');
        }
        foreach (OdooRecord::where('model', 'account.tax.repartition.line')->whereNull('deleted_at')->get(['odoo_id', 'data']) as $l) {
            if ($tax = Val::id($l->data['tax_id'] ?? null)) {
                $this->repartition[$tax][] = (array) $l->data + ['id' => (int) $l->odoo_id];
            }
        }
        foreach (OdooRecord::where('model', 'account.edi.format')->get(['odoo_id', 'data', 'display_name']) as $f) {
            $this->formats[(int) $f->odoo_id] = ['code' => Val::text($f->data['code'] ?? null, 40), 'name' => Val::text($f->data['name'] ?? null, 120) ?? $f->display_name];
        }
    }

    protected function project(OdooRecord $r): void
    {
        match ($r->model) {
            'account.tax.group' => $this->group($r),
            'account.tax' => $this->code($r),
            'account.fiscal.position' => $this->position($r),
            'account.fiscal.position.tax' => $this->positionLine($r),
            'account.edi.document' => $this->history($r),
            // what a tax is read with: in a whole pass the taxes are read with all of it already; a change re-reads them
            'account.tax.repartition.line' => $this->retax($r, [Val::id($r->data['tax_id'] ?? null)]),
            'account.account.tag' => $this->retax($r, array_keys($this->repartition)),
            'account.edi.format' => $this->count('formats.read'),
            default => null,
        };
    }

    // ───────────── groups ─────────────

    private function group(OdooRecord $r): ?Model
    {
        $name = Val::text($r->data['name'] ?? null, 120) ?? Val::text($r->display_name, 120) ?? "#{$r->odoo_id}";
        $values = ['name_ar' => self::GROUP_AR[mb_strtolower($name)] ?? $name, 'name_en' => $name, 'sequence' => Val::int($r->data['sequence'] ?? 10), 'active' => $this->alive($r)];

        return $this->upsert('groups', TaxGroup::class, $r, $values, fn () => TaxGroup::whereNull('source_system')->where(fn ($w) => $w->where('name_en', $name)->when(mb_strtolower($name) === 'vat taxes', fn ($x) => $x->orWhere('name_en', 'VAT')))
            ->orderBy('created_at')->first(), 'TaxGroup');
    }

    // ───────────── taxes → tax codes ─────────────

    private function code(OdooRecord $r): ?Model
    {
        $d = (array) $r->data;
        $use = $d['type_tax_use'] ?? null;
        if (! in_array($use, TaxCode::USES, true)) {
            $this->count('codes.skippedNoUse', "#{$r->odoo_id} ".($r->display_name ?? ''));

            return null;
        }
        if (($d['amount_type'] ?? 'percent') !== 'percent') {
            $this->count('codes.skippedNotPercent', "#{$r->odoo_id} ".($r->display_name ?? ''));

            return null;
        }
        $rate = Money::rate(Val::float($d['amount'] ?? 0));
        $groupLabel = (string) (Val::label($d['tax_group_id'] ?? null) ?? '');
        $box = $this->boxOf((int) $r->odoo_id);
        if ($box !== null && Boxes::side($box) !== $use) {
            $this->count('codes.boxOtherSide', "#{$r->odoo_id}: line {$box}");
            $box = null;
        }
        $withholding = Val::bool($d['l10n_sa_is_retention'] ?? false) || str_contains(mb_strtolower($groupLabel), 'withholding');
        $kind = $box !== null ? Boxes::kindOf($box) : ($withholding && bccomp($rate, '0', 4) > 0 ? 'withholding' : (bccomp($rate, '0', 4) > 0 ? 'standard' : 'out_of_scope'));
        if ($kind === 'withholding' && $use !== 'purchase') {
            $kind = 'out_of_scope';
        }
        $category = Codes::category($kind);
        $reason = Val::text($d['l10n_sa_exemption_reason_code'] ?? null, 30);
        $reason = $reason !== null && Codes::reasonFits($category, $reason) ? $reason : null;
        $name = Val::text($d['name'] ?? null, 190) ?? Val::text($r->display_name, 190) ?? "#{$r->odoo_id}";
        $description = Val::text($d['description'] ?? null, 2000);
        $scope = match ($d['tax_scope'] ?? null) {
            'service' => 'service', 'consu' => 'goods', default => null,
        };
        $values = [
            'name_ar' => mb_substr(self::KIND_AR[$kind].' '.$rate.'%'.($scope === 'service' ? ' — خدمات' : '').($kind === 'withholding' && $description ? " ({$description})" : ''), 0, 190),
            'name_en' => $name, 'label' => Val::text($d['invoice_label'] ?? null, 190), 'description' => $description, 'use' => $use, 'kind' => $kind, 'rate' => $rate,
            'category_code' => $category, 'scope' => $scope, 'price_include' => Val::bool($d['price_include'] ?? false), 'sequence' => Val::int($d['sequence'] ?? 0),
            'group_id' => $this->ref('groups', 'account.tax.group', Val::id($d['tax_group_id'] ?? null)), 'active' => $this->alive($r),
            'source_updated_at' => Val::at($d['write_date'] ?? null),
            'extra' => ['box_from_source' => $box !== null, 'reason_from_source' => $reason !== null, 'odooName' => $name, 'odooGroup' => $groupLabel ?: null],
        ];
        if ($box !== null) {
            $values['return_box'] = $box;
        }
        if ($reason !== null) {
            $values += ['exemption_reason_code' => $reason, 'exemption_reason_ar' => Codes::EXEMPTIONS[$reason][2], 'exemption_reason_en' => Codes::EXEMPTIONS[$reason][1]];
        }
        if (! $values['active']) {
            $values['is_default'] = false; // an archived code is nobody's default
        }
        $code = $this->upsert('codes', TaxCode::class, $r, $values, fn () => $box === null ? null : TaxCode::whereNull('source_system')->where('use', $use)->where('kind', $kind)->where('return_box', $box)
            ->orderByRaw("case when created_by = 'system' then 0 else 1 end")->orderBy('created_at')->get()->first(fn (TaxCode $c) => Money::sameRate($c->rate, $rate)), 'TaxCode',
            // only a code made here gets a short code: an adopted one keeps the one people know
            fn () => ['code' => $this->shortCode($use, $name, (int) $r->odoo_id), 'is_default' => false, 'return_box' => $box, 'updated_by' => $this->actor()->username]);

        return $code;
    }

    /** The line of the return a tax feeds: the number of the tag on its base repartition line of invoices ("+1. …" → 1). */
    private function boxOf(int $taxId): ?int
    {
        foreach ($this->repartition[$taxId] ?? [] as $line) {
            if (($line['repartition_type'] ?? null) !== 'base' || ($line['document_type'] ?? 'invoice') !== 'invoice') {
                continue;
            }
            foreach (Val::ids($line['tag_ids'] ?? []) as $tag) {
                if (preg_match('/^\+\s*(\d{1,2})\./u', $this->tags[$tag] ?? '', $m) && Boxes::exists((int) $m[1])) {
                    return (int) $m[1];
                }
            }
        }

        return null;
    }

    /** A free short code for a tax made from Odoo: "S-15", "P-15-R-C", "P-0-EXEMPT" … (OPS's own from then on). */
    private function shortCode(string $use, string $name, int $odooId): string
    {
        $base = ($use === 'sale' ? 'S-' : 'P-').trim((string) preg_replace('/[^A-Z0-9]+/', '-', strtoupper(Str::ascii($name))), '-');
        $base = substr(rtrim($base, '-'), 0, 24) ?: ($use === 'sale' ? 'S' : 'P');
        $code = $base;
        if (isset($this->codesTaken[$code])) {
            $code = substr($base, 0, 22).'-'.$odooId;
        }
        $this->codesTaken[$code] = true;

        return $code;
    }

    /** A repartition line or a tag changed: the taxes it belongs to are read again (in a whole pass they already were). */
    private function retax(OdooRecord $r, array $taxIds): void
    {
        if ($this->whole) {
            $this->count('taxParts.read');

            return;
        }
        if ($r->model === 'account.tax.repartition.line') {
            // the line as the copy holds it now replaces the one read at the start of the run
            $tax = Val::id($r->data['tax_id'] ?? null);
            $this->repartition[$tax] = array_values(array_filter($this->repartition[$tax] ?? [], fn (array $l) => ($l['id'] ?? null) !== $r->odoo_id));
            if ($r->deleted_at === null) {
                $this->repartition[$tax][] = (array) $r->data + ['id' => $r->odoo_id];
            }
        } else {
            $this->tags[(int) $r->odoo_id] = $r->deleted_at === null ? (string) ($r->data['name'] ?? '') : '';
        }
        foreach (array_filter($taxIds) as $taxId) {
            if ($tax = $this->mirrored('account.tax', (int) $taxId)) {
                $this->code($tax);
            }
        }
    }

    // ───────────── fiscal positions ─────────────

    private function position(OdooRecord $r): ?Model
    {
        $d = (array) $r->data;
        $name = Val::text($d['name'] ?? null, 120) ?? Val::text($r->display_name, 120) ?? "#{$r->odoo_id}";
        $country = Val::id($d['country_id'] ?? null);
        $countryCode = $country ? Val::text($this->mirrored('res.country', $country)?->data['code'] ?? null, 2) : null;
        $values = [
            'name_ar' => self::POSITION_AR[mb_strtolower($name)] ?? $name, 'name_en' => $name, 'sequence' => Val::int($d['sequence'] ?? 0), 'auto_apply' => Val::bool($d['auto_apply'] ?? false),
            'vat_required' => Val::bool($d['vat_required'] ?? false), 'country_code' => $countryCode ? strtoupper($countryCode) : null, 'country_group' => Val::text(Val::label($d['country_group_id'] ?? null), 60),
            'zip_from' => Val::text($d['zip_from'] ?? null, 20), 'zip_to' => Val::text($d['zip_to'] ?? null, 20), 'foreign_vat' => Val::text($d['foreign_vat'] ?? null, 40),
            'note' => Val::plain($d['note'] ?? null, 4000), 'active' => $this->alive($r),
            'extra' => array_filter(['country' => Val::label($d['country_id'] ?? null)]) ?: null,
        ];

        return $this->upsert('positions', FiscalPosition::class, $r, $values, fn () => FiscalPosition::whereNull('source_system')->where('name_en', $name)->orderBy('created_at')->first(), 'FiscalPosition');
    }

    /** "For a partner of this position, this tax becomes that one." A line removed in Odoo is removed here (it is part of the position's definition). */
    private function positionLine(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $row = $this->rows['lines'][$r->odoo_id] ?? null;
        if ($r->deleted_at !== null) {
            if (! $row) {
                $this->count('positionLines.skippedDeleted');

                return;
            }
            $this->count('positionLines.removed');
            if (! $this->dry) {
                $row->delete();
                unset($this->rows['lines'][$r->odoo_id]);
            }

            return;
        }
        $position = $this->ref('positions', 'account.fiscal.position', Val::id($d['position_id'] ?? null));
        $from = $this->ref('codes', 'account.tax', Val::id($d['tax_src_id'] ?? null));
        $to = $this->ref('codes', 'account.tax', Val::id($d['tax_dest_id'] ?? null));
        if (! $position || ! $from || ! $to) {
            // Odoo may replace a tax by none: OPS has no "no tax" code, a person decides what such a partner's documents are
            $this->count('positionLines.skippedIncomplete', "#{$r->odoo_id}");

            return;
        }
        $values = ['position_id' => $position, 'from_code_id' => $from, 'to_code_id' => $to];
        if ($row) {
            $changes = self::changes($row, $values);
            $this->count($changes ? 'positionLines.updated' : 'positionLines.unchanged');
            if ($changes && ! $this->dry) {
                $row->update($changes);
            }

            return;
        }
        $this->count('positionLines.created');
        if ($this->dry) {
            return;
        }
        // the same replacement typed in OPS on that position is adopted, never doubled
        $twin = FiscalPositionLine::where('position_id', $position)->where('from_code_id', $from)->whereNull('source_system')->first();
        $origin = ['source_system' => self::SYSTEM, 'external_ref' => (string) $r->odoo_id];
        $this->rows['lines'][$r->odoo_id] = $twin ? tap($twin)->update($values + $origin) : FiscalPositionLine::create($values + $origin);
    }

    // ───────────── e-invoice documents → history ─────────────

    private function history(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $ref = (string) $r->odoo_id;
        $row = $this->whole ? ($this->rows['history'][$ref] ?? null) : ($this->installed ? EinvoiceHistory::where('source_system', self::SYSTEM)->where('external_ref', $ref)->first() : null);
        if (! $row && $r->deleted_at !== null) {
            $this->count('history.skippedDeleted');

            return;
        }
        $moveId = Val::id($d['move_id'] ?? null);
        $move = $moveId ? $this->mirrored('account.move', $moveId) : null;
        $m = (array) ($move?->data ?? []);
        $moveType = Val::text($m['move_type'] ?? null, 20);
        $partner = Val::id($m['partner_id'] ?? null);
        $format = $this->formats[Val::id($d['edi_format_id'] ?? null) ?? 0] ?? ['code' => null, 'name' => Val::label($d['edi_format_id'] ?? null)];
        $state = Val::text($d['state'] ?? null, 20);
        $blocking = Val::text($d['blocking_level'] ?? null, 20);
        $values = [
            'format_code' => $format['code'], 'format_name' => self::cut($format['name'] ?? Val::label($d['edi_format_id'] ?? null), 120),
            'move_ref' => $moveId ? (string) $moveId : null, 'move_number' => self::cut(Val::text($m['name'] ?? null) ?? Val::label($d['move_id'] ?? null), 80), 'move_type' => $moveType,
            'archive_kind' => match ($moveType) {
                'out_invoice', 'out_receipt' => 'customer_invoice', 'out_refund' => 'customer_credit_note', 'in_invoice', 'in_receipt' => 'vendor_bill', 'in_refund' => 'vendor_refund', default => null,
            },
            'partner_name' => self::cut(Val::label($m['partner_id'] ?? null), 190), 'partner_ref' => $partner ? (string) $partner : null, 'customer_id' => $this->customerOf($partner),
            'invoice_date' => Val::date($m['invoice_date'] ?? null), 'amount_total' => $move ? Money::text(Money::minor(Val::float($m['amount_total'] ?? 0))) : null,
            'amount_tax' => $move ? Money::text(Money::minor(Val::float($m['amount_tax'] ?? 0))) : null, 'uuid' => Val::text($m['l10n_sa_uuid'] ?? null, 64),
            'chain_index' => isset($m['l10n_sa_chain_index']) && is_int($m['l10n_sa_chain_index']) && $m['l10n_sa_chain_index'] > 0 ? $m['l10n_sa_chain_index'] : null,
            'confirmed_at' => Val::at($m['l10n_sa_confirmation_datetime'] ?? null), 'state' => $state,
            'status' => $state === 'to_send' && $blocking === 'error' ? 'error' : (EinvoiceHistory::STATUS[$state] ?? ($state ?? 'not_sent')), 'blocking_level' => $blocking,
            'error' => Val::plain($d['error'] ?? null, 4000), 'attachment_ref' => ($a = Val::id($d['attachment_id'] ?? null)) ? (string) $a : null,
            'attachment_name' => self::cut(Val::label($d['attachment_id'] ?? null), 255),
            'source_created_at' => Val::at($d['create_date'] ?? null), 'source_updated_at' => Val::at($d['write_date'] ?? null),
            'source_deleted_at' => $r->deleted_at !== null ? ($row?->source_deleted_at ?? $r->deleted_at) : null,
        ];
        if (! $row) {
            $this->count('history.created');
            if (! $this->dry) {
                $this->rows['history'][$ref] = EinvoiceHistory::create($values + ['source_system' => self::SYSTEM, 'external_ref' => $ref]);
            }

            return;
        }
        $changes = self::changes($row, $values);
        $this->count(! $changes ? 'history.unchanged' : (array_key_exists('source_deleted_at', $changes) && $changes['source_deleted_at'] !== null ? 'history.deleted' : 'history.updated'));
        if ($changes && ! $this->dry) {
            $row->update($changes);
        }
    }

    /** The OPS customer an Odoo partner (or its company) is linked to. */
    private function customerOf(?int $partner): ?string
    {
        if (! $partner) {
            return null;
        }
        $id = $this->linked('customer', $partner);
        if ($id === null && ($parent = Val::id($this->mirrored('res.partner', $partner)?->data['parent_id'] ?? null))) {
            $id = $this->linked('customer', $parent);
        }

        return $id;
    }

    // ───────────── shared ─────────────

    /**
     * One record of a small table. `$values` are what Odoo owns, as it should be now; `$onCreate` what only a new row gets.
     *
     * @param  callable(): ?Model  $twin  the OPS-made row that is the same thing (adopted, never doubled)
     * @param  (callable(): array)|null  $onCreate
     */
    private function upsert(string $entity, string $class, OdooRecord $r, array $values, callable $twin, string $auditType, ?callable $onCreate = null): ?Model
    {
        $row = $this->rows[$entity][$r->odoo_id] ?? null;
        $label = (string) ($values['name_en'] ?? $r->display_name ?? "#{$r->odoo_id}");
        if (! $row) {
            if ($r->deleted_at !== null) {
                $this->count("{$entity}.skippedDeleted");

                return null;
            }
            $found = $this->installed ? $twin() : null;
            $this->count($entity.($found ? '.linked' : '.created'), $found ? $label : null);
            if ($this->dry) {
                $this->planned[$entity][$r->odoo_id] = true;
                if (! $found && $onCreate) {
                    $onCreate(); // reserves the short code a create would take
                }

                return null;
            }
            $row = DB::transaction(function () use ($class, $r, $values, $found, $onCreate, $auditType, $label) {
                $origin = ['source_system' => self::SYSTEM, 'external_ref' => (string) $r->odoo_id];
                if ($found) {
                    // adopted: what is OPS's own on it (short code, default, its line and reason where Odoo gives none) stays
                    $found->update($values + $origin);
                    $row = $found;
                } else {
                    $row = $class::create($values + $origin + ($onCreate ? $onCreate() : []) + ['created_by' => $this->actor()->username]);
                }
                $this->audit->log($this->actor(), ['action' => 'TAX.ODOO.'.($found ? 'LINK' : 'CREATE'), 'entityType' => $auditType, 'entityId' => $row->getKey(), 'entityNumber' => mb_substr($label, 0, 120),
                    'newValue' => ['origin' => "from Odoo {$r->model} #{$r->odoo_id}"] + array_diff_key($values, ['extra' => 1])]);

                return $row;
            });
            $this->rows[$entity][$r->odoo_id] = $row;

            return $row;
        }
        if ($r->deleted_at !== null) {
            $values = ['active' => false] + ($entity === 'codes' ? ['is_default' => false] : []); // gone from Odoo: put away; what carries it keeps it
        }
        $changes = self::changes($row, $values);
        if (! $changes) {
            $this->count("{$entity}.unchanged");

            return $row;
        }
        $this->count($entity.(($changes['active'] ?? null) === false ? '.archived' : '.updated'));
        if ($this->dry) {
            return $row;
        }
        DB::transaction(function () use ($row, $changes, $auditType, $label) {
            $old = [];
            foreach (array_keys($changes) as $column) {
                $old[$column] = $row->getAttribute($column);
            }
            $row->update($changes);
            $this->audit->log($this->actor(), ['action' => 'TAX.ODOO.UPDATE', 'entityType' => $auditType, 'entityId' => $row->getKey(), 'entityNumber' => mb_substr($label, 0, 120),
                'oldValue' => array_diff_key($old, ['extra' => 1]), 'newValue' => array_diff_key($changes, ['extra' => 1]) + ['origin' => 'Odoo']]);
        });

        return $row;
    }

    /** The OPS id of a group / code / position of Odoo; one the pass has not met yet is made from the copy on the spot. */
    private function ref(string $entity, string $model, ?int $odooId): ?string
    {
        if (! $odooId) {
            return null;
        }
        if ($row = $this->rows[$entity][$odooId] ?? null) {
            return (string) $row->getKey();
        }
        if (isset($this->planned[$entity][$odooId])) {
            return "new:{$entity}:{$odooId}";
        }
        if ($r = $this->mirrored($model, $odooId)) {
            $this->project($r);
        }
        if ($row = $this->rows[$entity][$odooId] ?? null) {
            return (string) $row->getKey();
        }

        return isset($this->planned[$entity][$odooId]) ? "new:{$entity}:{$odooId}" : null;
    }

    /** The columns whose value differs (numbers by value, moments to the second, flags as flags). */
    private static function changes(Model $row, array $values): array
    {
        $out = [];
        foreach ($values as $column => $value) {
            $current = $row->getAttribute($column);
            $same = match (true) {
                is_bool($value) || is_bool($current) => (bool) $current === (bool) $value,
                // a JSON column comes back with its keys in the engine's own order
                is_array($value) || is_array($current) => self::canonical($current) === self::canonical($value),
                $value instanceof DateTimeInterface || $current instanceof DateTimeInterface => self::moment($current) === self::moment($value),
                is_numeric($value) && is_numeric($current) => bccomp((string) $current, (string) $value, 4) === 0,
                default => (string) ($current ?? '') === (string) ($value ?? ''),
            };
            if (! $same) {
                $out[$column] = $value;
            }
        }

        return $out;
    }

    private static function canonical(mixed $v): string
    {
        $sort = function (mixed $x) use (&$sort) {
            if (! is_array($x)) {
                return $x;
            }
            if (! array_is_list($x)) {
                ksort($x);
            }

            return array_map($sort, $x);
        };

        return (string) json_encode($sort($v), JSON_UNESCAPED_UNICODE);
    }

    private static function moment(mixed $v): ?string
    {
        if ($v instanceof DateTimeInterface) {
            return $v->format('Y-m-d H:i:s');
        }

        return $v === null || $v === '' ? null : Carbon::parse((string) $v)->format('Y-m-d H:i:s');
    }

    private static function cut(?string $v, int $max): ?string
    {
        return $v === null ? null : mb_substr($v, 0, $max);
    }
}
