<?php

namespace Tests\Feature\Commissions;

use App\Commissions\Models\CommissionLine;
use App\Commissions\Models\CommissionPlan;
use App\Commissions\Models\CommissionPlanMember;
use App\Commissions\Models\CommissionPlanRule;
use App\Commissions\Models\CommissionStatement;
use App\Commissions\OdooCommissionsProjection;
use App\Finance\Models\Expense;
use App\Integration\Models\IntExternalRef;
use App\Integration\Odoo\Models\OdooChange;
use App\Integration\Odoo\Models\OdooModel;
use App\Integration\Odoo\Models\OdooRecord;
use App\Integration\Services\ExternalRefs;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\StatusHistory;
use App\Models\User;
use App\Support\AppError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\ApiTestCase;

/**
 * From the copy of Odoo's commission module to «العمولات» (OdooCommissionsProjection). The copy is built here by hand —
 * rows of `odoo_models` / `odoo_records` shaped like the ones the mirror writes, with the fields the real models carry
 * (`sales.commission`, `commission.invoice.line`, `invoice.commission.tracking`) — and Odoo itself is never called.
 * What is proven: the plan counts and writes nothing; the apply gives every record its home once; people and customers
 * are linked by identity or kept by name; who owns what before and after the hand-over; and how a change in Odoo
 * reaches OPS through the feed, whatever the order it arrives in.
 */
class OdooCommissionsProjectionTest extends ApiTestCase
{
    use CommissionFixtures;

    private const MODELS = ['sales.commission', 'commission.invoice.line', 'invoice.commission.tracking'];

    private string $tag;

    /** OPS users the Odoo users are matched to: the manager by e-mail (he can sign in), a salesperson by login, one by an explicit link. */
    private User $manager;

    private User $rep;

    private User $linked;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wipe();
        config(['odoo.enabled' => true, 'odoo.project.commissions' => true]);
        $this->tag = $tag = self::uid().random_int(10, 99);
        $this->manager = User::create(['username' => "cmmgr{$tag}", 'email' => "cm.mgr{$tag}@corp.example", 'password_hash' => Hash::make((string) env('SEED_PASSWORD')), 'name_ar' => 'مدير المبيعات', 'name_en' => 'Sales manager',
            'initials' => 'SM', 'active' => true, 'must_change_password' => false])->refresh();
        DB::table('user_roles')->insert(['user_id' => $this->manager->id, 'role_id' => DB::table('roles')->where('key', 'sales')->value('id')]);   // page.commissions only: his own statements
        $this->rep = User::create(['username' => "cmrep{$tag}", 'password_hash' => 'x', 'name_ar' => 'مندوب أودو', 'name_en' => 'Odoo rep', 'active' => true])->refresh();
        $this->linked = User::create(['username' => "cmlinked{$tag}", 'password_hash' => 'x', 'name_ar' => 'مندوب مربوط', 'name_en' => 'Linked rep', 'active' => true])->refresh();
        $this->customer = $this->customer('odoo');
        $refs = $this->app->make(ExternalRefs::class);
        $refs->link('odoo', 'customer', '900', $this->customer->id, $this->customer->code);   // what the master-data projection did
        $refs->link('odoo', 'user', '30', $this->linked->id, $this->linked->username);         // a person linked by hand

