<?php

namespace Tests\Feature\Commissions;

use App\Commissions\Models\CommissionPlan;
use App\Commissions\Models\CommissionStatement;
use App\Models\AuditLog;
use App\Models\User;
use Tests\ApiTestCase;

/**
 * Commission plans: who writes and reads them, what is refused (and about which field), that one plan decides for a
 * person and a month, and that the terms of a plan lock once a statement calculated from it has left the draft state.
 * The plans of this class are valid in 2024 only — no document of any test lives there.
 */
class CommissionPlansTest extends ApiTestCase
{
    use CommissionFixtures;

    private function plan(string $code): array
    {
        return $this->expectOk($this->getAs('finance', self::API."/plans/{$code}"));
    }

    public function test_a_plan_is_written_by_the_manager_and_read_by_those_who_manage_or_approve(): void
    {
        $a = $this->person('pa');
        $b = $this->person('pb');
        $frozen = $this->category('frozen');
        $key = $this->customer('key');
        $body = $this->planBody(['nameAr' => 'خطة المندوبين 2024', 'nameEn' => 'Salespeople 2024', 'members' => [$a->id, $b->username], 'earnOn' => 'collected', 'base' => 'margin',
            'netOfReturns' => false, 'validFrom' => '2024-01-01', 'validTo' => '2024-06-30', 'notes' => 'تُراجع كل ستة أشهر', 'rules' => [
                ['scope' => 'category', 'scopeIds' => [$frozen->id], 'rateType' => 'flat', 'rate' => 3, 'label' => 'مجمدات'],
                ['scope' => 'customer', 'scopeIds' => [$key->id], 'rateType' => 'flat', 'rate' => 0.5],
                ['scope' => 'all', 'rateType' => 'tiered', 'tierMode' => 'progressive', 'tiers' => [['upTo' => null, 'rate' => 2], ['upTo' => 1000, 'rate' => 1]]],
            ]]);
        // writing a plan is the manager's; the approver and a salesperson do not
        $this->expectRejected($this->postAs('sales', self::API.'/plans', $body), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('gm', self::API.'/plans', $body), 'FORBIDDEN', [403]);
        $res = $this->postAs('finance', self::API.'/plans', $body);
        $res->assertStatus(201);
        $plan = $this->expectOk($res);
        $this->assertStringStartsWith('CPL-', $plan['code']);
        $this->assertSame(['خطة المندوبين 2024', 'Salespeople 2024', 'draft', 'salesperson', null, false, 'collected', 'margin', false, '2024-01-01', '2024-06-30', 'تُراجع كل ستة أشهر', null, false, false, 0, 'finance'],
            [$plan['nameAr'], $plan['nameEn'], $plan['status'], $plan['earnerType'], $plan['beneficiary'], $plan['everyone'], $plan['earnOn'], $plan['base'], $plan['netOfReturns'], $plan['validFrom'],
                $plan['validTo'], $plan['notes'], $plan['sourceSystem'], $plan['owned'], $plan['locked'], $plan['statements'], $plan['createdBy']]);
        $this->assertEqualsCanonicalizing([$a->username, $b->username], array_column($plan['members'], 'username'));
        $this->assertSame([['category', 'flat', 3, null, 'مجمدات'], ['customer', 'flat', 0.5, null, null], ['all', 'tiered', null, 'progressive', null]],
            array_map(fn (array $r) => [$r['scope'], $r['rateType'], $r['rate'], $r['tierMode'], $r['label']], $plan['rules']));
        $this->assertSame([[$frozen->id], [$key->id], []], array_column($plan['rules'], 'scopeIds'));
        $this->assertSame([$frozen->code, $key->code], [$plan['rules'][0]['scopeNames'][0]['code'], $plan['rules'][1]['scopeNames'][0]['code']]);
        $this->assertEquals([['upTo' => 1000, 'rate' => 1], ['upTo' => null, 'rate' => 2]], $plan['rules'][2]['tiers'], 'tiers are kept in ascending order, the open one last');
        $this->assertSame(1, AuditLog::where('action', 'COMMISSION.PLAN.CREATE')->where('entity_number', $plan['code'])->where('username', 'finance')->count());

        // reading: the manager and the approver; a plan holds other people's rates
        $this->assertSame($plan['code'], $this->expectOk($this->getAs('gm', self::API."/plans/{$plan['code']}"))['code']);
        $this->assertSame($plan['code'], $this->expectOk($this->getAs('gm', self::API."/plans/{$plan['id']}"))['code']);
        $this->expectRejected($this->getAs('sales', self::API."/plans/{$plan['code']}"), 'FORBIDDEN', [403]);
        $this->expectRejected($this->getAs('finance', self::API.'/plans/CPL-NOPE'), 'COMMISSION_PLAN_NOT_FOUND', [404]);
        $find = fn (string $query) => array_column($this->expectOk($this->getAs('gm', self::API."/plans?q={$plan['code']}&{$query}"))['items'], 'code');
        $this->assertSame([[$plan['code']], [], [$plan['code']], [], [$plan['code']]], [$find('status=draft'), $find('status=active'), $find('earnerType=salesperson&source=ops'), $find('source=odoo'), $find('status=draft,active')]);
        $this->assertSame(1, $this->expectOk($this->getAs('finance', self::API.'/plans?q='.urlencode('Salespeople 2024')))['total']);
        $lookups = $this->expectOk($this->getAs('finance', self::API.'/lookups'));
        $this->assertContains($a->username, array_column($lookups['users'], 'username'));
        $this->assertContains($frozen->code, array_column($lookups['categories'], 'code'));
        $this->assertContains($plan['code'], array_column($lookups['plans'], 'code'));
        $this->assertSame(['manage' => true, 'approve' => false], $lookups['can']);
        $this->assertNotContains('svc.', array_map(fn (string $u) => substr($u, 0, 4), array_column($lookups['users'], 'username')), 'service accounts never earn');
    }

