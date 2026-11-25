<?php

namespace Tests\Feature\Integration;

use App\Integration\Odoo\Models\OdooChange;
use App\Integration\Odoo\Models\OdooFile;
use App\Integration\Odoo\Models\OdooModel;
use App\Integration\Odoo\Models\OdooRecord;
use App\Integration\Odoo\OdooExport;
use App\Integration\Odoo\OdooFiles;
use App\Integration\Odoo\OdooSync;
use App\Integration\Odoo\OdooVerify;
use App\Support\AppError;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\ApiTestCase;

/**
 * The copy of Odoo as the company's backup (docs/odoo/ARCHITECTURE.ar.md §8): it holds everything, it survives Odoo
 * being down or emptied, it can be checked against Odoo, it keeps the attachments' content, and it can be put in a
 * safe as files and loaded back.
 */
class OdooBackupTest extends ApiTestCase
{
    private FakeOdoo $odoo;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['odoo_runs', 'odoo_changes', 'odoo_records', 'odoo_models', 'odoo_files', 'odoo_verifications'] as $table) {
            DB::table($table)->delete();
        }
        Cache::flush();
        config(['odoo' => FakeOdoo::config(['priority' => ['sale.order']])]);
        $this->odoo = (new FakeOdoo)->install();
    }

    private function sync(?array $only = null): array
    {
        return $this->app->make(OdooSync::class)->run(60, 'test', $only);
    }

    private function orders(int $n): void
    {
        $this->odoo->model('sale.order', ['name' => 'char', 'state' => 'selection', 'amount_total' => 'monetary', 'partner_id' => ['type' => 'many2one', 'relation' => 'res.partner']], 'Sales Order');
        foreach (range(1, $n) as $i) {
            $this->odoo->create('sale.order', ['name' => sprintf('S%05d', $i), 'state' => 'sale', 'amount_total' => 10.0 * $i, 'partner_id' => [7, 'مطعم الواحة']]);
        }
    }

    public function test_a_model_odoo_trims_after_limiting_is_copied_whole(): void
    {
        // Odoo drops from a page of messages what the user may not read AFTER cutting the page: short pages everywhere
        $this->odoo->model('mail.message', ['body' => 'html', 'model' => 'char', 'res_id' => 'integer'], 'Message');
        foreach (range(1, 14) as $i) {
            $this->odoo->create('mail.message', ['body' => "<p>رسالة {$i}</p>", 'model' => 'sale.order', 'res_id' => $i]);
        }
        $this->odoo->models['mail.message']['hidden'] = [2, 5, 9];

        $this->assertSame(0, $this->sync()['errors']);
        $m = OdooModel::where('model', 'mail.message')->firstOrFail();
        $this->assertSame([true, 'done', 11], [$m->ranged, $m->full_state, OdooRecord::where('model', 'mail.message')->count()], 'the first short page was not taken for the end');
        $this->assertSame([1, 3, 4, 6, 7, 8, 10, 11, 12, 13, 14], OdooRecord::where('model', 'mail.message')->orderBy('odoo_id')->pluck('odoo_id')->all());

        // what happens afterwards arrives, and a deleted message is found without the hidden ones being taken for deleted
        $this->odoo->write('mail.message', 12, ['body' => '<p>معدّلة</p>']);
        $this->odoo->create('mail.message', ['body' => '<p>جديدة</p>', 'model' => 'sale.order', 'res_id' => 1]);
        $this->odoo->unlink('mail.message', 7);
        OdooModel::where('model', 'mail.message')->update(['last_deletion_check_at' => null]);
        $this->sync();
        $this->sync();
        $this->assertSame('<p>معدّلة</p>', OdooRecord::where('model', 'mail.message')->where('odoo_id', 12)->firstOrFail()->data['body']);
        $this->assertNotNull(OdooRecord::where('model', 'mail.message')->where('odoo_id', 15)->first());
        $this->assertSame([7], OdooRecord::where('model', 'mail.message')->whereNotNull('deleted_at')->pluck('odoo_id')->all());
        $this->assertSame('active', $m->refresh()->status, 'one deletion is not a reason to hold the model');
        $this->assertSame([], $this->odoo->writes);
    }

    public function test_many_records_gone_at_once_put_the_model_on_hold_until_a_person_confirms(): void
    {
        $this->orders(120);
        $this->sync();
        $this->assertSame(120, OdooRecord::where('model', 'sale.order')->whereNull('deleted_at')->count());

        // the Odoo user loses sight of most orders (a rule changed, or the database behind the address is another one)
        foreach (range(11, 110) as $id) {
            $this->odoo->unlink('sale.order', $id);
        }
        OdooModel::where('model', 'sale.order')->update(['last_deletion_check_at' => null]);
        $run = $this->sync();
        $m = OdooModel::where('model', 'sale.order')->firstOrFail();
        $this->assertSame(['paused', OdooSync::GUARD, 1], [$m->status, $m->reason, $run['errors']]);
        $this->assertSame([120, 0], [OdooRecord::where('model', 'sale.order')->whereNull('deleted_at')->count(), OdooChange::where('kind', 'deleted')->count()], 'the backup did not follow');
        $status = $this->expectOk($this->getAs('gm', '/api/integration/odoo/status'));
        $this->assertSame(['sale.order'], $status['backup']['onHold']);
        // the copy stays as it is, run after run, until a person looks
        $this->sync();
        $this->assertSame(120, OdooRecord::where('model', 'sale.order')->whereNull('deleted_at')->count());

        // a person confirms that the orders are really gone: resuming the model lets the next check mark them
        $this->expectRejected($this->postAs('gm', '/api/integration/odoo/models/sale.order', ['status' => 'active']), 'FORBIDDEN', [403]);
        $this->assertSame('active', $this->expectOk($this->postAs('admin', '/api/integration/odoo/models/sale.order', ['status' => 'active']))['status']);
        $this->sync();
        $this->assertSame([20, 100], [OdooRecord::where('model', 'sale.order')->whereNull('deleted_at')->count(), OdooChange::where('kind', 'deleted')->count()]);
        // …and even then nothing is erased: the last values of every deleted order are still in the copy
        $this->assertSame('S00050', OdooRecord::where('model', 'sale.order')->where('odoo_id', 50)->firstOrFail()->display_name);
        $this->assertSame([], $this->odoo->writes);
    }

    public function test_odoo_being_down_leaves_the_copy_whole_and_readable(): void
    {
        $this->orders(7);
        $this->sync();
        $this->odoo->offline = true;
        Cache::forget('odoo:connection');

        $run = $this->sync();
        $this->assertTrue($run['ran']);
        $this->assertGreaterThan(0, $run['errors']);
        $this->assertSame([7, 0], [OdooRecord::where('model', 'sale.order')->whereNull('deleted_at')->count(), OdooChange::count()]);
        $this->assertSame(7, $this->expectOk($this->getAs('gm', '/api/integration/odoo/records?model=sale.order'))['total']);
        $this->assertSame('S00003', $this->expectOk($this->getAs('gm', '/api/integration/odoo/records/sale.order/3'))['displayName']);
        $this->assertFalse($this->expectOk($this->getAs('gm', '/api/integration/odoo/status?fresh=1'))['connection']['ok']);
    }

    public function test_the_copy_is_compared_with_odoo_and_heals_what_differs(): void
    {
        $this->orders(9);
        $this->sync();
        $verify = $this->app->make(OdooVerify::class);

        $v = $verify->run('test', null, 50);
        $this->assertSame([true, 1, 9, 9, 0, 0, 9, 0], [$v['complete'], $v['models'], $v['recordsOdoo'], $v['recordsCopy'], $v['missing'], $v['extra'], $v['sampled'], $v['differing']]);

        // a partner renamed in Odoo changes the label printed on its orders, not the orders: not a difference
        $this->odoo->models['sale.order']['rows'][4]['partner_id'] = [7, 'مطعم الواحة الجديد'];
        // nor is the last bit of a decimal: a database that keeps JSON numbers as binary doubles gives 29601.92 back for
        // Odoo's 29601.920000000002 — the same amount, written with fewer digits
        $amount = (float) $this->odoo->models['sale.order']['rows'][3]['amount_total'];
        $this->odoo->models['sale.order']['rows'][3]['amount_total'] = $amount + max(abs($amount), 1.0) * 4e-16;
        $this->assertNotSame($amount, $this->odoo->models['sale.order']['rows'][3]['amount_total']);
        // Odoo recomputed a stored value without stamping the record: the runs cannot see it, the comparison does
        $this->odoo->models['sale.order']['rows'][5]['amount_total'] = 999.0;
        // a record written since the copy read it is the next run's business
        $this->odoo->write('sale.order', 6, ['state' => 'done']);
        // a record deleted in Odoo since the last deletion check, and one the copy lost
        $this->odoo->unlink('sale.order', 9);
        OdooRecord::where('model', 'sale.order')->where('odoo_id', 2)->delete();

        // a record created since the last run is new, not missing
        $this->odoo->create('sale.order', ['id' => 10, 'name' => 'S00010', 'state' => 'draft', 'amount_total' => 1.0]);

        $v = $verify->run('test', null, 50);
        // counts alone would have hidden one missing against one too many: the ids themselves are compared
        $this->assertSame([false, 1, 1, 1], [$v['complete'], $v['missing'], $v['extra'], $v['differing']]);
        // …and the record that differs is told by the NAME of the field that differs (never by a value)
        $this->assertEquals(['odoo' => 9, 'copy' => 8, 'missing' => 1, 'extra' => 1, 'new' => 1, 'sampled' => 8, 'differing' => 1, 'written' => 1, 'fields' => ['amount_total' => 1]], $v['detail']['models']['sale.order']);
        $seen = $this->expectOk($this->getAs('gm', '/api/integration/odoo/status'))['backup']['verification'];
        $this->assertSame([false, 1, 1], [$seen['complete'], $seen['missing'], $seen['differing']]);

        // with `heal` the model is read again in place — the copy is whole and true afterwards
        $verify->run('test', null, 50, heal: true);
        $this->assertSame('pending', OdooModel::where('model', 'sale.order')->value('full_state'));
        $this->sync();
        $this->assertSame([999.0, 'done', true, true], [(float) OdooRecord::where('model', 'sale.order')->where('odoo_id', 5)->firstOrFail()->data['amount_total'],
            OdooRecord::where('model', 'sale.order')->where('odoo_id', 6)->firstOrFail()->data['state'],
            OdooRecord::where('model', 'sale.order')->where('odoo_id', 2)->exists(), OdooRecord::where('model', 'sale.order')->where('odoo_id', 10)->exists()]);
        OdooModel::where('model', 'sale.order')->update(['last_deletion_check_at' => null]);
        $this->sync();
        $done = $verify->run('test', null, 50);
        $this->assertSame([true, 9, 9, 0, 0, 0], [$done['complete'], $done['recordsOdoo'], $done['recordsCopy'], $done['missing'], $done['extra'], $done['differing']]);
        $this->assertSame([], $this->odoo->writes);
    }

    public function test_the_content_of_attachments_is_kept_on_a_disk_of_ops(): void
    {
        Storage::fake('local');
        config(['odoo.files.max_mb' => 1]);
        $pdf = "%PDF-1.4\nفاتورة ضريبية INV/2026/0001\n";
        $slip = "%PDF-1.4\nكشف راتب\n";
        $this->odoo->model('ir.attachment', ['name' => 'char', 'type' => 'selection', 'res_model' => 'char', 'res_id' => 'integer', 'res_field' => 'char', 'mimetype' => 'char',
            'file_size' => 'integer', 'checksum' => 'char', 'url' => 'char', 'datas' => 'binary'], 'Attachment');
        $add = fn (array $v) => $this->odoo->create('ir.attachment', $v + ['type' => 'binary', 'mimetype' => 'application/pdf']);
        $add(['name' => 'INV-2026-0001.pdf', 'res_model' => 'account.move', 'res_id' => 44, 'file_size' => strlen($pdf), 'checksum' => sha1($pdf), 'datas' => base64_encode($pdf)]);          // 1
        $add(['name' => 'الموقع', 'type' => 'url', 'url' => 'https://example.com/x', 'res_model' => 'res.partner', 'res_id' => 5]);                                                       // 2 a link
        $add(['name' => 'web.assets_frontend.min.js', 'res_model' => 'ir.ui.view', 'res_id' => 9, 'file_size' => 10, 'checksum' => sha1('x'), 'datas' => base64_encode('x')]);            // 3 machinery
        $add(['name' => 'damaged.pdf', 'res_model' => 'sale.order', 'res_id' => 3, 'file_size' => 5, 'checksum' => sha1('what Odoo says'), 'datas' => base64_encode('other')]);           // 4 checksum
        $add(['name' => 'huge.mp4', 'res_model' => 'sale.order', 'res_id' => 3, 'file_size' => 5 * 1048576, 'checksum' => sha1('v'), 'datas' => base64_encode('v')]);                      // 5 too large
        $add(['name' => 'payslip.pdf', 'res_model' => 'hr.payslip', 'res_id' => 1, 'file_size' => strlen($slip), 'checksum' => sha1($slip), 'datas' => base64_encode($slip)]);            // 6 people's file
        $add(['name' => 'lost.pdf', 'res_model' => 'sale.order', 'res_id' => 3, 'file_size' => 7, 'checksum' => sha1('lost'), 'datas' => false]);                                       // 7 Odoo lost its content
        $add(['name' => 'removed.pdf', 'res_model' => 'sale.order', 'res_id' => 3, 'file_size' => 4, 'checksum' => sha1('gone'), 'datas' => base64_encode('gone')]);                     // 8 deleted after it was listed

        $files = $this->app->make(OdooFiles::class);
        $this->assertSame('the list of attachments is not copied yet', $files->run(10)['reason']);
        $this->sync();
        $this->assertSame(8, OdooRecord::where('model', 'ir.attachment')->count());
        $this->odoo->unlink('ir.attachment', 8);
        $this->assertStringNotContainsString(base64_encode($pdf), json_encode(OdooRecord::pluck('data')), 'the list is in the database, the content is not');

        $run = $files->run(30);
        // only what a later try could change counts as failed (here: the damaged file); what is gone or empty in Odoo is told with its reason
        $this->assertSame([2, strlen($pdf) + strlen($slip), 5, 1, 0], [$run['stored'], $run['bytes'], $run['skipped'], $run['failed'], $run['left']]);
        $this->assertSame([['skipped', 'Odoo holds no content for it'], ['skipped', 'no longer in Odoo']],
            OdooFile::whereIn('attachment_id', [7, 8])->orderBy('attachment_id')->get()->map(fn ($f) => [$f->status, $f->note])->all());
        $kept = OdooFile::where('attachment_id', 1)->firstOrFail();
        $this->assertSame(['stored', 'account.move', 44, strlen($pdf), sha1($pdf)], [$kept->status, $kept->res_model, $kept->res_id, $kept->size, $kept->checksum]);
        $this->assertSame($pdf, Storage::disk('local')->get($kept->path));
        $this->assertSame(['a link, not a file', 'Odoo machinery, not a document of the company', 'the content does not match the checksum Odoo states', 'larger than 1 MB'],
            OdooFile::whereIn('attachment_id', [2, 3, 4, 5])->orderBy('attachment_id')->pluck('note')->all());
        $this->assertSame(0, $files->run(30)['stored'], 'a file is read from Odoo once');
        $status = $this->expectOk($this->getAs('gm', '/api/integration/odoo/status'))['backup']['files'];
        $this->assertSame([8, 2, 5, 1, 0], [$status['listed'], $status['stored'], $status['skipped'], $status['failed'], $status['left']]);

        // reading a kept file: the archive or the tower; people's files only with odoo.private
        $got = $this->getAs('gm', '/api/odoo-backup/files/1');
        $got->assertOk();
        $this->assertSame($pdf, $got->streamedContent());
        $this->expectRejected($this->getAs('worker', '/api/odoo-backup/files/1'), 'FORBIDDEN', [403]);
        $this->expectRejected($this->getAs('gm', '/api/odoo-backup/files/6'), 'ODOO_PRIVATE', [403]);
        $this->assertSame($slip, $this->getAs('admin', '/api/odoo-backup/files/6')->assertOk()->streamedContent());
        $this->expectRejected($this->getAs('gm', '/api/odoo-backup/files/4'), 'ODOO_FILE_NOT_ARCHIVED', [404]);

        // the damaged file is repaired in Odoo: failed files are looked at again when asked
        $this->odoo->models['ir.attachment']['rows'][4]['datas'] = base64_encode('what Odoo says');
        $this->assertSame(1, $files->retryFailed());
        $this->assertSame(1, $files->run(30)['stored']);
        $this->assertSame([], $this->odoo->writes);
    }

    public function test_the_copy_can_be_put_in_a_safe_as_files_and_loaded_back(): void
    {
        $this->orders(5);
        $this->odoo->model('res.partner', ['name' => 'char', 'active' => 'boolean'], 'Contact');
        $this->odoo->create('res.partner', ['id' => 7, 'name' => 'مطعم الواحة']);
        $this->odoo->create('res.partner', ['id' => 8, 'name' => 'مؤرشف', 'active' => false]);
        $this->sync();
        $this->odoo->unlink('sale.order', 5);
        OdooModel::where('model', 'sale.order')->update(['last_deletion_check_at' => null]);
        $this->sync();
        $before = OdooRecord::orderBy('model')->orderBy('odoo_id')->get(['model', 'odoo_id', 'display_name', 'hash', 'active', 'deleted_at'])
            ->map(fn ($r) => [$r->model, $r->odoo_id, $r->display_name, $r->hash, $r->active, $r->deleted_at !== null])->all();

        $dir = storage_path('framework/testing/odoo-export-'.self::uid());
        $export = $this->app->make(OdooExport::class);
        $manifest = $export->export($dir);
        $this->assertSame([7, ['res.partner' => 2, 'sale.order' => 5]], [$manifest['records'], collect($manifest['models'])->pluck('records', 'model')->sortKeys()->all()]);
        // readable by anything: one record per line, as Odoo returned it
        $first = json_decode((string) gzgets(gzopen("{$dir}/sale.order.jsonl.gz", 'rb')), true);
        $this->assertSame([1, 'S00001', 'sale'], [$first['id'], $first['display_name'], $first['data']['state']]);
        $this->assertSame(['ok' => true, 'models' => 2, 'records' => 7, 'bad' => []], $export->check($dir));

        // an installation that has to stand without Odoo: load the files into an empty mirror
        $this->expectExceptionObjectIsRefused(fn () => $export->import($dir), 'ODOO_MIRROR_NOT_EMPTY');
        DB::table('odoo_records')->delete();
        DB::table('odoo_models')->delete();
        $this->assertSame(['models' => 2, 'records' => 7], $export->import($dir));
        $after = OdooRecord::orderBy('model')->orderBy('odoo_id')->get(['model', 'odoo_id', 'display_name', 'hash', 'active', 'deleted_at'])
            ->map(fn ($r) => [$r->model, $r->odoo_id, $r->display_name, $r->hash, $r->active, $r->deleted_at !== null])->all();
        $this->assertSame($before, $after, 'the same records with the same values');
        $restored = OdooModel::where('model', 'sale.order')->firstOrFail();
        $this->assertSame(['paused', 'restored from an archive', 4], [$restored->status, $restored->reason, $restored->mirrored_count]);
        $this->assertSame(4, $this->expectOk($this->getAs('gm', '/api/integration/odoo/records?model=sale.order'))['total']);

        // a file that was altered is found before anything is loaded
        file_put_contents("{$dir}/res.partner.jsonl.gz", gzencode('{"id":7,"display_name":"x","data":{}}'."\n"));
        $this->assertSame([false, ['res.partner']], [$export->check($dir)['ok'], $export->check($dir)['bad']]);
        $this->expectExceptionObjectIsRefused(fn () => $export->import($dir, true), 'ODOO_ARCHIVE_DAMAGED');
        File::deleteDirectory($dir);
    }

    private function expectExceptionObjectIsRefused(callable $work, string $code): void
    {
        try {
            $work();
            $this->fail("expected {$code}");
        } catch (AppError $e) {
            $this->assertSame($code, $e->errorCode, $e->getMessage());
        }
    }
}
