<?php

namespace App\ProductRequests\Support;

/**
 * A product name the way duplicate detection compares it. People type the same product in many spellings — «جبنة» /
 * «جبنه», «أرز» / «ارز», a kashida, a harakah, "Olive-Oil" / "olive oil" — so two names are "the same" when their keys
 * are equal: lower-case, the Arabic spelling variants folded, marks and punctuation gone, one space between words.
 *
 * The pack text people put in parentheses ("(125ml*18pcs)") is kept apart: the same name in another pack is another
 * product, the same name in the same pack — or with no pack stated on one side — is the same product.
 */
final class NameKey
{
    /** Spelling variants that are one letter to a reader, and the digits of the Arabic and Persian keyboards. */
    private const FOLD = [
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ی' => 'ي', 'ئ' => 'ي', 'ؤ' => 'و', 'ة' => 'ه', 'ک' => 'ك', 'گ' => 'ك',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '×' => 'x',
    ];

    public const MAX = 250;

    /** The whole name as a key ('' for nothing). */
    public static function of(?string $name): string
    {
        return self::squash(self::fold((string) $name));
    }

    /** The name without what stands in parentheses or brackets — the product itself, whatever its pack. */
    public static function core(?string $name): string
    {
        return self::squash((string) preg_replace('/\([^()]*\)|\[[^\[\]]*\]/u', ' ', self::fold((string) $name)));
    }

    /**
     * The pack a name states, as a key: every parenthesis that holds a number, plus a pack given on its own
     * ("125ml*18pcs" and "( 125 ML x 18 pcs )" are one pack). '' when no pack is stated.
     */
    public static function pack(?string $name, ?string $packText = null): string
    {
        $parts = [];
        if (preg_match_all('/\(([^()]*\d[^()]*)\)/u', self::fold((string) $name), $m)) {
            $parts = $m[1];
        }
        if ($packText !== null && trim($packText) !== '') {
            $parts[] = self::fold(trim($packText, " \t()"));
        }
        $keys = [];
        foreach ($parts as $part) {
            $key = (string) preg_replace('/[^\p{L}\p{N}.]+/u', '', str_replace('*', 'x', $part));
            if ($key !== '') {
                $keys[$key] = true;
            }
        }
        $keys = array_keys($keys);
        sort($keys);

        return implode('|', $keys);
    }

    /** The words of a key that tell products apart (a single letter does not). @return string[] */
    public static function tokens(string $key): array
    {
        $out = [];
        foreach (explode(' ', $key) as $word) {
            if ($word !== '' && mb_strlen($word) >= 2) {
                $out[$word] = true;
            }
        }

        return array_map('strval', array_keys($out));
    }

    /**
     * How much of what was typed is found in a name, 0 … 1. A typed word of three letters or more also matches the
     * beginning of a longer word, so a name is found while it is still being typed.
     *
     * @param  string[]  $typed
     * @param  string[]  $name
     */
    public static function coverage(array $typed, array $name): float
    {
        if (! $typed || ! $name) {
            return 0.0;
        }
        $set = array_fill_keys($name, true);
        $hit = 0;
        foreach ($typed as $word) {
            if (isset($set[$word])) {
                $hit++;

                continue;
            }
            if (mb_strlen($word) >= 3) {
                foreach ($name as $other) {
                    if (str_starts_with($other, $word)) {
                        $hit++;

                        break;
                    }
                }
            }
        }

        return $hit / count($typed);
    }

    private static function fold(string $s): string
    {
        $s = mb_strtolower(trim($s));
        // harakat, Quranic marks and the kashida are decoration, not letters
        $s = (string) preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}]/u', '', $s);

        return strtr($s, self::FOLD);
    }

    private static function squash(string $s): string
    {
        return mb_substr(trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s)), 0, self::MAX);
    }
}
