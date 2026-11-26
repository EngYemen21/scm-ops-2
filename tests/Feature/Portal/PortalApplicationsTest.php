<?php

namespace Tests\Feature\Portal;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Portal\Models\Application;
use App\Portal\Models\ApplicationDocument;
use App\Portal\Models\PortalEvent;
use Tests\ApiTestCase;

/**
 * Onboarding applications: the review (submit, ask for a correction, approve, refuse, expire, reopen), the customer an
 * approval makes or recognises — never a duplicate —, the papers, the privacy of the owner's identity, and what stays
 * Odoo's on an application that came from Odoo.
 */
class PortalApplicationsTest extends ApiTestCase
{
    use PortalFixtures;

    /** A 15-digit VAT number and a 10-digit registration nobody else carries. */
    private static function numbers(): array
    {
        $n = str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT).str_pad((string) (++self::$portalSeq), 4, '0', STR_PAD_LEFT);

        return ['vatNo' => '3'.$n.'03', 'cr' => '10'.substr($n, 0, 8)];
    }

    private function newApplication(array $more = [], string $as = 'sales'): array
    {
        return $this->expectOk($this->postAs($as, '/api/portal/applications', $more + ['companyNameAr' => 'مؤسسة '.self::tag(), 'mobile' => '0555555555'] + self::numbers()));
    }

    private function act(string $id, string $action, array $body = [], string $as = 'sales')
    {
        return $this->postAs($as, "/api/portal/applications/{$id}/{$action}", $body);
    }

    public function test_from_a_draft_to_a_new_customer_and_never_a_second_one(): void
    {
        $ids = self::numbers();
        $name = 'مطاعم الريف '.self::tag();
        $this->expectRejected($this->postAs('gm', '/api/portal/applications', ['companyNameAr' => $name]), 'FORBIDDEN', [403]);
        $a = $this->expectOk($this->postAs('sales', '/api/portal/applications', [
            'companyNameAr' => $name, 'companyNameEn' => 'Al Reef Restaurants', 'legalEntityType' => 'llc', 'ownerName' => 'مالك المنشأة', 'ownerNationalId' => '10 4567 8912',
            'city' => 'الرياض', 'district' => 'الملقا', 'street' => 'طريق أنس بن مالك', 'buildingNo' => '7421', 'postalCode' => '13521',
            'contacts' => [['role' => 'purchaser', 'name' => 'مسؤول المشتريات', 'mobile' => '0500000001'], ['name' => 'مستلم البضاعة']],
        ]));
        $this->assertStringStartsWith('APL-', $a['number']);
        $this->assertSame(['draft', $name, 'llc', null, 'ar', false], [$a['status'], $a['companyNameAr'], $a['legalEntityType'], $a['sourceSystem'], $a['language'], $a['owned']]);
        $this->assertSame([['role' => 'purchaser', 'name' => 'مسؤول المشتريات', 'mobile' => '0500000001', 'email' => null], ['role' => 'other', 'name' => 'مستلم البضاعة', 'mobile' => null, 'email' => null]], $a['contacts']);
        $this->assertSame(['edit' => true, 'submit' => true, 'requestCorrection' => false, 'approve' => false, 'reject' => false, 'expire' => true, 'reopen' => false, 'note' => true, 'addDocument' => true], $a['can']);

        // a person's identity number: in full for who decides, masked for another reader, and never on the audit trail
        $this->assertSame(['1045678912', false], [$a['ownerNationalId'], $a['ownerNationalIdMasked']]);
        $seen = $this->expectOk($this->getAs('gm', "/api/portal/applications/{$a['id']}"));
        $this->assertSame(['••••••8912', true], [$seen['ownerNationalId'], $seen['ownerNationalIdMasked']]);
        $this->assertSame(0, AuditLog::where('entity_id', $a['id'])->where(fn ($w) => $w->where('new_value', 'like', '%1045678912%')->orWhere('old_value', 'like', '%1045678912%'))->count());
        $this->assertSame(0, PortalEvent::where('entity_id', $a['id'])->get()->filter(fn (PortalEvent $e) => str_contains((string) json_encode($e->data), '1045678912'))->count());

        // not complete: no number to recognise the company by, nobody to call
        $refused = $this->expectRejected($this->act($a['id'], 'submit'), 'PORTAL_APPLICATION_INCOMPLETE', [422]);
        $this->assertEqualsCanonicalizing(['cr', 'mobile'], array_column($refused['details'], 'path'));
        $this->expectRejected($this->patchAs('sales', "/api/portal/applications/{$a['id']}", ['vatNo' => '12345']), 'PORTAL_APPLICATION_NUMBER', [400]);
        $a = $this->expectOk($this->patchAs('sales', "/api/portal/applications/{$a['id']}", ['vatNo' => substr($ids['vatNo'], 0, 5).' '.substr($ids['vatNo'], 5), 'cr' => $ids['cr'], 'mobile' => '0551112223', 'termsAccepted' => true]));
        $this->assertSame([$ids['vatNo'], $ids['cr'], true], [$a['vatNo'], $a['cr'], $a['termsAccepted']], 'numbers are kept as digits');
        $this->assertNotNull($a['termsAcceptedAt']);
        $this->assertSame(['companyNameAr' => true, 'cr' => true, 'mobile' => true, 'doc.cr' => false, 'doc.vat' => false, 'doc.national_address' => false, 'termsAccepted' => true],
            array_column($a['checks'], 'ok', 'key'));

        // review: a correction is asked for and answered
        $this->expectRejected($this->act($a['id'], 'approve', ['confirmNew' => true]), 'PORTAL_APPLICATION_TRANSITION', [422]);
        $a = $this->expectOk($this->act($a['id'], 'submit'));
        $this->assertSame(['submitted', true, true, true, false], [$a['status'], $a['can']['approve'], $a['can']['requestCorrection'], $a['can']['edit'], $a['can']['submit']]);
        $this->assertNotNull($a['submittedAt']);
        $this->expectRejected($this->act($a['id'], 'request-correction'), 'INVALID_INPUT', [400]);
        $a = $this->expectOk($this->act($a['id'], 'request-correction', ['note' => 'السجل التجاري المرفق منتهٍ']));
        $this->assertSame(['correction', 'السجل التجاري المرفق منتهٍ'], [$a['status'], $a['correctionNote']]);
        $a = $this->expectOk($this->act($a['id'], 'submit'));
        $this->assertSame(['resubmitted', 'correction', 'submitted'], array_slice(array_column($a['events'], 'kind'), 0, 3));

        // nobody in OPS carries its numbers or its name: a person must say it is new
        $this->assertSame(['choice', null, []], [$a['match']['needs'], $a['match']['customer'], $a['match']['candidates']]);
        $this->assertSame([$name, 'Al Reef Restaurants', 'الملقا', $ids['vatNo']], [$a['match']['suggested']['nameAr'], $a['match']['suggested']['nameEn'], $a['match']['suggested']['zone'], $a['match']['suggested']['vatNo']]);
        $customers = Customer::count();
        $this->expectRejected($this->act($a['id'], 'approve'), 'PORTAL_CUSTOMER_CONFIRM', [422]);
        // a new customer is the customers' module's: it needs its permission as well
        $this->expectRejected($this->act($a['id'], 'approve', ['confirmNew' => true], $this->portalOnlyUser()), 'FORBIDDEN', [403]);
        $this->assertSame([$customers, 'submitted'], [Customer::count(), Application::find($a['id'])->status]);
        $this->assertTrue(AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->where('username', 'portalonly')->where('new_value', 'denied: customer.manage')->exists());
        // credit terms need a limit: the customers' own rule holds here too, and nothing is half-done
        $this->expectRejected($this->act($a['id'], 'approve', ['confirmNew' => true, 'terms' => 'آجل 30 يومًا']), 'CUSTOMER_CREDIT_LIMIT', [422]);
        $this->assertSame([$customers, 'submitted'], [Customer::count(), Application::find($a['id'])->status]);

        $done = $this->expectOk($this->act($a['id'], 'approve', ['confirmNew' => true, 'zone' => 'شمال الرياض', 'note' => 'الأوراق مكتملة']));
        $this->assertSame(['approved', 'new', 'new'], [$done['status'], $done['customerBy'], $done['approval']['by']]);
        $this->assertSame($customers + 1, Customer::count());
        $c = Customer::findOrFail($done['customer']['id']);
        $this->assertSame([$name, 'Al Reef Restaurants', 'الرياض', 'شمال الرياض', $ids['vatNo'], $ids['cr'], true], [$c->name_ar, $c->name_en, $c->city, $c->zone, $c->vat_no, $c->cr, $c->active]);
        $this->assertStringStartsWith('CUS-', $c->code);
        $this->assertSame([$c->code, true], [$done['customer']['code'], $done['customer']['linked']]);
        $this->assertSame(['approved', $c->code], [$done['events'][0]['kind'], $done['events'][0]['data']['customerCode']]);
        $this->assertSame(['edit' => false, 'submit' => false, 'requestCorrection' => false, 'approve' => false, 'reject' => false, 'expire' => false, 'reopen' => false, 'note' => true, 'addDocument' => true], $done['can']);
        $this->assertNull($done['match']);
        $this->assertTrue(AuditLog::where('action', 'PORTAL.APPLICATION.APPROVE')->where('entity_id', $a['id'])->where('username', 'sales')->exists());
        $this->assertTrue(AuditLog::where('action', 'CUSTOMER.CREATE')->where('entity_id', $c->id)->exists(), 'the customer was made by the customers\' own service');
        // approved is final
        foreach (['approve', 'submit', 'expire', 'reopen'] as $move) {
            $this->expectRejected($this->act($a['id'], $move, ['confirmNew' => true]), 'PORTAL_APPLICATION_TRANSITION', [422]);
        }
        $this->expectRejected($this->act($a['id'], 'reject', ['reason' => 'تراجع']), 'PORTAL_APPLICATION_TRANSITION', [422]);
        $this->expectRejected($this->patchAs('sales', "/api/portal/applications/{$a['id']}", ['city' => 'جدة']), 'PORTAL_APPLICATION_CLOSED', [422]);

        // the same company applies again: its number names the customer — it is linked, never made twice
        $again = $this->newApplication(['companyNameAr' => $name.' (فرع جديد)', 'submit' => true] + $ids);
        $this->assertSame(['submitted', 'nothing', 'vat', $c->code], [$again['status'], $again['match']['needs'], $again['match']['by'], $again['match']['customer']['code']]);
        $linked = $this->expectOk($this->act($again['id'], 'approve', ['confirmNew' => true], $this->portalOnlyUser()));
        $this->assertSame(['approved', 'vat', $c->id], [$linked['status'], $linked['customerBy'], $linked['customer']['id']]);
        $this->assertSame($customers + 1, Customer::count(), 'no second customer for the same company');
    }

    public function test_identity_names_the_customer_and_a_person_decides_the_rest(): void
    {
        $tag = self::tag();
        $one = self::numbers();
        $two = self::numbers();
        $byVat = $this->makeCustomer(['vat_no' => $one['vatNo'], 'name_ar' => "شركة الضريبة {$tag}"]);
        $byCr = $this->makeCustomer(['cr' => $two['cr'], 'name_ar' => "شركة السجل {$tag}"]);
        $namesake = $this->makeCustomer(['name_ar' => "مخبز السنابل {$tag}", 'contact' => 'المدير · 0551234999']);

        // the registration number alone names the customer
        $a = $this->newApplication(['cr' => $two['cr'], 'vatNo' => null, 'submit' => true]);
        $this->assertSame(['cr', $byCr->code, 'nothing'], [$a['match']['by'], $a['match']['customer']['code'], $a['match']['needs']]);
        // … so another customer cannot be picked for it
        $mismatch = $this->expectRejected($this->act($a['id'], 'approve', ['customerId' => $byVat->code]), 'PORTAL_CUSTOMER_MISMATCH', [422]);
        $this->assertSame($byCr->code, $mismatch['details']['customer']['code']);
        $this->assertSame('cr', $this->expectOk($this->act($a['id'], 'approve'))['customerBy'], 'identity needs no word from the reviewer');

        // the two numbers name two different customers: nothing is assumed
        $b = $this->newApplication(['vatNo' => $one['vatNo'], 'cr' => $two['cr'], 'submit' => true]);
        $this->assertSame(['conflict', null], [$b['match']['needs'], $b['match']['customer']]);
        $this->assertEqualsCanonicalizing([$byVat->code, $byCr->code], array_column($b['match']['conflict'], 'code'));
        $conflict = $this->expectRejected($this->act($b['id'], 'approve', ['confirmNew' => true]), 'PORTAL_IDENTITY_CONFLICT', [422]);
        $this->assertCount(2, $conflict['details']['candidates']);
        $chosen = $this->expectOk($this->act($b['id'], 'approve', ['customerId' => $byVat->id]));
        $this->assertSame(['person', $byVat->id], [$chosen['customerBy'], Application::find($b['id'])->customer_id]);

        // no number names anybody, but the name and the phone are known: shown as candidates, never linked by themselves
        $c = $this->newApplication(['companyNameAr' => "مخبز السنابل {$tag}", 'mobile' => '+966 55 123 4999', 'submit' => true]);
        $this->assertSame(['choice', null], [$c['match']['needs'], $c['match']['customer']]);
        $this->assertSame([[$namesake->code, ['sameName', 'samePhone']]], array_map(fn (array $x) => [$x['code'], $x['why']], $c['match']['candidates']));
        $stillOpen = $this->expectRejected($this->act($c['id'], 'approve'), 'PORTAL_CUSTOMER_CONFIRM', [422]);
        $this->assertSame([$namesake->code], array_column($stillOpen['details']['candidates'], 'code'));
        // a stopped customer is not given a new application
        $namesake->update(['active' => false]);
        $this->expectRejected($this->act($c['id'], 'approve', ['customerId' => $namesake->code]), 'CUSTOMER_INACTIVE', [422]);
        $namesake->update(['active' => true]);
        $this->expectRejected($this->act($c['id'], 'approve', ['customerId' => 'CUS-NOBODY']), 'CUSTOMER_NOT_FOUND', [400]);
        $person = $this->expectOk($this->act($c['id'], 'approve', ['customerId' => $namesake->code]));
        $this->assertSame(['person', $namesake->id, 'person'], [$person['customerBy'], $person['customer']['id'], $person['events'][0]['data']['by']]);
    }

    public function test_refusing_expiring_and_reopening(): void
    {
        $a = $this->newApplication();
        // a draft is not decided
        $this->expectRejected($this->act($a['id'], 'reject', ['reason' => 'لا نخدم هذه المنطقة']), 'PORTAL_APPLICATION_TRANSITION', [422]);
        $this->expectRejected($this->act($a['id'], 'request-correction', ['note' => 'أكمل البيانات']), 'PORTAL_APPLICATION_TRANSITION', [422]);
        $this->expectRejected($this->act($a['id'], 'reopen'), 'PORTAL_APPLICATION_TRANSITION', [422]);

        $this->expectOk($this->act($a['id'], 'submit'));
        $this->expectRejected($this->act($a['id'], 'expire'), 'PORTAL_APPLICATION_TRANSITION', [422]);
        $this->expectRejected($this->act($a['id'], 'reject'), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->act($a['id'], 'reject', ['reason' => 'لا نخدم هذه المنطقة'], 'gm'), 'FORBIDDEN', [403]);
        $r = $this->expectOk($this->act($a['id'], 'reject', ['reason' => 'لا نخدم هذه المنطقة']));
        $this->assertSame(['rejected', 'لا نخدم هذه المنطقة', 'sales', true, false], [$r['status'], $r['rejectionReason'], $r['decidedBy'], $r['can']['reopen'], $r['can']['edit']]);
        $this->expectRejected($this->act($a['id'], 'approve', ['confirmNew' => true]), 'PORTAL_APPLICATION_TRANSITION', [422]);

        // another go: what was decided stays on the timeline
        $o = $this->expectOk($this->act($a['id'], 'reopen', ['note' => 'فُتح فرع توزيع جديد']));
        $this->assertSame(['draft', null, null], [$o['status'], $o['rejectionReason'], $o['decidedAt']]);
        $this->assertSame(['reopened', 'rejected', 'submitted', 'created'], array_column($o['events'], 'kind'));
        $this->assertSame(['rejected', 'لا نخدم هذه المنطقة'], [$o['events'][0]['data']['from'], $o['events'][1]['note']]);

        $x = $this->expectOk($this->act($a['id'], 'expire', ['reason' => 'لم يُكمل العميل طلبه']));
        $this->assertSame('expired', $x['status']);
        $this->assertNotNull($x['expiredAt']);
        $this->assertSame('draft', $this->expectOk($this->act($a['id'], 'reopen'))['status']);

        // a note is always welcome
        $n = $this->expectOk($this->act($a['id'], 'notes', ['text' => 'اتصل العميل وسيرسل الأوراق غدًا']));
        $this->assertSame(['note', 'اتصل العميل وسيرسل الأوراق غدًا', 'sales'], [$n['events'][0]['kind'], $n['events'][0]['note'], $n['events'][0]['username']]);
        $this->assertSame(5, AuditLog::where('entity_type', 'PortalApplication')->where('entity_id', $a['id'])->where('action', 'STATUS')->count(), 'every move is on the audit trail');
    }

    public function test_papers_are_kept_typed_by_their_bytes_and_identity_papers_are_private(): void
    {
        $a = $this->newApplication();
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< >>\n%%EOF";
        $up = fn (array $body, string $as = 'sales') => $this->postAs($as, "/api/portal/applications/{$a['id']}/documents", $body);

        $this->expectRejected($up(['kind' => 'cr', 'fileName' => 'cr.pdf', 'base64' => base64_encode($pdf)], 'gm'), 'FORBIDDEN', [403]);
        $withCr = $this->expectOk($up(['kind' => 'cr', 'fileName' => '../السجل التجاري 2026.pdf', 'mime' => 'text/html', 'base64' => base64_encode($pdf)]));
        $this->assertSame(1, $withCr['documentsCount']);
        $doc = $withCr['documents'][0];
        $this->assertSame(['cr', 'application/pdf', strlen($pdf), 'ops', true, null], [$doc['kind'], $doc['mime'], $doc['size'], $doc['origin'], $doc['available'], $doc['unavailable']], 'the type is read from the bytes, not from what the client says');
        $this->assertStringNotContainsString('/', $doc['fileName']);
        $this->assertArrayNotHasKey('storageKey', $doc);
        $withId = $this->expectOk($up(['kind' => 'owner_id', 'fileName' => 'هوية المالك.png', 'base64' => self::proofImage()]));
        $this->assertSame([2, 'image/png'], [$withId['documentsCount'], $withId['documents'][0]['mime']]);
        $this->assertTrue(array_column($withId['checks'], 'ok', 'key')['doc.cr']);
        // what is not a PDF or an image is not kept; nor an unknown kind of paper
        $this->expectRejected($up(['kind' => 'vat', 'fileName' => 'page.html', 'base64' => base64_encode('<html><script>alert(1)</script></html>')]), 'ATTACHMENT_INVALID', [400]);
        $this->expectRejected($up(['kind' => 'vat', 'fileName' => 'empty.pdf', 'base64' => '!!!']), 'ATTACHMENT_EMPTY', [400]);
        $this->expectRejected($up(['kind' => 'selfie', 'fileName' => 'x.pdf', 'base64' => base64_encode($pdf)]), 'INVALID_INPUT', [400]);
        $this->assertSame(2, ApplicationDocument::where('application_id', $a['id'])->count());

        // reading: the company's papers by every reader of the page, an identity paper by who decides
        $crId = $doc['id'];
        $idId = $withId['documents'][0]['id'];
        $file = $this->get("/api/portal/applications/{$a['id']}/documents/{$crId}", ['Authorization' => 'Bearer '.$this->token('gm')]);
        $file->assertOk();
        $this->assertSame([$pdf, 'application/pdf', 'nosniff'], [$file->getContent(), $file->headers->get('Content-Type'), $file->headers->get('X-Content-Type-Options')]);
        $this->assertStringContainsString('no-store', (string) $file->headers->get('Cache-Control'));
        $this->expectRejected($this->getAs('gm', "/api/portal/applications/{$a['id']}/documents/{$idId}"), 'PORTAL_DOCUMENT_PRIVATE', [403]);
        $seen = $this->expectOk($this->getAs('gm', "/api/portal/applications/{$a['id']}"));
        $this->assertSame([false, 'private'], [$seen['documents'][0]['available'], $seen['documents'][0]['unavailable']]);
        $this->get("/api/portal/applications/{$a['id']}/documents/{$idId}", ['Authorization' => 'Bearer '.$this->token('sales')])->assertOk();
        $this->expectRejected($this->getAs('wm', "/api/portal/applications/{$a['id']}/documents/{$crId}"), 'FORBIDDEN', [403]);
        // a paper of another application is not found through this one
        $other = $this->newApplication();
        $this->expectRejected($this->getAs('sales', "/api/portal/applications/{$other['id']}/documents/{$crId}"), 'PORTAL_DOCUMENT_NOT_FOUND', [404]);

        // taken off: it stays, marked, with who and why
        $off = $this->expectOk($this->postAs('sales', "/api/portal/applications/{$a['id']}/documents/{$crId}/remove", ['reason' => 'نسخة منتهية']));
        $this->assertSame(1, $off['documentsCount']);
        $removed = collect($off['documents'])->firstWhere('id', $crId);
        $this->assertSame([false, 'removed', 'sales', 'نسخة منتهية'], [$removed['available'], $removed['unavailable'], $removed['removedBy'], $removed['removeReason']]);
        $this->assertSame(2, ApplicationDocument::where('application_id', $a['id'])->count(), 'nothing is deleted');
        $this->expectRejected($this->postAs('sales', "/api/portal/applications/{$a['id']}/documents/{$crId}/remove"), 'PORTAL_DOCUMENT_REMOVED', [422]);
        $this->expectRejected($this->getAs('gm', "/api/portal/applications/{$a['id']}/documents/{$crId}"), 'PORTAL_DOCUMENT_REMOVED', [404]);
        $this->assertFalse(array_column($off['checks'], 'ok', 'key')['doc.cr']);
        $this->assertSame(['document_removed', 'document_added', 'document_added', 'created'], array_column($off['events'], 'kind'));

        // a paper Odoo holds whose content is not in the OPS archive says so
        $fromOdoo = ApplicationDocument::create(['application_id' => $a['id'], 'kind' => 'vat', 'file_name' => 'vat.pdf', 'status' => 'odoo', 'source_system' => 'odoo', 'external_ref' => (string) random_int(900000, 999999)]);
        $listed = collect($this->expectOk($this->getAs('sales', "/api/portal/applications/{$a['id']}"))['documents'])->firstWhere('id', $fromOdoo->id);
        $this->assertSame(['odoo', false, 'not_archived'], [$listed['origin'], $listed['available'], $listed['unavailable']]);
        $this->expectRejected($this->getAs('sales', "/api/portal/applications/{$a['id']}/documents/{$fromOdoo->id}"), 'ODOO_FILE_NOT_ARCHIVED', [404]);
    }

    public function test_an_application_of_odoo_is_odoos_until_the_section_is_handed_over(): void
    {
        $ids = self::numbers();
        $a = Application::create(['number' => 'ONB/2026/'.random_int(10000, 99999), 'status' => 'submitted', 'source_state' => 'pending_review', 'company_name_ar' => 'منشأة من أودو '.self::tag(), 'mobile' => '0500000009',
            'vat_no' => $ids['vatNo'], 'cr' => $ids['cr'], 'source_system' => 'odoo', 'external_ref' => (string) random_int(100000, 999999)]);

        $this->followOdoo(true);
        $shown = $this->expectOk($this->getAs('sales', "/api/portal/applications/{$a->id}"));
        $this->assertSame([true, 'pending_review'], [$shown['owned'], $shown['sourceState']]);
        $this->assertSame(['note' => true, 'addDocument' => true], array_filter($shown['can']), 'only what OPS adds is offered');
        foreach ([['approve', ['confirmNew' => true]], ['reject', ['reason' => 'سبب ما']], ['request-correction', ['note' => 'صحّح العنوان']]] as [$move, $body]) {
            $this->expectRejected($this->act($a->id, $move, $body), 'PORTAL_OWNED_BY_SOURCE', [422]);
        }
        $this->expectRejected($this->patchAs('sales', "/api/portal/applications/{$a->id}", ['city' => 'جدة']), 'PORTAL_OWNED_BY_SOURCE', [422]);
        $this->assertSame('submitted', $a->fresh()->status);
        // notes and papers are OPS's own
        $this->expectOk($this->act($a->id, 'notes', ['text' => 'راجعنا الأوراق مع العميل']));
        $this->expectOk($this->postAs('sales', "/api/portal/applications/{$a->id}/documents", ['kind' => 'cr', 'fileName' => 'cr.png', 'base64' => self::proofImage()]));

        $this->followOdoo(false);
        $done = $this->expectOk($this->act($a->id, 'approve', ['confirmNew' => true]));
        $this->assertSame(['approved', 'new', false], [$done['status'], $done['customerBy'], $done['owned']]);
    }

    public function test_the_list_searches_filters_sorts_and_pages(): void
    {
        $tag = self::tag();
        $draft = $this->newApplication(['companyNameAr' => "ألف {$tag}", 'city' => 'جدة']);
        $waiting = $this->newApplication(['companyNameAr' => "باء {$tag}", 'submit' => true]);
        $refused = $this->newApplication(['companyNameAr' => "جيم {$tag}", 'submit' => true]);
        $this->expectOk($this->act($refused['id'], 'reject', ['reason' => 'خارج نطاق التوزيع']));
        $fromOdoo = Application::create(['number' => 'ONB/2026/'.random_int(10000, 99999), 'status' => 'expired', 'source_state' => 'expired', 'company_name_ar' => "دال {$tag}", 'source_system' => 'odoo',
            'external_ref' => (string) random_int(100000, 999999)]);
        $list = fn (string $query) => $this->expectOk($this->getAs('gm', '/api/portal/applications?q='.urlencode($tag).'&'.$query));

        $this->assertSame(4, $list('')['total']);
        $this->assertSame([$draft['id'], $waiting['id'], $refused['id'], $fromOdoo->id], array_column($list('sort=companyNameAr&order=asc')['items'], 'id'));
        $this->assertSame([$waiting['id']], array_column($list('status=submitted')['items'], 'id'));
        $this->assertEqualsCanonicalizing([$draft['id'], $waiting['id']], array_column($list('status=open')['items'], 'id'));
        $this->assertSame([$waiting['id']], array_column($list('status=review')['items'], 'id'));
        $this->assertEqualsCanonicalizing([$refused['id'], $fromOdoo->id], array_column($list('status=rejected,expired')['items'], 'id'));
        $this->assertSame(0, $list('status=nonsense')['total']);
        $this->assertSame([$fromOdoo->id], array_column($list('origin=odoo')['items'], 'id'));
        $this->assertSame(3, $list('origin=ops')['total']);
        // never submitted comes last when sorted by the day it was submitted
        $bySubmitted = array_column($list('sort=submittedAt&order=asc')['items'], 'id');
        $this->assertSame([$waiting['id'], $refused['id']], array_slice($bySubmitted, 0, 2));
        $page = $list('sort=companyNameAr&order=asc&pageSize=3&page=2');
        $this->assertSame([[$fromOdoo->id], 4, 2], [array_column($page['items'], 'id'), $page['total'], $page['pages']]);
        // by number, by a registration number, by the id Odoo knows it by
        $this->assertSame([$draft['id']], array_column($this->expectOk($this->getAs('gm', '/api/portal/applications?q='.$draft['number']))['items'], 'id'));
        $this->assertSame([$waiting['id']], array_column($this->expectOk($this->getAs('gm', '/api/portal/applications?q='.$waiting['vatNo']))['items'], 'id'));
        $this->assertContains($fromOdoo->id, array_column($this->expectOk($this->getAs('gm', '/api/portal/applications?q='.$fromOdoo->external_ref))['items'], 'id'));
        $this->assertSame($draft['id'], $this->expectOk($this->getAs('gm', "/api/portal/applications/{$draft['number']}"))['id'], 'an application OPS numbered answers to its number');
        $today = now()->toDateString();
        $this->assertSame(4, $list("from={$today}&to={$today}")['total']);
        $this->assertSame(0, $list('to='.now()->subDay()->toDateString())['total']);
        $this->expectRejected($this->getAs('gm', '/api/portal/applications?from=yesterday'), 'INVALID_INPUT', [400]);
        // the list never shows a person's identity number
        $this->assertArrayNotHasKey('ownerNationalId', $list('')['items'][0]);
    }
}
