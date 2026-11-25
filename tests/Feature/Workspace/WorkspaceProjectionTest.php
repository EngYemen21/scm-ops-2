<?php

namespace Tests\Feature\Workspace;

use App\Integration\Odoo\Models\OdooChange;
use App\Integration\Services\ExternalRefs;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Support\AppError;
use App\Workspace\Models\DocTag;
use App\Workspace\Models\Document;
use App\Workspace\Models\Folder;
use App\Workspace\Models\Message;
use App\Workspace\Models\Project;
use App\Workspace\Models\State;
use App\Workspace\Models\Task;
use App\Workspace\Models\TaskStage;
use App\Workspace\Models\TaskTag;
use App\Workspace\Models\Ticket;
use App\Workspace\Models\TicketStage;
use App\Workspace\Models\TicketTeam;
use App\Workspace\Models\TicketType;
use App\Workspace\Models\WsFile;
use App\Workspace\OdooWorkspaceProjection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\ApiTestCase;

/**
 * Odoo's Helpdesk, Project and Documents apps → the OPS workspace (OdooWorkspaceProjection), on a small mirror written
 * straight into the copy's tables — Odoo is never called. One story in four steps: the plan and the first load (and a
 * second load that changes nothing); what Odoo owns is not changed here while replies and comments stay open; the
 * section handed over; and the feed that keeps it current, conversation included.
 */
class WorkspaceProjectionTest extends ApiTestCase
{
    use WorkspaceFixtures;

    private const MODELS = ['helpdesk.team', 'helpdesk.stage', 'helpdesk.ticket.type', 'helpdesk.ticket', 'project.tags', 'project.project', 'project.task.type', 'project.task',
        'documents.folder', 'documents.tag', 'documents.document', 'res.users', 'res.partner', 'res.groups', 'ir.attachment', 'mail.message.subtype'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sealNetwork();
        $this->followOdoo(true);
    }

    private function projection(): OdooWorkspaceProjection
    {
        return $this->app->make(OdooWorkspaceProjection::class);
    }

    /** The OPS row an Odoo record became. */
    private function ops(string $class, string $ref): object
    {
        return $class::where('source_system', 'odoo')->where('external_ref', $ref)->firstOrFail();
    }

    private static function sorted(array $counts): array
    {
        ksort($counts);

        return $counts;
    }

