<?php

namespace Tests\Feature\Hr;

use App\Hr\HrBootstrap;
use App\Hr\Models\ExpenseCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\ApiTestCase;

/** The tables of the section exist after a fresh build, with the master data the migration creates. */
class HrSchemaTest extends ApiTestCase
{
    public function test_the_migration_builds_the_section_and_its_default_expense_categories(): void
    {
        foreach (['hr_departments', 'hr_jobs', 'hr_employees', 'hr_employee_private', 'hr_contracts', 'hr_documents', 'hr_files', 'hr_alerts', 'hr_expense_categories', 'hr_claims', 'hr_claim_lines'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "table {$table} is missing");
        }
        // the personal file is a table of its own: the directory table carries none of its columns
        $this->assertSame([], array_values(array_intersect(Schema::getColumnListing('hr_employees'), ['identity_no', 'passport_no', 'birth_date', 'bank_account', 'home_address', 'wage'])));

        $this->assertGreaterThanOrEqual(8, ExpenseCategory::count());
        // every default category is tied to a postable expense account of the chart, through the account's posting role
        foreach (ExpenseCategory::whereNull('source_system')->get() as $c) {
            $account = DB::table('fin_accounts')->where('code', $c->account_code)->first();
            $this->assertNotNull($account, "category {$c->code} has no account");
            $this->assertSame('expense', $account->type);
            $this->assertTrue((bool) $account->is_postable);
        }
        // idempotent: a second call adds nothing
        $before = ExpenseCategory::count();
        $this->assertSame(['categories' => 0], HrBootstrap::ensure());
        $this->assertSame($before, ExpenseCategory::count());
    }
}
