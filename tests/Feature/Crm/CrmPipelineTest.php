<?php

namespace Tests\Feature\Crm;

use App\Crm\Models\CrmActivity;
use App\Crm\Models\CrmLead;
use App\Crm\Models\CrmLeadEvent;
use App\Crm\Models\CrmStage;
use App\Crm\Models\CrmTag;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\StatusHistory;
use Tests\ApiTestCase;

/**
 * The pipeline: who may read and change it, a lead from entry to closing, the lists (search, filters, sort, paging),
 * the board, moving between stages with the history kept, won / lost with a reason, reopening — and every illegal
 * move refused with its code.
 */
class CrmPipelineTest extends ApiTestCase
{
    use CrmFixtures;

    public function test_reading_needs_page_crm_and_changing_needs_crm_manage(): void
    {
        $lead = $this->newLead();
        $before = [CrmLead::count(), CrmLeadEvent::count()];

        // no page.crm: the section is closed, reads included
        foreach (['/api/crm/lookups', '/api/crm/leads', '/api/crm/board', "/api/crm/leads/{$lead['number']}", '/api/crm/activities', '/api/crm/report/funnel'] as $url) {
            $this->expectRejected($this->getAs('wm', $url), 'FORBIDDEN', [403]);
            $this->expectOk($this->getAs('gm', $url)); // page.crm without crm.manage: reads everything
        }
        // page.crm alone changes nothing
        $stages = $this->pipeline();
        foreach ([
            ['/api/crm/leads', ['name' => 'محاولة']], ["/api/crm/leads/{$lead['id']}/move", ['stageId' => $stages['visit']]], ["/api/crm/leads/{$lead['id']}/win", []],
            ["/api/crm/leads/{$lead['id']}/lose", ['reasonId' => $this->lostReason()]], ["/api/crm/leads/{$lead['id']}/notes", ['text' => 'ملاحظة']],
            ["/api/crm/leads/{$lead['id']}/activities", ['type' => 'call', 'dueDate' => now()->toDateString()]], ["/api/crm/leads/{$lead['id']}/convert", ['confirmNew' => true]],
            ['/api/crm/stages', ['nameAr' => 'مرحلة']], ['/api/crm/lost-reasons', ['nameAr' => 'سبب']], ['/api/crm/tags', ['name' => 'وسم']], ['/api/crm/teams', ['nameAr' => 'فريق']],
        ] as [$url, $body]) {
            $this->expectRejected($this->postAs('gm', $url, $body), 'FORBIDDEN', [403]);
        }
        $this->expectRejected($this->patchAs('gm', "/api/crm/leads/{$lead['id']}", ['name' => 'تعديل']), 'FORBIDDEN', [403]);
        $this->assertSame($before, [CrmLead::count(), CrmLeadEvent::count()], 'a refused request changes nothing');
        $this->assertSame($lead['name'], $this->lead($lead['id'], 'gm')['name']);
        $this->assertGreaterThan(0, AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->where('username', 'gm')->where('entity_id', 'like', '%api/crm/%')->count(), 'the denied attempts are on the audit trail');
        $this->expectRejected($this->getAs('sales', '/api/crm/leads/OPP-NOPE'), 'LEAD_NOT_FOUND', [404]);
    }