    /** A small Odoo: one live help desk, two projects, a little tree of documents — every value invented. */
    private function buildMirror(): Customer
    {
        $this->emptyMirror();
        foreach (self::MODELS as $model) {
            $this->mirrorModel($model);
        }
        $this->mirrorModel('mail.message', false); // the copy of Odoo's messages is still running
        DB::table('users')->where('username', 'proc')->update(['email' => 'proc@company.example']);
        $customer = Customer::create(['code' => 'WSC-'.self::uid(), 'name_ar' => 'مطعم الواحة', 'name_en' => 'Al Waha Restaurant', 'active' => true]);
        $this->app->make(ExternalRefs::class)->link('odoo', 'customer', '500', (string) $customer->id, $customer->code);

        // people: 7 is the OPS user «sales» by login, 9 is «proc» by the e-mail of his contact, 8 has no OPS user
        $this->mirrorRecord('res.users', 7, ['name' => 'Sales Person', 'login' => 'sales', 'partner_id' => [70, 'Sales Person']]);
        $this->mirrorRecord('res.users', 8, ['name' => 'منى الحربي', 'login' => 'mona@odoo.example', 'partner_id' => [80, 'منى الحربي']]);
        $this->mirrorRecord('res.users', 9, ['name' => 'Proc Person', 'login' => 'p.odoo', 'partner_id' => [90, 'Proc Person']]);
        $this->mirrorRecord('res.partner', 70, ['name' => 'Sales Person', 'email' => false, 'parent_id' => false]);
        $this->mirrorRecord('res.partner', 80, ['name' => 'منى الحربي', 'email' => 'mona@odoo.example', 'parent_id' => false]);
        $this->mirrorRecord('res.partner', 90, ['name' => 'Proc Person', 'email' => 'PROC@company.example', 'parent_id' => false]);
        $this->mirrorRecord('res.partner', 500, ['name' => 'مطعم الواحة', 'parent_id' => false]);
        $this->mirrorRecord('res.partner', 501, ['name' => 'أحمد', 'parent_id' => [500, 'مطعم الواحة']]);
        $this->mirrorRecord('res.groups', 55, ['name' => 'Accounting / Advisor']);
        $this->mirrorRecord('mail.message.subtype', 1, ['name' => 'Discussions', 'internal' => false]);
        $this->mirrorRecord('mail.message.subtype', 2, ['name' => 'Note', 'internal' => true]);

        // the help desk
        $this->mirrorRecord('helpdesk.team', 1, ['name' => 'Customer Care', 'active' => true, 'sequence' => 10, 'member_ids' => [7, 8], 'privacy_visibility' => 'internal', 'description' => false]);
        $this->mirrorRecord('helpdesk.team', 2, ['name' => 'فريق متوقف', 'active' => false, 'sequence' => 11, 'member_ids' => [], 'privacy_visibility' => 'invited_internal']);
        $this->mirrorRecord('helpdesk.stage', 11, ['name' => 'New', 'active' => true, 'sequence' => 0, 'fold' => false, 'team_ids' => [1]]);
        $this->mirrorRecord('helpdesk.stage', 12, ['name' => 'In Progress', 'active' => true, 'sequence' => 1, 'fold' => false, 'team_ids' => [1]]);
        $this->mirrorRecord('helpdesk.stage', 13, ['name' => 'Solved', 'active' => true, 'sequence' => 2, 'fold' => true, 'team_ids' => [1]]);
        $this->mirrorRecord('helpdesk.ticket.type', 21, ['name' => 'Question', 'sequence' => 10]);
        $this->mirrorRecord('helpdesk.ticket', 101, ['name' => 'تأخر التوريد', 'active' => true, 'team_id' => [1, 'Customer Care'], 'stage_id' => [11, 'New'], 'user_id' => [7, 'Sales Person'], 'priority' => '2',
            'partner_id' => [501, 'مطعم الواحة, أحمد'], 'partner_name' => 'أحمد', 'partner_email' => 'ahmad@alwaha.example', 'partner_phone' => false, 'ticket_type_id' => [21, 'Question'], 'ticket_ref' => '00101',
            'description' => '<p>لم تصل <b>الشحنة</b></p>', 'create_date' => '2026-08-01 08:00:00', 'create_uid' => [8, 'منى الحربي'], 'write_uid' => [8, 'منى الحربي'], 'close_date' => false, 'kanban_state' => 'normal', 'sla_deadline' => false]);
        $this->mirrorRecord('helpdesk.ticket', 102, ['name' => 'مشكلة فاتورة', 'active' => true, 'team_id' => [1, 'Customer Care'], 'stage_id' => [13, 'Solved'], 'user_id' => [8, 'منى الحربي'], 'priority' => '0',
            'partner_id' => [70, 'Sales Person'], 'partner_name' => false, 'ticket_type_id' => false, 'ticket_ref' => '00102', 'description' => false, 'create_date' => '2026-08-02 08:00:00', 'create_uid' => [9, 'Proc Person'],
            'close_date' => '2026-08-05 12:00:00']);
        $this->mirrorRecord('helpdesk.ticket', 103, ['name' => 'تذكرة مؤرشفة', 'active' => false, 'team_id' => [1, 'Customer Care'], 'stage_id' => [12, 'In Progress'], 'user_id' => false, 'priority' => '1',
            'partner_id' => false, 'partner_name' => false, 'ticket_ref' => '00103', 'create_date' => '2026-08-03 08:00:00', 'create_uid' => [8, 'منى الحربي'], 'close_date' => false]);
        // what was written on them (9003 is a notification of Odoo, not somebody's words)
        $this->mirrorRecord('mail.message', 9001, ['model' => 'helpdesk.ticket', 'res_id' => 101, 'message_type' => 'comment', 'subtype_id' => [1, 'Discussions'], 'body' => '<p>نعتذر، تصل غدًا</p>',
            'author_id' => [70, 'Sales Person'], 'date' => '2026-08-01 09:00:00', 'attachment_ids' => [7001]]);
        $this->mirrorRecord('mail.message', 9002, ['model' => 'helpdesk.ticket', 'res_id' => 101, 'message_type' => 'comment', 'subtype_id' => [2, 'Note'], 'body' => '<p>المورد تأخر</p>',
            'author_id' => [80, 'منى الحربي'], 'date' => '2026-08-01 09:30:00', 'attachment_ids' => []]);
        $this->mirrorRecord('mail.message', 9003, ['model' => 'helpdesk.ticket', 'res_id' => 101, 'message_type' => 'notification', 'subtype_id' => [3, 'Stage Changed'], 'body' => '', 'author_id' => [70, 'Sales Person'],
            'date' => '2026-08-01 09:40:00', 'attachment_ids' => []]);
        $this->mirrorRecord('mail.message', 9004, ['model' => 'project.task', 'res_id' => 301, 'message_type' => 'comment', 'subtype_id' => [2, 'Note'], 'body' => '<p>بدأ التركيب</p>', 'author_id' => [70, 'Sales Person'],
            'date' => '2026-08-06 10:00:00', 'attachment_ids' => []]);
        $this->mirrorRecord('mail.message', 9005, ['model' => 'sale.order', 'res_id' => 101, 'message_type' => 'comment', 'subtype_id' => [1, 'Discussions'], 'body' => '<p>رسالة على أمر بيع</p>', 'author_id' => [70, 'Sales Person'],
            'date' => '2026-08-06 10:00:00', 'attachment_ids' => []]);
        $attachment = fn (int $id, string $model, int $record, string $name, string $mime, array $more = []) => $this->mirrorRecord('ir.attachment', $id, $more + ['name' => $name, 'res_model' => $model, 'res_id' => $record,
            'mimetype' => $mime, 'file_size' => 83, 'type' => 'binary', 'res_field' => false, 'create_date' => '2026-08-01 09:00:00', 'create_uid' => [7, 'Sales Person']]);
        $attachment(7001, 'helpdesk.ticket', 101, 'waybill.png', 'image/png');
        $attachment(7002, 'helpdesk.ticket', 101, 'photo.jpg', 'image/jpeg');
        $attachment(7003, 'helpdesk.ticket', 101, 'thumbnail', 'image/png', ['res_field' => 'thumbnail']);
        $attachment(7100, 'documents.document', 401, 'contract-v1.pdf', 'application/pdf');
        $attachment(7101, 'documents.document', 401, 'contract-v2.pdf', 'application/pdf');

        // projects: stage 41 stands in both projects; stage 43 is somebody's personal column
        $this->mirrorRecord('project.tags', 31, ['name' => 'عاجل', 'color' => 3]);
        $this->mirrorRecord('project.project', 201, ['name' => 'تطوير المستودع', 'active' => true, 'user_id' => [7, 'Sales Person'], 'type_ids' => [41, 42], 'privacy_visibility' => 'portal', 'date_start' => '2026-07-01', 'date' => false,
            'last_update_status' => 'to_define', 'description' => false, 'create_date' => '2026-06-20 08:00:00', 'create_uid' => [7, 'Sales Person']]);
        $this->mirrorRecord('project.project', 202, ['name' => 'مشروع قديم', 'active' => false, 'user_id' => false, 'type_ids' => [41], 'privacy_visibility' => 'followers', 'last_update_status' => 'to_define']);
        $this->mirrorRecord('project.task.type', 41, ['name' => 'To Do', 'active' => true, 'sequence' => 1, 'fold' => false, 'project_ids' => [201], 'user_id' => false]);
        $this->mirrorRecord('project.task.type', 42, ['name' => 'Done', 'active' => true, 'sequence' => 2, 'fold' => true, 'project_ids' => [201], 'user_id' => false]);
        $this->mirrorRecord('project.task.type', 43, ['name' => 'Inbox', 'active' => true, 'sequence' => 1, 'fold' => false, 'project_ids' => [], 'user_id' => [8, 'منى الحربي']]);
        $this->mirrorRecord('project.task', 301, ['name' => 'تركيب الرفوف', 'active' => true, 'project_id' => [201, 'تطوير المستودع'], 'stage_id' => [41, 'To Do'], 'user_ids' => [7, 8], 'tag_ids' => [31], 'state' => '01_in_progress',
            'priority' => '1', 'date_deadline' => '2026-10-20 00:00:00', 'description' => '<p>الممر أ</p>', 'create_date' => '2026-08-06 08:00:00', 'create_uid' => [7, 'Sales Person'], 'personal_stage_type_ids' => []]);
        $this->mirrorRecord('project.task', 302, ['name' => 'جرد افتتاحي', 'active' => true, 'project_id' => [201, 'تطوير المستودع'], 'stage_id' => [42, 'Done'], 'user_ids' => [], 'tag_ids' => [], 'state' => '1_done', 'priority' => '0',
            'date_end' => '2026-08-10 10:00:00', 'create_date' => '2026-08-06 09:00:00', 'create_uid' => [7, 'Sales Person']]);
        $this->mirrorRecord('project.task', 303, ['name' => 'مهمة شخصية', 'active' => true, 'project_id' => false, 'stage_id' => false, 'user_ids' => [7], 'tag_ids' => [], 'state' => '01_in_progress', 'priority' => '0',
            'personal_stage_type_ids' => [43], 'create_date' => '2026-08-07 09:00:00', 'create_uid' => [8, 'منى الحربي']]);
        $this->mirrorRecord('project.task', 304, ['name' => 'مهمة في مشروع قديم', 'active' => false, 'project_id' => [202, 'مشروع قديم'], 'stage_id' => [41, 'To Do'], 'user_ids' => [], 'tag_ids' => [], 'state' => '1_canceled', 'priority' => '0']);

        // documents: folder 50 is listed before its parent 53; 51 is restricted to an Odoo group; 52 shows people their own papers
        $this->mirrorRecord('documents.folder', 50, ['name' => 'فرعي قبل أبيه', 'active' => true, 'sequence' => 5, 'parent_folder_id' => [53, 'عام'], 'group_ids' => [], 'read_group_ids' => [], 'user_specific' => false, 'user_specific_write' => false]);
        $this->mirrorRecord('documents.folder', 51, ['name' => 'Finance', 'active' => true, 'sequence' => 1, 'parent_folder_id' => false, 'group_ids' => [55], 'read_group_ids' => [], 'user_specific' => false, 'user_specific_write' => false,
            'description' => '<p>أوراق المالية</p>']);
        $this->mirrorRecord('documents.folder', 52, ['name' => 'عقود', 'active' => true, 'sequence' => 2, 'parent_folder_id' => [51, 'Finance'], 'group_ids' => [], 'read_group_ids' => [], 'user_specific' => true, 'user_specific_write' => true]);
        $this->mirrorRecord('documents.folder', 53, ['name' => 'عام', 'active' => true, 'sequence' => 3, 'parent_folder_id' => false, 'group_ids' => [], 'read_group_ids' => [], 'user_specific' => false, 'user_specific_write' => false]);
        $this->mirrorRecord('documents.tag', 61, ['name' => 'Validated', 'facet_id' => [5, 'Status'], 'folder_id' => [51, 'Finance'], 'sequence' => 1]);
        $this->mirrorRecord('documents.document', 401, ['name' => 'عقد التوريد', 'active' => true, 'type' => 'binary', 'url' => false, 'folder_id' => [52, 'عقود'], 'owner_id' => [7, 'Sales Person'], 'attachment_id' => [7101, 'contract-v2.pdf'],
            'previous_attachment_ids' => [7100], 'tag_ids' => [61], 'file_size' => 83, 'res_model' => 'documents.document', 'res_id' => 401, 'create_date' => '2026-07-15 08:00:00', 'create_uid' => [7, 'Sales Person']]);
        $this->mirrorRecord('documents.document', 402, ['name' => 'بوابة الزكاة', 'active' => true, 'type' => 'url', 'url' => 'https://zatca.example/portal', 'folder_id' => [53, 'عام'], 'owner_id' => [8, 'منى الحربي'], 'attachment_id' => false,
            'previous_attachment_ids' => [], 'tag_ids' => [], 'file_size' => 0, 'res_model' => false, 'res_id' => false]);
        $this->mirrorRecord('documents.document', 403, ['name' => 'رخصة المركبة', 'active' => false, 'type' => 'binary', 'folder_id' => [53, 'عام'], 'owner_id' => [8, 'منى الحربي'], 'attachment_id' => [7102, 'licence.jpg'],
            'previous_attachment_ids' => [], 'tag_ids' => [], 'file_size' => 1200, 'res_model' => 'fleet.vehicle', 'res_id' => 12]);
        $this->mirrorRecord('documents.document', 404, ['name' => 'بلا مجلد', 'active' => true, 'type' => 'binary', 'folder_id' => false, 'owner_id' => false, 'attachment_id' => false]);

        return $customer;
    }

