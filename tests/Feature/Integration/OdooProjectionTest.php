<?php

namespace Tests\Feature\Integration;

use App\Integration\Models\IntExternalRef;
use App\Integration\Odoo\Models\OdooChange;
use App\Integration\Odoo\Models\OdooRecord;
use App\Integration\Odoo\Models\OdooRun;
use App\Integration\Odoo\OdooSync;
use App\Integration\Odoo\Projection\OdooProjection;
use App\Integration\Odoo\Projection\Val;
use App\Models\ProductCategory;
use App\Support\AppError;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/** A small projection for the test: Odoo's partner tags become OPS product categories (any OPS table would do). */
class TagsProjection extends OdooProjection
{
    public function key(): string
    {
        return 'tags';
    }

    public function models(): array
    {
        return ['res.partner.category'];
    }

    protected function project(OdooRecord $r): void
    {
        $name = Val::text($r->data['name'] ?? null) ?? '';
        if ($name === 'boom') {
            throw new \RuntimeException('this tag cannot be applied');
        }
        $id = $this->linked('tag', $r->odoo_id);
        if (! $this->alive($r)) {
            $this->count($id ? 'archived' : 'skippedArchived');
            if ($id && ! $this->dry) {
                ProductCategory::whereKey($id)->update(['active' => false]);
            }

            return;
        }
        if (! $id) {
            $this->count('created', $name);
            if (! $this->dry) {
                $c = ProductCategory::create(['code' => 'tg-'.$r->odoo_id.'-'.substr(md5($name), 0, 4), 'name_ar' => $name, 'name_en' => $name, 'active' => true]);
                $this->link('tag', $r->odoo_id, (string) $c->id, $c->code);
            }

            return;
        }
        $c = ProductCategory::findOrFail($id);
        if ($c->name_ar === $name && $c->active) {
            $this->count('unchanged');

            return;
        }
        $this->count('updated');
        if (! $this->dry) {
            $c->update(['name_ar' => $name, 'name_en' => $name, 'active' => true]);
        }
    }
}

/** A second section that reads the same Odoo model as the first, and a model the master data consumes: it only notes what it is given. */
class TagsLogProjection extends OdooProjection
{
    /** @var string[] */
    public static array $seen = [];

    public function key(): string
    {
        return 'tagslog';
    }

    public function models(): array
    {
        return ['res.partner.category', 'res.partner'];
    }

    protected function project(OdooRecord $r): void
    {
        if (! $this->dry) {
            self::$seen[] = "{$r->model}:{$r->odoo_id}";
        }
        $this->count('seen');
    }
}

/**
 * The base every "OPS section that follows Odoo" is built on (OdooProjection): plan / apply / feed, links by
 * identity, one bad record never stops the rest — and the runs of the mirror call it by themselves.
 */