    public function test_the_lookups_offer_what_the_forms_and_filters_need(): void
    {
        $stages = $this->pipeline();
        $lk = $this->expectOk($this->getAs('sales', '/api/crm/lookups'));
        $this->assertSame(['follows', 'stages', 'teams', 'lostReasons', 'tags', 'users', 'owners', 'activityTypes', 'priorities', 'sources'], array_keys($lk));
        $mine = array_values(array_filter($lk['stages'], fn ($s) => in_array($s['id'], $stages, true)));
        $this->assertSame(['QA New', 'QA Visit', 'QA Offer', 'QA Won'], array_column($mine, 'nameEn'), 'stages come in the order of the board');
        $this->assertSame([false, false, false, true], array_column($mine, 'isWon'));
        $this->assertSame(['id', 'nameAr', 'nameEn', 'sequence', 'isWon', 'fold', 'probability', 'requirements', 'teamId', 'active', 'sourceSystem', 'externalRef', 'owned'], array_keys($mine[0]));
        $this->assertContains('sales', array_column($lk['users'], 'username'), 'holders of crm.manage may be given a lead');
        $this->assertNotContains('gm', array_column($lk['users'], 'username'));
        $this->assertEmpty(array_filter(array_column($lk['users'], 'username'), fn ($u) => str_starts_with($u, 'svc.')), 'service accounts never own a lead');
        $this->assertSame(['call', 'visit', 'meeting', 'email', 'message', 'sample', 'todo'], array_column($lk['activityTypes'], 'key'));
        $this->assertSame([0, 1, 2, 3], array_column($lk['priorities'], 'key'));
    }

