<?php

namespace Tests\Feature\Integration;

use App\Integration\Odoo\Models\OdooModel;
use App\Integration\Odoo\OdooSync;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/**
 * The archive's index behind the real mirror (OdooSync against an Odoo that lives in memory — FakeOdoo): whatever a
 * synchronisation run writes into the copy, or marks deleted in it, is in the index when the run ends — without the
 * build command, and without anything being written to Odoo.
 */
class OdooArchiveMirrorTest extends ApiTestCase
{
    private const API = '/api/odoo-archive';

    private FakeOdoo $odoo;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['odoo_docs', 'odoo_docs_state', 'odoo_runs', 'odoo_changes', 'odoo_records', 'odoo_models'] as $table) {
            DB::table($table)->delete();
        }
        Cache::flush();
        config(['odoo' => FakeOdoo::config(['priority' => ['res.partner', 'sale.order']])]);
        $this->odoo = (new FakeOdoo)->install();
    }

    private function sync(): array
    {
        return $this->app->make(OdooSync::class)->run(30, 'test');
    }

    private function orders(string $query = ''): array
    {
        return $this->expectOk($this->getAs('admin', self::API.'/docs/sale_order'.($query ? "?{$query}" : '')));
    }

    public function test_what_a_run_of_the_mirror_copies_changes_or_finds_deleted_is_in_the_index(): void
    {
        $this->odoo->model('res.partner', ['name' => 'char', 'customer_rank' => 'integer', 'supplier_rank' => 'integer', 'active' => 'boolean'], 'Contact');
        $this->odoo->model('sale.order', ['name' => 'char', 'state' => 'selection', 'partner_id' => ['type' => 'many2one', 'relation' => 'res.partner'], 'amount_total' => 'monetary',
            'date_order' => 'datetime'], 'Sales Order');
        $this->odoo->create('res.partner', ['id' => 1, 'name' => 'مطعم الريف', 'customer_rank' => 2, 'supplier_rank' => 0]);
        $this->odoo->create('res.partner', ['id' => 2, 'name' => 'Blue Hotel', 'customer_rank' => 0, 'supplier_rank' => 1]);
        foreach (range(1, 7) as $n) { // more than two pages of the mirror (its page is 3 records here)
            $this->odoo->create('sale.order', ['id' => $n, 'name' => "S0000{$n}", 'state' => 'sale', 'partner_id' => [1, 'مطعم الريف'], 'amount_total' => 100.0 * $n, 'date_order' => "2026-03-0{$n} 10:00:00"]);
        }

        // the first copy: every page the mirror wrote is indexed as it was written — no build command ran
        $this->sync();
        $this->assertSame([7, 2, 0], [DB::table('odoo_docs')->where('kind', 'sale_order')->count(), DB::table('odoo_docs')->where('kind', 'contact')->count(), DB::table('odoo_docs_state')->where('done', true)->count()]);
        $all = $this->orders();
        $this->assertSame([7, [7, 6, 5, 4, 3, 2, 1]], [$all['total'], array_column($all['items'], 'odooId')]);
        $this->assertEquals([2800.0, 'S00007', ['id' => 1, 'name' => 'مطعم الريف'], 700.0], [$all['totals']['amount'], $all['items'][0]['number'], $all['items'][0]['partner'], $all['items'][0]['amount']]);
        $this->assertSame([1], array_column($this->expectOk($this->getAs('admin', self::API.'/docs/customer'))['items'], 'odooId'));
        // the catalog says that the first build has not run yet — and lists what is indexed all the same
        $catalog = $this->expectOk($this->getAs('admin', self::API.'/catalog'));
        $this->assertSame([false, 9], [$catalog['ready'], $catalog['indexed']]);

        // Odoo changed an order and created one: after the next run the index has both
        $this->odoo->write('sale.order', 2, ['state' => 'cancel', 'amount_total' => 5.0]);
        $this->odoo->create('sale.order', ['id' => 8, 'name' => 'S00008', 'state' => 'draft', 'partner_id' => [2, 'Blue Hotel'], 'amount_total' => 50.0, 'date_order' => '2026-03-08 10:00:00']);
        $this->travel(2)->minutes();
        $this->sync();
        $this->assertSame([[2], [8], 8], [array_column($this->orders('state=cancel')['items'], 'odooId'), array_column($this->orders('state=draft')['items'], 'odooId'), $this->orders()['total']]);
        $this->assertEquals(2655.0, $this->orders()['totals']['amount']);

        // Odoo deleted an order: the mirror finds it by comparing ids, and the index takes it out of the lists — and keeps it
        $this->odoo->unlink('sale.order', 3);
        OdooModel::query()->update(['last_deletion_check_at' => now()->subMinutes(31)]);
        $this->sync();
        $this->assertSame([7, [3], 0], [$this->orders()['total'], array_column($this->orders('deleted=1')['items'], 'odooId'), (int) DB::table('odoo_docs')->where('model', 'sale.order')->where('odoo_id', 3)->value('live')]);
        $this->assertTrue($this->expectOk($this->getAs('admin', self::API.'/docs/sale_order/3'))['deleted']);

        // the build command after all that finds the index as the copy is: it only notes that every model is built
        $this->assertSame(0, Artisan::call('scm:odoo-archive-index'));
        $this->assertSame([10, 10, true], [DB::table('odoo_records')->count(), DB::table('odoo_docs')->count(), $this->expectOk($this->getAs('admin', self::API.'/catalog'))['ready']]);
        // the mirror only read Odoo, and the archive only read the copy
        $this->assertSame([], $this->odoo->writes);
    }
}
