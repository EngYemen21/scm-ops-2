<?php

namespace Tests\Feature\Credit;

use App\Credit\Models\CreditBankLine;
use App\Credit\Models\CreditFile;
use App\Credit\Models\CreditNotification;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/**
 * What is personal on a credit request — identity numbers, bank accounts, the papers — and the papers and the signature
 * themselves: masked in every answer, read in full only by those who may (each read audited, never the value), kept
 * encrypted, never in the timeline, the audit or a notification; papers typed by their bytes, never deleted; a stage
 * that requires papers or a signature is not left without them; a signature covers what was signed.
 */
class CreditPrivacyDocumentsTest extends ApiTestCase
{
    use CreditFixtures;

    private const OWNER_ID = '1012345678';

    private const IBAN = 'SA0380000000608010167519';

    protected function setUp(): void
    {
        parent::setUp();
        $this->followOdoo(false);
        $this->plainPolicies();
    }

    public function test_personal_values_are_masked_encrypted_and_read_only_by_who_may(): void
    {
        $this->twoStagePath();
        $reader = $this->user('credread', ['page.credit']); // reads the page (an auditor), holds none of the credit permissions
        $r = $this->newRequest();
        $id = $r['id'];

        // every answer carries the mask, nowhere the value
        foreach (['sales', 'finance', $reader] as $who) {
            $body = $this->getAs($who, "/api/credit/requests/{$id}")->getContent();
            $this->assertStringNotContainsString(self::OWNER_ID, $body, $who);
            $this->assertStringNotContainsString(self::IBAN, $body, $who);
            $this->assertStringNotContainsString('12345678901234', $body, $who);
        }
        $detail = $this->request($id, $reader);
        $this->assertSame('••••••5678', $detail['ownerNationalId']['masked']);
        $this->assertFalse($detail['can']['reveal']);
        $this->assertSame('••••••••••••7519', $detail['bankLines'][0]['iban']['masked']);
        $this->assertNull($detail['notifications'], 'the notification log is for the reviewers');

        // encrypted at rest
        $raw = DB::table('credit_requests')->where('id', $id)->value('owner_national_id');
        $this->assertNotSame(self::OWNER_ID, $raw);
        $this->assertStringNotContainsString(self::IBAN, (string) DB::table('credit_bank_lines')->where('request_id', $id)->value('iban'));

        // the reader is refused, and the refusal is written down
        $this->expectRejected($this->getAs($reader, "/api/credit/requests/{$id}/private"), 'CREDIT_PRIVATE', [403]);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'SECURITY.UNAUTHORIZED')->where('new_value', 'like', '%values of '.$r['number'].'%')->exists());

        // the requester reads what he typed; the reviewer reads everything; each read audited with a count only
        $mine = $this->expectOk($this->getAs('sales', "/api/credit/requests/{$id}/private"));
        $this->assertSame(self::OWNER_ID, $mine['ownerNationalId']['value']);
        $this->assertSame(self::IBAN, $mine['bankLines'][0]['iban']['value']);
        $all = $this->expectOk($this->getAs('finance', "/api/credit/requests/{$id}/private"));
        $this->assertSame('2098765432', $all['responsibles'][0]['nationalId']['value']);
        $reads = DB::table('audit_logs')->where('entity_id', $id)->where('action', 'CREDIT.PRIVATE.READ')->pluck('new_value')->all();
        $this->assertCount(2, $reads);
        $this->assertStringContainsString('"values":4', $reads[1]);

        // a mask sent back keeps the value; nothing personal in the trail
        $line = CreditBankLine::where('request_id', $id)->sole();
        $this->expectOk($this->patchAs('sales', "/api/credit/requests/{$id}", ['ownerNationalId' => '••••••5678', 'city' => 'جدة',
            'bankLines' => [['id' => $line->id, 'bankName' => 'البنك الأهلي', 'iban' => '••••••••••••7519', 'accountNumber' => '••••1234']]]));
        $this->assertSame(self::OWNER_ID, $this->row($id)->owner_national_id);
        $this->assertSame(self::IBAN, CreditBankLine::find($line->id)->iban);
        $trail = $this->auditOf($id).DB::table('credit_events')->where('request_id', $id)->get()->toJson().DB::table('credit_notifications')->where('request_id', $id)->get()->toJson();
        foreach ([self::OWNER_ID, self::IBAN, '2098765432', '12345678901234'] as $secret) {
            $this->assertStringNotContainsString($secret, $trail);
        }
        // a new value replaces it, and says who typed it
        $this->expectOk($this->patchAs('sales', "/api/credit/requests/{$id}", ['ownerNationalId' => '1099999999']));
        $this->assertSame('1099999999', $this->row($id)->owner_national_id);
        $this->expectRejected($this->patchAs('sales', "/api/credit/requests/{$id}", ['bankLines' => [['bankName' => 'بنك', 'iban' => 'SA0000000000000000000000']]]), 'CREDIT_IBAN_INVALID', [400]);

        // a value the reviewer typed is not the requester's to read; once closed, nothing is his
        $this->expectOk($this->postAs('sales', "/api/credit/requests/{$id}/submit"));
        $this->expectOk($this->patchAs('finance', "/api/credit/requests/{$id}", ['responsibles' => [['name' => 'مسؤول جديد', 'nationalId' => '1077777777']]]));
        $mine = $this->expectOk($this->getAs('sales', "/api/credit/requests/{$id}/private"));
        $this->assertTrue($mine['responsibles'][0]['nationalId']['hidden']);
        $this->expectOk($this->postAs('finance', "/api/credit/requests/{$id}/reject", ['reason' => 'ضمانات غير كافية']));
        $this->expectRejected($this->getAs('sales', "/api/credit/requests/{$id}/private"), 'CREDIT_PRIVATE', [403]);
    }

    public function test_papers_are_typed_by_their_bytes_replaced_never_deleted_and_read_only_by_who_may(): void
    {
        $this->twoStagePath();
        $reader = $this->user('credread', ['page.credit']);
        $r = $this->newRequest();
        $id = $r['id'];
        $this->expectRejected($this->postAs('sales', "/api/credit/requests/{$id}/documents", ['slot' => 'owner_id', 'fileName' => 'id.pdf', 'base64' => base64_encode('just text, not a paper')]), 'CREDIT_FILE_TYPE', [400]);
        $this->expectRejected($this->postAs('sales', "/api/credit/requests/{$id}/documents", ['slot' => 'nine', 'fileName' => 'x.pdf', 'base64' => self::pdf()]), null, [400]);
        $this->expectRejected($this->postAs($reader, "/api/credit/requests/{$id}/documents", ['slot' => 'owner_id', 'fileName' => 'id.pdf', 'base64' => self::pdf()]), 'CREDIT_NOT_YOURS', [403]);

        $d = $this->expectOk($this->postAs('sales', "/api/credit/requests/{$id}/documents", ['slot' => 'owner_id', 'fileName' => 'هوية صالح.pdf', 'base64' => 'data:application/pdf;base64,'.self::pdf()]));
        $slot = collect($d['documents'])->firstWhere('slot', 'owner_id');
        $this->assertTrue($slot['filled']);
        $file = $slot['files'][0];
        $this->assertSame(['application/pdf', 'هوية صالح.pdf', true], [$file['mime'], $file['name'], $file['canOpen']]);
        $this->assertDatabaseMissing('attachments', ['entity_id' => $id]);

        // the reader sees that the slot is filled — not the name, not the file
        $seen = collect($this->request($id, $reader)['documents'])->firstWhere('slot', 'owner_id')['files'][0];
        $this->assertSame([null, false, null], [$seen['name'], $seen['canOpen'], $seen['url']]);
        $this->expectRejected($this->getAs($reader, "/api/credit/requests/{$id}/documents/{$file['id']}"), 'CREDIT_PRIVATE', [403]);
        $res = $this->getAs('finance', "/api/credit/requests/{$id}/documents/{$file['id']}");
        $res->assertOk();
        $this->assertStringStartsWith('%PDF-', $res->getContent());
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $this->assertTrue(DB::table('audit_logs')->where('entity_id', $id)->where('action', 'CREDIT.FILE.READ')->exists());

        // a new paper in the slot replaces the old one, which stays
        $this->expectOk($this->postAs('sales', "/api/credit/requests/{$id}/documents", ['slot' => 'owner_id', 'fileName' => 'id2.png', 'base64' => self::proofImage()]));
        $this->assertSame(['replaced', 'current'], CreditFile::where('request_id', $id)->where('slot', 'owner_id')->orderBy('created_at')->orderBy('id')->pluck('status')->all());
        // removed with a reason, kept
        $current = CreditFile::where('request_id', $id)->where('slot', 'owner_id')->where('status', 'current')->sole();
        $this->expectOk($this->postAs('sales', "/api/credit/requests/{$id}/documents/{$current->id}/remove", ['reason' => 'صورة غير واضحة']));
        $this->assertSame(2, CreditFile::where('request_id', $id)->where('slot', 'owner_id')->count());
        $this->assertSame('removed', CreditFile::find($current->id)->status);
        $this->expectRejected($this->postAs('sales', "/api/credit/requests/{$id}/documents/{$current->id}/remove"), 'CREDIT_FILE_CLOSED', [422]);
        // the trail names the slot, never the file's name
        $this->assertStringNotContainsString('هوية صالح', $this->auditOf($id).DB::table('credit_events')->where('request_id', $id)->get()->toJson(JSON_UNESCAPED_UNICODE));
    }

    public function test_a_stage_that_requires_papers_is_not_left_without_them(): void
    {
        $this->twoStagePath(['require_documents' => true]);
        DB::table('credit_settings')->where('key', 'documents')->update(['value' => json_encode(['required' => ['commercial_registration', 'vat_certificate']])]);
        $r = $this->newRequest();
        $id = $r['id'];
        $this->expectOk($this->postAs('sales', "/api/credit/requests/{$id}/submit"));
        $res = $this->expectRejected($this->postAs('finance', "/api/credit/requests/{$id}/approve"), 'CREDIT_DOCUMENTS_REQUIRED', [422]);
        $this->assertSame(['commercial_registration', 'vat_certificate'], $res['details']['missing']);
        $this->assertSame('in_review', $this->row($id)->state);
        $this->assertSame('pending', DB::table('credit_approvals')->where('request_id', $id)->value('state'));
        foreach (['commercial_registration', 'vat_certificate'] as $slot) {
            $this->expectOk($this->postAs('finance', "/api/credit/requests/{$id}/documents", ['slot' => $slot, 'fileName' => "{$slot}.pdf", 'base64' => self::pdf()]));
        }
        $this->assertTrue((bool) $this->row($id)->attachments_complete);
        $this->assertSame('ceo_approval', $this->expectOk($this->postAs('finance', "/api/credit/requests/{$id}/approve"))['state']);
    }

    public function test_the_signature_is_required_issued_signed_by_hand_and_covers_what_was_signed(): void
    {
        $this->path([['sign', 'pending_signature', ['responsible_role' => 'finance', 'require_signature' => true]], ['final', 'ceo_approval', ['responsible_role' => 'gm']]]);
        $r = $this->newRequest();
        $id = $r['id'];
        $this->expectOk($this->postAs('sales', "/api/credit/requests/{$id}/submit"));
        $this->expectOk($this->postAs('finance', "/api/credit/requests/{$id}/assess", ['approvedCreditLimit' => 30000, 'approvedPaymentDays' => 30]));
        $this->expectRejected($this->postAs('finance', "/api/credit/requests/{$id}/approve"), 'CREDIT_SIGNATURE_REQUIRED', [422]);

        $this->expectRejected($this->postAs('sales', "/api/credit/requests/{$id}/envelopes"), 'CREDIT_SIGNATURE_STAFF', [403]);
        $d = $this->expectOk($this->postAs('finance', "/api/credit/requests/{$id}/envelopes", ['signerName' => 'سعد المفوض']));
        $env = $d['envelopes'][0];
        $this->assertSame(['sent', 'manual', 'سعد المفوض'], [$env['status'], $env['provider'], $env['signerName']]);
        $this->assertSame('sent', $d['signatureStatus']);
        $this->expectRejected($this->postAs('finance', "/api/credit/requests/{$id}/envelopes"), 'CREDIT_ENVELOPE_ACTIVE', [422]);
        $this->expectOk($this->postAs('finance', "/api/credit/requests/{$id}/envelopes/{$env['id']}/delivered"));
        // the requester brings the signed copy back
        $d = $this->expectOk($this->postAs('sales', "/api/credit/requests/{$id}/envelopes/{$env['id']}/signed", ['fileName' => 'signed.pdf', 'base64' => self::pdf()]));
        $this->assertSame('signed', $d['envelopes'][0]['status']);
        $this->assertNotNull($d['envelopes'][0]['signedFileId']);
        $this->assertTrue($d['requirements']['signature']['ok']);
        $this->expectRejected($this->postAs('finance', "/api/credit/requests/{$id}/envelopes/{$env['id']}/delivered"), 'CREDIT_ENVELOPE_TRANSITION', [422]);
        $this->expectRejected($this->postAs('finance', "/api/credit/requests/{$id}/envelopes"), 'CREDIT_ALREADY_SIGNED', [422]);

        // the limit changes after the customer signed: the signature no longer covers it
        $this->expectOk($this->postAs('finance', "/api/credit/requests/{$id}/assess", ['approvedCreditLimit' => 35000]));
        $this->expectRejected($this->postAs('finance', "/api/credit/requests/{$id}/approve"), 'CREDIT_SIGNATURE_STALE', [422]);
        $this->assertTrue($this->request($id)['envelopes'][0]['stale']);
        // put back what was signed: the signature covers it again
        $this->expectOk($this->postAs('finance', "/api/credit/requests/{$id}/assess", ['approvedCreditLimit' => 30000]));
        $this->assertSame('ceo_approval', $this->expectOk($this->postAs('finance', "/api/credit/requests/{$id}/approve"))['state']);

        // the bilingual printable agreement: both languages, the figures, no identity number
        $a = $this->expectOk($this->getAs('sales', "/api/credit/requests/{$id}/agreement"));
        $this->assertStringContainsString('اتفاقية حساب ائتمان آجل', $a['html']);
        $this->assertStringContainsString('Deferred Credit Account Agreement', $a['html']);
        $this->assertStringContainsString('30,000.00', $a['html']);
        $this->assertStringNotContainsString(self::OWNER_ID, $a['html']);
        $this->assertSame('30000.00', $a['agreement']['credit']['limit']);
    }

    public function test_messages_that_leave_the_system_are_logged_as_skipped_until_a_provider_is_configured(): void
    {
        $this->twoStagePath(['portal_visible' => true]);
        $r = $this->newRequest();
        $this->expectOk($this->postAs('sales', "/api/credit/requests/{$r['id']}/submit"));
        $logs = CreditNotification::where('request_id', $r['id'])->get();
        $this->assertGreaterThan(0, $logs->where('channel', 'in_app')->where('status', 'sent')->count());
        $out = $logs->whereIn('channel', ['email', 'whatsapp']);
        $this->assertGreaterThan(0, $out->count());
        $this->assertSame(['skipped'], $out->pluck('status')->unique()->values()->all());
        $this->assertSame('no provider is configured for this channel', $out->first()->error_message);
        $this->assertSame(0, CreditNotification::where('request_id', $r['id'])->where('channel', '!=', 'in_app')->where('status', 'sent')->count());
        $lookups = $this->expectOk($this->getAs('sales', '/api/credit/lookups'));
        $this->assertSame(['inApp' => true, 'email' => false, 'whatsapp' => false], $lookups['channels']);
        $this->assertSame(['key' => 'manual', 'configured' => true, 'remote' => false], array_intersect_key($lookups['signatureProvider'], array_flip(['key', 'configured', 'remote'])));
    }
}
