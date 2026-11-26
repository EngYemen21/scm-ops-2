<?php

namespace App\Tax\Services;

use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Tax\Models\TaxCode;
use App\Tax\Models\TaxDocumentCode;
use App\Tax\Models\VatReturn;
use App\Tax\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Which tax code an OPS document is of. OPS documents carry a VAT percentage, not a code (and stay that way); the code
 * is found, in this order:
 *
 *   1. explicit   a person said so for this document (`tax_document_codes`)
 *   2. partner    the customer's / supplier's own tax code, when its rate is the document's rate
 *   3. position   the default code of the rate, replaced by the partner's fiscal position
 *   4. default    the default code of the document's use and rate
 *   —  none       no code answers: the document waits for a person (it is on no line of a return, and a sale without
 *                 VAT cannot become an e-invoice until its reason is known)
 *
 * A reverse-charge code is the one code whose rate is not the document's: such a purchase carries no VAT in the books
 * and the code's rate says what is declared on both sides of the return. It is only ever given explicitly or to a
 * supplier.
 */
class Classifier
{
    /** The documents a code is given to, and the side of the return each stands on. */
    public const DOC_TYPES = ['CustomerInvoice' => 'sale', 'SupplierInvoice' => 'purchase', 'DebitNote' => 'purchase', 'Expense' => 'purchase', 'FuelRecord' => 'purchase', 'MaintenanceOrder' => 'purchase'];

    /** @var array<string, TaxCode>|null */
    private ?array $codes = null;

    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  array<int, array{docType:string, docId:string, use:string, rate:string, partnerType?:?string, partnerId?:?string, parentId?:?string}>  $docs
     *                                                                                                                                                       `parentId`: the invoice a credit note credits
     * @return array<string, array{code:?TaxCode, how:string}> keyed "docType:docId"
     */
    public function resolve(array $docs): array
    {
        $codes = $this->codes();
        $explicit = [];
        foreach (array_chunk(array_values(array_unique(array_map(fn ($d) => (string) $d['docId'], $docs))), 1000) as $ids) {
            foreach (DB::table('tax_document_codes')->whereIn('doc_id', $ids)->get(['doc_type', 'doc_id', 'tax_code_id']) as $row) {
                $explicit["{$row->doc_type}:{$row->doc_id}"] = (string) $row->tax_code_id;
            }
        }
        $parties = [];
        $partnerIds = array_values(array_unique(array_filter(array_map(fn ($d) => $d['partnerId'] ?? null, $docs))));
        foreach (array_chunk($partnerIds, 1000) as $ids) {
            foreach (DB::table('tax_parties')->whereIn('partner_id', $ids)->get(['partner_type', 'partner_id', 'default_code_id', 'fiscal_position_id']) as $row) {
                $parties["{$row->partner_type}:{$row->partner_id}"] = $row;
            }
        }
        $swaps = [];
        foreach (DB::table('tax_fiscal_position_lines as l')->join('tax_fiscal_positions as p', 'p.id', '=', 'l.position_id')->where('p.active', true)->get(['l.position_id', 'l.from_code_id', 'l.to_code_id']) as $row) {
            $swaps[(string) $row->position_id][(string) $row->from_code_id] = (string) $row->to_code_id;
        }
        $defaults = [];
        foreach ($codes as $c) {
            if ($c->is_default && $c->active) {
                $defaults[$c->use.'|'.Money::rate($c->rate)] = $c;
            }
        }
        // a credit note follows the decision a person took for the invoice it credits
        $parents = array_values(array_unique(array_filter(array_map(fn ($d) => isset($d['parentId']) ? (string) $d['parentId'] : null, $docs))));
        foreach (array_chunk($parents, 1000) as $ids) {
            foreach (DB::table('tax_document_codes')->whereIn('doc_id', $ids)->get(['doc_type', 'doc_id', 'tax_code_id']) as $row) {
                $explicit["{$row->doc_type}:{$row->doc_id}"] ??= (string) $row->tax_code_id;
            }
        }
        $out = [];
        foreach ($docs as $d) {
            $key = "{$d['docType']}:{$d['docId']}";
            $rate = Money::rate($d['rate']);
            $fits = fn (?TaxCode $c) => $c !== null && $c->use === $d['use'] && $c->kind !== 'withholding' && ($c->kind === 'reverse_charge' ? $rate === '0' : Money::sameRate($c->rate, $rate));
            $said = $explicit[$key] ?? (isset($d['parentId']) ? ($explicit["{$d['docType']}:{$d['parentId']}"] ?? null) : null);
            if ($said !== null && $fits($codes[$said] ?? null)) {
                $out[$key] = ['code' => $codes[$said], 'how' => 'explicit'];

                continue;
            }
            $party = ! empty($d['partnerId']) ? ($parties[($d['partnerType'] ?? '').':'.$d['partnerId']] ?? null) : null;
            $own = $party && $party->default_code_id ? ($codes[(string) $party->default_code_id] ?? null) : null;
            if ($own && $own->active && $fits($own)) {
                $out[$key] = ['code' => $own, 'how' => 'partner'];

                continue;
            }
            $default = $defaults[$d['use'].'|'.$rate] ?? null;
            if ($default && $party && $party->fiscal_position_id && isset($swaps[(string) $party->fiscal_position_id][$default->id])) {
                $swapped = $codes[$swaps[(string) $party->fiscal_position_id][$default->id]] ?? null;
                if ($swapped && $swapped->active && $fits($swapped)) {
                    $out[$key] = ['code' => $swapped, 'how' => 'position'];

                    continue;
                }
            }
            $out[$key] = $default ? ['code' => $default, 'how' => 'default'] : ['code' => null, 'how' => 'none'];
        }

        return $out;
    }

