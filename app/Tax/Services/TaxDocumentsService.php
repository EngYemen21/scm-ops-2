<?php

namespace App\Tax\Services;

use App\Services\Core\SettingsService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Tax\Models\TaxDocumentCode;
use App\Tax\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * The tax code of one OPS document: what it is of today and why, and a person's decision to give it another.
 * The documents themselves belong to their modules and are only read here.
 */
class TaxDocumentsService
{
    public function __construct(private readonly Classifier $classifier, private readonly SettingsService $settings) {}

    public function show(string $docType, string $idOrNumber): array
    {
        $doc = $this->facts($docType, $idOrNumber);
        $hit = $this->classifier->of($doc['docType'], $doc['docId'], $doc['rate'], $doc['partnerType'], $doc['partnerId'], $doc['parentId']);
        $said = TaxDocumentCode::where('doc_type', $doc['docType'])->where('doc_id', $doc['docId'])->first();
        $declared = DB::table('tax_return_entries as e')->join('tax_returns as r', 'r.id', '=', 'e.return_id')->where('e.doc_type', $doc['docType'])->where('e.doc_id', $doc['docId'])
            ->where('r.status', '!=', 'cancelled')->orderBy('r.period_from')->get(['r.id', 'r.period_code', 'r.status'])->unique('id')->values();

        return [
            'docType' => $doc['docType'], 'docId' => $doc['docId'], 'number' => $doc['number'], 'use' => $doc['use'], 'rate' => $doc['rate'],
            'code' => $hit['code'] ? ['id' => $hit['code']->id, 'code' => $hit['code']->code, 'nameAr' => $hit['code']->name_ar, 'nameEn' => $hit['code']->name_en, 'kind' => $hit['code']->kind, 'returnBox' => $hit['code']->return_box] : null,
            'how' => $hit['how'],
            'decision' => $said ? ['codeId' => $said->tax_code_id, 'reason' => $said->reason, 'setBy' => $said->set_by, 'setAt' => $said->set_at?->toJSON()] : null,
            'returns' => $declared->map(fn ($r) => ['id' => $r->id, 'periodCode' => $r->period_code, 'status' => $r->status])->all(),
        ];
    }

    public function assign(AuthUser $user, string $docType, string $idOrNumber, ?string $codeId, ?string $reason): array
    {
        $doc = $this->facts($docType, $idOrNumber);
        $this->classifier->assign($user, ['docType' => $doc['docType'], 'docId' => $doc['docId'], 'number' => $doc['number'], 'rate' => $doc['rate']], $codeId ?: null, $reason);
        $this->classifier->flush();

        return $this->show($doc['docType'], $doc['docId']);
    }

    /** @return array{docType:string, docId:string, number:string, use:string, rate:string, partnerType:?string, partnerId:?string, parentId:?string} */
    private function facts(string $docType, string $idOrNumber): array
    {
        $use = Classifier::DOC_TYPES[$docType] ?? throw AppError::validation('TAX_DOC_TYPE', 'نوع المستند لا يحمل رمزًا ضريبيًا', 'This kind of document carries no tax code');
        $row = DB::table(VatSources::SOURCES[$docType])->where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->first()
            ?? throw AppError::notFound('TAX_DOCUMENT_NOT_FOUND', "المستند {$idOrNumber} غير موجود", "Document {$idOrNumber} not found");
        // a voucher or a fleet document has VAT only when its journal put some on the VAT accounts
        $posted = fn () => isset($row->journal_id) && $row->journal_id && DB::table('fin_journal_lines as l')->join('fin_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.journal_id', $row->journal_id)->where(fn ($w) => $w->whereIn('a.role', ['vat_input', 'vat_output'])->orWhere('a.subtype', 'tax'))->exists();
        $rate = match ($docType) {
            'CustomerInvoice', 'SupplierInvoice', 'DebitNote' => Money::rate($row->vat_pct),
            'Expense' => Money::minor($row->vat_amount) !== 0 ? Money::rate($row->vat_pct) : '0',
            default => $posted() ? Money::rate($this->settings->get('sales.vatPct') ?? 15) : '0',
        };

        return ['docType' => $docType, 'docId' => (string) $row->id, 'number' => (string) $row->number, 'use' => $use, 'rate' => $rate,
            'partnerType' => $use === 'sale' ? 'customer' : (isset($row->supplier_id) && $row->supplier_id ? 'supplier' : null),
            'partnerId' => $use === 'sale' ? (string) $row->customer_id : (isset($row->supplier_id) && $row->supplier_id ? (string) $row->supplier_id : null),
            'parentId' => $docType === 'CustomerInvoice' && ($row->kind ?? null) === 'credit_note' && ! empty($row->credit_of_id) ? (string) $row->credit_of_id : null];
    }
}
