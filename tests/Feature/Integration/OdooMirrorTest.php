<?php

namespace Tests\Feature\Integration;

use App\Integration\Odoo\Models\OdooChange;
use App\Integration\Odoo\Models\OdooModel;
use App\Integration\Odoo\Models\OdooRecord;
use App\Integration\Odoo\Models\OdooRun;
use App\Integration\Odoo\OdooClient;
use App\Integration\Odoo\OdooError;
use App\Integration\Odoo\OdooSync;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\ApiTestCase;

/**
 * The Odoo → OPS live mirror (docs/odoo/ARCHITECTURE.ar.md) against an Odoo that lives in memory (FakeOdoo): the
 * connector only reads; the first copy, the changes, the deletions and the healing of the copy; what is left out and
 * why; faults that stay with one model; and what people see of it.
 */
class OdooMirrorTest extends ApiTestCase
{
    private FakeOdoo $odoo;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['odoo_runs', 'odoo_changes', 'odoo_records', 'odoo_models'] as $table) {
            DB::table($table)->delete();
        }
        Cache::flush();
        config(['odoo' => FakeOdoo::config()]);
        $this->odoo = (new FakeOdoo)->install();
    }

    private function sync(?array $only = null, int $seconds = 30): array
    {
        return $this->app->make(OdooSync::class)->run($seconds, 'test', $only);
    }

    /** A small company: partners (archivable), orders, a product model with fields that must never be copied. */
    private function company(): void
    {
        $this->odoo->model('res.partner', ['name' => 'char', 'active' => 'boolean', 'phone' => 'char', 'signup_token' => 'char', 'image_1920' => 'binary',
            'invoice_ids' => ['type' => 'one2many', 'relation' => 'account.move'], 'category_id' => ['type' => 'many2many', 'relation' => 'res.partner.category'],
            'credit' => ['type' => 'monetary', 'store' => false]], 'Contact');
        $this->odoo->model('sale.order', ['name' => 'char', 'state' => 'selection', 'partner_id' => ['type' => 'many2one', 'relation' => 'res.partner'],
            'amount_total' => 'monetary', 'access_token' => 'char'], 'Sales Order');
        $this->odoo->model('ir.cron', ['name' => 'char'], 'Scheduled Actions');
        $this->odoo->model('sale.report', ['name' => 'char'], 'Sales Analysis', logAccess: false);
        $this->odoo->model('base.language.install', ['name' => 'char'], 'Install Language', transient: true);
        foreach (['شركة الأفق', 'مطعم الريف', 'بقالة النور', 'فندق السلام', 'مخبز الفجر'] as $name) {
            $this->odoo->create('res.partner', ['name' => $name, 'phone' => '0500000000', 'signup_token' => 'SECRET-TOKEN', 'image_1920' => 'AAAA', 'invoice_ids' => [1, 2], 'category_id' => [3]]);
        }
        foreach (range(1, 7) as $n) {
            $this->odoo->create('sale.order', ['name' => "S0000{$n}", 'state' => 'sale', 'partner_id' => [($n % 5) + 1, 'x'], 'amount_total' => 100.0 * $n, 'access_token' => 'PORTAL-TOKEN']);
        }
    }

    public function test_the_connector_only_reads(): void
    {
        $this->company();
        $client = $this->app->make(OdooClient::class);
        foreach (['create', 'write', 'unlink', 'action_confirm', 'copy', 'toggle_active', 'message_post', 'execute'] as $method) {
            try {
                $client->call('sale.order', $method, ['ids' => [1], 'vals' => ['state' => 'cancel']]);
                $this->fail("`{$method}` must be refused");
            } catch (\LogicException $e) {
                $this->assertStringContainsString('read-only', $e->getMessage());
            }
        }
        Http::assertNothingSent(); // refused before a single byte left OPS
        $this->assertSame(7, $client->searchCount('sale.order'));
        $this->assertSame([], $this->odoo->writes);

        // a wrong key is an authentication fault, and the key never shows in a message
        config(['odoo.key' => 'wrong-key-value']);
        Cache::flush();
        try {
            (new OdooClient)->searchCount('sale.order');
            $this->fail('a wrong key must be refused');
        } catch (OdooError $e) {
            $this->assertSame('auth', $e->kind);
            $this->assertStringNotContainsString('wrong-key-value', $e->getMessage());
        }
        // plain http to a real host is refused before anything is sent
        try {
            (new OdooClient(FakeOdoo::config(['url' => 'http://erp.example.com'])))->searchCount('sale.order');
            $this->fail('http must be refused');
        } catch (OdooError $e) {
            $this->assertSame('auth', $e->kind);
        }
    }

    public function test_first_copy_registers_the_models_and_copies_what_may_be_copied(): void
    {
        $this->company();
        $out = $this->sync();
        $this->assertTrue($out['ran']);
        $this->assertSame(0, $out['errors']);
        $this->assertSame(['toInspect' => 0, 'firstCopy' => 0, 'windows' => 0], $out['pending']);

        // which models are mirrored, and why the others are not
        $models = OdooModel::all()->keyBy('model');
        $this->assertSame(['active', 'active', 'skipped', 'skipped'], [$models['res.partner']->status, $models['sale.order']->status, $models['ir.cron']->status, $models['sale.report']->status]);
        $this->assertStringContainsString('technical', $models['ir.cron']->reason);
        $this->assertStringContainsString('write_date', $models['sale.report']->reason);
        $this->assertFalse($models->has('base.language.install'), 'wizards are not even registered');
        $this->assertTrue($models['sale.order']->core() && $models['res.partner']->core());

        // what is never copied: credentials, binary content, one2many id lists, values Odoo does not store
        $partner = $models['res.partner'];
        $this->assertSame(['active', 'category_id', 'display_name', 'name', 'phone', 'write_date'], collect($partner->fields)->keys()->sort()->values()->all());
        $this->assertSame(['credit' => 'computed', 'image_1920' => 'binary', 'invoice_ids' => 'one2many', 'signup_token' => 'credential'], collect($partner->skipped_fields)->sortKeys()->all());
        $this->assertSame(['access_token' => 'credential'], $models['sale.order']->skipped_fields);
        $this->assertStringNotContainsString('TOKEN', json_encode(OdooRecord::pluck('data')));

        // the copy
        $this->assertSame([5, 7], [OdooRecord::where('model', 'res.partner')->count(), OdooRecord::where('model', 'sale.order')->count()]);
        $this->assertSame(['done', 5, 5], [$partner->full_state, $partner->mirrored_count, $partner->remote_count]);
        $r = OdooRecord::where('model', 'sale.order')->where('odoo_id', 3)->firstOrFail();
        $this->assertSame(['S00003', 300.0, [4, 'x'], 'sale'], [$r->display_name, (float) $r->data['amount_total'], $r->data['partner_id'], $r->data['state']]);
        $this->assertTrue(OdooRecord::where('model', 'res.partner')->where('odoo_id', 1)->value('active'));
        $this->assertSame(0, OdooChange::count(), 'a first copy is not a stream of changes');
        $this->assertSame($this->odoo->models['sale.order']['rows'][7]['write_date'], $models['sale.order']->cursor_write_date, 'the cursor starts at the newest write the copy began with');
        $this->assertSame([], $this->odoo->writes);

        // a second run with nothing new finds nothing, writes nothing
        $again = $this->sync();
        $this->assertSame([0, 0, 0], [$again['created'], $again['updated'], $again['deleted']]);
        $this->assertSame(0, OdooChange::count());
    }

    public function test_the_copy_is_complete_and_peoples_files_are_shown_only_to_who_may_read_them(): void
    {
        // the default: the copy is the company's backup, so it holds people's files, the conversation on every document
        // and the values Odoo keeps per company — and reading people's files needs `odoo.private`
        $m2o = fn (string $relation) => ['type' => 'many2one', 'relation' => $relation];
        $this->odoo->model('hr.employee', ['name' => 'char', 'job_title' => 'char', 'pin' => 'char', 'private_street' => 'char', 'identification_id' => 'char'], 'Employee');
        $this->odoo->model('hr.payslip', ['name' => 'char', 'net_wage' => 'monetary'], 'Pay Slip');
        $this->odoo->model('mail.message', ['body' => 'html', 'model' => 'char', 'res_id' => 'integer'], 'Message');
        $this->odoo->model('mail.followers', ['res_id' => 'integer'], 'Followers');
        $this->odoo->model('product.product', ['name' => 'char', 'standard_price' => ['type' => 'float', 'store' => false, 'company_dependent' => true],
            'qty_available' => ['type' => 'float', 'store' => false]], 'Product');
        $this->odoo->create('hr.employee', ['name' => 'سائق أول', 'job_title' => 'سائق', 'pin' => 'PIN-4321', 'private_street' => 'HOME-STREET', 'identification_id' => 'ID-1090']);
        $this->odoo->create('hr.payslip', ['name' => 'SLIP/001', 'net_wage' => 91234.5]);
        $this->odoo->create('mail.message', ['body' => '<p>تم الاتفاق على السعر</p>', 'model' => 'sale.order', 'res_id' => 3]);
        $this->odoo->create('mail.followers', ['res_id' => 3]);
        $this->odoo->create('product.product', ['name' => 'أرز', 'standard_price' => 42.5, 'qty_available' => 7.0]);

        $this->assertSame(0, $this->sync()['errors']);
        $models = OdooModel::all()->keyBy('model');
        $this->assertSame(['active', 'active', 'active', 'active', 'skipped'], array_map(fn ($m) => $models[$m]->status, ['hr.employee', 'hr.payslip', 'mail.message', 'product.product', 'mail.followers']));
        $this->assertSame(1, OdooRecord::where('model', 'hr.payslip')->count());
        // a key is never data: the attendance PIN stays out whatever the rules are
        $employee = OdooRecord::where('model', 'hr.employee')->firstOrFail();
        $this->assertSame(['HOME-STREET', false], [$employee->data['private_street'], array_key_exists('pin', $employee->data)]);
        // a value kept per company is data; a value Odoo works out when asked is not, and the copy says why it is absent
        $product = OdooRecord::where('model', 'product.product')->firstOrFail();
        $this->assertSame([42.5, false], [(float) $product->data['standard_price'], array_key_exists('qty_available', $product->data)]);
        $this->assertSame('computed', $models['product.product']->skipped_fields['qty_available']);

        // who may open the tower but not people's files: no payslips, no correspondence, an employee without the personal part
        $this->expectRejected($this->getAs('gm', '/api/integration/odoo/records?model=hr.payslip'), 'ODOO_PRIVATE', [403]);
        $this->expectRejected($this->getAs('gm', '/api/integration/odoo/records/hr.payslip/1'), 'ODOO_PRIVATE', [403]);
        $this->expectRejected($this->getAs('gm', '/api/integration/odoo/records?model=mail.message'), 'ODOO_PRIVATE', [403]);
        $this->expectRejected($this->getAs('gm', '/api/integration/odoo/changes?model=hr.payslip'), 'ODOO_PRIVATE', [403]);
        $seen = $this->expectOk($this->getAs('gm', '/api/integration/odoo/records/hr.employee/1'));
        $this->assertSame(['سائق', false, false], [$seen['data']['job_title'], array_key_exists('private_street', $seen['data']), array_key_exists('identification_id', $seen['data'])]);
        $panel = $this->expectOk($this->getAs('gm', '/api/integration/odoo/models/hr.payslip'));
        $this->assertSame([true, false, []], [$panel['restricted'], $panel['readable'], $panel['recentChanges']]);
        // the feed of everything leaves them out for him, and shows them to who may read them
        $this->odoo->write('hr.payslip', 1, ['net_wage' => 95000.0]);
        $this->odoo->write('product.product', 1, ['standard_price' => 44.0]);
        $this->sync();
        $this->assertSame(['product.product'], array_column($this->expectOk($this->getAs('gm', '/api/integration/odoo/changes'))['items'], 'model'));
        $this->assertEqualsCanonicalizing(['hr.payslip', 'product.product'], array_column($this->expectOk($this->getAs('admin', '/api/integration/odoo/changes'))['items'], 'model'));
        $full = $this->expectOk($this->getAs('admin', '/api/integration/odoo/records/hr.employee/1'));
        $this->assertSame('HOME-STREET', $full['data']['private_street']);
        $this->assertSame(1, $this->expectOk($this->getAs('admin', '/api/integration/odoo/records?model=hr.payslip'))['total']);

        // Odoo gained a field (or the rules changed): the records are read again in place, nothing is dropped meanwhile
        $this->odoo->models['product.product']['fields']['barcode'] = ['type' => 'char', 'string' => 'barcode', 'store' => true];
        $this->odoo->models['product.product']['rows'][1]['barcode'] = '6281000000017';
        $sync = $this->app->make(OdooSync::class);
        $this->assertFalse($sync->reinspect(OdooModel::where('model', 'hr.payslip')->firstOrFail()), 'nothing changed for this model');
        $this->assertTrue($sync->reinspect(OdooModel::where('model', 'product.product')->firstOrFail()));
        $this->assertSame(1, OdooRecord::where('model', 'product.product')->count(), 'the copy stays readable while it is read again');
        $feed = OdooChange::count();
        $this->sync();
        $again = OdooRecord::where('model', 'product.product')->firstOrFail();
        $this->assertSame(['6281000000017', 44.0, 'done'], [$again->data['barcode'], (float) $again->data['standard_price'], OdooModel::where('model', 'product.product')->value('full_state')]);
        $this->assertSame($feed, OdooChange::count(), 'reading the copy again is not something that happened in Odoo');
        $this->assertSame([], $this->odoo->writes);
    }

    public function test_peoples_files_are_not_copied_unless_a_person_decides_so(): void
    {
        // a deployment that must not hold people's files at all
        config(['odoo.copy_private' => false]);
        $m2o = fn (string $relation) => ['type' => 'many2one', 'relation' => $relation];
        $this->odoo->model('hr.employee', ['name' => 'char', 'active' => 'boolean', 'job_title' => 'char', 'work_email' => 'char', 'department_id' => $m2o('hr.department'),
            'pin' => 'char', 'private_street' => 'char', 'private_phone' => 'char', 'identification_id' => 'char', 'birthday' => 'date', 'marital' => 'selection',
            'bank_account_id' => $m2o('res.partner.bank'), 'emergency_contact' => 'char', 'country_id' => $m2o('res.country')], 'Employee');
        $this->odoo->model('hr.department', ['name' => 'char'], 'Department');
        $this->odoo->model('hr.payslip', ['name' => 'char', 'employee_id' => $m2o('hr.employee'), 'net_wage' => 'monetary'], 'Pay Slip');
        $this->odoo->model('hr.contract', ['name' => 'char', 'wage' => 'monetary'], 'Contract');
        $this->odoo->model('res.partner.bank', ['acc_number' => 'char'], 'Bank Accounts');
        $this->odoo->model('attendance.sheet', ['name' => 'char'], 'Hr Attendance Sheet');
        $this->odoo->model('penalty.request', ['name' => 'char'], 'Penalty Request');
        $this->odoo->create('attendance.sheet', ['name' => 'SHEET-NOV']);
        $this->odoo->create('penalty.request', ['name' => 'PENALTY-1']);
        $this->odoo->model('fleet.vehicle', ['name' => 'char'], 'Vehicle');
        $this->odoo->create('hr.department', ['name' => 'المستودع']);
        $this->odoo->create('hr.employee', ['name' => 'سائق أول', 'job_title' => 'سائق', 'work_email' => 'd1@co.example', 'department_id' => [1, 'x'], 'pin' => 'PIN-4321',
            'private_street' => 'HOME-STREET', 'private_phone' => 'HOME-PHONE', 'identification_id' => 'ID-1090', 'birthday' => '1990-01-01', 'marital' => 'married',
            'bank_account_id' => [1, 'IBAN-SA00'], 'emergency_contact' => 'EMERGENCY', 'country_id' => [192, 'Saudi Arabia']]);
        $this->odoo->create('hr.payslip', ['name' => 'SLIP/001', 'employee_id' => [1, 'x'], 'net_wage' => 91234.5]);
        $this->odoo->create('hr.contract', ['name' => 'عقد', 'wage' => 91234.5]);
        $this->odoo->create('res.partner.bank', ['acc_number' => 'IBAN-SA00']);
        $this->odoo->create('fleet.vehicle', ['name' => 'شاحنة 1']);

        $this->assertSame(0, $this->sync()['errors']);
        $models = OdooModel::all()->keyBy('model');
        // who works here is copied; pay, contracts and bank accounts are registered and left alone, with the reason
        $this->assertSame(['active', 'active', 'active', 'skipped', 'skipped', 'skipped'], array_map(fn ($m) => $models[$m]->status,
            ['hr.employee', 'hr.department', 'fleet.vehicle', 'hr.payslip', 'hr.contract', 'res.partner.bank']));
        $this->assertSame([OdooSync::PRIVATE, OdooSync::PRIVATE], [$models['hr.payslip']->reason, $models['res.partner.bank']->reason]);
        // attendance sheets and staff penalties come from add-on modules whose names do not start with "hr."
        $this->assertSame([OdooSync::PRIVATE, OdooSync::PRIVATE], [$models['attendance.sheet']->reason, $models['penalty.request']->reason]);
        $this->assertSame(0, OdooRecord::whereIn('model', ['hr.payslip', 'hr.contract', 'res.partner.bank', 'attendance.sheet', 'penalty.request'])->count());
        // of an employee, the work part only
        $this->assertSame(['active', 'department_id', 'display_name', 'job_title', 'name', 'work_email', 'write_date'], collect($models['hr.employee']->fields)->keys()->sort()->values()->all());
        $this->assertSame(['bank_account_id' => 'personal', 'birthday' => 'personal', 'country_id' => 'personal', 'emergency_contact' => 'personal', 'identification_id' => 'personal',
            'marital' => 'personal', 'pin' => 'credential', 'private_phone' => 'personal', 'private_street' => 'personal'], collect($models['hr.employee']->skipped_fields)->sortKeys()->all());
        $copy = json_encode(OdooRecord::pluck('data'), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('سائق أول', $copy);
        foreach (['PIN-4321', 'HOME-STREET', 'HOME-PHONE', 'ID-1090', 'IBAN-SA00', 'EMERGENCY', '91234', 'SLIP/001', '1990-01-01', 'SHEET-NOV', 'PENALTY-1'] as $private) {
            $this->assertStringNotContainsString($private, $copy);
        }

        // a person who may manage the integration decides to take the payslips in: carried out, and remembered as a decision
        $this->expectRejected($this->postAs('gm', '/api/integration/odoo/models/hr.payslip', ['status' => 'active']), 'FORBIDDEN', [403]);
        $taken = $this->expectOk($this->postAs('admin', '/api/integration/odoo/models/hr.payslip', ['status' => 'active']));
        $this->assertSame(['new', OdooSync::BY_PERSON], [$taken['status'], $taken['reason']]);
        $this->sync();
        $slips = OdooModel::where('model', 'hr.payslip')->firstOrFail();
        $this->assertSame(['active', OdooSync::BY_PERSON, 1], [$slips->status, $slips->reason, OdooRecord::where('model', 'hr.payslip')->count()]);

        // the field policy changes (this deployment does not want job titles): the copy is made again under it
        config(['odoo.never_fields' => '/pass(word|wd)?|secret|token|^pin$|^job_title$/i']);
        $employee = OdooModel::where('model', 'hr.employee')->firstOrFail();
        $this->assertSame(1, $this->app->make(OdooSync::class)->recopy($employee));
        $this->assertSame([0, 'new'], [OdooRecord::where('model', 'hr.employee')->count(), $employee->refresh()->status]);
        $this->sync();
        $again = OdooRecord::where('model', 'hr.employee')->firstOrFail();
        $this->assertSame(['سائق أول', false], [$again->data['name'], array_key_exists('job_title', $again->data)]);
        // …and the model a person took in stays in when its own copy is made again
        $this->app->make(OdooSync::class)->recopy($slips);
        $this->sync();
        $this->assertSame(['active', 1], [$slips->refresh()->status, OdooRecord::where('model', 'hr.payslip')->count()]);

        // the owner decides the copy is to be complete after all: what the rule kept out is taken in by itself
        $this->assertSame('skipped', OdooModel::where('model', 'hr.contract')->value('status'));
        config(['odoo.copy_private' => true]);
        $this->assertSame(4, $this->app->make(OdooSync::class)->discover()['admitted']); // contracts, bank accounts, attendance sheets, penalties
        $this->sync();
        $this->assertSame(['active', 1], [OdooModel::where('model', 'hr.contract')->value('status'), OdooRecord::where('model', 'hr.contract')->count()]);
        $this->assertSame([], $this->odoo->writes);
    }

    public function test_a_first_copy_that_runs_out_of_time_resumes_where_it_stopped(): void
    {
        $this->odoo->model('sale.order', ['name' => 'char'], 'Sales Order');
        foreach (range(1, 8) as $n) {
            $this->odoo->create('sale.order', ['name' => "S{$n}"]);
        }
        // each run gets time for the inspection and one page of three records
        $run = function () {
            $sync = new class($this->app->make(OdooClient::class)) extends OdooSync
            {
                public int $checks = 1;

                protected function timeLeft(): float
                {
                    return $this->checks-- > 0 ? 1.0 : 0.0;
                }
            };

            return $sync->run(30, 'test');
        };
        $run();
        $m = OdooModel::where('model', 'sale.order')->firstOrFail();
        $this->assertSame(['running', 3, 3], [$m->full_state, $m->full_cursor_id, OdooRecord::count()]);
        // an order written while the copy is still walking is not lost: it is newer than the high-water mark
        $this->odoo->write('sale.order', 2, ['name' => 'S2 edited']);
        $run();
        $this->assertSame(['running', 6], [$m->refresh()->full_state, $m->full_cursor_id]);
        $this->sync();
        $this->assertSame(['done', 8], [$m->refresh()->full_state, OdooRecord::count()]);
        $this->sync();
        $this->assertSame('S2 edited', OdooRecord::where('odoo_id', 2)->value('display_name'));
        $this->assertSame(['updated'], OdooChange::pluck('kind')->all());
    }

    public function test_what_happens_in_odoo_reaches_the_mirror_and_the_feed(): void
    {
        $this->company();
        $this->sync();
        $calls = count($this->odoo->log);

        // people work in Odoo: an order is confirmed and re-priced, a partner is renamed, one is archived, one is new
        $this->odoo->write('sale.order', 4, ['state' => 'done', 'amount_total' => 450.0]);
        $this->odoo->write('res.partner', 2, ['name' => 'مطعم الريف الجديد']);
        $this->odoo->write('res.partner', 5, ['active' => false]);
        $new = $this->odoo->create('res.partner', ['name' => 'كافيه المدينة']);
        $out = $this->sync();
        $this->assertSame([1, 3, 0], [$out['created'], $out['updated'], $out['deleted']]);

        $feed = OdooChange::orderBy('id')->get()->map(fn ($c) => [$c->model, $c->odoo_id, $c->kind, $c->fields])->all();
        $this->assertEqualsCanonicalizing([
            ['sale.order', 4, 'updated', ['amount_total', 'state']],
            ['res.partner', 2, 'updated', ['name']],
            ['res.partner', 5, 'archived', ['active']],
            ['res.partner', $new, 'created', null],
        ], $feed);
        $order = OdooRecord::where('model', 'sale.order')->where('odoo_id', 4)->firstOrFail();
        $this->assertSame(['done', 450.0, 2], [$order->data['state'], (float) $order->data['amount_total'], $order->version]);
        $this->assertFalse(OdooRecord::where('model', 'res.partner')->where('odoo_id', 5)->value('active'), 'archived in Odoo, still known here');
        $this->assertSame('مطعم الريف الجديد', OdooRecord::where('model', 'res.partner')->where('odoo_id', 2)->value('display_name'));
        $this->assertNotNull(OdooChange::where('odoo_id', 4)->value('run_id'));

        // the overlap re-reads the last minutes on every run: the same change is never reported twice
        $this->odoo->write('sale.order', 6, ['state' => 'cancel']);
        $this->sync();
        $this->sync();
        $this->assertSame(1, OdooChange::where('model', 'sale.order')->where('odoo_id', 6)->count());
        $this->assertSame(1, OdooChange::where('model', 'sale.order')->where('odoo_id', 4)->count());

        // restored from the archive
        $this->odoo->write('res.partner', 5, ['active' => true]);
        $this->sync();
        $this->assertSame('restored', OdooChange::where('model', 'res.partner')->where('odoo_id', 5)->orderByDesc('id')->value('kind'));

        // a quiet run costs one question per model
        $before = count($this->odoo->log);
        $this->sync();
        $this->assertSame(['res.partner.search_read', 'sale.order.search_read'], collect(array_slice($this->odoo->log, $before))->sort()->values()->all());
        $this->assertGreaterThan($calls, $before);
        $this->assertSame([], $this->odoo->writes);
    }

    public function test_a_record_committed_late_is_caught_by_the_deep_pass(): void
    {
        $this->company();
        $this->sync();
        $this->sync(); // the first look for changes after a copy is a deep one
        $m = OdooModel::where('model', 'sale.order')->firstOrFail();
        // written 20 minutes "ago" by a long transaction that only now committed: older than the cursor and its overlap
        $late = $this->odoo->create('sale.order', ['name' => 'S-LATE', 'state' => 'draft'],
            at: Carbon::parse($m->cursor_write_date, 'UTC')->subMinutes(20)->format('Y-m-d H:i:s'));
        $this->sync();
        $this->assertFalse(OdooRecord::where('model', 'sale.order')->where('odoo_id', $late)->exists(), 'an ordinary run looks 3 minutes back');
        OdooModel::query()->update(['last_deep_at' => now()->subMinutes(16)]);
        $this->sync();
        $this->assertSame('S-LATE', OdooRecord::where('model', 'sale.order')->where('odoo_id', $late)->value('display_name'));
        $this->assertSame('created', OdooChange::where('odoo_id', $late)->value('kind'));
        $this->assertGreaterThanOrEqual($m->cursor_write_date, $m->refresh()->cursor_write_date, 'the cursor never moves back');
    }

    public function test_deletions_are_found_by_comparing_ids_and_the_copy_heals_itself(): void
    {
        $this->company();
        $this->sync();
        $this->assertNotNull(OdooModel::where('model', 'sale.order')->value('last_deletion_check_at'), 'the first check ran with the first copy');

        // an order is deleted in Odoo; another one exists there that the mirror never saw (written long before the cursor)
        $this->odoo->unlink('sale.order', 3);
        $this->odoo->unlink('sale.order', 6);
        $missed = $this->odoo->create('sale.order', ['name' => 'S-MISSED', 'state' => 'sale'], at: '2020-01-01 00:00:00');
        $this->sync();
        $this->assertNull(OdooRecord::where('model', 'sale.order')->where('odoo_id', 3)->value('deleted_at'), 'not due yet: deletions are checked on a schedule');

        OdooModel::query()->update(['last_deletion_check_at' => now()->subMinutes(31)]);
        $out = $this->sync();
        $this->assertSame(2, $out['deleted']);
        $this->assertNotNull(OdooRecord::where('model', 'sale.order')->where('odoo_id', 3)->value('deleted_at'));
        $this->assertSame(['deleted', 'deleted'], OdooChange::where('model', 'sale.order')->whereIn('odoo_id', [3, 6])->pluck('kind')->all());
        $this->assertSame('S00003', OdooChange::where('odoo_id', 3)->value('display_name'), 'the feed still says what was deleted');
        $this->assertSame('S-MISSED', OdooRecord::where('model', 'sale.order')->where('odoo_id', $missed)->value('display_name'), 'healed: fetched because Odoo has it');
        $m = OdooModel::where('model', 'sale.order')->firstOrFail();
        $this->assertSame([6, 6, 0], [$m->remote_count, $m->mirrored_count, $m->deletion_cursor_id]);

        // the same id comes back (a restored backup): the record lives again
        $this->odoo->create('sale.order', ['id' => 3, 'name' => 'S00003', 'state' => 'sale'], at: '2020-01-01 00:00:00');
        OdooModel::query()->update(['last_deletion_check_at' => now()->subMinutes(31)]);
        $this->sync();
        $this->assertNull(OdooRecord::where('model', 'sale.order')->where('odoo_id', 3)->value('deleted_at'));
        $this->assertSame('restored', OdooChange::where('odoo_id', 3)->orderByDesc('id')->value('kind'));
        $this->assertSame([], $this->odoo->writes);
    }

    public function test_a_fault_stays_with_its_model(): void
    {
        $this->company();
        $this->odoo->model('account.analytic.line', ['name' => 'char'], 'Analytic Line');
        $this->odoo->models['account.analytic.line']['denied'] = true;
        $this->odoo->model('stock.quant', ['quantity' => 'float', 'inventory_quantity_auto_apply' => 'float', 'lot_id' => 'many2one'], 'Quants');
        $this->odoo->models['stock.quant']['broken'] = ['inventory_quantity_auto_apply'];
        $this->odoo->create('stock.quant', ['quantity' => 12.5, 'inventory_quantity_auto_apply' => 1.0]);
        $this->odoo->model('fleet.vehicle', ['name' => 'char'], 'Vehicle');
        $this->odoo->create('fleet.vehicle', ['name' => 'V-1']);
        $this->odoo->models['fleet.vehicle']['down'] = true;

        $out = $this->sync();
        $this->assertSame(2, $out['errors'], 'the analytic lines (no access) and the vehicles (no answer)');
        $models = OdooModel::all()->keyBy('model');
        // no access: skipped, with the reason — a fact about the Odoo user's rights
        $this->assertSame(['skipped', 'no read access for the Odoo user'], [$models['account.analytic.line']->status, $models['account.analytic.line']->reason]);
        // a field Odoo cannot return is dropped and remembered; the rest of the model is copied
        $this->assertSame(['active', 'done'], [$models['stock.quant']->status, $models['stock.quant']->full_state]);
        $this->assertSame(['inventory_quantity_auto_apply' => 'Odoo cannot return it'], $models['stock.quant']->skipped_fields);
        $this->assertSame(12.5, (float) OdooRecord::where('model', 'stock.quant')->value('data')['quantity']);
        // no answer: backed off, tried again later, and the others were copied meanwhile
        $this->assertSame([1, 'new'], [$models['fleet.vehicle']->consecutive_errors, $models['fleet.vehicle']->status]);
        $this->assertTrue($models['fleet.vehicle']->next_attempt_at->isFuture());
        $this->assertSame(7, OdooRecord::where('model', 'sale.order')->count());

        $this->odoo->models['fleet.vehicle']['down'] = false;
        $this->sync();
        $this->assertSame(0, OdooRecord::where('model', 'fleet.vehicle')->count(), 'still backing off');
        OdooModel::where('model', 'fleet.vehicle')->update(['next_attempt_at' => now()->subSecond()]);
        $this->sync();
        $this->assertSame([1, 0, null], [OdooRecord::where('model', 'fleet.vehicle')->count(), $models['fleet.vehicle']->refresh()->consecutive_errors, $models['fleet.vehicle']->last_error]);

        // Odoo refuses the key: the run stops at once and says so, nothing is half done
        config(['odoo.key' => 'replaced-key']);
        Cache::flush();
        $refused = $this->sync();
        $this->assertNotNull($refused['error']);
        $this->assertNotNull(OdooRun::orderByDesc('id')->firstOrFail()->detail['error'] ?? null);
    }

    public function test_a_credential_is_never_copied_and_what_only_looks_like_one_is(): void
    {
        $this->company();
        // the e-invoicing unit's certificate with its secret sits on a journal; a kiosk key on the website's settings
        $this->odoo->model('account.journal', ['name' => 'char', 'l10n_sa_production_csid_json' => 'char', 'l10n_sa_compliance_csid_json' => 'char',
            'l10n_sa_production_csid_validity' => 'datetime', 'l10n_sa_compliance_checks_passed' => 'boolean', 'access_token' => 'char'], 'Journal');
        $this->odoo->create('account.journal', ['name' => 'فواتير العملاء', 'l10n_sa_production_csid_json' => '{"secret":"CSID-SECRET-1"}', 'l10n_sa_compliance_csid_json' => '{"secret":"CSID-SECRET-2"}',
            'l10n_sa_production_csid_validity' => '2027-01-31 00:00:00', 'l10n_sa_compliance_checks_passed' => true, 'access_token' => 'PORTAL-TOKEN']);
        $this->odoo->model('website', ['name' => 'char', 'google_analytics_key' => 'char', 'attendance_kiosk_key' => 'char', 'firebase_project_id' => 'char'], 'Website');
        $this->odoo->create('website', ['name' => 'الموقع', 'google_analytics_key' => 'GA-KEY-9', 'attendance_kiosk_key' => 'KIOSK-KEY-9', 'firebase_project_id' => 'project-1']);
        // a passport number and an education level are data of a person, not keys: copied, and personal
        $this->odoo->model('hr.employee', ['name' => 'char', 'passport_id' => 'char', 'certificate' => 'selection', 'pin' => 'char'], 'Employee');
        $this->odoo->create('hr.employee', ['name' => 'سائق أول', 'passport_id' => 'PASSPORT-77', 'certificate' => 'bachelor', 'pin' => 'PIN-4321']);
        // the invitation key of a discussion channel opens the channel: left out by model, the name alone does not say it
        $this->odoo->model('discuss.channel', ['name' => 'char', 'uuid' => 'char'], 'Channel');
        $this->odoo->create('discuss.channel', ['name' => 'عام', 'uuid' => 'INVITE-UUID-1']);
        // an identity number in a model that is not a person's file: copied, shown only with `odoo.private`
        $this->odoo->model('portal.onboarding.application', ['name' => 'char', 'owner_national_id' => 'char', 'password_hash' => 'char', 'vat_number' => 'char'], 'Onboarding');
        $this->odoo->create('portal.onboarding.application', ['name' => 'مطعم الواحة', 'owner_national_id' => 'NATIONAL-ID-5', 'password_hash' => 'HASH-OF-A-PASSWORD', 'vat_number' => '300000000000003']);

        $this->assertSame(0, $this->sync()['errors']);
        $models = OdooModel::all()->keyBy('model');
        $this->assertSame(['access_token' => 'credential', 'l10n_sa_compliance_csid_json' => 'credential', 'l10n_sa_production_csid_json' => 'credential'],
            collect($models['account.journal']->skipped_fields)->sortKeys()->all());
        $journal = OdooRecord::where('model', 'account.journal')->firstOrFail()->data;
        $this->assertSame(['2027-01-31 00:00:00', true], [$journal['l10n_sa_production_csid_validity'], $journal['l10n_sa_compliance_checks_passed']], 'the validity date and a "…passed" flag are not keys');
        $this->assertSame(['attendance_kiosk_key' => 'credential', 'google_analytics_key' => 'credential'], collect($models['website']->skipped_fields)->sortKeys()->all());
        $this->assertSame('project-1', OdooRecord::where('model', 'website')->firstOrFail()->data['firebase_project_id']);
        $this->assertSame(['pin' => 'credential'], $models['hr.employee']->skipped_fields);
        $employee = OdooRecord::where('model', 'hr.employee')->firstOrFail()->data;
        $this->assertSame(['PASSPORT-77', 'bachelor'], [$employee['passport_id'], $employee['certificate']]);
        $this->assertSame(['uuid' => 'credential'], $models['discuss.channel']->skipped_fields);
        $this->assertSame(['password_hash' => 'credential'], $models['portal.onboarding.application']->skipped_fields);
        $copy = json_encode(OdooRecord::pluck('data'), JSON_UNESCAPED_UNICODE);
        foreach (['CSID-SECRET-1', 'CSID-SECRET-2', 'PORTAL-TOKEN', 'GA-KEY-9', 'KIOSK-KEY-9', 'PIN-4321', 'INVITE-UUID-1', 'HASH-OF-A-PASSWORD'] as $secret) {
            $this->assertStringNotContainsString($secret, $copy);
        }

        // who may open the tower but not people's files sees the application without the owner's identity number,
        // and the employee without the passport and the education level
        $seen = $this->expectOk($this->getAs('gm', '/api/integration/odoo/records/portal.onboarding.application/1'))['data'];
        $this->assertSame(['مطعم الواحة', '300000000000003', false], [$seen['name'], $seen['vat_number'], array_key_exists('owner_national_id', $seen)]);
        $staff = $this->expectOk($this->getAs('gm', '/api/integration/odoo/records/hr.employee/1'))['data'];
        $this->assertSame([false, false], [array_key_exists('passport_id', $staff), array_key_exists('certificate', $staff)]);
        $this->assertSame('NATIONAL-ID-5', $this->expectOk($this->getAs('admin', '/api/integration/odoo/records/portal.onboarding.application/1'))['data']['owner_national_id']);
        $this->assertSame([], $this->odoo->writes);
    }

    public function test_a_model_odoo_refused_enters_the_copy_once_the_access_is_granted(): void
    {
        $this->company();
        $this->odoo->model('b2b.credit.request', ['name' => 'char'], 'Credit Request');
        $this->odoo->create('b2b.credit.request', ['name' => 'CR-1']);
        $this->odoo->models['b2b.credit.request']['denied'] = true;
        $this->sync();
        $state = fn () => OdooModel::where('model', 'b2b.credit.request')->firstOrFail();
        $this->assertSame(['skipped', OdooSync::NO_ACCESS], [$state()->status, $state()->reason]);

        // every discovery asks Odoo again — still refused: nothing changes
        $sync = $this->app->make(OdooSync::class);
        $this->assertSame(0, $sync->discover()['granted']);
        $this->assertSame('skipped', $state()->status);

        // an administrator of Odoo gives the connection user the access: the model enters the copy by itself
        $this->odoo->models['b2b.credit.request']['denied'] = false;
        $this->assertSame(1, $sync->discover()['granted']);
        $this->sync();
        $this->assertSame(['active', 'done', 1, null], [$state()->status, $state()->full_state, OdooRecord::where('model', 'b2b.credit.request')->count(), $state()->reason]);
        $this->assertSame([], $this->odoo->writes);
    }

    public function test_odoo_19_is_read_through_json2(): void
    {
        $this->odoo->json2 = true;
        $this->company();
        $client = $this->app->make(OdooClient::class);
        $this->assertSame(['version' => '19.0', 'transport' => 'json2'], $client->version());
        $this->sync();
        $this->assertSame([5, 7], [OdooRecord::where('model', 'res.partner')->count(), OdooRecord::where('model', 'sale.order')->count()]);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/jsonrpc'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/json/2/sale.order/search_read') && $request->hasHeader('Authorization', 'bearer '.FakeOdoo::KEY));
    }

    public function test_a_server_that_has_the_code_but_not_the_tables_says_so(): void
    {
        // the code was deployed and `php artisan migrate` has not run yet: nothing may fail on a missing table
        Cache::flush();
        Schema::partialMock()->shouldReceive('hasTable')->with('odoo_models')->andReturnFalse();

        $status = $this->expectOk($this->getAs('gm', '/api/integration/odoo/status'));
        $this->assertSame([false, true, false], [$status['installed'], $status['connection']['configured'], $status['connection']['ok']]);
        $this->assertArrayNotHasKey('models', $status);
        foreach (['models', 'changes', 'runs', 'stock', 'master-data', 'records?model=res.partner'] as $path) {
            $this->expectRejected($this->getAs('gm', "/api/integration/odoo/{$path}"), 'ODOO_NOT_INSTALLED', [422]);
        }
        $this->expectRejected($this->postAs('admin', '/api/integration/odoo/sync'), 'ODOO_NOT_INSTALLED', [422]);
        $this->assertSame([], $this->odoo->log, 'Odoo was not even asked');
    }

    public function test_what_people_see_of_the_mirror(): void
    {
        $this->company();
        // nothing configured: the screen says so and nothing breaks
        config(['odoo.enabled' => false]);
        $this->expectRejected($this->postAs('admin', '/api/integration/odoo/sync'), 'ODOO_DISABLED', [422]);
        config(['odoo.enabled' => true]);

        // permissions: viewing needs integration.view, starting a run or changing a model integration.manage
        $this->expectRejected($this->getAs('worker', '/api/integration/odoo/status'), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('gm', '/api/integration/odoo/sync'), 'FORBIDDEN', [403]);
        $this->expectOk($this->getAs('gm', '/api/integration/odoo/status'));

        $run = $this->expectOk($this->postAs('admin', '/api/integration/odoo/sync', ['seconds' => 20]));
        $this->assertSame([12, 0], [$run['created'], $run['errors']]);
        $this->odoo->write('sale.order', 2, ['state' => 'done']);
        $this->expectOk($this->postAs('admin', '/api/integration/odoo/sync', ['model' => 'sale.order']));

        $status = $this->expectOk($this->getAs('admin', '/api/integration/odoo/status?fresh=1'));
        $this->assertSame([true, 'odoo.test', '17.0', 'jsonrpc', 'Mirror Bot'], [$status['connection']['ok'], $status['connection']['host'], $status['connection']['version'], $status['connection']['transport'], $status['connection']['user']['name']]);
        $this->assertStringNotContainsString(FakeOdoo::KEY, json_encode($status), 'the key is never shown');
        $this->assertSame([2, 2, 12, 12, true], [$status['models']['active'], $status['models']['skipped'], $status['firstCopy']['records'], $status['firstCopy']['expected'], $status['firstCopy']['complete']]);
        $this->assertSame([1, 1], [$status['changes']['last24h'], $status['changes']['byKind']['updated']]);
        $this->assertSame(['search', 'search_read', 'search_count', 'read', 'fields_get'], $status['policy']['readOnlyMethods']);

        $models = $this->expectOk($this->getAs('admin', '/api/integration/odoo/models?status=active'));
        $this->assertSame(['sale.order', 'res.partner'], array_column($models['items'], 'model'), 'core models in their order');
        $this->assertSame([7, 7, 100, true], [$models['items'][0]['mirroredCount'], $models['items'][0]['remoteCount'], $models['items'][0]['copyPct'], $models['items'][0]['core']]);
        $this->assertArrayNotHasKey('fields', $models['items'][0]);
        $this->assertSame(1, $this->expectOk($this->getAs('admin', '/api/integration/odoo/models?q=cron'))['total']);

        $detail = $this->expectOk($this->getAs('admin', '/api/integration/odoo/models/sale.order'));
        $this->assertSame(['access_token' => 'credential'], $detail['skippedFields']);
        $this->assertSame('many2one', $detail['fields']['partner_id']);
        $this->assertSame('updated', $detail['recentChanges'][0]['kind']);

        $records = $this->expectOk($this->getAs('admin', '/api/integration/odoo/records?model=sale.order&q=S00002'));
        $this->assertSame([1, 2, 'S00002'], [$records['total'], $records['items'][0]['odooId'], $records['items'][0]['displayName']]);
        $this->assertArrayNotHasKey('data', $records['items'][0]);
        $this->expectRejected($this->getAs('admin', '/api/integration/odoo/records'), 'MODEL_REQUIRED', [400]);
        $record = $this->expectOk($this->getAs('admin', '/api/integration/odoo/records/sale.order/2'));
        $this->assertSame(['done', 2, 'updated', ['state']], [$record['data']['state'], $record['version'], $record['history'][0]['kind'], $record['history'][0]['fields']]);
        $this->expectRejected($this->getAs('admin', '/api/integration/odoo/records/sale.order/999'), 'ODOO_RECORD_NOT_FOUND', [404]);

        $changes = $this->expectOk($this->getAs('admin', '/api/integration/odoo/changes?kind=updated'));
        $this->assertSame([1, 'sale.order', 2], [$changes['total'], $changes['items'][0]['model'], $changes['items'][0]['odooId']]);
        $this->assertSame(2, $this->expectOk($this->getAs('admin', '/api/integration/odoo/runs'))['total']);

        // pause a model: it is left alone until it is resumed; a skipped model can be taken into the mirror
        $paused = $this->expectOk($this->postAs('admin', '/api/integration/odoo/models/sale.order', ['status' => 'paused']));
        $this->assertSame('paused', $paused['status']);
        $this->odoo->write('sale.order', 5, ['state' => 'cancel']);
        $this->sync();
        $this->assertSame('sale', OdooRecord::where('model', 'sale.order')->where('odoo_id', 5)->firstOrFail()->data['state']);
        $this->expectOk($this->postAs('admin', '/api/integration/odoo/models/sale.order', ['status' => 'active']));
        $this->sync();
        $this->assertSame('cancel', OdooRecord::where('model', 'sale.order')->where('odoo_id', 5)->firstOrFail()->data['state']);
        $this->odoo->create('ir.cron', ['name' => 'nightly']);
        $this->assertSame('new', $this->expectOk($this->postAs('admin', '/api/integration/odoo/models/ir.cron', ['status' => 'active']))['status']);
        $this->sync();
        $this->assertSame(1, OdooRecord::where('model', 'ir.cron')->count());
        $this->expectRejected($this->postAs('gm', '/api/integration/odoo/models/sale.order', ['status' => 'paused']), 'FORBIDDEN', [403]);
        $this->assertSame([], $this->odoo->writes, 'nothing OPS did ever tried to change Odoo');
    }
}