    public function test_the_plan_the_first_load_and_a_second_load_that_changes_nothing(): void
    {
        $customer = $this->buildMirror();
        $projection = $this->projection();
        $this->assertSame('workspace', $projection->key());
        $this->assertTrue($projection->ready());
        $tables = fn () => [TicketTeam::count(), TicketStage::count(), TicketType::count(), Ticket::count(), Project::count(), TaskStage::count(), Task::count(), TaskTag::count(), Folder::count(), DocTag::count(),
            Document::count(), Message::count(), WsFile::count(), DB::table('number_sequences')->count(), AuditLog::count()];
        $before = $tables();

        // half a copy is never guessed from: without one of its models the pass refuses
        DB::table('odoo_models')->where('model', 'documents.document')->update(['full_state' => 'running']);
        try {
            $projection->plan();
            $this->fail('expected ODOO_COPY_INCOMPLETE');
        } catch (AppError $e) {
            $this->assertSame('ODOO_COPY_INCOMPLETE', $e->errorCode);
        }
        DB::table('odoo_models')->where('model', 'documents.document')->update(['full_state' => 'done']);

        // the copy of Odoo's messages is still running: the plan says the conversation waits, and counts the rest
        $waiting = $projection->plan();
        $this->assertSame([true, 1, 0], [$waiting['dryRun'], $waiting['counts']['conversation.waitingForCopy'], $waiting['counts']['messages.created'] ?? 0]);
        $this->mirrorModel('mail.message');

        $expected = ['docTags' => 1, 'documents' => 3, 'files' => 5, 'folders' => 4, 'messages' => 3, 'projects' => 2, 'stages' => 3, 'taskStages' => 3, 'taskTags' => 1, 'tasks' => 4, 'teams' => 2, 'tickets' => 3, 'types' => 1];
        $always = ['documents.skippedNoFolder' => 1, 'messages.skippedSystem' => 1, 'people.byName' => 1, 'people.matched' => 2, 'taskStages.skippedPersonal' => 1, 'tickets.withCustomer' => 1];
        $counts = fn (string $outcome) => self::sorted($always + array_combine(array_map(fn (string $k) => "{$k}.{$outcome}", array_keys($expected)), array_values($expected)));

        $plan = $projection->plan();
        $this->assertSame(self::sorted($counts('created') + ['folders.restricted' => 1]), self::sorted($plan['counts']), 'the plan counts what a load would do');
        $this->assertSame([true, false], [$plan['dryRun'], isset($plan['failed'])]);
        $this->assertSame($before, $tables(), 'a plan writes nothing — no row, no number, no audit line');
        $this->assertNull(State::read(OdooWorkspaceProjection::CURSOR));

        // the first load
        $applied = $projection->apply();
        $this->assertSame(self::sorted($counts('created') + ['folders.restricted' => 1]), self::sorted($applied['counts']));
        $this->assertFalse($applied['dryRun']);
        // a second load changes nothing
        $audit = AuditLog::count();
        $rows = $tables();
        $this->assertSame($counts('unchanged'), self::sorted($projection->apply()['counts']), 'idempotent');
        $this->assertSame([$audit, array_slice($rows, 0, 13)], [AuditLog::count(), array_slice($tables(), 0, 13)]);
        $this->assertNotNull(State::read(OdooWorkspaceProjection::CURSOR), 'from here on the runs follow the feed of Odoo\'s messages');

        // ── the help desk as OPS holds it
        $team = $this->ops(TicketTeam::class, '1');
        $lk = $this->ws('sales', 'tickets/lookups');
        $this->assertTrue($lk['follows']);
        $t1 = collect($lk['teams'])->firstWhere('id', $team->id);
        $this->assertSame(['خدمة العملاء', 'Customer Care', 'all', true, 'odoo', '1', true, true], [$t1['nameAr'], $t1['nameEn'], $t1['visibility'], $t1['active'], $t1['sourceSystem'], $t1['externalRef'], $t1['owned'], $t1['isMember']]);
        $this->assertSame([['sales', false], [null, true]], array_map(fn ($m) => [$m['username'], $m['external']], $t1['members']), 'an Odoo user is an OPS user by identity, otherwise a name');
        $this->assertSame('name:منى الحربي', $t1['members'][1]['key']);
        $t2 = collect($lk['teams'])->firstWhere('id', $this->ops(TicketTeam::class, '2')->id);
        $this->assertSame(['فريق متوقف', false, 'members'], [$t2['nameAr'], $t2['active'], $t2['visibility']]);
        $solved = collect($lk['stages'])->firstWhere('id', $this->ops(TicketStage::class, '13')->id);
        $this->assertSame(['تم الحل', 'Solved', true, [$team->id], true], [$solved['nameAr'], $solved['nameEn'], $solved['isClosing'], $solved['teamIds'], $solved['owned']]);
        $this->assertSame(['استفسار', 'Question'], array_values(array_intersect_key(collect($lk['types'])->firstWhere('id', $this->ops(TicketType::class, '21')->id), ['nameAr' => 1, 'nameEn' => 1])));

        $a = $this->ws('sales', 'tickets/'.$this->ops(Ticket::class, '101')->number);
        $this->assertMatchesRegularExpression('/^TKT-\d{4}-\d{5}$/', $a['number']);
        $this->assertSame(['تأخر التوريد', 'open', false, 2, 'Customer Care', 'New', 'Question', '00101', 'لم تصل الشحنة', 'odoo', '101', true],
            [$a['subject'], $a['status'], $a['archived'], $a['priority'], $a['team']['nameEn'], $a['stage']['nameEn'], $a['type']['nameEn'], $a['sourceNumber'], $a['description'], $a['sourceSystem'], $a['externalRef'], $a['owned']]);
        $this->assertSame(['customer', $customer->code, 'أحمد', 'ahmad@alwaha.example'], [$a['requester']['kind'], $a['requester']['customer']['code'], $a['requester']['name'], $a['requester']['email']], 'a contact of a linked company asks for that customer');
        $this->assertSame(['sales', false, 'منى الحربي'], [$a['assignee']['username'], $a['assignee']['external'], $a['createdBy']]);
        $this->assertSame(Carbon::parse('2026-08-01 08:00:00', 'UTC')->timestamp, Carbon::parse($a['createdAt'])->timestamp, 'the dates are Odoo\'s, not the moment of the load');
        // its conversation: the reply and the internal note people wrote in Odoo, each by whoever wrote it; the notification is not somebody's words
        $this->assertSame([['event', 'created'], ['reply', null], ['note', null]], array_map(fn ($m) => [$m['kind'], $m['event']], $a['messages']));
        [, $reply, $note] = $a['messages'];
        $this->assertSame(['نعتذر، تصل غدًا', 'sales', 'odoo', ['waybill.png']], [$reply['body'], $reply['author']['username'], $reply['origin'], array_column($reply['files'], 'name')]);
        $this->assertSame(['المورد تأخر', 'منى الحربي', true], [$note['body'], $note['author']['nameAr'], $note['author']['external']]);
        $this->assertSame([2, Carbon::parse('2026-08-01 09:30:00', 'UTC')->timestamp], [$a['messageCount'], Carbon::parse($a['lastMessageAt'])->timestamp]);
        // its files are linked to Odoo's attachments, not copied: one on the message, one on the ticket itself; a field's picture is not a file
        $this->assertSame([['photo.jpg', true, false, false]], array_map(fn ($f) => [$f['name'], $f['fromOdoo'], $f['available'], $f['removable']], $a['files']));
        $this->assertSame([true, false], [$reply['files'][0]['fromOdoo'], $reply['files'][0]['available']], 'its content is not in the Odoo backup yet');
        $this->expectRejected($this->getAs('sales', $reply['files'][0]['url']), 'WS_FILE_NOT_ARCHIVED', [404]);
        $row = WsFile::findOrFail($reply['files'][0]['id']);
        $this->assertSame(['odoo', '7001', null, 'message'], [$row->storage, $row->external_ref, $row->storage_key, $row->owner_type]);

        $b = $this->ws('sales', 'tickets/'.$this->ops(Ticket::class, '102')->number);
        $this->assertSame(['closed', 'Solved', 'staff', 'sales', null, 'name:منى الحربي', true, 'Proc Person'],
            [$b['status'], $b['stage']['nameEn'], $b['requester']['kind'], $b['requester']['user']['username'], $b['assignee']['id'], $b['assignee']['key'], $b['assignee']['external'], $b['createdBy']]);
        $this->assertSame(Carbon::parse('2026-08-05 12:00:00', 'UTC')->timestamp, Carbon::parse($b['closedAt'])->timestamp);
        $this->assertSame(['created', 'closed'], array_column($b['messages'], 'event'));
        $this->assertSame($this->userId('proc'), $this->ops(Ticket::class, '102')->created_by_id, 'matched by the e-mail of his Odoo contact');
        $c = $this->ws('sales', 'tickets/'.$this->ops(Ticket::class, '103')->number);
        $this->assertSame([true, 'open', 'other', 'منى الحربي', null], [$c['archived'], $c['status'], $c['requester']['kind'], $c['requester']['name'], $c['assignee']], 'a ticket nobody was named on was raised by whoever entered it');
        $this->assertSame([$a['number']], array_column($this->ws('wm', 'tickets?origin=odoo&q=00101')['items'], 'number'), 'found by the number people know it by in Odoo');
        $this->assertSame([$a['number']], array_column($this->ws('wm', 'tickets?origin=odoo&q=101')['items'], 'number'));

        // ── projects and tasks
        $p = $this->ws('sales', 'projects/'.$this->ops(Project::class, '201')->number);
        $this->assertMatchesRegularExpression('/^PRJ-\d{4}-\d{5}$/', $p['number']);
        $this->assertSame(['تطوير المستودع', 'active', false, 'all', 'sales', '2026-07-01', null, true, [['للتنفيذ', 'To Do', false], ['مكتملة', 'Done', true]]],
            [$p['name'], $p['status'], $p['archived'], $p['visibility'], $p['manager']['username'], $p['startDate'], $p['endDate'], $p['owned'], array_map(fn ($s) => [$s['nameAr'], $s['nameEn'], $s['isClosing']], $p['stages'])]);
        $this->assertSame(['open' => 1, 'done' => 1, 'overdue' => 0, 'total' => 2], $p['counts']);
        $old = $this->ops(Project::class, '202');
        $this->assertSame([true, 'members', ['41@202']], [$old->archived, $old->visibility, TaskStage::where('project_id', $old->id)->pluck('external_ref')->all()], 'a stage Odoo ties to two projects is a column in each');
        $this->assertSame(0, TaskStage::where('external_ref', 'like', '43@%')->count(), 'a personal stage is no project\'s column');

        $task = $this->ws('sales', 'tasks/'.$this->ops(Task::class, '301')->number);
        $this->assertMatchesRegularExpression('/^TSK-\d{4}-\d{5}$/', $task['number']);
        $this->assertSame(['تركيب الرفوف', 'open', 1, '2026-10-20', 'To Do', $p['number'], [['sales', false], [null, true]], ['عاجل'], 'الممر أ', true, 1],
            [$task['title'], $task['status'], $task['priority'], $task['deadline'], $task['stage']['nameEn'], $task['project']['number'], array_map(fn ($x) => [$x['username'], $x['external']], $task['assignees']),
                array_column($task['tags'], 'name'), $task['description'], $task['owned'], $task['commentCount']]);
        $this->assertSame([['event', null], ['comment', 'بدأ التركيب']], array_map(fn ($m) => [$m['kind'], $m['body']], $task['comments']));
        $done = $this->ops(Task::class, '302');
        $this->assertSame(['done', Carbon::parse('2026-08-10 10:00:00', 'UTC')->timestamp, '42@201'], [$done->status, $done->done_at->timestamp, TaskStage::find($done->stage_id)->external_ref]);
        $personal = $this->ops(Task::class, '303');
        $this->assertSame([null, null, ['Inbox']], [$personal->project_id, $personal->stage_id, $personal->extra['personalStages']]);
        $this->assertSame('open', $this->ws('sales', "tasks/{$personal->number}")['status'], 'its assignee sees a personal task');
        $this->expectRejected($this->getAs('admin', "/api/workspace/tasks/{$personal->number}"), 'WS_TASK_FORBIDDEN', [403]);
        $this->assertSame([true, 'cancelled'], [$this->ops(Task::class, '304')->archived, $this->ops(Task::class, '304')->status]);

        // ── documents
        [$finance, $contracts, $general, $early] = [$this->ops(Folder::class, '51'), $this->ops(Folder::class, '52'), $this->ops(Folder::class, '53'), $this->ops(Folder::class, '50')];
        $this->assertSame(['roles', null, 'roles', null, ['odooWriteGroups' => ['Accounting / Advisor']], 'أوراق المالية'], [$finance->read_scope, $finance->read_roles, $finance->write_scope, $finance->write_roles, $finance->extra, $finance->description],
            'restricted to an Odoo group: closed here until the documents manager names the OPS roles');
        $this->assertSame([$finance->id, 'all', 'all', true, true], [$contracts->parent_id, $contracts->read_scope, $contracts->write_scope, $contracts->own_read, $contracts->own_write]);
        $this->assertSame($general->id, $early->parent_id, 'a folder listed before its parent still finds it');
        $tree = collect($this->ws('wm', 'folders')['folders'])->keyBy('id');
        $this->assertSame([false, true, true, true], [$tree->has($finance->id), $tree->has($contracts->id), $tree->has($general->id), $tree[$contracts->id]['parentHidden']]);
        $tag = $this->ops(DocTag::class, '61');
        $this->assertSame(['Validated', 'Status', $finance->id], [$tag->name, $tag->group_name, $tag->folder_id]);

        $doc = $this->ws('admin', 'documents/'.$this->ops(Document::class, '401')->number);
        $this->assertMatchesRegularExpression('/^DOC-\d{4}-\d{5}$/', $doc['number']);
        $this->assertSame(['عقد التوريد', 'file', $contracts->id, 'sales', 2, 'application/pdf', 83, ['Validated'], true, [2, 1], ['contract-v2.pdf', 'contract-v1.pdf']],
            [$doc['name'], $doc['kind'], $doc['folder']['id'], $doc['owner']['username'], $doc['version'], $doc['mime'], $doc['size'], array_column($doc['tags'], 'name'), $doc['owned'],
                array_column($doc['versions'], 'versionNo'), array_column($doc['versions'], 'name')]);
        $this->assertSame([['Finance', 'عقود'], false], [array_column($doc['path'], 'name'), $doc['can']['download']], 'its content is not in the Odoo backup yet');
        // «own only»: sales owns it and reads it in the folder; wm reads the folder and does not see it
        $this->assertSame([$doc['number']], array_column($this->ws('sales', "documents?folder={$contracts->id}")['items'], 'number'));
        $this->assertSame([], $this->ws('wm', "documents?folder={$contracts->id}")['items']);
        $link = $this->ws('wm', 'documents/'.$this->ops(Document::class, '402')->number);
        $this->assertSame(['link', 'https://zatca.example/portal', null, 'منى الحربي', true], [$link['kind'], $link['url'], $link['file'], $link['owner']['nameAr'], $link['owner']['external']]);
        $vehicle = $this->ops(Document::class, '403');
        $this->assertSame([true, 'fleet.vehicle', 12, 1], [$vehicle->archived, $vehicle->extra['resModel'], $vehicle->extra['resId'], WsFile::where('owner_type', 'document')->where('owner_id', $vehicle->id)->where('external_ref', '7102')->count()],
            'what the paper hangs on in Odoo is kept beside it');
        $this->assertSame(0, Document::where('source_system', 'odoo')->where('external_ref', '404')->count());

        // once the backup holds the attachment, the same file row serves it — from the backup's disk, through the folder's permission
        Storage::fake('local');
        Storage::disk('local')->put('odoo-files/101/7101', '%PDF-1.4 the archived contract');
        DB::table('odoo_files')->insert(['attachment_id' => 7101, 'res_model' => 'documents.document', 'res_id' => 401, 'name' => 'contract-v2.pdf', 'mimetype' => 'application/pdf', 'size' => 30, 'status' => 'stored',
            'disk' => 'local', 'path' => 'odoo-files/101/7101', 'stored_at' => now()]);
        $kept = $this->ws('sales', "documents/{$doc['number']}");
        $this->assertSame([true, true, false], [$kept['can']['download'], $kept['versions'][0]['available'], $kept['versions'][1]['available']]);
        $file = $this->getAs('sales', $kept['file']['url']);
        $file->assertOk();
        $this->assertSame('%PDF-1.4 the archived contract', $file->streamedContent());
        $this->assertStringContainsString(rawurlencode('عقد التوريد.pdf'), (string) $file->headers->get('Content-Disposition'));
        $this->expectRejected($this->getAs('wm', $kept['file']['url']), 'WS_DOCUMENT_FORBIDDEN', [403]);
        $this->expectRejected($this->getAs('sales', $kept['versions'][1]['url']), 'WS_FILE_NOT_ARCHIVED', [404]);
        // an attachment that hangs on people's records in Odoo keeps the rule of the backup
        DB::table('odoo_files')->where('attachment_id', 7101)->update(['res_model' => 'hr.contract']);
        $this->expectRejected($this->getAs('sales', $kept['file']['url']), 'ODOO_PRIVATE', [403]);
        $this->getAs('admin', $kept['file']['url'])->assertOk();
        DB::table('odoo_files')->where('attachment_id', 7101)->update(['res_model' => 'documents.document']);
        $this->assertSame(0, DB::table('stored_files')->where('key', 'like', 'workspace/document/'.strtolower($this->ops(Document::class, '401')->id).'%')->count(), 'linked, never copied');
        $this->assertGreaterThan(0, AuditLog::where('action', 'WS.ODOO.CREATE')->where('username', 'svc.odoo')->where('entity_type', 'WsTicket')->count(), 'the load is on the audit trail under the service user');
    }