    public function test_what_cannot_work_is_refused_and_says_which_field(): void
    {
        $a = $this->person('bad');
        $service = User::firstOrCreate(['username' => 'svc.cmtest'], ['password_hash' => 'x', 'name_ar' => 'حساب خدمة', 'name_en' => 'Service account', 'active' => true]);
        $inactive = $this->person('off');
        $inactive->update(['active' => false]);
        $ok = fn (array $over) => $this->planBody($over + ['members' => [$a->id], 'validFrom' => '2024-01-01', 'validTo' => '2024-12-31']);
        $before = CommissionPlan::count();
        $refused = [
            'a manager plan without its beneficiary' => [$ok(['earnerType' => 'manager', 'everyone' => true, 'members' => []]), 'COMMISSION_BENEFICIARY_REQUIRED', 'beneficiary'],
            'a manager plan without a team' => [$ok(['earnerType' => 'manager', 'beneficiary' => $a->id, 'members' => []]), 'COMMISSION_MEMBERS_REQUIRED', 'members'],
            'salespeople, nobody named, not for everyone' => [$ok(['members' => []]), 'COMMISSION_MEMBERS_REQUIRED', 'members'],
            'a member nobody knows' => [$ok(['members' => [$a->id, 'no-such-user']]), 'COMMISSION_USER_UNKNOWN', 'members.1'],
            'a service account as a member' => [$ok(['members' => [$service->username]]), 'COMMISSION_USER_UNKNOWN', 'members.0'],
            'an inactive user as the beneficiary' => [$ok(['earnerType' => 'manager', 'beneficiary' => $inactive->id, 'everyone' => true]), 'COMMISSION_USER_UNKNOWN', 'beneficiary'],
            'it ends before it starts' => [$ok(['validTo' => '2023-12-31']), 'COMMISSION_VALIDITY', 'validTo'],
            'no rate at all' => [$ok(['rules' => []]), 'INVALID_INPUT', 'rules'],
            'a rule after "all sales"' => [$ok(['rules' => [['scope' => 'all', 'rateType' => 'flat', 'rate' => 1], ['scope' => 'customer', 'scopeIds' => ['x'], 'rateType' => 'flat', 'rate' => 2]]]), 'COMMISSION_RULE_UNREACHABLE', 'rules.0.scope'],
            'a category rule without a category' => [$ok(['rules' => [['scope' => 'category', 'rateType' => 'flat', 'rate' => 1]]]), 'COMMISSION_SCOPE_REQUIRED', 'rules.0.scopeIds'],
            'a customer that does not exist' => [$ok(['rules' => [['scope' => 'customer', 'scopeIds' => ['no-such-customer'], 'rateType' => 'flat', 'rate' => 1]]]), 'COMMISSION_SCOPE_UNKNOWN', 'rules.0.scopeIds'],
            'a flat rule without its rate' => [$ok(['rules' => [['scope' => 'all', 'rateType' => 'flat']]]), 'COMMISSION_RATE', 'rules.0.rate'],
            'a rate above 100' => [$ok(['rules' => [['scope' => 'all', 'rateType' => 'flat', 'rate' => 120]]]), 'INVALID_INPUT', 'rules.0.rate'],
            'tiers without an open one' => [$ok(['rules' => [['scope' => 'all', 'rateType' => 'tiered', 'tiers' => [['upTo' => 1000, 'rate' => 1]]]]]), 'COMMISSION_TIERS', 'rules.0.tiers'],
            'a tiered rule without tiers' => [$ok(['rules' => [['scope' => 'all', 'rateType' => 'tiered']]]), 'COMMISSION_TIERS', 'rules.0.tiers'],
            'a base nobody knows' => [$ok(['base' => 'turnover']), 'INVALID_INPUT', 'base'],
            'a date that is not a day' => [$ok(['validFrom' => '2024-13-01']), 'INVALID_INPUT', 'validFrom'],
            'no name' => [$ok(['nameAr' => '']), 'INVALID_INPUT', 'nameAr'],
        ];
        foreach ($refused as $what => [$body, $code, $path]) {
            $error = $this->expectRejected($this->postAs('finance', self::API.'/plans', $body), $code, [400]);
            $this->assertContains($path, array_column((array) $error['details'], 'path'), $what);
        }
        $this->assertSame($before, CommissionPlan::count(), 'a refused plan is not created');
    }

