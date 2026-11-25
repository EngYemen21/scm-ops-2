<?php

namespace Tests\Feature\Crm;

use App\Crm\Models\CrmActivity;
use App\Models\AuditLog;
use App\Models\Notification;
use Tests\ApiTestCase;

/**
 * To-dos on leads: a type, a due date and an owner; the lead always shows its earliest open one; an open to-do whose
 * day has passed is overdue; it is done with a note or cancelled, and what is closed stays closed.
 */
class CrmActivitiesTest extends ApiTestCase
{
    use CrmFixtures;

    public function test_a_to_do_is_planned_re_dated_done_with_a_note_or_cancelled(): void
    {
        $l = $this->newLead();
        $admin = $this->userId('admin');
        $url = "/api/crm/leads/{$l['id']}/activities";
        $day = fn (int $days) => now()->addDays($days)->toDateString();
        Notification::where('user_id', $admin)->where('entity_number', $l['number'])->delete();

        // validation: a type the section knows, a day, a person who exists
        $this->expectRejected($this->postAs('sales', $url, ['dueDate' => $day(1)]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', $url, ['type' => 'dance', 'dueDate' => $day(1)]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', $url, ['type' => 'call']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', $url, ['type' => 'call', 'dueDate' => 'tomorrow']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', $url, ['type' => 'call', 'dueDate' => $day(1), 'userId' => 'nobody']), 'CRM_USER_UNKNOWN', [400]);
        $this->expectRejected($this->postAs('sales', '/api/crm/leads/OPP-NOPE/activities', ['type' => 'call', 'dueDate' => $day(1)]), 'LEAD_NOT_FOUND', [404]);
        $this->assertSame(0, CrmActivity::where('lead_id', $l['id'])->count());

        $call = $this->expectOk($this->postAs('sales', $url, ['type' => 'call', 'dueDate' => $day(2), 'summary' => 'اتصال متابعة', 'note' => 'اسأل عن الأصناف المطلوبة']));
        $this->assertSame(['call', 'اتصال متابعة', 'اسأل عن الأصناف المطلوبة', $day(2), 'open', false, false, 'sales', $l['number']],
            [$call['type'], $call['summary'], $call['note'], $call['dueDate'], $call['status'], $call['overdue'], $call['today'], $call['user']['username'], $call['lead']['number']]);
        // for somebody else, already late, with no text: the type names it
        $visit = $this->expectOk($this->postAs('sales', $url, ['type' => 'visit', 'dueDate' => $day(-1), 'userId' => $admin]));
        $this->assertSame(['زيارة', true, $admin], [$visit['summary'], $visit['overdue'], $visit['user']['id']]);
        $this->assertSame(1, Notification::where('user_id', $admin)->where('entity_number', $l['number'])->count(), 'the owner of a to-do is told');

        // the lead shows its earliest open to-do, and lists the open ones soonest first
        $lead = $this->lead($l['id']);
        $this->assertSame([$day(-1), 'visit', 'زيارة', true, false], array_values($lead['nextActivity']));
        $this->assertSame([$visit['id'], $call['id']], array_column($lead['activities'], 'id'));

        // re-dated and re-worded: the call is now the next one
        $moved = $this->expectOk($this->patchAs('sales', "/api/crm/activities/{$visit['id']}", ['dueDate' => $day(5), 'summary' => 'زيارة المطبخ المركزي']));
        $this->assertSame([$day(5), 'زيارة المطبخ المركزي', false], [$moved['dueDate'], $moved['summary'], $moved['overdue']]);
        $this->assertSame([$day(2), 'call'], [$this->lead($l['id'])['nextActivity']['date'], $this->lead($l['id'])['nextActivity']['type']]);
        $this->expectRejected($this->patchAs('sales', "/api/crm/activities/{$visit['id']}", ['type' => 'dance']), 'INVALID_INPUT', [400]);

        // done, with what happened — on the lead's timeline too
        $done = $this->expectOk($this->postAs('sales', "/api/crm/activities/{$call['id']}/done", ['note' => 'طلب عرض سعر للأجبان']));
        $this->assertSame(['done', 'sales', 'طلب عرض سعر للأجبان', false], [$done['status'], $done['doneBy'], $done['doneNote'], $done['overdue']]);
        $this->assertNotNull($done['doneAt']);
        $lead = $this->lead($l['id']);
        $this->assertSame(['activity', 'طلب عرض سعر للأجبان', 'call'], [$lead['events'][0]['kind'], $lead['events'][0]['note'], $lead['events'][0]['data']['type']]);
        $this->assertSame([$day(5), 'visit'], [$lead['nextActivity']['date'], $lead['nextActivity']['type']]);

        // what is closed stays as it ended
        foreach ([fn () => $this->postAs('sales', "/api/crm/activities/{$call['id']}/done"), fn () => $this->postAs('sales', "/api/crm/activities/{$call['id']}/cancel"),
            fn () => $this->patchAs('sales', "/api/crm/activities/{$call['id']}", ['dueDate' => $day(9)])] as $attempt) {
            $this->expectRejected($attempt(), 'CRM_ACTIVITY_STATE', [422]);
        }
        $this->assertSame($day(2), CrmActivity::findOrFail($call['id'])->due_date->toDateString());

        // cancelled (action button: the reason is optional) — nothing is planned any more
        $cancelled = $this->expectOk($this->postAs('sales', "/api/crm/activities/{$visit['id']}/cancel", ['reason' => 'أجّل العميل الزيارة']));
        $this->assertSame(['cancelled', 'sales', 'أجّل العميل الزيارة'], [$cancelled['status'], $cancelled['cancelledBy'], $cancelled['cancelReason']]);
        $lead = $this->lead($l['id']);
        $this->assertNull($lead['nextActivity']);
        $this->assertSame([$visit['id'], $call['id']], array_column($lead['activities'], 'id'), 'closed ones: the latest first');
        $this->expectRejected($this->postAs('sales', '/api/crm/activities/nope/done'), 'CRM_ACTIVITY_NOT_FOUND', [404]);

        $this->assertSame(['LEAD.ACTIVITY.CANCEL' => 1, 'LEAD.ACTIVITY.CREATE' => 2, 'LEAD.ACTIVITY.DONE' => 1, 'LEAD.ACTIVITY.UPDATE' => 1],
            AuditLog::where('entity_id', $l['id'])->where('action', 'like', 'LEAD.ACTIVITY.%')->selectRaw('action, COUNT(*) AS n')->groupBy('action')->orderBy('action')->pluck('n', 'action')->map(fn ($n) => (int) $n)->all());
    }