    public function test_what_odoo_owns_is_not_changed_here_and_what_is_made_here_works_fully(): void
    {
        [$team, $ticket, $project, $task] = [$this->ops(TicketTeam::class, '1'), $this->ops(Ticket::class, '101'), $this->ops(Project::class, '201'), $this->ops(Task::class, '301')];
        [$new, $progress, $solved] = [$this->ops(TicketStage::class, '11')->id, $this->ops(TicketStage::class, '12')->id, $this->ops(TicketStage::class, '13')->id];
        $owned = fn ($res) => $this->expectRejected($res, 'WORKSPACE_OWNED_BY_SOURCE', [422]);
        $url = "/api/workspace/tickets/{$ticket->number}";

        // ── a ticket of Odoo: nothing Odoo holds is changed here, by anybody
        $before = $ticket->only(['subject', 'stage_id', 'assignee_id', 'status', 'archived']);
        foreach (['sales', 'admin'] as $who) {
            $owned($this->patchAs($who, $url, ['subject' => 'تعديل من العمليات']));
            $owned($this->postAs($who, "{$url}/assign", ['assigneeId' => $this->userId('wm')]));
            $owned($this->postAs($who, "{$url}/move", ['stageId' => $progress]));
            $owned($this->postAs($who, "{$url}/close", ['resolution' => 'تم الحل']));
            $owned($this->postAs($who, "{$url}/archive"));
        }
        $owned($this->postAs('sales', '/api/workspace/tickets/'.$this->ops(Ticket::class, '102')->number.'/reopen'));
        $owned($this->postAs('sales', '/api/workspace/tickets/'.$this->ops(Ticket::class, '103')->number.'/restore'));
        $this->assertSame($before, $ticket->fresh()->only(['subject', 'stage_id', 'assignee_id', 'status', 'archived']));
        $seen = $this->ws('sales', "tickets/{$ticket->number}");
        $this->assertSame(['edit' => false, 'assign' => false, 'move' => false, 'close' => false, 'reopen' => false, 'archive' => false, 'restore' => false, 'reply' => true, 'note' => true], $seen['can']);
        // … but what people write here is OPS's own: a reply and a note, with a file
        $r = $this->expectOk($this->postAs('sales', "{$url}/messages", ['kind' => 'reply', 'body' => 'تواصلنا مع العميل من نظام العمليات', 'files' => [self::pngFile('call.png')]]));
        $mine = collect($r['messages'])->last();
        $this->assertSame(['reply', null, 'sales', [['call.png', false, true]], 3], [$mine['kind'], $mine['origin'], $mine['author']['username'], array_map(fn ($f) => [$f['name'], $f['fromOdoo'], $f['removable']], $mine['files']), $r['messageCount']]);
        $this->expectOk($this->postAs('sales', "{$url}/messages", ['kind' => 'note', 'body' => 'ملاحظة داخلية من العمليات']));
        // a file of Odoo is not taken off here; the one added here is
        $odooFile = collect($r['messages'])->first(fn ($m) => $m['origin'] === 'odoo' && $m['files'])['files'][0];
        $owned($this->postAs('admin', "/api/workspace/files/{$odooFile['id']}/remove"));
        $this->expectOk($this->postAs('sales', "/api/workspace/files/{$mine['files'][0]['id']}/remove"));

        // ── the desk: Odoo's names and members stay Odoo's; the targets and the default assignee are OPS's own
        $owned($this->patchAs('sales', "/api/workspace/ticket-teams/{$team->id}", ['nameAr' => 'اسم آخر']));
        $owned($this->patchAs('sales', "/api/workspace/ticket-teams/{$team->id}", ['memberKeys' => [], 'resolutionHours' => 10]));
        $owned($this->patchAs('sales', "/api/workspace/ticket-stages/{$new}", ['nameAr' => 'اسم آخر']));
        $owned($this->patchAs('sales', '/api/workspace/ticket-types/'.$this->ops(TicketType::class, '21')->id, ['active' => false]));
        $targets = $this->expectOk($this->patchAs('sales', "/api/workspace/ticket-teams/{$team->id}", ['firstResponseHours' => 2, 'resolutionHours' => 24, 'defaultAssigneeId' => $this->userId('proc')]));
        $this->assertSame([2.0, 24.0, 'proc', 'Customer Care', true], [(float) $targets['firstResponseHours'], (float) $targets['resolutionHours'], $targets['defaultAssignee']['username'], $targets['nameEn'], $targets['owned']]);

        // ── a ticket made here in Odoo's team is OPS's: it takes the team's path and targets and works fully
        $own = $this->expectOk($this->postAs('wm', '/api/workspace/tickets', ['subject' => 'تذكرة من العمليات '.self::tag(), 'teamId' => $team->id]));
        $this->assertSame([null, false, $new, 'proc', Carbon::parse($own['createdAt'])->addHours(24)->timestamp], [$own['sourceSystem'], $own['owned'], $own['stage']['id'], $own['assignee']['username'], Carbon::parse($own['dueAt'])->timestamp]);
        $ownUrl = "/api/workspace/tickets/{$own['id']}";
        $this->assertSame($progress, $this->expectOk($this->postAs('proc', "{$ownUrl}/move", ['stageId' => $progress]))['stage']['id']);
        $this->assertSame('أولوية', $this->expectOk($this->patchAs('sales', $ownUrl, ['subject' => 'أولوية', 'priority' => 3]))['subject']);
        $closed = $this->expectOk($this->postAs('proc', "{$ownUrl}/close", ['resolution' => 'عولجت']));
        $this->assertSame(['closed', $solved], [$closed['status'], $closed['stage']['id']]);

        // ── projects and tasks
        $pUrl = "/api/workspace/projects/{$project->id}";
        $owned($this->patchAs('gm', $pUrl, ['name' => 'اسم آخر']));
        $owned($this->postAs('gm', "{$pUrl}/status", ['status' => 'on_hold']));
        $owned($this->postAs('gm', "{$pUrl}/stages", ['nameAr' => 'مرحلة من العمليات']));
        $owned($this->postAs('gm', "{$pUrl}/archive"));
        $owned($this->patchAs('gm', '/api/workspace/task-stages/'.TaskStage::where('external_ref', '41@201')->value('id'), ['nameAr' => 'اسم آخر']));
        $owned($this->patchAs('gm', '/api/workspace/task-tags/'.$this->ops(TaskTag::class, '31')->id, ['name' => 'اسم آخر']));
        // who works the tasks people add here is OPS's own list
        $members = $this->expectOk($this->putAs('gm', "{$pUrl}/members", ['memberKeys' => [$this->userId('inv')]]));
        $this->assertSame([['inv'], false, true, false, true], [array_column($members['members'], 'username'), $members['can']['edit'], $members['can']['members'], $members['can']['stages'], $members['can']['addTask']]);
        $tUrl = "/api/workspace/tasks/{$task->number}";
        $owned($this->patchAs('inv', $tUrl, ['title' => 'عنوان آخر']));
        $owned($this->postAs('inv', "{$tUrl}/move", ['stageId' => TaskStage::where('external_ref', '42@201')->value('id')]));
        $owned($this->postAs('inv', "{$tUrl}/done"));
        $owned($this->postAs('inv', "{$tUrl}/cancel", ['reason' => 'سبب']));
        $owned($this->postAs('inv', "{$tUrl}/archive"));
        $owned($this->postAs('inv', "{$tUrl}/checklist", ['text' => 'بند']));
        $commented = $this->expectOk($this->postAs('inv', "{$tUrl}/comments", ['body' => 'تعليق من العمليات']));
        $this->assertSame([2, ['edit' => false, 'move' => false, 'done' => false, 'reopen' => false, 'cancel' => false, 'archive' => false, 'restore' => false, 'comment' => true, 'checklist' => false]], [$commented['commentCount'], $commented['can']]);
        // a task made here in Odoo's project crosses Odoo's columns like any other
        $mineTask = $this->expectOk($this->postAs('inv', '/api/workspace/tasks', ['title' => 'مهمة من العمليات', 'projectId' => $project->id]));
        $this->assertSame([false, '41@201'], [$mineTask['owned'], TaskStage::find($mineTask['stage']['id'])->external_ref]);
        $this->assertSame('done', $this->expectOk($this->postAs('inv', "/api/workspace/tasks/{$mineTask['id']}/move", ['stageId' => TaskStage::where('external_ref', '42@201')->value('id')]))['status']);

        // ── documents: Odoo's folders and papers stay as they are; who reads and writes them by role is OPS's to say
        [$finance, $general, $doc] = [$this->ops(Folder::class, '51'), $this->ops(Folder::class, '53'), $this->ops(Document::class, '401')];
        $owned($this->patchAs('admin', "/api/workspace/folders/{$finance->id}", ['name' => 'المالية']));
        $owned($this->patchAs('admin', "/api/workspace/folders/{$finance->id}", ['ownRead' => true]));
        $owned($this->postAs('admin', '/api/workspace/folders/'.$this->ops(Folder::class, '50')->id.'/archive'));
        $owned($this->patchAs('admin', '/api/workspace/doc-tags/'.$this->ops(DocTag::class, '61')->id, ['name' => 'اسم آخر']));
        $this->assertFalse(collect($this->ws('finance', 'folders')['folders'])->contains('id', $finance->id));
        $access = $this->expectOk($this->patchAs('admin', "/api/workspace/folders/{$finance->id}", ['readScope' => 'roles', 'readRoles' => ['finance'], 'writeScope' => 'roles', 'writeRoles' => ['finance']]));
        $this->assertSame([['finance'], ['finance'], 'Finance', true], [$access['readRoles'], $access['writeRoles'], $access['name'], $access['owned']]);
        $this->assertTrue(collect($this->ws('finance', 'folders')['folders'])->contains('id', $finance->id), 'the documents manager named the OPS role');
        $dUrl = "/api/workspace/documents/{$doc->number}";
        $owned($this->patchAs('admin', $dUrl, ['name' => 'اسم آخر']));
        $owned($this->postAs('admin', "{$dUrl}/move", ['folderId' => $general->id]));
        $owned($this->postAs('admin', "{$dUrl}/versions", ['file' => self::pdfFile()]));
        $owned($this->postAs('admin', "{$dUrl}/archive"));
        $this->assertSame(['edit' => false, 'move' => false, 'version' => false, 'archive' => false, 'restore' => false, 'download' => true], $this->ws('admin', "documents/{$doc->number}")['can']);
        // a paper uploaded here into Odoo's folder is OPS's
        $up = $this->expectOk($this->postAs('wm', '/api/workspace/documents', ['folderId' => $general->id, 'file' => self::pdfFile('ops.pdf')]));
        $this->assertSame([false, true, 2], [$up['owned'], $up['can']['edit'], $this->expectOk($this->postAs('wm', "/api/workspace/documents/{$up['id']}/versions", ['file' => self::pdfFile('ops2.pdf')]))['version']]);

        // a load does not touch what is OPS's own: the replies written here, the targets, the members, the roles
        $again = $this->projection()->apply()['counts'];
        $this->assertSame([3, 2, 4, 3, 4, 3], [$again['tickets.unchanged'], $again['teams.unchanged'], $again['folders.unchanged'], $again['documents.unchanged'], $again['tasks.unchanged'], $again['messages.unchanged']]);
        $this->assertSame([], array_values(array_filter(array_keys($again), fn ($k) => str_ends_with($k, '.updated') || str_ends_with($k, '.created') || str_ends_with($k, '.removed') || str_ends_with($k, '.relinked'))));
        $this->assertSame([24.0, $this->userId('proc')], [(float) $team->fresh()->resolution_hours, $team->fresh()->default_assignee_id]);
        $this->assertSame([['finance'], 4, 2], [$finance->fresh()->read_roles, $this->ws('sales', "tickets/{$ticket->number}")['messageCount'], $this->ws('inv', "tasks/{$task->number}")['commentCount']]);
        $this->assertSame(['inv'], array_column($this->ws('gm', "projects/{$project->id}")['members'], 'username'));
    }

