<?php

namespace App\Tax\Zatca;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The cryptographic stamp of a unit on an e-invoice: an enveloped XAdES signature (level B-B) inside ext:UBLExtensions,
 * the cac:Signature marker, and the QR code with its stamp fields (Security Features Implementation Standards v1.2
 * §2.3.3; Detailed Technical Guidelines §5.2 steps 1–6).
 *
 * What each value is — every one of them checked against invoices of this company that the authority accepted, most of
 * them carrying the authority's OWN stamp:
 *   invoice digest          base64 of the 32-byte hash (InvoiceHash)
 *   signature value         ECDSA with SHA-256 over those 32 bytes, DER, base64 (the authority's stamp verifies so)
 *   certificate digest      base64 of the hex text of SHA-256 over the certificate's base64 text
 *   signed-properties       base64 of the hex text of SHA-256 over the xades:SignedProperties element as it stands in
 *   digest                  the document, with the namespace declarations a canonicaliser adds (xades on the element,
 *                           ds on each ds child) — so the layout below is part of the value and is never re-indented
 *   second reference type   http://www.w3.org/2000/09/xmldsig#SignatureProperties (what the authority's stamps write)
 *
 * The stamp is put in without a single character of whitespace around it, so the hash of the document is the same
 * before and after: a document that was made unstamped is stamped later as the same document.
 */
final class Signer
{
    private const XADES = 'http://uri.etsi.org/01903/v1.3.2#';

    private const DS = 'http://www.w3.org/2000/09/xmldsig#';

    /**
     * @param  array{seller:string, vatNo:string, timestamp:string, total:string, vat:string}  $qrBasic
     * @return array{xml:string, hash:string, signature:string, qr:string, signedAt:string}
     */
    public static function stamp(string $xml, string $privatePem, Certificate $certificate, array $qrBasic, bool $simplified, ?CarbonInterface $at = null): array
    {
        if (! Keys::matches($privatePem, $certificate->publicKey())) {
            throw new RuntimeException('The certificate does not belong to the signing key of the unit.');
        }
        $xml = self::unstamp($xml);
        $hash = InvoiceHash::binary($xml);
        $hash64 = base64_encode($hash);
        $signature = base64_encode(Keys::sign($privatePem, $hash));
        $signedAt = ($at ? Carbon::instance($at) : Carbon::now())->copy()->setTimezone('Asia/Riyadh')->format('Y-m-d\TH:i:s');

        $properties = self::signedProperties($signedAt, $certificate);
        $propertiesDigest = base64_encode(hash('sha256', self::forDigest($properties)));
        $qr = Qr::encode($qrBasic, ['hash' => $hash64, 'signature' => $signature, 'publicKey' => $certificate->publicKey(),
            'certificateSignature' => $simplified ? $certificate->signature() : null]);

        $out = preg_replace('/(<Invoice\b[^>]*>)/', '$1'.self::quote(self::extensions($hash64, $propertiesDigest, $signature, $certificate->base64(), $properties)), $xml, 1, $placed);
        $marker = '<cac:Signature><cbc:ID>urn:oasis:names:specification:ubl:signature:Invoice</cbc:ID><cbc:SignatureMethod>urn:oasis:names:specification:ubl:dsig:enveloped:xades</cbc:SignatureMethod></cac:Signature>';
        $out = preg_replace('/(<\/cac:AdditionalDocumentReference>)(\s*<cac:AccountingSupplierParty>)/', '$1'.self::quote($marker).'$2', (string) $out, 1, $marked);
        $out = self::withQr((string) $out, $qr);
        if ($placed !== 1 || $marked !== 1) {
            throw new RuntimeException('The e-invoice has not the layout the stamp is placed in.');
        }
        // the stamp must not have moved the hash, and it must verify with the certificate it names
        if (InvoiceHash::base64($out) !== $hash64 || ! Keys::verify($certificate->publicKey(), $hash, (string) base64_decode($signature))) {
            throw new RuntimeException('The stamped e-invoice failed its own verification.');
        }

        return ['xml' => $out, 'hash' => $hash64, 'signature' => $signature, 'qr' => $qr, 'signedAt' => $signedAt];
    }

    /** The document without a stamp (as `Ubl` wrote it); its QR is left as it is. */
    public static function unstamp(string $xml): string
    {
        return (string) preg_replace(['/<ext:UBLExtensions>.*?<\/ext:UBLExtensions>/s', '/<cac:Signature>.*?<\/cac:Signature>/s'], '', $xml);
    }

    public static function stamped(string $xml): bool
    {
        return str_contains($xml, '<ext:UBLExtensions>');
    }

    /** Replaces the content of the QR reference. */
    public static function withQr(string $xml, string $qr): string
    {
        $out = preg_replace('/(<cbc:ID>QR<\/cbc:ID>\s*<cac:Attachment>\s*<cbc:EmbeddedDocumentBinaryObject mimeCode="text\/plain">)[^<]*(<\/cbc:EmbeddedDocumentBinaryObject>)/', '${1}'.self::quote($qr).'${2}', $xml, 1, $n);
        if ($n !== 1) {
            throw new RuntimeException('The e-invoice has no QR reference.');
        }

        return (string) $out;
    }

    /**
     * What a reader finds in a stamped document: the digests, the signature and whether each of them holds.
     *
     * @return array{stamped:bool, hash:string, digestMatches?:bool, signatureValid?:bool, propertiesDigestMatches?:bool, certificateDigestMatches?:bool}
     */
    public static function inspect(string $xml): array
    {
        $hash = InvoiceHash::binary($xml);
        $out = ['stamped' => self::stamped($xml), 'hash' => base64_encode($hash)];
        if (! $out['stamped']) {
            return $out;
        }
        $x = InvoiceHash::xpath(InvoiceHash::load($xml));
        $text = fn (string $query) => trim((string) $x->query($query)->item(0)?->textContent);
        $certificate = Certificate::fromDer((string) preg_replace('/\s+/', '', $text('//ds:X509Certificate')));
        preg_match('/<xades:SignedProperties\b.*?<\/xades:SignedProperties>/s', $xml, $m);

        return $out + [
            'digestMatches' => $text("//ds:Reference[@Id='invoiceSignedData']/ds:DigestValue") === $out['hash'],
            'signatureValid' => Keys::verify($certificate->publicKey(), $hash, (string) base64_decode($text('//ds:SignatureValue'))),
            'propertiesDigestMatches' => $text("//ds:Reference[@URI='#xadesSignedProperties']/ds:DigestValue") === base64_encode(hash('sha256', self::forDigest($m[0] ?? ''))),
            'certificateDigestMatches' => $text('//xades:CertDigest/ds:DigestValue') === $certificate->digest(),
        ];
    }

    /** The element as the document embeds it: it stands 32 spaces deep, its children 36 and more. */
    private static function signedProperties(string $signedAt, Certificate $c): string
    {
        $e = fn (string $v) => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $p = fn (int $n) => str_repeat(' ', $n);

        return '<xades:SignedProperties Id="xadesSignedProperties">'."\n"
            .$p(36).'<xades:SignedSignatureProperties>'."\n"
            .$p(40).'<xades:SigningTime>'.$signedAt.'</xades:SigningTime>'."\n"
            .$p(40).'<xades:SigningCertificate>'."\n"
            .$p(44).'<xades:Cert>'."\n"
            .$p(48).'<xades:CertDigest>'."\n"
            .$p(52).'<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'."\n"
            .$p(52).'<ds:DigestValue>'.$c->digest().'</ds:DigestValue>'."\n"
            .$p(48).'</xades:CertDigest>'."\n"
            .$p(48).'<xades:IssuerSerial>'."\n"
            .$p(52).'<ds:X509IssuerName>'.$e($c->issuerName()).'</ds:X509IssuerName>'."\n"
            .$p(52).'<ds:X509SerialNumber>'.$c->serial().'</ds:X509SerialNumber>'."\n"
            .$p(48).'</xades:IssuerSerial>'."\n"
            .$p(44).'</xades:Cert>'."\n"
            .$p(40).'</xades:SigningCertificate>'."\n"
            .$p(36).'</xades:SignedSignatureProperties>'."\n"
            .$p(32).'</xades:SignedProperties>';
    }

    /** The text the digest of the signed properties is taken over. */
    private static function forDigest(string $embedded): string
    {
        $text = preg_replace('/^<xades:SignedProperties\b/', '<xades:SignedProperties xmlns:xades="'.self::XADES.'"', $embedded);

        return (string) preg_replace('/<ds:(\w+)/', '<ds:$1 xmlns:ds="'.self::DS.'"', (string) $text);
    }

    private static function extensions(string $hash, string $propertiesDigest, string $signature, string $certificate, string $properties): string
    {
        $p = fn (int $n) => str_repeat(' ', $n);
        $transform = fn (string $xpath) => $p(36).'<ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116">'."\n".$p(40).'<ds:XPath>'.$xpath.'</ds:XPath>'."\n".$p(36).'</ds:Transform>'."\n";

        return '<ext:UBLExtensions>'."\n"
            .$p(4).'<ext:UBLExtension>'."\n"
            .$p(8).'<ext:ExtensionURI>urn:oasis:names:specification:ubl:dsig:enveloped:xades</ext:ExtensionURI>'."\n"
            .$p(8).'<ext:ExtensionContent>'."\n"
            .$p(12).'<sig:UBLDocumentSignatures xmlns:sig="'.InvoiceHash::NS['sig'].'" xmlns:sac="'.InvoiceHash::NS['sac'].'" xmlns:sbc="'.InvoiceHash::NS['sbc'].'">'."\n"
            .$p(16).'<sac:SignatureInformation>'."\n"
            .$p(20).'<cbc:ID>urn:oasis:names:specification:ubl:signature:1</cbc:ID>'."\n"
            .$p(20).'<sbc:ReferencedSignatureID>urn:oasis:names:specification:ubl:signature:Invoice</sbc:ReferencedSignatureID>'."\n"
            .$p(20).'<ds:Signature xmlns:ds="'.self::DS.'" Id="signature">'."\n"
            .$p(24).'<ds:SignedInfo>'."\n"
            .$p(28).'<ds:CanonicalizationMethod Algorithm="http://www.w3.org/2006/12/xml-c14n11"/>'."\n"
            .$p(28).'<ds:SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256"/>'."\n"
            .$p(28).'<ds:Reference Id="invoiceSignedData" URI="">'."\n"
            .$p(32).'<ds:Transforms>'."\n"
            .$transform('not(//ancestor-or-self::ext:UBLExtensions)')
            .$transform('not(//ancestor-or-self::cac:Signature)')
            .$transform("not(//ancestor-or-self::cac:AdditionalDocumentReference[cbc:ID='QR'])")
            .$p(36).'<ds:Transform Algorithm="http://www.w3.org/2006/12/xml-c14n11"/>'."\n"
            .$p(32).'</ds:Transforms>'."\n"
            .$p(32).'<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'."\n"
            .$p(32).'<ds:DigestValue>'.$hash.'</ds:DigestValue>'."\n"
            .$p(28).'</ds:Reference>'."\n"
            .$p(28).'<ds:Reference Type="http://www.w3.org/2000/09/xmldsig#SignatureProperties" URI="#xadesSignedProperties">'."\n"
            .$p(32).'<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'."\n"
            .$p(32).'<ds:DigestValue>'.$propertiesDigest.'</ds:DigestValue>'."\n"
            .$p(28).'</ds:Reference>'."\n"
            .$p(24).'</ds:SignedInfo>'."\n"
            .$p(24).'<ds:SignatureValue>'.$signature.'</ds:SignatureValue>'."\n"
            .$p(24).'<ds:KeyInfo>'."\n"
            .$p(28).'<ds:X509Data>'."\n"
            .$p(32).'<ds:X509Certificate>'.$certificate.'</ds:X509Certificate>'."\n"
            .$p(28).'</ds:X509Data>'."\n"
            .$p(24).'</ds:KeyInfo>'."\n"
            .$p(24).'<ds:Object>'."\n"
            .$p(28).'<xades:QualifyingProperties xmlns:xades="'.self::XADES.'" Target="signature">'."\n"
            .$p(32).$properties."\n"
            .$p(28).'</xades:QualifyingProperties>'."\n"
            .$p(24).'</ds:Object>'."\n"
            .$p(20).'</ds:Signature>'."\n"
            .$p(16).'</sac:SignatureInformation>'."\n"
            .$p(12).'</sig:UBLDocumentSignatures>'."\n"
            .$p(8).'</ext:ExtensionContent>'."\n"
            .$p(4).'</ext:UBLExtension>'."\n"
            .'</ext:UBLExtensions>';
    }

    /** A replacement text is taken literally (a "$1" or a backslash inside a value is not a reference). */
    private static function quote(string $replacement): string
    {
        return addcslashes($replacement, '\\$');
    }
}
