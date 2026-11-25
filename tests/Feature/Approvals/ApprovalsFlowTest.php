<?php

namespace Tests\Feature\Approvals;

use App\Approvals\Models\ApprovalApprover;
use App\Approvals\Models\ApprovalAttachment;
use App\Approvals\Models\ApprovalCategory;
use App\Approvals\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\Product;
use App\Models\StatusHistory;
use App\Models\StoredFile;
use App\Models\Supplier;
use App\Models\User;
use Tests\ApiTestCase;

/**
 * «الاعتمادات» — approval requests raised and decided in OPS: categories and their rules, the fields a category
 * requires, approvers in parallel and in sequence, the minimum number of approvals, amount thresholds, refusing and
 * taking a decision back, recalling and cancelling, who may see and do what, comments, files, lists, and that every
 * step is told to the right person and written to the audit trail.
 *
 * Users of the demo data: admin manages (approval.manage); proc / sales raise; finance / gm / wm approve; worker cannot
 * open the section at all.
 */
class ApprovalsFlowTest extends ApiTestCase
{
    private const API = '/api/approvals';

    /** A category created by the manager; `$over` replaces the defaults (one approver, parallel, nothing required). */
    private function category(array $over = []): array
    {
        return $this->expectOk($this->postAs('admin', self::API.'/categories', $over + [
            'nameAr' => 'نوع اختبار '.self::uid().'-'.bin2hex(random_bytes(2)), 'approvalMode' => 'parallel', 'minApprovals' => 1, 'approvers' => [['user' => 'finance']],
        ]));
    }

    private function raise(string $user, array $body): array
    {
        return $this->expectOk($this->postAs($user, self::API.'/requests', $body));
    }

    private function act(string $user, array $request, string $action, array $body = []): array
    {
        return $this->expectOk($this->postAs($user, self::API."/requests/{$request['number']}/{$action}", $body));
    }

    private function userId(string $username): string
    {
        return (string) User::where('username', $username)->value('id');
    }

    /** What this user was told about this request, oldest first. @return string[] */
    private function told(string $username, array $request): array
    {
        return Notification::where('user_id', $this->userId($username))->where('entity_type', 'ApprovalRequest')->where('entity_id', $request['id'])->orderBy('at')->orderBy('id')->pluck('text_ar')->all();
    }

    /** @return array<string, string> username → status of his line */
    private function lines(array $request): array
    {
        return array_column(array_map(fn (array $a) => ['u' => $a['user']['username'], 's' => $a['status']], $request['approvers']), 's', 'u');
    }