        foreach (self::MODELS as $model) {
            OdooModel::create(['model' => $model, 'status' => 'active', 'full_state' => 'done', 'has_active' => false]);
        }
        // ── Odoo's people and partners (other models of the copy the projection only looks things up in)
        $this->rec('res.users', 17, ['login' => strtoupper("cm.mgr{$tag}@corp.example"), 'partner_id' => [861, 'Manager']]);
        $this->rec('res.users', 8, ['login' => "cmrep{$tag}", 'partner_id' => [698, 'Rep']]);
        $this->rec('res.users', 23, ['login' => "nobody{$tag}@corp.example", 'partner_id' => [967, 'مندوب رابع']]);
        $this->rec('res.users', 30, ['login' => "elsewhere{$tag}@corp.example", 'partner_id' => [968, 'Linked']]);
        $this->rec('res.partner', 900, ['name' => 'مطعم الواحة', 'customer_rank' => 4]);
        $this->rec('res.partner', 901, ['name' => 'فرع الواحة — العليا', 'parent_id' => [900, 'مطعم الواحة']]);
        $this->rec('res.partner', 902, ['name' => 'كافيه بلا ارتباط']);
        // ── the commission module as Odoo holds it
        $calc = fn (int $id, array $more) => $this->rec('sales.commission', $id, $more + ['name' => sprintf('COM/2025/%04d', $id), 'company_id' => [1, 'Company'], 'date_from' => '2024-10-01',
            'commission_type' => 'manager', 'commission_rate' => 7.0, 'salesperson_id' => [17, 'Manager'], 'create_uid' => [2, 'Admin'], 'create_date' => '2025-09-29 08:00:00']);
        $calc(5, ['state' => 'paid', 'date_to' => '2025-06-30', 'total_margin' => 600.0, 'commission_amount' => 42.0]);
        $calc(7, ['state' => 'confirmed', 'date_to' => '2025-07-31', 'total_margin' => 100.1, 'commission_amount' => 7.01]);   // 7.007 → Odoo rounds once, on the whole
        $calc(9, ['state' => 'draft', 'date_from' => '2025-08-01', 'date_to' => '2025-08-31', 'commission_type' => 'individual', 'commission_rate' => 2.5, 'salesperson_id' => [8, 'Rep'], 'total_margin' => 200.0, 'commission_amount' => 5.0]);
        $calc(11, ['state' => 'draft', 'date_from' => '2025-08-01', 'date_to' => '2025-08-31', 'commission_type' => 'individual', 'commission_rate' => 2.5, 'salesperson_id' => [23, 'مندوب رابع'], 'total_margin' => 0.0, 'commission_amount' => 0.0]);
        $line = fn (int $id, int $commission, int $invoice, array $more) => $this->rec('commission.invoice.line', $id, $more + ['commission_id' => [$commission, sprintf('COM/2025/%04d', $commission)],
            'invoice_id' => [$invoice, "INV/2025/{$invoice}"], 'invoice_number' => "INV/2025/{$invoice}", 'invoice_date' => '2025-05-03', 'partner_id' => [900, 'مطعم الواحة'], 'salesperson_id' => [8, 'Rep'],
            'payment_state' => 'paid', 'excluded' => false, 'exclude_reason' => false, 'company_id' => [1, 'Company'], 'create_date' => '2025-09-29 08:05:00']);
        $line(101, 5, 5001, ['amount_total' => 2300.0, 'margin' => 400.0]);
        $line(102, 5, 5002, ['amount_total' => 1150.0, 'margin' => 200.0, 'partner_id' => [901, 'مطعم الواحة, فرع الواحة — العليا'], 'salesperson_id' => [23, 'مندوب رابع']]);
        $line(103, 5, 5003, ['amount_total' => 575.0, 'margin' => 123.45, 'partner_id' => [902, 'كافيه بلا ارتباط'], 'salesperson_id' => [30, 'Linked'], 'excluded' => true, 'exclude_reason' => 'عميل داخلي']);
        $line(104, 7, 5004, ['amount_total' => 115.12, 'margin' => 100.1, 'invoice_date' => '2025-07-09']);
        $line(105, 9, 5005, ['amount_total' => 1380.0, 'margin' => 200.0, 'invoice_date' => '2025-08-14']);
        $line(106, 999, 5006, ['amount_total' => 10.0, 'margin' => 1.0]);   // its calculation is not in the copy
        $track = fn (int $id, int $commission, int $invoice) => $this->rec('invoice.commission.tracking', $id, ['commission_id' => [$commission, sprintf('COM/2025/%04d', $commission)], 'invoice_id' => [$invoice, "INV/2025/{$invoice}"],
            'invoice_number' => "INV/2025/{$invoice}", 'commission_type' => 'manager', 'commission_reference' => sprintf('COM/2025/%04d', $commission), 'salesperson_id' => [17, 'Manager'], 'customer_id' => [900, 'مطعم الواحة'],
            'date_processed' => '2025-10-02 07:29:39', 'amount_total' => 1.0]);
        $track(201, 5, 5001);
        $track(202, 5, 5002);
        $track(203, 5, 5003);
        $track(204, 7, 5004);
        $track(205, 5, 7777);   // Odoo says "processed", and holds no line of that invoice
    }

    protected function tearDown(): void
    {
        $this->wipe();   // what this story projected does not stay for the others
        parent::tearDown();
    }

    /** The copy, and everything that came from Odoo into the section. */
    private function wipe(): void
    {
        foreach (['odoo_changes', 'odoo_records', 'odoo_models'] as $table) {
            DB::table($table)->delete();
        }
        $statements = CommissionStatement::where('source_system', 'odoo')->pluck('id');
        CommissionLine::whereIn('statement_id', $statements)->delete();
        CommissionLine::where('source_system', 'odoo')->delete();
        CommissionStatement::whereIn('id', $statements)->delete();
        $plans = CommissionPlan::where('source_system', 'odoo')->pluck('id');
        CommissionPlanRule::whereIn('plan_id', $plans)->delete();
        CommissionPlanMember::whereIn('plan_id', $plans)->delete();
        CommissionPlan::whereIn('id', $plans)->delete();
        IntExternalRef::where('system', 'odoo')->whereIn('entity', ['customer', 'user'])->delete();
    }

    private function rec(string $model, int $id, array $data): void
    {
        $data += ['write_date' => '2026-08-05 11:22:57'];
        OdooRecord::updateOrCreate(['model' => $model, 'odoo_id' => $id], ['display_name' => is_string($data['name'] ?? null) ? $data['name'] : null, 'write_date' => $data['write_date'], 'active' => null,
            'data' => $data, 'hash' => sha1(json_encode($data)), 'first_seen_at' => now(), 'changed_at' => now(), 'deleted_at' => null]);
    }

    /** What changes in Odoo: the record of the copy is rewritten and the feed says so. */
    private function odooWrites(string $model, int $id, array $values): void
    {
        $current = OdooRecord::where('model', $model)->where('odoo_id', $id)->first();
        $this->rec($model, $id, $values + ['write_date' => '2026-09-10 11:00:00'] + (array) ($current?->data ?? []));
        OdooChange::create(['model' => $model, 'odoo_id' => $id, 'kind' => $current ? 'updated' : 'created', 'seen_at' => now()]);
    }

    private function odooDeletes(string $model, int $id): void
    {
        OdooRecord::where('model', $model)->where('odoo_id', $id)->update(['deleted_at' => now()]);
        OdooChange::create(['model' => $model, 'odoo_id' => $id, 'kind' => 'deleted', 'seen_at' => now()]);
    }

    private function projection(): OdooCommissionsProjection
    {
        return $this->app->make(OdooCommissionsProjection::class);
    }

    private function statement(int $odooId): CommissionStatement
    {
        return CommissionStatement::where('source_system', 'odoo')->where('external_ref', (string) $odooId)->firstOrFail();
    }

    private function line(int $odooId): CommissionLine
    {
        return CommissionLine::where('source_system', 'odoo')->where('external_ref', (string) $odooId)->firstOrFail();
    }

    /** What a first pass over this copy does. */
    private const FIRST_PASS = ['people.matched' => 3, 'people.byName' => 1, 'plans.created' => 2, 'statements.created' => 4, 'lines.created' => 5, 'lines.withCustomer' => 4, 'lines.excluded' => 1,
        'lines.skippedNoStatement' => 1, 'tracking.attached' => 4, 'tracking.skippedNoLine' => 1];

    public function test_the_plan_counts_what_an_apply_would_do_and_writes_nothing(): void
    {
        $users = User::count();
        $audits = AuditLog::count();
        $projection = $this->projection();
        $plan = $projection->plan();
        $this->assertTrue($plan['dryRun']);
        $this->assertEquals(self::FIRST_PASS, $plan['counts']);
        $this->assertArrayNotHasKey('failed', $plan);
        $this->assertSame(['commission.invoice.line #106'], $plan['notes']['lines.skippedNoStatement']);
        $this->assertSame(['invoice.commission.tracking #205'], $plan['notes']['tracking.skippedNoLine']);
        $this->assertSame([0, 0, 0, $users, $audits], [CommissionStatement::where('source_system', 'odoo')->count(), CommissionPlan::where('source_system', 'odoo')->count(), CommissionLine::where('source_system', 'odoo')->count(), User::count(), AuditLog::count()],
            'a plan writes nothing — no record, no service user, no audit row');
        // the sums of the pass: by state, never by person
        $this->assertEquals(['statements.paid.count' => 1, 'statements.paid.commission' => 42.0, 'statements.approved.count' => 1, 'statements.approved.commission' => 7.01, 'statements.draft.count' => 2,
            'statements.draft.commission' => 5.0, 'statements.commission' => 54.01, 'statements.margin' => 900.1, 'lines.count' => 5, 'lines.invoiced' => 5520.12, 'lines.margin' => 900.1, 'lines.commission' => 54.01], $projection->totals());

        // nothing is guessed from half a copy
        OdooModel::where('model', 'commission.invoice.line')->update(['full_state' => 'running']);
        try {
            $projection->plan();
            $this->fail('expected ODOO_COPY_INCOMPLETE');
        } catch (AppError $e) {
            $this->assertSame('ODOO_COPY_INCOMPLETE', $e->errorCode);
        }
    }

    public function test_the_apply_gives_every_record_of_odoo_its_home_once(): void
    {
        $applied = $this->projection()->apply();
        $this->assertFalse($applied['dryRun']);
        $this->assertEquals(self::FIRST_PASS, $applied['counts']);
        $this->assertEquals(['people.matched' => 3, 'people.byName' => 1, 'plans.unchanged' => 2, 'statements.unchanged' => 4, 'lines.unchanged' => 5, 'lines.withCustomer' => 4, 'lines.excluded' => 1,
            'lines.skippedNoStatement' => 1, 'tracking.unchanged' => 4, 'tracking.skippedNoLine' => 1], $this->projection()->apply()['counts'], 'a second pass changes nothing');
        $this->assertSame([4, 2, 5], [CommissionStatement::where('source_system', 'odoo')->count(), CommissionPlan::where('source_system', 'odoo')->count(), CommissionLine::where('source_system', 'odoo')->count()]);
        // a first load that was cut short heals on the next whole pass: no line is "touched" by it, and every statement still says how many lines it holds
        CommissionStatement::where('source_system', 'odoo')->update(['lines_count' => 0]);
        $healed = $this->projection()->apply();
        $this->assertSame([5, 4, 3, 1, 1, 0], [$healed['counts']['lines.unchanged'], $healed['counts']['statements.unchanged'], $this->statement(5)->lines_count, $this->statement(7)->lines_count, $this->statement(9)->lines_count, $this->statement(11)->lines_count]);

        // ── a calculation of Odoo is a statement: Odoo's reference, window, state and totals, as they are
        $paid = $this->statement(5);
        $this->assertStringStartsWith('CMS-', $paid->number);
        $this->assertSame(['COM/2025/0005', 'odoo:5', '2025-06', '2024-10-01', '2025-06-30', 'paid', 'manager', $this->manager->id, null, '17', '600.00', '42.00', '0.00', '42.00', 3, 'Admin'],
            [$paid->reference, $paid->slot, $paid->period, $paid->date_from->toDateString(), $paid->date_to->toDateString(), $paid->status, $paid->earner_type, $paid->beneficiary_id, $paid->beneficiary_name,
                $paid->beneficiary_ref, (string) $paid->base_total, (string) $paid->commission_total, (string) $paid->adjustments_total, (string) $paid->total, $paid->lines_count, $paid->created_by]);
        $this->assertSame('2025-09-29 08:00:00', $paid->created_at->copy()->utc()->format('Y-m-d H:i:s'));
        $this->assertEquals(['odoo', 'manager', 'collected', 'margin', 7.0, 'flat'], [$paid->terms['origin'], $paid->terms['commissionType'], $paid->terms['earnOn'], $paid->terms['base'], $paid->terms['rules'][0]['rate'], $paid->terms['rules'][0]['rateType']]);
        $this->assertSame(['approved', '7.01', 'draft', '5.00', 'salesperson', $this->rep->id], [$this->statement(7)->status, (string) $this->statement(7)->total, $this->statement(9)->status, (string) $this->statement(9)->total,
            $this->statement(9)->earner_type, $this->statement(9)->beneficiary_id], 'confirmed in Odoo = approved here');
        $unknown = $this->statement(11);
        $this->assertSame([null, 'مندوب رابع', '23', 0], [$unknown->beneficiary_id, $unknown->beneficiary_name, $unknown->beneficiary_ref, $unknown->lines_count], 'a person OPS does not know is kept by the name Odoo gives');

        // ── the rule a calculation carries is a plan: once per rule, a draft OPS can take over
        $managers = CommissionPlan::with(['rules', 'members'])->findOrFail($paid->plan_id);
        $this->assertSame(['odoo', 'manager:7:17', 'draft', 'manager', $this->manager->id, true, 'collected', 'margin', false, '2024-10-01', null, $this->statement(7)->plan_id],
            [$managers->source_system, $managers->external_ref, $managers->status, $managers->earner_type, $managers->beneficiary_id, $managers->everyone, $managers->earn_on, $managers->base_kind, $managers->net_of_returns,
                $managers->valid_from->toDateString(), $managers->valid_to, $managers->id]);
        $this->assertEquals([['all', 'flat', 7.0]], $managers->rules->map(fn ($r) => [$r->scope, $r->rate_type, $r->rate])->all());
        $individual = CommissionPlan::with(['rules', 'members'])->findOrFail($this->statement(9)->plan_id);
        $this->assertSame(['individual:2.5', 'salesperson', false, $unknown->plan_id], [$individual->external_ref, $individual->earner_type, $individual->everyone, $individual->id]);
        $this->assertEqualsCanonicalizing([[$this->rep->id, null, '8'], [null, 'مندوب رابع', '23']], $individual->members->map(fn ($m) => [$m->user_id, $m->person_name, $m->person_ref])->all());

        // ── an invoice of a calculation is a line: base = the margin, rate = the calculation's, the commission unrounded
        $line = $this->line(101);
        $this->assertSame([$paid->id, 'invoice', $this->manager->id, $paid->plan_id, 'OdooInvoice', '5001', 'INV/2025/5001', '2025-05-03', $this->customer->id, 'مطعم الواحة', '900', $this->rep->id, null, '8', 'odoo',
            '2300.00', '400.00', 7.0, '28.0000', false, 'paid', '201'],
            [$line->statement_id, $line->kind, $line->beneficiary_id, $line->plan_id, $line->source_type, $line->source_id, $line->doc_number, $line->doc_date->toDateString(), $line->customer_id, $line->customer_name, $line->customer_ref,
                $line->salesperson_id, $line->salesperson_name, $line->salesperson_ref, $line->salesperson_via, (string) $line->invoice_total, (string) $line->base_amount, $line->rate, (string) $line->commission, $line->excluded,
                $line->payment_state, $line->tracking_ref]);
        $this->assertSame('2025-10-02 07:29:39', $line->processed_at->copy()->utc()->format('Y-m-d H:i:s'), 'the tracking record is the line\'s "processed at"');
        $branch = $this->line(102);
        $this->assertSame([$this->customer->id, '901', null, 'مندوب رابع', '23', '14.0000'], [$branch->customer_id, $branch->customer_ref, $branch->salesperson_id, $branch->salesperson_name, $branch->salesperson_ref, (string) $branch->commission],
            'a branch of a company is the company\'s customer');
        $excluded = $this->line(103);
        $this->assertSame([true, 'عميل داخلي', '0.0000', '123.45', null, 'كافيه بلا ارتباط', $this->linked->id, '203'],
            [$excluded->excluded, $excluded->exclude_reason, (string) $excluded->commission, (string) $excluded->base_amount, $excluded->customer_id, $excluded->customer_name, $excluded->salesperson_id, $excluded->tracking_ref]);
        $this->assertSame(['7.0070', '7.01'], [(string) $this->line(104)->commission, (string) $this->statement(7)->commission_total], 'the lines add up to what Odoo rounded once');
        $this->assertSame(0, CommissionLine::where('source_system', 'odoo')->where('external_ref', '106')->count());
        $this->assertSame(4, AuditLog::where('action', 'COMMISSION.STATEMENT.CREATE')->where('username', 'svc.odoo')->whereIn('entity_id', CommissionStatement::where('source_system', 'odoo')->pluck('id'))->count());
        $this->assertSame(0, AuditLog::where('action', 'like', 'COMMISSION.LINE%')->whereIn('entity_id', CommissionLine::where('source_system', 'odoo')->pluck('id'))->count(), 'the history of lines is loaded without an audit row each');

        // ── in the section: the history reads like any statement, to those who may see it
        $list = $this->expectOk($this->getAs('finance', self::API.'/statements?source=odoo&sort=period&order=asc'));
        $this->assertSame([4, ['2025-06', '2025-07', '2025-08', '2025-08'], '54.01'], [$list['total'], array_column($list['items'], 'period'), $list['totals']['total']]);
        $detail = $this->expectOk($this->getAs('finance', self::API."/statements/{$paid->number}"));
        $this->assertSame(['odoo', '5', true, 'COM/2025/0005', $this->manager->username, $managers->code, '2025-10-02T07:29:39+00:00', null],
            [$detail['sourceSystem'], $detail['externalRef'], $detail['owned'], $detail['reference'], $detail['beneficiary']['username'], $detail['plan']['code'], gmdate('Y-m-d\TH:i:sP', strtotime($detail['processedAt'])), $detail['payout']['expense']]);
        $lines = $this->expectOk($this->getAs('finance', self::API."/statements/{$paid->number}/lines"));
        $this->assertSame([3, ['base' => '600.00', 'commission' => '42.0000', 'invoiced' => '4025.00']], [$lines['total'], $lines['totals']]);
        $this->assertSame([['INV/2025/5001', $this->customer->code, false], ['INV/2025/5002', $this->customer->code, true], ['INV/2025/5003', null, false]],
            array_map(fn (array $l) => [$l['docNumber'], $l['customer']['code'], $l['salesperson']['external']], $lines['items']));
        // "was this invoice paid a commission for — in which statement, to whom?"
        $found = $this->expectOk($this->getAs('finance', self::API.'/lines?q='.urlencode('INV/2025/5004')));
        $this->assertSame([1, $this->statement(7)->number, 'approved', $this->manager->id], [$found['total'], $found['items'][0]['statement']['number'], $found['items'][0]['statement']['status'], $found['items'][0]['statement']['beneficiary']['id']]);
        // the manager Odoo names has an OPS login: his history is in his own view, and only his
        $own = $this->expectOk($this->getAs($this->manager->username, self::API.'/statements'));
        $this->assertSame([2, ['approved', 'paid'], [true, true]], [$own['total'], array_column($own['items'], 'status'), array_column($own['items'], 'mine')]);
        $this->expectRejected($this->getAs($this->manager->username, self::API.'/statements/'.$this->statement(9)->number), 'COMMISSION_STATEMENT_NOT_FOUND', [404]);
        $summary = $this->expectOk($this->getAs('finance', self::API.'/summary?source=odoo'));
        $this->assertSame([['count' => 2, 'amount' => '5.00'], ['count' => 1, 'amount' => '7.01'], ['count' => 1, 'amount' => '42.00'], '54.01', 3, true],
            [$summary['totals']['pending'], $summary['totals']['approved'], $summary['totals']['paid'], $summary['totals']['total'], count($summary['byBeneficiary']), $summary['following']]);
        $this->assertContains('name:مندوب رابع', array_map(fn (array $p) => $p['beneficiary']['key'], $summary['byBeneficiary']));
    }

    public function test_what_came_from_odoo_is_odoos_until_the_section_is_handed_over(): void
    {
        $this->projection()->apply();
        $draft = $this->statement(9);
        $url = self::API."/statements/{$draft->number}";
        $plan = CommissionPlan::findOrFail($draft->plan_id);
        $line = $this->line(105);
        $expenses = Expense::count();

        // ── while the section follows Odoo: read everything, change nothing
        $detail = $this->expectOk($this->getAs('finance', $url));
        $this->assertSame([true, []], [$detail['owned'], array_keys(array_filter($detail['actions'], fn (array $a) => $a['allowed']))]);
        $this->assertSame(['COMMISSION_OWNED_BY_SOURCE'], array_values(array_unique(array_column($detail['actions'], 'code'))));
        foreach (['/adjustments' => ['amount' => 10, 'reason' => 'تسوية على كشف أودو'], '/submit' => [], '/recalculate' => []] as $step => $body) {
            $this->expectRejected($this->postAs('finance', $url.$step, $body), 'COMMISSION_OWNED_BY_SOURCE', [422]);
        }
        $this->expectRejected($this->postAs('finance', self::API."/lines/{$line->id}/exclude", ['reason' => 'استبعاد من العمليات']), 'COMMISSION_OWNED_BY_SOURCE', [422]);
        $this->expectRejected($this->postAs('gm', self::API.'/statements/'.$this->statement(7)->number.'/payout', ['account' => '6210', 'bankAccount' => 'BANK', 'reference' => 'TRF-ODOO']), 'COMMISSION_OWNED_BY_SOURCE', [422]);
        $this->expectRejected($this->postAs('gm', self::API.'/statements/'.$this->statement(5)->number.'/void-payout', ['reason' => 'عكس صرف أودو']), 'COMMISSION_OWNED_BY_SOURCE', [422]);
        $this->expectRejected($this->postAs('finance', self::API."/plans/{$plan->code}/activate"), 'COMMISSION_OWNED_BY_SOURCE', [422]);
        $this->expectRejected($this->patchAs('finance', self::API."/plans/{$plan->code}", $this->planBody(['members' => [$this->rep->id]])), 'COMMISSION_OWNED_BY_SOURCE', [422]);
        $this->assertSame(['draft', '5.00', 'draft', $expenses], [$draft->refresh()->status, (string) $draft->total, $plan->refresh()->status, Expense::count()]);
        // a copy of Odoo's plan is OPS's own: that is how the rule is put to work here before the hand-over
        $copy = $this->expectOk($this->postAs('finance', self::API."/plans/{$plan->code}/duplicate"));
        $this->assertEquals([null, false, 'draft', 'collected', 'margin', 2.5], [$copy['sourceSystem'], $copy['owned'], $copy['status'], $copy['earnOn'], $copy['base'], $copy['rules'][0]['rate']]);
        $this->assertEqualsCanonicalizing([[$this->rep->username, false], [null, true]], array_map(fn (array $m) => [$m['username'], $m['external']], $copy['members']), 'the person OPS does not know stays on the copy by name, for a person to replace');
        $this->assertTrue($this->expectOk($this->getAs('finance', self::API."/plans/{$plan->code}"))['owned']);
        // a calculation of OPS never reaches into the history
        $this->expectRejected($this->postAs('finance', self::API.'/calculate', ['period' => '2025-08']), 'COMMISSION_NO_ACTIVE_PLAN', [422]);

        // ── handed over: everything is worked here
        config(['odoo.project.commissions' => false]);
        $this->assertFalse($this->expectOk($this->getAs('finance', $url))['owned']);
        $adjusted = $this->expectOk($this->postAs('finance', "{$url}/adjustments", ['amount' => 10, 'reason' => 'مكافأة أُقرت بعد التسليم']));
        $this->assertSame(['5.00', '10.00', '15.00'], [$adjusted['commissionTotal'], $adjusted['adjustmentsTotal'], $adjusted['total']], 'Odoo\'s own figure stays, what OPS adds is on top');
        $this->assertSame('10.00', $this->expectOk($this->postAs('finance', self::API."/lines/{$line->id}/exclude", ['reason' => 'فاتورة لعميل داخلي']))['total']);
        $this->assertSame(['15.00', '5.0000'], [$this->expectOk($this->postAs('finance', self::API."/lines/{$line->id}/include"))['total'], (string) $line->refresh()->commission]);
        $this->expectRejected($this->postAs('finance', "{$url}/recalculate"), 'COMMISSION_STATEMENT_STATE', [422]);   // its lines are Odoo's invoices, not OPS's
        $this->assertSame('submitted', $this->expectOk($this->postAs('finance', "{$url}/submit"))['status']);
        $this->assertSame('approved', $this->expectOk($this->postAs('gm', "{$url}/approve"))['status']);
        $paid = $this->expectOk($this->postAs('gm', "{$url}/payout", ['account' => '6210', 'bankAccount' => 'BANK', 'reference' => 'TRF-HANDOVER']));
        $this->assertSame(['paid', '15.00'], [$paid['status'], (string) Expense::findOrFail($paid['payout']['expense']['id'])->total]);
        // what Odoo paid stays paid there: its payout is not reversed in OPS's books
        $this->expectRejected($this->postAs('gm', self::API.'/statements/'.$this->statement(5)->number.'/void-payout', ['reason' => 'عكس صرف تم في أودو']), 'COMMISSION_PAID_ELSEWHERE', [422]);
        $this->assertSame('active', $this->expectOk($this->postAs('finance', self::API."/plans/{$plan->code}/activate"))['status'], 'Odoo\'s plan itself can be put to work now');
        $this->expectOk($this->postAs('finance', self::API."/plans/{$plan->code}/archive"));
    }

    public function test_what_changes_in_odoo_reaches_the_section_through_the_feed(): void
    {
        $projection = $this->projection();
        $projection->apply();
        $this->assertSame(0, $projection->waiting());

        // ── in Odoo: a calculation is paid; an invoice is excluded; a rate is corrected; a line is removed; a calculation is deleted;
        //    and a new calculation arrives — its tracking record first, then its line, then the calculation itself
        $this->odooWrites('sales.commission', 7, ['state' => 'paid']);
        $this->odooWrites('commission.invoice.line', 105, ['excluded' => true, 'exclude_reason' => 'فاتورة مرتجعة']);
        $this->odooWrites('sales.commission', 9, ['commission_rate' => 3.0, 'total_margin' => 0.0, 'commission_amount' => 0.0]);
        $this->odooDeletes('commission.invoice.line', 102);
        $this->odooWrites('sales.commission', 5, ['total_margin' => 400.0, 'commission_amount' => 28.0]);
        $this->odooDeletes('sales.commission', 11);
        $this->odooWrites('invoice.commission.tracking', 206, ['commission_id' => [13, 'COM/2025/0013'], 'invoice_id' => [5007, 'INV/2025/5007'], 'date_processed' => '2026-09-09 10:00:00', 'commission_type' => 'manager']);
        $this->odooWrites('commission.invoice.line', 107, ['commission_id' => [13, 'COM/2025/0013'], 'invoice_id' => [5007, 'INV/2025/5007'], 'invoice_number' => 'INV/2025/5007', 'invoice_date' => '2025-09-02',
            'partner_id' => [900, 'مطعم الواحة'], 'salesperson_id' => [8, 'Rep'], 'amount_total' => 230.0, 'margin' => 50.0, 'payment_state' => 'paid', 'excluded' => false, 'exclude_reason' => false]);
        $this->odooWrites('sales.commission', 13, ['name' => 'COM/2025/0013', 'state' => 'confirmed', 'date_from' => '2024-10-01', 'date_to' => '2025-09-30', 'commission_type' => 'manager', 'commission_rate' => 7.0,
            'salesperson_id' => [17, 'Manager'], 'total_margin' => 50.0, 'commission_amount' => 3.5, 'create_date' => '2026-09-09 09:00:00']);
        $this->assertSame(9, $projection->waiting());

        $run = $this->projection()->consume();
        $this->assertSame(9, $run['changes']);
        $this->assertArrayNotHasKey('failed', $run);
        $this->assertEquals(['statements.updated' => 3, 'statements.archived' => 1, 'statements.created' => 1, 'statements.unchanged' => 1, 'plans.unchanged' => 2, 'plans.created' => 1,
            'lines.updated' => 1, 'lines.archived' => 1, 'lines.created' => 1, 'lines.unchanged' => 1, 'lines.withCustomer' => 3, 'lines.excluded' => 1, 'tracking.attached' => 1, 'people.matched' => 2, 'people.byName' => 1], $run['counts']);
        $this->assertSame([0, 0], [$projection->waiting(), $this->projection()->consume()['changes']]);

        // the state moved, with its line in the statement's history
        $seven = $this->statement(7);
        $this->assertSame(['paid', ['paid']], [$seven->status, StatusHistory::where('entity_type', 'CommissionStatement')->where('entity_id', $seven->id)->pluck('to_status')->all()]);
        // the invoice Odoo excluded earns nothing here either; the corrected rate reached the statement and its lines; its rule is another plan now
        $nine = $this->statement(9);
        $excluded = $this->line(105);
        $this->assertEquals([true, 'فاتورة مرتجعة', 0.0, 3.0, '0.00', 3.0, 'individual:3'],
            [$excluded->excluded, $excluded->exclude_reason, (float) $excluded->commission, $excluded->rate, (string) $nine->total, $nine->terms['rules'][0]['rate'], CommissionPlan::findOrFail($nine->plan_id)->external_ref]);
        $this->assertSame($nine->plan_id, $excluded->plan_id);
        // a line removed in Odoo is kept, struck out, and counted no more; a deleted calculation keeps its statement, marked
        $gone = $this->line(102);
        $five = $this->statement(5);
        $this->assertSame(['odoo', 'حُذف السطر في أودو', '0.0000', 2, '28.00'], [$gone->voided_by, $gone->void_reason, (string) $gone->commission, $five->lines_count, (string) $five->total]);
        $this->assertNotNull($gone->voided_at);
        $listed = $this->expectOk($this->getAs('finance', self::API."/statements/{$five->number}/lines"));
        $this->assertSame([3, '28.0000', '400.00'], [$listed['total'], $listed['totals']['commission'], (string) $five->base_total], 'the removed line is still listed; it no longer counts');
        $this->assertNotNull(collect($listed['items'])->firstWhere('docNumber', 'INV/2025/5002')['voided']);
        $this->assertNotNull($this->statement(11)->source_deleted_at);
        // the new calculation: made when its tracking record arrived, complete whatever the order
        $new = $this->statement(13);
        $line = $this->line(107);
        $this->assertSame(['approved', 'COM/2025/0013', '2025-09', '3.50', 1, $this->manager->id, $new->id, '3.5000', '206', '5007'],
            [$new->status, $new->reference, $new->period, (string) $new->total, $new->lines_count, $new->beneficiary_id, $line->statement_id, (string) $line->commission, $line->tracking_ref, $line->source_id]);
        $this->assertSame($this->statement(5)->plan_id, $new->plan_id, 'the same rule is the same plan');

        // ── a person Odoo names gets an OPS user later: the next whole pass attaches his history to him
        $late = User::create(['username' => "nobody{$this->tag}@corp.example", 'password_hash' => 'x', 'name_ar' => 'مندوب رابع', 'name_en' => 'Fourth rep', 'active' => true])->refresh();
        $applied = $this->projection()->apply();
        $this->assertSame([4, 1], [$applied['counts']['people.matched'], $applied['counts']['statements.updated']]);
        $this->assertArrayNotHasKey('people.byName', $applied['counts']);
        $this->assertSame([$late->id, null], [$this->statement(11)->beneficiary_id, $this->statement(11)->beneficiary_name]);
        $this->assertSame([$late->id, null], [$this->line(102)->salesperson_id, $this->line(102)->salesperson_name]);
    }
}
