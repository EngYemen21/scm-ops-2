<?php

namespace Tests\Feature\Hr;

use App\Hr\Models\Document;
use App\Hr\Models\HrAlert;
use App\Hr\Models\HrFile;
use App\Hr\Services\AlertsService;
use App\Models\AuditLog;
use App\Models\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/**
 * Employee documents, the contracts and what expires: who sees which paper, where its scan is kept and who may open
 * it, and the alerts 60 / 30 / 7 days before and after the date — raised once per stage, resolved by a renewal.
 */
class HrDocumentsTest extends ApiTestCase
{
    use HrFixtures;

    private string $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sealNetwork();
        $this->followOdoo(false);
        $this->clerk = $this->userWith('hrclerk', ['page.hr', 'hr.manage']); // keeps the register, may not read the personal file
    }

    private function day(int $offset): string
    {
        return now()->addDays($offset)->toDateString();
    }

    public function test_a_document_is_registered_with_its_scan_and_private_papers_need_hr_private(): void
    {
        $e = $this->makeEmployee();
        // the clerk registers a driving licence with its scan
        $licence = $this->expectOk($this->postAs($this->clerk, '/api/hr/documents', ['employee' => $e['code'], 'kind' => 'driving_license', 'number' => 'DL-TEST-1', 'issueDate' => $this->day(-300),
            'expiryDate' => $this->day(400), 'file' => self::pdfFile('رخصة.pdf')]));
        $this->assertSame(['driving_license', false, 'active', 'valid', 400], [$licence['kind'], $licence['private'], $licence['status'], $licence['stage'], $licence['days']]);
        $this->assertCount(1, $licence['files']);
        $this->assertSame('application/pdf', $licence['files'][0]['mime']);
        $this->assertSame('رخصة.pdf', $licence['files'][0]['name']);

        // identity papers are ALWAYS part of the personal file, and so is any paper marked private
        $this->expectRejected($this->postAs($this->clerk, '/api/hr/documents', ['employee' => $e['id'], 'kind' => 'passport', 'expiryDate' => $this->day(500)]), 'HR_PRIVATE', [403]);
        $this->expectRejected($this->postAs($this->clerk, '/api/hr/documents', ['employee' => $e['id'], 'kind' => 'training', 'private' => true]), 'HR_PRIVATE', [403]);
        $this->expectRejected($this->patchAs($this->clerk, "/api/hr/documents/{$licence['id']}", ['private' => true]), 'HR_PRIVATE', [403]);
        $this->assertSame(1, Document::where('employee_id', $e['id'])->count());
        $passport = $this->expectOk($this->postAs('admin', '/api/hr/documents', ['employee' => $e['id'], 'kind' => 'passport', 'number' => 'P-TEST-77', 'expiryDate' => $this->day(500), 'private' => false, 'file' => self::pngFile('scan.png')]));
        $this->assertTrue($passport['private'], 'a passport cannot be made public');

        // the clerk's register does not contain the passport; to the clerk it does not exist
        $this->assertSame([$licence['id']], array_column($this->expectOk($this->getAs($this->clerk, "/api/hr/documents?employee={$e['id']}"))['items'], 'id'));
        $this->expectRejected($this->getAs($this->clerk, "/api/hr/documents/{$passport['id']}"), 'DOCUMENT_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs($this->clerk, "/api/hr/documents/{$passport['id']}/void", ['reason' => 'محاولة بلا صلاحية']), 'DOCUMENT_NOT_FOUND', [404]);
        $this->assertEqualsCanonicalizing([$licence['id'], $passport['id']], array_column($this->expectOk($this->getAs('admin', "/api/hr/documents?employee={$e['id']}"))['items'], 'id'));
        $card = $this->expectOk($this->getAs($this->clerk, "/api/hr/employees/{$e['id']}"));
        $this->assertSame([$licence['id']], array_column($card['documents'], 'id'));
        $this->assertStringNotContainsString('P-TEST-77', json_encode($card));
        // the general manager opens the directory, not the register
        $this->expectRejected($this->getAs('gm', '/api/hr/documents'), 'FORBIDDEN', [403]);
        $this->assertArrayNotHasKey('documents', $this->expectOk($this->getAs('gm', "/api/hr/employees/{$e['id']}")));

        // a scan is opened by who may read its document — and by nobody through the general attachments door
        $scan = $this->expectOk($this->getAs($this->clerk, $licence['files'][0]['url'].'?as=data'));
        $this->assertStringStartsWith('data:application/pdf;base64,JVBERi0', $scan['dataUrl']);
        $raw = $this->getAs($this->clerk, str_replace('/api', '/api', $licence['files'][0]['url']));
        $raw->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $raw->getContent());
        $this->expectOk($this->getAs('admin', $passport['files'][0]['url'].'?as=data'));
        foreach ([$this->clerk, 'gm', 'sales'] as $user) {
            $this->expectRejected($this->getAs($user, $passport['files'][0]['url']), null, [403]);
        }
        $this->expectRejected($this->getAs('sales', $licence['files'][0]['url']), 'HR_FORBIDDEN', [403]);
        $key = HrFile::find($licence['files'][0]['id'])->storage_key;
        $this->assertStringStartsWith('hr/document/', $key);
        $this->expectRejected($this->getAs('admin', '/api/integrations/files/'.$key), 'FILE_NOT_FOUND', [404]);
        $this->assertSame(0, DB::table('attachments')->where('storage_key', $key)->count());

        // only a PDF or a picture is kept, told from the bytes
        $this->expectRejected($this->postAs($this->clerk, "/api/hr/documents/{$licence['id']}/files", ['fileName' => 'page.pdf', 'mime' => 'application/pdf', 'base64' => base64_encode('<html><script>1</script></html>')]), 'HR_FILE_TYPE', [400]);
        $this->expectRejected($this->postAs($this->clerk, "/api/hr/documents/{$licence['id']}/files", ['fileName' => 'x.pdf', 'base64' => '!!!!not-base64!!!!']), 'HR_FILE_EMPTY', [400]);
        $two = $this->expectOk($this->postAs($this->clerk, "/api/hr/documents/{$licence['id']}/files", self::pngFile('back.png')));
        $this->assertCount(2, $two['files']);
        $one = $this->expectOk($this->deleteAs($this->clerk, "/api/hr/documents/{$licence['id']}/files/{$two['files'][1]['id']}"));
        $this->assertCount(1, $one['files']);
        $this->expectRejected($this->getAs($this->clerk, $two['files'][1]['url']), 'HR_FILE_NOT_FOUND', [404]);

        // dates must make sense
        $this->expectRejected($this->postAs($this->clerk, '/api/hr/documents', ['employee' => $e['id'], 'kind' => 'training', 'issueDate' => $this->day(-5), 'expiryDate' => $this->day(-10)]), 'DOCUMENT_DATES', [400]);
        $this->expectRejected($this->postAs($this->clerk, '/api/hr/documents', ['employee' => $e['id'], 'kind' => 'no_such_kind']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs($this->clerk, '/api/hr/documents', ['employee' => 'EMP-0000-00000', 'kind' => 'training']), 'EMPLOYEE_NOT_FOUND', [404]);
        // the trail records the act, not the number on the paper
        $this->assertStringNotContainsString('DL-TEST-1', (string) AuditLog::where('action', 'HR.DOCUMENT.CREATE')->where('entity_id', $licence['id'])->value('new_value'));
    }

    public function test_alerts_follow_the_expiry_date_through_the_stages_and_a_renewal_resolves_them(): void
    {
        $e = $this->makeEmployee(['nameAr' => 'صاحب الوثائق '.self::uid()]);
        $doc = fn (string $kind, int $days, array $more = []) => $this->expectOk($this->postAs('admin', '/api/hr/documents', $more + ['employee' => $e['id'], 'kind' => $kind, 'expiryDate' => $this->day($days)]));
        $far = $doc('training', 120);
        $d60 = $doc('driving_license', 45);
        $d30 = $doc('health_card', 20);
        $d7 = $doc('professional_license', 5);
        $late = $doc('other', -3, ['title' => 'تصريح دخول']);
        $this->assertSame(['valid', 'd60', 'd30', 'd7', 'expired'], [$far['stage'], $d60['stage'], $d30['stage'], $d7['stage'], $late['stage']]);

        // an alert exists the moment the document is registered — one per paper, none for the one that is far
        $list = $this->expectOk($this->getAs($this->clerk, "/api/hr/alerts?employee={$e['id']}"));
        $this->assertSame(4, $list['total']);
        $this->assertSame(['expired', 'd7', 'd30', 'd60'], array_column($list['items'], 'stage'), 'the most urgent first');
        $this->assertSame([-3, 5, 20, 45], array_column($list['items'], 'days'));
        $this->assertStringContainsString('منتهية منذ 3', $list['items'][0]['textAr']);
        $this->assertStringContainsString('expires in 5 day', $list['items'][1]['textEn']);
        $this->assertSame($e['code'], $list['items'][0]['employee']['code']);
        $this->assertSame(1, $this->expectOk($this->getAs($this->clerk, "/api/hr/alerts?employee={$e['id']}&stage=d7"))['total']);
        $this->assertSame(2, $this->expectOk($this->getAs($this->clerk, "/api/hr/alerts?employee={$e['id']}&stage=d7,expired"))['total']);

        // the holders of hr.manage were told once per paper — and are not told again while the stage stays
        $told = fn () => Notification::where('entity_type', 'Employee')->where('entity_id', $e['id'])->where('role_key', 'super')->count();
        $this->assertSame(4, $told());
        $this->assertSame(4, Notification::where('entity_type', 'Employee')->where('entity_id', $e['id'])->where('role_key', 't-hrclerk')->count());
        $again = $this->expectOk($this->postAs($this->clerk, '/api/hr/alerts/refresh'));
        $this->assertSame([0, 0, 0], [$again['created'], $again['resolved'], $again['notified']]);
        $this->assertSame(4, $told());

        // three weeks later the licence is 24 days away: its alert moves to the 30-day stage and people are told again — once
        $clock = Carbon::getTestNow(); // the suite's anchored clock: put back below (travelBack() would jump to the machine's real date)
        $this->travel(21)->days();
        $stats = app(AlertsService::class)->refresh();
        $this->assertSame('d30', HrAlert::where('ref_id', $d60['id'])->where('status', 'open')->value('stage'));
        $this->assertSame('expired', HrAlert::where('ref_id', $d30['id'])->where('status', 'open')->value('stage'), 'the 20-day paper is now past its date');
        $this->assertSame('expired', HrAlert::where('ref_id', $d7['id'])->where('status', 'open')->value('stage'));
        $this->assertSame(3, $stats['notified'], 'three papers reached a new stage; the one already expired did not');
        $this->assertSame(0, app(AlertsService::class)->refresh()['notified']);
        $this->assertSame(7, $told());
        Carbon::setTestNow($clock);
        app(AlertsService::class)->refresh(); // back to today: the stages are today's again, and nobody is told about a stage they already know
        $this->assertSame(7, $told());

        // the expired permit is renewed: the old paper is kept as replaced, its alert is resolved, the new one is far away
        $this->expectRejected($this->postAs($this->clerk, "/api/hr/documents/{$late['id']}/renew", ['expiryDate' => $this->day(-10)]), 'DOCUMENT_EXPIRY', [400]);
        $renewed = $this->expectOk($this->postAs($this->clerk, "/api/hr/documents/{$late['id']}/renew", ['expiryDate' => $this->day(365), 'issueDate' => $this->day(0), 'file' => self::pdfFile()]));
        $this->assertSame(['active', 'valid', 'تصريح دخول'], [$renewed['status'], $renewed['stage'], $renewed['title']]);
        $old = $this->expectOk($this->getAs($this->clerk, "/api/hr/documents/{$late['id']}"));
        $this->assertSame(['replaced', $renewed['id']], [$old['status'], $old['replacedById']]);
        $this->assertSame('resolved', HrAlert::where('ref_id', $late['id'])->value('status'));
        $this->expectRejected($this->patchAs($this->clerk, "/api/hr/documents/{$late['id']}", ['notes' => 'x']), 'DOCUMENT_STATE', [422]);
        $this->assertSame(3, $this->expectOk($this->getAs($this->clerk, "/api/hr/alerts?employee={$e['id']}"))['total']);

        // moving a date resolves or re-stages at once; voiding a paper resolves its alert; nothing is deleted
        $this->expectOk($this->patchAs($this->clerk, "/api/hr/documents/{$d7['id']}", ['expiryDate' => $this->day(300)]));
        $this->assertSame('resolved', HrAlert::where('ref_id', $d7['id'])->value('status'));
        $this->expectRejected($this->postAs($this->clerk, "/api/hr/documents/{$d30['id']}/void", ['reason' => 'x']), 'INVALID_INPUT', [400]);
        $void = $this->expectOk($this->postAs($this->clerk, "/api/hr/documents/{$d30['id']}/void", ['reason' => 'أُدخلت بالخطأ لموظف آخر']));
        $this->assertSame(['void', 'أُدخلت بالخطأ لموظف آخر'], [$void['status'], $void['voidReason']]);
        $this->expectRejected($this->postAs($this->clerk, "/api/hr/documents/{$d30['id']}/void", ['reason' => 'مرة ثانية للتأكد']), 'DOCUMENT_STATE', [422]);
        $this->assertSame(1, $this->expectOk($this->getAs($this->clerk, "/api/hr/alerts?employee={$e['id']}"))['total']);
        $this->assertSame(6, Document::where('employee_id', $e['id'])->count(), 'every paper ever registered is still there');

        // the register filters by what is due, by kind and by state
        $register = fn (string $query) => array_column($this->expectOk($this->getAs($this->clerk, "/api/hr/documents?employee={$e['id']}&{$query}"))['items'], 'id');
        $this->assertSame([$d60['id']], $register('due=due'));
        $this->assertSame([$d60['id']], $register('due=d60'));
        $this->assertEqualsCanonicalizing([$far['id'], $d7['id'], $renewed['id']], $register('due=valid'));
        $this->assertSame([$d60['id']], $register('kind=driving_license'));
        $this->assertSame([$d30['id']], $register('status=void'));
        $this->assertCount(6, $register('status=all'));
        $this->assertSame($d60['id'], $register('status=active')[0], 'what expires first comes first');

        // a person who left has nothing to renew
        $this->expectOk($this->patchAs('admin', "/api/hr/employees/{$e['id']}", ['status' => 'left']));
        $this->assertSame(0, HrAlert::where('employee_id', $e['id'])->where('status', 'open')->count());
    }

    public function test_alerts_about_the_personal_file_are_for_hr_private_only(): void
    {
        $e = $this->makeEmployee();
        $this->expectOk($this->putAs('admin', "/api/hr/employees/{$e['id']}/private", ['identityNo' => 'ID-TEST-5', 'identityExpiry' => $this->day(6), 'passportNo' => 'P-TEST-5', 'passportExpiry' => $this->day(50)]));
        $this->expectOk($this->postAs('admin', '/api/hr/documents', ['employee' => $e['id'], 'kind' => 'iqama', 'expiryDate' => $this->day(-1)]));
        $public = $this->expectOk($this->postAs($this->clerk, '/api/hr/documents', ['employee' => $e['id'], 'kind' => 'health_card', 'expiryDate' => $this->day(10)]));

        $all = $this->expectOk($this->getAs('admin', "/api/hr/alerts?employee={$e['id']}"));
        $this->assertSame(['document', 'identity', 'document', 'passport'], array_column($all['items'], 'category'));
        $this->assertSame([true, true, false, true], array_column($all['items'], 'private'));
        $this->assertStringContainsString('الهوية / الإقامة', $all['items'][1]['textAr']);
        // the clerk sees the health certificate and nothing that belongs to the personal file — not even that it exists
        $mine = $this->expectOk($this->getAs($this->clerk, "/api/hr/alerts?employee={$e['id']}"));
        $this->assertSame([$public['id']], array_column($mine['items'], 'refId'));
        $adminCounts = $this->expectOk($this->getAs('admin', '/api/hr/summary'))['due'];
        $clerkCounts = $this->expectOk($this->getAs($this->clerk, '/api/hr/summary'))['due'];
        $this->assertGreaterThanOrEqual($clerkCounts['total'] + 3, $adminCounts['total']);
        $this->assertSame([$public['id']], array_column($this->expectOk($this->getAs($this->clerk, "/api/hr/employees/{$e['id']}"))['alerts'], 'refId'));
        // a private alert is announced to the holders of hr.private, never to a role that only keeps the register
        $this->assertSame(1, Notification::where('entity_id', $e['id'])->where('role_key', 't-hrclerk')->count());
        $this->assertSame(4, Notification::where('entity_id', $e['id'])->where('role_key', 'super')->count());
        // …and nothing about a person's papers is written to the activity feed, which other roles read
        $this->assertSame(0, DB::table('activity_logs')->where('entity_id', $e['id'])->count());
        $this->expectRejected($this->getAs('gm', '/api/hr/alerts'), 'FORBIDDEN', [403]);

        // the identity is renewed in the personal file: its alert is resolved at once
        $this->expectOk($this->putAs('admin', "/api/hr/employees/{$e['id']}/private", ['identityExpiry' => $this->day(700)]));
        $this->assertSame(['document', 'document', 'passport'], array_column($this->expectOk($this->getAs('admin', "/api/hr/alerts?employee={$e['id']}"))['items'], 'category'));
    }

    public function test_contracts_are_peoples_pay_with_one_running_contract_per_employee(): void
    {
        $e = $this->makeEmployee(['jobTitle' => 'مشرف مستودع']);
        foreach (['gm', $this->clerk, 'finance'] as $user) {
            $this->expectRejected($this->getAs($user, '/api/hr/contracts'), 'FORBIDDEN', [403]);
            $this->expectRejected($this->postAs($user, '/api/hr/contracts', ['employee' => $e['id'], 'startDate' => $this->day(-30), 'wage' => 1]), 'FORBIDDEN', [403]);
        }
        $this->expectRejected($this->postAs('admin', '/api/hr/contracts', ['employee' => $e['id'], 'startDate' => $this->day(0), 'endDate' => $this->day(-1), 'wage' => 1000]), 'CONTRACT_DATES', [400]);
        $c = $this->expectOk($this->postAs('admin', '/api/hr/contracts', ['employee' => $e['code'], 'type' => 'fixed_term', 'startDate' => $this->day(-200), 'endDate' => $this->day(25), 'wage' => 7654.32,
            'housingAllowance' => 1500, 'transportAllowance' => 500.5]));
        $this->assertMatchesRegularExpression('/^HRC-\d{4}-\d{5}$/', $c['number']);
        $this->assertSame(['draft', '7654.32', '9654.82', 'مشرف مستودع'], [$c['state'], $c['wage'], $c['package'], $c['jobTitle']]);
        $this->assertSame(['edit' => true, 'start' => true, 'end' => false, 'cancel' => true, 'renew' => false], $c['can']);

        // the state table is the only way through
        $this->expectRejected($this->postAs('admin', "/api/hr/contracts/{$c['id']}/end"), 'CONTRACT_TRANSITION', [422]);
        $running = $this->expectOk($this->postAs('admin', "/api/hr/contracts/{$c['number']}/start"));
        $this->assertSame(['running', 25, 'd30'], [$running['state'], $running['endsInDays'], $running['stage']]);
        $this->expectRejected($this->postAs('admin', "/api/hr/contracts/{$c['id']}/start"), 'CONTRACT_TRANSITION', [422]);
        $second = $this->expectOk($this->postAs('admin', '/api/hr/contracts', ['employee' => $e['id'], 'startDate' => $this->day(26), 'wage' => 8000]));
        $this->expectRejected($this->postAs('admin', "/api/hr/contracts/{$second['id']}/start"), 'CONTRACT_OVERLAP', [422]);
        $this->expectRejected($this->postAs('admin', "/api/hr/contracts/{$second['id']}/cancel", ['reason' => 'x']), 'INVALID_INPUT', [400]);
        $this->assertSame('cancelled', $this->expectOk($this->postAs('admin', "/api/hr/contracts/{$second['id']}/cancel", ['reason' => 'أُدخل مرتين بالخطأ']))['state']);
        $this->expectRejected($this->postAs('admin', "/api/hr/contracts/{$second['id']}/start"), 'CONTRACT_TRANSITION', [422]);

        // a contract that ends within 60 days alerts — to hr.private only
        $alert = collect($this->expectOk($this->getAs('admin', "/api/hr/alerts?employee={$e['id']}"))['items'])->firstWhere('category', 'contract');
        $this->assertSame(['d30', true, $c['id']], [$alert['stage'], $alert['private'], $alert['refId']]);
        $this->assertSame(0, $this->expectOk($this->getAs($this->clerk, "/api/hr/alerts?employee={$e['id']}"))['total']);
        $this->assertSame([$c['id']], array_column($this->expectOk($this->getAs('admin', '/api/hr/contracts?ending=30&q='.$e['code']))['items'], 'id'));

        // a change of pay is audited by the names of the fields — the amounts never reach the trail
        $raised = $this->expectOk($this->patchAs('admin', "/api/hr/contracts/{$c['id']}", ['wage' => 8123.45, 'notes' => 'زيادة سنوية']));
        $this->assertSame('8123.45', $raised['wage']);
        $trail = AuditLog::where('entity_type', 'Contract')->where('entity_id', $c['id'])->get()->map(fn ($a) => $a->new_value.$a->old_value)->implode(' ');
        $this->assertStringContainsString('wage', $trail);
        foreach (['7654', '8123', '1500'] as $figure) {
            $this->assertStringNotContainsString($figure, $trail);
        }

        // renewal: a new draft with the same terms, starting the day after; the old one ends; its alert is resolved
        $renewal = $this->expectOk($this->postAs('admin', "/api/hr/contracts/{$c['id']}/renew", ['endDate' => $this->day(391)]));
        $this->assertSame(['draft', $this->day(26), '8123.45', 'fixed_term'], [$renewal['state'], $renewal['startDate'], $renewal['wage'], $renewal['type']]);
        $ended = $this->expectOk($this->postAs('admin', "/api/hr/contracts/{$c['id']}/end", ['date' => $this->day(0), 'reason' => 'انتهت المدة']));
        $this->assertSame(['expired', $this->day(0), 'انتهت المدة'], [$ended['state'], $ended['endDate'], $ended['closeReason']]);
        $this->expectRejected($this->patchAs('admin', "/api/hr/contracts/{$c['id']}", ['wage' => 1]), 'CONTRACT_STATE', [422]);
        $this->assertSame('ملاحظة لاحقة', $this->expectOk($this->patchAs('admin', "/api/hr/contracts/{$c['id']}", ['notes' => 'ملاحظة لاحقة']))['notes'], 'a note may still be added to a closed contract');
        $this->assertSame('running', $this->expectOk($this->postAs('admin', "/api/hr/contracts/{$renewal['id']}/start"))['state']);
        $this->assertSame(0, HrAlert::where('employee_id', $e['id'])->where('status', 'open')->count());

        // the employee's card carries the contracts for hr.private; the list filters by state
        $card = $this->expectOk($this->getAs('admin', "/api/hr/employees/{$e['id']}"));
        $this->assertSame([$renewal['id'], $second['id'], $c['id']], array_column($card['contracts'], 'id'), 'newest start first');
        $this->assertSame([$renewal['id']], array_column($this->expectOk($this->getAs('admin', "/api/hr/contracts?employee={$e['id']}&state=running"))['items'], 'id'));
        $this->assertSame(3, $this->expectOk($this->getAs('admin', "/api/hr/contracts?employee={$e['id']}"))['total']);
        $this->assertArrayHasKey('contracts', $this->expectOk($this->getAs('admin', '/api/hr/summary')));
    }
}
