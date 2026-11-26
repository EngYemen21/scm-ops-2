<?php

namespace Tests\Feature\Workspace;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Notification;
use App\Models\StatusHistory;
use App\Workspace\Models\Message;
use App\Workspace\Models\Ticket;
use App\Workspace\Models\TicketStage;
use App\Workspace\Models\TicketTeam;
use App\Workspace\Models\TicketType;
use App\Workspace\Models\WsFile;
use Illuminate\Support\Carbon;
use Tests\ApiTestCase;

/**
 * The help desk: who opens the section and who keeps the desk, a ticket from the moment it is raised to its closing
 * (targets, assignment, the conversation with files, stages, closing with a resolution, reopening, archiving), who sees
 * the tickets of a closed team, the list (search, filters, sort, paging) — and every illegal move refused with its code.
 */
class WorkspaceTicketsTest extends ApiTestCase
{
    use WorkspaceFixtures;

    public function test_the_section_needs_page_workspace_and_the_desk_is_kept_by_its_manager(): void
    {
        $desk = $this->desk();
        $ticket = $this->newTicket($desk);
        $before = [Ticket::count(), TicketTeam::count(), TicketStage::count(), TicketType::count()];

        // no page.workspace: the section is closed, reads included; with it, everything the desk shows is read
        foreach (['summary', 'lookups', 'tickets/lookups', 'tickets', "tickets/{$ticket['number']}", 'customers?q=a', 'records?type=so&number=x'] as $path) {
            $this->expectRejected($this->getAs('worker', "/api/workspace/{$path}"), 'FORBIDDEN', [403]);
            $this->ws('wm', $path);
        }
        $this->expectRejected($this->postAs('worker', '/api/workspace/tickets', ['subject' => 'محاولة', 'teamId' => $desk['team']['id']]), 'FORBIDDEN', [403]);
        // page.workspace alone does not keep the desk
        foreach ([['ticket-teams', ['nameAr' => 'فريق']], ['ticket-stages', ['nameAr' => 'مرحلة']], ['ticket-types', ['nameAr' => 'نوع']]] as [$path, $body]) {
            $this->expectRejected($this->postAs('wm', "/api/workspace/{$path}", $body), 'FORBIDDEN', [403]);
        }
        $this->expectRejected($this->patchAs('wm', "/api/workspace/ticket-teams/{$desk['team']['id']}", ['nameAr' => 'تعديل']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->patchAs('wm', "/api/workspace/ticket-stages/{$desk['open']}", ['isClosing' => true]), 'FORBIDDEN', [403]);
        $this->assertSame($before, [Ticket::count(), TicketTeam::count(), TicketStage::count(), TicketType::count()], 'a refused request changes nothing');
        $this->assertGreaterThan(0, AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->where('username', 'wm')->where('entity_id', 'like', '%api/workspace/ticket-%')->count(), 'the denied attempts are on the audit trail');
        $this->expectRejected($this->getAs('wm', '/api/workspace/tickets/TKT-NOPE'), 'WS_TICKET_NOT_FOUND', [404]);
    }

    public function test_the_lookups_and_the_summary_offer_what_the_page_needs(): void
    {
        $desk = $this->desk(['defaultAssigneeId' => $this->userId('proc')]);
        $shared = $this->ws('wm', 'lookups');
        $this->assertSame(['follows', 'users', 'roles', 'priorities', 'recordTypes', 'files'], array_keys($shared));
        $this->assertContains('wm', array_column($shared['users'], 'username'), 'whoever opens the section may be named on a record');
        $this->assertNotContains('worker', array_column($shared['users'], 'username'));
        $this->assertEmpty(array_filter(array_column($shared['users'], 'username'), fn ($u) => str_starts_with($u, 'svc.')), 'service accounts are never named');
        $this->assertSame([0, 1, 2, 3], array_column($shared['priorities'], 'key'));
        $this->assertContains('so', array_column($shared['recordTypes'], 'key'));
        $this->assertSame([5 * 1024 * 1024, 5], [$shared['files']['maxBytes'], $shared['files']['maxFiles']]);
        $this->assertContains('pdf', $shared['files']['extensions']);
        $this->assertContains('finance', array_column($shared['roles'], 'key'));

        $lk = $this->ws('proc', 'tickets/lookups');
        $this->assertSame(['follows', 'teams', 'stages', 'types', 'assignees'], array_keys($lk));
        $team = collect($lk['teams'])->firstWhere('id', $desk['team']['id']);
        $this->assertSame(['id', 'nameAr', 'nameEn', 'description', 'sequence', 'active', 'visibility', 'firstResponseHours', 'resolutionHours', 'defaultAssignee', 'members', 'isMember', 'sourceSystem', 'externalRef', 'owned'], array_keys($team));
        $this->assertSame([true, 'all', 4.0, 48.0, 'proc', ['proc'], false], [$team['isMember'], $team['visibility'], (float) $team['firstResponseHours'], (float) $team['resolutionHours'],
            $team['defaultAssignee']['username'], array_column($team['members'], 'username'), $team['owned']]);
        $this->assertFalse(collect($this->ws('wm', 'tickets/lookups')['teams'])->firstWhere('id', $desk['team']['id'])['isMember']);
        $mine = array_values(array_filter($lk['stages'], fn ($s) => $s['teamIds'] === [$desk['team']['id']]));
        $this->assertSame([[$desk['open'], false], [$desk['work'], false], [$desk['done'], true]], array_map(fn ($s) => [$s['id'], $s['isClosing']], $mine), 'stages come in the order a ticket passes through them');

        $ticket = $this->newTicket($desk);
        $s = $this->ws('proc', 'summary');
        $this->assertSame(['follows', 'me', 'tickets', 'tasks', 'documents'], array_keys($s));
        $this->assertSame(['open', 'mine', 'unassigned', 'overdue'], array_keys($s['tickets']));
        $this->assertSame(['open', 'mine', 'overdue', 'dueToday'], array_keys($s['tasks']));
        $this->assertSame(['tickets' => false, 'projects' => false, 'documents' => false], $s['me']['manages']);
        $this->assertSame(['tickets' => true, 'projects' => false, 'documents' => false], $this->ws('sales', 'summary')['me']['manages']);
        $this->assertGreaterThanOrEqual(1, $s['tickets']['mine'], 'the default assignee of the team got the ticket');
        $this->assertSame('proc', $ticket['assignee']['username']);
    }

    public function test_a_ticket_from_the_request_to_its_closing(): void
    {
        $desk = $this->desk();
        [$wm, $proc, $inv] = [$this->userId('wm'), $this->userId('proc'), $this->userId('inv')];

        // validation: a subject and a team are needed, the people and the type named must exist
        $this->expectRejected($this->postAs('wm', '/api/workspace/tickets', ['teamId' => $desk['team']['id']]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('wm', '/api/workspace/tickets', ['subject' => 'س']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('wm', '/api/workspace/tickets', ['subject' => 'س', 'teamId' => 'no-such-team']), 'WS_TEAM_UNKNOWN', [400]);
        $this->expectRejected($this->postAs('wm', '/api/workspace/tickets', ['subject' => 'س', 'teamId' => $desk['team']['id'], 'typeId' => 'nope']), 'WS_TYPE_UNKNOWN', [400]);
        $this->expectRejected($this->postAs('wm', '/api/workspace/tickets', ['subject' => 'س', 'teamId' => $desk['team']['id'], 'assigneeId' => 'nobody']), 'WS_USER_UNKNOWN', [400]);
        $this->expectRejected($this->postAs('wm', '/api/workspace/tickets', ['subject' => 'س', 'teamId' => $desk['team']['id'], 'requesterEmail' => 'not-an-email']), 'INVALID_INPUT', [400]);

        // wm raises a ticket with a screenshot: nobody is named and the team has no default — its members are told
        $t = $this->expectOk($this->postAs('wm', '/api/workspace/tickets', ['subject' => 'الطابعة لا تطبع ملصقات الشحن', 'description' => 'منذ الصباح', 'teamId' => $desk['team']['id'], 'priority' => 2, 'files' => [self::pngFile()]]));
        $this->assertMatchesRegularExpression('/^TKT-\d{4}-\d{5}$/', $t['number']);
        $this->assertSame(['open', false, 2, $desk['open'], 'staff', 'wm', null, 0], [$t['status'], $t['archived'], $t['priority'], $t['stage']['id'], $t['requester']['kind'], $t['requester']['user']['username'], $t['assignee'], $t['messageCount']]);
        $created = Carbon::parse($t['createdAt']);
        $this->assertSame([$created->copy()->addHours(48)->timestamp, $created->copy()->addHours(4)->timestamp], [Carbon::parse($t['dueAt'])->timestamp, Carbon::parse($t['firstResponseDueAt'])->timestamp], 'the team gives the ticket its two targets');
        $this->assertSame([false, false, null, false], [$t['overdue'], $t['responseOverdue'], $t['firstResponseAt'], $t['dueManual']]);
        $this->assertSame(['created'], array_column($t['messages'], 'event'));
        $this->assertSame(['screen.png', 'image/png', false, true, true], [$t['files'][0]['name'], $t['files'][0]['mime'], $t['files'][0]['fromOdoo'], $t['files'][0]['available'], $t['files'][0]['removable']]);
        $this->assertSame(['edit' => false, 'assign' => false, 'move' => false, 'close' => false, 'reopen' => false, 'archive' => false, 'restore' => false, 'reply' => true, 'note' => false], $t['can'], 'the requester answers; he does not work the ticket');
        $this->assertSame(1, Notification::where('user_id', $proc)->where('entity_number', $t['number'])->count(), 'the members of the team are told a ticket waits');
        $this->assertSame('wm', AuditLog::where('action', 'TICKET.CREATE')->where('entity_id', $t['id'])->value('username'));
        $url = "/api/workspace/tickets/{$t['number']}";

        // the file leaves through the one door, as the bytes that came in
        $file = $this->getAs('wm', $t['files'][0]['url']);
        $file->assertOk();
        $this->assertSame(['image/png', 'nosniff'], [$file->headers->get('Content-Type'), $file->headers->get('X-Content-Type-Options')]);
        $this->assertStringContainsString("inline; filename*=UTF-8''screen.png", (string) $file->headers->get('Content-Disposition'));
        $this->assertSame(base64_decode(explode(',', self::proofImage())[1]), $file->getContent());
        $this->assertStringStartsWith('data:image/png;base64,', $this->ws('proc', "files/{$t['files'][0]['id']}?as=data")['dataUrl']);

        // who works it: the members of its team, its assignee, the manager — not the requester, not a bystander
        foreach ([['assign', ['assigneeId' => $wm]], ['move', ['stageId' => $desk['work']]], ['close', ['resolution' => 'تم']], ['archive', []]] as [$action, $body]) {
            $this->expectRejected($this->postAs('wm', "{$url}/{$action}", $body), 'WS_TICKET_FORBIDDEN', [403]);
            $this->expectRejected($this->postAs('inv', "{$url}/{$action}", $body), 'WS_TICKET_FORBIDDEN', [403]);
        }
        $this->expectRejected($this->patchAs('wm', $url, ['subject' => 'تعديل']), 'WS_TICKET_FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('wm', "{$url}/messages", ['kind' => 'note', 'body' => 'ملاحظة']), 'WS_TICKET_FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('inv', "{$url}/messages", ['kind' => 'reply', 'body' => 'رد']), 'WS_TICKET_FORBIDDEN', [403]);
        $this->assertGreaterThan(0, AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->where('username', 'inv')->where('new_value', 'denied: WS_TICKET_FORBIDDEN')->count());

        // a member takes it
        $this->expectRejected($this->postAs('proc', "{$url}/assign", ['assigneeId' => 'nobody']), 'WS_USER_UNKNOWN', [400]);
        $a = $this->expectOk($this->postAs('proc', "{$url}/assign", ['assigneeId' => $proc]));
        $this->assertSame(['proc', ['created', 'assigned']], [$a['assignee']['username'], array_column($a['messages'], 'event')]);
        $this->assertSame(['edit' => true, 'assign' => true, 'move' => true, 'close' => true, 'reopen' => false, 'archive' => true, 'restore' => false, 'reply' => true, 'note' => true], $a['can']);
        // the manager hands it to somebody else, who is told
        $a = $this->expectOk($this->postAs('sales', "{$url}/assign", ['assigneeId' => $inv]));
        $this->assertSame('inv', $a['assignee']['username']);
        $this->assertSame(1, Notification::where('user_id', $inv)->where('entity_number', $t['number'])->count());
        $this->expectOk($this->postAs('inv', "{$url}/assign", ['assigneeId' => $proc]));

        // the conversation: an empty message is refused; an internal note does not stop the first-response clock; the first reply does
        $this->expectRejected($this->postAs('proc', "{$url}/messages", ['kind' => 'reply']), 'WS_MESSAGE_EMPTY', [400]);
        $this->expectRejected($this->postAs('proc', "{$url}/messages", ['kind' => 'shout', 'body' => 'x']), 'INVALID_INPUT', [400]);
        $n = $this->expectOk($this->postAs('proc', "{$url}/messages", ['kind' => 'note', 'body' => 'يبدو أن الشريط انتهى']));
        $this->assertSame([null, 1], [$n['firstResponseAt'], $n['messageCount']]);
        $r = $this->expectOk($this->postAs('proc', "{$url}/messages", ['kind' => 'reply', 'body' => 'نبدّل الشريط خلال ساعة', 'files' => [self::pdfFile('steps.pdf')]]));
        $this->assertNotNull($r['firstResponseAt']);
        $this->assertSame(2, $r['messageCount']);
        $reply = collect($r['messages'])->firstWhere('kind', 'reply');
        $this->assertSame(['نبدّل الشريط خلال ساعة', 'proc', ['steps.pdf'], null], [$reply['body'], $reply['author']['username'], array_column($reply['files'], 'name'), $reply['origin']]);
        $this->assertSame(1, Notification::where('user_id', $wm)->where('entity_number', $t['number'])->count(), 'the requester is told about the answer');
        $first = $r['firstResponseAt'];
        // the requester writes back (a reply — he reads the notes too: everybody here is staff) and attaches nothing
        $back = $this->expectOk($this->postAs('wm', "{$url}/messages", ['kind' => 'reply', 'body' => 'شكرًا']));
        $this->assertSame([$first, 3, ['note', 'reply', 'reply']], [$back['firstResponseAt'], $back['messageCount'], array_values(array_filter(array_column($back['messages'], 'kind'), fn ($k) => $k !== 'event'))]);
        $this->assertSame(3, Message::where('thread_type', 'ticket')->where('thread_id', $t['id'])->whereIn('kind', ['reply', 'note'])->count());

        // stages: an open stage by a move, a closing stage only by a closing with its resolution
        $this->expectRejected($this->postAs('proc', "{$url}/move", ['stageId' => 'nope']), 'WS_STAGE_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('proc', "{$url}/move", ['stageId' => $desk['done']]), 'WS_STAGE_IS_CLOSING', [422]);
        $other = $this->desk();
        $this->expectRejected($this->postAs('proc', "{$url}/move", ['stageId' => $other['work']]), 'WS_STAGE_NOT_OF_TEAM', [422]);
        $m = $this->expectOk($this->postAs('proc', "{$url}/move", ['stageId' => $desk['work']]));
        $this->assertSame([$desk['work'], 'open'], [$m['stage']['id'], $m['status']]);
        $moved = collect($m['messages'])->firstWhere('event', 'moved');
        $this->assertStringStartsWith('QA working', $moved['data']['to']['en']);
        $this->assertSame(1, AuditLog::where('action', 'TICKET.MOVE')->where('entity_id', $t['id'])->count());
        $this->expectOk($this->postAs('proc', "{$url}/move", ['stageId' => $desk['work']]));
        $this->assertSame(1, AuditLog::where('action', 'TICKET.MOVE')->where('entity_id', $t['id'])->count(), 'moving to where it stands changes nothing');

        $this->expectRejected($this->postAs('proc', "{$url}/close", []), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('proc', "{$url}/close", ['resolution' => 'تم', 'stageId' => $desk['open']]), 'WS_STAGE_NOT_CLOSING', [422]);
        $this->expectRejected($this->postAs('proc', "{$url}/reopen", []), 'WS_TICKET_TRANSITION', [422]);
        $c = $this->expectOk($this->postAs('proc', "{$url}/close", ['resolution' => 'بُدّل شريط الطابعة وجُرّبت الطباعة']));
        $this->assertSame(['closed', $desk['done'], 'بُدّل شريط الطابعة وجُرّبت الطباعة', 'proc', false], [$c['status'], $c['stage']['id'], $c['resolution'], $c['closedBy'], $c['overdue']]);
        $this->assertNotNull($c['closedAt']);
        $this->assertSame(['edit' => true, 'assign' => true, 'move' => false, 'close' => false, 'reopen' => true, 'archive' => true, 'restore' => false, 'reply' => true, 'note' => true], $c['can']);
        $this->assertSame(['open', 'closed'], [StatusHistory::where('entity_id', $t['id'])->value('from_status'), StatusHistory::where('entity_id', $t['id'])->value('to_status')]);
        $this->assertSame(2, Notification::where('user_id', $wm)->where('entity_number', $t['number'])->count(), 'the requester is told his ticket was closed');
        $this->expectRejected($this->postAs('proc', "{$url}/close", ['resolution' => 'مرة أخرى']), 'WS_TICKET_TRANSITION', [422]);
        $this->expectRejected($this->postAs('proc', "{$url}/move", ['stageId' => $desk['open']]), 'WS_TICKET_TRANSITION', [422]);

        // the answer did not settle it: the requester reopens his ticket; a bystander may not
        $this->expectRejected($this->postAs('inv', "{$url}/reopen", []), 'WS_TICKET_FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('wm', "{$url}/reopen", ['stageId' => $desk['done']]), 'WS_STAGE_IS_CLOSING', [422]);
        $o = $this->expectOk($this->postAs('wm', "{$url}/reopen", ['note' => 'ما زالت لا تطبع']));
        $this->assertSame(['open', $desk['open'], null, 'ما زالت لا تطبع'], [$o['status'], $o['stage']['id'], $o['closedAt'], collect($o['messages'])->firstWhere('event', 'reopened')['data']['reason']]);

        // put away and brought back: nothing is deleted
        $ar = $this->expectOk($this->postAs('proc', "{$url}/archive", ['reason' => 'مكررة']));
        $this->assertSame([true, 'مكررة', 'proc'], [$ar['archived'], $ar['archiveReason'], $ar['archivedBy']]);
        $this->assertSame(['edit' => false, 'assign' => false, 'move' => false, 'close' => false, 'reopen' => false, 'archive' => false, 'restore' => true, 'reply' => false, 'note' => false], $ar['can']);
        foreach ([['messages', ['kind' => 'reply', 'body' => 'رد']], ['move', ['stageId' => $desk['work']]], ['assign', ['assigneeId' => $inv]], ['close', ['resolution' => 'تم']]] as [$action, $body]) {
            $this->expectRejected($this->postAs('proc', "{$url}/{$action}", $body), 'WS_ARCHIVED', [422]);
        }
        $this->expectRejected($this->patchAs('proc', $url, ['subject' => 'تعديل']), 'WS_ARCHIVED', [422]);
        $back = $this->expectOk($this->postAs('proc', "{$url}/restore"));
        $this->assertSame([false, null, 1], [$back['archived'], $back['archiveReason'], Ticket::where('id', $t['id'])->count()]);
        $this->assertSame(['created', 'assigned', 'assigned', 'assigned', 'moved', 'closed', 'reopened', 'archived', 'restored'], array_values(array_filter(array_column($back['messages'], 'event'))), 'every step is told on the thread, in order');
    }

    public function test_who_asks_what_it_is_about_and_when_it_is_due(): void
    {
        $desk = $this->desk();
        $customer = Customer::where('active', true)->orderBy('code')->firstOrFail();
        $type = $this->expectOk($this->postAs('sales', '/api/workspace/ticket-types', ['nameAr' => 'شكوى توريد '.self::tag()]));

        // a customer asks: found by the picker, kept by id; a contact name and a phone may stand beside it
        $found = $this->ws('wm', 'customers?q='.urlencode($customer->code));
        $this->assertSame(['id', 'code', 'nameAr', 'nameEn', 'city'], array_keys($found[0]));
        $this->assertContains($customer->code, array_column($found, 'code'));
        $this->expectRejected($this->postAs('wm', '/api/workspace/tickets', ['subject' => 'س', 'teamId' => $desk['team']['id'], 'requesterKind' => 'customer']), 'WS_CUSTOMER_UNKNOWN', [400]);
        $this->expectRejected($this->postAs('wm', '/api/workspace/tickets', ['subject' => 'س', 'teamId' => $desk['team']['id'], 'requesterKind' => 'customer', 'customerId' => 'nope']), 'WS_CUSTOMER_UNKNOWN', [400]);
        $c = $this->newTicket($desk, ['requesterKind' => 'customer', 'customerId' => $customer->id, 'requesterName' => 'أبو خالد', 'requesterPhone' => '0550001111', 'typeId' => $type['id'],
            'refType' => 'customer', 'refNumber' => strtolower($customer->code)]);
        $this->assertSame(['customer', $customer->code, 'أبو خالد', '0550001111', null, $type['id']], [$c['requester']['kind'], $c['requester']['customer']['code'], $c['requester']['name'], $c['requester']['phone'], $c['requester']['user'], $c['type']['id']]);
        $this->assertSame(['type' => 'customer', 'number' => $customer->code, 'labelAr' => 'عميل', 'labelEn' => 'Customer'], $c['ref'], 'the link keeps the number as the record itself writes it');

        // somebody who is neither a customer nor staff: a name is needed
        $this->expectRejected($this->postAs('wm', '/api/workspace/tickets', ['subject' => 'س', 'teamId' => $desk['team']['id'], 'requesterKind' => 'other']), 'INVALID_INPUT', [400]);
        $o = $this->newTicket($desk, ['requesterKind' => 'other', 'requesterName' => 'مندوب شركة الشحن', 'requesterEmail' => 'rep@carrier.example']);
        $this->assertSame(['other', 'مندوب شركة الشحن', 'rep@carrier.example', null, null], [$o['requester']['kind'], $o['requester']['name'], $o['requester']['email'], $o['requester']['customer'], $o['requester']['user']]);

        // the link to another record of OPS is checked when it is saved, and looked up while it is typed
        $this->expectRejected($this->postAs('wm', '/api/workspace/tickets', ['subject' => 'س', 'teamId' => $desk['team']['id'], 'refType' => 'spaceship', 'refNumber' => 'X-1']), 'WS_RECORD_TYPE_UNKNOWN', [400]);
        $this->expectRejected($this->postAs('wm', '/api/workspace/tickets', ['subject' => 'س', 'teamId' => $desk['team']['id'], 'refType' => 'so', 'refNumber' => 'SO-0000-NOPE']), 'WS_RECORD_NOT_FOUND', [400]);
        $this->expectRejected($this->postAs('wm', '/api/workspace/tickets', ['subject' => 'س', 'teamId' => $desk['team']['id'], 'refType' => 'so']), 'WS_RECORD_NOT_FOUND', [400]);
        $this->assertSame(['type' => 'customer', 'number' => $customer->code, 'found' => true, 'labelAr' => 'عميل', 'labelEn' => 'Customer', 'title' => $customer->name_ar],
            $this->ws('wm', 'records?type=customer&number='.urlencode(strtolower($customer->code))));
        $this->assertFalse($this->ws('wm', 'records?type=so&number=SO-0000-NOPE')['found']);
        $this->assertFalse($this->ws('wm', 'records?type=spaceship&number=1')['found']);

        // an edit changes what was sent, clears what was sent empty, leaves the rest — and is told on the thread
        $url = "/api/workspace/tickets/{$c['id']}";
        $e = $this->expectOk($this->patchAs('proc', $url, ['subject' => 'تأخر توريد الطلبية', 'priority' => 3, 'typeId' => null, 'refType' => null, 'refNumber' => null, 'requesterPhone' => null]));
        $this->assertSame(['تأخر توريد الطلبية', 3, null, null, null, 'أبو خالد', $customer->code], [$e['subject'], $e['priority'], $e['type'], $e['ref'], $e['requester']['phone'], $e['requester']['name'], $e['requester']['customer']['code']]);
        $this->assertContains('Subject', array_column(collect($e['messages'])->firstWhere('event', 'edited')['data']['fields'], 'en'));
        $this->assertSame(1, AuditLog::where('action', 'TICKET.UPDATE')->where('entity_id', $c['id'])->count());
        $this->expectOk($this->patchAs('proc', $url, ['subject' => 'تأخر توريد الطلبية', 'priority' => 3]));
        $this->assertSame(1, AuditLog::where('action', 'TICKET.UPDATE')->where('entity_id', $c['id'])->count(), 'the same edit again writes nothing');
        $this->expectRejected($this->patchAs('proc', $url, ['subject' => '']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->patchAs('proc', $url, ['priority' => 9]), 'INVALID_INPUT', [400]);
        // the requester becomes a member of staff: the customer leaves the ticket
        $s = $this->expectOk($this->patchAs('proc', $url, ['requesterKind' => 'staff', 'requesterUserId' => $this->userId('inv')]));
        $this->assertSame(['staff', 'inv', null], [$s['requester']['kind'], $s['requester']['user']['username'], $s['requester']['customer']]);

        // the due date: set by hand it no longer follows the team; given back, it is the team's target again
        $target = Carbon::parse($c['dueAt'])->timestamp;
        $manual = $this->expectOk($this->patchAs('proc', $url, ['dueAt' => '2026-12-01T09:00:00Z']));
        $this->assertSame([true, Carbon::parse('2026-12-01T09:00:00Z')->timestamp], [$manual['dueManual'], Carbon::parse($manual['dueAt'])->timestamp]);
        $this->expectRejected($this->patchAs('proc', $url, ['dueAt' => 'soon']), 'INVALID_INPUT', [400]);
        $given = $this->expectOk($this->patchAs('proc', $url, ['dueAt' => null]));
        $this->assertSame([false, $target], [$given['dueManual'], Carbon::parse($given['dueAt'])->timestamp]);

        // another team takes it: its targets and its own path apply (the stage of the old team does not exist there)
        $slow = $this->desk(['firstResponseHours' => null, 'resolutionHours' => 100]);
        $moved = $this->expectOk($this->patchAs('proc', $url, ['teamId' => $slow['team']['id']]));
        $this->assertSame([$slow['team']['id'], $slow['open'], null, Carbon::parse($c['createdAt'])->addHours(100)->timestamp], [$moved['team']['id'], $moved['stage']['id'], $moved['firstResponseDueAt'], Carbon::parse($moved['dueAt'])->timestamp]);
        // a team without a target gives no due date; a team that was switched off takes no new ticket
        $none = $this->desk(['firstResponseHours' => null, 'resolutionHours' => null]);
        $this->assertSame([null, null], array_values(array_intersect_key($this->newTicket($none), ['dueAt' => 1, 'firstResponseDueAt' => 1])));
        $this->expectOk($this->patchAs('sales', "/api/workspace/ticket-teams/{$none['team']['id']}", ['active' => false]));
        $this->expectRejected($this->postAs('wm', '/api/workspace/tickets', ['subject' => 'س', 'teamId' => $none['team']['id']]), 'WS_TEAM_UNKNOWN', [400]);
    }

    public function test_the_tickets_of_a_closed_team_are_for_its_people(): void
    {
        $closed = $this->desk(['visibility' => 'members'], ['proc']);
        $t = $this->newTicket($closed, ['subject' => 'طلب سرّي '.self::tag()], 'wm');
        $url = "/api/workspace/tickets/{$t['number']}";
        $listed = fn (string $as, string $query = '') => array_column($this->ws($as, "tickets?team={$closed['team']['id']}&state=all{$query}")['items'], 'number');

        // its requester, the members of the team and the manager see it; a bystander does not — not in a list, not by its number, not its files
        foreach (['wm', 'proc', 'sales', 'admin'] as $who) {
            $this->assertSame([$t['number']], $listed($who), $who);
            $this->ws($who, "tickets/{$t['number']}");
        }
        $this->assertSame([], $listed('inv'));
        $this->expectRejected($this->getAs('inv', $url), 'WS_TICKET_FORBIDDEN', [403]);
        $withFile = $this->newTicket($closed, ['files' => [self::pngFile()]], 'wm');
        $this->expectRejected($this->getAs('inv', $withFile['files'][0]['url']), 'WS_TICKET_FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('inv', "{$url}/messages", ['kind' => 'reply', 'body' => 'رد']), 'WS_TICKET_FORBIDDEN', [403]);
        // given to somebody outside the team, he sees it and works it
        $this->expectOk($this->postAs('proc', "{$url}/assign", ['assigneeId' => $this->userId('inv')]));
        $this->assertSame([$t['number']], $listed('inv'));
        $this->assertTrue($this->ws('inv', "tickets/{$t['number']}")['can']['close']);

        // the scopes of the list: mine (assigned to me or asked by me), assigned, requested, my team's
        $this->assertSame([$withFile['number'], $t['number']], $listed('wm', '&scope=requested'));
        $this->assertSame([], $listed('wm', '&scope=assigned'));
        $this->assertSame([$t['number']], $listed('inv', '&scope=assigned'));
        $this->assertSame([$t['number']], $listed('inv', '&scope=mine'));
        $this->assertSame([], $listed('wm', '&scope=team'), 'wm is a member of no team');
        $this->assertSame([$withFile['number'], $t['number']], $listed('proc', '&scope=team'));
        $this->expectRejected($this->getAs('wm', '/api/workspace/tickets?scope=everything'), 'INVALID_INPUT', [400]);

        // the files of the conversation: taken off by whoever added them or by the manager — the row stays
        $r = $this->expectOk($this->postAs('wm', "/api/workspace/tickets/{$withFile['id']}/messages", ['kind' => 'reply', 'body' => 'مرفق', 'files' => [self::pdfFile('a.pdf'), self::pngFile('b.png')]]));
        [$a, $b] = collect($r['messages'])->firstWhere('kind', 'reply')['files'];
        $this->assertTrue($a['removable']);
        $asProc = collect($this->ws('proc', "tickets/{$withFile['id']}")['messages'])->firstWhere('kind', 'reply')['files'];
        $this->assertFalse($asProc[0]['removable'], 'a member who did not add the file does not remove it');
        $this->expectRejected($this->postAs('proc', "/api/workspace/files/{$a['id']}/remove"), 'WS_FILE_FORBIDDEN', [403]);
        $this->assertSame(['ok' => true], $this->expectOk($this->postAs('wm', "/api/workspace/files/{$a['id']}/remove", ['reason' => 'ملف خاطئ'])));
        $this->assertSame(['ok' => true], $this->expectOk($this->postAs('sales', "/api/workspace/files/{$b['id']}/remove")));
        $this->expectRejected($this->getAs('wm', $a['url']), 'WS_FILE_NOT_FOUND', [404]);
        $this->assertSame([], collect($this->ws('wm', "tickets/{$withFile['id']}")['messages'])->firstWhere('kind', 'reply')['files']);
        $row = WsFile::findOrFail($a['id']);
        $this->assertSame(['wm', 'ملف خاطئ'], [$row->removed_by, $row->remove_reason]);
        $this->assertNotNull($row->removed_at);
    }

    public function test_the_list_searches_filters_sorts_and_pages(): void
    {
        $desk = $this->desk();
        $key = 'ياقوت'.self::uid();
        [$proc, $inv] = [$this->userId('proc'), $this->userId('inv')];
        $a = $this->newTicket($desk, ['subject' => "{$key} تعطل الماسح", 'priority' => 3, 'assigneeId' => $proc]);
        $b = $this->newTicket($desk, ['subject' => "{$key} طلب مستخدم جديد", 'priority' => 0, 'assigneeId' => $inv, 'requesterKind' => 'other', 'requesterName' => 'مكتب الموارد', 'requesterEmail' => 'hr@office.example']);
        $c = $this->newTicket($desk, ['subject' => "{$key} استفسار عن فاتورة", 'priority' => 1]);
        $d = $this->newTicket($desk, ['subject' => "{$key} شاشة المستودع", 'priority' => 1]);
        $e = $this->newTicket($desk, ['subject' => "{$key} تذكرة للأرشيف", 'priority' => 2]);
        $this->expectOk($this->postAs('proc', "/api/workspace/tickets/{$d['id']}/close", ['resolution' => 'أُعيد تشغيلها']));
        $this->expectOk($this->postAs('proc', "/api/workspace/tickets/{$e['id']}/archive"));
        // a is past its due date; c got no answer in time
        Ticket::where('id', $a['id'])->update(['due_at' => now()->subHours(3)]);
        Ticket::where('id', $c['id'])->update(['first_response_due_at' => now()->subHour(), 'due_at' => now()->addDays(5)]);
        $this->expectOk($this->postAs('proc', "/api/workspace/tickets/{$b['id']}/messages", ['kind' => 'reply', 'body' => 'تم إنشاء المستخدم']));

        $list = fn (string $query, string $as = 'proc') => $this->ws($as, "tickets?team={$desk['team']['id']}&{$query}");
        $numbers = fn (array $page) => array_column($page['items'], 'number');

        $open = $list('');
        $this->assertSame(['items', 'total', 'page', 'pageSize', 'pages'], array_keys($open));
        $this->assertSame([$c['number'], $b['number'], $a['number']], $numbers($open), 'open tickets, the newest first: closed and archived ones are out');
        $row = collect($open['items'])->firstWhere('number', $a['number']);
        $this->assertSame(['id', 'number', 'subject', 'status', 'archived', 'priority', 'team', 'stage', 'type', 'requester', 'assignee', 'dueAt', 'firstResponseDueAt', 'firstResponseAt', 'overdue', 'responseOverdue',
            'ref', 'messageCount', 'lastMessageAt', 'sourceNumber', 'createdAt', 'createdBy', 'closedAt', 'sourceSystem', 'externalRef', 'owned'], array_keys($row));
        $this->assertSame([true, false], [$row['overdue'], $row['responseOverdue']]);
        $this->assertSame([false, true], array_values(array_intersect_key(collect($open['items'])->firstWhere('number', $c['number']), ['overdue' => 1, 'responseOverdue' => 1])));

        $this->assertSame([$d['number']], $numbers($list('state=closed')));
        $this->assertSame([$e['number']], $numbers($list('state=archived')));
        $this->assertCount(5, $list('state=all')['items']);
        $this->assertSame([$c['number'], $a['number']], $numbers($list('overdue=1')), 'past the due date, or past the first-response target without an answer');
        $this->assertSame([$a['number']], $numbers($list('priority=3')));
        $this->assertSame([$a['number']], $numbers($list('assignee=me')));
        $this->assertSame([$b['number']], $numbers($list("assignee={$inv}")));
        $this->assertSame([$c['number']], $numbers($list('assignee=none')));
        $this->assertSame([$a['number']], $numbers($list("stage={$desk['open']}&priority=3")));
        $this->assertSame([], $numbers($list("stage={$desk['work']}")));
        $this->assertSame([$c['number'], $b['number'], $a['number']], $numbers($list('origin=ops')));
        $this->assertSame([], $numbers($list('origin=odoo')));
        // search: the number, the subject, who asks
        $this->assertSame([$b['number']], $numbers($list('q='.urlencode('مكتب الموارد'))));
        $this->assertSame([$b['number']], $numbers($list('q=hr@office')));
        $this->assertSame([$a['number']], $numbers($list('q='.urlencode('الماسح'))));
        $this->assertSame([$c['number']], $numbers($list("q={$c['number']}")));
        $this->assertCount(5, $list('state=all&q='.urlencode($key))['items']);
        $this->assertSame([], $numbers($list('q=zzz-no-such-ticket')));
        // sort: the column asked for, either way; a sparse column keeps its empty rows last
        $this->assertSame([$a['number'], $c['number'], $b['number']], $numbers($list('sort=priority&order=desc')));
        $this->assertSame([$b['number'], $c['number'], $a['number']], $numbers($list('sort=priority&order=asc')));
        $this->assertSame([$a['number'], $b['number'], $c['number']], $numbers($list('sort=dueAt&order=asc')));
        $this->assertSame($b['number'], $numbers($list('sort=lastMessageAt&order=asc'))[0], 'the one with a message comes before those that have none, whichever way');
        $this->assertSame($b['number'], $numbers($list('sort=lastMessageAt&order=desc'))[0]);
        $this->assertSame([$a['number'], $b['number'], $c['number']], $numbers($list('sort=number&order=asc')));
        // paging
        $page1 = $list('state=all&pageSize=2&sort=number&order=asc');
        $this->assertSame([5, 1, 2, 3, [$a['number'], $b['number']]], [$page1['total'], $page1['page'], $page1['pageSize'], $page1['pages'], $numbers($page1)]);
        $this->assertSame([$e['number']], $numbers($list('state=all&pageSize=2&sort=number&order=asc&page=3')));
        $this->expectRejected($this->getAs('proc', '/api/workspace/tickets?state=sometimes'), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->getAs('proc', '/api/workspace/tickets?priority=7'), 'INVALID_INPUT', [400]);

        // the people tickets are assigned to, for the filter
        $this->assertEmpty(array_diff(['proc', 'inv'], array_column($this->ws('proc', 'tickets/lookups')['assignees'], 'username')));
    }

    public function test_the_desk_settings_and_the_rules_of_an_upload(): void
    {
        $desk = $this->desk();
        $team = $desk['team']['id'];

        // a team: validation, an edit, its members by key, switching it off
        $this->expectRejected($this->postAs('sales', '/api/workspace/ticket-teams', ['nameEn' => 'No Arabic name']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', '/api/workspace/ticket-teams', ['nameAr' => 'فريق', 'visibility' => 'secret']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', '/api/workspace/ticket-teams', ['nameAr' => 'فريق', 'memberKeys' => ['nobody']]), 'WS_USER_UNKNOWN', [400]);
        $this->expectRejected($this->patchAs('sales', '/api/workspace/ticket-teams/nope', ['nameAr' => 'فريق']), 'WS_TEAM_NOT_FOUND', [404]);
        $edited = $this->expectOk($this->patchAs('sales', "/api/workspace/ticket-teams/{$team}", ['nameAr' => 'دعم المستودعات '.self::tag(), 'visibility' => 'members', 'resolutionHours' => 24.5, 'firstResponseHours' => null,
            'defaultAssigneeId' => $this->userId('inv'), 'memberKeys' => [$this->userId('inv'), $this->userId('disp')]]));
        $this->assertSame(['members', 24.5, null, 'inv', ['disp', 'inv']], [$edited['visibility'], (float) $edited['resolutionHours'], $edited['firstResponseHours'], $edited['defaultAssignee']['username'],
            collect($edited['members'])->pluck('username')->sort()->values()->all()]);
        $this->assertSame(1, AuditLog::where('action', 'TICKET.TEAM.UPDATE')->where('entity_id', $team)->count());
        $this->assertSame('inv', $this->newTicket($desk)['assignee']['username'], 'a new ticket nobody was named for goes to the default assignee');

        // a stage: tickets stand in it — it cannot change what it means under them, nor leave while open tickets wait in it
        $t = $this->newTicket($desk);
        $this->expectRejected($this->patchAs('sales', "/api/workspace/ticket-stages/{$desk['open']}", ['isClosing' => true]), 'WS_STAGE_IN_USE', [422]);
        $this->expectRejected($this->patchAs('sales', "/api/workspace/ticket-stages/{$desk['open']}", ['active' => false]), 'WS_STAGE_IN_USE', [422]);
        $this->expectRejected($this->postAs('sales', '/api/workspace/ticket-stages', ['nameAr' => 'مرحلة', 'teamIds' => ['nope']]), 'WS_TEAM_UNKNOWN', [400]);
        $renamed = $this->expectOk($this->patchAs('sales', "/api/workspace/ticket-stages/{$desk['work']}", ['nameAr' => 'بانتظار قطعة', 'nameEn' => 'Waiting for a part', 'sequence' => 2]));
        $this->assertSame(['بانتظار قطعة', 'Waiting for a part', false, [$team]], [$renamed['nameAr'], $renamed['nameEn'], $renamed['isClosing'], $renamed['teamIds']]);
        $off = $this->expectOk($this->patchAs('sales', "/api/workspace/ticket-stages/{$desk['work']}", ['active' => false]));
        $this->assertFalse($off['active']);
        $this->expectRejected($this->postAs('inv', "/api/workspace/tickets/{$t['id']}/move", ['stageId' => $desk['work']]), 'WS_STAGE_NOT_FOUND', [404]);
        // a team without a closing stage cannot close: the manager is told what is missing
        $bare = $this->expectOk($this->postAs('sales', '/api/workspace/ticket-teams', ['nameAr' => 'بلا مراحل '.self::tag()]));
        $only = $this->expectOk($this->postAs('sales', '/api/workspace/ticket-stages', ['nameAr' => 'وحيدة '.self::tag(), 'sequence' => 1, 'teamIds' => [$bare['id']]]));
        $lone = $this->expectOk($this->postAs('wm', '/api/workspace/tickets', ['subject' => 'بلا إغلاق', 'teamId' => $bare['id']]));
        $this->assertSame($only['id'], $lone['stage']['id']);

        // a type: created, renamed, switched off — the tickets that carry it keep it
        $type = $this->expectOk($this->postAs('sales', '/api/workspace/ticket-types', ['nameAr' => 'عطل جهاز '.self::tag()]));
        $this->assertSame([$type['nameAr'], true, false], [$type['nameEn'], $type['active'], $type['owned']], 'without an English name the Arabic one stands for both');
        $typed = $this->newTicket($desk, ['typeId' => $type['id']]);
        $this->assertFalse($this->expectOk($this->patchAs('sales', "/api/workspace/ticket-types/{$type['id']}", ['active' => false, 'nameEn' => 'Device fault']))['active']);
        $this->assertSame('Device fault', $this->ws('inv', "tickets/{$typed['id']}")['type']['nameEn']);
        $this->expectRejected($this->patchAs('sales', '/api/workspace/ticket-types/nope', ['nameAr' => 'نوع']), 'WS_TYPE_NOT_FOUND', [404]);

        // uploads: the cap is on the files of ONE request together; the type is read from the bytes, never from the name
        $url = "/api/workspace/tickets/{$t['id']}/messages";
        $files = WsFile::count();
        $this->expectRejected($this->postAs('inv', $url, ['kind' => 'reply', 'body' => 'x', 'files' => array_fill(0, 6, self::pngFile())]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('inv', $url, ['kind' => 'reply', 'body' => 'x', 'files' => [['fileName' => 'empty.pdf', 'base64' => 'data:application/pdf;base64,AAAA====']]]), 'WS_FILE_EMPTY', [400]);
        $this->expectRejected($this->postAs('inv', $url, ['kind' => 'reply', 'body' => 'x', 'files' => [['fileName' => 'tool.exe', 'base64' => base64_encode("MZ\x90\x00\x03\x00\x00\x00")]]]), 'WS_FILE_TYPE', [400]);
        $this->expectRejected($this->postAs('inv', $url, ['kind' => 'reply', 'body' => 'x', 'files' => [['fileName' => 'invoice.pdf', 'base64' => base64_encode("MZ\x90\x00\x03\x00\x00\x00")]]]), 'WS_FILE_TYPE', [400]);
        $this->expectRejected($this->postAs('inv', $url, ['kind' => 'reply', 'body' => 'x', 'files' => [['fileName' => 'page.html', 'base64' => base64_encode('<html><script>alert(1)</script></html>')]]]), 'WS_FILE_TYPE', [400]);
        $big = base64_encode(str_repeat('سطر من ملف نصي طويل'."\n", 80000)); // about 2.9 MB of text
        $this->expectRejected($this->postAs('inv', $url, ['kind' => 'reply', 'body' => 'x', 'files' => [['fileName' => 'a.txt', 'base64' => $big], ['fileName' => 'b.txt', 'base64' => $big]]]), 'WS_FILE_TOO_LARGE', [400]);
        $this->assertSame($files, WsFile::count(), 'a refused upload keeps nothing');
        // a picture named as something else is kept as what it is; text and office files pass
        $ok = $this->expectOk($this->postAs('inv', $url, ['kind' => 'reply', 'files' => [['fileName' => 'صورة الشاشة.pdf', 'base64' => self::proofImage()], ['fileName' => 'notes.txt', 'base64' => base64_encode("سطر\nline")], self::officeFile('جرد.xlsx')]]));
        $kept = collect($ok['messages'])->last()['files'];
        $this->assertSame([['صورة الشاشة.png', 'image/png'], ['notes.txt', 'text/plain'], ['جرد.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']], array_map(fn ($f) => [$f['name'], $f['mime']], $kept));
        $sheet = $this->getAs('inv', $kept[2]['url']);
        $this->assertSame('application/octet-stream', $sheet->headers->get('Content-Type'), 'only pictures and PDF are shown in place; anything else is a download');
        $this->assertStringStartsWith('attachment;', (string) $sheet->headers->get('Content-Disposition'));
    }
}
