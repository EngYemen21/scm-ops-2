<?php

namespace Tests\Feature\Hr;

use App\Hr\Models\Contract;
use App\Hr\Services\Workforce;
use Tests\ApiTestCase;

/**
 * App\Hr\Services\Workforce — what the modules built on People & expenses (payroll, time off, attendance) read:
 * who is at work and under whom, the contract in force on a day and during a period with what it pays, the
 * reimbursements still owed from posted claims, and where a person's pay is sent. It reads and never writes.
 */
class HrWorkforceTest extends ApiTestCase
{
    use HrFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sealNetwork();
        $this->followOdoo(false);
    }

    private function day(int $offset): string
    {
        return now()->addDays($offset)->toDateString();
    }

    public function test_it_answers_who_works_under_which_contract_and_what_is_owed(): void
    {
        $workforce = app(Workforce::class);
        $tag = self::uid();
        $login = $this->userWith('wfuser', ['expense.claim']);
        $department = $this->expectOk($this->postAs('admin', '/api/hr/departments', ['nameAr' => "قسم القوى العاملة {$tag}"]));
        $boss = $this->makeEmployee(['departmentId' => $department['id']]);
        $worker = $this->makeEmployee(['departmentId' => $department['id'], 'managerId' => $boss['id'], 'userId' => $login]);
        $gone = $this->makeEmployee(['departmentId' => $department['id'], 'managerId' => $boss['id']]);
        $this->expectOk($this->patchAs('admin', "/api/hr/employees/{$gone['id']}", ['status' => 'left']));

        // people: at work in a department, the person behind a login, who reports to whom
        $this->assertEqualsCanonicalizing([$boss['id'], $worker['id']], $workforce->active($department['id'])->pluck('id')->all());
        $this->assertSame($worker['id'], $workforce->ofUser($this->userId($login))->id);
        $this->assertNull($workforce->ofUser($this->userId('worker')));
        $this->assertSame([$worker['id']], $workforce->reportsOf($boss['id'])->pluck('id')->all(), 'someone who left reports to nobody');
        $this->assertSame($worker['code'], $workforce->employee($worker['code'])->code);

        // contracts: nothing is in force before one is put in force
        $first = $this->expectOk($this->postAs('admin', '/api/hr/contracts', ['employee' => $worker['id'], 'startDate' => $this->day(-100), 'endDate' => $this->day(30), 'wage' => 5000, 'housingAllowance' => 1250.5, 'transportAllowance' => 300]));
        $this->assertNull($workforce->contractOn($worker['id']), 'a draft is not in force');
        $this->expectOk($this->postAs('admin', "/api/hr/contracts/{$first['id']}/start"));
        $this->assertSame($first['id'], $workforce->contractOn($worker['id'])->id);
        $this->assertNull($workforce->contractOn($worker['id'], $this->day(-150)), 'not before it started');
        $this->assertSame(['basic' => 5000.0, 'housing' => 1250.5, 'transport' => 300.0, 'other' => 0.0, 'total' => 6550.5, 'currency' => 'SAR'], Workforce::pay($workforce->contractOn($worker['id'])));

        // it ends today and is renewed from tomorrow: each day has its own contract, a period that spans both has both
        $renewal = $this->expectOk($this->postAs('admin', "/api/hr/contracts/{$first['id']}/renew"));
        $this->assertSame($this->day(31), $renewal['startDate']);
        $this->expectOk($this->postAs('admin', "/api/hr/contracts/{$first['id']}/end", ['date' => $this->day(0)]));
        $this->expectOk($this->patchAs('admin', "/api/hr/contracts/{$renewal['id']}", ['startDate' => $this->day(1), 'wage' => 5600]));
        $this->expectOk($this->postAs('admin', "/api/hr/contracts/{$renewal['id']}/start"));
        $this->assertSame($first['id'], $workforce->contractOn($worker['id'])->id, 'today is still the old contract\'s last day');
        $this->assertSame($renewal['id'], $workforce->contractOn($worker['id'], $this->day(1))->id);
        $this->assertSame($first['id'], $workforce->contractOn($worker['id'], now()->subDays(10))->id, 'a day may be a date object');
        $ofWorker = fn (string $from, string $to) => $workforce->contractsBetween($from, $to)->where('employee_id', $worker['id'])->pluck('id')->values()->all();
        $this->assertSame([$first['id'], $renewal['id']], $ofWorker($this->day(-5), $this->day(20)));
        $this->assertSame([$first['id']], $ofWorker($this->day(-20), $this->day(-1)));
        $this->assertSame([$renewal['id']], $ofWorker($this->day(2), $this->day(40)));
        $this->assertSame($worker['code'], $workforce->contractsBetween($this->day(2), $this->day(40))->firstWhere('id', $renewal['id'])->employee->code);
        // a cancelled contract was never in force
        $mistake = $this->expectOk($this->postAs('admin', '/api/hr/contracts', ['employee' => $boss['id'], 'startDate' => $this->day(-10), 'wage' => 9000]));
        $this->expectOk($this->postAs('admin', "/api/hr/contracts/{$mistake['id']}/start"));
        $this->expectOk($this->postAs('admin', "/api/hr/contracts/{$mistake['id']}/cancel", ['reason' => 'أُدخل بالخطأ']));
        $this->assertNull($workforce->contractOn($boss['id']));

        // where the pay is sent — from the personal file
        $this->assertSame(['bankName' => null, 'bankAccount' => null], $workforce->bankOf($worker['id']));
        $this->expectOk($this->putAs('admin', "/api/hr/employees/{$worker['id']}/private", ['bankName' => 'بنك الاختبار', 'bankAccount' => 'SA00 0000 0000 0000 0000 0042']));
        $this->assertSame(['bankName' => 'بنك الاختبار', 'bankAccount' => 'SA0000000000000000000042'], $workforce->bankOf($worker['id']));

        // reimbursements owed: a claim counts from the moment it is posted (accrued) until it is paid
        $claim = $this->expectOk($this->postAs($login, '/api/hr/claims', ['title' => 'مصروفات مهمة عمل', 'lines' => [['date' => $this->day(-2), 'category' => 'TRAVEL', 'description' => 'تنقلات', 'amount' => 100, 'vatPct' => 15]]]));
        $this->assertSame($worker['code'], $claim['employee']['code'], 'the login\'s claim carries its employee record');
        $this->expectOk($this->postAs($login, "/api/hr/claims/{$claim['id']}/submit"));
        $this->expectOk($this->postAs('gm', "/api/hr/claims/{$claim['id']}/approve"));
        $this->assertCount(0, $workforce->reimbursementsDue($worker['id']), 'approved is not yet in the books');
        $this->expectOk($this->postAs('finance', "/api/hr/claims/{$claim['id']}/post"));
        $due = $workforce->reimbursementsDue($worker['id']);
        $this->assertSame([[$claim['number'], '115.00']], $due->map(fn ($c) => [$c->number, $c->employee_due])->all());
        $this->assertTrue($workforce->reimbursementsDue()->contains('id', $claim['id']));
        $this->expectOk($this->postAs('finance', "/api/hr/claims/{$claim['id']}/pay", ['reference' => 'PAYRUN-TEST-1']));
        $this->assertCount(0, $workforce->reimbursementsDue($worker['id']));

        // reading changed nothing
        $this->assertSame(['running', 'expired', 'cancelled'], [Contract::find($renewal['id'])->state, Contract::find($first['id'])->state, Contract::find($mistake['id'])->state]);
    }
}