    public function test_one_plan_decides_for_a_person_and_a_month(): void
    {
        $a = $this->person('one');
        $b = $this->person('two');
        $manager = $this->person('boss');
        $first = $this->draftPlan(['members' => [$a->id], 'validFrom' => '2024-01-01', 'validTo' => '2024-06-30']);
        $this->expectRejected($this->postAs('gm', self::API."/plans/{$first['code']}/activate"), 'FORBIDDEN', [403]);
        $active = $this->expectOk($this->postAs('finance', self::API."/plans/{$first['code']}/activate"));
        $this->assertSame(['active', 'finance'], [$active['status'], $active['activatedBy']]);
        $this->expectRejected($this->postAs('finance', self::API."/plans/{$first['code']}/activate"), 'COMMISSION_PLAN_TRANSITION', [422]);

        // a second plan that names the same person in months the first one covers is refused — and says which plan and who
        $second = $this->draftPlan(['members' => [$a->id, $b->id], 'validFrom' => '2024-06-01', 'validTo' => '2024-12-31']);
        $overlap = $this->expectRejected($this->postAs('finance', self::API."/plans/{$second['code']}/activate"), 'COMMISSION_PLAN_OVERLAP', [422]);
        $this->assertSame([$first['code'], [$a->id]], [$overlap['details']['plan'], $overlap['details']['users']]);
        $this->assertSame('draft', $this->plan($second['code'])['status']);
        // … from the day after the first one ends it is fine
        $this->expectOk($this->patchAs('finance', self::API."/plans/{$second['code']}", $this->planBody(['members' => [$a->id, $b->id], 'validFrom' => '2024-07-01', 'validTo' => '2024-12-31'])));
        $this->assertSame('active', $this->expectOk($this->postAs('finance', self::API."/plans/{$second['code']}/activate"))['status']);
        // moving an active plan back onto the other one's months is refused the same way
        $this->expectRejected($this->patchAs('finance', self::API."/plans/{$second['code']}", $this->planBody(['members' => [$a->id, $b->id], 'validFrom' => '2024-06-15', 'validTo' => '2024-12-31'])), 'COMMISSION_PLAN_OVERLAP', [422]);
        $this->assertSame('2024-07-01', $this->plan($second['code'])['validFrom'], 'a refused change is not kept');

        // the manager of a manager plan is an earner too: he cannot be named twice in one month
        $asManager = $this->draftPlan(['earnerType' => 'manager', 'beneficiary' => $a->id, 'everyone' => true, 'validFrom' => '2024-03-01', 'validTo' => '2024-03-31']);
        $this->assertSame([$a->id, true, []], [$asManager['beneficiary']['id'], $asManager['everyone'], $asManager['members']]);
        $this->expectRejected($this->postAs('finance', self::API."/plans/{$asManager['code']}/activate"), 'COMMISSION_PLAN_OVERLAP', [422]);

        // one plan for "every salesperson" at a time; a manager's plan over every invoice beside it is another earner's
        $default = $this->activePlan(['everyone' => true, 'validFrom' => '2024-01-01', 'validTo' => '2024-12-31']);
        $again = $this->draftPlan(['everyone' => true, 'validFrom' => '2024-10-01', 'validTo' => null]);
        $clash = $this->expectRejected($this->postAs('finance', self::API."/plans/{$again['code']}/activate"), 'COMMISSION_PLAN_OVERLAP', [422]);
        $this->assertSame([$default['code'], []], [$clash['details']['plan'], $clash['details']['users']]);
        $this->activePlan(['earnerType' => 'manager', 'beneficiary' => $manager->id, 'everyone' => true, 'validFrom' => '2024-01-01', 'validTo' => '2024-12-31']);

        // archiving ends a plan: it decides no more, and the person is free for another one
        $this->expectRejected($this->postAs('sales', self::API."/plans/{$first['code']}/archive"), 'FORBIDDEN', [403]);
        $archived = $this->expectOk($this->postAs('finance', self::API."/plans/{$first['code']}/archive", ['reason' => 'استُبدلت بخطة المدير']));
        $this->assertSame(['archived', 'finance'], [$archived['status'], $archived['archivedBy']]);
        $this->expectRejected($this->postAs('finance', self::API."/plans/{$first['code']}/archive"), 'COMMISSION_PLAN_TRANSITION', [422]);
        $this->expectRejected($this->patchAs('finance', self::API."/plans/{$first['code']}", $this->planBody(['members' => [$a->id], 'validFrom' => '2024-01-01', 'validTo' => '2024-06-30'])), 'COMMISSION_PLAN_STATE', [422]);
        $this->assertSame('active', $this->expectOk($this->postAs('finance', self::API."/plans/{$asManager['code']}/activate"))['status']);
        // … so the archived one cannot simply come back
        $this->expectRejected($this->postAs('finance', self::API."/plans/{$first['code']}/activate"), 'COMMISSION_PLAN_OVERLAP', [422]);
        $this->assertSame('archived', $this->plan($first['code'])['status']);
        $status = array_column($this->expectOk($this->getAs('finance', self::API.'/plans?pageSize=200'))['items'], 'status', 'code');
        $this->assertSame(['archived', 'active', 'active', 'draft'], [$status[$first['code']], $status[$second['code']], $status[$asManager['code']], $status[$again['code']]]);
    }