    public function test_a_lead_is_entered_edited_and_audited(): void
    {
        $stages = $this->pipeline();
        $team = $this->newTeam();
        $tag = CrmTag::create(['name' => 'مطعم '.self::tag(), 'color' => 3, 'active' => true]);
        $sales = $this->userId('sales');

        // validation: a title is needed, an e-mail must be one, the people and tags named must exist
        $this->expectRejected($this->postAs('sales', '/api/crm/leads', ['companyName' => 'بلا عنوان']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', '/api/crm/leads', ['name' => 'س', 'email' => 'not-an-email']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', '/api/crm/leads', ['name' => 'س', 'stageId' => $stages['new'], 'ownerId' => 'nobody']), 'CRM_USER_UNKNOWN', [400]);
        $this->expectRejected($this->postAs('sales', '/api/crm/leads', ['name' => 'س', 'stageId' => $stages['new'], 'tagIds' => ['no-such-tag']]), 'CRM_TAG_UNKNOWN', [400]);
        $this->expectRejected($this->postAs('sales', '/api/crm/leads', ['name' => 'س', 'stageId' => $stages['new'], 'teamId' => 'no-such-team']), 'CRM_TEAM_UNKNOWN', [400]);
        $this->expectRejected($this->postAs('sales', '/api/crm/leads', ['name' => 'س', 'stageId' => 'no-such-stage']), 'CRM_STAGE_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('sales', '/api/crm/leads', ['name' => 'س', 'stageId' => $stages['won']]), 'CRM_STAGE_IS_WON', [422]);

        $name = 'توريد مطعم الواحة '.self::tag();
        $l = $this->expectOk($this->postAs('sales', '/api/crm/leads', [
            'name' => $name, 'stageId' => $stages['new'], 'contactName' => 'أحمد العتيبي', 'companyName' => 'مطعم الواحة', 'jobTitle' => 'مدير المشتريات', 'phone' => '011 222 3333', 'mobile' => '0551112222',
            'email' => 'buyer@alwaha.example', 'website' => 'https://alwaha.example', 'street' => 'طريق الملك فهد', 'city' => 'الرياض', 'country' => 'السعودية', 'vatNo' => '3100-5555-0000-003',
            'expectedRevenue' => 45000.5, 'priority' => 2, 'teamId' => $team, 'source' => 'زيارة ميدانية', 'tagIds' => [$tag->id], 'description' => 'يطلب عرضًا للأجبان والزيوت', 'deadline' => '2026-10-30',
        ]));
        $this->assertMatchesRegularExpression('/^OPP-\d{4}-\d{5}$/', $l['number']);
        $this->assertSame([$name, 'opportunity', 'open', false, 2, '45000.50', 10.0, 'QA New', 'مطعم الواحة', 'أحمد العتيبي', '0551112222', 'الرياض', '2026-10-30'],
            [$l['name'], $l['kind'], $l['status'], $l['archived'], $l['priority'], $l['expectedRevenue'], (float) $l['probability'], $l['stage']['nameEn'], $l['companyName'], $l['contactName'], $l['mobile'], $l['city'], $l['deadline']]);
        $this->assertSame('310055550000003', $l['vatNo'], 'a VAT number is kept as its digits');
        $this->assertSame([$sales, 'sales', false], [$l['owner']['id'], $l['owner']['username'], $l['owner']['external']], 'nobody was named: the person who enters the lead works it');
        $this->assertSame([$tag->name], array_column($l['tags'], 'name'));
        $this->assertSame([null, null, false], [$l['sourceSystem'], $l['externalRef'], $l['owned']]);
        $this->assertSame(['edit' => true, 'move' => true, 'win' => true, 'lose' => true, 'reopen' => false, 'convert' => false, 'quote' => false, 'note' => true, 'activity' => true], $l['can']);
        $this->assertSame(['created'], array_column($l['events'], 'kind'));
        $this->assertSame('sales', AuditLog::where('action', 'LEAD.CREATE')->where('entity_id', $l['id'])->value('username'));

        // by id or by number; an edit changes what was sent, clears what was sent empty, leaves the rest
        $admin = $this->userId('admin');
        $edited = $this->expectOk($this->patchAs('sales', "/api/crm/leads/{$l['number']}", ['expectedRevenue' => 60000, 'probability' => 35, 'priority' => 3, 'website' => null, 'ownerId' => $admin, 'tagIds' => [], 'city' => 'جدة']));
        $this->assertSame(['60000.00', 35.0, 3, null, 'جدة', $admin, [], 'مطعم الواحة'],
            [$edited['expectedRevenue'], (float) $edited['probability'], $edited['priority'], $edited['website'], $edited['city'], $edited['owner']['id'], $edited['tags'], $edited['companyName']]);
        $this->assertSame(['assigned', 'created'], array_column($edited['events'], 'kind'), 'handing the lead over is on its timeline');
        $this->assertSame(1, Notification::where('user_id', $admin)->where('entity_number', $l['number'])->count(), 'the new salesperson is told');
        $audit = AuditLog::where('action', 'LEAD.UPDATE')->where('entity_id', $l['id'])->firstOrFail();
        $this->assertStringContainsString('expected_revenue', (string) $audit->new_value);
        // the same edit again changes nothing and writes nothing
        $this->expectOk($this->patchAs('sales', "/api/crm/leads/{$l['id']}", ['priority' => 3, 'city' => 'جدة']));
        $this->assertSame(1, AuditLog::where('action', 'LEAD.UPDATE')->where('entity_id', $l['id'])->count());
        $this->expectRejected($this->patchAs('sales', "/api/crm/leads/{$l['id']}", ['name' => '']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->patchAs('sales', "/api/crm/leads/{$l['id']}", ['probability' => 140]), 'INVALID_INPUT', [400]);
        // a lead that is not yet an opportunity (Odoo's first step) is qualified into one
        $this->expectRejected($this->patchAs('sales', "/api/crm/leads/{$l['id']}", ['kind' => 'prospect']), 'INVALID_INPUT', [400]);
        $this->assertSame('lead', $this->expectOk($this->patchAs('sales', "/api/crm/leads/{$l['id']}", ['kind' => 'lead']))['kind']);
        $this->assertSame([$l['number']], array_column($this->expectOk($this->getAs('sales', "/api/crm/leads?team={$team}&kind=lead"))['items'], 'number'));
        $this->assertSame('opportunity', $this->expectOk($this->patchAs('sales', "/api/crm/leads/{$l['id']}", ['kind' => 'opportunity']))['kind']);

        // without a stage a lead enters the first open stage of the board
        $first = CrmStage::where('active', true)->where('is_won', false)->ordered()->firstOrFail();
        $auto = $this->expectOk($this->postAs('sales', '/api/crm/leads', ['name' => 'بلا مرحلة '.self::tag()]));
        $this->assertSame($first->id, $auto['stage']['id']);
    }

    public function test_the_list_searches_filters_sorts_and_pages(): void
    {
        $stages = $this->pipeline();
        $team = $this->newTeam();
        $tag = CrmTag::create(['name' => 'كافيه '.self::tag(), 'color' => 5, 'active' => true]);
        $key = 'زمرد'.self::uid();
        $admin = $this->userId('admin');
        $a = $this->newLead(['teamId' => $team, 'name' => "{$key} فندق الخزامى", 'companyName' => 'فندق الخزامى', 'phone' => '0115550001', 'expectedRevenue' => 9000, 'priority' => 3, 'tagIds' => [$tag->id]]);
        $b = $this->newLead(['teamId' => $team, 'name' => "{$key} كافيه الركن", 'contactName' => 'سارة', 'email' => 'sara@corner.example', 'expectedRevenue' => 500, 'priority' => 0, 'ownerId' => $admin, 'stageId' => $stages['visit']]);
        $c = $this->newLead(['teamId' => $team, 'name' => "{$key} مطبخ مركزي", 'city' => 'الدمام', 'expectedRevenue' => 30000, 'priority' => 1]);
        $d = $this->newLead(['teamId' => $team, 'name' => "{$key} تموين الحفلات", 'expectedRevenue' => 1200, 'priority' => 1]);
        $this->expectOk($this->postAs('sales', "/api/crm/leads/{$c['id']}/win", ['stageId' => $stages['won']]));
        $this->expectOk($this->postAs('sales', "/api/crm/leads/{$d['id']}/lose", ['reasonId' => $this->lostReason()]));
        $this->expectOk($this->postAs('sales', "/api/crm/leads/{$a['id']}/activities", ['type' => 'call', 'dueDate' => now()->subDays(2)->toDateString()]));
        $this->expectOk($this->postAs('sales', "/api/crm/leads/{$b['id']}/activities", ['type' => 'visit', 'dueDate' => now()->addDays(3)->toDateString()]));

        $list = fn (string $query, string $as = 'sales') => $this->expectOk($this->getAs($as, "/api/crm/leads?team={$team}&{$query}"));
        $numbers = fn (array $page) => array_column($page['items'], 'number');

        $open = $list('');
        $this->assertSame(['items', 'total', 'page', 'pageSize', 'pages'], array_keys($open));
        $this->assertEqualsCanonicalizing([$a['number'], $b['number']], $numbers($open), 'the default list is what is still open');
        $this->assertSame([$c['number']], $numbers($list('state=won')));
        $this->assertSame([$d['number']], $numbers($list('state=lost')));
        $this->assertSame(4, $list('state=all')['total']);
        $this->assertSame([], $numbers($list('state=archived')));
        $this->assertEqualsCanonicalizing([$a['number'], $b['number'], $c['number']], $numbers($list('state=board')), 'the board shows what is open and what was won');
        $this->expectRejected($this->getAs('sales', '/api/crm/leads?state=nonsense'), 'INVALID_INPUT', [400]);

        // filters
        $this->assertSame([$b['number']], $numbers($list("stage={$stages['visit']}")));
        $this->assertSame([$b['number']], $numbers($list("owner={$admin}")));
        $this->assertSame([$a['number']], $numbers($list('owner=me')));
        $this->assertSame([$b['number']], $numbers($list('owner=me', 'admin')));
        $this->assertSame([], $numbers($list('owner=none')));
        $this->assertSame([$a['number']], $numbers($list('priority=3')));
        $this->assertSame([$b['number']], $numbers($list('priority=0')));
        $this->assertSame([$a['number']], $numbers($list("tag={$tag->id}")));
        $this->assertSame([$a['number']], $numbers($list('activity=overdue')));
        $this->assertSame([$b['number']], $numbers($list('activity=upcoming')));
        $this->assertSame([$c['number'], $d['number']], array_reverse($numbers($list('state=all&activity=none&sort=createdAt&order=desc'))));
        $this->assertSame(4, $list('state=all&origin=ops')['total']);
        $this->assertSame(0, $list('state=all&origin=odoo')['total']);
        $this->assertSame(4, $list('state=all&from='.now()->toDateString().'&to='.now()->toDateString())['total']);
        $this->assertSame(0, $list('state=all&to='.now()->subDay()->toDateString())['total']);

        // search: title, company, contact, phone, e-mail, city, number — whatever the letter case
        $find = fn (string $q) => $numbers($this->expectOk($this->getAs('sales', '/api/crm/leads?state=all&q='.rawurlencode($q))));
        $this->assertEqualsCanonicalizing([$a['number'], $b['number'], $c['number'], $d['number']], $find($key));
        $this->assertSame([$a['number']], $find("{$key} فندق"));
        $this->assertContains($a['number'], $find('0115550001'));
        $this->assertContains($b['number'], $find('SARA@corner'));
        $this->assertSame([$c['number']], $find($c['number']));
        $this->assertSame([], $find('100%_لا_شيء'), 'the wildcards of LIKE are plain characters in a search');

        // sort (server side) and paging
        $this->assertSame([$c['number'], $a['number'], $d['number'], $b['number']], $numbers($list('state=all&sort=expectedRevenue&order=desc')));
        $this->assertSame([$b['number'], $d['number'], $a['number'], $c['number']], $numbers($list('state=all&sort=expectedRevenue&order=asc')));
        $this->assertSame($a['number'], $numbers($list('state=all&sort=priority&order=desc'))[0]);
        $this->assertSame([$a['number'], $b['number']], $numbers($list('sort=nextActivity&order=asc')), 'the earliest to-do first');
        $this->assertSame([$a['number'], $b['number'], $c['number'], $d['number']], $numbers($list('state=all&sort=number&order=asc')));
        $page2 = $list('state=all&sort=number&order=asc&pageSize=3&page=2');
        $this->assertSame([4, 2, 3, 2, [$d['number']]], [$page2['total'], $page2['page'], $page2['pageSize'], $page2['pages'], $numbers($page2)]);

        // a row carries what the list and the card show
        $row = $list('priority=3')['items'][0];
        $this->assertSame(['date', 'type', 'summary', 'overdue', 'today'], array_keys($row['nextActivity']));
        $this->assertSame([now()->subDays(2)->toDateString(), 'call', 'اتصال', true, false], array_values($row['nextActivity']));
    }

    public function test_the_board_shows_each_stage_with_its_count_revenue_and_first_cards(): void
    {
        $stages = $this->pipeline();
        $team = $this->newTeam();
        foreach ([[1000, 0, 'new'], [2000, 3, 'new'], [4000, 1, 'new'], [500, 0, 'visit']] as [$revenue, $priority, $stage]) {
            $this->newLead(['teamId' => $team, 'expectedRevenue' => $revenue, 'priority' => $priority, 'stageId' => $stages[$stage], 'probability' => 50]);
        }
        $won = $this->newLead(['teamId' => $team, 'expectedRevenue' => 8000]);
        $this->expectOk($this->postAs('sales', "/api/crm/leads/{$won['id']}/win", ['stageId' => $stages['won']]));
        $lost = $this->newLead(['teamId' => $team, 'expectedRevenue' => 99999]);
        $this->expectOk($this->postAs('sales', "/api/crm/leads/{$lost['id']}/lose", ['reasonId' => $this->lostReason()]));

        $board = $this->expectOk($this->getAs('gm', "/api/crm/board?team={$team}&per=2"));
        $this->assertSame(['stages', 'per', 'totals'], array_keys($board));
        $col = fn (string $key) => collect($board['stages'])->firstWhere('id', $stages[$key]);
        $this->assertSame([3, 7000.0, 3500.0, 2, true], [$col('new')['count'], (float) $col('new')['revenue'], (float) $col('new')['weighted'], count($col('new')['items']), $col('new')['more']]);
        $this->assertSame([3, 1], array_column($col('new')['items'], 'priority'), 'the most urgent cards first');
        $this->assertSame([1, 500.0, 250.0, false], [$col('visit')['count'], (float) $col('visit')['revenue'], (float) $col('visit')['weighted'], $col('visit')['more']]);
        $this->assertSame([0, 0.0, []], [$col('offer')['count'], (float) $col('offer')['revenue'], $col('offer')['items']]);
        $this->assertSame([1, 8000.0, 8000.0, 'won'], [$col('won')['count'], (float) $col('won')['revenue'], (float) $col('won')['weighted'], $col('won')['items'][0]['status']], 'won opportunities stand in the won column; lost ones are not on the board');
        $this->assertEquals(['count' => 5, 'revenue' => 15500, 'weighted' => 11750], $board['totals']);
        $ids = array_column($board['stages'], 'id');
        $this->assertSame(array_values(array_intersect($ids, $stages)), array_values($stages), 'columns in the order of the pipeline');

        // the rest of a column is read through the list, in the same order
        $rest = $this->expectOk($this->getAs('gm', "/api/crm/leads?team={$team}&state=board&stage={$stages['new']}&sort=board&pageSize=2&page=2"));
        $this->assertSame([3, [0]], [$rest['total'], array_column($rest['items'], 'priority')]);
        // the filters of the list narrow the board too
        $urgent = $this->expectOk($this->getAs('gm', "/api/crm/board?team={$team}&priority=3"));
        $this->assertEquals(['count' => 1, 'revenue' => 2000, 'weighted' => 1000], $urgent['totals']);
    }

    public function test_moving_between_stages_keeps_the_history(): void
    {
        $stages = $this->pipeline();
        $l = $this->newLead(['probability' => 15]);

        $visit = $this->expectOk($this->postAs('sales', "/api/crm/leads/{$l['id']}/move", ['stageId' => $stages['visit'], 'note' => 'زرناه اليوم']));
        $this->assertSame(['QA Visit', 30.0, 'open'], [$visit['stage']['nameEn'], (float) $visit['probability'], $visit['status']], 'a stage that carries a chance gives it to the lead');
        $offer = $this->expectOk($this->postAs('sales', "/api/crm/leads/{$l['number']}/move", ['stageId' => $stages['offer']]));
        $back = $this->expectOk($this->postAs('sales', "/api/crm/leads/{$l['id']}/move", ['stageId' => $stages['visit']]));
        $this->assertSame('QA Offer', $offer['stage']['nameEn']);
        $moves = array_values(array_filter($back['events'], fn ($e) => $e['kind'] === 'stage'));
        $this->assertSame([['QA Offer', 'QA Visit'], ['QA Visit', 'QA Offer'], ['QA New', 'QA Visit']], array_map(fn ($e) => [$e['fromStage']['nameEn'], $e['toStage']['nameEn']], $moves), 'newest first, each with both ends');
        $this->assertSame(['زرناه اليوم', 'sales'], [$moves[2]['note'], $moves[2]['by']]);
        $this->assertSame(3, AuditLog::where('action', 'LEAD.MOVE')->where('entity_id', $l['id'])->count());

        // moving to where it stands is not a move
        $this->expectOk($this->postAs('sales', "/api/crm/leads/{$l['id']}/move", ['stageId' => $stages['visit']]));
        $this->assertSame(3, CrmLeadEvent::where('lead_id', $l['id'])->where('kind', 'stage')->count());

        // illegal: no stage, an unknown one, an archived one
        $this->expectRejected($this->postAs('sales', "/api/crm/leads/{$l['id']}/move"), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', "/api/crm/leads/{$l['id']}/move", ['stageId' => 'nope']), 'CRM_STAGE_NOT_FOUND', [404]);
        $archived = CrmStage::create(['name_ar' => 'مؤرشفة '.self::tag(), 'name_en' => 'Archived', 'sequence' => 95000, 'active' => false]);
        $this->expectRejected($this->postAs('sales', "/api/crm/leads/{$l['id']}/move", ['stageId' => $archived->id]), 'CRM_STAGE_INACTIVE', [422]);
        // a stage of one team takes only that team's leads
        $teamStage = CrmStage::create(['name_ar' => 'خاصة '.self::tag(), 'name_en' => 'Team only', 'sequence' => 95001, 'active' => true, 'team_id' => $this->newTeam()]);
        $this->expectRejected($this->postAs('sales', "/api/crm/leads/{$l['id']}/move", ['stageId' => $teamStage->id]), 'CRM_STAGE_TEAM', [422]);
        $this->assertSame($stages['visit'], CrmLead::findOrFail($l['id'])->stage_id);

        // entering a won stage IS winning
        $won = $this->expectOk($this->postAs('sales', "/api/crm/leads/{$l['id']}/move", ['stageId' => $stages['won']]));
        $this->assertSame(['won', 'QA Won', 100.0], [$won['status'], $won['stage']['nameEn'], (float) $won['probability']]);
        $this->assertNotNull($won['closedAt']);
        $this->expectRejected($this->postAs('sales', "/api/crm/leads/{$l['id']}/move", ['stageId' => $stages['new']]), 'CRM_NOT_OPEN', [422]);
    }

    public function test_won_lost_with_a_reason_and_reopening(): void
    {
        $stages = $this->pipeline();
        $reason = $this->lostReason();

        // ── won, then reopened: back to the stage it was won from, with the chance it had
        $w = $this->newLead(['expectedRevenue' => 12000, 'stageId' => $stages['offer'], 'probability' => 65]);
        $won = $this->expectOk($this->postAs('sales', "/api/crm/leads/{$w['id']}/win", ['stageId' => $stages['won'], 'note' => 'وقّع العقد']));
        $this->assertSame(['won', 'QA Won', 100.0, null], [$won['status'], $won['stage']['nameEn'], (float) $won['probability'], $won['lostReason']]);
        $this->assertSame(['edit' => true, 'move' => false, 'win' => false, 'lose' => false, 'reopen' => true, 'convert' => true, 'quote' => false, 'note' => true, 'activity' => true], $won['can']);
        $this->assertSame(['won', 'QA Offer', 'QA Won', 'وقّع العقد'], [$won['events'][0]['kind'], $won['events'][0]['fromStage']['nameEn'], $won['events'][0]['toStage']['nameEn'], $won['events'][0]['note']]);
        $history = StatusHistory::where('entity_type', 'CrmLead')->where('entity_id', $w['id'])->orderByDesc('at')->orderByDesc('id')->first();
        $this->assertSame(['open', 'won', 'sales'], [$history->from_status, $history->to_status, $history->username]);
        foreach (['win' => [], 'lose' => ['reasonId' => $reason], 'move' => ['stageId' => $stages['new']]] as $action => $body) {
            $this->expectRejected($this->postAs('sales', "/api/crm/leads/{$w['id']}/{$action}", $body), 'CRM_NOT_OPEN', [422]);
        }
        $this->expectRejected($this->postAs('sales', "/api/crm/leads/{$w['id']}/reopen", ['stageId' => $stages['won']]), 'CRM_STAGE_IS_WON', [422]);
        $reopened = $this->expectOk($this->postAs('sales', "/api/crm/leads/{$w['id']}/reopen", ['note' => 'تراجع العميل']));
        $this->assertSame(['open', 'QA Offer', 65.0, null], [$reopened['status'], $reopened['stage']['nameEn'], (float) $reopened['probability'], $reopened['closedAt']]);
        $this->assertSame(['reopened', 'won'], [$reopened['events'][0]['kind'], $reopened['events'][0]['data']['from']]);
        $history = StatusHistory::where('entity_type', 'CrmLead')->where('entity_id', $w['id'])->orderByDesc('at')->orderByDesc('id')->first();
        $this->assertSame(['won', 'open', 'تراجع العميل'], [$history->from_status, $history->to_status, $history->note]);
        $this->expectRejected($this->postAs('sales', "/api/crm/leads/{$w['id']}/reopen"), 'CRM_ALREADY_OPEN', [422]);
        // a named stage must be a won one; with none named the opportunity enters the first won stage of the board
        $this->expectRejected($this->postAs('sales', "/api/crm/leads/{$w['id']}/win", ['stageId' => $stages['visit']]), 'CRM_STAGE_NOT_WON', [422]);
        $firstWon = CrmStage::where('active', true)->where('is_won', true)->whereNull('team_id')->ordered()->firstOrFail();
        $again = $this->expectOk($this->postAs('sales', "/api/crm/leads/{$w['id']}/win")); // action button: no body
        $this->assertSame(['won', $firstWon->id], [$again['status'], $again['stage']['id']]);

        // ── lost: a reason is needed; what was planned is cancelled; the lead keeps its stage
        $l = $this->newLead(['stageId' => $stages['visit'], 'probability' => 40]);
        $todo = $this->expectOk($this->postAs('sales', "/api/crm/leads/{$l['id']}/activities", ['type' => 'visit', 'dueDate' => now()->addDays(2)->toDateString()]));
        $this->expectRejected($this->postAs('sales', "/api/crm/leads/{$l['id']}/lose"), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', "/api/crm/leads/{$l['id']}/lose", ['reasonId' => 'nope']), 'CRM_REASON_NOT_FOUND', [404]);
        $lost = $this->expectOk($this->postAs('sales', "/api/crm/leads/{$l['id']}/lose", ['reasonId' => $reason, 'note' => 'عرض المنافس أرخص']));
        $this->assertSame(['lost', 'QA Visit', 0.0, 'QA Too expensive', 'عرض المنافس أرخص', null], [$lost['status'], $lost['stage']['nameEn'], (float) $lost['probability'], $lost['lostReason']['nameEn'], $lost['lostNote'], $lost['nextActivity']]);
        $this->assertEquals(['lost', 'QA Too expensive', 40], [$lost['events'][0]['kind'], $lost['events'][0]['data']['reasonEn'], $lost['events'][0]['data']['probabilityBefore']]);
        $this->assertSame(['cancelled', 'sales'], [CrmActivity::findOrFail($todo['id'])->status, CrmActivity::findOrFail($todo['id'])->cancelled_by]);
        $this->assertSame(false, $lost['can']['convert']);
        $this->expectRejected($this->postAs('sales', "/api/crm/leads/{$l['id']}/win"), 'CRM_NOT_OPEN', [422]);
        // reopened into the stage named, with the chance it had before it was lost
        $again = $this->expectOk($this->postAs('sales', "/api/crm/leads/{$l['id']}/reopen", ['stageId' => $stages['new']]));
        $this->assertSame(['open', 'QA New', 40.0, null, null], [$again['status'], $again['stage']['nameEn'], (float) $again['probability'], $again['lostReason'], $again['lostNote']]);
        $this->assertSame('cancelled', CrmActivity::findOrFail($todo['id'])->status, 'a cancelled to-do stays cancelled');
        // an archived reason is not offered any more
        $old = $this->lostReason('QA Archived reason '.self::tag(), 'سبب مؤرشف');
        $this->expectOk($this->patchAs('sales', "/api/crm/lost-reasons/{$old}", ['active' => false]));
        $this->expectRejected($this->postAs('sales', "/api/crm/leads/{$l['id']}/lose", ['reasonId' => $old]), 'CRM_REASON_INACTIVE', [422]);

        // ── notes: a line of the timeline, on any lead
        $noted = $this->expectOk($this->postAs('sales', "/api/crm/leads/{$l['id']}/notes", ['text' => 'اتصل بعد رمضان']));
        $this->assertSame(['note', 'اتصل بعد رمضان', 'sales'], [$noted['events'][0]['kind'], $noted['events'][0]['note'], $noted['events'][0]['by']]);
        $this->expectRejected($this->postAs('sales', "/api/crm/leads/{$l['id']}/notes", ['text' => '']), 'INVALID_INPUT', [400]);
    }
}
