<?php

namespace App\Tax\Zatca;

use App\Tax\Support\Money;

/**
 * The UBL 2.1 XML of a Saudi e-invoice (XML Implementation Standard v1.2 of 2023-05-19): a tax invoice (388), a credit
 * note (381) or a debit note (383), standard ("01…") or simplified ("02…"), from a neutral description of the document.
 * Every amount arrives in halalas and is printed with two decimals; nothing is computed here but the layout.
 *
 * The document is written WITHOUT the stamp: `Signer` puts ext:UBLExtensions and cac:Signature in afterwards, in a way
 * that leaves the hash as it was, so a document made while the unit had no certificate can be stamped later as the
 * same document (same counter, same hash, same place in the chain).
 *
 * The order of the elements is the order of the UBL 2.1 schema; the business terms are the ones a real invoice of
 * this company, cleared by the authority, carries.
 *
 * @phpstan-type Party array{name:string, vatNo?:?string, idScheme?:?string, idValue?:?string, street?:?string, buildingNo?:?string,
 *   additionalNo?:?string, district?:?string, city?:?string, postalCode?:?string, province?:?string, country?:?string}
 */
final class Ubl
{
    public const PROFILE = 'reporting:1.0';

    private const ROOT = '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2" xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2">';

    /**
     * @param  array{
     *   kind:string, profile:string, flags?:array<string,bool>, number:string, uuid:string, issueDate:string, issueTime:string,
     *   deliveryDate?:?string, currency?:string, note?:?string, billingReference?:?string, reason?:?string, paymentMeans?:?string,
     *   icv:int, previousHash:string, qr:string, seller:array, buyer:?array,
     *   lines:array<int, array{id:int, name:string, sku?:?string, qty:int|string, unit?:string, price:string, discount:int, net:int, vat:int,
     *     category:string, rate:string}>,
     *   tax:array<int, array{category:string, rate:string, taxable:int, vat:int, reasonCode?:?string, reason?:?string}>,
     *   totals:array{lineNet:int, allowance:int, taxable:int, vat:int, total:int, prepaid?:int, payable:int}
     * }  $d
     */
    public static function render(array $d): string
    {
        $cur = $d['currency'] ?? 'SAR';
        $amount = fn (string $name, int $minor) => [$name, Money::text($minor), ['currencyID' => $cur]];
        $category = fn (string $name, array $t, bool $reason = false) => [$name, array_filter([
            ['cbc:ID', $t['category']],
            ['cbc:Percent', self::percent($t['rate'])],
            $reason && ! empty($t['reasonCode']) ? ['cbc:TaxExemptionReasonCode', $t['reasonCode']] : null,
            $reason && ! empty($t['reason']) ? ['cbc:TaxExemptionReason', $t['reason']] : null,
            ['cac:TaxScheme', [['cbc:ID', 'VAT']]],
        ])];
        $attachment = fn (string $id, string $value) => ['cac:AdditionalDocumentReference', [
            ['cbc:ID', $id],
            ['cac:Attachment', [['cbc:EmbeddedDocumentBinaryObject', $value, ['mimeCode' => 'text/plain']]]],
        ]];

        $nodes = [
            ['cbc:ProfileID', self::PROFILE],
            ['cbc:ID', $d['number']],
            ['cbc:UUID', $d['uuid']],
            ['cbc:IssueDate', $d['issueDate']],
            ['cbc:IssueTime', $d['issueTime']],
            ['cbc:InvoiceTypeCode', Codes::TYPE[$d['kind']], ['name' => self::transactionCode($d['profile'], $d['flags'] ?? [])]],
            ! empty($d['note']) ? ['cbc:Note', $d['note'], ['languageID' => 'ar']] : null,
            ['cbc:DocumentCurrencyCode', $cur],
            ['cbc:TaxCurrencyCode', 'SAR'],
            ! empty($d['billingReference']) ? ['cac:BillingReference', [['cac:InvoiceDocumentReference', [['cbc:ID', $d['billingReference']]]]]] : null,
            ['cac:AdditionalDocumentReference', [['cbc:ID', 'ICV'], ['cbc:UUID', (string) $d['icv']]]],
            $attachment('PIH', $d['previousHash']),
            $attachment('QR', $d['qr']),
            ['cac:AccountingSupplierParty', [self::party($d['seller'])]],
            ['cac:AccountingCustomerParty', [self::party($d['buyer'] ?? [])]],
            ! empty($d['deliveryDate']) ? ['cac:Delivery', [['cbc:ActualDeliveryDate', $d['deliveryDate']]]] : null,
            ['cac:PaymentMeans', array_filter([
                ['cbc:PaymentMeansCode', $d['paymentMeans'] ?? '10'],
                ! empty($d['reason']) ? ['cbc:InstructionNote', $d['reason']] : null,
            ])],
        ];
        if ($d['totals']['allowance'] > 0) {
            // a discount on the whole document: one allowance per VAT category it lowers (here the first, OPS invoices carry one rate)
            $nodes[] = ['cac:AllowanceCharge', [
                ['cbc:ChargeIndicator', 'false'],
                ['cbc:AllowanceChargeReason', 'discount'],
                $amount('cbc:Amount', $d['totals']['allowance']),
                $category('cac:TaxCategory', $d['tax'][0]),
            ]];
        }
        // the VAT total in the tax currency, then the total with its breakdown per category
        $nodes[] = ['cac:TaxTotal', [$amount('cbc:TaxAmount', $d['totals']['vat'])]];
        $nodes[] = ['cac:TaxTotal', array_merge([$amount('cbc:TaxAmount', $d['totals']['vat'])], array_map(fn (array $t) => ['cac:TaxSubtotal', [
            $amount('cbc:TaxableAmount', $t['taxable']),
            $amount('cbc:TaxAmount', $t['vat']),
            $category('cac:TaxCategory', $t, true),
        ]], array_values($d['tax'])))];
        $nodes[] = ['cac:LegalMonetaryTotal', [
            $amount('cbc:LineExtensionAmount', $d['totals']['lineNet']),
            $amount('cbc:TaxExclusiveAmount', $d['totals']['taxable']),
            $amount('cbc:TaxInclusiveAmount', $d['totals']['total']),
            $amount('cbc:AllowanceTotalAmount', $d['totals']['allowance']),
            $amount('cbc:PrepaidAmount', $d['totals']['prepaid'] ?? 0),
            $amount('cbc:PayableAmount', $d['totals']['payable']),
        ]];
        foreach ($d['lines'] as $l) {
            $nodes[] = ['cac:InvoiceLine', array_filter([
                ['cbc:ID', (string) $l['id']],
                ['cbc:InvoicedQuantity', (string) $l['qty'], ['unitCode' => $l['unit'] ?? 'PCE']],
                $amount('cbc:LineExtensionAmount', $l['net']),
                $l['discount'] > 0 ? ['cac:AllowanceCharge', [['cbc:ChargeIndicator', 'false'], ['cbc:AllowanceChargeReason', 'discount'], $amount('cbc:Amount', $l['discount'])]] : null,
                // a line that stands a halala above quantity × price (the rounding remainder of a credit note) says so as a charge
                ($l['charge'] ?? 0) > 0 ? ['cac:AllowanceCharge', [['cbc:ChargeIndicator', 'true'], ['cbc:AllowanceChargeReasonCode', 'ZZZ'], ['cbc:AllowanceChargeReason', 'rounding'], $amount('cbc:Amount', $l['charge'])]] : null,
                // the line's VAT and the line with its VAT (KSA-11, KSA-12): net + VAT, to the halala (BR-KSA-51)
                ['cac:TaxTotal', [$amount('cbc:TaxAmount', $l['vat']), $amount('cbc:RoundingAmount', $l['net'] + $l['vat'])]],
                ['cac:Item', array_filter([
                    ['cbc:Name', $l['name']],
                    ! empty($l['sku']) ? ['cac:SellersItemIdentification', [['cbc:ID', $l['sku']]]] : null,
                    $category('cac:ClassifiedTaxCategory', $l),
                ])],
                ['cac:Price', [$amount('cbc:PriceAmount', Money::minor($l['price']))]],
            ])];
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n".self::ROOT."\n".self::write(array_values(array_filter($nodes)), 1).'</Invoice>'."\n";
    }

    /** "NNPNESB": 01 standard / 02 simplified, then third party · nominal · export · summary · self-billed (rule BR-KSA-06). */
    public static function transactionCode(string $profile, array $flags = []): string
    {
        $bit = fn (string $key) => empty($flags[$key]) ? '0' : '1';

        return ($profile === 'simplified' ? '02' : '01').$bit('thirdParty').$bit('nominal').$bit('export').$bit('summary').$bit('selfBilled');
    }

    public static function percent(string|int|float $rate): string
    {
        return number_format((float) Money::rate($rate), 2, '.', '');
    }

    /** A seller or a buyer. What is not known is left out — the checks say what a document of its kind is missing. */
    private static function party(array $p): array
    {
        $has = fn (string $key) => isset($p[$key]) && trim((string) $p[$key]) !== '';
        $address = array_filter([
            $has('street') ? ['cbc:StreetName', $p['street']] : null,
            $has('buildingNo') ? ['cbc:BuildingNumber', $p['buildingNo']] : null,
            $has('additionalNo') ? ['cbc:PlotIdentification', $p['additionalNo']] : null,
            $has('district') ? ['cbc:CitySubdivisionName', $p['district']] : null,
            $has('city') ? ['cbc:CityName', $p['city']] : null,
            $has('postalCode') ? ['cbc:PostalZone', $p['postalCode']] : null,
            $has('province') ? ['cbc:CountrySubentity', $p['province']] : null,
            $has('country') ? ['cac:Country', [['cbc:IdentificationCode', $p['country']]]] : null,
        ]);

        return ['cac:Party', array_filter([
            $has('idScheme') && $has('idValue') ? ['cac:PartyIdentification', [['cbc:ID', $p['idValue'], ['schemeID' => $p['idScheme']]]]] : null,
            $address ? ['cac:PostalAddress', array_values($address)] : null,
            $has('vatNo') ? ['cac:PartyTaxScheme', [['cbc:CompanyID', $p['vatNo']], ['cac:TaxScheme', [['cbc:ID', 'VAT']]]]] : null,
            $has('name') ? ['cac:PartyLegalEntity', [['cbc:RegistrationName', $p['name']]]] : null,
        ])];
    }

    /** @param array<int, array{0:string, 1:string|array, 2?:array<string,string>}> $nodes */
    private static function write(array $nodes, int $depth): string
    {
        $out = '';
        $pad = str_repeat('    ', $depth);
        foreach ($nodes as $node) {
            [$name, $content] = $node;
            $attributes = '';
            foreach ($node[2] ?? [] as $key => $value) {
                $attributes .= ' '.$key.'="'.self::escape((string) $value).'"';
            }
            if (is_array($content)) {
                $children = array_values(array_filter($content));
                $out .= $children ? "{$pad}<{$name}{$attributes}>\n".self::write($children, $depth + 1)."{$pad}</{$name}>\n" : "{$pad}<{$name}{$attributes}/>\n";
            } else {
                $out .= "{$pad}<{$name}{$attributes}>".self::escape((string) $content)."</{$name}>\n";
            }
        }

        return $out;
    }

    /** Text of a value: the five XML characters escaped, control characters XML 1.0 forbids dropped. */
    private static function escape(string $text): string
    {
        $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';

        return htmlspecialchars(trim($text), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
