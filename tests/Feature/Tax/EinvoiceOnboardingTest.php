<?php

namespace Tests\Feature\Tax;

use App\Models\AuditLog;
use App\Tax\Models\Einvoice;
use App\Tax\Models\EinvoiceAttempt;
use App\Tax\Models\EinvoiceUnit;
use App\Tax\Services\EinvoiceSubmitter;
use App\Tax\Zatca\Asn1;
use App\Tax\Zatca\Certificate;
use App\Tax\Zatca\Csr;
use App\Tax\Zatca\Keys;
use App\Tax\Zatca\Qr;
use App\Tax\Zatca\Signer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\ApiTestCase;

/**
 * A unit onboarded with the authority — against a FAKE authority (Http::fake: nothing ever reaches the real gateway).
 * The certificate the fake answers with is issued here by a test CA for the unit's own request, so the key, the
 * request, the certificate, the stamp and the QR code are the real thing end to end. What is proven: the five steps
 * of the onboarding, the secrets never leave the server (not in an answer, not in the audit trail, not readable in the
 * database), the stamp verifies, and the queue turns every answer of the authority into the right status.
 */
class EinvoiceOnboardingTest extends ApiTestCase
{
    use TaxFixtures;

    private const GATEWAY = 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal/';

    /** @var array<string, array<int, array{0:int, 1:array}>> path → answers still to give */
    private static array $answers = [];

    /** @var Request[] */
    private static array $calls = [];

    private static ?string $unitId = null;

    private static string $token = '';

    private static string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        self::$tag ??= self::uid();
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            self::$calls[] = $request;
            $path = substr($request->url(), strlen(self::GATEWAY));
            $next = array_shift(self::$answers[$path]);
            if ($next === null) {
                return Http::response(['message' => 'no answer prepared for '.$path], 500);
            }

