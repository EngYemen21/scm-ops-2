<?php

namespace App\Tax\Zatca;

use App\Support\AppError;

/**
 * The certificate request (PKCS#10) of an e-invoicing unit, as the authority's CA expects it (Security Features
 * Implementation Standards v1.2, table 1; Detailed Technical Guidelines §3.3.3):
 *
 *   subject        C (country) · OU (branch — for a VAT group: the member's 10-digit TIN) · O (taxpayer) · CN (the
 *                  unit's name or asset number)
 *   extensions     the certificate template name of the environment (1.3.6.1.4.1.311.20.2), and an alternative name
 *                  that is a directory name with: SN = the unit's serial "1-…|2-…|3-…", UID = the VAT number,
 *                  title = which documents the unit issues (four digits, standard · simplified · 0 · 0),
 *                  registeredAddress = where the unit is, businessCategory = the industry.
 *   signature      ECDSA with SHA-256 by the unit's own key (proof that it holds it).
 *
 * The attribute types of the alternative name were checked against a certificate the authority's CA really issued to
 * this company's unit (it carries exactly these five, as UTF-8 strings).
 * Written by hand in DER so that it does not need an openssl.cnf on the server.
 */
final class Csr
{
    private const OID = [
        'C' => '2.5.4.6', 'OU' => '2.5.4.11', 'O' => '2.5.4.10', 'CN' => '2.5.4.3',
        'SN' => '2.5.4.4', 'UID' => '0.9.2342.19200300.100.1.1', 'title' => '2.5.4.12', 'registeredAddress' => '2.5.4.26', 'businessCategory' => '2.5.4.15',
        'templateName' => '1.3.6.1.4.1.311.20.2', 'subjectAltName' => '2.5.29.17', 'extensionRequest' => '1.2.840.113549.1.9.14', 'ecdsaWithSha256' => '1.2.840.10045.4.3.2',
    ];

    /**
     * @param  array{commonName:string, serial:string, vatNo:string, branch:string, organization:string, country?:string,
     *               invoiceTypes:string, location:string, industry:string, environment:string}  $in
     * @return string the request in PEM
     */
    public static function build(array $in, string $privatePem): string
    {
        $in = self::checked($in);
        $subject = Asn1::name([
            [self::OID['C'], $in['country'], true], [self::OID['OU'], $in['branch']], [self::OID['O'], $in['organization']], [self::OID['CN'], $in['commonName']],
        ]);
        $directory = Asn1::name([
            [self::OID['SN'], $in['serial']], [self::OID['UID'], $in['vatNo']], [self::OID['title'], $in['invoiceTypes']],
            [self::OID['registeredAddress'], $in['location']], [self::OID['businessCategory'], $in['industry']],
        ]);
        $extensions = Asn1::sequence(
            Asn1::sequence(Asn1::oid(self::OID['templateName']), Asn1::octets(Asn1::printable(Codes::ENVIRONMENTS[$in['environment']]['template']))),
            Asn1::sequence(Asn1::oid(self::OID['subjectAltName']), Asn1::octets(Asn1::sequence(Asn1::context(4, $directory)))),
        );
        $info = Asn1::sequence(
            Asn1::integer(0),
            $subject,
            Keys::publicDer($privatePem),
            Asn1::context(0, Asn1::sequence(Asn1::oid(self::OID['extensionRequest']), Asn1::set($extensions))),
        );
        $request = Asn1::sequence($info, Asn1::sequence(Asn1::oid(self::OID['ecdsaWithSha256'])), Asn1::bits(Keys::sign($privatePem, $info)));

        return Asn1::toPem($request, 'CERTIFICATE REQUEST');
    }