    public function test_the_section_handed_over_is_changed_here_and_a_load_then_leaves_it_alone(): void
    {
        $ticket = $this->ops(Ticket::class, '101');
        // the switch is off: the section is OPS's, the records keep saying where they came from
        $this->followOdoo(false);
        $seen = $this->ws('sales', "tickets/{$ticket->number}");
        $this->assertSame(['odoo', false, true, true], [$seen['sourceSystem'], $seen['owned'], $seen['can']['edit'], $seen['can']['close']]);
        $this->assertFalse($this->ws('sales', 'tickets/lookups')['follows']);
        $edited = $this->expectOk($this->patchAs('sales', "/api/workspace/tickets/{$ticket->number}", ['subject' => 'تأخر التوريد — يتابعه نظام العمليات', 'priority' => 3]));
        $this->assertSame(['تأخر التوريد — يتابعه نظام العمليات', 3], [$edited['subject'], $edited['priority']]);
        $this->expectOk($this->patchAs('sales', '/api/workspace/ticket-teams/'.$this->ops(TicketTeam::class, '1')->id, ['nameAr' => 'خدمة عملاء العمليات']));
        $this->expectOk($this->patchAs('admin', '/api/workspace/folders/'.$this->ops(Folder::class, '51')->id, ['name' => 'المالية']));
        $this->expectOk($this->patchAs('gm', '/api/workspace/projects/'.$this->ops(Project::class, '201')->id, ['description' => 'يُدار من هنا']));

        // Odoo changes its copy afterwards; a load only adds what is missing and leaves what OPS holds
        $this->mirrorRecord('helpdesk.ticket', 101, ['name' => 'عنوان غيّره أودو بعد التسليم', 'active' => true, 'team_id' => [1, 'Customer Care'], 'stage_id' => [12, 'In Progress'], 'user_id' => [8, 'منى الحربي'], 'priority' => '0',
            'partner_id' => [501, 'أحمد'], 'partner_name' => 'أحمد', 'ticket_ref' => '00101', 'create_date' => '2026-08-01 08:00:00', 'create_uid' => [8, 'منى الحربي'], 'close_date' => false]);
        $this->mirrorRecord('helpdesk.ticket', 105, ['name' => 'تذكرة جديدة بعد التسليم', 'active' => true, 'team_id' => [1, 'Customer Care'], 'stage_id' => [11, 'New'], 'user_id' => false, 'priority' => '0', 'partner_id' => false,
            'ticket_ref' => '00105', 'create_date' => '2026-08-20 08:00:00', 'create_uid' => [7, 'Sales Person'], 'close_date' => false]);
        $counts = $this->projection()->apply()['counts'];
        $this->assertSame([3, 1, 2, 4], [$counts['tickets.kept'], $counts['tickets.created'], $counts['teams.kept'], $counts['folders.kept']]);
        $this->assertSame([], array_values(array_filter(array_keys($counts), fn ($k) => str_ends_with($k, '.updated') || str_ends_with($k, '.unchanged') || str_ends_with($k, '.archived'))));
        $this->assertSame(['تأخر التوريد — يتابعه نظام العمليات', 3, 'خدمة عملاء العمليات', 'المالية'],
            [$ticket->fresh()->subject, $ticket->fresh()->priority, $this->ops(TicketTeam::class, '1')->name_ar, $this->ops(Folder::class, '51')->name]);
        $this->assertSame('تذكرة جديدة بعد التسليم', $this->ops(Ticket::class, '105')->subject);
    }

