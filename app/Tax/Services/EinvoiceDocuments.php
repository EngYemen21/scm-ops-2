<?php

namespace App\Tax\Services;

use App\Finance\Models\CustomerInvoice;
use App\Finance\Models\CustomerInvoiceLine;
use App\Models\Customer;
use App\Support\AppError;
use App\Tax\Models\Einvoice;
use App\Tax\Models\EinvoiceUnit;
use App\Tax\Models\TaxParty;
use App\Tax\Models\TaxProfile;
use App\Tax\Support\Money;
use App\Tax\Zatca\Codes;
use App\Tax\Zatca\Qr;
use Illuminate\Support\Carbon;

/**
 * From an OPS customer invoice or credit note to the neutral description `Ubl` prints, with the list of what the
 * document lacks against the authority's rules (`issues`: errors keep it from being SENT, warnings are to be read).
 *
 * THE FIGURES ARE THE INVOICE'S OWN. Net, VAT and total are the amounts finance posted — nothing is recomputed, so the
 * e-invoice and the books say the same to the halala. Per line the XML carries quantity, price, the discount as the
 * difference between quantity × price and the line's net, and the line's VAT at the invoice's rate.
 *
 * WHICH DOCUMENTS. OPS issues tax invoices and — when goods of an invoiced delivery come back — credit notes
 * (`fin_customer_invoices`). Its "debit note" is a SUPPLIER debit note: there OPS is the buyer and the tax document is
 * the supplier's credit note, so it is not an e-invoice of ours. `Ubl` prints a debit note (383) all the same; the day
 * OPS has a customer debit note it only needs its description here.
 *
 * STANDARD OR SIMPLIFIED. What the customer's tax details say; left on "auto": a customer with a VAT number, a
 * commercial registration or another identification is a business and gets a standard invoice (cleared), anyone else
 * a simplified one (reported). A credit note is of the kind of the invoice it credits.
 */
class EinvoiceDocuments
{
    public const TIMEZONE = 'Asia/Riyadh';

    public function __construct(private readonly TaxProfileService $profiles, private readonly Classifier $classifier) {}

