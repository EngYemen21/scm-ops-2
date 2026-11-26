<?php

namespace App\Tax\Zatca;

use InvalidArgumentException;

/**
 * The little of ASN.1 / DER the e-invoice needs: writing a certificate request, and reading a certificate (its serial
 * number, its issuer, its public key and the signature the authority's CA put on it). Nothing here touches a key.
 */
final class Asn1
{
    public const SEQUENCE = 0x30;

    public const SET = 0x31;

    // ───────────── writing ─────────────

    public static function tlv(int $tag, string $value): string
    {
        $n = strlen($value);
        if ($n < 0x80) {
            return chr($tag).chr($n).$value;
        }
        $bytes = ltrim(pack('N', $n), "\x00");

        return chr($tag).chr(0x80 | strlen($bytes)).$bytes.$value;
    }

    public static function sequence(string ...$items): string
    {
        return self::tlv(self::SEQUENCE, implode('', $items));
    }

    public static function set(string ...$items): string
    {
        sort($items, SORT_STRING); // DER: the members of a SET OF in the order of their encodings

        return self::tlv(self::SET, implode('', $items));
    }

    /** A non-negative INTEGER from its big-endian bytes or a small number. */
    public static function integer(int|string $v): string
    {
        $bytes = is_int($v) ? ltrim(pack('J', $v), "\x00") : ltrim($v, "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80)) {
            $bytes = "\x00".$bytes;
        }

        return self::tlv(0x02, $bytes);
    }

    public static function oid(string $dotted): string
    {
        $parts = array_map('intval', explode('.', $dotted));
        if (count($parts) < 2) {
            throw new InvalidArgumentException("not an object identifier: {$dotted}");
        }
        $out = chr($parts[0] * 40 + $parts[1]);
        foreach (array_slice($parts, 2) as $n) {
            $chunk = chr($n & 0x7F);
            while ($n >>= 7) {
                $chunk = chr(0x80 | ($n & 0x7F)).$chunk;
            }
            $out .= $chunk;
        }

        return self::tlv(0x06, $out);
    }

    public static function utf8(string $s): string
    {
        return self::tlv(0x0C, $s);
    }

    public static function printable(string $s): string
    {
        return self::tlv(0x13, $s);
    }

    public static function octets(string $bytes): string
    {
        return self::tlv(0x04, $bytes);
    }

    public static function bits(string $bytes): string
    {
        return self::tlv(0x03, "\x00".$bytes);
    }

    /** A context-specific, constructed tag: [n] { … }. */
    public static function context(int $n, string $content): string
    {
        return self::tlv(0xA0 | $n, $content);
    }

    /** A distinguished name: one attribute per RDN, in the order given. @param array<int, array{0:string, 1:string, 2?:bool}> $attributes [oid, value, printable] */
    public static function name(array $attributes): string
    {
        $rdns = [];
        foreach ($attributes as $a) {
            $rdns[] = self::set(self::sequence(self::oid($a[0]), ($a[2] ?? false) ? self::printable($a[1]) : self::utf8($a[1])));
        }

        return self::sequence(...$rdns);
    }

    // ───────────── reading ─────────────

    /** The element at `$offset`: [tag, value, the whole element]. `$offset` moves past it. @return array{0:int, 1:string, 2:string} */
    public static function read(string $der, int &$offset): array
    {
        $start = $offset;
        $total = strlen($der);
        if ($offset + 2 > $total) {
            throw new InvalidArgumentException('truncated DER');
        }
        $tag = ord($der[$offset++]);
        $len = ord($der[$offset++]);
        if ($len & 0x80) {
            $n = $len & 0x7F;
            if ($n === 0 || $n > 4 || $offset + $n > $total) {
                throw new InvalidArgumentException('unsupported DER length');
            }
            $len = 0;
            for ($i = 0; $i < $n; $i++) {
                $len = ($len << 8) | ord($der[$offset++]);
            }
        }
        if ($offset + $len > $total) {
            throw new InvalidArgumentException('truncated DER');
        }
        $value = substr($der, $offset, $len);
        $offset += $len;

        return [$tag, $value, substr($der, $start, $offset - $start)];
    }

    /** The elements inside a constructed value. @return array<int, array{0:int, 1:string, 2:string}> */
    public static function children(string $value): array
    {
        $out = [];
        $offset = 0;
        $n = strlen($value);
        while ($offset < $n) {
            $out[] = self::read($value, $offset);
        }

        return $out;
    }

    public static function oidText(string $bytes): string
    {
        if ($bytes === '') {
            return '';
        }
        $first = ord($bytes[0]);
        $parts = [intdiv($first, 40), $first % 40];
        $n = 0;
        for ($i = 1, $len = strlen($bytes); $i < $len; $i++) {
            $c = ord($bytes[$i]);
            $n = ($n << 7) | ($c & 0x7F);
            if (! ($c & 0x80)) {
                $parts[] = $n;
                $n = 0;
            }
        }

        return implode('.', $parts);
    }

    /** The bytes of an unsigned INTEGER as a decimal text (a certificate's serial number has up to 20 bytes). */
    public static function decimal(string $bytes): string
    {
        $out = '0';
        for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
            $out = bcadd(bcmul($out, '256', 0), (string) ord($bytes[$i]), 0);
        }

        return $out;
    }

    /** PEM (or bare base64) → DER. */
    public static function fromPem(string $pem): string
    {
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem) ?? '', true);
        if ($der === false || $der === '') {
            throw new InvalidArgumentException('not a PEM / base64 value');
        }

        return $der;
    }

    public static function toPem(string $der, string $label): string
    {
        return "-----BEGIN {$label}-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END {$label}-----\n";
    }
}