    public function test_the_terms_of_a_plan_lock_once_a_statement_has_left_the_draft_state(): void
    {
        $a = $this->person('lock');
        $b = $this->person('lock2');
        $terms = ['members' => [$a->id], 'validFrom' => '2024-01-01', 'validTo' => null, 'nameAr' => 'خطة تُقفل شروطها'];
        $plan = $this->activePlan($terms);
        $rule = $plan['rules'][0]['id'];

        // while nothing is closed everything may change — and a rule that only changes its rate keeps its id (lines point at it)
        $changed = $this->expectOk($this->patchAs('finance', self::API."/plans/{$plan['code']}", $this->planBody(['rules' => [['scope' => 'all', 'rateType' => 'flat', 'rate' => 2.5]]] + $terms)));
        $this->assertEquals([2.5, $rule, false, 'active'], [$changed['rules'][0]['rate'], $changed['rules'][0]['id'], $changed['locked'], $changed['status']]);
        $this->expectRejected($this->patchAs('sales', self::API."/plans/{$plan['code']}", $this->planBody($terms)), 'FORBIDDEN', [403]);

        // a statement of the plan was submitted (written here as the calculation and the submission leave it)
        CommissionStatement::create(['number' => self::code('CMS-T'), 'slot' => "u:{$a->id}:2024-01", 'beneficiary_id' => $a->id, 'period' => '2024-01', 'date_from' => '2024-01-01', 'date_to' => '2024-01-31',
            'plan_id' => $plan['id'], 'earner_type' => 'salesperson', 'status' => 'submitted']);
        $this->assertSame([true, 1, ['submitted' => 1]], [$this->plan($plan['code'])['locked'], $this->plan($plan['code'])['statements'], $this->plan($plan['code'])['statementsByStatus']]);
        $body = fn (array $over) => $this->planBody($over + ['rules' => [['scope' => 'all', 'rateType' => 'flat', 'rate' => 2.5]]] + $terms);
        $locked = $this->expectRejected($this->patchAs('finance', self::API."/plans/{$plan['code']}", $body(['rules' => [['scope' => 'all', 'rateType' => 'flat', 'rate' => 3]]])), 'COMMISSION_PLAN_LOCKED', [422]);
        $this->assertSame(['rules'], $locked['details']['fields']);
        $locked = $this->expectRejected($this->patchAs('finance', self::API."/plans/{$plan['code']}", $body(['earnOn' => 'collected', 'base' => 'gross', 'netOfReturns' => false, 'validFrom' => '2024-02-01'])), 'COMMISSION_PLAN_LOCKED', [422]);
        $this->assertSame(['earnOn', 'base', 'netOfReturns', 'validFrom'], $locked['details']['fields']);
        $this->assertEquals(2.5, $this->plan($plan['code'])['rules'][0]['rate']);
        // its name, its end and its salespeople stay open
        $open = $this->expectOk($this->patchAs('finance', self::API."/plans/{$plan['code']}", $body(['nameAr' => 'خطة أُنهيت', 'validTo' => '2024-12-31', 'members' => [$a->id, $b->id], 'notes' => 'تنتهي آخر السنة'])));
        $this->assertSame(['خطة أُنهيت', '2024-12-31', 2, 'تنتهي آخر السنة', true], [$open['nameAr'], $open['validTo'], count($open['members']), $open['notes'], $open['locked']]);

        // new terms = a copy: a draft OPS owns, starting today, with the same rules and people
        $this->expectRejected($this->postAs('gm', self::API."/plans/{$plan['code']}/duplicate"), 'FORBIDDEN', [403]);
        $res = $this->postAs('finance', self::API."/plans/{$plan['code']}/duplicate");
        $res->assertStatus(201);
        $copy = $this->expectOk($res);
        $this->assertNotSame($plan['code'], $copy['code']);
        $this->assertEquals(['draft', 'خطة أُنهيت — نسخة', now()->toDateString(), null, 2, 2.5, false, 0, null],
            [$copy['status'], $copy['nameAr'], $copy['validFrom'], $copy['validTo'], count($copy['members']), $copy['rules'][0]['rate'], $copy['locked'], $copy['statements'], $copy['sourceSystem']]);
        $this->assertNotSame($rule, $copy['rules'][0]['id']);
        $this->assertSame(['COMMISSION.PLAN.CREATE', 'STATUS', 'COMMISSION.PLAN.UPDATE', 'COMMISSION.PLAN.UPDATE'],
            AuditLog::where('entity_type', 'CommissionPlan')->where('entity_id', $plan['id'])->orderBy('at')->orderBy('id')->pluck('action')->all());
        $this->assertSame(1, AuditLog::where('action', 'COMMISSION.PLAN.DUPLICATE')->where('entity_number', $copy['code'])->count());
    }
}
