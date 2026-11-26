<?php

namespace Tests\Feature\Payroll;

use App\Payroll\Models\PayAccount;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/**
 * What pay and working time are made of — every figure configuration with a date, every change by `payroll.manage`,
 * every wrong value refused with its code — and the skills and résumé of a person, kept by that person and by payroll.
 */
class PayrollSetupTest extends ApiTestCase
{
    use PayrollFixtures;

    public function test_configuration_is_kept_by_payroll_and_checked(): void
    {
        $tag = preg_replace('/\D/', '', self::tag());
        // reading is open to the payroll people, changing only to payroll.manage
        $this->expectOk($this->getAs('gm', '/api/payroll/components'));
        $this->assertSame(403, $this->postAs('gm', '/api/payroll/components', ['code' => "X{$tag}", 'nameAr' => 'بند', 'category' => 'allowance'])->getStatusCode());
        $this->assertSame(403, $this->getAs('sales', '/api/payroll/components')->getStatusCode());

        // components: what a person adds is an input of a month; the engine's own stay what they are
        $c = $this->expectOk($this->postAs('admin', '/api/payroll/components', ['code' => "TB{$tag}", 'nameAr' => 'بدل اختبار', 'category' => 'allowance']));
        $this->assertSame(['input', 'allowance', 'allowances', false], [$c['calc'], $c['category'], $c['accountKey'], $c['system']]);
        $this->expectRejected($this->postAs('admin', '/api/payroll/components', ['code' => "TB{$tag}", 'nameAr' => 'مكرر', 'category' => 'allowance']), 'COMPONENT_EXISTS', [409]);
        $this->expectRejected($this->patchAs('admin', "/api/payroll/components/{$c['id']}", ['gosiBase' => true]), 'COMPONENT_GOSI_BASE', [422]);
        $basic = DB::table('pay_components')->where('code', 'BASIC')->value('id');
        $this->expectRejected($this->patchAs('admin', "/api/payroll/components/{$basic}", ['active' => false]), 'COMPONENT_REQUIRED', [422]);

        // social-insurance rates: a new dated row, never one behind approved pay; a row nobody was paid under may be corrected or taken out
        $scheme = 't'.substr($tag, -8);
        $r = $this->expectOk($this->postAs('admin', '/api/payroll/gosi-rates', ['scheme' => $scheme, 'effectiveFrom' => '2027-01-01', 'employeePct' => 10, 'employerPct' => 12, 'baseMin' => 400, 'baseMax' => 45000]));
        $this->expectRejected($this->postAs('admin', '/api/payroll/gosi-rates', ['scheme' => $scheme, 'effectiveFrom' => '2027-01-01', 'employeePct' => 1, 'employerPct' => 1]), 'RATE_EXISTS', [409]);
        $this->expectRejected($this->postAs('admin', '/api/payroll/gosi-rates', ['scheme' => $scheme, 'effectiveFrom' => '2027-02-01', 'employeePct' => 1, 'employerPct' => 1, 'baseMin' => 500, 'baseMax' => 100]), 'GOSI_RATE', [400]);
        $this->assertSame(9.5, $this->expectOk($this->patchAs('admin', "/api/payroll/gosi-rates/{$r['id']}", ['employeePct' => 9.5]))['employeePct']);
        $this->expectOk($this->deleteAs('admin', "/api/payroll/gosi-rates/{$r['id']}"));
        $this->expectRejected($this->postAs('admin', '/api/payroll/structures', ['code' => "S{$tag}", 'nameAr' => 'هيكل', 'gosiScheme' => $scheme]), 'GOSI_SCHEME', [400]);

        // parameters: only what the engine reads, of the kind it reads
        $this->expectRejected($this->postAs('admin', '/api/payroll/parameters', ['code' => 'anything', 'value' => '1', 'effectiveFrom' => '2027-01-01']), 'PARAMETER_UNKNOWN', [400]);
        $this->expectRejected($this->postAs('admin', '/api/payroll/parameters', ['code' => 'unpaid_base', 'value' => 'net', 'effectiveFrom' => '2027-01-01']), 'PARAMETER_VALUE', [400]);
        $items = $this->expectOk($this->postAs('admin', '/api/payroll/parameters', ['code' => 'month_days', 'value' => '30', 'effectiveFrom' => '2027-03-01', 'note' => 'تأكيد']))['items'];
        $row = array_values(array_filter(array_values(array_filter($items, fn ($p) => $p['code'] === 'month_days'))[0]['history'], fn ($h) => $h['effectiveFrom'] === '2027-03-01'))[0];
        $this->assertFalse($row['current'], 'a value from a date to come is not the current one yet');
        $this->expectOk($this->deleteAs('admin', "/api/payroll/parameters/{$row['id']}"));

        // the posting map: the right side of the books, never a control account
        $payable = PayAccount::find('payable')->account_code;
        $this->expectRejected($this->putAs('admin', '/api/payroll/accounts', ['accounts' => ['salaries' => $payable]]), 'ACCOUNT_SIDE', [422]);
        $this->expectRejected($this->putAs('admin', '/api/payroll/accounts', ['accounts' => ['nonsense' => $payable]]), 'ACCOUNT_KEY', [400]);
        $this->expectRejected($this->putAs('admin', '/api/payroll/accounts', ['accounts' => ['payable' => '000000']]), 'ACCOUNT_NOT_FOUND', [404]);
        $map = $this->expectOk($this->getAs('gm', '/api/payroll/accounts'))['items'];
        $this->assertTrue(array_values(array_filter($map, fn ($m) => $m['key'] === 'payable'))[0]['ready']);

        // schedules and policies: refused when they do not make sense
        $this->expectRejected($this->postAs('admin', '/api/payroll/schedules', ['nameAr' => 'دوام متقاطع', 'days' => [['weekday' => 6, 'from' => '08:00', 'to' => '12:00'], ['weekday' => 6, 'from' => '11:00', 'to' => '15:00']]]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('admin', '/api/payroll/schedules', ['nameAr' => 'منطقة خاطئة', 'timezone' => 'Mars/Base']), 'SCHEDULE_TIMEZONE', [400]);
        $this->expectRejected($this->postAs('admin', '/api/payroll/policies', ['nameAr' => 'إضافي مكرر', 'overtime' => [['dayType' => 'workday', 'rate' => 1.5], ['dayType' => 'workday', 'rate' => 2]]]), 'POLICY_RULE', [400]);
        $this->expectRejected($this->postAs('admin', '/api/payroll/leave-types', ['code' => 'bad code!', 'nameAr' => 'نوع']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->putAs('admin', '/api/payroll/profiles/'.$this->newEmployee()['id'], ['structureId' => '01NOTASTRUCTURE00000000000']), 'PROFILE_REFERENCE', [404]);

        // nothing above wrote a figure into the trail that is not configuration — and every change is in it
        $this->assertTrue(DB::table('audit_logs')->where('action', 'PAYCFG.COMPONENT.CREATE')->where('entity_number', "TB{$tag}")->exists());
        $this->assertTrue(DB::table('audit_logs')->where('action', 'PAYCFG.GOSI.REMOVE')->where('entity_id', $r['id'])->exists());
    }

    public function test_skills_and_resume_belong_to_the_person_and_to_payroll(): void
    {
        $w = $this->payrollWorld();
        $meUser = $this->userWith('psm'.self::tag(), ['leave.request']);
        $otherUser = $this->userWith('pso'.self::tag(), ['leave.request']);
        $me = $this->staff($w, ['userId' => $meUser]);
        $other = $this->staff($w, ['userId' => $otherUser]);
        $tag = self::tag();
        $catalog = $this->expectOk($this->postAs('admin', '/api/payroll/skills/types', ['nameAr' => "لغات {$tag}"]));
        $type = array_values(array_filter($catalog['types'], fn ($t) => $t['nameAr'] === "لغات {$tag}"))[0];
        $this->assertSame(403, $this->postAs($meUser, '/api/payroll/skills/types', ['nameAr' => 'نوع من موظف'])->getStatusCode());
        $catalog = $this->expectOk($this->postAs('admin', '/api/payroll/skills/skills', ['nameAr' => "العربية {$tag}", 'typeId' => $type['id']]));
        $catalog = $this->expectOk($this->postAs('admin', '/api/payroll/skills/levels', ['nameAr' => "متقدم {$tag}", 'typeId' => $type['id'], 'progress' => 80, 'isDefault' => true]));
        $type = array_values(array_filter($catalog['types'], fn ($t) => $t['id'] === $type['id']))[0];
        [$skill, $level] = [$type['skills'][0]['id'], $type['levels'][0]['id']];
        $otherType = $this->expectOk($this->postAs('admin', '/api/payroll/skills/types', ['nameAr' => "حاسب {$tag}"]));
        $foreignLevel = $this->expectOk($this->postAs('admin', '/api/payroll/skills/levels', ['nameAr' => "مبتدئ {$tag}", 'typeId' => array_values(array_filter($otherType['types'], fn ($t) => $t['nameAr'] === "حاسب {$tag}"))[0]['id']]));
        $foreign = null;
        foreach ($foreignLevel['types'] as $t) {
            foreach ($t['levels'] as $l) {
                $foreign = $l['nameAr'] === "مبتدئ {$tag}" ? $l['id'] : $foreign;
            }
        }

        // the person keeps his own
        $mine = $this->expectOk($this->postAs($meUser, '/api/payroll/skills/employees/me/skills', ['skillId' => $skill, 'levelId' => $level]));
        $this->assertSame([1, 80, true], [count($mine['skills']), $mine['skills'][0]['level']['progress'], $mine['can']['edit']]);
        $this->expectRejected($this->postAs($meUser, '/api/payroll/skills/employees/me/skills', ['skillId' => $skill, 'levelId' => $foreign]), 'SKILL_LEVEL', [422]);
        $mine = $this->expectOk($this->postAs($meUser, '/api/payroll/skills/employees/me/resume', ['title' => 'أمين مستودع', 'dateStart' => '2020-01-01', 'dateEnd' => '2024-12-31']));
        $line = $mine['resume'][0]['id'];
        $this->expectRejected($this->patchAs($meUser, "/api/payroll/skills/employees/me/resume/{$line}", ['dateEnd' => '2019-01-01']), 'RESUME_DATES', [400]);
        $this->expectOk($this->patchAs($meUser, "/api/payroll/skills/employees/me/resume/{$line}", ['description' => 'استلام وصرف']));

        // nobody else's
        $this->expectRejected($this->getAs($otherUser, "/api/payroll/skills/employees/{$me['id']}"), 'PAYROLL_PRIVATE', [403]);
        $this->expectRejected($this->postAs($otherUser, "/api/payroll/skills/employees/{$me['id']}/resume", ['title' => 'سطر مدسوس']), 'PAYROLL_PRIVATE', [403]);
        $this->assertSame(403, $this->getAs($otherUser, '/api/payroll/skills/employees')->getStatusCode());
        // payroll reads and keeps anyone's; the approver reads but does not keep
        $this->assertSame(1, count($this->expectOk($this->getAs('gm', "/api/payroll/skills/employees/{$me['id']}"))['resume']));
        $this->expectRejected($this->postAs('gm', "/api/payroll/skills/employees/{$me['id']}/resume", ['title' => 'من المعتمِد']), 'PAYROLL_PRIVATE', [403]);
        $list = $this->expectOk($this->getAs('gm', "/api/payroll/skills/employees?department={$w['department']}&skill={$skill}"));
        $this->assertSame([$me['id']], array_map(fn ($r) => $r['employee']['id'], $list['items']));
        $after = $this->expectOk($this->deleteAs('admin', "/api/payroll/skills/employees/{$me['id']}/skills/{$mine['skills'][0]['id']}"));
        $this->assertSame([], $after['skills']);
        $this->assertSame([], $this->expectOk($this->getAs($otherUser, '/api/payroll/skills/employees/me'))['skills']);
        $this->assertNotEmpty($other['id']);
    }
}