    public function test_the_to_do_list_is_mine_by_default_and_marks_what_is_overdue(): void
    {
        // a person of his own, so the to-dos of the other stories do not land in his list
        $me = $this->pipelineOnlyUser();
        $myId = $this->userId($me);
        $key = 'ياقوت'.self::uid();
        $a = $this->newLead(['name' => "{$key} مطعم الشرفة", 'companyName' => 'مطعم الشرفة'], $me);
        $b = $this->newLead(['name' => "{$key} فندق النخيل"], $me);
        $day = fn (int $days) => now()->addDays($days)->toDateString();
        $add = fn (array $lead, string $type, int $days, ?string $summary = null) => $this->expectOk($this->postAs($me, "/api/crm/leads/{$lead['id']}/activities", ['type' => $type, 'dueDate' => $day($days), 'summary' => $summary]));
        $late = $add($a, 'call', -3, 'اتصال فائت');
        $today = $add($a, 'visit', 0);
        $soon = $add($b, 'sample', 4, 'عينات أجبان');
        $done = $add($b, 'email', -1);
        $this->expectOk($this->postAs($me, "/api/crm/activities/{$done['id']}/done", ['note' => 'أُرسل']));
        $dropped = $add($a, 'meeting', 2);
        $this->expectOk($this->postAs($me, "/api/crm/activities/{$dropped['id']}/cancel"));

        $list = fn (string $query = '', ?string $as = null) => $this->expectOk($this->getAs($as ?? $me, '/api/crm/activities'.($query ? "?{$query}" : '')));
        $ids = fn (array $page) => array_column($page['items'], 'id');

        // mine, open, the soonest first — with what is late marked
        $mine = $list();
        $this->assertSame(['items', 'total', 'page', 'pageSize', 'pages', 'counts'], array_keys($mine));
        $this->assertSame([$late['id'], $today['id'], $soon['id']], $ids($mine));
        $this->assertSame([[true, false], [false, true], [false, false]], array_map(fn ($x) => [$x['overdue'], $x['today']], $mine['items']));
        $this->assertSame(['overdue' => 1, 'today' => 1, 'upcoming' => 1, 'open' => 3], $mine['counts']);
        $this->assertSame([$a['number'], $a['name'], 'مطعم الشرفة', 'QA New'], [$mine['items'][0]['lead']['number'], $mine['items'][0]['lead']['name'], $mine['items'][0]['lead']['companyName'], $mine['items'][0]['lead']['stage']['nameEn']]);

        foreach (['overdue' => [$late['id']], 'today' => [$today['id']], 'upcoming' => [$soon['id']], 'done' => [$done['id']], 'cancelled' => [$dropped['id']]] as $state => $expected) {
            $this->assertSame($expected, $ids($list("state={$state}")), $state);
        }
        $this->assertSame(5, $list('state=all')['total']);
        $this->expectRejected($this->getAs($me, '/api/crm/activities?state=nonsense'), 'INVALID_INPUT', [400]);

        // filters: type, lead, search (to-do text or lead), another person's list, everybody's
        $this->assertSame([$late['id']], $ids($list('type=call')));
        $this->assertSame([$soon['id']], $ids($list("lead={$b['number']}")));
        $this->assertSame([$soon['id']], $ids($list('q='.rawurlencode('عينات'))));
        $this->assertSame([$late['id'], $today['id']], $ids($list('q='.rawurlencode('الشرفة'))));
        $this->assertSame([], array_values(array_intersect($ids($list('pageSize=200', 'sales')), [$late['id'], $today['id'], $soon['id']])), 'the list is the reader\'s own unless another is asked for');
        $this->assertSame([$late['id'], $today['id'], $soon['id']], $ids($list("owner={$myId}", 'gm')), 'page.crm reads anybody\'s list');
        $everybody = $list('owner=all&q='.rawurlencode($key), 'sales');
        $this->assertSame(3, $everybody['total']);

        // sort and paging
        $this->assertSame([$soon['id'], $today['id'], $late['id']], $ids($list('sort=dueDate&order=desc')));
        $this->assertSame([$late['id'], $today['id'], $soon['id']], $ids($list('sort=createdAt&order=asc')), 'in the order they were added');
        $this->assertSame([$late['id'], $done['id'], $today['id'], $dropped['id'], $soon['id']], $ids($list('state=all&sort=dueDate&order=asc')), 'a sort that was asked for applies to closed to-dos too');
        $page2 = $list('pageSize=2&page=2');
        $this->assertSame([3, 2, 2, [$soon['id']]], [$page2['total'], $page2['page'], $page2['pages'], $ids($page2)]);
    }
}
