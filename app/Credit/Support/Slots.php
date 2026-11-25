<?php

namespace App\Credit\Support;

/**
 * The nine document slots of a credit request — Odoo's `file_<slot>` / `filename_<slot>` pairs of
 * `b2b.credit.request` — and the slot of the signed agreement, which is the signature's own (not one of the nine).
 * Every one of them is a personal or company paper: it is kept in `credit_files`, never in the generic attachments.
 */
final class Slots
{
    /** slot => [Arabic, English]; the slot is also the suffix of Odoo's field names. */
    public const DOCUMENTS = [
        'commercial_registration' => ['نسخة السجل التجاري', 'Commercial registration copy'],
        'vat_certificate' => ['شهادة ضريبة القيمة المضافة', 'VAT certificate'],
        'national_address' => ['وثيقة العنوان الوطني', 'National address document'],
        'chamber_certificate' => ['شهادة الغرفة التجارية', 'Chamber of commerce certificate'],
        'owner_id' => ['صورة هوية المالك', 'Owner ID copy'],
        'authorized_signatory_ids' => ['صور هويات المفوضين بالتوقيع', 'Authorized signatories\' ID copies'],
        'official_authorization' => ['تفويض التوقيع الرسمي', 'Official signing authorization'],
        'recent_bank_statement' => ['كشف حساب بنكي حديث', 'Recent bank statement'],
        'other' => ['مرفق آخر', 'Other attachment'],
    ];

    /** The scanned agreement the customer signed: added through the signature, shown with the envelope. */
    public const SIGNED_AGREEMENT = 'signed_agreement';

    /** A slot that may hold several papers at once; every other slot holds one — a new paper replaces the one before. */
    public const MANY = ['other'];

    /** What a stage that requires documents asks for unless the settings say otherwise: every named paper. */
    public const DEFAULT_REQUIRED = ['commercial_registration', 'vat_certificate', 'national_address', 'chamber_certificate', 'owner_id', 'authorized_signatory_ids', 'official_authorization', 'recent_bank_statement'];

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::DOCUMENTS);
    }

    public static function isDocument(?string $slot): bool
    {
        return $slot !== null && isset(self::DOCUMENTS[$slot]);
    }

    /** @return array{0:string, 1:string} [Arabic, English] */
    public static function label(string $slot): array
    {
        return $slot === self::SIGNED_AGREEMENT ? ['الاتفاقية الموقَّعة', 'Signed agreement'] : (self::DOCUMENTS[$slot] ?? [$slot, $slot]);
    }
}
