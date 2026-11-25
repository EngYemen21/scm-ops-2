<?php

namespace App\Banking\Support;

use App\Support\AuthUser;

/**
 * Bank account numbers and IBANs. They are shown in full only to a user who works the banks — who holds
 * `bankstatement.manage`, `bank.reconcile` or finance's `bank.manage` — and as their last four characters to everyone
 * else who may open the section. They are never written to a log, an audit value or a message.
 */
final class Mask
{
    public const PERMISSIONS = ['bankstatement.manage', 'bank.reconcile', 'bank.manage'];

    /** An IBAN inside free text: two letters, two check digits, then 11–30 letters or digits, in one piece or in groups. */
    private const IBAN = '/(?<![A-Za-z0-9])[A-Za-z]{2}\d{2}(?:[ \x{00A0}-]?[A-Za-z0-9]{4}){2,7}(?:[ \x{00A0}-]?[A-Za-z0-9]{1,3})?(?![A-Za-z0-9])/u';

    /** May this user read account numbers in full? */
    public static function sees(?AuthUser $user): bool
    {
        if (! $user) {
            return false;
        }
        foreach (self::PERMISSIONS as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    /** An account number / IBAN as this reader may see it: itself, or "••••1234". */
    public static function number(?string $number, bool $full): ?string
    {
        $number = $number === null ? null : trim($number);
        if ($number === null || $number === '') {
            return null;
        }
        if ($full) {
            return $number;
        }
        $key = self::key($number) ?? '';

        return '••••'.(mb_strlen($key) > 4 ? mb_substr($key, -4) : '');
    }

    /** Free text (a bank's narrative) with every IBAN inside it reduced to its last four characters for a reader who may not see them. */
    public static function text(?string $text, bool $full): ?string
    {
        if ($text === null || $text === '' || $full) {
            return $text;
        }

        return preg_replace_callback(self::IBAN, fn (array $m) => self::number($m[0], false), $text) ?? $text;
    }

    /**
     * What an account number is compared by: its letters and digits, upper case — "sa03 8000 0000 6080 1016 7519" and
     * "SA0380000000608010167519" are the same account.
     */
    public static function key(?string $number): ?string
    {
        if ($number === null) {
            return null;
        }
        $key = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', self::latinDigits($number)));

        return $key === '' ? null : $key;
    }

    /** Does the key look like an IBAN (country, check digits, the account)? */
    public static function isIban(?string $key): bool
    {
        return $key !== null && (bool) preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $key);
    }

    /** Arabic-Indic and Persian digits as 0-9 (banks print both). */
    public static function latinDigits(string $text): string
    {
        return strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }
}