    public function test_categories_are_set_up_by_the_manager_and_refuse_what_cannot_work(): void
    {
        $tag = self::uid();
        // the section is closed to a role without page.approvals; a category is the manager's to create
        $this->expectRejected($this->getAs('worker', self::API.'/categories'), 'FORBIDDEN', [403]);
        $this->expectRejected($this->getAs('worker', self::API.'/summary'), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('proc', self::API.'/categories', ['nameAr' => "نوع {$tag}"]), 'FORBIDDEN', [403]);

        $base = ['nameAr' => "طلب صرف {$tag}", 'nameEn' => "Payment {$tag}", 'approvalMode' => 'sequence', 'minApprovals' => 2, 'fields' => ['amount' => 'required', 'partner' => 'optional'],
            'approvers' => [['user' => 'finance', 'required' => true], ['user' => 'gm', 'minAmount' => 10000]]];
        $before = ApprovalCategory::count();
        $post = fn (array $over) => $this->postAs('admin', self::API.'/categories', $over + $base);
        $this->expectRejected($post(['nameAr' => '']), 'INVALID_INPUT', [400]);
        $this->expectRejected($post(['approvers' => [['user' => 'nobody-here']]]), 'APPROVAL_APPROVER_UNKNOWN', [400]);
        $this->expectRejected($post(['approvers' => [['user' => 'worker']]]), 'APPROVAL_APPROVER_NO_ACCESS', [422]); // he could never open the request he is asked about
        $this->expectRejected($post(['approvers' => [['user' => 'finance'], ['user' => 'finance']]]), 'APPROVAL_APPROVER_DUPLICATE', [400]);
        $this->expectRejected($post(['minApprovals' => 3]), 'APPROVAL_MIN_EXCEEDS_APPROVERS', [422]);
        $this->expectRejected($post(['code' => 'OD-77']), 'APPROVAL_CATEGORY_CODE_RESERVED', [400]);
        $this->expectRejected($post(['code' => 'has space']), 'APPROVAL_CATEGORY_CODE', [400]);
        $this->expectRejected($post(['fields' => ['colour' => 'required']]), 'APPROVAL_FIELD_RULE', [400]);
        $this->expectRejected($post(['approvalMode' => 'by-vote']), 'INVALID_INPUT', [400]);
        $this->assertSame($before, ApprovalCategory::count(), 'a refused category leaves nothing behind');

        $c = $this->expectOk($post(['code' => "pay-{$tag}", 'description' => 'تحويل لمورد']));
        $this->assertSame(["PAY-{$tag}", 'sequence', 2, false, true, null, false], [$c['code'], $c['approvalMode'], $c['minApprovals'], $c['allowSelfApproval'], $c['active'], $c['source'], $c['owned']]);
        $this->assertSame(['amount' => 'required', 'partner' => 'optional', 'reference' => 'no', 'document' => 'optional'], array_intersect_key($c['fields'], array_flip(['amount', 'partner', 'reference', 'document'])),
            'a field nobody set is not asked for; a file can always be attached');
        $this->assertSame([['finance', 10, true, null], ['gm', 20, false, '10000.00']], array_map(fn ($a) => [$a['user']['username'], $a['sequence'], $a['required'], $a['minAmount']], $c['approvers']));
        $this->expectRejected($post(['code' => "PAY-{$tag}", 'nameAr' => 'مكرر']), 'APPROVAL_CATEGORY_CODE_TAKEN', [409]);
        // a category nobody named gets the next code
        $auto = $this->category(['nameAr' => "بلا رمز {$tag}"]);
        $this->assertMatchesRegularExpression('/^AC-\d{3,}$/', $auto['code']);
        $this->assertSame($auto['nameAr'], $auto['nameEn'], 'one name serves both languages until an English one is given');

        // every signed-in user of the section reads the categories; only the manager changes one
        $this->assertSame($c['id'], $this->expectOk($this->getAs('proc', self::API."/categories/{$c['code']}"))['id']);
        $this->expectRejected($this->patchAs('proc', self::API."/categories/{$c['code']}", ['nameAr' => 'تغيير']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->patchAs('admin', self::API."/categories/{$c['code']}", ['minApprovals' => 3]), 'APPROVAL_MIN_EXCEEDS_APPROVERS', [422]);
        $u = $this->expectOk($this->patchAs('admin', self::API."/categories/{$c['code']}", ['nameAr' => "صرف معدّل {$tag}", 'approvers' => [['user' => 'finance']], 'minApprovals' => 1, 'fields' => ['reference' => 'required']]));
        $this->assertSame(["صرف معدّل {$tag}", 1, 1, 'required', 'required'], [$u['nameAr'], $u['minApprovals'], count($u['approvers']), $u['fields']['amount'], $u['fields']['reference']], 'only the rules that were sent change');
        $this->assertSame(['APPROVAL_CATEGORY.CREATE', 'APPROVAL_CATEGORY.UPDATE'], AuditLog::where('entity_type', 'ApprovalCategory')->where('entity_id', $c['id'])->orderBy('at')->orderBy('id')->pluck('action')->all());

        // a stopped category: still listed for the manager, no longer offered, and refuses a new request
        $this->expectOk($this->patchAs('admin', self::API."/categories/{$c['code']}", ['active' => false]));
        $this->assertSame([$c['code']], array_column($this->expectOk($this->getAs('admin', self::API."/categories?active=0&q=PAY-{$tag}"))['items'], 'code'));
        $this->assertSame([], $this->expectOk($this->getAs('admin', self::API."/categories?active=1&q=PAY-{$tag}"))['items']);
        $offered = array_column($this->expectOk($this->getAs('proc', self::API.'/lookups'))['categories'], 'code');
        $this->assertNotContains($c['code'], $offered);
        $this->assertContains($auto['code'], $offered);
        $this->expectRejected($this->postAs('proc', self::API.'/requests', ['category' => $c['code'], 'amount' => 10]), 'APPROVAL_CATEGORY_INACTIVE', [422]);
        // the people who can be named as approvers: users who can open the section — not the worker, not a service account
        $users = array_column($this->expectOk($this->getAs('proc', self::API.'/lookups'))['users'], 'username');
        $this->assertContains('finance', $users);
        $this->assertNotContains('worker', $users);
        $this->assertSame([], array_values(array_filter($users, fn ($name) => str_starts_with($name, 'svc.'))));
    }

    public function test_a_request_is_decided_by_its_named_approvers_in_parallel(): void
    {
        $tag = self::uid();
        $c = $this->category(['minApprovals' => 2, 'fields' => ['amount' => 'required', 'reference' => 'required', 'partner' => 'optional'],
            'approvers' => [['user' => 'finance', 'required' => true], ['user' => 'gm'], ['user' => 'wm']]]);

        // a draft may be incomplete; it is not sent before what the category requires is there
        $draft = $this->raise('proc', ['category' => $c['code'], 'title' => "دفعة مورد {$tag}"]);
        $this->assertSame(['draft', ['finance' => 'new', 'gm' => 'new', 'wm' => 'new'], true, true, false], [$draft['status'], $this->lines($draft), $draft['can']['edit'], $draft['can']['submit'], $draft['can']['approve']]);
        $this->assertMatchesRegularExpression('/^APR-\d{4}-\d{5}$/', $draft['number']);
        $refused = $this->expectRejected($this->postAs('proc', self::API."/requests/{$draft['number']}/submit"), 'APPROVAL_FIELDS_REQUIRED', [400]);
        $this->assertSame(['amount', 'reference'], array_column($refused['details'], 'path'));
        $this->assertSame([], $this->told('finance', $draft), 'nobody is asked about a draft');

        // it is its requester's: nobody else sees or changes it (a manager aside)
        $this->expectRejected($this->getAs('sales', self::API."/requests/{$draft['number']}"), 'APPROVAL_NOT_VISIBLE', [403]);
        $this->expectRejected($this->patchAs('sales', self::API."/requests/{$draft['number']}", ['amount' => 5]), 'APPROVAL_NOT_YOURS', [403]);
        $this->expectRejected($this->postAs('sales', self::API."/requests/{$draft['number']}/submit"), 'APPROVAL_NOT_YOURS', [403]);
        $this->assertSame($draft['id'], $this->expectOk($this->getAs('admin', self::API."/requests/{$draft['id']}"))['id'], 'by id or by number');
        $this->expectRejected($this->getAs('proc', self::API.'/requests/APR-0000-00000'), 'APPROVAL_NOT_FOUND', [404]);

        $filled = $this->expectOk($this->patchAs('proc', self::API."/requests/{$draft['number']}", ['amount' => 2500.5, 'reference' => 'PO-2026-00012', 'reason' => 'دفعة مقدمة']));
        $this->assertSame(['2500.50', 'PO-2026-00012', 'دفعة مقدمة'], [$filled['amount'], $filled['reference'], $filled['reason']]);
        $sent = $this->act('proc', $draft, 'submit');
        $this->assertSame(['pending', ['finance' => 'pending', 'gm' => 'pending', 'wm' => 'pending'], ['approved' => 0, 'needed' => 2, 'total' => 3]], [$sent['status'], $this->lines($sent), $sent['progress']]);
        $this->assertNotNull($sent['submittedAt']);
        foreach (['finance', 'gm', 'wm'] as $approver) {
            $told = $this->told($approver, $sent);
            $this->assertCount(1, $told, "{$approver} is asked once");
            $this->assertStringContainsString('بانتظار قرارك', $told[0]);
        }
        $this->assertSame([], $this->told('proc', $sent), 'nobody is told about what he did himself');

        // "waiting for me" is the approver's list, and his alone
        $waiting = fn (string $user) => array_column($this->expectOk($this->getAs($user, self::API.'/requests?scope=waiting&pageSize=200'))['items'], 'number');
        $this->assertContains($sent['number'], $waiting('finance'));
        $this->assertNotContains($sent['number'], $waiting('sales'));
        $this->assertNotContains($sent['number'], $waiting('proc'));
        $this->assertGreaterThanOrEqual(1, $this->expectOk($this->getAs('finance', self::API.'/summary'))['waiting']);
        $asFinance = $this->expectOk($this->getAs('finance', self::API."/requests/{$sent['number']}"));
        $this->assertSame([true, true, false, false, false, 'pending', true], [$asFinance['can']['approve'], $asFinance['can']['refuse'], $asFinance['can']['edit'], $asFinance['can']['cancel'], $asFinance['my']['isRequester'], $asFinance['my']['lineStatus'], $asFinance['my']['turn']]);

        // deciding is the right of the named approvers: not a bystander, not the requester, not even the manager
        foreach (['sales', 'proc', 'admin'] as $notAnApprover) {
            $this->expectRejected($this->postAs($notAnApprover, self::API."/requests/{$sent['number']}/approve"), 'APPROVAL_NOT_APPROVER', [403]);
        }
        $this->expectRejected($this->patchAs('proc', self::API."/requests/{$sent['number']}", ['amount' => 1]), 'APPROVAL_NOT_DRAFT', [422]);
        $this->assertSame('pending', ApprovalRequest::findOrFail($sent['id'])->status);

        $one = $this->act('gm', $sent, 'approve', ['comment' => 'لا مانع']);
        $this->assertSame(['pending', 1, 'approved', 'لا مانع', true, false], [$one['status'], $one['progress']['approved'], $one['my']['lineStatus'], $one['approvers'][1]['comment'], $one['can']['withdraw'], $one['can']['approve']]);
        $this->expectRejected($this->postAs('gm', self::API."/requests/{$sent['number']}/approve"), 'APPROVAL_ALREADY_DECIDED', [422]);
        // two approvals reach the minimum, but the approver marked as required has not spoken
        $two = $this->act('wm', $sent, 'approve');
        $this->assertSame(['pending', ['approved' => 2, 'needed' => 2, 'total' => 3]], [$two['status'], $two['progress']]);
        $done = $this->act('finance', $sent, 'approve');
        $this->assertSame('approved', $done['status']);
        $this->assertNotNull($done['decidedAt']);
        $this->assertCount(1, array_filter($this->told('proc', $sent), fn ($text) => str_contains($text, 'اعتُمد')), 'the requester is told his request was approved');
        $this->assertNotContains($sent['number'], $waiting('finance'));

        // approved is final: no decision is taken back, the requester cannot cancel it — a manager voids it, with a reason
        $this->expectRejected($this->postAs('gm', self::API."/requests/{$sent['number']}/withdraw"), 'APPROVAL_FINAL', [422]);
        $this->expectRejected($this->postAs('proc', self::API."/requests/{$sent['number']}/cancel", ['reason' => 'خطأ']), 'APPROVAL_CANCEL_DECIDED', [422]);
        $this->expectRejected($this->postAs('proc', self::API."/requests/{$sent['number']}/reopen"), 'APPROVAL_TRANSITION', [422]);
        $this->expectRejected($this->postAs('admin', self::API."/requests/{$sent['number']}/cancel"), 'APPROVAL_REASON_REQUIRED', [400]);
        $void = $this->act('admin', $sent, 'cancel', ['reason' => 'دُفعت الفاتورة مرتين']);
        $this->assertSame(['cancelled', 'دُفعت الفاتورة مرتين'], [$void['status'], $void['cancelReason']]);
        $this->assertCount(1, array_filter($this->told('proc', $sent), fn ($text) => str_contains($text, 'أُلغي')));

        // the story of the request, its status history and the audit trail agree
        $this->assertSame(['created', 'edited', 'submitted', 'approved', 'approved', 'approved', 'cancelled'], array_column($void['timeline'], 'kind'));
        $this->assertSame(['gm', 'wm', 'finance'], array_map(fn ($e) => $e['by']['username'], array_slice($void['timeline'], 3, 3)));
        $this->assertSame([[null, 'draft'], ['draft', 'pending'], ['pending', 'approved'], ['approved', 'cancelled']],
            StatusHistory::where('entity_type', 'ApprovalRequest')->where('entity_id', $sent['id'])->orderBy('at')->orderBy('id')->get()->map(fn ($h) => [$h->from_status, $h->to_status])->all());
        $actions = AuditLog::where('entity_type', 'ApprovalRequest')->where('entity_id', $sent['id'])->where('action', '!=', 'STATUS')->orderBy('at')->orderBy('id')->pluck('action')->all();
        $this->assertSame(['APPROVAL.CREATE', 'APPROVAL.UPDATE', 'APPROVAL.SUBMIT', 'APPROVAL.APPROVE', 'APPROVAL.APPROVE', 'APPROVAL.APPROVE', 'APPROVAL.CANCEL'], $actions);
        $this->assertSame('finance', AuditLog::where('entity_id', $sent['id'])->where('action', 'APPROVAL.APPROVE')->orderByDesc('at')->orderByDesc('id')->value('username'));
    }

    public function test_approvers_in_sequence_are_asked_one_after_the_other_from_their_amount_on(): void
    {
        $tag = self::uid();
        $c = $this->category(['approvalMode' => 'sequence', 'fields' => ['amount' => 'required'],
            'approvers' => [['user' => 'finance', 'required' => true], ['user' => 'gm', 'required' => true, 'minAmount' => 10000]]]);

        // below the threshold the general manager is not asked at all
        $small = $this->raise('proc', ['category' => $c['code'], 'title' => "صغير {$tag}", 'amount' => 5000, 'submit' => true]);
        $this->assertSame(['finance' => 'pending'], $this->lines($small));
        $this->assertSame('approved', $this->act('finance', $small, 'approve')['status']);

        // from the threshold on: finance first, the general manager waits for his turn
        $big = $this->raise('proc', ['category' => $c['code'], 'title' => "كبير {$tag}", 'amount' => 25000, 'submit' => true]);
        $this->assertSame(['pending', ['finance' => 'pending', 'gm' => 'waiting'], 'sequence'], [$big['status'], $this->lines($big), $big['approvalMode']]);
        $this->assertCount(1, $this->told('finance', $big));
        $this->assertSame([], $this->told('gm', $big), 'the one whose turn has not come is not asked yet');
        $list = fn (string $user, string $scope) => array_column($this->expectOk($this->getAs($user, self::API."/requests?scope={$scope}&pageSize=200"))['items'], 'number');
        $this->assertNotContains($big['number'], $list('gm', 'waiting'));
        $this->assertContains($big['number'], $list('gm', 'involved'), 'he sees the request he will be asked about');
        $asGm = $this->expectOk($this->getAs('gm', self::API."/requests/{$big['number']}"));
        $this->assertSame([false, 'APPROVAL_NOT_YOUR_TURN', 'waiting', false], [$asGm['can']['approve'], $asGm['decisionBlock']['code'], $asGm['my']['lineStatus'], $asGm['my']['turn']]);
        $this->expectRejected($this->postAs('gm', self::API."/requests/{$big['number']}/approve"), 'APPROVAL_NOT_YOUR_TURN', [422]);

        $step = $this->act('finance', $big, 'approve');
        $this->assertSame(['pending', ['finance' => 'approved', 'gm' => 'pending']], [$step['status'], $this->lines($step)]);
        $this->assertCount(1, $this->told('gm', $big), 'the next approver is told when his turn comes');
        $this->assertContains($big['number'], $list('gm', 'waiting'));

        // a refusal needs its reason, refuses the request and reaches the requester with the reason
        $this->expectRejected($this->postAs('gm', self::API."/requests/{$big['number']}/refuse"), 'APPROVAL_COMMENT_REQUIRED', [400]);
        $this->expectRejected($this->postAs('gm', self::API."/requests/{$big['number']}/refuse", ['comment' => 'لا']), 'APPROVAL_COMMENT_REQUIRED', [400]);
        $no = $this->act('gm', $big, 'refuse', ['comment' => 'المبلغ يتجاوز الميزانية']);
        $this->assertSame(['refused', 'refused', 'المبلغ يتجاوز الميزانية'], [$no['status'], $this->lines($no)['gm'], $no['approvers'][1]['comment']]);
        $this->assertNotNull($no['decidedAt']);
        $toldRequester = $this->told('proc', $big);
        $this->assertStringContainsString('المبلغ يتجاوز الميزانية', end($toldRequester));
        $this->expectRejected($this->postAs('gm', self::API."/requests/{$big['number']}/approve"), 'APPROVAL_NOT_PENDING', [422]);

        // only the one who refused reopens it by taking the refusal back; then he decides again
        $this->expectRejected($this->postAs('finance', self::API."/requests/{$big['number']}/withdraw"), 'APPROVAL_FINAL', [422]);
        $this->expectRejected($this->postAs('proc', self::API."/requests/{$big['number']}/withdraw"), 'APPROVAL_NOT_APPROVER', [403]);
        // (a request two approvers refused — only history brought from another system can hold one — is not reopened by one of them)
        ApprovalApprover::where('request_id', $big['id'])->where('status', 'approved')->update(['status' => 'refused']);
        $this->expectRejected($this->postAs('gm', self::API."/requests/{$big['number']}/withdraw"), 'APPROVAL_FINAL', [422]);
        ApprovalApprover::where('request_id', $big['id'])->whereNull('comment')->where('status', 'refused')->update(['status' => 'approved']);
        $back = $this->act('gm', $big, 'withdraw', ['comment' => 'بعد مراجعة الميزانية']);
        $this->assertSame(['pending', ['finance' => 'approved', 'gm' => 'pending'], null, null], [$back['status'], $this->lines($back), $back['approvers'][1]['comment'], $back['decidedAt']]);
        $this->expectRejected($this->postAs('gm', self::API."/requests/{$big['number']}/withdraw"), 'APPROVAL_NOTHING_TO_WITHDRAW', [422]);
        $this->assertSame('approved', $this->act('gm', $big, 'approve')['status']);
        $this->assertSame([[null, 'draft'], ['draft', 'pending'], ['pending', 'refused'], ['refused', 'pending'], ['pending', 'approved']],
            StatusHistory::where('entity_type', 'ApprovalRequest')->where('entity_id', $big['id'])->orderBy('at')->orderBy('id')->get()->map(fn ($h) => [$h->from_status, $h->to_status])->all());
        $this->assertSame(['created', 'submitted', 'approved', 'refused', 'withdrawn', 'approved'], array_column($this->expectOk($this->getAs('proc', self::API."/requests/{$big['number']}"))['timeline'], 'kind'));

        // an approval taken back while the request still waits: the turn goes back to him, the next one waits again
        $third = $this->raise('proc', ['category' => $c['code'], 'title' => "ثالث {$tag}", 'amount' => 30000, 'submit' => true]);
        $this->act('finance', $third, 'approve');
        $undone = $this->act('finance', $third, 'withdraw');
        $this->assertSame(['pending', ['finance' => 'pending', 'gm' => 'waiting']], [$undone['status'], $this->lines($undone)]);
        $this->expectRejected($this->postAs('gm', self::API."/requests/{$third['number']}/approve"), 'APPROVAL_NOT_YOUR_TURN', [422]);

        // an approver the requester adds himself is asked after the category's own
        $extra = $this->raise('proc', ['category' => $c['code'], 'title' => "بمعتمد إضافي {$tag}", 'amount' => 5000, 'approvers' => [['user' => 'wm']], 'submit' => true]);
        $this->assertSame(['finance' => 'pending', 'wm' => 'waiting'], $this->lines($extra));

        // the rule a request was sent under stays its rule when the category changes afterwards
        $this->expectOk($this->patchAs('admin', self::API."/categories/{$c['code']}", ['approvalMode' => 'parallel', 'approvers' => [['user' => 'wm']]]));
        $after = $this->expectOk($this->getAs('proc', self::API."/requests/{$third['number']}"));
        $this->assertSame(['sequence', ['finance' => 'pending', 'gm' => 'waiting']], [$after['approvalMode'], $this->lines($after)]);
    }

    public function test_a_request_is_recalled_corrected_and_sent_again_and_a_draft_follows_its_category(): void
    {
        $tag = self::uid();
        $sku = (string) Product::where('active', true)->orderBy('sku')->value('sku');
        $c = $this->category(['fields' => ['amount' => 'optional', 'items' => 'optional', 'date' => 'optional', 'period' => 'optional', 'paymentMethod' => 'optional', 'partner' => 'optional']]);
        $body = ['category' => $c['code'], 'title' => "مستلزمات {$tag}", 'amount' => 100, 'location' => 'الرياض', 'reference' => 'يُهمل', 'date' => '2026-11-30', 'periodStart' => '2026-12-01',
            'periodEnd' => '2026-12-05', 'paymentMethod' => 'bank', 'partnerType' => 'other', 'partnerName' => 'مكتبة الحي',
            'items' => [['description' => 'كرتون', 'quantity' => 2, 'uom' => 'حبة'], ['product' => $sku, 'quantity' => 1.5]]];
        $this->expectRejected($this->postAs('proc', self::API.'/requests', ['periodStart' => '2026-12-05', 'periodEnd' => '2026-12-01'] + $body), 'APPROVAL_PERIOD', [400]);
        $this->expectRejected($this->postAs('proc', self::API.'/requests', ['items' => [['quantity' => 1]]] + $body), 'APPROVAL_ITEM_DESCRIPTION', [400]);
        $this->expectRejected($this->postAs('proc', self::API.'/requests', ['items' => [['product' => 'NO-SUCH-SKU', 'quantity' => 1]]] + $body), 'APPROVAL_PRODUCT_NOT_FOUND', [400]);
        $this->expectRejected($this->postAs('proc', self::API.'/requests', ['items' => [['description' => 'صفر', 'quantity' => 0]]] + $body), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('proc', self::API.'/requests', ['paymentMethod' => 'barter'] + $body), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('proc', self::API.'/requests', ['partnerType' => 'supplier', 'partner' => 'SUP-NOT-THERE'] + $body), 'APPROVAL_PARTNER_NOT_FOUND', [400]);
        $this->expectRejected($this->postAs('worker', self::API.'/requests', $body), 'FORBIDDEN', [403]);

        $r = $this->raise('proc', $body + ['submit' => true]);
        // what the category does not use is not kept; what it uses is kept as it was typed
        $this->assertSame([null, null, '2026-11-30', 'bank', 'other', 'مكتبة الحي', '100.00'], [$r['location'], $r['reference'], $r['date'], $r['paymentMethod'], $r['partner']['type'], $r['partner']['name'], $r['amount']]);
        $this->assertStringStartsWith('2026-12-01', $r['periodStart']);
        $this->assertSame([['كرتون', '2.000', 'حبة', null], [Product::where('sku', $sku)->value('name_ar'), '1.500', null, $sku]],
            array_map(fn ($i) => [$i['description'], $i['quantity'], $i['uom'], $i['product']['sku'] ?? null], $r['items']));
        $this->assertSame('pending', $r['status']);
        $this->assertCount(1, $this->told('finance', $r));

        // the requester recalls it: a draft again, nobody is asked any more, and the approver is told so
        $recalled = $this->act('proc', $r, 'reopen');
        $this->assertSame(['draft', ['finance' => 'new'], null, true], [$recalled['status'], $this->lines($recalled), $recalled['submittedAt'], $recalled['can']['edit']]);
        $this->assertStringContainsString('سُحب', $this->told('finance', $r)[1]);
        $this->assertNotContains($r['number'], array_column($this->expectOk($this->getAs('finance', self::API.'/requests?scope=waiting&pageSize=200'))['items'], 'number'));
        $edited = $this->expectOk($this->patchAs('proc', self::API."/requests/{$r['number']}", ['amount' => 150, 'items' => [['description' => 'كرتون كبير', 'quantity' => 3]], 'title' => '']));
        $this->assertSame(['150.00', 1, "مستلزمات {$tag}"], [$edited['amount'], count($edited['items']), $edited['title']], 'the lines are replaced as a whole; a request keeps its title');
        $again = $this->act('proc', $r, 'submit');
        $this->assertSame(['pending', ['finance' => 'pending']], [$again['status'], $this->lines($again)]);
        $this->assertCount(3, $this->told('finance', $r), 'asked again after the correction');
        $this->assertSame('refused', $this->act('finance', $r, 'refuse', ['comment' => 'السعر مرتفع'])['status']);

        // refused → the requester takes it back to a draft; a draft is cancelled without a reason; a cancelled one is reopened
        $this->assertSame('draft', $this->act('proc', $r, 'reopen')['status']);
        $this->expectRejected($this->postAs('sales', self::API."/requests/{$r['number']}/cancel"), 'APPROVAL_NOT_YOURS', [403]);
        $this->assertSame('cancelled', $this->act('proc', $r, 'cancel')['status']);
        $this->expectRejected($this->postAs('proc', self::API."/requests/{$r['number']}/cancel"), 'APPROVAL_TRANSITION', [422]);
        $this->expectRejected($this->postAs('proc', self::API."/requests/{$r['number']}/submit"), 'APPROVAL_TRANSITION', [422]);
        $this->assertSame('draft', $this->act('proc', $r, 'reopen')['status']);
        $this->expectRejected($this->postAs('proc', self::API."/requests/{$r['number']}/reopen"), 'APPROVAL_TRANSITION', [422]);
        // a pending request is cancelled by its requester with a reason, and the approver who was asked is told
        $live = $this->act('proc', $r, 'submit');
        $this->expectRejected($this->postAs('proc', self::API."/requests/{$r['number']}/cancel"), 'APPROVAL_REASON_REQUIRED', [400]);
        $this->assertSame('cancelled', $this->act('proc', $live, 'cancel', ['reason' => 'لم تعد مطلوبة'])['status']);
        $toldFinance = $this->told('finance', $r);
        $this->assertStringContainsString('لم تعد مطلوبة', end($toldFinance));

        // a draft follows its category: a new approver of the category is on it when it is sent
        $draft = $this->raise('proc', ['category' => $c['code'], 'title' => "مسودة {$tag}", 'amount' => 20]);
        $this->expectOk($this->patchAs('admin', self::API."/categories/{$c['code']}", ['approvers' => [['user' => 'gm'], ['user' => 'wm']], 'minApprovals' => 2]));
        $sent = $this->act('proc', $draft, 'submit');
        $this->assertSame([['gm' => 'pending', 'wm' => 'pending'], 2], [$this->lines($sent), $sent['minApprovals']]);
        // a title nobody typed is made from the category and what the request is about
        $untitled = $this->raise('proc', ['category' => $c['code'], 'partnerType' => 'other', 'partnerName' => 'شركة النور']);
        $this->assertSame("{$c['nameAr']} — شركة النور", $untitled['title']);
    }

    public function test_nobody_approves_his_own_request_unless_the_category_says_so_and_approvers_can_be_added(): void
    {
        $tag = self::uid();
        // the requester is one of the category's approvers: he is left out of his own request
        $c1 = $this->category(['approvers' => [['user' => 'proc'], ['user' => 'finance']]]);
        $own = $this->raise('proc', ['category' => $c1['code'], 'title' => "طلبي {$tag}", 'submit' => true]);
        $this->assertSame(['finance' => 'pending'], $this->lines($own));
        // …and when he is the only one, there is nobody to ask: the request is not sent (and not created by that call)
        $c2 = $this->category(['approvers' => [['user' => 'proc']]]);
        $count = ApprovalRequest::count();
        $this->expectRejected($this->postAs('proc', self::API.'/requests', ['category' => $c2['code'], 'title' => 'وحيد', 'submit' => true]), 'APPROVAL_NO_APPROVERS', [422]);
        $this->assertSame($count, ApprovalRequest::count());

        // he names approvers himself on his draft: users who can open the section, each once, never himself
        $d = $this->raise('proc', ['category' => $c2['code'], 'title' => "بمعتمد أختاره {$tag}", 'approvers' => [['user' => 'gm', 'required' => true]]]);
        $this->assertSame([['gm', 'new', 'requester', true]], array_map(fn ($a) => [$a['user']['username'], $a['status'], $a['origin'], $a['required']], $d['approvers']));
        $add = fn (string $who, string $user) => $this->postAs($who, self::API."/requests/{$d['number']}/approvers", ['user' => $user]);
        $this->expectRejected($add('proc', 'proc'), 'APPROVAL_SELF_DECISION', [422]);
        $this->expectRejected($add('proc', 'gm'), 'APPROVAL_APPROVER_DUPLICATE', [422]);
        $this->expectRejected($add('proc', 'worker'), 'APPROVAL_APPROVER_NO_ACCESS', [422]);
        $this->expectRejected($add('proc', 'nobody-here'), 'APPROVAL_APPROVER_UNKNOWN', [400]);
        $this->expectRejected($add('sales', 'wm'), 'APPROVAL_NOT_YOURS', [403]);
        $with = $this->expectOk($add('proc', 'wm'));
        $this->assertSame(['gm' => 'new', 'wm' => 'new'], $this->lines($with));
        $wmLine = $with['approvers'][1]['id'];
        $without = $this->expectOk($this->postAs('proc', self::API."/requests/{$d['number']}/approvers/{$wmLine}/remove"));
        $this->assertSame(['gm' => 'new'], $this->lines($without), 'a draft is not a record of anything yet: the line simply goes');
        // an approver that comes from the category follows the category
        $catLine = $this->raise('proc', ['category' => $c1['code'], 'title' => "من النوع {$tag}"])['approvers'][0];
        $this->assertSame('category', $catLine['origin']);
        $fromCategory = ApprovalRequest::where('title', "من النوع {$tag}")->value('number');
        $this->expectRejected($this->postAs('proc', self::API."/requests/{$fromCategory}/approvers/{$catLine['id']}/remove"), 'APPROVAL_APPROVER_FROM_CATEGORY', [422]);
        $this->assertSame(['gm' => 'pending'], $this->lines($this->act('proc', $d, 'submit')));

        // a category that allows it: the requester decides his own request
        $c3 = $this->category(['approvers' => [['user' => 'proc']], 'allowSelfApproval' => true]);
        $self = $this->raise('proc', ['category' => $c3['code'], 'title' => "أعتمده بنفسي {$tag}", 'submit' => true]);
        $this->assertSame([['proc' => 'pending'], true], [$this->lines($self), $self['can']['approve']]);
        $this->assertSame('approved', $this->act('proc', $self, 'approve')['status']);

        // raising a request in somebody else's name is the manager's; the request is then that person's
        $this->expectRejected($this->postAs('proc', self::API.'/requests', ['category' => $c1['code'], 'requester' => 'sales']), 'APPROVAL_ON_BEHALF', [403]);
        $behalf = $this->raise('admin', ['category' => $c1['code'], 'title' => "باسم المبيعات {$tag}", 'requester' => 'sales', 'submit' => true]);
        $this->assertSame(['sales', 'admin', ['proc' => 'pending', 'finance' => 'pending']], [$behalf['requester']['username'], $behalf['createdBy']['username'], $this->lines($behalf)]);
        $this->assertContains($behalf['number'], array_column($this->expectOk($this->getAs('sales', self::API.'/requests?scope=mine&pageSize=200'))['items'], 'number'));
        $this->assertTrue($this->expectOk($this->getAs('sales', self::API."/requests/{$behalf['number']}"))['can']['cancel']);
    }

    public function test_the_manager_changes_the_approvers_of_a_request_that_waits(): void
    {
        $tag = self::uid();
        $c = $this->category(['minApprovals' => 2, 'approvers' => [['user' => 'finance', 'required' => true], ['user' => 'gm']]]);
        $r = $this->raise('proc', ['category' => $c['code'], 'title' => "بانتظار {$tag}", 'submit' => true]);
        $line = fn (array $request, string $user) => collect($request['approvers'])->first(fn ($a) => $a['user']['username'] === $user);

        // once sent, the approvers are no longer the requester's to change
        $this->expectRejected($this->postAs('proc', self::API."/requests/{$r['number']}/approvers", ['user' => 'wm']), 'APPROVAL_MANAGE_ONLY', [403]);
        $added = $this->expectOk($this->postAs('admin', self::API."/requests/{$r['number']}/approvers", ['user' => 'wm']));
        $this->assertSame(['pending', 'manager'], [$line($added, 'wm')['status'], $line($added, 'wm')['origin']]);
        $this->assertCount(1, $this->told('wm', $r), 'an approver added to a waiting request is asked at once');

        $this->act('gm', $r, 'approve');
        $this->expectRejected($this->postAs('admin', self::API."/requests/{$r['number']}/approvers/{$line($added, 'gm')['id']}/remove"), 'APPROVAL_ALREADY_DECIDED', [422]);
        $this->expectRejected($this->postAs('proc', self::API."/requests/{$r['number']}/approvers/{$line($added, 'finance')['id']}/remove"), 'APPROVAL_MANAGE_ONLY', [403]);
        // the required approver is away: the manager takes him off; his line stays on record and no longer counts
        $removed = $this->expectOk($this->postAs('admin', self::API."/requests/{$r['number']}/approvers/{$line($added, 'finance')['id']}/remove", ['reason' => 'في إجازة']));
        $this->assertSame(['removed', 'pending', ['approved' => 1, 'needed' => 2, 'total' => 2]], [$line($removed, 'finance')['status'], $removed['status'], $removed['progress']]);
        $toldFinance = $this->told('finance', $r);
        $this->assertStringContainsString('لم يعد قرارك مطلوبًا', end($toldFinance));
        $this->expectRejected($this->postAs('finance', self::API."/requests/{$r['number']}/approve"), 'APPROVAL_NOT_APPROVER', [403]);
        $this->expectRejected($this->getAs('finance', self::API."/requests/{$r['number']}"), 'APPROVAL_NOT_VISIBLE', [403]);
        $done = $this->act('wm', $r, 'approve');
        $this->assertSame('approved', $done['status']);
        $this->assertSame(['approver_added', 'approved', 'approver_removed', 'approved'], array_slice(array_column($done['timeline'], 'kind'), 2));
        $this->expectRejected($this->postAs('admin', self::API."/requests/{$r['number']}/approvers", ['user' => 'sales']), 'APPROVAL_FINAL', [422]);

        // a request is never left without an approver; taking the last undecided one off may complete it
        $single = $this->raise('proc', ['category' => $this->category()['code'], 'title' => "واحد {$tag}", 'submit' => true]);
        $this->expectRejected($this->postAs('admin', self::API."/requests/{$single['number']}/approvers/{$single['approvers'][0]['id']}/remove"), 'APPROVAL_LAST_APPROVER', [422]);
        $pair = $this->raise('proc', ['category' => $this->category(['approvers' => [['user' => 'finance', 'required' => true], ['user' => 'gm', 'required' => true]]])['code'], 'title' => "اثنان {$tag}", 'submit' => true]);
        $this->act('finance', $pair, 'approve');
        $completed = $this->expectOk($this->postAs('admin', self::API."/requests/{$pair['number']}/approvers/{$line($pair, 'gm')['id']}/remove", ['reason' => 'غادر الشركة']));
        $this->assertSame('approved', $completed['status'], 'with the missing required approver gone, the approvals given are enough');
    }

    public function test_comments_and_files_belong_to_the_request_and_to_those_who_see_it(): void
    {
        $tag = self::uid();
        $c = $this->category(['fields' => ['document' => 'required']]);
        $r = $this->raise('proc', ['category' => $c['code'], 'title' => "بمرفق {$tag}"]);
        $missing = $this->expectRejected($this->postAs('proc', self::API."/requests/{$r['number']}/submit"), 'APPROVAL_FIELDS_REQUIRED', [400]);
        $this->assertSame(['attachments'], array_column($missing['details'], 'path'));

        $png = base64_decode(explode(',', self::proofImage())[1]);
        $attach = fn (string $user, array $file) => $this->postAs($user, self::API."/requests/{$r['number']}/attachments", $file);
        $this->expectRejected($attach('proc', ['fileName' => 'x.png', 'mime' => 'image/png', 'base64' => '!!!!']), 'ATTACHMENT_EMPTY', [400]);
        $this->expectRejected($attach('proc', ['fileName' => 'x.png', 'mime' => 'not a mime', 'base64' => base64_encode('x')]), 'INVALID_INPUT', [400]);
        $this->expectRejected($attach('proc', ['fileName' => 'big.bin', 'mime' => 'application/octet-stream', 'base64' => base64_encode(str_repeat('a', 5 * 1024 * 1024 + 1))]), 'ATTACHMENT_TOO_LARGE', [400]);
        $this->expectRejected($attach('sales', ['fileName' => 'x.png', 'mime' => 'image/png', 'base64' => self::proofImage()]), 'APPROVAL_NOT_VISIBLE', [403]);
        $with = $this->expectOk($attach('proc', ['fileName' => 'إيصال التحويل/1.png', 'mime' => 'image/png', 'base64' => self::proofImage()]));
        $file = $with['attachments'][0];
        $this->assertSame(['إيصال_التحويل_1.png', 'image/png', strlen($png), true, false, true, 'proc', null], [$file['fileName'], $file['mime'], $file['size'], $file['available'], $file['removed'], $file['canRemove'], $file['uploadedBy']['username'], $file['source']]);
        $this->assertArrayNotHasKey('storageKey', $file, 'where a file lives never leaves the server');
        $stored = ApprovalAttachment::findOrFail($file['id']);
        $this->assertSame(strlen($png), (int) StoredFile::where('key', $stored->storage_key)->value('size'), 'kept by the file store of the system');

        // the bytes come back to whoever sees the request, and to nobody else — not even through the generic file route
        $get = fn (string $user, string $id) => $this->get(self::API."/requests/{$r['number']}/attachments/{$id}", ['Authorization' => 'Bearer '.$this->token($user), 'Accept' => 'application/json']);
        $bytes = $get('proc', $file['id']);
        $bytes->assertOk();
        $this->assertSame([$png, 'image/png', 'nosniff'], [$bytes->getContent(), $bytes->headers->get('Content-Type'), $bytes->headers->get('X-Content-Type-Options')]);
        $this->assertStringStartsWith('inline', (string) $bytes->headers->get('Content-Disposition'));
        $this->expectRejected($get('sales', $file['id']), 'APPROVAL_NOT_VISIBLE', [403]);
        $this->expectRejected($this->getAs('sales', '/api/integrations/files/'.implode('/', array_map('rawurlencode', explode('/', $stored->storage_key)))), 'FILE_NOT_FOUND', [404]);
        $this->expectRejected($get('proc', '01zzzzzzzzzzzzzzzzzzzzzzzz'), 'APPROVAL_ATTACHMENT_NOT_FOUND', [404]);

        // sent: the approver reads it, comments, and adds a file of his own; the requester hears of the comment
        $sent = $this->act('proc', $r, 'submit');
        $this->assertSame('pending', $sent['status']);
        $this->assertSame($png, $get('finance', $file['id'])->getContent());
        $this->expectRejected($this->postAs('sales', self::API."/requests/{$r['number']}/comments", ['text' => 'ملاحظة']), 'APPROVAL_NOT_VISIBLE', [403]);
        $this->expectRejected($this->postAs('finance', self::API."/requests/{$r['number']}/comments", ['text' => '   ']), 'INVALID_INPUT', [400]);
        $commented = $this->act('finance', $r, 'comments', ['text' => 'أرفق كشف الحساب من فضلك']);
        $last = end($commented['timeline']);
        $this->assertSame(['comment', 'أرفق كشف الحساب من فضلك', 'finance'], [$last['kind'], $last['note'], $last['by']['username']]);
        $toldProc = $this->told('proc', $r);
        $this->assertStringContainsString('أرفق كشف الحساب', end($toldProc));
        $this->act('proc', $r, 'comments', ['text' => 'تم']);
        $toldFinance = $this->told('finance', $r);
        $this->assertStringContainsString('تعليق من', end($toldFinance));
        $pdf = "%PDF-1.4\n% كشف حساب\n";
        $two = $this->expectOk($attach('finance', ['fileName' => 'statement.pdf', 'mime' => 'application/pdf', 'base64' => 'data:application/pdf;base64,'.base64_encode($pdf)]));
        $second = $two['attachments'][1];
        $this->assertSame($pdf, $get('proc', $second['id'])->getContent());
        $zip = $this->expectOk($attach('proc', ['fileName' => 'data.html', 'mime' => 'text/html', 'base64' => base64_encode('<script>1</script>')]))['attachments'][2];
        $served = $get('finance', $zip['id']);
        $this->assertSame('application/octet-stream', $served->headers->get('Content-Type'), 'only pictures and PDF are shown in place; anything else is a download');
        $this->assertStringStartsWith('attachment', (string) $served->headers->get('Content-Disposition'));

        // after sending, a file is evidence: only the manager takes one off, with what was removed kept on record
        $this->expectRejected($this->postAs('proc', self::API."/requests/{$r['number']}/attachments/{$file['id']}/remove"), 'APPROVAL_NOT_DRAFT', [422]);
        $this->expectRejected($this->postAs('proc', self::API."/requests/{$r['number']}/attachments/{$second['id']}/remove"), 'APPROVAL_ATTACHMENT_NOT_YOURS', [403]);
        $removed = $this->expectOk($this->postAs('admin', self::API."/requests/{$r['number']}/attachments/{$file['id']}/remove", ['reason' => 'ملف خاطئ']));
        $this->assertSame([true, 'ملف خاطئ', false], [$removed['attachments'][0]['removed'], $removed['attachments'][0]['removeReason'], $removed['attachments'][0]['canRemove']]);
        $this->expectRejected($this->postAs('admin', self::API."/requests/{$r['number']}/attachments/{$file['id']}/remove"), 'APPROVAL_ATTACHMENT_REMOVED', [422]);
        $this->expectRejected($get('proc', $file['id']), 'APPROVAL_ATTACHMENT_REMOVED', [404]);
        $this->assertSame($png, $get('admin', $file['id'])->getContent(), 'the file itself is never deleted');
        $this->expectOk($this->postAs('admin', self::API."/requests/{$r['number']}/attachments/{$zip['id']}/remove"));
        // the category requires a file and the request waits: its last file stays until another one replaces it
        $this->expectRejected($this->postAs('admin', self::API."/requests/{$r['number']}/attachments/{$second['id']}/remove"), 'APPROVAL_DOCUMENT_REQUIRED', [422]);
        $kinds = array_column($this->expectOk($this->getAs('proc', self::API."/requests/{$r['number']}"))['timeline'], 'kind');
        $this->assertSame(['created', 'attached', 'submitted', 'comment', 'comment', 'attached', 'attached', 'detached', 'detached'], $kinds);
        $this->assertSame(3, AuditLog::where('entity_id', $r['id'])->where('action', 'APPROVAL.ATTACH')->count());
        $this->assertSame(2, AuditLog::where('entity_id', $r['id'])->where('action', 'APPROVAL.DETACH')->count());

        // on a draft the one who added a file removes it himself
        $d = $this->raise('proc', ['category' => $c['code'], 'title' => "مسودة بمرفق {$tag}"]);
        $own = $this->expectOk($this->postAs('proc', self::API."/requests/{$d['number']}/attachments", ['fileName' => 'a.png', 'mime' => 'image/png', 'base64' => self::proofImage()]))['attachments'][0];
        $this->assertTrue($this->expectOk($this->postAs('proc', self::API."/requests/{$d['number']}/attachments/{$own['id']}/remove"))['attachments'][0]['removed']);
    }

    public function test_the_lists_search_filter_sort_and_page_and_the_summary_counts(): void
    {
        $tag = 'LST'.self::uid();
        $c = $this->category(['fields' => ['amount' => 'optional', 'reference' => 'optional', 'partner' => 'optional']]);
        $other = $this->category();
        $before = $this->expectOk($this->getAs('proc', self::API.'/summary'));
        $a = $this->raise('proc', ['category' => $c['code'], 'title' => "{$tag} أ", 'amount' => 100, 'reference' => "REF-{$tag}"]);
        $b = $this->raise('proc', ['category' => $c['code'], 'title' => "{$tag} ب", 'amount' => 900, 'submit' => true, 'partnerType' => 'other', 'partnerName' => "مورد {$tag}"]);
        $d = $this->raise('proc', ['category' => $other['code'], 'title' => "{$tag} ج", 'submit' => true]);
        $this->act('finance', $d, 'approve');
        $e = $this->raise('proc', ['category' => $c['code'], 'title' => "{$tag} د", 'amount' => 5000, 'submit' => true]);
        $foreign = $this->raise('sales', ['category' => $c['code'], 'title' => "{$tag} مبيعات", 'amount' => 70, 'submit' => true]);

        $after = $this->expectOk($this->getAs('proc', self::API.'/summary'));
        $this->assertSame([1, 2, 1, 4], [$after['mine']['draft'] - $before['mine']['draft'], $after['mine']['pending'] - $before['mine']['pending'], $after['mine']['approved'] - $before['mine']['approved'], $after['mine']['total'] - $before['mine']['total']]);
        $this->assertSame([true, false, null], [$after['canRequest'], $after['canManage'], $after['all']]);
        $manager = $this->expectOk($this->getAs('admin', self::API.'/summary'));
        $this->assertTrue($manager['canManage']);
        $this->assertGreaterThanOrEqual(5, $manager['all']['total']);

        $numbers = fn (string $user, string $query) => array_column($this->expectOk($this->getAs($user, self::API."/requests?{$query}"))['items'], 'number');
        $mine = "scope=mine&q={$tag}";
        $this->assertEqualsCanonicalizing([$a['number'], $b['number'], $d['number'], $e['number']], $numbers('proc', $mine));
        $this->assertSame([$a['number']], $numbers('proc', "{$mine}&status=draft"));
        $this->assertEqualsCanonicalizing([$b['number'], $d['number'], $e['number']], $numbers('proc', "{$mine}&status=pending,approved"));
        $this->assertEqualsCanonicalizing([$a['number'], $b['number'], $e['number']], $numbers('proc', "{$mine}&category={$c['code']}"));
        $this->assertSame([$d['number']], $numbers('proc', "{$mine}&category={$other['id']}"));
        $this->assertEqualsCanonicalizing([$b['number'], $e['number']], $numbers('proc', "{$mine}&amountMin=500"));
        $this->assertSame([$a['number']], $numbers('proc', "{$mine}&amountMax=500"));
        $this->assertSame([$a['number']], $numbers('proc', "scope=mine&q=REF-{$tag}"), 'the search reads the reference');
        $this->assertSame([$b['number']], $numbers('proc', 'scope=mine&q='.rawurlencode("مورد {$tag}")), '…and the partner');
        $this->assertSame([$e['number']], $numbers('proc', "scope=mine&q={$e['number']}"), '…and the number');
        $this->assertSame([], $numbers('proc', "{$mine}&source=odoo"));
        $this->assertCount(4, $numbers('proc', "{$mine}&source=ops"));
        // dates: the suite runs on a clock anchored to 2026-09-18
        $this->assertCount(4, $numbers('proc', "{$mine}&from=2026-09-18&to=2026-09-18"));
        $this->assertSame([], $numbers('proc', "{$mine}&from=2026-09-19"));
        $this->assertSame([], $numbers('proc', "{$mine}&to=2026-09-17"));
        // sort: a column and its direction; rows without the value come last either way
        $this->assertSame([$a['number'], $b['number'], $e['number'], $d['number']], $numbers('proc', "{$mine}&sort=amount&order=asc"));
        $this->assertSame([$e['number'], $b['number'], $a['number'], $d['number']], $numbers('proc', "{$mine}&sort=amount&order=desc"));
        $this->assertSame([$e['number'], $d['number'], $b['number'], $a['number']], $numbers('proc', $mine), 'newest first unless asked otherwise');
        // paging
        $page2 = $this->expectOk($this->getAs('proc', self::API."/requests?{$mine}&sort=number&order=asc&pageSize=3&page=2"));
        $this->assertSame([4, 2, 2, 3, [$e['number']]], [$page2['total'], $page2['pages'], $page2['page'], $page2['pageSize'], array_column($page2['items'], 'number')]);

        // the scopes: mine · waiting for me (the oldest first) · the ones I am an approver of · all (the manager's)
        $this->assertNotContains($foreign['number'], $numbers('proc', 'scope=mine&pageSize=200'));
        $waiting = $numbers('finance', "scope=waiting&q={$tag}");
        $this->assertSame([$b['number'], $e['number'], $foreign['number']], $waiting);
        $this->assertEqualsCanonicalizing([$b['number'], $d['number'], $e['number'], $foreign['number']], $numbers('finance', "scope=involved&q={$tag}"));
        $this->expectRejected($this->getAs('proc', self::API.'/requests?scope=all'), 'APPROVAL_ALL_FORBIDDEN', [403]);
        $this->assertCount(5, $numbers('admin', "scope=all&q={$tag}"));
        $this->assertSame([$foreign['number']], $numbers('admin', "scope=all&q={$tag}&requester=".rawurlencode((string) User::where('username', 'sales')->value('name_ar'))));
        $this->expectRejected($this->getAs('proc', self::API.'/requests?scope=everything'), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->getAs('proc', self::API.'/requests?from=yesterday'), 'INVALID_INPUT', [400]);
        // a row tells the list what it needs without opening the request
        $row = collect($this->expectOk($this->getAs('finance', self::API."/requests?scope=waiting&q={$tag}"))['items'])->firstWhere('number', $b['number']);
        $this->assertSame(['proc', '900.00', "مورد {$tag}", ['approved' => 0, 'needed' => 1, 'total' => 1], true, false], [$row['requester']['username'], $row['amount'], $row['partner']['name'], $row['progress'], $row['my']['turn'], $row['owned']]);

        // the pickers of the form
        $supplier = Supplier::where('active', true)->orderBy('code')->first();
        $found = $this->expectOk($this->getAs('proc', self::API.'/partners?type=supplier&q='.rawurlencode($supplier->code)))['items'];
        $this->assertContains($supplier->code, array_column($found, 'code'));
        $this->expectRejected($this->getAs('proc', self::API.'/partners?type=everyone'), 'INVALID_INPUT', [400]);
        $withPartner = $this->raise('proc', ['category' => $c['code'], 'partnerType' => 'supplier', 'partner' => $supplier->code]);
        $this->assertSame(['supplier', $supplier->id, $supplier->code, $supplier->name_ar], [$withPartner['partner']['type'], $withPartner['partner']['id'], $withPartner['partner']['code'], $withPartner['partner']['name']]);
        $product = Product::where('active', true)->orderBy('sku')->first();
        $this->assertContains($product->sku, array_column($this->expectOk($this->getAs('proc', self::API.'/products?q='.rawurlencode($product->sku)))['items'], 'sku'));
    }
}
