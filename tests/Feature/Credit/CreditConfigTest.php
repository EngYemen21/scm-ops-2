<?php

namespace Tests\Feature\Credit;

use App\Credit\Models\CreditStage;
use App\Credit\Models\CreditTermsVersion;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/**
 * What the section is made of, set by its manager: the workflow is data and checked as data; terms versions are
 * frozen once published and a request keeps the version its customer accepted; payment methods and policies are
 * archived / validated, never deleted. Every change audited; anybody else is refused and nothing changes.
 */
class CreditConfigTest extends ApiTestCase
{
    use CreditFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->followOdoo(false);
        $this->plainPolicies();
    }

    public function test_the_workflow_is_checked_as_data(): void
    {
        $tag = self::tag();
        $code = "qa_stage_{$tag}";
        $s = $this->expectOk($this->postAs('admin', '/api/credit/stages', ['code' => $code, 'nameAr' => 'مراجعة قانونية', 'nameEn' => 'Legal review', 'state' => 'in_review',
            'responsibleRole' => 'finance', 'slaHours' => 8, 'reminderBeforeHours' => 2, 'escalateAfterHours' => 4, 'escalationRole' => 'gm', 'sequence' => 500]));
        $this->assertSame(['in_review', 'finance', 8.0], [$s['state'], $s['responsibleRole']['key'], (float) $s['slaHours']]);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'CREDIT.STAGE.CREATE')->where('entity_id', $s['id'])->exists());

        $this->expectRejected($this->postAs('admin', '/api/credit/stages', ['code' => $code, 'nameAr' => 'مكرر', 'state' => 'in_review']), 'CREDIT_STAGE_CODE_TAKEN', [409]);
        $this->expectRejected($this->postAs('admin', '/api/credit/stages', ['code' => 'Bad Code!', 'nameAr' => 'س', 'state' => 'in_review']), 'CREDIT_CODE_INVALID', [400]);
        $this->expectRejected($this->postAs('admin', '/api/credit/stages', ['code' => "qa_r_{$tag}", 'nameAr' => 'س', 'state' => 'in_review', 'responsibleRole' => 'no_such_role']), 'CREDIT_ROLE_UNKNOWN', [400]);
        $this->expectRejected($this->postAs('admin', '/api/credit/stages', ['code' => "qa_t_{$tag}", 'nameAr' => 'س', 'state' => 'in_review', 'slaHours' => 4, 'reminderBeforeHours' => 4]), 'CREDIT_STAGE_SLA', [400]);
        $this->expectRejected($this->postAs('admin', '/api/credit/stages', ['code' => "qa_u_{$tag}", 'nameAr' => 'س', 'state' => 'in_review', 'reminderBeforeHours' => 1]), 'CREDIT_STAGE_SLA', [400]);
        $this->expectRejected($this->postAs('admin', '/api/credit/stages', ['code' => "qa_v_{$tag}", 'nameAr' => 'س', 'state' => 'draft', 'requireSignature' => true]), 'CREDIT_STAGE_SIGNATURE', [400]);
        // one active stage per state a request is sent to
        $this->expectRejected($this->postAs('admin', '/api/credit/stages', ['code' => "qa_w_{$tag}", 'nameAr' => 'معتمد آخر', 'state' => 'approved']), 'CREDIT_STAGE_STATE_TAKEN', [409]);
        $approved = CreditStage::where('active', true)->where('state', 'approved')->sole();
        $this->expectRejected($this->patchAs('admin', "/api/credit/stages/{$approved->id}", ['active' => false]), 'CREDIT_STAGE_REQUIRED', [422]);

        // a stage a request stands in is not archived
        $ids = $this->path([['busy', 'in_review', ['responsible_role' => 'finance']], ['final', 'ceo_approval', ['responsible_role' => 'gm']]]);
        $busy = CreditStage::findOrFail($ids['busy']);
        $r = $this->newRequest();
        $this->expectOk($this->postAs('sales', "/api/credit/requests/{$r['id']}/submit"));
        $this->expectRejected($this->patchAs('admin', "/api/credit/stages/{$busy->id}", ['active' => false]), 'CREDIT_STAGE_IN_USE', [422]);
        $this->assertTrue((bool) CreditStage::find($busy->id)->active);
        $out = $this->expectOk($this->patchAs('admin', "/api/credit/stages/{$busy->id}", ['slaHours' => 24, 'nameEn' => 'Busy review']));
        $this->assertSame(1, $out['requests']);

        // not the manager: refused, nothing changed
        $this->expectRejected($this->patchAs('gm', "/api/credit/stages/{$busy->id}", ['slaHours' => 1]), 'FORBIDDEN', [403]);
        $this->assertSame(24.0, (float) CreditStage::find($busy->id)->sla_hours);
    }

    public function test_terms_are_frozen_once_published_and_the_request_keeps_what_its_customer_accepted(): void
    {
        $this->twoStagePath();
        $tag = self::tag();
        $v1 = $this->expectOk($this->postAs('admin', '/api/credit/terms', ['name' => 'شروط الائتمان', 'version' => "1.{$tag}", 'effectiveDate' => '2026-09-01',
            'termsAr' => "البند الأول: السداد خلال المدة.\nالبند الثاني: غرامة التأخير.", 'termsEn' => "Clause 1: payment within the term.\nClause 2: late fee.", 'jurisdictionCity' => 'الرياض']));
        $this->assertSame('draft', $v1['status']);
        $this->expectOk($this->patchAs('admin', "/api/credit/terms/{$v1['id']}", ['jurisdictionCity' => 'جدة']));
        $this->expectRejected($this->postAs('admin', '/api/credit/terms', ['name' => 'x', 'version' => "1.{$tag}", 'effectiveDate' => '2026-09-01']), 'CREDIT_TERMS_VERSION_TAKEN', [409]);
        $v1 = $this->expectOk($this->postAs('admin', "/api/credit/terms/{$v1['id']}/publish"));
        $this->assertSame(['published', true], [$v1['status'], $v1['inForce']]);
        $this->assertSame(CreditTermsVersion::hashOf("1.{$tag}", $v1['termsAr'], $v1['termsEn'], 'جدة'), $v1['hash']);
        $this->expectRejected($this->patchAs('admin', "/api/credit/terms/{$v1['id']}", ['termsAr' => 'نص آخر']), 'CREDIT_TERMS_FROZEN', [422]);
        $this->expectRejected($this->postAs('admin', "/api/credit/terms/{$v1['id']}/publish"), 'CREDIT_TERMS_TRANSITION', [422]);

        // a request is not sent before its customer accepted the version in force
        $r = $this->newRequest();
        $this->expectRejected($this->postAs('sales', "/api/credit/requests/{$r['id']}/submit"), 'CREDIT_TERMS_NOT_ACCEPTED', [422]);
        $d = $this->expectOk($this->postAs('sales', "/api/credit/requests/{$r['id']}/accept-terms", ['versionId' => $v1['id']]));
        $this->assertSame("1.{$tag}", $d['terms']['accepted']['version']);
        $this->assertTrue($d['terms']['current']);

        // a new version comes into force: the request that accepted the old one must accept again before it is sent
        $v2 = $this->expectOk($this->postAs('admin', '/api/credit/terms', ['name' => 'شروط الائتمان', 'version' => "2.{$tag}", 'effectiveDate' => '2026-09-10', 'termsAr' => 'نص 2', 'termsEn' => 'Text 2']));
        $this->expectOk($this->postAs('admin', "/api/credit/terms/{$v2['id']}/publish"));
        $this->expectRejected($this->postAs('sales', "/api/credit/requests/{$r['id']}/submit"), 'CREDIT_TERMS_OUTDATED', [422]);
        $this->expectRejected($this->postAs('sales', "/api/credit/requests/{$r['id']}/accept-terms", ['versionId' => $v1['id']]), 'CREDIT_TERMS_OUTDATED', [422]);
        $this->expectOk($this->postAs('sales', "/api/credit/requests/{$r['id']}/accept-terms"));
        $this->expectOk($this->postAs('sales', "/api/credit/requests/{$r['id']}/submit"));
        $this->expectRejected($this->postAs('sales', "/api/credit/requests/{$r['id']}/accept-terms"), 'CREDIT_TERMS_STATE', [422]);

        // retiring the version in force never rewrites what a request accepted: the agreement prints the frozen text
        $this->expectOk($this->postAs('admin', "/api/credit/terms/{$v2['id']}/retire", ['reason' => 'تحديث']));
        $a = $this->expectOk($this->getAs('sales', "/api/credit/requests/{$r['id']}/agreement"))['agreement'];
        $this->assertSame(["2.{$tag}", true, 'نص 2'], [$a['terms']['version'], $a['terms']['frozen'], $a['terms']['termsAr']]);
        $this->assertSame("2.{$tag}", $this->row($r['id'])->terms_snapshot['version']);
        $this->assertSame("1.{$tag}", $this->expectOk($this->getAs('sales', '/api/credit/lookups'))['termsInForce']['version']);
    }

    public function test_payment_methods_are_archived_and_an_archived_one_is_not_a_new_choice(): void
    {
        $tag = self::tag();
        $m = $this->expectOk($this->postAs('admin', '/api/credit/payment-methods', ['code' => "lc_{$tag}", 'nameAr' => 'اعتماد مستندي', 'nameEn' => 'Letter of credit']));
        $this->twoStagePath();
        $r = $this->newRequest(null, ['paymentMethodIds' => [$m['id']]]);
        $this->assertSame([$m['id']], array_column($r['paymentMethods'], 'id'));
        $this->expectOk($this->patchAs('admin', "/api/credit/payment-methods/{$m['id']}", ['active' => false]));
        // kept on the request that had it; refused as a new choice
        $this->expectOk($this->patchAs('sales', "/api/credit/requests/{$r['id']}", ['paymentMethodIds' => [$m['id']], 'city' => 'الدمام']));
        $r2 = $this->newRequest();
        $this->expectRejected($this->patchAs('sales', "/api/credit/requests/{$r2['id']}", ['paymentMethodIds' => [$m['id']]]), 'CREDIT_PAYMENT_METHOD_ARCHIVED', [400]);
        $this->assertSame(1, DB::table('credit_payment_methods')->where('id', $m['id'])->count());
    }

    public function test_policies_are_validated(): void
    {
        $this->expectRejected($this->putAs('admin', '/api/credit/settings/calendar', ['value' => ['mode' => 'working', 'weekdays' => [], 'dayStart' => '08:00', 'dayEnd' => '17:00']]), 'CREDIT_CALENDAR_INVALID', [400]);
        $this->expectRejected($this->putAs('admin', '/api/credit/settings/calendar', ['value' => ['mode' => 'working', 'weekdays' => [0], 'dayStart' => '17:00', 'dayEnd' => '08:00']]), 'CREDIT_CALENDAR_INVALID', [400]);
        $this->expectRejected($this->putAs('admin', '/api/credit/settings/documents', ['value' => ['required' => ['passport']]]), 'CREDIT_SLOT_UNKNOWN', [400]);
        $this->expectRejected($this->putAs('admin', '/api/credit/settings/reminders', ['value' => ['repeatHours' => 0, 'max' => 3]]), 'CREDIT_SETTING_INVALID', [400]);
        $this->expectRejected($this->putAs('finance', '/api/credit/settings/signature', ['value' => ['expiryDays' => 7]]), 'FORBIDDEN', [403]);
        $out = $this->expectOk($this->putAs('admin', '/api/credit/settings/calendar', ['value' => ['mode' => 'working', 'weekdays' => [4, 0, 1, 2, 3, 3], 'dayStart' => '08:30', 'dayEnd' => '16:30', 'holidays' => ['2026-09-23']]]));
        $this->assertSame([0, 1, 2, 3, 4], $out['calendar']['weekdays']);
        $this->assertSame(['2026-09-23'], $out['calendar']['holidays']);
        $out = $this->expectOk($this->putAs('admin', '/api/credit/settings/signature', ['value' => ['expiryDays' => 7]]));
        $this->assertSame(7, $out['signature']['expiryDays']);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'CREDIT.SETTINGS.UPDATE')->where('entity_number', 'signature')->exists());
        DB::table('credit_settings')->whereIn('key', ['calendar', 'signature'])->delete();
    }
}
