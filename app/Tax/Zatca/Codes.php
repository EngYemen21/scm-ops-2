<?php

namespace App\Tax\Zatca;

/**
 * The code lists of the Saudi e-invoice (XML Implementation Standard v1.2 of 2023-05-19, §11.2 and rules BR-KSA-05,
 * -06, -08, -14, -16, -18): document types, VAT categories with their exemption reasons, the identification schemes
 * of a seller and of a buyer, and the three environments of the authority's services.
 */
final class Codes
{
    /** UN/CEFACT 1001 subset: what the document is. */
    public const TYPE = ['invoice' => '388', 'credit_note' => '381', 'debit_note' => '383', 'prepayment' => '386'];

    /** UN/CEFACT 5305 subset: the VAT category of a kind of tax. */
    public const CATEGORY = ['standard' => 'S', 'zero' => 'Z', 'exempt' => 'E', 'out_of_scope' => 'O'];

    /** code → [category, English text of the standard, Arabic]. VATEX-SA-OOS carries the taxpayer's own text. */
    public const EXEMPTIONS = [
        'VATEX-SA-29' => ['E', 'Financial services mentioned in Article 29 of the VAT Regulations', 'الخدمات المالية المذكورة في المادة 29 من اللائحة التنفيذية لضريبة القيمة المضافة'],
        'VATEX-SA-29-7' => ['E', 'Life insurance services mentioned in Article 29 of the VAT Regulations', 'عقود التأمين على الحياة المذكورة في المادة 29 من اللائحة التنفيذية لضريبة القيمة المضافة'],
        'VATEX-SA-30' => ['E', 'Real estate transactions mentioned in Article 30 of the VAT Regulations', 'التوريدات العقارية المذكورة في المادة 30 من اللائحة التنفيذية لضريبة القيمة المضافة'],
        'VATEX-SA-32' => ['Z', 'Export of goods', 'صادرات السلع من المملكة'],
        'VATEX-SA-33' => ['Z', 'Export of services', 'صادرات الخدمات من المملكة'],
        'VATEX-SA-34-1' => ['Z', 'The international transport of Goods', 'النقل الدولي للسلع'],
        'VATEX-SA-34-2' => ['Z', 'international transport of passengers', 'النقل الدولي للركاب'],
        'VATEX-SA-34-3' => ['Z', 'services directly connected and incidental to a Supply of international passenger transport', 'الخدمات المرتبطة مباشرة أو عرضيًا بتوريد النقل الدولي للركاب'],
        'VATEX-SA-34-4' => ['Z', 'Supply of a qualifying means of transport', 'توريد وسائل النقل المؤهلة'],
        'VATEX-SA-34-5' => ['Z', 'Any services relating to Goods or passenger transportation, as defined in article twenty five of these Regulations', 'الخدمات ذات الصلة بنقل السلع أو الركاب وفق المادة الخامسة والعشرين من اللائحة التنفيذية'],
        'VATEX-SA-35' => ['Z', 'Medicines and medical equipment', 'الأدوية والمعدات الطبية'],
        'VATEX-SA-36' => ['Z', 'Qualifying metals', 'المعادن المؤهلة'],
        'VATEX-SA-EDU' => ['Z', 'Private education to citizen', 'الخدمات التعليمية الخاصة للمواطنين'],
        'VATEX-SA-HEA' => ['Z', 'Private healthcare to citizen', 'الخدمات الصحية الخاصة للمواطنين'],
        'VATEX-SA-MLTRY' => ['Z', 'supply of qualified military goods', 'توريد السلع العسكرية المؤهلة'],
        'VATEX-SA-OOS' => ['O', 'Reason is free text, to be provided by the taxpayer on case to case basis', 'السبب نص يكتبه المكلَّف بحسب الحالة'],
    ];

    /** Identification of the seller besides the VAT number (BR-KSA-08), in the order the standard gives. */
    public const SELLER_IDS = ['CRN' => 'السجل التجاري', 'MOM' => 'رخصة وزارة الشؤون البلدية', 'MLS' => 'رخصة وزارة الموارد البشرية', '700' => 'الرقم الموحد 700', 'SAG' => 'رخصة وزارة الاستثمار', 'OTH' => 'معرّف آخر'];

    /** Identification of a buyer that has no VAT number (BR-KSA-14). */
    public const BUYER_IDS = ['TIN' => 'الرقم الضريبي المميز', 'CRN' => 'السجل التجاري', 'MOM' => 'رخصة وزارة الشؤون البلدية', 'MLS' => 'رخصة وزارة الموارد البشرية',
        '700' => 'الرقم الموحد 700', 'SAG' => 'رخصة وزارة الاستثمار', 'NAT' => 'الهوية الوطنية', 'GCC' => 'هوية مواطني دول الخليج', 'IQA' => 'الإقامة', 'PAS' => 'جواز السفر', 'OTH' => 'معرّف آخر'];

    /** UNTDID 4461 subset the standard accepts (BR-KSA-16). */
    public const PAYMENT_MEANS = ['10' => 'نقدًا', '30' => 'آجل / تحويل دائن', '42' => 'حساب بنكي', '48' => 'بطاقة بنكية', '1' => 'غير محدد'];

    /**
     * The three environments of the authority's e-invoicing services: the developer portal (a sandbox that needs no
     * registration), the simulation of the Fatoora portal, and production (`core`). Each has its own certificate
     * template name, which a certificate request must carry.
     */
    public const ENVIRONMENTS = [
        'sandbox' => ['path' => 'developer-portal', 'template' => 'TSTZATCA-Code-Signing', 'ar' => 'بوابة المطورين (تجريبية)', 'en' => 'Developer portal (sandbox)'],
        'simulation' => ['path' => 'simulation', 'template' => 'PREZATCA-Code-Signing', 'ar' => 'المحاكاة', 'en' => 'Simulation'],
        'production' => ['path' => 'core', 'template' => 'ZATCA-Code-Signing', 'ar' => 'الإنتاج', 'en' => 'Production'],
    ];

    public const GATEWAY = 'https://gw-fatoora.zatca.gov.sa/e-invoicing';

    /** The previous-invoice hash of the very first document of a unit: base64 of the SHA-256 of "0" (rule BR-KSA-26). */
    public const FIRST_PREVIOUS_HASH = 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==';

    /** The VAT number of a taxpayer of the Kingdom: fifteen digits, the first and the last being 3 (BR-KSA-40, -44). */
    public static function vatNumber(?string $v): bool
    {
        return is_string($v) && preg_match('/^3\d{13}3$/', $v) === 1;
    }

    public static function category(string $kind): ?string
    {
        return self::CATEGORY[$kind] ?? null;
    }

    /** May a tax of this category carry this reason code? */
    public static function reasonFits(?string $category, ?string $code): bool
    {
        return $code !== null && isset(self::EXEMPTIONS[$code]) && self::EXEMPTIONS[$code][0] === $category;
    }

    /** The reasons as the forms offer them. */
    public static function exemptions(): array
    {
        $out = [];
        foreach (self::EXEMPTIONS as $code => [$category, $en, $ar]) {
            $out[] = ['code' => $code, 'category' => $category, 'en' => $en, 'ar' => $ar];
        }

        return $out;
    }
}