            return Http::response($next[1], $next[0]);
        });
    }

    private function answer(string $path, int $status, array $body): void
    {
        self::$answers[$path][] = [$status, $body];
    }

    /** A certificate of a test CA for the unit's own request — what the authority's CA would issue. */
    private static function issue(string $csrPem): string
    {
        $cnf = tempnam(sys_get_temp_dir(), 'ossl');
        file_put_contents($cnf, "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=x\n[v3]\nbasicConstraints=CA:FALSE\n");
        $caKey = openssl_pkey_get_private(Keys::generate());
        $caCsr = openssl_csr_new(['commonName' => 'TEST-SubCA-1', 'domainComponent' => 'test'], $caKey, ['config' => $cnf, 'digest_alg' => 'sha256']);
        $ca = openssl_csr_sign($caCsr, null, $caKey, 3650, ['config' => $cnf, 'digest_alg' => 'sha256'], 1);
        $cert = openssl_csr_sign($csrPem, $ca, $caKey, 365, ['config' => $cnf, 'digest_alg' => 'sha256', 'x509_extensions' => 'v3'], random_int(1000, 999999));
        @unlink($cnf);
        while (openssl_error_string() !== false) {
        }
        openssl_x509_export($cert, $pem);

        // the authority's binarySecurityToken: the base64 of the certificate's base64 text
        return base64_encode(base64_encode(Asn1::fromPem($pem)));
    }

    public function test_the_five_steps_of_onboarding_and_the_secrets_stay_inside(): void
    {
        $this->completeProfile();
        $tag = self::$tag;
        $this->expectRejected($this->postAs('gm', '/api/tax/units', ['name' => "EGS-{$tag}"]), 'FORBIDDEN', [403]);
        $u = $this->expectOk($this->postAs('finance', '/api/tax/units', ['name' => "EGS-{$tag}", 'environment' => 'sandbox', 'invoiceTypes' => '1100', 'serial' => "1-OPS|2-T|3-{$tag}"]));
        self::$unitId = $u['id'];
        $this->assertSame(['not_onboarded', false], [$u['status'], $u['onboarded']]);
        // the request needs where the unit is and what the company does
        $this->expectRejected($this->postAs('finance', "/api/tax/units/{$u['id']}/request"), 'EINVOICE_UNIT_INVALID', [400]);
        $this->expectOk($this->patchAs('finance', "/api/tax/units/{$u['id']}", ['branchName' => 'الفرع الرئيسي', 'location' => 'RRRD2929', 'industry' => 'Food distribution']));
        $u = $this->expectOk($this->postAs('finance', "/api/tax/units/{$u['id']}/request"));
        $this->assertSame(['csr_ready', true, true, false], [$u['status'], $u['holds']['key'], $u['holds']['request'], $u['holds']['complianceCertificate']]);
        $unit = EinvoiceUnit::find($u['id']);
        $csr = Csr::read((string) $unit->csr);
        $this->assertSame(['SA', 'الفرع الرئيسي', 'OPS Test Trading Co.', "EGS-{$tag}"], [$csr['subject']['C'], $csr['subject']['OU'], $csr['subject']['O'], $csr['subject']['CN']]);
        $this->assertSame(["1-OPS|2-T|3-{$tag}", '399999999900003', '1100', 'RRRD2929', 'Food distribution'], [$csr['alternative']['SN'], $csr['alternative']['UID'], $csr['alternative']['title'], $csr['alternative']['registeredAddress'], $csr['alternative']['businessCategory']]);
        $this->assertSame('TSTZATCA-Code-Signing', $csr['template']);
        $this->assertTrue(Keys::verify($csr['publicKey'], $csr['info'], $csr['signature']), 'the request is signed by the unit key');

        // compliance certificate, for the portal's one-time password
        self::$token = self::issue((string) $unit->csr);
        $this->expectRejected($this->postAs('finance', "/api/tax/units/{$u['id']}/compliance", ['otp' => '12']), 'EINVOICE_OTP', [400]);
        $this->answer('compliance', 400, ['errors' => [['code' => 'Invalid-OTP', 'message' => 'Invalid OTP']]]);
        $this->expectRejected($this->postAs('finance', "/api/tax/units/{$u['id']}/compliance", ['otp' => '654321']), 'EINVOICE_AUTHORITY_REFUSED', [422]);
        $this->answer('compliance', 200, ['requestID' => 1234567890123456789, 'dispositionMessage' => 'ISSUED', 'binarySecurityToken' => self::$token, 'secret' => 'compliance-secret-'.$tag]);
        $u = $this->expectOk($this->postAs('finance', "/api/tax/units/{$u['id']}/compliance", ['otp' => '123456']));
        $this->assertSame(['compliance', true], [$u['status'], $u['holds']['complianceCertificate']]);
        $this->assertNotNull($u['certificate']['serial']);
        $call = collect(self::$calls)->last();
        $this->assertSame(['123456', 'V2'], [$call->header('OTP')[0], $call->header('Accept-Version')[0]]);
        $this->assertSame((string) $unit->csr, base64_decode($call->data()['csr']));
        $this->assertSame('1234567890123456789', EinvoiceUnit::find($u['id'])->compliance_request_id, 'a long request id stays exact');

        // compliance checks: an invoice, a credit note and a debit note of each kind the unit issues, stamped
        for ($i = 0; $i < 6; $i++) {
            $this->answer('compliance/invoices', 200, ['validationResults' => ['status' => 'PASS', 'infoMessages' => [['type' => 'INFO', 'code' => 'XSD_ZATCA_VALID', 'message' => 'Complied with UBL 2.1']], 'errorMessages' => [], 'warningMessages' => []],
                'reportingStatus' => 'REPORTED', 'clearanceStatus' => null]);
        }
        $u = $this->expectOk($this->postAs('finance', "/api/tax/units/{$u['id']}/checks"));
        $this->assertSame(['compliant', true], [$u['status'], $u['checksPassed']]);
        $this->assertEqualsCanonicalizing(['standard.invoice', 'standard.credit_note', 'standard.debit_note', 'simplified.invoice', 'simplified.credit_note', 'simplified.debit_note'], array_keys($u['complianceChecks']));
        $samples = array_slice(self::$calls, -6);
        foreach ($samples as $s) {
            $this->assertSame('Basic '.base64_encode(self::$token.':compliance-secret-'.$tag), $s->header('Authorization')[0]);
            $xml = base64_decode($s->data()['invoice']);
            $check = Signer::inspect($xml);
            $this->assertSame([true, true, true, true, true, $s->data()['invoiceHash']], [$check['stamped'], $check['digestMatches'], $check['signatureValid'], $check['propertiesDigestMatches'], $check['certificateDigestMatches'], $check['hash']]);
        }
        $this->assertSame(['383', '381', '388'], array_values(array_unique(array_map(fn ($s) => preg_match('/<cbc:InvoiceTypeCode name="\d+">(\d+)</', base64_decode($s->data()['invoice']), $m) ? $m[1] : null, array_reverse($samples)))));

        // production certificate: the unit is onboarded
        $this->answer('production/csids', 200, ['requestID' => 99, 'dispositionMessage' => 'ISSUED', 'binarySecurityToken' => self::$token, 'secret' => 'production-secret-'.$tag]);
        $u = $this->expectOk($this->postAs('finance', "/api/tax/units/{$u['id']}/production"));
        $this->assertSame(['active', true, true], [$u['status'], $u['onboarded'], $u['holds']['productionCertificate']]);
        $this->assertSame('1234567890123456789', collect(self::$calls)->last()->data()['compliance_request_id']);
        $this->expectOk($this->patchAs('finance', "/api/tax/units/{$u['id']}", ['isDefault' => true]));
        $this->expectRejected($this->patchAs('finance', "/api/tax/units/{$u['id']}", ['name' => 'اسم جديد']), 'EINVOICE_UNIT_ISSUED', [422]);

        // THE SECRETS: never in an answer, never in the audit trail, never readable in the database
        $raw = DB::table('tax_einvoice_units')->where('id', $u['id'])->first();
        $unit = EinvoiceUnit::find($u['id']);
        foreach (['private_key' => 'PRIVATE KEY', 'csr' => 'CERTIFICATE REQUEST', 'production_token' => self::$token, 'production_secret' => 'production-secret-'] as $column => $plain) {
            $this->assertStringNotContainsString($plain, (string) $raw->{$column}, "{$column} is stored encrypted");
            $this->assertStringContainsString($plain, (string) $unit->{$column});
        }
        $bodies = [$this->getAs('finance', "/api/tax/units/{$u['id']}")->getContent(), $this->getAs('finance', '/api/tax/units')->getContent(), $this->getAs('finance', '/api/tax/einvoices/summary')->getContent(),
            $this->getAs('finance', '/api/tax/overview')->getContent(), json_encode(AuditLog::where('entity_type', 'EinvoiceUnit')->where('entity_id', $u['id'])->get(['old_value', 'new_value'])),
            json_encode(EinvoiceAttempt::where('unit_id', $u['id'])->get()), json_encode(DB::table('idempotency_keys')->get(['response_body']))];
        foreach ($bodies as $body) {
            foreach (['PRIVATE KEY', 'CERTIFICATE REQUEST', self::$token, 'compliance-secret-', 'production-secret-', '123456'] as $secret) {
                $this->assertStringNotContainsString($secret, (string) $body);
            }
        }
    }

    public function test_documents_are_stamped_and_every_answer_of_the_authority_has_its_status(): void
    {
        $tag = self::$tag;
        $c = $this->customer("O{$tag}", ['vat_no' => '300011112222003', 'address' => 'شارع التحلية', 'city' => 'الرياض']);
        DB::table('tax_parties')->insert(['id' => (string) Str::ulid(), 'partner_type' => 'customer', 'partner_id' => $c->id, 'invoice_kind' => 'auto', 'street' => 'شارع التحلية',
            'building_no' => '1111', 'district' => 'السليمانية', 'city' => 'الرياض', 'postal_code' => '12244', 'country_code' => 'SA', 'created_at' => now()]);
        $walkIn = $this->customer("OW{$tag}");
        $std = $this->expectOk($this->postAs('finance', '/api/tax/einvoices/make', ['invoice' => $this->customerInvoice($c, '2026-09-15', 3, '100.00')->number]));
        $this->assertSame(['queued', true, self::$unitId, 0], [$std['status'], $std['signed'], $std['unitId'], $std['issueCount']['errors']]);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8], array_keys(Qr::decode($std['qr'])), 'a standard invoice: the stamp fields, no CA signature');
        $sim = $this->expectOk($this->postAs('finance', '/api/tax/einvoices/make', ['invoice' => $this->customerInvoice($walkIn, '2026-09-15', 1, '20.00')->number]));
        $qr = Qr::decode($sim['qr']);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9], array_keys($qr), 'a simplified invoice also carries the CA signature on the certificate');
        $this->assertSame(Certificate::fromToken(self::$token)->signature(), $qr[9]);
        $this->assertSame($sim['hash'], $qr[6]);
        $xml = Einvoice::find($sim['id'])->xml;
        $this->assertSame([true, true, true, true], array_values(array_intersect_key(Signer::inspect($xml), array_flip(['digestMatches', 'signatureValid', 'propertiesDigestMatches', 'certificateDigestMatches']))));

        // the standard one is cleared (the stamped copy is kept), the simplified one reported with a warning
        $this->answer('invoices/clearance/single', 200, ['clearanceStatus' => 'CLEARED', 'clearedInvoice' => base64_encode('<Invoice>cleared</Invoice>'), 'validationResults' => ['status' => 'PASS', 'infoMessages' => [], 'warningMessages' => [], 'errorMessages' => []]]);
        $this->answer('invoices/reporting/single', 202, ['reportingStatus' => 'REPORTED', 'validationResults' => ['status' => 'WARNING', 'warningMessages' => [['type' => 'WARNING', 'code' => 'BR-KSA-08', 'category' => 'KSA', 'message' => 'seller id']], 'errorMessages' => []]]);
        $out = app(EinvoiceSubmitter::class)->run();
        $this->assertSame([2, 1, 1], [$out['sent'], $out['accepted'], $out['warnings']]);
        $std = $this->expectOk($this->getAs('finance', "/api/tax/einvoices/{$std['id']}"));
        $this->assertSame(['cleared', 'clearance', true, 200], [$std['status'], $std['via'], $std['hasClearedXml'], $std['httpStatus']]);
        $this->assertSame('<Invoice>cleared</Invoice>', $this->get("/api/tax/einvoices/{$std['id']}/xml?cleared=1", ['Authorization' => 'Bearer '.$this->token('finance')])->getContent());
        $this->assertSame(['accepted_warnings', 1, 'BR-KSA-08'], [Einvoice::find($sim['id'])->status, Einvoice::find($sim['id'])->warnings, Einvoice::find($sim['id'])->messages[0]['code']]);
        $calls = array_slice(self::$calls, -2);
        $this->assertSame(['1', '0'], [$calls[0]->header('Clearance-Status')[0], $calls[1]->header('Clearance-Status')[0]]);
        $this->assertSame([$std['uuid'], $std['hash']], [$calls[0]->data()['uuid'], $calls[0]->data()['invoiceHash']]);
        $this->assertSame('Basic '.base64_encode(self::$token.':production-secret-'.$tag), $calls[0]->header('Authorization')[0]);
        // an accepted document is corrected by a credit note, never re-made
        $this->expectRejected($this->postAs('finance', "/api/tax/einvoices/{$std['id']}/remake", ['reason' => 'محاولة بعد القبول']), 'EINVOICE_ACCEPTED', [422]);

        // no answer: it stays queued and waits; a refusal is final for that document and a corrected one is made
        $next = $this->expectOk($this->postAs('finance', '/api/tax/einvoices/make', ['invoice' => $this->customerInvoice($c, '2026-09-16', 1, '50.00')->number]));
        $this->answer('invoices/clearance/single', 503, ['message' => 'Service Unavailable']);
        $out = app(EinvoiceSubmitter::class)->run();
        $this->assertSame([1, 1], [$out['sent'], $out['retry']]);
        $row = Einvoice::find($next['id']);
        $this->assertSame(['queued', 1], [$row->status, $row->attempts]);
        $this->assertTrue($row->next_attempt_at->isFuture());
        $calls = count(self::$calls);
        $this->assertSame(0, app(EinvoiceSubmitter::class)->run()['sent'], 'not due yet: nothing is sent');
        $this->assertSame($calls, count(self::$calls));
        Carbon::setTestNow(now()->addMinutes(2));
        $this->answer('invoices/clearance/single', 400, ['clearanceStatus' => 'NOT_CLEARED', 'validationResults' => ['status' => 'ERROR', 'errorMessages' => [['type' => 'ERROR', 'code' => 'BR-KSA-37', 'category' => 'KSA', 'message' => 'building number']]]]);
        $this->assertSame(1, app(EinvoiceSubmitter::class)->run()['rejected']);
        $row->refresh();
        $this->assertSame(['rejected', 2, 400], [$row->status, $row->attempts, $row->http_status]);
        $this->assertStringContainsString('BR-KSA-37', (string) $row->last_error);
        $fixed = $this->expectOk($this->postAs('finance', "/api/tax/einvoices/{$next['id']}/remake", ['reason' => 'تصحيح بعد رفض الهيئة']));
        $this->assertSame(['queued', $row->icv + 1, $row->hash], [$fixed['status'], $fixed['icv'], $fixed['previousHash']]);
        $this->assertSame('rejected', Einvoice::find($next['id'])->status, 'the rejected document keeps its status and its place');
        // taken out of the queue before it leaves, with a reason
        $this->expectRejected($this->postAs('finance', "/api/tax/einvoices/{$fixed['id']}/unqueue"), 'REASON_REQUIRED', [400]);
        $this->assertSame('not_sent', $this->expectOk($this->postAs('finance', "/api/tax/einvoices/{$fixed['id']}/unqueue", ['reason' => 'مراجعة قبل الإرسال']))['status']);
        $this->assertSame('queued', $this->expectOk($this->postAs('finance', "/api/tax/einvoices/{$fixed['id']}/queue"))['status']);
        $this->assertTrue($this->expectOk($this->getAs('finance', '/api/tax/einvoices/chain?unit='.self::$unitId))['ok']);

        // the unit is retired: its documents and its chain stay, and OPS is back to "not onboarded"
        $this->expectRejected($this->postAs('finance', '/api/tax/units/'.self::$unitId.'/retire'), 'REASON_REQUIRED', [400]);
        $u = $this->expectOk($this->postAs('finance', '/api/tax/units/'.self::$unitId.'/retire', ['reason' => 'نهاية اختبار الربط']));
        $this->assertSame(['retired', false], [$u['status'], $u['isDefault']]);
        $this->assertSame('not_onboarded', $this->expectOk($this->getAs('finance', '/api/tax/einvoices/summary'))['mode']);
        foreach (self::$calls as $call) {
            $this->assertStringStartsWith(self::GATEWAY, $call->url(), 'only the sandbox of the fake authority was ever called');
        }
    }
}