    /** What a request says, read back from its DER (for the screen and for the tests): subject, alternative name, template. */
    public static function read(string $pem): array
    {
        $offset = 0;
        [, $request] = Asn1::read(Asn1::fromPem($pem), $offset);
        [$info, , $signature] = Asn1::children($request);
        [, $subject, $spki, $attributes] = Asn1::children($info[1]);
        $out = ['subject' => self::attributes($subject[1]), 'alternative' => [], 'template' => null, 'publicKey' => $spki[2], 'info' => $info[2], 'signature' => substr($signature[1], 1)];
        foreach (Asn1::children($attributes[1]) as [, $attribute]) {
            [$type, $values] = Asn1::children($attribute);
            if (Asn1::oidText($type[1]) !== self::OID['extensionRequest']) {
                continue;
            }
            foreach (Asn1::children(Asn1::children($values[1])[0][1]) as [, $extension]) {
                $parts = Asn1::children($extension);
                $id = Asn1::oidText($parts[0][1]);
                $value = Asn1::children(end($parts)[1])[0];
                if ($id === self::OID['templateName']) {
                    $out['template'] = $value[1];
                } elseif ($id === self::OID['subjectAltName']) {
                    foreach (Asn1::children($value[1]) as [$tag, $general]) {
                        if ($tag === 0xA4) {
                            $out['alternative'] = self::attributes(Asn1::children($general)[0][1]);
                        }
                    }
                }
            }
        }

        return $out;
    }

    /** @return array<string, string> attribute type (short name or OID) → value */
    private static function attributes(string $name): array
    {
        $short = array_flip(self::OID);
        $out = [];
        foreach (Asn1::children($name) as [, $set]) {
            foreach (Asn1::children($set) as [, $pair]) {
                [$type, $value] = Asn1::children($pair);
                $oid = Asn1::oidText($type[1]);
                $out[$short[$oid] ?? $oid] = $value[1];
            }
        }

        return $out;
    }

    private static function checked(array $in): array
    {
        $in = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $in) + ['country' => 'SA'];
        $bad = function (string $path, string $ar, string $en): never {
            throw AppError::validation('EINVOICE_CSR_INPUT', $ar, $en, [['path' => $path, 'message' => $ar]]);
        };
        foreach (['commonName' => 'اسم الوحدة', 'branch' => 'اسم الفرع', 'organization' => 'اسم المنشأة', 'location' => 'عنوان الوحدة', 'industry' => 'النشاط'] as $key => $label) {
            if (! is_string($in[$key] ?? null) || $in[$key] === '' || mb_strlen($in[$key]) > 128) {
                $bad($key, "{$label} إلزامي ولا يزيد على 128 حرفًا", "{$key} is required (at most 128 characters)");
            }
        }
        if (! isset(Codes::ENVIRONMENTS[$in['environment'] ?? ''])) {
            $bad('environment', 'بيئة الهيئة غير معروفة', 'Unknown environment');
        }
        if (! Codes::vatNumber($in['vatNo'] ?? null)) {
            $bad('vatNo', 'الرقم الضريبي 15 رقمًا يبدأ وينتهي بالرقم 3', 'The VAT number has 15 digits, the first and the last being 3');
        }
        if (! preg_match('/^[01]{4}$/', (string) ($in['invoiceTypes'] ?? '')) || ! str_contains(substr($in['invoiceTypes'], 0, 2), '1')) {
            $bad('invoiceTypes', 'أنواع الفواتير أربعة أرقام من 0 و1 (ضريبية · مبسطة · 0 · 0) وأحد النوعين على الأقل مفعَّل', 'Invoice types are four digits of 0 and 1 (standard · simplified · 0 · 0) with at least one of the two on');
        }
        if (! preg_match('/^1-[^|]+\|2-[^|]+\|3-[^|]+$/u', (string) ($in['serial'] ?? ''))) {
            $bad('serial', 'الرقم التسلسلي للوحدة بالصيغة «1-المصنّع|2-الطراز|3-الرقم»', 'The unit serial has the form "1-maker|2-model|3-serial"');
        }
        if (! preg_match('/^[A-Z]{2}$/', (string) $in['country'])) {
            $bad('country', 'رمز الدولة حرفان', 'The country is a two-letter code');
        }
        // a member of a VAT group (the 11th digit of the group number is 1) names itself by its own 10-digit TIN
        if ($in['vatNo'][10] === '1' && ! preg_match('/^\d{10}$/', $in['branch'])) {
            $bad('branch', 'الرقم الضريبي لمجموعة ضريبية: خانة الفرع تحمل الرقم المميز (10 أرقام) لعضو المجموعة', 'A VAT group number: the branch field carries the 10-digit TIN of the group member');
        }

        return $in;
    }
}
