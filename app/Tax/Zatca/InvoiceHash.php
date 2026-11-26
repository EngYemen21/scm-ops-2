<?php

namespace App\Tax\Zatca;

use DOMDocument;
use DOMXPath;
use InvalidArgumentException;

/**
 * The hash of an e-invoice (XML Implementation Standard v1.2, rule BR-KSA-26; Detailed Technical Guidelines §5.2):
 *
 *   1. the three blocks that are not "the invoice" are taken out — ext:UBLExtensions (the stamp), cac:Signature, and
 *      the cac:AdditionalDocumentReference whose cbc:ID is QR;
 *   2. what is left is canonicalised (Canonical XML; an invoice has no xml:* attributes, so the result of the 1.0
 *      algorithm PHP has is the result of the 1.1 algorithm the standard names);
 *   3. SHA-256 of those bytes;
 *   4. the hash is carried as the base64 of its 32 bytes (the digest of the stamp, the `invoiceHash` of the
 *      authority's services, QR tag 6, and the "previous invoice hash" of the next document).
 *
 * This is the procedure that reproduces, byte for byte, the digest inside every one of the 700 invoices of this
 * company that the authority accepted and the backup holds (checked when the section was built).
 */
final class InvoiceHash
{
    public const NS = [
        'inv' => 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
        'cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
        'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
        'ext' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2',
        'sig' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2',
        'sac' => 'urn:oasis:names:specification:ubl:schema:xsd:SignatureAggregateComponents-2',
        'sbc' => 'urn:oasis:names:specification:ubl:schema:xsd:SignatureBasicComponents-2',
        'ds' => 'http://www.w3.org/2000/09/xmldsig#',
        'xades' => 'http://uri.etsi.org/01903/v1.3.2#',
    ];

    private const REMOVED = ['//ext:UBLExtensions', '/inv:Invoice/cac:Signature', "//cac:AdditionalDocumentReference[cbc:ID[normalize-space(text())='QR']]"];

    /** The canonical bytes the hash is taken over. */
    public static function canonical(string $xml): string
    {
        $doc = self::load($xml);
        $xpath = self::xpath($doc);
        foreach (self::REMOVED as $query) {
            foreach (iterator_to_array($xpath->query($query)) as $node) {
                $node->parentNode->removeChild($node);
            }
        }

        return $doc->documentElement->C14N(false, false);
    }

    /** The 32 bytes. */
    public static function binary(string $xml): string
    {
        return hash('sha256', self::canonical($xml), true);
    }

    /** The hash as documents and services carry it. */
    public static function base64(string $xml): string
    {
        return base64_encode(self::binary($xml));
    }

    public static function load(string $xml): DOMDocument
    {
        $doc = new DOMDocument;
        $doc->preserveWhiteSpace = true;
        $doc->formatOutput = false;
        // no network, no entity expansion: an invoice is data
        if ($xml === '' || ! @$doc->loadXML($xml, LIBXML_NONET) || $doc->documentElement === null) {
            throw new InvalidArgumentException('The e-invoice is not well-formed XML.');
        }

        return $doc;
    }

    public static function xpath(DOMDocument $doc): DOMXPath
    {
        $xpath = new DOMXPath($doc);
        foreach (self::NS as $prefix => $uri) {
            $xpath->registerNamespace($prefix, $uri);
        }

        return $xpath;
    }
}
