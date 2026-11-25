<?php

namespace Tests\Feature\Credit;

use App\Credit\Models\CreditApproval;
use App\Credit\Models\CreditEvent;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\ApiTestCase;

/**
 * The workflow of a credit request, end to end: raised, filled in, sent, decided stage by stage (any / all), the
 * approved limit and term written on the customer ONCE through the customer service with the previous values kept,
 * revoked and put back — and every illegal move refused with its code, nothing changed.
 */
class CreditWorkflowTest extends ApiTestCase
{
    use CreditFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->followOdoo(false);
        $this->plainPolicies();
    }

    public function test_happy_path_reaches_the_customer_once_through_the_customer_service(): void
    {
        $stages = $this->twoStagePath();
        $c = $this->customer();
        $r = $this->newRequest($c);
        $this->assertSame('draft', $r['state']);
        $this->assertMatchesRegularExpression('/^CRA-\d{4}-\d{5}$/', $r['number']);
        $this->assertTrue($r['can']['submit']);
        $this->assertSame('••••••5678', $r['ownerNationalId']['masked']);

        // sent: the first stage, its line asks the finance role, its deadline counted
        $r = $this->expectOk($this->postAs('sales', "/api/credit/requests/{$r['id']}/submit"));
        $this->assertSame('in_review', $r['state']);
        $this->assertSame($stages['review'], $r['stage']['id']);
        $this->assertSame(1, $r['round']);
        $line = CreditApproval::where('request_id', $r['id'])->sole();
        $this->assertSame(['pending', 'finance'], [$line->state, $line->role_key]);

        // finance is waiting; gm is not (not his role), sales is not (his own request)
        $this->assertContains($r['number'], array_column($this->expectOk($this->getAs('finance', '/api/credit/requests?scope=waiting&pageSize=200'))['items'], 'number'));
        $this->assertNotContains($r['number'], array_column($this->expectOk($this->getAs('gm', '/api/credit/requests?scope=waiting&pageSize=200'))['items'], 'number'));
        $this->expectRejected($this->postAs('gm', "/api/credit/requests/{$r['id']}/approve"), 'CREDIT_NOT_APPROVER', [403]);
        $this->expectRejected($this->postAs('sales', "/api/credit/requests/{$r['id']}/approve"), 'CREDIT_SELF_APPROVAL', [422]);

        // finance writes what is granted and approves: on to the final stage
        $r = $this->expectOk($this->postAs('finance', "/api/credit/requests/{$r['id']}/approve", ['note' => 'الملاءة جيدة', 'approvedCreditLimit' => 40000, 'approvedPaymentDays' => 45, 'financeRecommendation' => 'يوصى بـ 40 ألف']));
        $this->assertSame('ceo_approval', $r['state']);
        $this->assertSame('40000.00', $r['approvedCreditLimit']);
        // finance holds only credit.review: the final approval is not his
        $this->expectRejected($this->postAs('finance', "/api/credit/requests/{$r['id']}/approve"), null, [403]);

        $before = Customer::find($c->id);
        $r = $this->expectOk($this->postAs('gm', "/api/credit/requests/{$r['id']}/approve", ['note' => 'معتمد']));
        $this->assertSame('approved', $r['state']);
        $this->assertSame('applied', $r['applied']['status']);
        $after = Customer::find($c->id);
        $this->assertSame('40000.00', number_format((float) $after->credit_limit, 2, '.', ''));
        $this->assertSame('آجل 45', $after->terms);
        $this->assertEquals(['from' => '0.00', 'to' => '40000.00'], $r['applied']['changes']['creditLimit']);
        $this->assertEquals(['from' => $before->terms, 'to' => 'آجل 45'], $r['applied']['changes']['terms']);
        // the customer service wrote its own audit line, in the approver's name
        $this->assertTrue(DB::table('audit_logs')->where('action', 'CUSTOMER.UPDATE')->where('entity_id', $c->id)->where('username', 'gm')->exists());
        // once
        $this->expectRejected($this->postAs('gm', "/api/credit/requests/{$r['id']}/apply"), 'CREDIT_ALREADY_APPLIED', [422]);

        // the audit: one decided line per stage, never edited
        $lines = CreditApproval::where('request_id', $r['id'])->orderBy('created_at')->orderBy('id')->get();
        $this->assertSame(['approved', 'approved'], $lines->pluck('state')->all());
        $this->expectException(LogicException::class);
        $lines[0]->forceFill(['note' => 'تعديل'])->save();
    }

    public function test_revoking_leaves_the_customer_and_restoring_is_explicit(): void
    {
        $this->twoStagePath();
        $c = $this->customer(['credit_limit' => 5000, 'terms' => 'آجل 15']);
        $r = $this->approved($c);
        $this->assertSame('5000.00', $r['applied']['changes']['creditLimit']['from']);

        $this->expectRejected($this->postAs('gm', "/api/credit/requests/{$r['id']}/cancel", ['reason' => 'إلغاء']), 'CREDIT_REVOKE_PERMISSION', [403]);
        $this->expectRejected($this->postAs('admin', "/api/credit/requests/{$r['id']}/cancel"), 'CREDIT_REASON_REQUIRED', [400]);
        $out = $this->expectOk($this->postAs('admin', "/api/credit/requests/{$r['id']}/cancel", ['reason' => 'تعثر العميل في السداد']));
        $this->assertTrue($out['revoked']);
        $this->assertSame('cancelled', $out['state']);
        $this->assertSame('40000.00', number_format((float) Customer::find($c->id)->credit_limit, 2, '.', ''), 'revoking never takes the limit back by itself');
        $this->assertTrue($out['can']['restore']);
        $this->expectRejected($this->postAs('admin', "/api/credit/requests/{$r['id']}/reopen"), 'CREDIT_REOPEN_APPROVED', [422]);

        $out = $this->expectOk($this->postAs('admin', "/api/credit/requests/{$r['id']}/restore-customer", ['reason' => 'إعادة الحد السابق']));
        $this->assertSame('restored', $out['applied']['status']);
        $back = Customer::find($c->id);
        $this->assertSame(['5000.00', 'آجل 15'], [number_format((float) $back->credit_limit, 2, '.', ''), $back->terms]);
        $this->expectRejected($this->postAs('admin', "/api/credit/requests/{$r['id']}/restore-customer", ['reason' => 'مرة أخرى']), 'CREDIT_NOTHING_TO_RESTORE', [422]);
    }

    public function test_a_customer_changed_since_is_not_overwritten_by_a_restore(): void
    {
        $this->twoStagePath();
        $c = $this->customer();
        $r = $this->approved($c);
        $this->expectOk($this->postAs('admin', "/api/credit/requests/{$r['id']}/cancel", ['reason' => 'إلغاء الاعتماد']));
        $this->expectOk($this->patchAs('admin', "/api/customers/{$c->id}", ['creditLimit' => 60000]));
        $this->expectRejected($this->postAs('admin', "/api/credit/requests/{$r['id']}/restore-customer", ['reason' => 'إعادة']), 'CREDIT_CUSTOMER_CHANGED_SINCE', [422]);
        $this->assertSame('60000.00', number_format((float) Customer::find($c->id)->credit_limit, 2, '.', ''));
    }

    public function test_the_customer_service_refusal_is_recorded_and_the_approval_stands(): void
    {
        $this->twoStagePath();
        // the customer's commercial data is Odoo's: the customer service refuses, the request says why
        $c = $this->customer(['source_system' => 'odoo']);
        $r = $this->approved($c);
        $this->assertSame('approved', $r['state']);
        $this->assertSame('blocked', $r['applied']['status']);
        $this->assertSame('CUSTOMER_OWNED_BY_SOURCE', $r['applied']['error']['code']);
        $this->assertSame('0.00', number_format((float) Customer::find($c->id)->credit_limit, 2, '.', ''));
        $this->assertTrue(CreditEvent::where('request_id', $r['id'])->where('kind', 'apply_blocked')->exists());
        // the cause is gone (the customer is handed over to OPS): applied once, by a person
        Customer::whereKey($c->id)->update(['source_system' => null]);
        $this->expectRejected($this->postAs('finance', "/api/credit/requests/{$r['id']}/apply"), 'CREDIT_APPLY_PERMISSION', [403]);
        $out = $this->expectOk($this->postAs('gm', "/api/credit/requests/{$r['id']}/apply"));
        $this->assertSame('applied', $out['applied']['status']);
        $this->assertSame('40000.00', number_format((float) Customer::find($c->id)->credit_limit, 2, '.', ''));
    }

    public function test_all_users_stage_needs_every_approver_and_any_user_one(): void
    {
        $role = 'credq'.self::tag();
        $a = $this->user($role, ['page.credit', 'credit.review']);
        $b = $this->user($role, ['page.credit', 'credit.review']);
        $this->path([['board', 'in_review', ['responsible_role' => $role, 'approval_mode' => 'all_users']], ['final', 'ceo_approval', ['responsible_role' => 'gm']]]);
        $r = $this->submitted();
        $this->assertSame(2, CreditApproval::where('request_id', $r['id'])->where('state', 'pending')->count());

        $r = $this->expectOk($this->postAs($a, "/api/credit/requests/{$r['id']}/approve"));
        $this->assertSame('in_review', $r['state'], 'one of two approvals does not decide an all-users stage');
        $this->expectRejected($this->postAs($a, "/api/credit/requests/{$r['id']}/approve"), 'CREDIT_ALREADY_DECIDED', [422]);
        $r = $this->expectOk($this->postAs($b, "/api/credit/requests/{$r['id']}/approve"));
        $this->assertSame('ceo_approval', $r['state']);

        // any user: one approval decides, and nobody else is asked any more
        $this->path([['open', 'in_review', ['responsible_role' => $role]], ['final', 'ceo_approval', ['responsible_role' => 'gm']]]);
        $r2 = $this->submitted();
        $this->assertSame(1, CreditApproval::where('request_id', $r2['id'])->count());
        $r2 = $this->expectOk($this->postAs($b, "/api/credit/requests/{$r2['id']}/approve"));
        $this->assertSame('ceo_approval', $r2['state']);
    }

    public function test_reject_changes_withdraw_reopen_and_illegal_moves(): void
    {
        $this->twoStagePath();
        $r = $this->newRequest();
        $id = $r['id'];
        // illegal from a draft: decide, withdraw, apply, reopen
        $this->expectRejected($this->postAs('finance', "/api/credit/requests/{$id}/approve"), 'CREDIT_NOT_PENDING', [422]);
        $this->expectRejected($this->postAs('sales', "/api/credit/requests/{$id}/withdraw"), 'CREDIT_TRANSITION', [422]);
        $this->expectRejected($this->postAs('gm', "/api/credit/requests/{$id}/apply"), 'CREDIT_NOT_APPROVED', [422]);
        $this->expectRejected($this->postAs('sales', "/api/credit/requests/{$id}/reopen"), 'CREDIT_TRANSITION', [422]);
        $this->expectRejected($this->postAs('finance', "/api/credit/requests/{$id}/submit"), 'CREDIT_NOT_YOURS', [403]);

        $this->expectOk($this->postAs('sales', "/api/credit/requests/{$id}/submit"));
        $this->expectRejected($this->postAs('sales', "/api/credit/requests/{$id}/submit"), 'CREDIT_TRANSITION', [422]);
        $this->expectRejected($this->postAs('finance', "/api/credit/requests/{$id}/request-changes", ['reason' => 'x']), 'CREDIT_REASON_REQUIRED', [400]);
        $r = $this->expectOk($this->postAs('finance', "/api/credit/requests/{$id}/request-changes", ['reason' => 'أرفق كشف الحساب']));
        $this->assertSame('changes_requested', $r['state']);
        $this->assertSame('أرفق كشف الحساب', $r['changesRequestedNote']);
        $this->assertTrue($this->request($id, 'sales')['can']['edit']);
        $this->assertFalse($r['can']['edit'], 'the reviewer does not edit a request sent back to its requester');
        // sent again: from the first stage, second round, a new line
        $r = $this->expectOk($this->postAs('sales', "/api/credit/requests/{$id}/submit"));
        $this->assertSame([2, 'in_review'], [$r['round'], $r['state']]);
        $this->assertSame(1, CreditApproval::where('request_id', $id)->where('round', 2)->count());

        // withdrawn by its requester only
        $this->expectRejected($this->postAs('finance', "/api/credit/requests/{$id}/withdraw", ['reason' => 'سحب']), 'CREDIT_NOT_YOURS', [403]);
        $r = $this->expectOk($this->postAs('sales', "/api/credit/requests/{$id}/withdraw", ['reason' => 'تصحيح الحد']));
        $this->assertSame('draft', $r['state']);
        $this->assertSame('skipped', CreditApproval::where('request_id', $id)->where('round', 2)->value('state'));

        $this->expectOk($this->postAs('sales', "/api/credit/requests/{$id}/submit"));
        $r = $this->expectOk($this->postAs('finance', "/api/credit/requests/{$id}/reject", ['reason' => 'سجل ائتماني ضعيف']));
        $this->assertSame('rejected', $r['state']);
        $this->assertSame('سجل ائتماني ضعيف', $r['rejectionReason']);
        $this->expectRejected($this->patchAs('sales', "/api/credit/requests/{$id}", ['city' => 'جدة']), 'CREDIT_CLOSED', [422]);
        $this->expectRejected($this->postAs('finance', "/api/credit/requests/{$id}/approve"), 'CREDIT_NOT_PENDING', [422]);
        $r = $this->expectOk($this->postAs('sales', "/api/credit/requests/{$id}/reopen"));
        $this->assertSame('draft', $r['state']);
        $this->expectOk($this->postAs('sales', "/api/credit/requests/{$id}/cancel"));
        $this->assertSame('cancelled', $this->row($id)->state);
    }

    public function test_what_is_needed_before_sending_and_before_granting(): void
    {
        $this->twoStagePath();
        $r = $this->newRequest(null, ['requestedCreditLimit' => null, 'signatories' => []]);
        $res = $this->expectRejected($this->postAs('sales', "/api/credit/requests/{$r['id']}/submit"), 'CREDIT_FIELDS_REQUIRED', [400]);
        $this->assertEqualsCanonicalizing(['requestedCreditLimit', 'signatories'], array_column($res['details'], 'path'));
        $this->assertSame('draft', $this->row($r['id'])->state);

        $r = $this->submitted();
        $this->expectOk($this->postAs('finance', "/api/credit/requests/{$r['id']}/approve"));
        // the final stage grants: nothing written, nothing granted
        $this->expectRejected($this->postAs('gm', "/api/credit/requests/{$r['id']}/approve"), 'CREDIT_GRANT_REQUIRED', [400]);
        $this->assertSame('ceo_approval', $this->row($r['id'])->state);
        $this->assertSame(0, CreditApproval::where('request_id', $r['id'])->where('state', 'approved')->where('decided_by', DB::table('users')->where('username', 'gm')->value('name_ar'))->count());
    }

    public function test_one_open_request_per_customer_and_permission_denials_change_nothing(): void
    {
        $this->twoStagePath();
        $c = $this->customer();
        $this->newRequest($c);
        $this->expectRejected($this->postAs('sales', '/api/credit/requests', $this->body($c)), 'CREDIT_OPEN_REQUEST_EXISTS', [409]);
        $count = DB::table('credit_requests')->where('customer_id', $c->id)->count();
        // finance cannot raise a request; nor configure the section
        $this->expectRejected($this->postAs('finance', '/api/credit/requests', $this->body($this->customer())), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('gm', '/api/credit/stages', ['code' => 'x_stage', 'nameAr' => 'س', 'state' => 'in_review']), 'FORBIDDEN', [403]);
        $this->assertSame($count, DB::table('credit_requests')->where('customer_id', $c->id)->count());
        // a user outside the page sees nothing of it
        $this->expectRejected($this->getAs('wm', '/api/credit/requests'), 'FORBIDDEN', [403]);
    }

    public function test_a_stage_nobody_can_decide_is_not_entered(): void
    {
        $role = 'credempty'.self::tag();
        $this->user($role, ['page.credit']); // the role exists, its only user cannot decide
        $this->path([['nobody', 'in_review', ['responsible_role' => $role]], ['final', 'ceo_approval', ['responsible_role' => 'gm']]]);
        $r = $this->newRequest();
        $this->expectRejected($this->postAs('sales', "/api/credit/requests/{$r['id']}/submit"), 'CREDIT_STAGE_NO_APPROVER', [422]);
        $this->assertSame('draft', $this->row($r['id'])->state);
        $this->assertSame(0, CreditApproval::where('request_id', $r['id'])->count());
    }

    public function test_the_manager_skips_a_stage_but_never_the_final_one(): void
    {
        $this->twoStagePath();
        $r = $this->submitted();
        $this->expectRejected($this->postAs('gm', "/api/credit/requests/{$r['id']}/skip", ['reason' => 'غياب']), 'FORBIDDEN', [403]);
        $r = $this->expectOk($this->postAs('admin', "/api/credit/requests/{$r['id']}/skip", ['reason' => 'المراجع في إجازة']));
        $this->assertSame('ceo_approval', $r['state']);
        $this->expectRejected($this->postAs('admin', "/api/credit/requests/{$r['id']}/skip", ['reason' => 'تخطي']), 'CREDIT_FINAL_NOT_SKIPPABLE', [422]);
    }

    // ───────────── helpers ─────────────

    private function submitted(?Customer $c = null): array
    {
        $r = $this->newRequest($c);

        return $this->expectOk($this->postAs('sales', "/api/credit/requests/{$r['id']}/submit"));
    }

    private function approved(Customer $c): array
    {
        $r = $this->submitted($c);
        $this->expectOk($this->postAs('finance', "/api/credit/requests/{$r['id']}/approve", ['approvedCreditLimit' => 40000, 'approvedPaymentDays' => 30]));

        return $this->expectOk($this->postAs('gm', "/api/credit/requests/{$r['id']}/approve"));
    }
}