    public function test_the_feed_keeps_the_section_current_conversation_included(): void
    {
        // the section follows Odoo again: what Odoo holds replaces what was written over it here
        $ticket = $this->ops(Ticket::class, '101');
        $cursor = (int) State::read(OdooWorkspaceProjection::CURSOR);
        $this->assertSame(0, $this->projection()->consume()['changes'], 'nothing waits on the feed');

        // in Odoo: ticket 101 is closed and handed to somebody else; 102 is deleted; a brand-new ticket arrives in a brand-new stage;
        // a task is finished; a document gets a new version; a folder is archived
        $this->mirrorRecord('helpdesk.ticket', 101, ['name' => 'تأخر التوريد', 'active' => true, 'team_id' => [1, 'Customer Care'], 'stage_id' => [13, 'Solved'], 'user_id' => [8, 'منى الحربي'], 'priority' => '2',
            'partner_id' => [501, 'أحمد'], 'partner_name' => 'أحمد', 'partner_email' => 'ahmad@alwaha.example', 'ticket_type_id' => [21, 'Question'], 'ticket_ref' => '00101', 'description' => '<p>لم تصل <b>الشحنة</b></p>',
            'create_date' => '2026-08-01 08:00:00', 'create_uid' => [8, 'منى الحربي'], 'write_uid' => [7, 'Sales Person'], 'close_date' => '2026-08-25 15:00:00'], 'updated', false, '2026-08-25 15:00:00');
        $this->mirrorRecord('helpdesk.ticket', 102, ['name' => 'مشكلة فاتورة', 'active' => true, 'team_id' => [1, 'Customer Care'], 'stage_id' => [13, 'Solved'], 'user_id' => [8, 'منى الحربي'], 'priority' => '0', 'partner_id' => [70, 'Sales Person'],
            'ticket_ref' => '00102', 'create_date' => '2026-08-02 08:00:00', 'create_uid' => [9, 'Proc Person'], 'close_date' => '2026-08-05 12:00:00'], 'deleted', true);
        $this->mirrorRecord('helpdesk.stage', 14, ['name' => 'Waiting', 'active' => true, 'sequence' => 3, 'fold' => false, 'team_ids' => [1]]);
        $this->mirrorRecord('helpdesk.ticket', 106, ['name' => 'تذكرة وصلت من التغذية', 'active' => true, 'team_id' => [1, 'Customer Care'], 'stage_id' => [14, 'Waiting'], 'user_id' => [7, 'Sales Person'], 'priority' => '1', 'partner_id' => false,
            'ticket_ref' => '00106', 'create_date' => '2026-08-26 08:00:00', 'create_uid' => [7, 'Sales Person'], 'close_date' => false], 'created');
        $this->mirrorRecord('project.task', 301, ['name' => 'تركيب الرفوف', 'active' => true, 'project_id' => [201, 'تطوير المستودع'], 'stage_id' => [42, 'Done'], 'user_ids' => [7], 'tag_ids' => [], 'state' => '1_done', 'priority' => '1',
            'date_deadline' => '2026-10-20 00:00:00', 'description' => '<p>الممر أ</p>', 'date_end' => '2026-08-27 10:00:00', 'create_date' => '2026-08-06 08:00:00', 'create_uid' => [7, 'Sales Person'], 'personal_stage_type_ids' => []], 'updated', false, '2026-08-27 10:00:00');
        $this->mirrorRecord('ir.attachment', 7103, ['name' => 'contract-v3.pdf', 'res_model' => 'documents.document', 'res_id' => 401, 'mimetype' => 'application/pdf', 'file_size' => 90, 'type' => 'binary', 'res_field' => false]);
        $this->mirrorRecord('documents.document', 401, ['name' => 'عقد التوريد', 'active' => true, 'type' => 'binary', 'url' => false, 'folder_id' => [52, 'عقود'], 'owner_id' => [7, 'Sales Person'], 'attachment_id' => [7103, 'contract-v3.pdf'],
            'previous_attachment_ids' => [7100, 7101], 'tag_ids' => [], 'file_size' => 90, 'res_model' => 'documents.document', 'res_id' => 401, 'create_date' => '2026-07-15 08:00:00', 'create_uid' => [7, 'Sales Person']], 'updated', false, '2026-08-28 09:00:00');
        $this->mirrorRecord('documents.folder', 50, ['name' => 'فرعي قبل أبيه', 'active' => false, 'sequence' => 5, 'parent_folder_id' => [53, 'عام'], 'group_ids' => [], 'read_group_ids' => [], 'user_specific' => false, 'user_specific_write' => false], 'archived');
        // … and people wrote on the ticket: a new reply with a file, and the old note was deleted; a message of another document passes by
        $this->mirrorRecord('ir.attachment', 7004, ['name' => 'delivery.pdf', 'res_model' => 'helpdesk.ticket', 'res_id' => 101, 'mimetype' => 'application/pdf', 'file_size' => 500, 'type' => 'binary', 'res_field' => false]);
        $this->mirrorRecord('mail.message', 9010, ['model' => 'helpdesk.ticket', 'res_id' => 101, 'message_type' => 'comment', 'subtype_id' => [1, 'Discussions'], 'body' => '<p>وصلت الشحنة</p>', 'author_id' => [80, 'منى الحربي'],
            'date' => '2026-08-25 14:00:00', 'attachment_ids' => [7004]], 'created');
        $this->mirrorRecord('mail.message', 9002, ['model' => 'helpdesk.ticket', 'res_id' => 101, 'message_type' => 'comment', 'subtype_id' => [2, 'Note'], 'body' => '<p>المورد تأخر</p>', 'author_id' => [80, 'منى الحربي'],
            'date' => '2026-08-01 09:30:00', 'attachment_ids' => []], 'deleted', true);
        $this->mirrorRecord('mail.message', 9011, ['model' => 'sale.order', 'res_id' => 5, 'message_type' => 'comment', 'subtype_id' => [1, 'Discussions'], 'body' => '<p>ليس لنا</p>', 'author_id' => [70, 'Sales Person'], 'date' => '2026-08-25 14:00:00',
            'attachment_ids' => []], 'created');

        $projection = $this->projection();
        $this->assertSame(6, $projection->waiting());
        $out = $projection->consume();
        $this->assertSame(8, $out['changes'], 'six records of the section and two messages on a ticket (the message of a sale order is not counted)');
        foreach (['tickets.updated' => 1, 'tickets.archived' => 1, 'tickets.created' => 1, 'stages.created' => 1, 'tasks.updated' => 1, 'documents.updated' => 1, 'folders.archived' => 1, 'messages.created' => 1, 'messages.removed' => 1] as $what => $n) {
            $this->assertSame($n, $out['counts'][$what] ?? 0, $what);
        }
        $this->assertArrayNotHasKey('failed', $out);
        $this->assertSame([0, 0], [$projection->waiting(), OdooChange::whereIn('model', $projection->models())->whereNull('processed_at')->count()]);
        $this->assertGreaterThan($cursor, (int) State::read(OdooWorkspaceProjection::CURSOR));
        $this->assertSame(0, $projection->consume()['changes'], 'a change is applied once');

        $a = $this->ws('sales', "tickets/{$ticket->number}");
        $this->assertSame(['تأخر التوريد', 2, 'closed', 'Solved', 'name:منى الحربي', true], [$a['subject'], $a['priority'], $a['status'], $a['stage']['nameEn'], $a['assignee']['key'], $a['owned']], 'Odoo\'s values are back');
        $this->assertSame(Carbon::parse('2026-08-25 15:00:00', 'UTC')->timestamp, Carbon::parse($a['closedAt'])->timestamp);
        $events = array_values(array_filter(array_column($a['messages'], 'event')));
        $this->assertContains('closed', $events);
        $this->assertContains('assigned', $events);
        // the conversation followed: the new reply with its file is there, the deleted note is gone, what was written here stays
        $bodies = array_values(array_filter(array_column($a['messages'], 'body')));
        $this->assertContains('وصلت الشحنة', $bodies);
        $this->assertNotContains('المورد تأخر', $bodies);
        $this->assertContains('تواصلنا مع العميل من نظام العمليات', $bodies);
        $fresh = collect($a['messages'])->firstWhere('body', 'وصلت الشحنة');
        $this->assertSame(['reply', 'odoo', 'منى الحربي', ['delivery.pdf']], [$fresh['kind'], $fresh['origin'], $fresh['author']['nameAr'], array_column($fresh['files'], 'name')]);
        $this->assertSame(4, $a['messageCount'], 'two replies of Odoo, a reply and a note of OPS');
        $this->assertSame(['photo.jpg'], array_column($a['files'], 'name'), 'the file of the new reply hangs on the reply, not on the ticket as well');
        $this->assertNotNull(Message::where('external_ref', '9002')->value('removed_at'));
        $this->assertSame(0, Message::where('external_ref', '9011')->count(), 'a message of another document of Odoo is not this section\'s');

        // deleted in Odoo: archived here with its history — never removed
        $gone = $this->ops(Ticket::class, '102');
        $this->assertSame([true, 'closed', true], [$gone->archived, $gone->status, $gone->source_deleted_at !== null]);
        $this->assertContains('archived', array_column($this->ws('sales', "tickets/{$gone->number}")['messages'], 'event'));
        // a ticket that arrives with a stage the section had never seen brings the stage along
        $new = $this->ws('sales', 'tickets/'.$this->ops(Ticket::class, '106')->number);
        $this->assertSame(['تذكرة وصلت من التغذية', 'Waiting', 'sales', 'staff'], [$new['subject'], $new['stage']['nameEn'], $new['assignee']['username'], $new['requester']['kind']]);
        $this->assertSame([$this->ops(TicketTeam::class, '1')->id], $this->ops(TicketStage::class, '14')->teams()->pluck('ws_ticket_teams.id')->all());

        $task = $this->ws('sales', 'tasks/'.$this->ops(Task::class, '301')->number);
        $this->assertSame(['done', 'Done', ['sales'], [], 2], [$task['status'], $task['stage']['nameEn'], array_column($task['assignees'], 'username'), $task['tags'], $task['commentCount']]);
        $this->assertContains('done', array_column($task['comments'], 'event'));
        $doc = $this->ws('admin', 'documents/'.$this->ops(Document::class, '401')->number);
        $this->assertSame([3, [3, 2, 1], ['contract-v3.pdf', 'contract-v2.pdf', 'contract-v1.pdf'], 90, []], [$doc['version'], array_column($doc['versions'], 'versionNo'), array_column($doc['versions'], 'name'), $doc['size'], $doc['tags']],
            'the attachment Odoo put in force is the version in force; the earlier ones stay');
        $this->assertTrue($this->ops(Folder::class, '50')->archived);

        // a ticket whose team the copy does not hold is counted and left out; the one beside it is applied
        $this->mirrorRecord('helpdesk.ticket', 107, ['name' => str_repeat('س', 10), 'active' => true, 'team_id' => [999, 'No such team'], 'stage_id' => false, 'user_id' => false, 'priority' => '0', 'ticket_ref' => '00107'], 'created');
        $this->mirrorRecord('helpdesk.ticket', 108, ['name' => 'تذكرة سليمة', 'active' => true, 'team_id' => [1, 'Customer Care'], 'stage_id' => [11, 'New'], 'user_id' => false, 'priority' => '0', 'ticket_ref' => '00108', 'create_uid' => [7, 'Sales Person']], 'created');
        $last = $projection->consume();
        $this->assertSame([2, 1, 1], [$last['changes'], $last['counts']['tickets.skippedNoTeam'], $last['counts']['tickets.created']]);
        $this->assertSame('تذكرة سليمة', $this->ops(Ticket::class, '108')->subject);
    }
}
