<?php

namespace App\Tax\Zatca;

use RuntimeException;

/**
 * The key pair of an e-invoicing unit: ECDSA on the curve secp256k1 — the curve of the authority's own instructions
 * (`openssl ecparam -name secp256k1 -genkey`, Detailed Technical Guidelines §5) and of the certificates its CA issues.
 *
 * A key is made from 32 random bytes and written as a SEC1 structure by hand, so that nothing depends on an
 * `openssl.cnf` being installed (the Windows and serverless builds of PHP have none); OpenSSL then reads the key,
 * derives its public half and does every signature. The private key never leaves this class as anything but the PEM
 * text the caller stores ENCRYPTED: it is not logged, not put in an exception message, not returned by an endpoint.
 */
final class Keys
{
    public const CURVE = 'secp256k1';

    private const CURVE_OID = '1.3.132.0.10';

    /** The order of the curve: a private key is a number between 1 and this, exclusive. */
    private const ORDER = 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364141';

    /** A new private key (PEM, "EC PRIVATE KEY"). */
    public static function generate(): string
    {
        do {
            $d = random_bytes(32);
            $hex = strtoupper(bin2hex($d));
        } while ($hex === str_repeat('0', 64) || strcmp($hex, self::ORDER) >= 0);
        $pem = Asn1::toPem(Asn1::sequence(Asn1::integer(1), Asn1::octets($d), Asn1::context(0, Asn1::oid(self::CURVE_OID))), 'EC PRIVATE KEY');
        // proven before anyone relies on it: the key is read back, and a signature made with it verifies
        $probe = random_bytes(16);
        if (! self::verify(self::publicDer($pem), $probe, self::sign($pem, $probe))) {
            throw new RuntimeException('The generated signing key failed its self-check.');
        }

        return $pem;
    }

    /** The public half as SubjectPublicKeyInfo (DER) — what a certificate request and the QR code carry. */
    public static function publicDer(string $privatePem): string
    {
        $key = openssl_pkey_get_private($privatePem);
        $details = $key ? openssl_pkey_get_details($key) : false;
        self::drain();
        if (! $details || ($details['ec']['curve_name'] ?? null) !== self::CURVE) {
            throw new RuntimeException('The signing key is not a readable secp256k1 key.');
        }

        return Asn1::fromPem($details['key']);
    }

    /** ECDSA with SHA-256 over `$data`; the signature is the DER structure (r, s). */
    public static function sign(string $privatePem, string $data): string
    {
        $key = openssl_pkey_get_private($privatePem);
        $signature = '';
        $ok = $key && openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256);
        self::drain();
        if (! $ok) {
            throw new RuntimeException('The signing key could not sign.');
        }

        return $signature;
    }

    /** @param  string  $publicDer  SubjectPublicKeyInfo (DER), or a certificate / key in PEM */
    public static function verify(string $publicDer, string $data, string $signature): bool
    {
        $pem = str_contains($publicDer, '-----BEGIN') ? $publicDer : Asn1::toPem($publicDer, 'PUBLIC KEY');
        $key = openssl_pkey_get_public($pem);
        $ok = $key && openssl_verify($data, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
        self::drain();

        return $ok;
    }

    /** Does this private key belong to this public key? */
    public static function matches(string $privatePem, string $publicDer): bool
    {
        try {
            return hash_equals(self::publicDer($privatePem), $publicDer);
        } catch (RuntimeException) {
            return false;
        }
    }

    /** OpenSSL keeps its complaints (a missing openssl.cnf among them) in a queue: emptied, so they never surface later. */
    private static function drain(): void
    {
        while (openssl_error_string() !== false) {
        }
    }
}
