<?php

namespace App\Credit\Services;

use App\Credit\Models\CreditRequest;
use App\Credit\Models\CreditTermsVersion;
use App\Credit\Support\Text;
use App\Models\Customer;
use App\Services\Core\SettingsService;
use Illuminate\Support\Carbon;

/**
 * The deferred-credit agreement of a request, in Arabic and English side by side: the two parties, what is granted
 * (the approved limit, days and payment term — or what is asked for while nothing is approved yet), how the customer
 * pays, who signs for it, and the terms.
 *
 * THE TERMS ARE FROZEN. The agreement prints the version the customer accepted, as it was kept on the request at that
 * moment (`terms_snapshot`) — never the version in force today. A request whose customer has not accepted any terms
 * yet shows the version in force as a preview, marked as such.
 *
 * `fingerprint()` is the proof of what an agreement says: the facts a signature covers. An envelope keeps the
 * fingerprint of the agreement it carried; when a signed agreement no longer matches the request (the limit was
 * changed after the customer signed), the signature is stale and the request cannot pass a stage that requires one.
 *
 * No identity number and no bank account stands in the agreement: it names the company by its registration and the
 * signatories by name and position.
 */
class AgreementService
{
    public function __construct(private readonly SettingsService $settings) {}

    /** The current published version: the latest effective date that has come, as the business counts days. */
    public static function termsInForce(): ?CreditTermsVersion
    {
        $today = now((string) config('app.business_timezone', 'UTC'))->toDateString();

        return CreditTermsVersion::where('status', 'published')->whereDate('effective_date', '<=', $today)
            ->orderByDesc('effective_date')->orderByDesc('published_at')->orderByDesc('id')->first();
    }

    /** What is granted: the approved figures once somebody wrote them, otherwise what the customer asks for. */
    public static function figures(CreditRequest $r): array
    {
        $approved = $r->approved_credit_limit !== null && (float) $r->approved_credit_limit > 0;
        $days = $approved && $r->approved_payment_days !== null ? (int) $r->approved_payment_days : ($r->requested_payment_days !== null ? (int) $r->requested_payment_days : null);

        return [
            'approved' => $approved, 'limit' => Text::money($approved ? $r->approved_credit_limit : $r->requested_credit_limit), 'days' => $days,
            'term' => $approved && $r->approved_term ? $r->approved_term : ($days !== null ? CustomerCredit::termsFor($days) : null), 'currency' => $r->currency ?: 'SAR',
        ];
    }

    /** The proof of what the agreement of this request says now. */
    public function fingerprint(CreditRequest $r): string
    {
        $f = self::figures($r);

        return hash('sha256', json_encode([
            'customer' => (string) $r->customer_id, 'company' => (string) $r->company_name, 'registration' => (string) $r->commercial_registration,
            'limit' => $f['limit'], 'days' => $f['days'], 'term' => $f['term'], 'currency' => $f['currency'], 'terms' => (string) ($r->terms_snapshot['hash'] ?? ''),
            'signatories' => $r->signatories()->get()->map(fn ($s) => (string) $s->name)->all(),
        ], JSON_UNESCAPED_UNICODE));
    }

