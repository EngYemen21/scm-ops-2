<?php

namespace App\Integration\Odoo\Archive;

/**
 * The spelling the search box works in: lower case, the forms of an Arabic letter written as one, marks and
 * lengthening dropped, digits Latin, spaces single. The index stores this spelling and a question is put into it, so
 * "أحمد" finds "احمد" and "Juice" finds "JUICE" on any database engine.
 */
final class Fold
{
    private const LETTERS = [
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ئ' => 'ي', 'ؤ' => 'و', 'ة' => 'ه',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    public static function text(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        $s = mb_strtolower($text, 'UTF-8');
        $s = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]+/u', '', $s);

        return trim((string) preg_replace('/\s+/u', ' ', strtr($s, self::LETTERS)));
    }

    /** The words of a question, in the index's spelling — every one of them must be found. @return string[] */
    public static function terms(?string $question, int $max = 6): array
    {
        $words = array_values(array_filter(explode(' ', self::text($question)), fn ($w) => $w !== ''));

        return array_slice(array_values(array_unique($words)), 0, $max);
    }

    /** A word as a LIKE pattern that matches it anywhere (the wildcards a person typed are letters, not wildcards). */
    public static function like(string $word): string
    {
        return '%'.addcslashes($word, '%_\\').'%';
    }
}