    /**
     * @return array{document: array<string, mixed>, issues: array<int, array{level:string, code:string, ar:string, en:string}>, facts: array<string, mixed>, qrBasic: array<string, string>}
     *
     * @throws AppError when the document cannot be made at all (the taxpayer has no name / VAT number yet, or a sale without VAT has no tax code that says why)
     */
    public function fromInvoice(CustomerInvoice $inv, EinvoiceUnit $unit): array
    {
        $profile = $this->profiles->profile();
        if (! $profile->canIssue()) {
            throw AppError::rule('EINVOICE_SELLER_PROFILE', 'أكمل بيانات المنشأة الضريبية أولًا (الاسم النظامي والرقم الضريبي) — لا تُصدر فاتورة إلكترونية بدونهما',
                'Complete the taxpayer profile first (legal name and VAT number) — no e-invoice is made without them');
        }
        $customer = Customer::find($inv->customer_id) ?? throw AppError::rule('EINVOICE_NO_CUSTOMER', "الفاتورة {$inv->number} بلا عميل", "Invoice {$inv->number} has no customer");
        $party = TaxParty::where('partner_type', 'customer')->where('partner_id', $customer->id)->first();
        $credits = $inv->kind === 'credit_note' && $inv->credit_of_id ? CustomerInvoice::find($inv->credit_of_id) : null;
        $rate = Money::rate($inv->vat_pct);
        $hit = $this->classifier->of('CustomerInvoice', (string) $inv->id, $rate, 'customer', (string) $customer->id, $credits?->id);
        $code = $hit['code'];
        $category = $code ? Codes::category($code->kind) : (bccomp($rate, '0', 4) > 0 ? 'S' : null);
        if ($category === null) {
            throw AppError::rule('EINVOICE_TAX_CODE_REQUIRED', "الفاتورة {$inv->number} بلا ضريبة ولا رمز ضريبي يبيّن السبب (صفرية، صادرات، معفاة، خارج النطاق) — حدّد رمزها الضريبي أولًا",
                "Invoice {$inv->number} carries no VAT and no tax code says why (zero-rated, export, exempt, out of scope) — give it a tax code first", ['docType' => 'CustomerInvoice', 'docId' => $inv->id]);
        }
        $reasonCode = $category === 'S' ? null : $code?->exemption_reason_code;
        if ($category !== 'S' && ! Codes::reasonFits($category, $reasonCode)) {
            throw AppError::rule('EINVOICE_EXEMPTION_REASON', "الرمز الضريبي {$code?->code} بلا سبب إعفاء من قائمة الهيئة — أكمله في الرموز الضريبية",
                "Tax code {$code?->code} has no exemption reason of the authority's list — complete it in the tax codes", ['code' => $code?->code]);
        }
        $reason = $category === 'S' ? null : ($reasonCode === 'VATEX-SA-OOS' ? ($code->exemption_reason_ar ?: $code->exemption_reason_en) : Codes::EXEMPTIONS[$reasonCode][1]);

        // ── standard or simplified
        $buyerVat = Codes::vatNumber($customer->vat_no) ? (string) $customer->vat_no : null;
        $idScheme = $party?->id_scheme ?: (trim((string) $customer->cr) !== '' && preg_match('/^[A-Za-z0-9]+$/', trim((string) $customer->cr)) ? 'CRN' : null);
        $idValue = $party?->id_value ?: ($idScheme === 'CRN' ? trim((string) $customer->cr) : null);
        $earlier = $credits ? Einvoice::where('doc_type', 'CustomerInvoice')->where('doc_id', $credits->id)->where('is_current', true)->value('profile') : null;
        $profileKind = $earlier ?: (in_array($party?->invoice_kind, ['standard', 'simplified'], true) ? $party->invoice_kind : ($buyerVat || $idValue ? 'standard' : 'simplified'));
        $export = $code !== null && $code->return_box === 4;

        // ── the document
        $issued = Carbon::parse($inv->created_at ?? now())->copy()->setTimezone(self::TIMEZONE);
        $lines = [];
        $lineNet = 0;
        foreach (CustomerInvoiceLine::with('product:id,sku,name_ar,name_en')->where('invoice_id', $inv->id)->orderBy('line_no')->get() as $l) {
            $net = Money::minor($l->line_total);
            $gross = (int) $l->qty * Money::minor($l->price);
            $lineNet += $net;
            $lines[] = [
                'id' => (int) $l->line_no, 'name' => (string) ($l->product?->name_ar ?: $l->product?->name_en ?: $l->product?->sku ?: "#{$l->line_no}"), 'sku' => $l->product?->sku, 'qty' => (int) $l->qty, 'unit' => 'PCE',
                'price' => (string) $l->price, 'discount' => max(0, $gross - $net), 'charge' => max(0, $net - $gross), 'net' => $net, 'vat' => Money::percent($net, $rate), 'category' => $category, 'rate' => $rate,
            ];
        }
        $allowance = Money::minor($inv->discount);
        $vat = Money::minor($inv->vat_amount);
        $total = Money::minor($inv->total);
        $taxable = Money::minor($inv->subtotal) - $allowance;
        $seller = self::seller($profile);
        $buyer = array_filter([
            'name' => $customer->name_ar ?: $customer->name_en, 'vatNo' => $export ? null : $buyerVat, 'idScheme' => $buyerVat && ! $export ? null : $idScheme, 'idValue' => $buyerVat && ! $export ? null : $idValue,
            'street' => $party?->street ?: ($customer->address ?: null), 'buildingNo' => $party?->building_no, 'additionalNo' => $party?->additional_no, 'district' => $party?->district ?: ($customer->zone ?: null),
            'city' => $party?->city ?: ($customer->city ?: null), 'postalCode' => $party?->postal_code, 'country' => $party?->country_code ?: 'SA',
        ], fn ($v) => $v !== null && $v !== '');
        $qrBasic = ['seller' => $seller['name'], 'vatNo' => $seller['vatNo'], 'timestamp' => $issued->format('Y-m-d\TH:i:s'), 'total' => Money::text($total), 'vat' => Money::text($vat)];
        $document = [
            'kind' => $inv->kind === 'credit_note' ? 'credit_note' : 'invoice', 'profile' => $profileKind, 'flags' => ['export' => $export], 'number' => (string) $inv->number,
            'issueDate' => $issued->format('Y-m-d'), 'issueTime' => $issued->format('H:i:s'), 'deliveryDate' => $inv->invoice_date?->format('Y-m-d'), 'currency' => 'SAR',
            'billingReference' => $credits?->number, 'reason' => $inv->kind === 'credit_note' ? (trim((string) $inv->notes) ?: 'مرتجع مبيعات') : null,
            'paymentMeans' => (int) ($customer->payment_terms_days ?? 0) > 0 ? '30' : '10',
            'seller' => $seller, 'buyer' => $buyer, 'lines' => $lines,
            'tax' => [['category' => $category, 'rate' => $rate, 'taxable' => $taxable, 'vat' => $vat, 'reasonCode' => $reasonCode, 'reason' => $reason]],
            'totals' => ['lineNet' => $lineNet, 'allowance' => $allowance, 'taxable' => $taxable, 'vat' => $vat, 'total' => $total, 'prepaid' => 0, 'payable' => $total],
            'qr' => Qr::encode($qrBasic),
        ];

        return [
            'document' => $document,
            'issues' => self::check($document, $unit, $profile),
            'qrBasic' => $qrBasic,
            'facts' => ['docType' => 'CustomerInvoice', 'docId' => (string) $inv->id, 'docNumber' => (string) $inv->number, 'docKind' => $document['kind'], 'profile' => $profileKind, 'issueAt' => $issued->copy()->utc(),
                'customerId' => (string) $customer->id, 'buyerName' => mb_substr((string) $buyer['name'], 0, 190), 'buyerVat' => $buyerVat, 'net' => $taxable, 'vat' => $vat, 'total' => $total,
                'code' => $code?->code, 'classified' => $hit['how']],
        ];
    }