    /** The agreement as data — what the client prints and what the HTML below is made of. */
    public function build(CreditRequest $r): array
    {
        $company = (array) $this->settings->get('company');
        $customer = $r->customer_id ? Customer::find($r->customer_id) : null;
        $snapshot = $r->terms_snapshot ?: null;
        $preview = null;
        if (! $snapshot && ($inForce = self::termsInForce())) {
            $preview = $inForce->snapshot();
        }
        $terms = $snapshot ?? $preview;

        return [
            'number' => $r->number, 'date' => $r->request_date?->toDateString(), 'state' => $r->state,
            'company' => ['nameAr' => (string) ($company['nameAr'] ?? ''), 'nameEn' => (string) ($company['nameEn'] ?? '')],
            'customer' => [
                'code' => $customer?->code, 'nameAr' => $customer?->name_ar ?? $r->company_name ?? $r->partner_name, 'nameEn' => $customer?->name_en ?? $r->company_name ?? $r->partner_name,
                'companyName' => $r->company_name, 'commercialRegistration' => $r->commercial_registration, 'legalEntityType' => $r->legal_entity_type, 'businessActivity' => $r->business_activity,
                'ownerName' => $r->owner_name, 'street' => $r->street, 'district' => $r->district, 'buildingNo' => $r->building_no, 'city' => $r->city, 'postalCode' => $r->postal_code, 'poBox' => $r->po_box,
                'phone' => $r->phone, 'mobile' => $r->mobile, 'email' => $r->email,
            ],
            'credit' => self::figures($r) + ['monthlyPurchaseVolume' => Text::money($r->monthly_purchase_volume)],
            'paymentMethods' => $r->paymentMethods()->get()->map(fn ($m) => ['code' => $m->code, 'nameAr' => $m->name_ar, 'nameEn' => $m->name_en])->all(),
            'signatories' => $r->signatories()->get()->map(fn ($s) => ['name' => $s->name, 'jobTitle' => $s->job_title])->all(),
            'terms' => $terms ? [
                'version' => $terms['version'] ?? null, 'name' => $terms['name'] ?? null, 'effectiveDate' => $terms['effectiveDate'] ?? null, 'jurisdictionCity' => $terms['jurisdictionCity'] ?? null,
                'termsAr' => (string) ($terms['termsAr'] ?? ''), 'termsEn' => (string) ($terms['termsEn'] ?? ''), 'hash' => $terms['hash'] ?? null,
                // frozen = the version this customer accepted, kept on the request; a preview is only the version in force today
                'frozen' => $snapshot !== null, 'acceptedAt' => Text::at($r->terms_accepted_at), 'acceptedBy' => $r->terms_accepted_by,
            ] : null,
            'signature' => ['status' => $r->signature_status ?: 'none'],
            'fingerprint' => $this->fingerprint($r),
        ];
    }

