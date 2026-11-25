<?php

namespace Tests\Feature\Hr;

use App\Finance\Models\Expense;
use App\Hr\Models\Claim;
use App\Hr\Models\ClaimLine;
use App\Hr\Models\Contract;
use App\Hr\Models\Department;
use App\Hr\Models\Employee;
use App\Hr\Models\EmployeePrivate;
use App\Hr\Models\ExpenseCategory;
use App\Hr\Models\HrAlert;
use App\Hr\Models\HrFile;
use App\Hr\Models\Job;
use App\Hr\OdooHrProjection;
use App\Integration\Models\IntExternalRef;
use App\Integration\Odoo\Models\OdooChange;
use App\Integration\Odoo\Models\OdooRecord;
use App\Models\AuditLog;
use App\Support\AppError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\ApiTestCase;

/**
 * From the copy of Odoo to the section (App\Hr\OdooHrProjection). A small mirror is written straight into the copy's
 * tables — Odoo is never called — and the class is a sequential story: the plan, the first load, who owns what while
 * the section follows Odoo, what the change feed brings, and the hand-over. Every person here is invented.
 */
class HrOdooProjectionTest extends ApiTestCase
{
    use HrFixtures;

    private static bool $mirrored = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sealNetwork();
        $this->followOdoo(true);
        // two OPS logins the projection should recognise, and one it must not
        $this->userWith('hrlinked', ['expense.claim'], 'hr.one@example.test');   // = the Odoo login of employee 101
        $this->userWith('emailuser', ['expense.claim'], 'work.two@example.test'); // = the work e-mail of employee 102
        if (! self::$mirrored) {
            $this->buildMirror();
            self::$mirrored = true;
        }
    }

    private function projection(): OdooHrProjection
    {
        return app(OdooHrProjection::class);
    }

    private function buildMirror(): void
    {
        $this->emptyMirror();
        foreach (['hr.department', 'hr.job', 'hr.employee', 'hr.contract', 'hr.expense.sheet', 'hr.expense', 'ir.attachment', 'res.users', 'res.partner.bank', 'product.product', 'product.template', 'account.tax'] as $model) {
            $this->mirrorModel($model, ['name' => 'char', 'active' => 'boolean']);
        }
        $m = fn (int $id) => [$id, 'label'];
        $this->mirrorRecord('res.users', 501, ['login' => 'HR.One@Example.test']);
        $this->mirrorRecord('res.users', 502, ['login' => 'admin']); // a bare account name both systems have: never an identity
        $this->mirrorRecord('res.partner.bank', 301, ['acc_number' => 'SA00 1111 2222 3333 4444 5555', 'bank_id' => [1, 'Test Bank']]);
        $this->mirrorRecord('account.tax', 5, ['amount' => 15.0, 'amount_type' => 'percent']);
        $this->mirrorRecord('product.template', 40, ['name' => 'مصاريف سفر Travel costs', 'can_be_expensed' => true]);
        $this->mirrorRecord('product.product', 400, ['product_tmpl_id' => $m(40), 'default_code' => 'trv & acc']);
        $this->mirrorRecord('product.template', 41, ['name' => 'Whatever Odoo calls it']);
        $this->mirrorRecord('product.product', 401, ['product_tmpl_id' => $m(41), 'default_code' => 'TRAVEL']); // the code of an OPS category

        // departments: 11 names a parent with a HIGHER id, 10 a manager that is an employee — both arrive later in the pass
        $this->mirrorRecord('hr.department', 10, ['name' => 'الإدارة العامة Head Office', 'parent_id' => false, 'manager_id' => [102, 'Second Tester'], 'active' => true]);
        $this->mirrorRecord('hr.department', 11, ['name' => 'المستودعات Warehouses', 'parent_id' => $m(12), 'manager_id' => false, 'active' => true]);
        $this->mirrorRecord('hr.department', 12, ['name' => 'العمليات Operations', 'parent_id' => $m(10), 'manager_id' => false, 'active' => true]);
        $this->mirrorRecord('hr.job', 20, ['name' => 'أمين مستودع Storekeeper', 'department_id' => $m(11), 'description' => '<p>يستلم البضاعة</p><p>ويصرفها</p>', 'active' => true]);
        $this->mirrorRecord('hr.job', 21, ['name' => 'Accountant', 'department_id' => false, 'description' => false, 'active' => true]);

        $this->mirrorRecord('hr.employee', 101, ['name' => 'موظف أول First Tester', 'active' => true, 'job_id' => $m(20), 'job_title' => 'أمين مستودع', 'department_id' => $m(11), 'parent_id' => $m(102),
            'work_email' => 'Work.One@Example.test', 'work_phone' => '0110000001', 'mobile_phone' => '0550000101', 'work_location_id' => [1, 'مستودع الرياض'], 'user_id' => $m(501),
            'registration_number' => 'E-101', 'first_contract_date' => '2023-03-01', 'departure_date' => false, 'departure_reason_id' => false,
            'country_id' => [192, 'Testland'], 'identification_id' => 'ID-ODOO-101', 'birthday' => '1991-02-03', 'gender' => 'male', 'marital' => 'married', 'children' => 2,
            'private_phone' => '0500000101', 'private_email' => 'Home.One@Example.test', 'private_street' => 'Test street 1', 'private_city' => 'Test city', 'bank_account_id' => $m(301),
            'emergency_contact' => false, 'emergency_phone' => '0500000199']);
        $this->mirrorRecord('hr.employee', 102, ['name' => 'Second Tester', 'active' => true, 'job_id' => [21, 'Accountant'], 'job_title' => false, 'department_id' => $m(10), 'parent_id' => false,
            'work_email' => 'work.two@example.test', 'user_id' => $m(502), 'marital' => 'single', 'children' => 0, 'birthday' => false, 'identification_id' => false]);
        $this->mirrorRecord('hr.employee', 103, ['name' => 'موظف غادر Left Tester', 'active' => false, 'department_id' => $m(11), 'parent_id' => $m(102), 'work_email' => false, 'user_id' => false,
            'departure_date' => '2025-09-30', 'departure_reason_id' => [2, 'Resigned'], 'children' => 0]);

        $this->mirrorRecord('hr.contract', 201, ['name' => 'Contract 101', 'active' => true, 'employee_id' => $m(101), 'department_id' => $m(11), 'job_id' => [20, 'أمين مستودع Storekeeper'], 'contract_type_id' => [1, 'Permanent'],
            'date_start' => '2023-03-01', 'date_end' => false, 'wage' => 6100.5, 'l10n_sa_housing_allowance' => 1500.0, 'l10n_sa_transportation_allowance' => 400.0, 'l10n_sa_other_allowances' => 0.0, 'state' => 'open']);
        $this->mirrorRecord('hr.contract', 202, ['name' => 'Contract 103', 'active' => false, 'employee_id' => $m(103), 'contract_type_id' => [2, 'Fixed term'], 'date_start' => '2022-01-01', 'date_end' => '2025-09-30', 'wage' => 4000.0, 'state' => 'cancel']);
        $this->mirrorRecord('hr.contract', 203, ['name' => 'Nobody', 'active' => true, 'employee_id' => false, 'date_start' => '2024-01-01', 'wage' => 1.0, 'state' => 'draft']);

        $sheet = fn (int $id, int $employee, string $state, float $total, float $tax, array $more = []) => $this->mirrorRecord('hr.expense.sheet', $id, $more + ['name' => "Report {$id}", 'employee_id' => $m($employee), 'department_id' => $m(11),
            'user_id' => [502, 'Approver Name'], 'state' => $state, 'total_amount' => $total, 'untaxed_amount' => $total - $tax, 'total_tax_amount' => $tax, 'currency_id' => [150, 'SAR'], 'create_date' => '2026-07-01 08:00:00',
            'approval_date' => false, 'accounting_date' => false]);
        $sheet(601, 101, 'done', 230.0, 30.0, ['approval_date' => '2026-07-02 09:00:00', 'accounting_date' => '2026-07-03']);
        $sheet(602, 102, 'cancel', 80.0, 0.0);
        $sheet(603, 101, 'draft', 55.0, 0.0);
        $sheet(604, 102, 'approve', 100.0, 0.0, ['approval_date' => '2026-08-01 10:00:00']);
        $expense = fn (int $id, int|false $sheetId, int $employee, int $product, float $total, float $tax, string $mode, string $state, string $date) => $this->mirrorRecord('hr.expense', $id, ['name' => "Expense {$id}", 'sheet_id' => $sheetId ? $m($sheetId) : false,
            'employee_id' => $m($employee), 'product_id' => $m($product), 'account_id' => [9, '400100 Travel'], 'date' => $date, 'payment_mode' => $mode, 'total_amount' => $total, 'tax_amount' => $tax,
            'tax_ids' => $tax > 0 ? [5] : [], 'state' => $state, 'quantity' => 1.0, 'create_date' => "{$date} 10:00:00"]);
        $expense(701, 601, 101, 400, 230.0, 30.0, 'own_account', 'done', '2026-06-28');
        $expense(702, 602, 102, 401, 80.0, 0.0, 'company_account', 'refused', '2026-07-10');
        $expense(703, 603, 101, 400, 55.0, 0.0, 'own_account', 'draft', '2026-08-05');
        $expense(704, 604, 102, 400, 100.0, 0.0, 'own_account', 'approved', '2026-07-30');
        $expense(705, false, 101, 400, 40.0, 0.0, 'own_account', 'draft', '2026-08-20'); // never put on a report

        $file = fn (int $id, string $model, int $resId, array $more = []) => $this->mirrorRecord('ir.attachment', $id, $more + ['name' => "file-{$id}.pdf", 'res_model' => $model, 'res_id' => $resId, 'res_field' => false,
            'mimetype' => 'application/pdf', 'file_size' => 1000 + $id, 'type' => 'binary']);
        $file(801, 'hr.expense', 701);
        $file(802, 'hr.expense.sheet', 601, ['mimetype' => 'image/png']);
        $file(803, 'hr.employee', 101);
        $file(804, 'account.move', 5);                       // another area's file
        $file(805, 'hr.employee', 101, ['type' => 'url']);    // a link, not a file
        $file(806, 'hr.expense', 999);                        // hangs on an expense the copy does not hold
        $file(807, 'hr.employee', 101, ['res_field' => 'image_1920']); // the picture of a field, not a paper
    }

    private function ops(string $class, int|string $ref): mixed
    {
        return $class::where('source_system', 'odoo')->where('external_ref', (string) $ref)->first();
    }

    public function test_the_plan_counts_what_a_load_would_do_and_writes_nothing(): void
    {
        $before = [Employee::count(), Claim::count(), HrFile::count(), ExpenseCategory::count(), IntExternalRef::where('system', 'odoo')->count(), DB::table('users')->count(), AuditLog::count()];
        $this->assertTrue($this->projection()->ready());
        $plan = $this->projection()->plan();
        $this->assertTrue($plan['dryRun']);
        $this->assertEquals([
            'departments.created' => 3, 'jobs.created' => 2, 'employees.created' => 3, 'personalFiles.created' => 3, 'employees.loginLinked' => 2,
            'contracts.created' => 2, 'contracts.skippedNoEmployee' => 1, 'claims.created' => 4, 'claims.fromLooseExpense.created' => 1, 'lines.created' => 5,
            'categories.created' => 1, 'categories.linked' => 1, 'files.created' => 3, 'files.skippedLink' => 1, 'files.skippedOwnerNotCopied' => 1,
        ], $plan['counts']);
        $this->assertSame($before, [Employee::count(), Claim::count(), HrFile::count(), ExpenseCategory::count(), IntExternalRef::where('system', 'odoo')->count(), DB::table('users')->count(), AuditLog::count()], 'a plan writes nothing');
        // what a person reads of a pass names records by model and Odoo id — never a person
        $this->assertSame(['contracts.skippedNoEmployee' => ['hr.contract #203']], $plan['notes']);
    }

    public function test_the_first_load_puts_everything_in_its_place_and_a_second_changes_nothing(): void
    {
        $vouchers = Expense::count();
        $out = $this->projection()->apply();
        $this->assertFalse($out['dryRun']);
        $this->assertArrayNotHasKey('failed', $out);
        $this->assertEquals([
            'departments.created' => 3, 'jobs.created' => 2, 'employees.created' => 3, 'personalFiles.created' => 3, 'employees.loginLinked' => 2,
            'contracts.created' => 2, 'contracts.skippedNoEmployee' => 1, 'claims.created' => 4, 'claims.fromLooseExpense.created' => 1, 'lines.created' => 5,
            'categories.created' => 1, 'categories.linked' => 1, 'files.created' => 3, 'files.skippedLink' => 1, 'files.skippedOwnerNotCopied' => 1,
        ], $out['counts']);

        // ── departments and jobs: the tree and the manager are right although they arrived out of order
        [$head, $warehouses, $operations] = [$this->ops(Department::class, 10), $this->ops(Department::class, 11), $this->ops(Department::class, 12)];
        [$first, $second, $left] = [$this->ops(Employee::class, 101), $this->ops(Employee::class, 102), $this->ops(Employee::class, 103)];
        $this->assertSame(['الإدارة العامة', 'Head Office', null], [$head->name_ar, $head->name_en, $head->parent_id]);
        $this->assertSame([$operations->id, $head->id], [$warehouses->parent_id, $operations->parent_id]);
        $this->assertSame($second->id, $head->manager_id);
        $job = $this->ops(Job::class, 20);
        $this->assertSame(['أمين مستودع', 'Storekeeper', $warehouses->id, "يستلم البضاعة\nويصرفها"], [$job->name_ar, $job->name_en, $job->department_id, $job->description]);

        // ── the work part of an employee
        $this->assertMatchesRegularExpression('/^EMP-\d{4}-\d{5}$/', $first->code);
        $this->assertSame(['موظف أول', 'First Tester', 'E-101', $warehouses->id, $job->id, 'أمين مستودع', $second->id, 'work.one@example.test', '0110000001', '0550000101', 'مستودع الرياض', '2023-03-01', 'active'],
            [$first->name_ar, $first->name_en, $first->employee_no, $first->department_id, $first->job_id, $first->job_title, $first->manager_id, $first->work_email, $first->work_phone, $first->work_mobile, $first->work_location, $first->start_date->toDateString(), $first->status]);
        $this->assertSame(['Second Tester', 'Second Tester', 'Accountant', 'active'], [$second->name_ar, $second->name_en, $second->job_title, $second->status]);
        $this->assertSame('left', $left->status);
        // ── links by identity: the Odoo login / the work e-mail IS an OPS user's e-mail; the bare word "admin" is nobody's identity
        $this->assertSame($this->userId('hrlinked'), $first->user_id);
        $this->assertSame($this->userId('emailuser'), $second->user_id);
        $this->assertFalse(Employee::where('user_id', $this->userId('admin'))->exists());

        // ── the personal part went to its own table, and only there
        $file = EmployeePrivate::where('employee_id', $first->id)->first();
        $this->assertSame(['Testland', 'ID-ODOO-101', '1991-02-03', 'male', 'married', 2, '0500000101', 'home.one@example.test', 'Test street 1، Test city', 'Test Bank', 'SA0011112222333344445555', '0500000199'],
            [$file->nationality, $file->identity_no, $file->birth_date->toDateString(), $file->gender, $file->marital_status, $file->children, $file->private_phone, $file->private_email, $file->home_address, $file->bank_name, $file->bank_account, $file->emergency_phone]);
        $gone = EmployeePrivate::where('employee_id', $left->id)->first();
        $this->assertSame(['2025-09-30', 'Resigned'], [$gone->end_date->toDateString(), $gone->departure_reason]);
        $card = json_encode($this->expectOk($this->getAs('gm', "/api/hr/employees/{$first->code}")), JSON_UNESCAPED_UNICODE);
        foreach (['ID-ODOO-101', '1991-02-03', 'SA0011112222333344445555', '0500000101', 'Test street'] as $secret) {
            $this->assertStringNotContainsString($secret, $card);
        }
        $this->assertSame('ID-ODOO-101', $this->expectOk($this->getAs('admin', "/api/hr/employees/{$first->code}"))['private']['identityNo']);

        // ── contracts (people's pay)
        $contract = $this->ops(Contract::class, 201);
        $this->assertSame(['OD-CT-201', $first->id, 'permanent', 'Permanent', 'أمين مستودع', '2023-03-01', null, '6100.50', '1500.00', '400.00', 'running'],
            [$contract->number, $contract->employee_id, $contract->type, $contract->type_label, $contract->job_title, $contract->start_date->toDateString(), $contract->end_date, $contract->wage, $contract->housing_allowance, $contract->transport_allowance, $contract->state]);
        $this->assertSame(['cancelled', 'fixed_term'], [$this->ops(Contract::class, 202)->state, $this->ops(Contract::class, 202)->type]);
        $this->assertNull($this->ops(Contract::class, 203));

        // ── expense reports: Odoo's state and Odoo's figures, as history
        $paid = $this->ops(Claim::class, 601);
        $this->assertSame(['OD-ES-601', 'Report 601', 'paid', 'done', 'odoo', '200.00', '30.00', '230.00', '230.00', '0.00', 'Approver Name', 'SAR', $first->id, $this->userId('hrlinked')],
            [$paid->number, $paid->title, $paid->state, $paid->source_state, $paid->posted_in, $paid->amount, $paid->vat_amount, $paid->total, $paid->employee_due, $paid->company_paid, $paid->decided_by, $paid->currency, $paid->employee_id, $paid->claimant_user_id]);
        $this->assertSame(['2026-07-01 08:00', '2026-07-02 09:00', '2026-07-03'], [$paid->created_at->format('Y-m-d H:i'), $paid->decided_at->format('Y-m-d H:i'), $paid->accounting_date->toDateString()]);
        $this->assertSame([['refused', null, '80.00', '0.00'], ['draft', null, '0.00', '55.00'], ['approved', null, '0.00', '100.00']],
            array_map(fn (int $id) => [($c = $this->ops(Claim::class, $id))->state, $c->posted_in, $c->company_paid, $c->employee_due], [602, 603, 604]));
        $line = $this->ops(ClaimLine::class, 701);
        $travel = $this->ops(ExpenseCategory::class, 400);
        $this->assertSame([$paid->id, '2026-06-28', 'Expense 701', '200.00', 15.0, '30.00', '230.00', 'employee', '400100 Travel', 'done', $travel->id],
            [$line->claim_id, $line->expense_date->toDateString(), $line->description, $line->amount, $line->vat_pct, $line->vat_amount, $line->total, $line->paid_by, $line->source_account, $line->source_state, $line->category_id]);
        $this->assertSame(['TRV_ACC', 'مصاريف سفر', 'Travel costs', null], [$travel->code, $travel->name_ar, $travel->name_en, $travel->account_code], 'an Odoo expense product becomes a category; its account in the OPS chart is OPS\'s to choose');
        // the Odoo product whose code is an OPS category's code IS that category: linked, not duplicated, and it stays OPS's
        $this->assertSame(ExpenseCategory::where('code', 'TRAVEL')->value('id'), $this->ops(ClaimLine::class, 702)->category_id);
        $this->assertNull(ExpenseCategory::where('code', 'TRAVEL')->value('source_system'));
        $this->assertSame('company', $this->ops(ClaimLine::class, 702)->paid_by);
        // an expense that was never put on a report is a draft claim of its own
        $loose = $this->ops(Claim::class, 'expense:705');
        $this->assertSame(['OD-EX-705', 'draft', '40.00', '40.00', $first->id], [$loose->number, $loose->state, $loose->total, $loose->employee_due, $loose->employee_id]);
        $this->assertSame($loose->id, $this->ops(ClaimLine::class, 705)->claim_id);
        // NOTHING of it reached the OPS books
        $this->assertSame($vouchers, Expense::count());
        $this->assertSame(0, ClaimLine::where('source_system', 'odoo')->whereNotNull('finance_expense_id')->count());

        // ── attachments: listed on what they hang on; their content stays with the Odoo backup
        $receipt = $this->ops(HrFile::class, 801);
        $this->assertSame(['claim_line', $line->id, 'file-801.pdf', 'application/pdf', 1801, 'odoo', null], [$receipt->owner_type, $receipt->owner_id, $receipt->name, $receipt->mime, $receipt->size, $receipt->storage, $receipt->storage_key]);
        $this->assertSame(['claim', $paid->id], [$this->ops(HrFile::class, 802)->owner_type, $this->ops(HrFile::class, 802)->owner_id]);
        $this->assertSame(['employee', $first->id], [$this->ops(HrFile::class, 803)->owner_type, $this->ops(HrFile::class, 803)->owner_id]);
        foreach ([804, 805, 806, 807] as $notOurs) {
            $this->assertNull($this->ops(HrFile::class, $notOurs));
        }

        // ── links are on record, the trail names records and never people
        $this->assertSame($first->id, IntExternalRef::where('system', 'odoo')->where('entity', 'hr_employee')->where('external_id', '101')->value('internal_id'));
        $trail = AuditLog::where('username', 'svc.odoo')->whereIn('action', ['HR.EMPLOYEE.IMPORT', 'HR.EMPLOYEE.LINK', 'HR.CONTRACT.IMPORT', 'CLAIM.IMPORT'])->get();
        $this->assertGreaterThanOrEqual(3 + 2 + 2 + 5, $trail->count());
        $text = $trail->map(fn ($a) => $a->new_value.$a->old_value)->implode(' ');
        foreach (['First Tester', 'موظف أول', 'ID-ODOO-101', '6100', 'SA0011'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }

        // ── idempotent: a second whole pass creates nothing and changes nothing
        $again = $this->projection()->apply()['counts'];
        $this->assertEquals(['departments.unchanged' => 3, 'jobs.unchanged' => 2, 'employees.unchanged' => 3, 'personalFiles.unchanged' => 3, 'contracts.unchanged' => 2, 'contracts.skippedNoEmployee' => 1,
            'claims.unchanged' => 4, 'claims.fromLooseExpense.unchanged' => 1, 'lines.unchanged' => 5, 'files.unchanged' => 3, 'files.skippedLink' => 1, 'files.skippedOwnerNotCopied' => 1], $again);
        $this->assertSame([3, 3, 2, 5, 5, 3], [Department::where('source_system', 'odoo')->count(), Employee::where('source_system', 'odoo')->count(), Contract::where('source_system', 'odoo')->count(),
            Claim::where('source_system', 'odoo')->count(), ClaimLine::where('source_system', 'odoo')->count(), HrFile::where('source_system', 'odoo')->count()]);
    }

    public function test_what_odoo_owns_is_shown_and_not_edited_while_the_section_follows_odoo(): void
    {
        $first = $this->ops(Employee::class, 101);
        $this->assertTrue($this->expectOk($this->getAs('admin', '/api/hr/summary'))['odoo']['follows']);
        $card = $this->expectOk($this->getAs('admin', "/api/hr/employees/{$first->id}"));
        $this->assertSame(['odoo', true], [$card['source'], $card['locked']]);
        $this->assertContains('nameAr', $card['ownedFields']);
        $this->assertNotContains('userId', $card['ownedFields']);

        // the work part: Odoo's values are changed in Odoo; what OPS adds is OPS's
        $refused = $this->expectRejected($this->patchAs('admin', "/api/hr/employees/{$first->id}", ['nameAr' => 'اسم معدَّل هنا', 'workPhone' => '0119999999']), 'EMPLOYEE_OWNED_BY_SOURCE', [422]);
        $this->assertEqualsCanonicalizing(['nameAr', 'workPhone'], $refused['details']['fields']);
        $this->assertSame('موظف أول', $first->refresh()->name_ar);
        $this->assertSame('ملاحظة من العمليات', $this->expectOk($this->patchAs('admin', "/api/hr/employees/{$first->id}", ['notes' => 'ملاحظة من العمليات', 'nameAr' => 'موظف أول']))['notes'], 'an unchanged Odoo value in the same request is not an edit');
        // the personal file: the same rule, field by field
        $this->expectRejected($this->putAs('admin', "/api/hr/employees/{$first->id}/private", ['identityNo' => 'CHANGED-HERE']), 'EMPLOYEE_OWNED_BY_SOURCE', [422]);
        $own = $this->expectOk($this->putAs('admin', "/api/hr/employees/{$first->id}/private", ['identityExpiry' => now()->addDays(400)->toDateString(), 'passportNo' => 'P-OPS-101', 'passportExpiry' => now()->addDays(900)->toDateString(), 'notes' => 'ملف مكتمل']));
        $this->assertSame(['ID-ODOO-101', 'P-OPS-101'], [$own['identityNo'], $own['passportNo']]);
        $this->assertContains('identityNo', $own['ownedFields']);
        $this->assertNotContains('passportNo', $own['ownedFields']);
        // departments, jobs, contracts, categories
        $this->expectRejected($this->patchAs('admin', '/api/hr/departments/'.$this->ops(Department::class, 11)->id, ['nameAr' => 'اسم آخر']), 'DEPARTMENT_OWNED_BY_SOURCE', [422]);
        $this->assertSame('ملاحظة', $this->expectOk($this->patchAs('admin', '/api/hr/departments/'.$this->ops(Department::class, 11)->id, ['notes' => 'ملاحظة']))['notes']);
        $this->expectRejected($this->patchAs('admin', '/api/hr/jobs/'.$this->ops(Job::class, 20)->id, ['nameAr' => 'اسم آخر']), 'JOB_OWNED_BY_SOURCE', [422]);
        $contract = $this->ops(Contract::class, 201);
        $this->expectRejected($this->patchAs('admin', "/api/hr/contracts/{$contract->id}", ['wage' => 1]), 'CONTRACT_OWNED_BY_SOURCE', [422]);
        $this->expectRejected($this->postAs('admin', "/api/hr/contracts/{$contract->id}/end"), 'CONTRACT_OWNED_BY_SOURCE', [422]);
        $this->assertSame('running', $contract->refresh()->state);
        $shown = $this->expectOk($this->getAs('admin', "/api/hr/contracts/{$contract->number}"));
        $this->assertSame([true, false, false, true], [$shown['locked'], $shown['can']['edit'], $shown['can']['end'], $shown['can']['renew']]);
        $travel = $this->ops(ExpenseCategory::class, 400);
        $this->expectRejected($this->patchAs('finance', "/api/hr/expense-categories/{$travel->id}", ['nameAr' => 'اسم آخر']), 'CATEGORY_OWNED_BY_SOURCE', [422]);
        $this->assertSame('6210', $this->expectOk($this->patchAs('finance', "/api/hr/expense-categories/{$travel->id}", ['accountCode' => '6210']))['accountCode'], 'the account in the OPS chart is OPS\'s own');

        // expense reports are history: read by their owner and the reviewers, moved by nobody
        $draft = $this->ops(Claim::class, 603);
        $approved = $this->ops(Claim::class, 604);
        $mine = $this->expectOk($this->getAs('hrlinked', '/api/hr/claims'));
        $this->assertEqualsCanonicalizing(['OD-ES-601', 'OD-ES-603', 'OD-EX-705'], array_column($mine['items'], 'number'), 'the employee sees their Odoo history through the login link');
        $detail = $this->expectOk($this->getAs('hrlinked', '/api/hr/claims/OD-ES-601'));
        $this->assertSame(['paid', true, 'odoo'], [$detail['state'], $detail['locked'], $detail['postedIn']]);
        $this->assertSame([['draft', 'odoo'], ['approved', 'odoo'], ['posted', 'odoo']], array_map(fn ($h) => [$h['to'], $h['origin']], $detail['history']));
        $this->assertSame([], array_keys(array_filter($detail['can'])), 'nothing can be done to it here');
        $this->assertTrue($detail['lines'][0]['receipts'][0]['fromOdoo']);
        $this->expectRejected($this->postAs('hrlinked', "/api/hr/claims/{$draft->id}/submit"), 'CLAIM_OWNED_BY_SOURCE', [422]);
        $this->expectRejected($this->patchAs('hrlinked', "/api/hr/claims/{$draft->id}", ['title' => 'عنوان آخر']), 'CLAIM_OWNED_BY_SOURCE', [422]);
        $this->expectRejected($this->postAs('finance', "/api/hr/claims/{$approved->id}/post", ['bankAccount' => 'BANK']), 'CLAIM_OWNED_BY_SOURCE', [422]);
        $this->expectRejected($this->postAs('finance', "/api/hr/claims/{$approved->id}/reopen", ['reason' => 'محاولة تعديل']), 'CLAIM_OWNED_BY_SOURCE', [422]);
        $this->assertSame(0, Expense::where('reference', 'like', 'OD-E%')->count());
        // the reviewers' queue holds what waits for a person HERE; the archive of Odoo's claims is under "all"
        $this->assertSame(0, $this->expectOk($this->getAs('finance', '/api/hr/claims?scope=review&source=odoo'))['total']);
        $this->assertSame(5, $this->expectOk($this->getAs('finance', '/api/hr/claims?scope=all&source=odoo'))['total']);

        // a receipt that came from Odoo: its content is the Odoo backup's — honest when it is not archived yet, served when it is
        $receipt = $this->ops(HrFile::class, 801);
        $this->expectRejected($this->getAs('hrlinked', "/api/hr/files/{$receipt->id}"), 'HR_FILE_NOT_ARCHIVED', [404]);
        Storage::fake('local');
        Storage::disk('local')->put('odoo-files/801/801', "%PDF-1.4 archived by the backup\n");
        DB::table('odoo_files')->insert(['attachment_id' => 801, 'res_model' => 'hr.expense', 'res_id' => 701, 'name' => 'file-801.pdf', 'mimetype' => 'application/pdf', 'size' => 31, 'status' => 'stored',
            'disk' => 'local', 'path' => 'odoo-files/801/801', 'stored_at' => now()]);
        $this->assertSame('data:application/pdf;base64,'.base64_encode("%PDF-1.4 archived by the backup\n"), $this->expectOk($this->getAs('hrlinked', "/api/hr/files/{$receipt->id}?as=data"))['dataUrl']);
        $this->expectRejected($this->getAs('emailuser', "/api/hr/files/{$receipt->id}"), 'CLAIM_NOT_YOURS', [403]);
        // a paper attached to the employee in Odoo belongs to the personal file
        $paper = $this->ops(HrFile::class, 803);
        $this->assertSame([$paper->id], array_column($this->expectOk($this->getAs('admin', "/api/hr/employees/{$first->id}"))['files'], 'id'));
        $this->expectRejected($this->getAs('gm', "/api/hr/files/{$paper->id}"), 'HR_PRIVATE', [403]);

        // what is created in OPS works fully beside Odoo's records
        $ours = $this->makeEmployee(['managerId' => $first->id]);
        $this->assertSame('اسم جديد', $this->expectOk($this->patchAs('admin', "/api/hr/employees/{$ours['id']}", ['nameAr' => 'اسم جديد']))['nameAr']);
        $renewal = $this->expectOk($this->postAs('admin', "/api/hr/contracts/{$contract->id}/renew"));
        $this->assertSame([null, false, 'draft'], [$renewal['source'], $renewal['locked'], $renewal['state']]);
        $this->expectOk($this->postAs('admin', "/api/hr/contracts/{$renewal['id']}/cancel", ['reason' => 'تجربة التجديد فقط']));

        // a pass of the projection leaves OPS's own values alone
        $counts = $this->projection()->apply()['counts'];
        $this->assertSame(3, $counts['employees.unchanged']);
        $this->assertSame(3, $counts['personalFiles.unchanged']);
        $kept = EmployeePrivate::where('employee_id', $first->id)->first();
        $this->assertSame(['P-OPS-101', 'ملف مكتمل', 'ملاحظة من العمليات', '6210'], [$kept->passport_no, $kept->notes, $first->refresh()->notes, $travel->refresh()->account_code]);
    }

    public function test_what_changes_in_odoo_arrives_through_the_feed(): void
    {
        $m = fn (int $id) => [$id, 'label'];
        $first = $this->ops(Employee::class, 101);
        $this->assertSame(['changes' => 0, 'counts' => []], $this->projection()->consume());

        // in Odoo: a new title and phone; a new employee who reports to 101; the contract gets an end date; the draft report is approved;
        // the loose expense is put on that report; an expense is deleted; a receipt is deleted; a new receipt is attached
        $data = fn (string $model, int $id) => (array) OdooRecord::where('model', $model)->where('odoo_id', $id)->firstOrFail()->data;
        $this->mirrorRecord('hr.employee', 101, ['job_title' => 'مشرف مستودع', 'work_phone' => '0110000002'] + $data('hr.employee', 101), 'updated');
        $this->mirrorRecord('hr.employee', 104, ['name' => 'Fourth Tester', 'active' => true, 'department_id' => $m(11), 'parent_id' => $m(101), 'work_email' => 'work.four@example.test', 'user_id' => false, 'children' => 0], 'created');
        $this->mirrorRecord('hr.contract', 201, ['date_end' => now()->addDays(20)->toDateString()] + $data('hr.contract', 201), 'updated');
        $this->mirrorRecord('hr.expense.sheet', 603, ['state' => 'approve', 'approval_date' => '2026-09-01 12:00:00', 'total_amount' => 40.0, 'untaxed_amount' => 40.0] + $data('hr.expense.sheet', 603), 'updated');
        $this->mirrorRecord('hr.expense', 705, ['sheet_id' => $m(603), 'state' => 'approved'] + $data('hr.expense', 705), 'updated');
        $this->mirrorRecord('hr.expense', 703, $data('hr.expense', 703), 'deleted', true);
        $this->mirrorRecord('ir.attachment', 801, $data('ir.attachment', 801), 'deleted', true);
        $this->mirrorRecord('ir.attachment', 808, ['name' => 'file-808.pdf', 'res_model' => 'hr.expense', 'res_id' => 705, 'res_field' => false, 'mimetype' => 'application/pdf', 'file_size' => 2000, 'type' => 'binary'], 'created');
        $this->mirrorRecord('ir.attachment', 809, ['name' => 'so.pdf', 'res_model' => 'sale.order', 'res_id' => 1, 'res_field' => false, 'mimetype' => 'application/pdf', 'file_size' => 1, 'type' => 'binary'], 'created');
        $this->assertSame(9, $this->projection()->waiting());

        $out = $this->projection()->consume();
        $this->assertSame(9, $out['changes']);
        $this->assertArrayNotHasKey('failed', $out);
        $this->assertEquals(['employees.updated' => 1, 'personalFiles.unchanged' => 1, 'employees.created' => 1, 'contracts.updated' => 1, 'claims.updated' => 1, 'lines.updated' => 1, 'lines.removed' => 1,
            'files.removed' => 1, 'files.created' => 1], $out['counts']);
        $this->assertSame(0, $this->projection()->waiting());
        $this->assertSame(0, OdooChange::whereNull('processed_at')->count());

        $first->refresh();
        $this->assertSame(['مشرف مستودع', '0110000002', 'ملاحظة من العمليات', $this->userId('hrlinked')], [$first->job_title, $first->work_phone, $first->notes, $first->user_id], 'Odoo\'s values moved; OPS\'s own stayed');
        $this->assertSame($first->id, $this->ops(Employee::class, 104)->manager_id);
        // the contract now ends in twenty days: its alert exists, for hr.private
        $contract = $this->ops(Contract::class, 201);
        $this->assertSame(now()->addDays(20)->toDateString(), $contract->end_date->toDateString());
        $this->assertSame(['d30', true], [($a = HrAlert::where('category', 'contract')->where('ref_id', $contract->id)->where('status', 'open')->first())->stage, $a->is_private]);
        // the report is approved and now holds the expense that used to stand alone; the wrapper claim is gone with nothing lost
        $report = $this->ops(Claim::class, 603);
        $this->assertSame(['approved', 'approve', '40.00', '40.00'], [$report->state, $report->source_state, $report->total, $report->employee_due]);
        $this->assertSame([$report->id], ClaimLine::where('source_system', 'odoo')->where('external_ref', '705')->pluck('claim_id')->all());
        $this->assertNull($this->ops(Claim::class, 'expense:705'));
        $this->assertNull($this->ops(ClaimLine::class, 703), 'an expense deleted in Odoo takes its line with it');
        $this->assertNotNull($this->ops(HrFile::class, 801)->removed_at);
        $this->assertSame($this->ops(ClaimLine::class, 705)->id, $this->ops(HrFile::class, 808)->owner_id);
        $this->assertNull($this->ops(HrFile::class, 809));

        // nothing left to do; a whole pass agrees with what the feed built
        $this->assertSame(['changes' => 0, 'counts' => []], $this->projection()->consume());
        $counts = $this->projection()->apply()['counts'];
        $this->assertSame([], array_filter(array_keys($counts), fn (string $k) => str_ends_with($k, '.created') || str_ends_with($k, '.updated') || str_ends_with($k, '.removed')), json_encode($counts));
    }

    public function test_after_the_hand_over_the_section_is_opss_and_odoos_history_is_never_posted_again(): void
    {
        $this->followOdoo(false);
        $first = $this->ops(Employee::class, 101);
        $this->assertFalse($this->expectOk($this->getAs('admin', '/api/hr/summary'))['odoo']['follows']);
        // everything is editable now
        $renamed = $this->expectOk($this->patchAs('admin', "/api/hr/employees/{$first->id}", ['nameAr' => 'موظف أول — بعد التسليم']));
        $this->assertSame(['موظف أول — بعد التسليم', false, []], [$renamed['nameAr'], $renamed['locked'], $renamed['ownedFields']]);
        $this->assertSame('ID-OPS-101', $this->expectOk($this->putAs('admin', "/api/hr/employees/{$first->id}/private", ['identityNo' => 'ID-OPS-101']))['identityNo']);
        $this->assertSame('قسم المستودعات', $this->expectOk($this->patchAs('admin', '/api/hr/departments/'.$this->ops(Department::class, 11)->id, ['nameAr' => 'قسم المستودعات']))['nameAr']);
        $contract = $this->ops(Contract::class, 201);
        $this->assertSame('6500.00', $this->expectOk($this->patchAs('admin', "/api/hr/contracts/{$contract->id}", ['wage' => 6500]))['wage']);

        // loading the section from Odoo again would overwrite all of that: it is refused; a plan may still be read
        try {
            $this->projection()->apply();
            $this->fail('a load after the hand-over must be refused');
        } catch (AppError $e) {
            $this->assertSame('ODOO_AREA_HANDED_OVER', $e->errorCode);
        }
        $this->assertSame(['changes' => 0, 'counts' => []], $this->projection()->consume());
        $this->assertTrue($this->projection()->plan()['dryRun']);
        $this->assertSame('موظف أول — بعد التسليم', $first->refresh()->name_ar);

        // what Odoo posted and paid stays Odoo's history: it cannot be posted here, and there is no OPS posting to reverse
        $paid = $this->ops(Claim::class, 601);
        $vouchers = Expense::count();
        $this->expectRejected($this->postAs('finance', "/api/hr/claims/{$paid->id}/post", ['bankAccount' => 'BANK']), 'CLAIM_TRANSITION', [422]);
        $this->expectRejected($this->postAs('finance', "/api/hr/claims/{$paid->id}/unpost", ['reason' => 'محاولة عكس ترحيل أودو']), 'CLAIM_BOOKS_ELSEWHERE', [422]);
        $this->assertSame([$vouchers, 'paid', 'odoo'], [Expense::count(), $paid->refresh()->state, $paid->posted_in]);

        // what Odoo approved and never posted is in nobody's books: it carries on here — through the finance vouchers, like any claim
        $approved = $this->ops(Claim::class, 604);
        $this->assertSame(1, $this->expectOk($this->getAs('finance', "/api/hr/claims?scope=review&q={$approved->number}"))['total']);
        $posted = $this->expectOk($this->postAs('finance', "/api/hr/claims/{$approved->id}/post"));
        $this->assertSame(['posted', 'ops', 'accrued', '6210'], [$posted['state'], $posted['postedIn'], $posted['settlement'], $posted['lines'][0]['accountCode']]);
        $this->assertSame(['100.00', 'accrued'], [($v = Expense::where('reference', $approved->number)->first())->total, $v->settlement]);
        // and the report Odoo had approved for the first employee goes through the OPS workflow like any other: taken back, returned, edited by its owner
        $report = $this->ops(Claim::class, 603);
        $this->assertSame('submitted', $this->expectOk($this->postAs('finance', "/api/hr/claims/{$report->id}/reopen", ['reason' => 'يُراجَع بعد التسليم']))['state']);
        $this->assertSame('draft', $this->expectOk($this->postAs('finance', "/api/hr/claims/{$report->id}/return", ['reason' => 'أرفق الإيصال الأصلي']))['state']);
        $this->assertSame('تقرير بعد التسليم', $this->expectOk($this->patchAs('hrlinked', "/api/hr/claims/{$report->id}", ['title' => 'تقرير بعد التسليم']))['title']);
        $this->assertSame('cancelled', $this->expectOk($this->postAs('hrlinked', "/api/hr/claims/{$report->id}/cancel"))['state']);
    }
}