    /** @return array<string, ?string> */
    public static function seller(TaxProfile $p): array
    {
        return [
            'name' => (string) $p->legal_name_ar, 'vatNo' => (string) $p->vat_no, 'idScheme' => $p->id_value ? ($p->id_scheme ?: 'CRN') : null, 'idValue' => $p->id_value, 'street' => $p->street,
            'buildingNo' => $p->building_no, 'additionalNo' => $p->additional_no, 'district' => $p->district, 'city' => $p->city, 'postalCode' => $p->postal_code, 'province' => $p->province, 'country' => $p->country_code ?: 'SA',
        ];
    }

    /**
     * The document against the authority's business rules that data can break (XML Implementation Standard v1.2, §13.3;
     * the rule each finding names is the standard's). A finding of level `error` keeps the document from being sent.
     *
     * @return array<int, array{level:string, code:string, ar:string, en:string}>
     */
    public static function check(array $d, ?EinvoiceUnit $unit = null, ?TaxProfile $profile = null): array
    {
        $out = [];
        $add = function (bool $broken, string $level, string $code, string $ar, string $en) use (&$out) {
            if ($broken) {
                $out[] = ['level' => $level, 'code' => $code, 'ar' => $ar, 'en' => $en];
            }
        };
        $has = fn (array $p, string $k) => isset($p[$k]) && trim((string) $p[$k]) !== '';
        $s = $d['seller'];
        $b = $d['buyer'] ?? [];
        $standard = $d['profile'] === 'standard';
        $export = ! empty($d['flags']['export']);
        // seller
        $add(! Codes::vatNumber($s['vatNo'] ?? null), 'error', 'BR-KSA-40', 'الرقم الضريبي للبائع 15 رقمًا يبدأ وينتهي بـ 3', 'The seller VAT number must be 15 digits, first and last 3');
        $add(! $has($s, 'idValue') || ! isset(Codes::SELLER_IDS[$s['idScheme'] ?? '']), 'error', 'BR-KSA-08', 'معرّف البائع (السجل التجاري أو غيره) ناقص', 'The seller identification (commercial registration or another) is missing');
        $add(! $has($s, 'street') || ! $has($s, 'city') || ! $has($s, 'district') || ! $has($s, 'country'), 'error', 'BR-KSA-09', 'عنوان البائع ناقص (الشارع، الحي، المدينة)', 'The seller address is incomplete (street, district, city)');
        $add(! preg_match('/^\d{4}$/', (string) ($s['buildingNo'] ?? '')), 'error', 'BR-KSA-37', 'رقم مبنى البائع أربعة أرقام', 'The seller building number must have 4 digits');
        $add(! preg_match('/^\d{5}$/', (string) ($s['postalCode'] ?? '')), 'error', 'BR-KSA-66', 'الرمز البريدي للبائع خمسة أرقام', 'The seller postal code must have 5 digits');
        // buyer
        if ($standard) {
            $add(! $has($b, 'name'), 'error', 'BR-KSA-42', 'اسم المشتري إلزامي في الفاتورة الضريبية', 'The buyer name is required on a standard invoice');
            $add(! $has($b, 'street') || ! $has($b, 'city') || ! $has($b, 'country'), 'error', 'BR-KSA-10', 'عنوان المشتري يحتاج الشارع والمدينة والدولة — أكمل البيانات الضريبية للعميل', 'The buyer address needs street, city and country — complete the customer\'s tax details');
            if (($b['country'] ?? 'SA') === 'SA') {
                $add(! $has($b, 'buildingNo') || ! $has($b, 'district') || ! preg_match('/^\d{5}$/', (string) ($b['postalCode'] ?? '')), 'warning', 'BR-KSA-63',
                    'عنوان مشترٍ داخل المملكة: رقم المبنى والحي والرمز البريدي (5 أرقام) ناقصة', 'A buyer in the Kingdom: building number, district and a 5-digit postal code are missing');
            }
            $add(! $export && ! $has($b, 'vatNo') && ! $has($b, 'idValue'), 'warning', 'BR-KSA-14', 'المشتري بلا رقم ضريبي ولا معرّف آخر', 'The buyer has neither a VAT number nor another identification');
            $add($d['kind'] === 'invoice' && empty($d['deliveryDate']), 'error', 'BR-KSA-15', 'تاريخ التوريد إلزامي في الفاتورة الضريبية', 'The supply date is required on a standard invoice');
        }
        $add($has($b, 'vatNo') && ! $export && ! Codes::vatNumber($b['vatNo']), 'error', 'BR-KSA-44', 'الرقم الضريبي للمشتري 15 رقمًا يبدأ وينتهي بـ 3', 'The buyer VAT number must be 15 digits, first and last 3');
        $add($export && $has($b, 'vatNo'), 'error', 'BR-KSA-46', 'فاتورة تصدير لا تحمل رقمًا ضريبيًا للمشتري', 'An export invoice must not carry a buyer VAT number');
        $add($export && ! $standard, 'error', 'BR-KSA-07', 'التصدير يكون بفاتورة ضريبية لا مبسطة', 'An export is a standard invoice, never a simplified one');
        // notes
        if ($d['kind'] !== 'invoice') {
            $add(empty($d['billingReference']), 'error', 'BR-KSA-56', 'الإشعار يحتاج رقم الفاتورة الأصلية', 'A credit / debit note needs the number of the invoice it refers to');
            $add(empty($d['reason']), 'error', 'BR-KSA-17', 'الإشعار يحتاج سبب إصداره', 'A credit / debit note needs the reason it is issued for');
        }
        // tax
        foreach ($d['tax'] as $t) {
            $add($t['category'] !== 'S' && empty($t['reasonCode']), 'error', $t['category'] === 'Z' ? 'BR-KSA-69' : ($t['category'] === 'E' ? 'BR-KSA-23' : 'BR-KSA-24'), 'فئة بلا ضريبة تحتاج رمز سبب الإعفاء', 'A category without VAT needs its exemption reason code');
            $add(in_array($t['reasonCode'] ?? null, ['VATEX-SA-EDU', 'VATEX-SA-HEA'], true) && ($b['idScheme'] ?? null) !== 'NAT', 'error', 'BR-KSA-49', 'خدمات التعليم أو الصحة للمواطنين تحتاج الهوية الوطنية للمشتري', 'Education / healthcare to citizens needs the buyer\'s national ID');
            $expected = Money::percent($t['taxable'], $t['rate']);
            $add(abs($t['vat'] - $expected) > 100, 'error', 'BR-CO-17', 'ضريبة الفئة لا تساوي وعاءها × النسبة', 'The VAT of the category is not its taxable amount × rate');
            $add($t['vat'] !== $expected && abs($t['vat'] - $expected) <= 100, 'warning', 'BR-CO-17', 'ضريبة الفاتورة تختلف بهللات عن وعائها × النسبة (تقريب)', 'The invoice VAT differs by halalas from taxable × rate (rounding)');
        }
        // arithmetic
        $t = $d['totals'];
        $add($t['taxable'] !== $t['lineNet'] - $t['allowance'], 'error', 'BR-CO-13', 'مجموع البنود لا يساوي صافي الفاتورة', 'The sum of the lines is not the net of the invoice');
        $add($t['total'] !== $t['taxable'] + $t['vat'], 'error', 'BR-CO-15', 'الصافي + الضريبة لا يساوي الإجمالي', 'Net + VAT is not the total');
        $add(! $d['lines'], 'error', 'BR-16', 'الفاتورة بلا بنود', 'The invoice has no lines');
        // the unit
        if ($unit) {
            $add(($unit->invoice_types[$standard ? 0 : 1] ?? '0') !== '1', 'error', 'EINVOICE_UNIT_TYPE', $standard ? 'الوحدة غير مهيأة للفواتير الضريبية' : 'الوحدة غير مهيأة للفواتير المبسطة',
                $standard ? 'The unit is not set up for standard invoices' : 'The unit is not set up for simplified invoices');
        }
        $add(strlen((string) ($d['qr'] ?? '')) > Qr::LIMIT, 'warning', 'KSA-14', 'رمز الاستجابة السريعة يتجاوز 700 حرف', 'The QR text is longer than 700 characters');

        return $out;
    }
}