    /** The agreement as one printable page: Arabic on the right, English on the left, clause beside clause. */
    public function html(array $a): string
    {
        $e = fn (mixed $v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $dash = fn (mixed $v) => $v === null || $v === '' ? '—' : $e($v);
        $row = fn (string $ar, string $en) => '<tr><td class="ar" dir="rtl" lang="ar">'.$ar.'</td><td class="en" dir="ltr" lang="en">'.$en.'</td></tr>';
        $head = fn (string $ar, string $en) => $row("<h2>{$e($ar)}</h2>", "<h2>{$e($en)}</h2>");
        $kv = fn (string $ar, string $en, mixed $v, mixed $vEn = null) => $row("<p><b>{$e($ar)}:</b> {$dash($v)}</p>", "<p><b>{$e($en)}:</b> {$dash($vEn ?? $v)}</p>");
        $c = $a['customer'];
        $k = $a['credit'];
        $address = implode('، ', array_filter([$c['buildingNo'], $c['street'], $c['district'], $c['city'], $c['postalCode']]));
        $date = $a['date'] ? Carbon::parse($a['date'])->format('Y-m-d') : null;
        $out = [];
        $out[] = $row('<h1>اتفاقية حساب ائتمان آجل</h1><p class="ref">'.$e($a['number']).($date ? ' — '.$e($date) : '').'</p>',
            '<h1>Deferred Credit Account Agreement</h1><p class="ref">'.$e($a['number']).($date ? ' — '.$e($date) : '').'</p>');
        $out[] = $head('أولًا: الطرفان', '1. The parties');
        $out[] = $kv('الطرف الأول (المورِّد)', 'First party (the supplier)', $a['company']['nameAr'], $a['company']['nameEn']);
        $out[] = $kv('الطرف الثاني (العميل)', 'Second party (the customer)', $c['companyName'] ?: $c['nameAr'], $c['companyName'] ?: $c['nameEn']);
        $out[] = $kv('السجل التجاري', 'Commercial registration', $c['commercialRegistration']);
        $out[] = $kv('الكيان القانوني', 'Legal entity', $c['legalEntityType']);
        $out[] = $kv('النشاط', 'Business activity', $c['businessActivity']);
        $out[] = $kv('المالك', 'Owner', $c['ownerName']);
        $out[] = $kv('العنوان', 'Address', $address);
        $out[] = $head('ثانيًا: التسهيل الائتماني', '2. The credit facility');
        $limit = $k['limit'] !== null ? number_format((float) $k['limit'], 2).' '.$k['currency'] : null;
        $out[] = $kv($k['approved'] ? 'الحد الائتماني المعتمد' : 'الحد الائتماني المطلوب', $k['approved'] ? 'Approved credit limit' : 'Requested credit limit', $limit);
        $out[] = $kv('مدة السداد (يوم)', 'Payment period (days)', $k['days']);
        $out[] = $kv('شرط السداد', 'Payment term', $k['term'], $k['days'] !== null ? "Net {$k['days']} days" : $k['term']);
        $out[] = $kv('طرق السداد المتعاقد عليها', 'Contractual payment methods', implode('، ', array_column($a['paymentMethods'], 'nameAr')), implode(', ', array_column($a['paymentMethods'], 'nameEn')));
        $out[] = $head('ثالثًا: الشروط والأحكام', '3. Terms and conditions');
        if ($t = $a['terms']) {
            $note = $t['frozen'] ? ['النسخة التي قبلها العميل', 'The version the customer accepted'] : ['النسخة السارية حاليًا — لم يقبلها العميل بعد', 'The version in force — not accepted by the customer yet'];
            $out[] = $row('<p class="ref">'.$e($note[0]).': '.$e($t['version']).($t['effectiveDate'] ? ' — '.$e($t['effectiveDate']) : '').'</p>',
                '<p class="ref">'.$e($note[1]).': '.$e($t['version']).($t['effectiveDate'] ? ' — '.$e($t['effectiveDate']) : '').'</p>');
            $ar = self::clauses($t['termsAr']);
            $en = self::clauses($t['termsEn']);
            for ($i = 0, $n = max(count($ar), count($en)); $i < $n; $i++) {
                $out[] = $row('<p>'.nl2br($e($ar[$i] ?? '')).'</p>', '<p>'.nl2br($e($en[$i] ?? '')).'</p>');
            }
            if ($t['jurisdictionCity']) {
                $out[] = $kv('الاختصاص القضائي', 'Jurisdiction', $t['jurisdictionCity']);
            }
        } else {
            $out[] = $row('<p>لا توجد نسخة شروط منشورة.</p>', '<p>No terms version is published.</p>');
        }
        $out[] = $head('رابعًا: التوقيعات', '4. Signatures');
        $signers = $a['signatories'] ?: [['name' => null, 'jobTitle' => null]];
        foreach ($signers as $s) {
            $line = fn (string $name, string $title, string $sign, string $day) => "<p><b>{$name}:</b> {$dash($s['name'])} &nbsp; <b>{$title}:</b> {$dash($s['jobTitle'])}</p><p class=\"sign\">{$sign}: ____________________ &nbsp; {$day}: ____________</p>";
            $out[] = $row($line('المفوَّض بالتوقيع عن العميل', 'الصفة', 'التوقيع', 'التاريخ'), $line('Authorised signatory of the customer', 'Position', 'Signature', 'Date'));
        }
        $out[] = $row('<p><b>عن الطرف الأول:</b> '.$dash($a['company']['nameAr']).'</p><p class="sign">التوقيع: ____________________ &nbsp; التاريخ: ____________</p>',
            '<p><b>For the first party:</b> '.$dash($a['company']['nameEn']).'</p><p class="sign">Signature: ____________________ &nbsp; Date: ____________</p>');

        return '<!doctype html><html lang="ar"><head><meta charset="utf-8"><title>'.$e($a['number']).'</title><style>'
            .'body{font-family:"Segoe UI",Tahoma,Arial,sans-serif;font-size:12px;color:#111;margin:24px}table{width:100%;border-collapse:collapse;table-layout:fixed}'
            .'td{width:50%;vertical-align:top;padding:4px 10px}td.ar{text-align:right;border-inline-start:1px solid #ccc}td.en{text-align:left}'
            .'h1{font-size:17px;margin:0 0 4px}h2{font-size:13px;margin:12px 0 2px;border-bottom:1px solid #999}p{margin:3px 0;line-height:1.55}.ref{color:#555}.sign{margin-top:14px}'
            .'@page{size:A4;margin:14mm}tr{page-break-inside:avoid}</style></head><body><table>'.implode('', $out).'</table>'
            .'<p class="ref" dir="ltr" style="margin-top:14px;font-size:10px">'.$e($a['fingerprint']).'</p></body></html>';
    }

    /** The clauses of a terms text: one per paragraph (a blank line, or a new line, starts the next). @return string[] */
    private static function clauses(string $text): array
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') {
            return [];
        }
        $parts = preg_split(str_contains($text, "\n\n") ? "/\n{2,}/" : "/\n/", $text) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn ($p) => $p !== ''));
    }
}