class OdooProjectionTest extends ApiTestCase
{
    private FakeOdoo $odoo;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['odoo_runs', 'odoo_changes', 'odoo_records', 'odoo_models', 'odoo_projections'] as $table) {
            DB::table($table)->delete();
        }
        IntExternalRef::where('system', 'odoo')->where('entity', 'tag')->delete();
        Cache::flush();
        config(['odoo' => FakeOdoo::config(['priority' => ['res.partner.category'], 'projections' => ['tags' => TagsProjection::class], 'project' => ['master' => false, 'tags' => false]])]);
        $this->odoo = (new FakeOdoo)->install();
        $this->odoo->model('res.partner.category', ['name' => 'char', 'active' => 'boolean'], 'Partner Tag');
    }

    private function sync(): array
    {
        return $this->app->make(OdooSync::class)->run(30, 'test');
    }

    public function test_values_are_read_the_way_odoo_writes_them(): void
    {
        $this->assertSame([5, 'مطعم', null, null], [Val::id([5, 'مطعم']), Val::label([5, 'مطعم']), Val::id(false), Val::label(false)]);
        $this->assertSame([[3, 9], [], 'نص', null, null], [Val::ids([3, 9]), Val::ids(false), Val::text('  نص '), Val::text(false), Val::text('')]);
        $this->assertSame("سطر أول\nسطر ثانٍ & أكثر", Val::plain('<p>سطر أول</p><p>سطر ثانٍ &amp; أكثر</p>'));
        $this->assertSame([12.5, 0.0, true, false, '2026-11-22', null], [Val::float(12.5), Val::float(false), Val::bool(true), Val::bool(false), Val::date('2026-11-22 09:30:00'), Val::date(false)]);
        $this->assertSame('2026-11-22T09:30:00+00:00', Val::at('2026-11-22 09:30:00')->toIso8601String());
        $this->assertNull(Val::at(false));
    }

    public function test_plan_apply_and_the_feed(): void
    {
        $tag = self::uid();
        foreach (["عملاء ذهبيون {$tag}", "مطاعم {$tag}", 'boom'] as $name) {
            $this->odoo->create('res.partner.category', ['name' => $name]);
        }
        $this->odoo->create('res.partner.category', ['name' => "قديم {$tag}", 'active' => false]);
        $projection = $this->app->make(TagsProjection::class);

        // the copy is not there yet: nothing is guessed from half a picture
        try {
            $projection->plan();
            $this->fail('expected ODOO_COPY_INCOMPLETE');
        } catch (AppError $e) {
            $this->assertSame('ODOO_COPY_INCOMPLETE', $e->errorCode);
        }
        $this->sync();
        $this->assertTrue($projection->ready());

        $before = ProductCategory::count();
        $plan = $projection->plan();
        $this->assertSame([true, ['created' => 2, 'failed' => 1, 'skippedArchived' => 1], 1], [$plan['dryRun'], $plan['counts'], $plan['failed']]);
        $this->assertSame([$before, 0], [ProductCategory::count(), IntExternalRef::where('system', 'odoo')->where('entity', 'tag')->count()], 'a plan writes nothing');
        $this->assertStringContainsString('res.partner.category #3', $plan['notes']['failed'][0]);

        $applied = $projection->apply();
        $this->assertSame(['created' => 2, 'failed' => 1, 'skippedArchived' => 1], $applied['counts']);
        $this->assertSame($before + 2, ProductCategory::count(), 'the record that failed did not stop the others');
        $this->assertSame(['unchanged' => 2, 'failed' => 1, 'skippedArchived' => 1], $projection->apply()['counts'], 'a second pass changes nothing');

        // the area does not follow Odoo yet: the mirror knows what changed, OPS waits
        $this->odoo->write('res.partner.category', 1, ['name' => "عملاء بلاتينيون {$tag}"]);
        $this->odoo->write('res.partner.category', 2, ['active' => false]);
        $this->odoo->create('res.partner.category', ['name' => "فنادق {$tag}"]);
        $this->sync();
        $this->assertSame(3, $projection->waiting());
        $this->assertSame(0, ProductCategory::where('name_ar', "عملاء بلاتينيون {$tag}")->count());

        // it follows Odoo: the next run applies what the feed reported, and says so in its own line
        config(['odoo.project.tags' => true]);
        $this->assertTrue(OdooProjection::owns('tags'));
        $run = $this->sync();
        $step = OdooRun::findOrFail($run['runId'])->detail['models']['→ OPS tags'];
        $this->assertSame([3, 1, 1, 1], [$step['changes'], $step['counts']['updated'], $step['counts']['archived'], $step['counts']['created']]);
        $this->assertSame([1, 0], [ProductCategory::where('name_ar', "عملاء بلاتينيون {$tag}")->count(), $projection->waiting()]);
        $this->assertFalse((bool) ProductCategory::where('name_ar', "مطاعم {$tag}")->value('active'));
        $this->assertSame(0, OdooChange::where('model', 'res.partner.category')->whereNull('processed_at')->count());
        $this->assertSame([], $this->odoo->writes);
    }

    public function test_the_runs_feed_a_section_only_after_its_first_whole_load(): void
    {
        $tag = self::uid();
        $this->odoo->create('res.partner.category', ['name' => "أولى {$tag}"]);
        config(['odoo.project.tags' => true]); // the section follows Odoo from the first day …
        $projection = $this->app->make(TagsProjection::class);
        $this->sync();
        $this->odoo->write('res.partner.category', 1, ['name' => "أولى معدلة {$tag}"]);
        $this->odoo->create('res.partner.category', ['name' => "ثانية {$tag}"]);
        $run = $this->sync();

        // … but nobody applied its plan yet: the runs leave it alone (half a section is worse than none), without an error
        $this->assertFalse($projection->applied());
        $this->assertArrayNotHasKey('→ OPS tags', OdooRun::findOrFail($run['runId'])->detail['models'] ?? []);
        $this->assertSame(0, $run['errors']);
        $this->assertSame(0, ProductCategory::where('name_ar', 'like', "%{$tag}")->count());
        $this->assertGreaterThan(0, $projection->waiting());

        // a person applies the plan once: everything is there, the feed is taken, the section is open to the runs
        $this->assertSame(['created' => 2], $projection->apply()['counts']);
        $this->assertTrue($projection->applied());
        $this->assertSame([2, 0], [ProductCategory::where('name_ar', 'like', "%{$tag}")->count(), $projection->waiting()]);
        $first = OdooProjection::states()['tags'];
        $this->assertNotNull($first['firstAppliedAt']);
        $this->assertSame(['created' => 2], $first['lastCounts']);
        $this->assertNull($first['lastFedAt']);

        $this->odoo->write('res.partner.category', 2, ['name' => "ثانية معدلة {$tag}"]);
        $run = $this->sync();
        $this->assertSame(1, OdooRun::findOrFail($run['runId'])->detail['models']['→ OPS tags']['counts']['updated']);
        $state = OdooProjection::states()['tags'];
        $this->assertNotNull($state['lastFedAt']);
        $this->assertSame($first['firstAppliedAt'], $state['firstAppliedAt'], 'the first load is remembered as the first');
        $this->assertSame([], $this->odoo->writes);
    }

    public function test_every_section_keeps_its_own_place_in_the_feed(): void
    {
        $tag = self::uid();
        config(['odoo.projections' => ['tags' => TagsProjection::class, 'tagslog' => TagsLogProjection::class], 'odoo.project.tags' => true, 'odoo.project.tagslog' => true]);
        $this->odoo->model('res.partner', ['name' => 'char'], 'Contact'); // a model the master data consumes
        $this->odoo->create('res.partner.category', ['name' => "أ {$tag}"]);
        $this->odoo->create('res.partner', ['name' => "عميل {$tag}"]);
        $this->sync();
        $tags = $this->app->make(TagsProjection::class);
        $log = $this->app->make(TagsLogProjection::class);
        $tags->apply();
        $log->apply();
        TagsLogProjection::$seen = [];

        $this->odoo->write('res.partner.category', 1, ['name' => "ب {$tag}"]);
        $this->odoo->write('res.partner', 1, ['name' => "عميل جديد {$tag}"]);
        $run = $this->sync();
        $detail = OdooRun::findOrFail($run['runId'])->detail['models'];
        // both sections took the tag's change: the one that came first did not take it away from the other
        $this->assertSame(1, $detail['→ OPS tags']['counts']['updated']);
        $this->assertSame(2, $detail['→ OPS tagslog']['counts']['seen']);
        $this->assertEqualsCanonicalizing(['res.partner.category:1', 'res.partner:1'], TagsLogProjection::$seen);
        $this->assertSame([0, 0], [$tags->waiting(), $log->waiting()]);
        // …and the partner's change is still the master data's to take: a section does not spend that mark
        $this->assertSame(1, OdooChange::where('model', 'res.partner')->whereNull('processed_at')->count());

        // a quiet feed repeats nothing; a section that was away catches up from its own place
        TagsLogProjection::$seen = [];
        $this->sync();
        $this->assertSame([], TagsLogProjection::$seen);
        config(['odoo.project.tagslog' => false]);
        $this->odoo->write('res.partner.category', 1, ['name' => "ج {$tag}"]);
        $this->sync();
        $this->assertSame([[], 0, 1], [TagsLogProjection::$seen, $tags->waiting(), $log->waiting()]);
        config(['odoo.project.tagslog' => true]);
        $this->sync();
        $this->assertSame([['res.partner.category:1'], 0], [TagsLogProjection::$seen, $log->waiting()]);
        $this->assertSame([], $this->odoo->writes);
    }
}
