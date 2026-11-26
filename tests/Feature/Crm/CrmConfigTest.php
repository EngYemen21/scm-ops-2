<?php

namespace Tests\Feature\Crm;

use App\Crm\CrmBootstrap;
use App\Crm\Models\CrmLostReason;
use App\Crm\Models\CrmStage;
use App\Crm\Models\CrmTag;
use App\Crm\Models\CrmTeam;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/**
 * What the pipeline is made of — stages, sales teams, lost reasons, tags: added and changed by crm.manage, archived
 * instead of deleted, and never changed under the opportunities that stand on them.
 */
class CrmConfigTest extends ApiTestCase
{
    use CrmFixtures;

    public function test_stages_are_added_ordered_and_archived_but_not_under_their_opportunities(): void
    {
        $tag = self::tag();
        $this->expectRejected($this->postAs('sales', '/api/crm/stages', ['nameEn' => 'No Arabic name']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', '/api/crm/stages', ['nameAr' => 'س', 'probability' => 150]), 'INVALID_INPUT', [400]);

        $last = (int) CrmStage::max('sequence');
        $s = $this->expectOk($this->postAs('sales', '/api/crm/stages', ['nameAr' => "تفاوض {$tag}", 'probability' => 60]));
        $this->assertSame(["تفاوض {$tag}", "تفاوض {$tag}", $last + 1, false, false, true, null, false], [$s['nameAr'], $s['nameEn'], $s['sequence'], $s['isWon'], $s['fold'], $s['active'], $s['sourceSystem'], $s['owned']]);
        $this->assertEquals(60, $s['probability']);
        $this->assertSame('sales', AuditLog::where('action', 'CRM.STAGE.CREATE')->where('entity_id', $s['id'])->value('username'));

        $edited = $this->expectOk($this->patchAs('sales', "/api/crm/stages/{$s['id']}", ['nameEn' => "Negotiation {$tag}", 'sequence' => 90025, 'fold' => true, 'requirements' => 'عرض سعر مُرسل', 'probability' => null]));
        $this->assertSame(["Negotiation {$tag}", 90025, true, 'عرض سعر مُرسل', null, 5], [$edited['nameEn'], $edited['sequence'], $edited['fold'], $edited['requirements'], $edited['probability'], $edited['changed']]);
        $this->assertSame(0, $this->expectOk($this->patchAs('sales', "/api/crm/stages/{$s['id']}", ['fold' => true]))['changed'], 'the same values again change nothing');
        $this->assertSame(1, AuditLog::where('action', 'CRM.STAGE.UPDATE')->where('entity_id', $s['id'])->count());
        $this->expectRejected($this->patchAs('sales', "/api/crm/stages/{$s['id']}", ['nameAr' => '']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->patchAs('sales', '/api/crm/stages/nope', ['fold' => true]), 'CRM_STAGE_NOT_FOUND', [404]);

        // the board shows it where its sequence puts it: between "visit" (90020) and "offer" (90030)
        $stages = $this->pipeline();
        $order = array_column($this->expectOk($this->getAs('gm', '/api/crm/lookups'))['stages'], 'id');
        $this->assertTrue(array_search($stages['visit'], $order) < array_search($s['id'], $order) && array_search($s['id'], $order) < array_search($stages['offer'], $order));

        // a stage that holds open opportunities is neither archived nor turned into a won stage
        $lead = $this->newLead(['stageId' => $s['id']]);
        $inUse = $this->expectRejected($this->patchAs('sales', "/api/crm/stages/{$s['id']}", ['active' => false]), 'CRM_STAGE_IN_USE', [422]);
        $this->assertSame(1, $inUse['details']['leads']);
        $this->expectRejected($this->patchAs('sales', "/api/crm/stages/{$s['id']}", ['isWon' => true]), 'CRM_STAGE_IN_USE', [422]);
        $this->expectOk($this->postAs('sales', "/api/crm/leads/{$lead['id']}/move", ['stageId' => $stages['offer']]));
        $archived = $this->expectOk($this->patchAs('sales', "/api/crm/stages/{$s['id']}", ['active' => false]));
        $this->assertFalse($archived['active']);
        $this->assertNotNull(CrmStage::find($s['id']), 'archived, not deleted');
        $this->expectRejected($this->postAs('sales', '/api/crm/leads', ['name' => 'س', 'stageId' => $s['id']]), 'CRM_STAGE_INACTIVE', [422]);

        // a won stage carries 100 %
        $won = $this->expectOk($this->postAs('sales', '/api/crm/stages', ['nameAr' => "تم التعاقد {$tag}", 'isWon' => true, 'probability' => 20]));
        $this->assertEquals([true, 100], [$won['isWon'], $won['probability']]);
        $this->expectOk($this->patchAs('sales', "/api/crm/stages/{$won['id']}", ['active' => false]));
    }

    public function test_the_pipeline_always_keeps_one_open_stage(): void
    {
        $stages = $this->pipeline();
        // inside a transaction that is rolled back: the other stories keep their stages
        DB::beginTransaction();
        try {
            CrmStage::where('id', '!=', $stages['new'])->where('is_won', false)->update(['active' => false]);
            DB::table('crm_leads')->where('stage_id', $stages['new'])->update(['archived' => true]);
            $this->expectRejected($this->patchAs('sales', "/api/crm/stages/{$stages['new']}", ['active' => false]), 'CRM_STAGE_LAST', [422]);
            $this->expectRejected($this->patchAs('sales', "/api/crm/stages/{$stages['new']}", ['isWon' => true]), 'CRM_STAGE_LAST', [422]);
            $this->assertTrue((bool) CrmStage::findOrFail($stages['new'])->active);
            // with no open stage at all a lead cannot be entered — and the refusal says what to do
            CrmStage::where('is_won', false)->update(['active' => false]);
            $none = $this->expectRejected($this->postAs('sales', '/api/crm/leads', ['name' => 'بلا مسار']), 'CRM_NO_STAGE', [422]);
            $this->assertStringContainsString('الإعدادات', $none['message']);
            CrmStage::where('is_won', true)->update(['active' => false]);
            CrmStage::whereKey($stages['new'])->update(['active' => true]);
            $lead = $this->newLead();
            $this->expectRejected($this->postAs('sales', "/api/crm/leads/{$lead['id']}/win"), 'CRM_NO_WON_STAGE', [422]);
        } finally {
            DB::rollBack();
        }
        $this->assertTrue((bool) CrmStage::findOrFail($stages['visit'])->active, 'nothing of this story was kept');
    }

    public function test_teams_reasons_and_tags(): void
    {
        $tag = self::tag();
        $admin = $this->userId('admin');

        // ── teams
        $this->expectRejected($this->postAs('sales', '/api/crm/teams', ['nameAr' => 'فريق', 'leaderId' => 'nobody']), 'CRM_USER_UNKNOWN', [400]);
        $team = $this->expectOk($this->postAs('sales', '/api/crm/teams', ['nameAr' => "مبيعات الفنادق {$tag}", 'nameEn' => "Hotels {$tag}", 'leaderId' => $admin]));
        $this->assertSame(["مبيعات الفنادق {$tag}", "Hotels {$tag}", true, true, $admin, 'admin'], [$team['nameAr'], $team['nameEn'], $team['pipeline'], $team['active'], $team['leader']['id'], $team['leader']['username']]);
        $team = $this->expectOk($this->patchAs('sales', "/api/crm/teams/{$team['id']}", ['nameAr' => "مبيعات الضيافة {$tag}", 'leaderId' => null]));
        $this->assertSame(["مبيعات الضيافة {$tag}", null, 2], [$team['nameAr'], $team['leader'], $team['changed']]);
        $lead = $this->newLead(['teamId' => $team['id']]);
        $this->expectOk($this->patchAs('sales', "/api/crm/teams/{$team['id']}", ['active' => false]));
        $this->expectRejected($this->postAs('sales', '/api/crm/leads', ['name' => 'س', 'stageId' => $this->pipeline()['new'], 'teamId' => $team['id']]), 'CRM_TEAM_INACTIVE', [422]);
        $this->assertSame($team['id'], $this->expectOk($this->patchAs('sales', "/api/crm/leads/{$lead['id']}", ['teamId' => $team['id'], 'city' => 'مكة']))['team']['id'], 'a lead keeps the archived team it already has');
        $this->expectRejected($this->patchAs('sales', '/api/crm/teams/nope', ['active' => true]), 'CRM_TEAM_NOT_FOUND', [404]);

        // ── lost reasons
        $reason = $this->expectOk($this->postAs('sales', '/api/crm/lost-reasons', ['nameAr' => "أغلق نشاطه {$tag}"]));
        $this->assertSame(["أغلق نشاطه {$tag}", "أغلق نشاطه {$tag}", true], [$reason['nameAr'], $reason['nameEn'], $reason['active']]);
        $reason = $this->expectOk($this->patchAs('sales', "/api/crm/lost-reasons/{$reason['id']}", ['nameEn' => "Closed down {$tag}", 'active' => false]));
        $this->assertSame(["Closed down {$tag}", false], [$reason['nameEn'], $reason['active']]);
        $this->assertNotNull(CrmLostReason::find($reason['id']));
        $this->expectRejected($this->patchAs('sales', '/api/crm/lost-reasons/nope', ['active' => true]), 'CRM_REASON_NOT_FOUND', [404]);

        // ── tags: one tag per name, whatever the letter case
        $t = $this->expectOk($this->postAs('sales', '/api/crm/tags', ['name' => "Hotel {$tag}", 'color' => 4]));
        $this->assertSame(["Hotel {$tag}", 4, true], [$t['name'], $t['color'], $t['active']]);
        $this->expectRejected($this->postAs('sales', '/api/crm/tags', ['name' => "hotel {$tag}"]), 'CRM_TAG_EXISTS', [422]);
        $this->expectRejected($this->postAs('sales', '/api/crm/tags', ['name' => 'x', 'color' => 12]), 'INVALID_INPUT', [400]);
        $other = $this->expectOk($this->postAs('sales', '/api/crm/tags', ['name' => "Café {$tag}"]));
        $this->expectRejected($this->patchAs('sales', "/api/crm/tags/{$other['id']}", ['name' => "HOTEL {$tag}"]), 'CRM_TAG_EXISTS', [422]);
        $renamed = $this->expectOk($this->patchAs('sales', "/api/crm/tags/{$other['id']}", ['name' => "Coffee shop {$tag}", 'color' => 7, 'active' => false]));
        $this->assertSame(["Coffee shop {$tag}", 7, false], [$renamed['name'], $renamed['color'], $renamed['active']]);
        $this->assertNotNull(CrmTag::find($other['id']));
        $this->assertNotNull(CrmTeam::find($team['id']));
        $this->assertSame(['CRM.REASON.CREATE', 'CRM.REASON.UPDATE', 'CRM.TAG.CREATE', 'CRM.TAG.UPDATE', 'CRM.TEAM.CREATE', 'CRM.TEAM.UPDATE'],
            AuditLog::whereIn('entity_id', [$team['id'], $reason['id'], $other['id']])->distinct()->orderBy('action')->pluck('action')->all());
    }

    public function test_a_first_pipeline_is_seeded_only_into_empty_tables_and_only_where_the_section_does_not_follow_odoo(): void
    {
        $this->pipeline();
        $counts = fn () => [CrmStage::count(), CrmLostReason::count(), CrmTeam::count()];
        $before = $counts();
        // the tables hold records already: nothing is added, whatever the section follows
        $this->followOdoo(false);
        CrmTeam::firstOrCreate(['name_en' => 'QA seeded team'], ['name_ar' => 'فريق', 'sequence' => 1, 'pipeline' => true, 'active' => true]);
        $this->lostReason();
        $this->assertSame(['stages' => 0, 'reasons' => 0, 'teams' => 0], CrmBootstrap::ensure());
        $this->followOdoo(true);
        $this->assertSame(['stages' => 0, 'reasons' => 0, 'teams' => 0], CrmBootstrap::ensure(), 'where the section follows Odoo its stages come from the copy');

        // empty tables: a pipeline of five stages ending in a won one, the usual reasons and one team — once
        DB::beginTransaction();
        try {
            foreach (['crm_stages', 'crm_lost_reasons', 'crm_teams'] as $table) {
                DB::table($table)->delete();
            }
            $this->assertSame(['stages' => 0, 'reasons' => 0, 'teams' => 0], CrmBootstrap::ensure(), 'following Odoo: still nothing');
            $this->followOdoo(false);
            $this->assertSame(['stages' => 5, 'reasons' => 7, 'teams' => 1], CrmBootstrap::ensure());
            $stages = CrmStage::query()->ordered()->get();
            $this->assertSame([false, false, false, false, true], $stages->pluck('is_won')->all());
            $this->assertSame(['New', 'Visit', 'Qualified', 'Proposition', 'Won'], $stages->pluck('name_en')->all());
            $this->assertSame(['stages' => 0, 'reasons' => 0, 'teams' => 0], CrmBootstrap::ensure(), 'a second call adds nothing');
        } finally {
            DB::rollBack();
        }
        $this->assertGreaterThanOrEqual($before[0], $counts()[0]);
    }
}
