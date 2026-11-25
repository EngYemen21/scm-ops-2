<?php

namespace App\Tax\Zatca;

use InvalidArgumentException;

/**
 * The QR code of a Saudi tax invoice: its fields in Tag-Length-Value form — one byte for the tag, one byte for the
 * length, the value — joined with nothing between them and written as base64 (Security Features Implementation
 * Standards v1.2 §4; Detailed Technical Guidelines §6).
 *
 *   1 seller's name · 2 seller's VAT number · 3 date and time of the invoice · 4 total with VAT · 5 VAT total
 *   — the five fields every invoice carries, also one of a unit that has no certificate yet;
 *   6 the hash of the XML · 7 the unit's signature of that hash · 8 the unit's public key
 *   9 (simplified invoices and their notes) the signature the authority's CA put on the unit's certificate.
 *
 * The forms of 3, 6, 7, 8 and 9 are the ones found in the invoices the authority accepted and re-stamped itself:
 * tag 3 is the issue date and time exactly as the XML prints them ("2025-02-15T16:19:37"), tags 6 and 7 are the
 * base64 TEXTS of the hash and of the signature, tags 8 and 9 are raw DER bytes.
 */
final class Qr
{
    /** A length is one byte. */
    private const MAX = 255;

    /** The standard asks for at most this many base64 characters. */
    public const LIMIT = 700;

    /**
     * @param  array{seller:string, vatNo:string, timestamp:string, total:string, vat:string}  $basic
     * @param  array{hash:string, signature:string, publicKey:string, certificateSignature?:?string}|null  $stamp
     */
    public static function encode(array $basic, ?array $stamp = null): string
    {
        $fields = [1 => self::fit($basic['seller']), 2 => $basic['vatNo'], 3 => $basic['timestamp'], 4 => $basic['total'], 5 => $basic['vat']];
        if ($stamp !== null) {
            $fields += [6 => $stamp['hash'], 7 => $stamp['signature'], 8 => $stamp['publicKey']];
            if (($stamp['certificateSignature'] ?? null) !== null) {
                $fields[9] = $stamp['certificateSignature'];
            }
        }
        $out = '';
        foreach ($fields as $tag => $value) {
            $value = (string) $value;
            if (strlen($value) > self::MAX) {
                throw new InvalidArgumentException("QR field {$tag} is longer than 255 bytes.");
            }
            $out .= chr($tag).chr(strlen($value)).$value;
        }

        return base64_encode($out);
    }

    /** @return array<int, string> tag → value bytes */
    public static function decode(string $base64): array
    {
        $bytes = base64_decode($base64, true);
        if ($bytes === false) {
            throw new InvalidArgumentException('The QR text is not base64.');
        }
        $out = [];
        for ($i = 0, $n = strlen($bytes); $i + 2 <= $n;) {
            $length = ord($bytes[$i + 1]);
            $out[ord($bytes[$i])] = substr($bytes, $i + 2, $length);
            $i += 2 + $length;
        }

        return $out;
    }

    /** A name longer than a length byte can say is cut on a character boundary. */
    private static function fit(string $text): string
    {
        return strlen($text) <= self::MAX ? $text : rtrim(mb_strcut($text, 0, self::MAX, 'UTF-8'));
    }
}
