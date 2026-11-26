<?php

namespace Tests\Feature\Hr;

use App\Hr\Models\Department;
use App\Hr\Models\Employee;
use App\Hr\Models\EmployeePrivate;
use App\Models\AuditLog;
use App\Models\Driver;
use Tests\ApiTestCase;

/**
 * The staff directory: who reads it (page.hr), who changes it (hr.manage), the department tree, job positions, the
 * links to an OPS login and a driver record — and that the personal file is NOT part of it: without hr.private the
 * API does not return those fields at all.
 */
class HrDirectoryTest extends ApiTestCase
{
    use HrFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sealNetwork();
        $this->followOdoo(false);
    }

    public function test_reading_needs_page_hr_and_writing_needs_hr_manage(): void
    {
        $before = Employee::count();
        // who files expense claims (sales, the warehouse, purchasing…) opens the page, so he reads the staff directory —
        // its work part; a worker has nothing in this section
        $this->expectOk($this->getAs('sales', '/api/hr/employees'));
        $this->expectRejected($this->getAs('sales', '/api/hr/contracts'), 'FORBIDDEN', [403]);
        $this->expectRejected($this->getAs('worker', '/api/hr/employees'), 'FORBIDDEN', [403]);
        $this->expectRejected($this->getAs('worker', '/api/hr/summary'), 'FORBIDDEN', [403]);
        $this->expectRejected($this->getAs('worker', '/api/hr/lookups'), 'FORBIDDEN', [403]);
        // the general manager reads the directory and cannot change it
        $this->expectOk($this->getAs('gm', '/api/hr/employees'));
        $this->expectRejected($this->postAs('gm', '/api/hr/employees', ['nameAr' => 'لا يُنشأ']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('gm', '/api/hr/departments', ['nameAr' => 'لا يُنشأ']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('gm', '/api/hr/jobs', ['nameAr' => 'لا يُنشأ']), 'FORBIDDEN', [403]);
        $this->assertSame($before, Employee::count());
        $this->assertTrue(AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->where('username', 'gm')->where('entity_id', 'POST /api/hr/employees')->exists(), 'a denied attempt is a security event');
        // validation speaks the API's error contract
        $this->expectRejected($this->postAs('admin', '/api/hr/employees', ['nameAr' => '']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('admin', '/api/hr/employees', ['nameAr' => 'بريد خاطئ', 'workEmail' => 'not-an-email']), 'INVALID_INPUT', [400]);
    }

    public function test_departments_form_a_tree_and_refuse_a_cycle(): void
    {
        $tag = self::uid();
        $root = $this->expectOk($this->postAs('admin', '/api/hr/departments', ['nameAr' => "الإدارة {$tag}", 'nameEn' => "Head office {$tag}"]));
        $child = $this->expectOk($this->postAs('admin', '/api/hr/departments', ['nameAr' => "المبيعات {$tag}", 'parentId' => $root['id']]));
        $leaf = $this->expectOk($this->postAs('admin', '/api/hr/departments', ['nameAr' => "مبيعات الجملة {$tag}", 'parentId' => $child['id']]));
        $this->assertSame([0, 1, 2], [$root['depth'], $child['depth'], $leaf['depth']]);
        $this->assertSame("المبيعات {$tag}", $child['nameEn'], 'the English name falls back to the Arabic one');

        // a department cannot sit under itself or under one of its own sub-departments
        $this->expectRejected($this->patchAs('admin', "/api/hr/departments/{$root['id']}", ['parentId' => $leaf['id']]), 'DEPARTMENT_CYCLE', [422]);
        $this->expectRejected($this->patchAs('admin', "/api/hr/departments/{$child['id']}", ['parentId' => $child['id']]), 'DEPARTMENT_CYCLE', [422]);
        $this->expectRejected($this->patchAs('admin', "/api/hr/departments/{$child['id']}", ['parentId' => 'no-such-department']), 'DEPARTMENT_NOT_FOUND', [404]);

        $manager = $this->makeEmployee(['departmentId' => $child['id']]);
        $this->makeEmployee(['departmentId' => $leaf['id'], 'managerId' => $manager['id']]);
        $this->expectOk($this->patchAs('admin', "/api/hr/departments/{$child['id']}", ['managerId' => $manager['id']]));

        $tree = collect($this->expectOk($this->getAs('gm', '/api/hr/departments'))['items'])->keyBy('id');
        $this->assertSame(1, $tree[$child['id']]['headcount']);
        $this->assertSame(2, $tree[$child['id']]['total'], 'the people of the sub-departments count under their parent');
        $this->assertSame(2, $tree[$root['id']]['total']);
        $this->assertSame($manager['code'], $tree[$child['id']]['manager']['code']);

        // a department with people or sub-departments is not archived; an empty one is, and leaves the tree
        $this->expectRejected($this->patchAs('admin', "/api/hr/departments/{$child['id']}", ['active' => false]), 'DEPARTMENT_IN_USE', [422]);
        $empty = $this->expectOk($this->postAs('admin', '/api/hr/departments', ['nameAr' => "قسم فارغ {$tag}", 'parentId' => $root['id']]));
        $this->expectOk($this->patchAs('admin', "/api/hr/departments/{$empty['id']}", ['active' => false]));
        $this->assertFalse(collect($this->expectOk($this->getAs('gm', '/api/hr/departments'))['items'])->contains('id', $empty['id']));
        $this->assertTrue(collect($this->expectOk($this->getAs('gm', '/api/hr/departments?archived=1'))['items'])->contains('id', $empty['id']));

        // the org chart: the same tree with the active people in it
        $org = $this->expectOk($this->getAs('gm', '/api/hr/org'));
        $this->assertSame($manager['id'], collect($org['employees'])->firstWhere('departmentId', $leaf['id'])['managerId']);
    }

    public function test_job_positions_and_the_title_an_employee_carries(): void
    {
        $tag = self::uid();
        $job = $this->expectOk($this->postAs('admin', '/api/hr/jobs', ['nameAr' => "محاسب {$tag}", 'nameEn' => "Accountant {$tag}", 'description' => 'قيود وتسويات']));
        $e = $this->makeEmployee(['jobId' => $job['id']]);
        $this->assertSame("محاسب {$tag}", $e['jobTitle'], 'the title follows the position when none was written');
        $custom = $this->makeEmployee(['jobId' => $job['id'], 'jobTitle' => 'محاسب أول']);
        $this->assertSame('محاسب أول', $custom['jobTitle']);

        $list = $this->expectOk($this->getAs('gm', '/api/hr/jobs?q='.urlencode("محاسب {$tag}")));
        $this->assertSame(1, $list['total']);
        $this->assertSame(2, $list['items'][0]['headcount']);
        $this->expectRejected($this->patchAs('admin', "/api/hr/jobs/{$job['id']}", ['active' => false]), 'JOB_IN_USE', [422]);
        $this->expectOk($this->patchAs('admin', "/api/hr/jobs/{$job['id']}", ['nameEn' => "Senior accountant {$tag}"]));
        $this->assertSame("Senior accountant {$tag}", $this->expectOk($this->getAs('gm', '/api/hr/jobs?q='.urlencode($tag)))['items'][0]['nameEn']);
    }

    public function test_the_directory_lists_filters_sorts_and_pages(): void
    {
        $tag = self::uid();
        $dept = $this->expectOk($this->postAs('admin', '/api/hr/departments', ['nameAr' => "اللوجستيات {$tag}"]));
        $sub = $this->expectOk($this->postAs('admin', '/api/hr/departments', ['nameAr' => "النقل {$tag}", 'parentId' => $dept['id']]));
        $boss = $this->makeEmployee(['nameAr' => "أ مدير {$tag}", 'nameEn' => "Alpha {$tag}", 'departmentId' => $dept['id'], 'workEmail' => "Boss.{$tag}@Example.test", 'startDate' => '2024-02-01']);
        $this->assertMatchesRegularExpression('/^EMP-\d{4}-\d{5}$/', $boss['code']);
        $this->assertSame("boss.{$tag}@example.test", $boss['workEmail'], 'e-mails are kept in lower case');
        $this->assertSame('2024-02-01', $boss['startDate']);
        $b = $this->makeEmployee(['nameAr' => "ب سائق {$tag}", 'nameEn' => "Bravo {$tag}", 'departmentId' => $sub['id'], 'managerId' => $boss['code'], 'workMobile' => '0550000001']);
        $c = $this->makeEmployee(['nameAr' => "ج أمين مستودع {$tag}", 'nameEn' => "Charlie {$tag}", 'departmentId' => $sub['id'], 'managerId' => $boss['id']]);
        $this->assertSame($boss['code'], $b['manager']['code'], 'a manager may be named by code');

        $all = $this->expectOk($this->getAs('gm', '/api/hr/employees?q='.urlencode($tag)));
        $this->assertSame(3, $all['total']);
        $this->assertSame([$boss['code'], $b['code'], $c['code']], array_column($all['items'], 'code'), 'A → Z by default');
        $this->assertSame([$c['code'], $b['code'], $boss['code']], array_column($this->expectOk($this->getAs('gm', '/api/hr/employees?order=desc&q='.urlencode($tag)))['items'], 'code'));
        // filters: the department alone, the department with what is under it, the manager, the search by e-mail and by mobile
        $this->assertSame(1, $this->expectOk($this->getAs('gm', "/api/hr/employees?department={$dept['id']}"))['total']);
        $this->assertSame(3, $this->expectOk($this->getAs('gm', "/api/hr/employees?department={$dept['id']}&sub=1"))['total']);
        $this->assertSame(2, $this->expectOk($this->getAs('gm', "/api/hr/employees?manager={$boss['id']}"))['total']);
        $this->assertSame($boss['code'], $this->expectOk($this->getAs('gm', '/api/hr/employees?q='.urlencode("boss.{$tag}")))['items'][0]['code']);
        $this->assertSame($b['code'], $this->expectOk($this->getAs('gm', '/api/hr/employees?q=0550000001'))['items'][0]['code']);
        // paging
        $page2 = $this->expectOk($this->getAs('gm', '/api/hr/employees?pageSize=2&page=2&q='.urlencode($tag)));
        $this->assertSame([3, 2, 2, 1], [$page2['total'], $page2['pages'], $page2['page'], count($page2['items'])]);

        // one employee: by id or by code, with the people around them
        $card = $this->expectOk($this->getAs('gm', "/api/hr/employees/{$boss['code']}"));
        $this->assertEqualsCanonicalizing([$b['code'], $c['code']], array_column($card['reports'], 'code'));
        $this->expectRejected($this->getAs('gm', '/api/hr/employees/EMP-0000-99999'), 'EMPLOYEE_NOT_FOUND', [404]);

        // a person cannot report to someone who reports to them; nor two active people share one work e-mail
        $this->expectRejected($this->patchAs('admin', "/api/hr/employees/{$boss['id']}", ['managerId' => $b['id']]), 'EMPLOYEE_MANAGER_CYCLE', [422]);
        $this->expectRejected($this->patchAs('admin', "/api/hr/employees/{$boss['id']}", ['managerId' => $boss['id']]), 'EMPLOYEE_MANAGER_CYCLE', [422]);
        $this->expectRejected($this->patchAs('admin', "/api/hr/employees/{$c['id']}", ['workEmail' => "boss.{$tag}@example.test"]), 'EMPLOYEE_EMAIL_TAKEN', [409]);

        // someone who left drops out of the default list and of the head counts, and stays findable
        $this->expectOk($this->patchAs('admin', "/api/hr/employees/{$c['id']}", ['status' => 'left']));
        $this->assertSame(2, $this->expectOk($this->getAs('gm', '/api/hr/employees?q='.urlencode($tag)))['total']);
        $this->assertSame(3, $this->expectOk($this->getAs('gm', '/api/hr/employees?status=all&q='.urlencode($tag)))['total']);
        $this->assertSame([$c['code']], array_column($this->expectOk($this->getAs('gm', '/api/hr/employees?status=left&q='.urlencode($tag)))['items'], 'code'));
        $this->assertSame(1, collect($this->expectOk($this->getAs('gm', '/api/hr/departments'))['items'])->firstWhere('id', $sub['id'])['headcount']);
    }

    public function test_an_employee_is_linked_to_one_login_and_to_that_logins_driver_record(): void
    {
        $e = $this->makeEmployee();
        $linked = $this->expectOk($this->patchAs('admin', "/api/hr/employees/{$e['id']}", ['userId' => 'sales']));
        $this->assertSame('sales', $linked['user']['username']);
        // one login is one person
        $other = $this->makeEmployee();
        $this->expectRejected($this->patchAs('admin', "/api/hr/employees/{$other['id']}", ['userId' => $this->userId('sales')]), 'EMPLOYEE_USER_TAKEN', [409]);
        $this->expectRejected($this->patchAs('admin', "/api/hr/employees/{$other['id']}", ['userId' => 'no-such-login']), 'USER_NOT_FOUND', [404]);
        // the link can be taken off again
        $this->assertNull($this->expectOk($this->patchAs('admin', "/api/hr/employees/{$e['id']}", ['userId' => null]))['user']);

        // a login that has a driver record brings it along
        $driver = Driver::whereNotNull('user_id')->first();
        $this->assertNotNull($driver, 'the demo data has a driver with a login');
        $asDriver = $this->expectOk($this->patchAs('admin', "/api/hr/employees/{$other['id']}", ['userId' => $driver->user_id]));
        $this->assertSame($driver->code, $asDriver['driver']['code']);
        $this->expectRejected($this->patchAs('admin', "/api/hr/employees/{$e['id']}", ['driverId' => $driver->code]), 'EMPLOYEE_DRIVER_TAKEN', [409]);
        // free the login and the driver record for the other tests
        $this->expectOk($this->patchAs('admin', "/api/hr/employees/{$other['id']}", ['userId' => null, 'driverId' => null]));
    }

    public function test_the_personal_file_does_not_exist_for_a_caller_without_hr_private(): void
    {
        $clerk = $this->userWith('hrclerk', ['page.hr', 'hr.manage']); // changes the directory, may not read the personal file
        $tag = self::uid();
        $identity = "ID{$tag}99";
        $e = $this->makeEmployee(['notes' => 'ملاحظة يكتبها قسم الموارد البشرية']);
        $private = $this->expectOk($this->putAs('admin', "/api/hr/employees/{$e['id']}/private", ['nationality' => 'بلد اختبار', 'identityNo' => $identity, 'identityExpiry' => now()->addDays(200)->toDateString(),
            'birthDate' => '1990-05-17', 'gender' => 'male', 'maritalStatus' => 'married', 'children' => 2, 'homeAddress' => 'شارع الاختبار 1', 'privatePhone' => '0500000009',
            'bankName' => 'بنك الاختبار', 'bankAccount' => 'sa00 0000 0000 0000 0000 0001', 'emergencyContact' => 'قريب اختبار', 'emergencyPhone' => '0500000008']));
        $this->assertSame($identity, $private['identityNo']);
        $this->assertSame('SA0000000000000000000001', $private['bankAccount'], 'an IBAN is kept without spaces, in capitals');
        $this->assertSame('1990-05-17', $private['birthDate']);
        $this->assertSame(200, $private['identityDays']);

        // with hr.private the card carries the file; without it the keys are not there at all
        $full = $this->expectOk($this->getAs('admin', "/api/hr/employees/{$e['id']}"));
        $this->assertSame($identity, $full['private']['identityNo']);
        $this->assertArrayHasKey('contracts', $full);
        foreach (['gm', $clerk] as $user) {
            $card = $this->expectOk($this->getAs($user, "/api/hr/employees/{$e['id']}"));
            foreach (['private', 'contracts', 'files'] as $key) {
                $this->assertArrayNotHasKey($key, $card, "{$user} must not receive `{$key}`");
            }
            $json = json_encode($card, JSON_UNESCAPED_UNICODE);
            foreach ([$identity, 'SA0000000000000000000001', '1990-05-17', 'شارع الاختبار 1', '0500000009', 'قريب اختبار'] as $secret) {
                $this->assertStringNotContainsString($secret, $json, "{$user} received a value of the personal file");
            }
            $this->assertStringNotContainsString($identity, json_encode($this->expectOk($this->getAs($user, "/api/hr/employees?q={$e['code']}")), JSON_UNESCAPED_UNICODE));
            $this->expectRejected($this->getAs($user, "/api/hr/employees/{$e['id']}/private"), 'FORBIDDEN', [403]);
            $this->expectRejected($this->putAs($user, "/api/hr/employees/{$e['id']}/private", ['identityNo' => 'CHANGED']), 'FORBIDDEN', [403]);
            $this->expectRejected($this->getAs($user, '/api/hr/contracts'), 'FORBIDDEN', [403]);
        }
        // what HR writes about a person in free text is HR's: the directory's readers do not receive it
        $this->assertArrayNotHasKey('notes', $this->expectOk($this->getAs('gm', "/api/hr/employees/{$e['id']}")));
        $this->assertSame('ملاحظة يكتبها قسم الموارد البشرية', $this->expectOk($this->getAs($clerk, "/api/hr/employees/{$e['id']}"))['notes']);
        $this->assertSame($identity, EmployeePrivate::where('employee_id', $e['id'])->value('identity_no'), 'a refused write changed nothing');
        // the clerk still does the clerk's work on the same employee
        $this->assertSame('مكتب الرياض', $this->expectOk($this->patchAs($clerk, "/api/hr/employees/{$e['id']}", ['workLocation' => 'مكتب الرياض']))['workLocation']);

        // the audit trail of the personal file names the fields that changed — never their values
        $trail = AuditLog::where('action', 'HR.PRIVATE.UPDATE')->where('entity_id', $e['id'])->get();
        $this->assertCount(1, $trail);
        $this->assertStringContainsString('identityNo', (string) $trail[0]->new_value);
        foreach ([$identity, 'SA0000000000000000000001', '0500000009'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $trail[0]->new_value.(string) $trail[0]->old_value);
        }
        // a partial update keeps the rest; an unchanged one writes nothing
        $after = $this->expectOk($this->putAs('admin', "/api/hr/employees/{$e['id']}/private", ['privatePhone' => '0500000007']));
        $this->assertSame(['0500000007', $identity], [$after['privatePhone'], $after['identityNo']]);
        $this->expectOk($this->putAs('admin', "/api/hr/employees/{$e['id']}/private", ['privatePhone' => '0500000007']));
        $this->assertSame(2, AuditLog::where('action', 'HR.PRIVATE.UPDATE')->where('entity_id', $e['id'])->count());
        $this->expectRejected($this->putAs('admin', "/api/hr/employees/{$e['id']}/private", ['gender' => 'unknown']), 'INVALID_INPUT', [400]);
    }

    public function test_the_summary_and_the_lookups_give_each_caller_their_own_part(): void
    {
        $admin = $this->expectOk($this->getAs('admin', '/api/hr/summary'));
        $this->assertSame(Employee::where('status', 'active')->count(), $admin['directory']['employees']);
        $this->assertSame(Department::where('active', true)->count(), $admin['directory']['departments']);
        foreach (['due', 'contracts', 'claims'] as $key) {
            $this->assertArrayHasKey($key, $admin);
        }
        // payroll is said, not built
        $this->assertFalse($admin['payroll']['inThisSection']);
        $this->assertStringContainsString('الرواتب', $admin['payroll']['ar']);
        $this->assertFalse($admin['odoo']['follows']);

        $gm = $this->expectOk($this->getAs('gm', '/api/hr/summary'));
        $this->assertArrayHasKey('directory', $gm);
        $this->assertArrayHasKey('review', $gm['claims']);
        $this->assertArrayNotHasKey('contracts', $gm, 'contracts are people\'s pay');
        $this->assertArrayNotHasKey('due', $gm);

        // a claimant: the directory's figures and his own claims — nothing of pay, papers or other people's claims
        $sales = $this->expectOk($this->getAs('sales', '/api/hr/summary'));
        $this->assertArrayHasKey('directory', $sales);
        $this->assertArrayNotHasKey('contracts', $sales);
        $this->assertArrayNotHasKey('due', $sales);
        $this->assertArrayHasKey('mine', $sales['claims']);
        $this->assertArrayNotHasKey('review', $sales['claims']);

        $lookups = $this->expectOk($this->getAs('sales', '/api/hr/lookups'));
        $this->assertNotEmpty($lookups['categories']);
        $this->assertArrayNotHasKey('accountCode', $lookups['categories'][0], 'the account of a category is the reviewers\' business');
        // he opens the page, so the directory's names are his to pick from; nothing of users, accounts, banks or papers
        $this->assertArrayHasKey('employees', $lookups);
        foreach (['users', 'accounts', 'banks', 'contractTypes', 'documentKinds'] as $key) {
            $this->assertArrayNotHasKey($key, $lookups);
        }
        $finance = $this->expectOk($this->getAs('finance', '/api/hr/lookups'));
        $this->assertNotEmpty($finance['accounts']);
        $this->assertNotEmpty($finance['banks']);
        $this->assertArrayHasKey('accountCode', $finance['categories'][0]);
        $this->assertArrayNotHasKey('users', $finance);
        $full = $this->expectOk($this->getAs('admin', '/api/hr/lookups'));
        $this->assertNotEmpty($full['users']);
        $this->assertContains('passport', array_column($full['documentKinds'], 'key'));
    }
}