    /** One document. @return array{code:?TaxCode, how:string} */
    public function of(string $docType, string $docId, string $rate, ?string $partnerType = null, ?string $partnerId = null, ?string $parentId = null): array
    {
        return $this->resolve([['docType' => $docType, 'docId' => $docId, 'use' => self::DOC_TYPES[$docType] ?? 'sale', 'rate' => $rate, 'partnerType' => $partnerType, 'partnerId' => $partnerId,
            'parentId' => $parentId]])["{$docType}:{$docId}"];
    }

    /**
     * A person gives a document its code — or takes the decision back (`$codeId` null: the rate decides again).
     * `$doc` are the facts of the document as its module holds them (the caller found it).
     *
     * @param  array{docType:string, docId:string, number:string, rate:string}  $doc
     */
    public function assign(AuthUser $user, array $doc, ?string $codeId, ?string $reason): array
    {
        return DB::transaction(function () use ($user, $doc, $codeId, $reason) {
            $use = self::DOC_TYPES[$doc['docType']] ?? throw AppError::validation('TAX_DOC_TYPE', 'نوع المستند لا يحمل رمزًا ضريبيًا', 'This kind of document carries no tax code');
            $declared = DB::table('tax_return_entries as e')->join('tax_returns as r', 'r.id', '=', 'e.return_id')->where('e.doc_type', $doc['docType'])->where('e.doc_id', $doc['docId'])
                ->whereIn('r.status', VatReturn::FROZEN)->orderBy('r.period_from')->value('r.period_code');
            if ($declared) {
                throw AppError::rule('VAT_DOCUMENT_DECLARED', "المستند {$doc['number']} مُدرج في إقرار الفترة {$declared} الذي تمت مراجعته — أعد الإقرار إلى مسودة أولًا، أو صحّح بسطر تسوية في إقرار لاحق",
                    "Document {$doc['number']} is part of the reviewed return of {$declared} — reopen that return first, or correct it with an adjustment in a later one", ['period' => $declared]);
            }
            $current = TaxDocumentCode::where('doc_type', $doc['docType'])->where('doc_id', $doc['docId'])->lockForUpdate()->first();
            $before = $current ? ($this->codes()[(string) $current->tax_code_id]->code ?? null) : null;
            if ($codeId === null) {
                // the decision is taken back: the row of the decision goes, its trace stays in the audit trail
                $current?->delete();
                if ($current) {
                    $this->audit->log($user, ['action' => 'TAX.DOCUMENT.CODE', 'entityType' => $doc['docType'], 'entityId' => $doc['docId'], 'entityNumber' => $doc['number'], 'oldValue' => $before, 'newValue' => ['code' => null, 'reason' => $reason]]);
                }

                return ['docType' => $doc['docType'], 'docId' => $doc['docId'], 'code' => null, 'how' => 'cleared'];
            }
            $code = TaxCode::where(fn ($w) => $w->where('id', $codeId)->orWhere('code', $codeId))->first() ?? throw AppError::notFound('TAX_CODE_NOT_FOUND', 'الرمز الضريبي غير موجود', 'Tax code not found');
            if (! $code->active || $code->use !== $use || $code->kind === 'withholding') {
                throw AppError::rule('TAX_CODE_NOT_FOR_DOCUMENT', $use === 'sale' ? 'اختر رمز مبيعات فعّالًا' : 'اختر رمز مشتريات فعّالًا', $use === 'sale' ? 'Pick an active sales code' : 'Pick an active purchases code');
            }
            $rate = Money::rate($doc['rate']);
            if ($code->kind === 'reverse_charge' ? $rate !== '0' : ! Money::sameRate($code->rate, $rate)) {
                throw AppError::rule('TAX_CODE_RATE_MISMATCH', $code->kind === 'reverse_charge'
                    ? "رمز الاحتساب العكسي لمستند بلا ضريبة في الدفاتر؛ المستند {$doc['number']} عليه {$rate}%"
                    : "نسبة الرمز {$code->code} (".Money::rate($code->rate)."%) لا توافق نسبة المستند {$doc['number']} ({$rate}%)",
                    "The rate of code {$code->code} does not fit document {$doc['number']} ({$rate}%)", ['codeRate' => Money::rate($code->rate), 'documentRate' => $rate]);
            }
            if ($current) {
                $current->update(['tax_code_id' => $code->id, 'reason' => $reason ?: null, 'set_by' => $user->username, 'set_at' => now()]);
            } else {
                TaxDocumentCode::create(['doc_type' => $doc['docType'], 'doc_id' => $doc['docId'], 'tax_code_id' => $code->id, 'reason' => $reason ?: null, 'set_by' => $user->username, 'set_at' => now()]);
            }
            $this->audit->log($user, ['action' => 'TAX.DOCUMENT.CODE', 'entityType' => $doc['docType'], 'entityId' => $doc['docId'], 'entityNumber' => $doc['number'], 'oldValue' => $before, 'newValue' => ['code' => $code->code, 'reason' => $reason]]);

            return ['docType' => $doc['docType'], 'docId' => $doc['docId'], 'code' => $code->code, 'codeId' => $code->id, 'how' => 'explicit'];
        });
    }

    /** @return array<string, TaxCode> every code by id (read once per request) */
    public function codes(): array
    {
        return $this->codes ??= TaxCode::all()->keyBy('id')->all();
    }

    public function flush(): void
    {
        $this->codes = null;
    }
}
