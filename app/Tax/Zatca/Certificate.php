<?php

namespace App\Tax\Zatca;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A stamp certificate (the "cryptographic stamp identifier" the authority's CA issues to a unit), read from its DER
 * without relying on how a PHP build prints long serial numbers or repeated name parts. It gives exactly what the
 * signature block and the QR code of an e-invoice need — each form checked on real invoices the authority accepted:
 *
 *   base64()       the certificate as the invoice embeds it (ds:X509Certificate)
 *   digest()       base64 of the HEX text of SHA-256 over that base64 text (xades:CertDigest)
 *   issuerName()   "CN=…, DC=…, DC=…": the issuer, most specific part first
 *   serial()       the serial number as a decimal text
 *   publicKey()    SubjectPublicKeyInfo, DER (QR tag 8)
 *   signature()    the CA's signature on the certificate, DER (QR tag 9 of a simplified invoice)
 */
final class Certificate
{
    private const NAMES = [
        '2.5.4.3' => 'CN', '2.5.4.6' => 'C', '2.5.4.7' => 'L', '2.5.4.8' => 'ST', '2.5.4.10' => 'O', '2.5.4.11' => 'OU', '2.5.4.5' => 'SERIALNUMBER',
        '0.9.2342.19200300.100.1.25' => 'DC', '0.9.2342.19200300.100.1.1' => 'UID', '2.5.4.4' => 'SN', '2.5.4.12' => 'T', '1.2.840.113549.1.9.1' => 'E',
    ];

    /** @param array<int, array{0:int, 1:string, 2:string}> $tbs the fields of the certificate body, the optional version first */
    private function __construct(public readonly string $der, private readonly array $tbs, private readonly string $signature) {}

    /** From DER, PEM or bare base64. */
    public static function fromDer(string $der): self
    {
        if (str_contains($der, '-----BEGIN') || preg_match('/^[A-Za-z0-9+\/=\s]+$/', $der)) {
            $der = Asn1::fromPem($der);
        }
        try {
            $offset = 0;
            [$tag, $certificate] = Asn1::read($der, $offset);
            $parts = Asn1::children($certificate);
            if ($tag !== Asn1::SEQUENCE || count($parts) !== 3) {
                throw new InvalidArgumentException('not a certificate');
            }
            $tbs = Asn1::children($parts[0][1]);
            if (($tbs[0][0] ?? null) !== 0xA0) {
                array_unshift($tbs, [0xA0, '', '']); // a version-1 certificate carries no version field
            }
            if (count($tbs) < 7) {
                throw new InvalidArgumentException('not a certificate');
            }

            return new self($der, $tbs, substr($parts[2][1], 1));
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException('The value is not an X.509 certificate.', 0, $e);
        }
    }

    /**
     * From the `binarySecurityToken` the authority's services answer with: the base64 text of the certificate's own
     * base64 text. (A token that is the certificate's base64 itself is read as well.)
     */
    public static function fromToken(string $token): self
    {
        $inner = base64_decode(trim($token), true);
        if ($inner === false || $inner === '') {
            throw new InvalidArgumentException('The security token is not base64.');
        }

        return self::fromDer(preg_match('/^[A-Za-z0-9+\/=\s]+$/', $inner) ? $inner : trim($token));
    }

    public function base64(): string
    {
        return base64_encode($this->der);
    }

    public function digest(): string
    {
        return base64_encode(hash('sha256', $this->base64()));
    }

    public function serial(): string
    {
        return Asn1::decimal(ltrim($this->tbs[1][1], "\x00"));
    }

    public function issuerName(): string
    {
        return self::nameText($this->tbs[3][1]);
    }

    public function subjectName(): string
    {
        return self::nameText($this->tbs[5][1]);
    }

    public function publicKey(): string
    {
        return $this->tbs[6][2];
    }

    public function signature(): string
    {
        return $this->signature;
    }

    public function notBefore(): CarbonImmutable
    {
        return self::time(Asn1::children($this->tbs[4][1])[0]);
    }

    public function notAfter(): CarbonImmutable
    {
        return self::time(Asn1::children($this->tbs[4][1])[1]);
    }

    /** The curve of the certificate's key, as its object identifier (secp256k1 = 1.3.132.0.10). */
    public function curve(): string
    {
        $algorithm = Asn1::children(Asn1::children($this->tbs[6][1])[0][1]);

        return Asn1::oidText($algorithm[1][1] ?? '');
    }

    /** RFC 4514 text of a name, the last RDN first — the form the authority's own stamps write. */
    private static function nameText(string $name): string
    {
        $parts = [];
        foreach (Asn1::children($name) as [, $set]) {
            $rdn = [];
            foreach (Asn1::children($set) as [, $pair]) {
                [$type, $value] = Asn1::children($pair);
                $oid = Asn1::oidText($type[1]);
                $text = $value[0] === 0x1E ? mb_convert_encoding($value[1], 'UTF-8', 'UTF-16BE') : $value[1];
                $rdn[] = (self::NAMES[$oid] ?? $oid).'='.preg_replace('/([,+"\\\\<>;])/', '\\\\$1', $text);
            }
            $parts[] = implode('+', $rdn);
        }

        return implode(', ', array_reverse($parts));
    }

    /** @param array{0:int, 1:string, 2:string} $element UTCTime (YYMMDDHHMMSSZ) or GeneralizedTime (YYYYMMDDHHMMSSZ) */
    private static function time(array $element): CarbonImmutable
    {
        $text = rtrim($element[1], 'Z');
        $format = $element[0] === 0x17 ? 'ymdHis' : 'YmdHis';

        return CarbonImmutable::createFromFormat($format, substr($text, 0, $element[0] === 0x17 ? 12 : 14), 'UTC');
    }
}
