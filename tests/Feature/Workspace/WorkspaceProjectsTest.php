<?php

namespace Tests\Feature\Workspace;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Notification;
use App\Models\StatusHistory;
use App\Workspace\Models\ChecklistItem;
use App\Workspace\Models\Project;
use App\Workspace\Models\Task;
use Tests\ApiTestCase;

/**
 * Projects and tasks: who creates a project and who keeps it (details, members, stages, status), a task from its
 * creation to its end on the board (assignees, deadline, tags, checklist, comments with files, stages, done / reopened
 * / cancelled / archived), personal tasks nobody else sees, a project for its members only, the lists and the board —
 * and every illegal move refused with its code.
 */
class WorkspaceProjectsTest extends ApiTestCase
{
    use WorkspaceFixtures;

    public function test_a_project_is_created_by_the_projects_manager_and_kept_by_its_own_manager(): void
    {
        $before = Project::count();
        // opening the section is not enough to create a project
        foreach (['wm', 'sales', 'finance'] as $who) {
            $this->expectRejected($this->postAs($who, '/api/workspace/projects', ['name' => 'محاولة']), 'FORBIDDEN', [403]);
        }
        $this->expectRejected($this->getAs('worker', '/api/workspace/projects'), 'FORBIDDEN', [403]);
        $this->assertSame($before, Project::count());
        $this->expectRejected($this->postAs('gm', '/api/workspace/projects', ['description' => 'بلا اسم']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('gm', '/api/workspace/projects', ['name' => 'س', 'startDate' => '2026-10-10', 'endDate' => '2026-10-01']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('gm', '/api/workspace/projects', ['name' => 'س', 'managerId' => 'nobody']), 'WS_USER_UNKNOWN', [400]);
        $this->expectRejected($this->postAs('gm', '/api/workspace/projects', ['name' => 'س', 'visibility' => 'secret']), 'INVALID_INPUT', [400]);

        // gm opens a project that wm runs, with inv as a member: three columns to start with
        $name = 'تجهيز مستودع جدة '.self::tag();
        $p = $this->expectOk($this->postAs('gm', '/api/workspace/projects', ['name' => $name, 'description' => 'رفوف وتبريد', 'managerId' => $this->userId('wm'), 'memberKeys' => [$this->userId('inv')],
            'startDate' => '2026-10-01', 'endDate' => '2026-12-15']));
        $this->assertMatchesRegularExpression('/^PRJ-\d{4}-\d{5}$/', $p['number']);
        $this->assertSame([$name, 'active', false, 'all', 'wm', ['inv'], '2026-10-01', '2026-12-15', ['open' => 0, 'done' => 0, 'overdue' => 0, 'total' => 0]],
            [$p['name'], $p['status'], $p['archived'], $p['visibility'], $p['manager']['username'], array_column($p['members'], 'username'), $p['startDate'], $p['endDate'], $p['counts']]);
        $this->assertSame([['To do', false], ['In progress', false], ['Done', true]], array_map(fn ($s) => [$s['nameEn'], $s['isClosing']], $p['stages']));
        $this->assertSame(['edit' => true, 'status' => true, 'archive' => true, 'restore' => false, 'members' => true, 'stages' => true, 'addTask' => true], $p['can']);
        $this->assertSame(1, Notification::where('user_id', $this->userId('wm'))->where('entity_number', $p['number'])->count(), 'the manager is told');
        $url = "/api/workspace/projects/{$p['id']}";
        // nobody was named: whoever opens the project runs it; given stages replace the usual three
        $own = $this->expectOk($this->postAs('gm', '/api/workspace/projects', ['name' => 'مشروع بمراحله '.self::tag(), 'stages' => [['nameAr' => 'فكرة'], ['nameAr' => 'منجزة', 'nameEn' => 'Shipped', 'isClosing' => true]]]));
        $this->assertSame(['gm', [['فكرة', false], ['Shipped', true]]], [$own['manager']['username'], array_map(fn ($s) => [$s['nameEn'], $s['isClosing']], $own['stages'])]);

        // its manager keeps it without holding project.manage; a member and a bystander do not
        $asWm = $this->ws('wm', "projects/{$p['number']}");
        $this->assertSame([true, true, true, true], [$asWm['canManage'], $asWm['canWork'], $asWm['isMember'], $asWm['can']['edit']]);
        $asInv = $this->ws('inv', "projects/{$p['id']}");
        $this->assertSame([false, true, true, false, true], [$asInv['canManage'], $asInv['canWork'], $asInv['isMember'], $asInv['can']['edit'], $asInv['can']['addTask']]);
        $asProc = $this->ws('proc', "projects/{$p['id']}");
        $this->assertSame([false, false, false, false], [$asProc['canManage'], $asProc['canWork'], $asProc['isMember'], $asProc['can']['addTask']], 'an open project is read by everybody, worked by its people');
        foreach (['inv', 'proc'] as $who) {
            $this->expectRejected($this->patchAs($who, $url, ['name' => 'تعديل']), 'WS_PROJECT_FORBIDDEN', [403]);
            $this->expectRejected($this->putAs($who, "{$url}/members", ['memberKeys' => []]), 'WS_PROJECT_FORBIDDEN', [403]);
            $this->expectRejected($this->postAs($who, "{$url}/status", ['status' => 'on_hold']), 'WS_PROJECT_FORBIDDEN', [403]);
            $this->expectRejected($this->postAs($who, "{$url}/stages", ['nameAr' => 'مرحلة']), 'WS_PROJECT_FORBIDDEN', [403]);
            $this->expectRejected($this->postAs($who, "{$url}/archive"), 'WS_PROJECT_FORBIDDEN', [403]);
        }
        $e = $this->expectOk($this->patchAs('wm', $url, ['description' => null, 'endDate' => '2027-01-31', 'visibility' => 'members']));
        $this->assertSame([null, '2027-01-31', 'members', '2026-10-01'], [$e['description'], $e['endDate'], $e['visibility'], $e['startDate']]);
        $this->assertSame(1, AuditLog::where('action', 'PROJECT.UPDATE')->where('entity_id', $p['id'])->count());
        $this->expectRejected($this->patchAs('wm', $url, ['endDate' => '2026-09-01']), 'INVALID_INPUT', [400]);
        $m = $this->expectOk($this->putAs('wm', "{$url}/members", ['memberKeys' => [$this->userId('proc'), $this->userId('disp')]]));
        $this->assertSame(['disp', 'proc'], collect($m['members'])->pluck('username')->sort()->values()->all());
        $this->assertSame(1, Notification::where('user_id', $this->userId('proc'))->where('entity_number', $p['number'])->count(), 'a new member is told');
        $this->expectRejected($this->putAs('wm', "{$url}/members", ['memberKeys' => ['nobody']]), 'WS_USER_UNKNOWN', [400]);
        $this->expectRejected($this->putAs('wm', "{$url}/members", []), 'INVALID_INPUT', [400]);
        // now a project for its members only: inv is out
        $this->expectRejected($this->getAs('inv', $url), 'WS_PROJECT_FORBIDDEN', [403]);
        $this->expectRejected($this->getAs('inv', "{$url}/board"), 'WS_PROJECT_FORBIDDEN', [403]);
        $this->assertNotContains($p['number'], array_column($this->ws('inv', 'projects?state=all&pageSize=200')['items'], 'number'));
        $this->assertContains($p['number'], array_column($this->ws('proc', 'projects?state=all&pageSize=200')['items'], 'number'));
        $this->assertNotContains($p['id'], array_column($this->ws('inv', 'tasks/lookups')['projects'], 'id'));

        // its board: a column is added, renamed, reordered; one that holds tasks cannot change what it means
        $s = $this->expectOk($this->postAs('wm', "{$url}/stages", ['nameAr' => 'مراجعة', 'nameEn' => 'Review', 'sequence' => 25]));
        $this->assertSame(['To do', 'In progress', 'Review', 'Done'], array_column($s['stages'], 'nameEn'));
        [$todo, $doing, $review, $done] = array_column($s['stages'], 'id');
        $task = $this->expectOk($this->postAs('proc', '/api/workspace/tasks', ['title' => 'طلب عروض الرفوف', 'projectId' => $p['id']]));
        $this->assertSame($todo, $task['stage']['id']);
        $this->expectRejected($this->patchAs('wm', "/api/workspace/task-stages/{$todo}", ['isClosing' => true]), 'WS_STAGE_IN_USE', [422]);
        $this->expectRejected($this->patchAs('wm', "/api/workspace/task-stages/{$todo}", ['active' => false]), 'WS_STAGE_IN_USE', [422]);
        $this->expectRejected($this->patchAs('inv', "/api/workspace/task-stages/{$review}", ['nameAr' => 'تعديل']), 'WS_PROJECT_FORBIDDEN', [403]);
        $this->expectRejected($this->patchAs('wm', '/api/workspace/task-stages/nope', ['nameAr' => 'تعديل']), 'WS_STAGE_NOT_FOUND', [404]);
        $r = $this->expectOk($this->patchAs('wm', "/api/workspace/task-stages/{$review}", ['nameAr' => 'تدقيق', 'sequence' => 5]));
        $this->assertSame(['Review', 'To do', 'In progress', 'Done'], array_column($r['stages'], 'nameEn'));
        $this->assertSame('تدقيق', $r['stages'][0]['nameAr']);

        // status: a project is not finished while tasks in it are open; the machine refuses what it does not list
        $this->expectRejected($this->postAs('wm', "{$url}/status", ['status' => 'paused']), 'INVALID_INPUT', [400]);
        $this->assertSame('on_hold', $this->expectOk($this->postAs('wm', "{$url}/status", ['status' => 'on_hold', 'note' => 'بانتظار الميزانية']))['status']);
        $this->expectRejected($this->postAs('wm', "{$url}/status", ['status' => 'done']), 'WS_PROJECT_HAS_OPEN_TASKS', [422]);
        $this->expectOk($this->postAs('proc', "/api/workspace/tasks/{$task['id']}/done"));
        $finished = $this->expectOk($this->postAs('wm', "{$url}/status", ['status' => 'done']));
        $this->assertSame(['done', false, ['open' => 0, 'done' => 1, 'overdue' => 0, 'total' => 1]], [$finished['status'], $finished['can']['addTask'], $finished['counts']]);
        $this->expectRejected($this->postAs('wm', "{$url}/status", ['status' => 'on_hold']), 'WS_PROJECT_TRANSITION', [422]);
        $this->expectRejected($this->postAs('proc', '/api/workspace/tasks', ['title' => 'مهمة بعد الإنهاء', 'projectId' => $p['id']]), 'WS_PROJECT_CLOSED', [422]);
        $this->assertSame(['on_hold', 'done'], StatusHistory::where('entity_id', $p['id'])->orderBy('at')->orderBy('id')->pluck('to_status')->all());
        $this->assertSame('active', $this->expectOk($this->postAs('gm', "{$url}/status", ['status' => 'active']))['status']);

        // put away and brought back
        $ar = $this->expectOk($this->postAs('wm', "{$url}/archive", ['reason' => 'أُجّل للعام القادم']));
        $this->assertSame([true, 'أُجّل للعام القادم', false, true], [$ar['archived'], $ar['archiveReason'], $ar['can']['edit'], $ar['can']['restore']]);
        $this->expectRejected($this->patchAs('wm', $url, ['name' => 'تعديل']), 'WS_ARCHIVED', [422]);
        $this->expectRejected($this->postAs('wm', "{$url}/stages", ['nameAr' => 'مرحلة']), 'WS_ARCHIVED', [422]);
        $this->assertNotContains($p['number'], array_column($this->ws('wm', 'projects?pageSize=200')['items'], 'number'), 'the usual list shows projects that are alive');
        $this->assertContains($p['number'], array_column($this->ws('wm', 'projects?state=archived&pageSize=200')['items'], 'number'));
        $this->assertFalse($this->expectOk($this->postAs('wm', "{$url}/restore"))['archived']);

        // the list: search, mine, sort, paging; the tags are kept by the projects manager
        $key = 'زبرجد'.self::uid();
        $x = $this->newProject(['name' => "{$key} أ", 'endDate' => '2026-11-30']);
        $y = $this->newProject(['name' => "{$key} ب", 'managerId' => $this->userId('inv')], []);
        $found = $this->ws('inv', 'projects?q='.urlencode($key).'&sort=name&order=asc');
        $this->assertSame(['items', 'total', 'page', 'pageSize', 'pages'], array_keys($found));
        $this->assertSame([$x['number'], $y['number']], array_column($found['items'], 'number'));
        $this->assertSame([$y['number'], $x['number']], array_column($this->ws('inv', 'projects?q='.urlencode($key).'&sort=name&order=desc')['items'], 'number'));
        $this->assertSame([$x['number'], $y['number']], array_column($this->ws('inv', 'projects?q='.urlencode($key).'&sort=endDate&order=desc')['items'], 'number'), 'a project without an end date comes last whichever way');
        $this->assertSame([$y['number']], array_column($this->ws('gm', 'projects?q='.urlencode($key).'&pageSize=1&page=1')['items'], 'number'));
        $this->assertSame([$y['number'], $x['number']], array_column($this->ws('inv', 'projects?mine=1&q='.urlencode($key))['items'], 'number'), 'the ones I run or work in');
        $this->assertSame([], $this->ws('proc', 'projects?mine=1&q='.urlencode($key))['items']);
        $this->expectRejected($this->getAs('gm', '/api/workspace/projects/PRJ-NOPE'), 'WS_PROJECT_NOT_FOUND', [404]);

        $this->expectRejected($this->postAs('wm', '/api/workspace/task-tags', ['name' => 'وسم']), 'FORBIDDEN', [403]);
        $tagName = 'عاجل للمورد '.self::tag();
        $tag = $this->expectOk($this->postAs('gm', '/api/workspace/task-tags', ['name' => $tagName, 'color' => 4]));
        $this->assertSame([$tagName, 4, true, false], [$tag['name'], $tag['color'], $tag['active'], $tag['owned']]);
        $this->expectRejected($this->postAs('gm', '/api/workspace/task-tags', ['name' => $tagName]), 'WS_TAG_EXISTS', [409]);
        $this->expectRejected($this->postAs('gm', '/api/workspace/task-tags', ['name' => 'س', 'color' => 99]), 'INVALID_INPUT', [400]);
        $this->assertSame([9, false], array_values(array_intersect_key($this->expectOk($this->patchAs('gm', "/api/workspace/task-tags/{$tag['id']}", ['color' => 9, 'active' => false])), ['color' => 1, 'active' => 1])));
        $this->expectRejected($this->patchAs('gm', '/api/workspace/task-tags/nope', ['name' => 'س']), 'WS_TAG_NOT_FOUND', [404]);
    }

    public function test_a_task_from_its_creation_to_its_end_on_the_board(): void
    {
        $p = $this->newProject([], ['inv', 'proc']);
        [$todo, $doing, $done] = array_column($p['stages'], 'id');
        $tag = $this->expectOk($this->postAs('gm', '/api/workspace/task-tags', ['name' => 'توريد '.self::tag(), 'color' => 2]));
        [$inv, $proc, $disp] = [$this->userId('inv'), $this->userId('proc'), $this->userId('disp')];

        // validation, and who adds tasks: the project's people — a bystander reads the project and may not
        $this->expectRejected($this->postAs('inv', '/api/workspace/tasks', ['projectId' => $p['id']]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('inv', '/api/workspace/tasks', ['title' => 'س', 'projectId' => 'nope']), 'WS_PROJECT_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('disp', '/api/workspace/tasks', ['title' => 'س', 'projectId' => $p['id']]), 'WS_PROJECT_FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('inv', '/api/workspace/tasks', ['title' => 'س', 'projectId' => $p['id'], 'stageId' => $done]), 'WS_STAGE_IS_CLOSING', [422]);
        $other = $this->newProject();
        $this->expectRejected($this->postAs('inv', '/api/workspace/tasks', ['title' => 'س', 'projectId' => $p['id'], 'stageId' => $other['stages'][0]['id']]), 'WS_STAGE_NOT_OF_PROJECT', [422]);
        $this->expectRejected($this->postAs('inv', '/api/workspace/tasks', ['title' => 'س', 'projectId' => $p['id'], 'tagIds' => ['nope']]), 'WS_TAG_UNKNOWN', [400]);
        $this->expectRejected($this->postAs('inv', '/api/workspace/tasks', ['title' => 'س', 'projectId' => $p['id'], 'assigneeKeys' => ['nobody']]), 'WS_USER_UNKNOWN', [400]);
        $this->expectRejected($this->postAs('inv', '/api/workspace/tasks', ['title' => 'س', 'projectId' => $p['id'], 'deadline' => 'tomorrow']), 'INVALID_INPUT', [400]);

        $t = $this->expectOk($this->postAs('inv', '/api/workspace/tasks', ['title' => 'استلام عروض ثلاثة موردين', 'description' => 'رفوف ثقيلة', 'projectId' => $p['id'], 'assigneeKeys' => [$proc], 'priority' => 2,
            'deadline' => now()->addDays(3)->toDateString(), 'tagIds' => [$tag['id']], 'checklist' => ['مورد أول', '', 'مورد ثانٍ'], 'files' => [self::pdfFile('rfq.pdf')]]));
        $this->assertMatchesRegularExpression('/^TSK-\d{4}-\d{5}$/', $t['number']);
        $this->assertSame(['open', false, 2, $todo, $p['number'], ['proc'], [$tag['name']], ['done' => 0, 'total' => 2], false, false, ['rfq.pdf']],
            [$t['status'], $t['archived'], $t['priority'], $t['stage']['id'], $t['project']['number'], array_column($t['assignees'], 'username'), array_column($t['tags'], 'name'), $t['checklist'], $t['overdue'], $t['dueToday'], array_column($t['files'], 'name')]);
        $this->assertSame(['مورد أول', 'مورد ثانٍ'], array_column($t['checklistItems'], 'text'), 'an empty line is no line');
        $this->assertSame(['created'], array_column($t['comments'], 'event'));
        $this->assertSame(['edit' => true, 'move' => true, 'done' => true, 'reopen' => false, 'cancel' => true, 'archive' => true, 'restore' => false, 'comment' => true, 'checklist' => true], $t['can']);
        $this->assertSame(1, Notification::where('user_id', $proc)->where('entity_number', $t['number'])->count(), 'whoever the task is given to is told');
        $url = "/api/workspace/tasks/{$t['number']}";

        // a bystander (the project is open) reads the task and comments on it; he does not work it
        $asDisp = $this->ws('disp', "tasks/{$t['id']}");
        $this->assertSame([false, false, true], [$asDisp['can']['edit'], $asDisp['can']['move'], $asDisp['can']['comment']]);
        foreach ([['move', ['stageId' => $doing]], ['done', []], ['cancel', ['reason' => 'سبب']], ['archive', []], ['checklist', ['text' => 'بند']]] as [$action, $body]) {
            $this->expectRejected($this->postAs('disp', "{$url}/{$action}", $body), 'WS_TASK_FORBIDDEN', [403]);
        }
        $this->expectRejected($this->patchAs('disp', $url, ['title' => 'تعديل']), 'WS_TASK_FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('disp', "{$url}/comments", []), 'WS_MESSAGE_EMPTY', [400]);
        $c = $this->expectOk($this->postAs('disp', "{$url}/comments", ['body' => 'عندي مورد رابع', 'files' => [self::pngFile('card.png')]]));
        $comment = collect($c['comments'])->firstWhere('kind', 'comment');
        $this->assertSame([1, 'عندي مورد رابع', 'disp', ['card.png']], [$c['commentCount'], $comment['body'], $comment['author']['username'], array_column($comment['files'], 'name')]);
        $this->assertSame(1, Notification::where('user_id', $inv)->where('entity_number', $t['number'])->count(), 'whoever made the task hears of a comment');

        // an edit: what was sent changes, what was sent empty is cleared; a change of hands is told and the new hand is told
        $e = $this->expectOk($this->patchAs('proc', $url, ['title' => 'استلام العروض ومقارنتها', 'deadline' => null, 'priority' => 3, 'assigneeKeys' => [$proc, $inv], 'tagIds' => []]));
        $this->assertSame(['استلام العروض ومقارنتها', null, 3, ['inv', 'proc'], []], [$e['title'], $e['deadline'], $e['priority'], collect($e['assignees'])->pluck('username')->sort()->values()->all(), $e['tags']]);
        $this->assertSame(['created', 'edited', 'assigned'], array_values(array_filter(array_column($e['comments'], 'event'))));
        $this->assertSame(1, AuditLog::where('action', 'TASK.UPDATE')->where('entity_id', $t['id'])->count());
        $this->expectOk($this->patchAs('proc', $url, ['priority' => 3, 'assigneeKeys' => [$inv, $proc]]));
        $this->assertSame(1, AuditLog::where('action', 'TASK.UPDATE')->where('entity_id', $t['id'])->count(), 'the same edit again writes nothing');
        $this->expectRejected($this->patchAs('proc', $url, ['title' => '']), 'INVALID_INPUT', [400]);

        // the checklist
        $k = $this->expectOk($this->postAs('proc', "{$url}/checklist", ['text' => 'مورد ثالث']));
        $this->assertSame(['done' => 0, 'total' => 3], $k['checklist']);
        [$one, $two, $three] = array_column($k['checklistItems'], 'id');
        $k = $this->expectOk($this->patchAs('proc', "{$url}/checklist/{$one}", ['done' => true]));
        $this->assertSame([['done' => 1, 'total' => 3], true, 'proc'], [$k['checklist'], $k['checklistItems'][0]['done'], $k['checklistItems'][0]['doneBy']]);
        $k = $this->expectOk($this->patchAs('proc', "{$url}/checklist/{$two}", ['text' => 'مورد ثانٍ — محلي', 'done' => true]));
        $this->assertSame(['مورد ثانٍ — محلي', ['done' => 2, 'total' => 3]], [$k['checklistItems'][1]['text'], $k['checklist']]);
        $k = $this->expectOk($this->patchAs('proc', "{$url}/checklist/{$one}", ['done' => false]));
        $this->assertSame([false, null, ['done' => 1, 'total' => 3]], [$k['checklistItems'][0]['done'], $k['checklistItems'][0]['doneBy'], $k['checklist']]);
        $k = $this->expectOk($this->postAs('proc', "{$url}/checklist/{$three}/remove"));
        $this->assertSame([['مورد أول', 'مورد ثانٍ — محلي'], 0], [array_column($k['checklistItems'], 'text'), ChecklistItem::where('id', $three)->count()]);
        $this->assertStringContainsString('مورد ثالث', (string) AuditLog::where('action', 'TASK.CHECKLIST.REMOVE')->where('entity_id', $t['id'])->value('old_value'), 'the trail keeps what the line said');
        $this->expectRejected($this->patchAs('proc', "{$url}/checklist/nope", ['done' => true]), 'WS_CHECKLIST_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('proc', "{$url}/checklist", []), 'INVALID_INPUT', [400]);

        // across the board: entering the closing column finishes the task, leaving it opens it again
        $this->expectRejected($this->postAs('proc', "{$url}/move", ['stageId' => $other['stages'][0]['id']]), 'WS_STAGE_NOT_OF_PROJECT', [422]);
        $this->expectRejected($this->postAs('proc', "{$url}/move", ['stageId' => 'nope']), 'WS_STAGE_NOT_FOUND', [404]);
        $m = $this->expectOk($this->postAs('proc', "{$url}/move", ['stageId' => $doing]));
        $this->assertSame([$doing, 'open'], [$m['stage']['id'], $m['status']]);
        $m = $this->expectOk($this->postAs('inv', "{$url}/move", ['stageId' => $done]));
        $this->assertSame([$done, 'done', 'inv', false], [$m['stage']['id'], $m['status'], $m['doneBy'], $m['overdue']]);
        $this->assertNotNull($m['doneAt']);
        $this->assertSame(['edit' => true, 'move' => true, 'done' => false, 'reopen' => true, 'cancel' => false, 'archive' => true, 'restore' => false, 'comment' => true, 'checklist' => true], $m['can']);
        $this->expectRejected($this->postAs('proc', "{$url}/done"), 'WS_TASK_TRANSITION', [422]);
        $this->expectRejected($this->postAs('proc', "{$url}/cancel", ['reason' => 'لم تعد لازمة']), 'WS_TASK_TRANSITION', [422]);
        $m = $this->expectOk($this->postAs('proc', "{$url}/move", ['stageId' => $doing]));
        $this->assertSame([$doing, 'open', null], [$m['stage']['id'], $m['status'], $m['doneAt']]);
        // "done" from anywhere puts it in the closing column; "reopen" brings it back to the first open one
        $d = $this->expectOk($this->postAs('proc', "{$url}/done"));
        $this->assertSame([$done, 'done'], [$d['stage']['id'], $d['status']]);
        $this->assertSame(1, Notification::where('user_id', $inv)->where('entity_number', $t['number'])->where('text_en', 'like', '%is done%')->count());
        $this->expectRejected($this->postAs('proc', "{$url}/reopen", ['stageId' => $done]), 'WS_STAGE_IS_CLOSING', [422]);
        $o = $this->expectOk($this->postAs('proc', "{$url}/reopen"));
        $this->assertSame([$todo, 'open'], [$o['stage']['id'], $o['status']]);
        // cancelled with a reason: it does not move until it is reopened
        $this->expectRejected($this->postAs('proc', "{$url}/cancel", []), 'INVALID_INPUT', [400]);
        $x = $this->expectOk($this->postAs('proc', "{$url}/cancel", ['reason' => 'أُلغي المشروع الفرعي']));
        $this->assertSame(['cancelled', 'أُلغي المشروع الفرعي', $todo, false], [$x['status'], $x['cancelReason'], $x['stage']['id'], $x['can']['move']]);
        $this->expectRejected($this->postAs('proc', "{$url}/move", ['stageId' => $doing]), 'WS_TASK_TRANSITION', [422]);
        $this->assertSame('open', $this->expectOk($this->postAs('proc', "{$url}/reopen", ['stageId' => $doing]))['status']);
        $this->assertSame(['open', 'done', 'open', 'done', 'open', 'cancelled', 'open'], array_merge(['open'], StatusHistory::where('entity_id', $t['id'])->orderBy('at')->orderBy('id')->pluck('to_status')->all()));

        // put away and brought back; an archived task takes no comment
        $ar = $this->expectOk($this->postAs('inv', "{$url}/archive", ['reason' => 'مكررة']));
        $this->assertSame([true, 'مكررة', false, true, false], [$ar['archived'], $ar['archiveReason'], $ar['can']['edit'], $ar['can']['restore'], $ar['can']['comment']]);
        foreach ([['comments', ['body' => 'تعليق']], ['move', ['stageId' => $todo]], ['done', []], ['checklist', ['text' => 'بند']]] as [$action, $body]) {
            $this->expectRejected($this->postAs('inv', "{$url}/{$action}", $body), 'WS_ARCHIVED', [422]);
        }
        $this->assertSame([false, 1], [$this->expectOk($this->postAs('inv', "{$url}/restore"))['archived'], Task::where('id', $t['id'])->count()]);
        $this->expectRejected($this->getAs('inv', '/api/workspace/tasks/TSK-NOPE'), 'WS_TASK_NOT_FOUND', [404]);
    }

    public function test_the_board_and_the_lists_of_tasks(): void
    {
        $p = $this->newProject([], ['inv', 'proc']);
        [$todo, $doing, $done] = array_column($p['stages'], 'id');
        $tag = $this->expectOk($this->postAs('gm', '/api/workspace/task-tags', ['name' => 'جرد '.self::tag()]));
        $key = 'عقيق'.self::uid();
        [$inv, $proc] = [$this->userId('inv'), $this->userId('proc')];
        $make = fn (array $more) => $this->expectOk($this->postAs('inv', '/api/workspace/tasks', $more + ['projectId' => $p['id']]));
        $a = $make(['title' => "{$key} عدّ الممر أ", 'priority' => 3, 'assigneeKeys' => [$proc], 'deadline' => now()->subDays(2)->toDateString(), 'tagIds' => [$tag['id']]]);
        $b = $make(['title' => "{$key} عدّ الممر ب", 'priority' => 1, 'assigneeKeys' => [$inv], 'deadline' => now()->toDateString()]);
        $c = $make(['title' => "{$key} مطابقة الفروقات", 'priority' => 1, 'deadline' => now()->addDays(4)->toDateString(), 'stageId' => $doing]);
        $d = $make(['title' => "{$key} طباعة الملصقات", 'priority' => 0]);
        $e = $make(['title' => "{$key} مهمة ملغاة"]);
        $f = $make(['title' => "{$key} مهمة مؤرشفة"]);
        $this->expectOk($this->postAs('inv', "/api/workspace/tasks/{$d['id']}/done"));
        $this->expectOk($this->postAs('inv', "/api/workspace/tasks/{$e['id']}/cancel", ['reason' => 'غير لازمة']));
        $this->expectOk($this->postAs('inv', "/api/workspace/tasks/{$f['id']}/archive"));

        // the board: one column per stage with its count; the most urgent first; cancelled and archived tasks are not on it
        $board = $this->ws('proc', "projects/{$p['id']}/board");
        $this->assertSame(['project', 'per', 'stages', 'unstaged'], array_keys($board));
        $this->assertSame([$p['number'], true], [$board['project']['number'], $board['project']['canWork']]);
        $column = fn (array $b, string $stage) => collect($b['stages'])->firstWhere('id', $stage);
        $this->assertSame([[$todo, 2], [$doing, 1], [$done, 1]], array_map(fn ($s) => [$s['id'], $s['count']], $board['stages']));
        $this->assertSame([$a['number'], $b['number']], array_column($column($board, $todo)['items'], 'number'));
        $this->assertSame([$d['number']], array_column($column($board, $done)['items'], 'number'));
        $this->assertSame(['count' => 0, 'items' => [], 'more' => false], $board['unstaged']);
        $card = $column($board, $todo)['items'][0];
        $this->assertSame(['id', 'number', 'title', 'status', 'archived', 'priority', 'deadline', 'overdue', 'dueToday', 'project', 'stage', 'assignees', 'tags', 'checklist', 'commentCount', 'ref', 'createdAt', 'createdBy', 'doneAt',
            'sourceSystem', 'externalRef', 'owned'], array_keys($card));
        $this->assertSame([true, false, false, true], [$card['overdue'], $card['dueToday'], $column($board, $todo)['items'][1]['overdue'], $column($board, $todo)['items'][1]['dueToday']]);
        // a column holds more than it shows: the rest is read through the list
        $one = $this->ws('proc', "projects/{$p['id']}/board?per=1");
        $this->assertSame([[$a['number']], true, 2], [array_column($column($one, $todo)['items'], 'number'), $column($one, $todo)['more'], $column($one, $todo)['count']]);
        $this->assertSame([$a['number'], $b['number']], array_column($this->ws('proc', "tasks?project={$p['id']}&stage={$todo}&state=board&sort=priority&order=desc")['items'], 'number'));
        // the filters of the board
        $this->assertSame([[$todo, 1], [$doing, 0], [$done, 0]], array_map(fn ($s) => [$s['id'], $s['count']], $this->ws('proc', "projects/{$p['id']}/board?assignee=me")['stages']));
        $this->assertSame([1, 0, 0], array_column($this->ws('proc', "projects/{$p['id']}/board?tag={$tag['id']}")['stages'], 'count'));
        $this->assertSame([0, 1, 0], array_column($this->ws('proc', "projects/{$p['id']}/board?q=".urlencode('الفروقات'))['stages'], 'count'));
        $this->assertSame([1, 1, 0], array_column($this->ws('proc', "projects/{$p['id']}/board?priority=1")['stages'], 'count'));

        // the lists
        $list = fn (string $query, string $as = 'proc') => $this->ws($as, "tasks?project={$p['id']}&{$query}");
        $numbers = fn (array $page) => array_column($page['items'], 'number');
        $open = $list('');
        $this->assertSame(['items', 'total', 'page', 'pageSize', 'pages'], array_keys($open));
        $this->assertSame([$c['number'], $b['number'], $a['number']], $numbers($open), 'open tasks, the newest first');
        $this->assertSame([$d['number']], $numbers($list('state=done')));
        $this->assertSame([$e['number']], $numbers($list('state=cancelled')));
        $this->assertSame([$f['number']], $numbers($list('state=archived')));
        $this->assertCount(6, $list('state=all')['items']);
        $this->assertCount(4, $list('state=board')['items']);
        $this->assertSame([$a['number']], $numbers($list('overdue=1')));
        $this->assertSame([$a['number']], $numbers($list('due=overdue')));
        $this->assertSame([$b['number']], $numbers($list('due=today')));
        $this->assertSame([$c['number'], $b['number']], $numbers($list('due=week')));
        $this->assertSame([$d['number']], $numbers($list('state=board&due=none')));
        $this->assertSame([$a['number']], $numbers($list('assignee=me')));
        $this->assertSame([$b['number']], $numbers($list("assignee={$inv}")));
        $this->assertSame([$c['number']], $numbers($list('assignee=none')));
        $this->assertSame([$a['number']], $numbers($list("tag={$tag['id']}")));
        $this->assertSame([$c['number']], $numbers($list("stage={$doing}")));
        $this->assertSame([$c['number'], $b['number']], $numbers($list('priority=1')));
        $this->assertSame([$c['number']], $numbers($list('q='.urlencode('الفروقات'))));
        $this->assertSame([$b['number']], $numbers($list("q={$b['number']}")));
        $this->assertSame([$a['number'], $b['number'], $c['number']], $numbers($list('sort=deadline&order=asc')));
        $this->assertSame([$a['number'], $c['number'], $b['number']], $numbers($list('sort=priority&order=desc')));
        $this->assertSame([$a['number'], $b['number'], $c['number']], $numbers($list('sort=title&order=asc')));
        $page = $list('state=all&sort=number&order=asc&pageSize=4&page=2');
        $this->assertSame([6, 2, 2, [$e['number'], $f['number']]], [$page['total'], $page['page'], $page['pages'], $numbers($page)]);
        $this->expectRejected($this->getAs('proc', '/api/workspace/tasks?state=sometimes'), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->getAs('proc', '/api/workspace/tasks?due=someday'), 'INVALID_INPUT', [400]);

        // "my tasks" across projects, and what the top of the page counts
        $elsewhere = $this->newProject([], ['proc']);
        $z = $this->expectOk($this->postAs('proc', '/api/workspace/tasks', ['title' => "{$key} في مشروع آخر", 'projectId' => $elsewhere['id'], 'assigneeKeys' => [$proc]]));
        $this->assertSame([$z['number'], $a['number']], $numbers($this->ws('proc', 'tasks?scope=mine&q='.urlencode($key))));
        $this->assertSame([$z['number']], $numbers($this->ws('proc', 'tasks?scope=created&q='.urlencode($key))));
        $s = $this->ws('proc', 'summary')['tasks'];
        $this->assertGreaterThanOrEqual(2, $s['mine']);
        $this->assertGreaterThanOrEqual(1, $s['overdue']);
        $this->assertGreaterThanOrEqual($s['mine'], $s['open']);
        $this->assertGreaterThanOrEqual(1, $this->ws('inv', 'summary')['tasks']['dueToday']);
        $lk = $this->ws('proc', 'tasks/lookups');
        $this->assertSame(['follows', 'projects', 'tags', 'assignees'], array_keys($lk));
        $mine = collect($lk['projects'])->firstWhere('id', $p['id']);
        $this->assertSame([$p['number'], true, 3], [$mine['number'], $mine['canWork'], count($mine['stages'])]);
        $this->assertContains($tag['id'], array_column($lk['tags'], 'id'));
        $this->assertEmpty(array_diff(['inv', 'proc'], array_column($lk['assignees'], 'username')));
        $this->assertSame(['open' => 3, 'done' => 1, 'overdue' => 1, 'total' => 4], $this->ws('proc', "projects/{$p['id']}")['counts']);
    }

    public function test_a_personal_task_is_seen_by_its_people_only(): void
    {
        $key = 'مرجان'.self::uid();
        // without a project: wm's own to-do, given to himself when nobody is named
        $t = $this->expectOk($this->postAs('wm', '/api/workspace/tasks', ['title' => "{$key} تجديد رخصة الرافعة", 'deadline' => now()->addDay()->toDateString(), 'refType' => 'customer',
            'refNumber' => Customer::orderBy('code')->value('code')]));
        $this->assertSame([null, null, ['wm'], 'customer'], [$t['project'], $t['stage'], array_column($t['assignees'], 'username'), $t['ref']['type']]);
        $this->assertSame([false, true, true], [$t['can']['move'], $t['can']['done'], $t['can']['edit']]);
        $url = "/api/workspace/tasks/{$t['id']}";

        // nobody else sees it — not the projects manager, not the administrator
        foreach (['gm', 'admin', 'inv'] as $who) {
            $this->expectRejected($this->getAs($who, $url), 'WS_TASK_FORBIDDEN', [403]);
            $this->expectRejected($this->postAs($who, "{$url}/comments", ['body' => 'تعليق']), 'WS_TASK_FORBIDDEN', [403]);
            $this->assertSame([], $this->ws($who, 'tasks?state=all&q='.urlencode($key))['items'], $who);
        }
        $this->assertSame([$t['number']], array_column($this->ws('wm', 'tasks?project=none&q='.urlencode($key))['items'], 'number'));
        $withFile = $this->expectOk($this->postAs('wm', "{$url}/comments", ['body' => 'صورة الرخصة', 'files' => [self::pngFile('licence.png')]]));
        $file = collect($withFile['comments'])->firstWhere('kind', 'comment')['files'][0];
        $this->expectRejected($this->getAs('gm', $file['url']), 'WS_TASK_FORBIDDEN', [403]);
        $this->getAs('wm', $file['url'])->assertOk();

        // given to somebody, he sees it and works it
        $this->expectOk($this->patchAs('wm', $url, ['assigneeKeys' => [$this->userId('wm'), $this->userId('inv')]]));
        $asInv = $this->ws('inv', "tasks/{$t['number']}");
        $this->assertTrue($asInv['can']['done']);
        $this->assertSame([$t['number']], array_column($this->ws('inv', 'tasks?scope=mine&q='.urlencode($key))['items'], 'number'));
        // it has no stages: it is finished or cancelled directly
        $this->expectRejected($this->postAs('inv', "{$url}/move", ['stageId' => 'anything']), 'WS_STAGE_NOT_OF_PROJECT', [422]);
        $d = $this->expectOk($this->postAs('inv', "{$url}/done"));
        $this->assertSame(['done', null, 'inv'], [$d['status'], $d['stage'], $d['doneBy']]);
        $this->assertSame('open', $this->expectOk($this->postAs('wm', "{$url}/reopen"))['status']);
    }
}
