<?php

namespace Tests\Feature\Workspace;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\StoredFile;
use App\Workspace\Models\Document;
use App\Workspace\Models\Folder;
use App\Workspace\Models\WsFile;
use Tests\ApiTestCase;

/**
 * Documents: the folders kept by the documents manager (tree, access by role, archive only when empty), who reads and
 * who writes a folder, a document from its upload through its versions, renaming, moving and archiving — never a
 * deletion —, links, tags, the link to an OPS record, the download through the permission-checked door, and the list
 * (search, filters, sort, paging).
 */
class WorkspaceDocumentsTest extends ApiTestCase
{
    use WorkspaceFixtures;

    public function test_folders_are_kept_by_the_documents_manager(): void
    {
        $before = Folder::count();
        // doc.manage keeps the tree: opening the section, or managing another tool, is not enough
        foreach (['wm', 'sales', 'gm'] as $who) {
            $this->expectRejected($this->postAs($who, '/api/workspace/folders', ['name' => 'محاولة']), 'FORBIDDEN', [403]);
            $this->expectRejected($this->postAs($who, '/api/workspace/doc-tags', ['name' => 'وسم']), 'FORBIDDEN', [403]);
        }
        $this->expectRejected($this->getAs('worker', '/api/workspace/folders'), 'FORBIDDEN', [403]);
        $this->assertSame($before, Folder::count());
        $this->expectRejected($this->postAs('admin', '/api/workspace/folders', ['description' => 'بلا اسم']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('admin', '/api/workspace/folders', ['name' => 'س', 'parentId' => 'nope']), 'WS_FOLDER_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('admin', '/api/workspace/folders', ['name' => 'س', 'readScope' => 'nobody']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('admin', '/api/workspace/folders', ['name' => 'س', 'readScope' => 'roles', 'readRoles' => ['no-such-role']]), 'WS_ROLE_UNKNOWN', [400]);

        $tag = self::tag();
        $root = $this->expectOk($this->postAs('admin', '/api/workspace/folders', ['name' => "العقود {$tag}", 'description' => 'عقود الموردين والعملاء', 'readScope' => 'roles', 'readRoles' => ['finance', 'gm'],
            'writeScope' => 'roles', 'writeRoles' => ['finance']]));
        $this->assertSame(['id', 'parentId', 'name', 'description', 'sequence', 'archived', 'readScope', 'readRoles', 'writeScope', 'writeRoles', 'ownRead', 'ownWrite', 'documentCount', 'canWrite', 'canManage',
            'parentHidden', 'extra', 'sourceSystem', 'externalRef', 'owned'], array_keys($root));
        $this->assertSame([null, 'roles', ['finance', 'gm'], 'roles', ['finance'], false, false, 0, true, true, false, false],
            [$root['parentId'], $root['readScope'], $root['readRoles'], $root['writeScope'], $root['writeRoles'], $root['ownRead'], $root['ownWrite'], $root['documentCount'], $root['canWrite'], $root['canManage'], $root['parentHidden'], $root['owned']]);
        // a folder made inside another starts with its access
        $child = $this->expectOk($this->postAs('admin', '/api/workspace/folders', ['name' => 'عقود 2026', 'parentId' => $root['id']]));
        $this->assertSame([$root['id'], 'roles', ['finance', 'gm'], 'roles', ['finance']], [$child['parentId'], $child['readScope'], $child['readRoles'], $child['writeScope'], $child['writeRoles']]);
        $grand = $this->expectOk($this->postAs('admin', '/api/workspace/folders', ['name' => 'الربع الأول', 'parentId' => $child['id'], 'readScope' => 'all']));
        $this->assertSame(['all', 'roles'], [$grand['readScope'], $grand['writeScope']]);
        // two live folders of one name do not stand side by side
        $this->expectRejected($this->postAs('admin', '/api/workspace/folders', ['name' => 'عقود 2026', 'parentId' => $root['id']]), 'WS_FOLDER_NAME_TAKEN', [409]);
        $this->expectRejected($this->postAs('admin', '/api/workspace/folders', ['name' => "العقود {$tag}"]), 'WS_FOLDER_NAME_TAKEN', [409]);

        // renamed, described, moved — never into itself or under its own children
        $this->expectRejected($this->patchAs('sales', "/api/workspace/folders/{$child['id']}", ['name' => 'تعديل']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->patchAs('admin', '/api/workspace/folders/nope', ['name' => 'تعديل']), 'WS_FOLDER_NOT_FOUND', [404]);
        $e = $this->expectOk($this->patchAs('admin', "/api/workspace/folders/{$child['id']}", ['name' => 'عقود سنة 2026', 'description' => 'السارية', 'ownRead' => true]));
        $this->assertSame(['عقود سنة 2026', 'السارية', true], [$e['name'], $e['description'], $e['ownRead']]);
        $this->assertSame(1, AuditLog::where('action', 'FOLDER.ACCESS')->where('entity_id', $child['id'])->count(), 'a change of who sees what is on the trail as such');
        $this->expectOk($this->patchAs('admin', "/api/workspace/folders/{$child['id']}", ['ownRead' => false]));
        $this->expectRejected($this->patchAs('admin', "/api/workspace/folders/{$root['id']}", ['parentId' => $root['id']]), 'WS_FOLDER_CYCLE', [422]);
        $this->expectRejected($this->patchAs('admin', "/api/workspace/folders/{$root['id']}", ['parentId' => $grand['id']]), 'WS_FOLDER_CYCLE', [422]);
        $moved = $this->expectOk($this->patchAs('admin', "/api/workspace/folders/{$grand['id']}", ['parentId' => null]));
        $this->assertNull($moved['parentId']);
        $this->assertSame($child['id'], $this->expectOk($this->patchAs('admin', "/api/workspace/folders/{$grand['id']}", ['parentId' => $child['id']]))['parentId']);

        // the tree is what the caller may read: finance and gm see the three, wm sees the one that is open to all — at its root
        $tree = fn (string $as, string $query = '') => collect($this->ws($as, "folders{$query}")['folders'])->keyBy('id');
        $this->assertTrue($tree('finance')->has($root['id']) && $tree('finance')->has($child['id']) && $tree('gm')->has($root['id']));
        $this->assertSame([true, false], [$tree('finance')[$root['id']]['canWrite'], $tree('gm')[$root['id']]['canWrite']]);
        $asWm = $tree('wm');
        $this->assertSame([false, false, true], [$asWm->has($root['id']), $asWm->has($child['id']), $asWm->has($grand['id'])]);
        $this->assertSame([true, false, false], [$asWm[$grand['id']]['parentHidden'], $asWm[$grand['id']]['canWrite'], $asWm[$grand['id']]['canManage']]);
        $this->assertFalse($tree('finance')[$grand['id']]['parentHidden']);

        // archived only once nothing alive is left in it; brought back when needed
        $this->expectOk($this->postAs('finance', '/api/workspace/documents', ['folderId' => $grand['id'], 'file' => self::pdfFile('q1.pdf')]));
        $this->expectRejected($this->postAs('admin', "/api/workspace/folders/{$child['id']}/archive"), 'WS_FOLDER_NOT_EMPTY', [422]);
        $this->expectRejected($this->postAs('admin', "/api/workspace/folders/{$grand['id']}/archive"), 'WS_FOLDER_NOT_EMPTY', [422]);
        $empty = $this->newFolder(['parentId' => $root['id']]);
        $this->expectRejected($this->postAs('finance', "/api/workspace/folders/{$empty['id']}/archive"), 'FORBIDDEN', [403]);
        $ar = $this->expectOk($this->postAs('admin', "/api/workspace/folders/{$empty['id']}/archive"));
        $this->assertSame([true, false], [$ar['archived'], $ar['canWrite']]);
        $this->assertFalse($tree('admin')->has($empty['id']), 'the tree shows the folders that are alive');
        $this->assertTrue($tree('admin', '?archived=1')->has($empty['id']));
        $this->expectRejected($this->postAs('finance', '/api/workspace/documents', ['folderId' => $empty['id'], 'file' => self::pdfFile()]), 'WS_ARCHIVED', [422]);
        $this->expectRejected($this->postAs('admin', '/api/workspace/folders', ['name' => 'داخل مؤرشف', 'parentId' => $empty['id']]), 'WS_ARCHIVED', [422]);
        $this->assertFalse($this->expectOk($this->postAs('admin', "/api/workspace/folders/{$empty['id']}/restore"))['archived']);
        $this->assertSame(1, Folder::where('id', $empty['id'])->count());
    }

    public function test_who_reads_and_who_writes_a_folder(): void
    {
        // open to read, written by finance only
        $shared = $this->newFolder(['writeScope' => 'roles', 'writeRoles' => ['finance']]);
        // read by finance and gm, written by nobody but the documents manager
        $closed = $this->newFolder(['readScope' => 'roles', 'readRoles' => ['finance', 'gm'], 'writeScope' => 'roles', 'writeRoles' => []]);
        // everybody writes, and everybody sees his own papers only
        $own = $this->newFolder(['ownRead' => true, 'ownWrite' => true]);

        // uploading follows the write access of the folder
        $this->expectRejected($this->postAs('wm', '/api/workspace/documents', ['folderId' => $shared['id'], 'file' => self::pdfFile()]), 'WS_FOLDER_FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('finance', '/api/workspace/documents', ['folderId' => $closed['id'], 'file' => self::pdfFile()]), 'WS_FOLDER_FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('wm', '/api/workspace/documents', ['folderId' => $closed['id'], 'file' => self::pdfFile()]), 'WS_FOLDER_FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('finance', '/api/workspace/documents', ['folderId' => 'nope', 'file' => self::pdfFile()]), 'WS_FOLDER_NOT_FOUND', [404]);
        $policy = $this->expectOk($this->postAs('finance', '/api/workspace/documents', ['folderId' => $shared['id'], 'name' => 'سياسة الائتمان', 'file' => self::pdfFile('policy.pdf')]));
        $secret = $this->expectOk($this->postAs('admin', '/api/workspace/documents', ['folderId' => $closed['id'], 'name' => 'ميزانية 2027', 'file' => self::pdfFile('budget.pdf')]));
        $this->assertSame([true, true, 'finance', false], [$policy['canWrite'], $policy['can']['edit'], $policy['owner']['username'], $policy['owned']]);

        // reading: wm reads the shared folder and downloads from it, and changes nothing there
        $asWm = $this->ws('wm', "documents/{$policy['number']}");
        $this->assertSame([false, ['edit' => false, 'move' => false, 'version' => false, 'archive' => false, 'restore' => false, 'download' => true]], [$asWm['canWrite'], $asWm['can']]);
        $this->getAs('wm', $asWm['file']['url'])->assertOk();
        foreach ([['PATCH', '', ['name' => 'تعديل']], ['POST', '/move', ['folderId' => $own['id']]], ['POST', '/versions', ['file' => self::pdfFile()]], ['POST', '/archive', []]] as [$method, $path, $body]) {
            $res = $method === 'PATCH' ? $this->patchAs('wm', "/api/workspace/documents/{$policy['id']}{$path}", $body) : $this->postAs('wm', "/api/workspace/documents/{$policy['id']}{$path}", $body);
            $this->expectRejected($res, 'WS_DOCUMENT_FORBIDDEN', [403]);
        }
        // the closed folder: not in wm's tree, its documents not in his lists, not by their number, not their files
        $this->expectRejected($this->getAs('wm', "/api/workspace/documents/{$secret['number']}"), 'WS_DOCUMENT_FORBIDDEN', [403]);
        $this->expectRejected($this->getAs('wm', $secret['file']['url']), 'WS_DOCUMENT_FORBIDDEN', [403]);
        $this->expectRejected($this->getAs('wm', "/api/workspace/documents?folder={$closed['id']}"), 'WS_FOLDER_FORBIDDEN', [403]);
        $this->assertNotContains($secret['number'], array_column($this->ws('wm', 'documents?pageSize=200&state=all')['items'], 'number'));
        $this->assertContains($policy['number'], array_column($this->ws('wm', 'documents?pageSize=200')['items'], 'number'));
        $this->assertGreaterThan(0, AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->where('username', 'wm')->where('new_value', 'denied: WS_DOCUMENT_FORBIDDEN')->count());
        // gm reads it and may not change it; finance too
        foreach (['gm', 'finance'] as $who) {
            $seen = $this->ws($who, "documents/{$secret['id']}");
            $this->assertSame([false, false, true], [$seen['canWrite'], $seen['can']['edit'], $seen['can']['download']], $who);
            $this->getAs($who, $seen['file']['url'])->assertOk();
            $this->assertSame([$secret['number']], array_column($this->ws($who, "documents?folder={$closed['id']}")['items'], 'number'));
        }
        // the documents manager names the roles: procurement now writes there
        $this->expectOk($this->patchAs('admin', "/api/workspace/folders/{$closed['id']}", ['writeRoles' => ['proc']]));
        $this->assertTrue($this->ws('proc', "documents/{$secret['id']}")['can']['edit'], 'writing includes reading');
        $this->expectOk($this->patchAs('proc', "/api/workspace/documents/{$secret['id']}", ['name' => 'ميزانية 2027 — مسودة']));

        // "own only": each sees and changes the papers he owns; the documents manager sees all
        $mine = $this->expectOk($this->postAs('wm', '/api/workspace/documents', ['folderId' => $own['id'], 'file' => self::pdfFile('wm.pdf')]));
        $theirs = $this->expectOk($this->postAs('inv', '/api/workspace/documents', ['folderId' => $own['id'], 'file' => self::pdfFile('inv.pdf')]));
        $in = fn (string $as) => array_column($this->ws($as, "documents?folder={$own['id']}&sort=number&order=asc")['items'], 'number');
        $this->assertSame([[$mine['number']], [$theirs['number']], [$mine['number'], $theirs['number']]], [$in('wm'), $in('inv'), $in('admin')]);
        $this->expectRejected($this->getAs('wm', "/api/workspace/documents/{$theirs['id']}"), 'WS_DOCUMENT_FORBIDDEN', [403]);
        $this->expectRejected($this->getAs('wm', $theirs['file']['url']), 'WS_DOCUMENT_FORBIDDEN', [403]);
        $counts = fn (string $as) => collect($this->ws($as, 'folders')['folders'])->firstWhere('id', $own['id'])['documentCount'];
        $this->assertSame([1, 1, 2], [$counts('wm'), $counts('inv'), $counts('admin')], 'the tree counts what the caller sees');
        $this->assertTrue($this->expectOk($this->patchAs('admin', "/api/workspace/documents/{$theirs['id']}", ['description' => 'راجعها مدير المستندات']))['can']['edit']);
    }

    public function test_a_document_through_its_versions_names_folders_and_archive(): void
    {
        $folder = $this->newFolder();
        $elsewhere = $this->newFolder();
        $customer = Customer::where('active', true)->orderBy('code')->firstOrFail();
        $tag = $this->expectOk($this->postAs('admin', '/api/workspace/doc-tags', ['name' => 'ساري '.self::tag(), 'group' => 'الحالة', 'color' => 10]));
        $this->assertSame(['id', 'name', 'group', 'color', 'folderId', 'sequence', 'active', 'sourceSystem', 'externalRef', 'owned'], array_keys($tag));
        $this->expectRejected($this->postAs('admin', '/api/workspace/doc-tags', ['name' => $tag['name'], 'group' => 'الحالة']), 'WS_TAG_EXISTS', [409]);
        $this->assertContains($tag['id'], array_column($this->ws('wm', 'documents/lookups')['tags'], 'id'));

        // content: a file or a link, one of the two; the type is read from the bytes
        $post = fn (array $body, string $as = 'wm') => $this->postAs($as, '/api/workspace/documents', $body + ['folderId' => $folder['id']]);
        $this->expectRejected($post([]), 'WS_DOCUMENT_CONTENT_REQUIRED', [400]);
        $this->expectRejected($post(['file' => self::pdfFile(), 'url' => 'https://example.com/a']), 'WS_DOCUMENT_CONTENT_REQUIRED', [400]);
        $this->expectRejected($post(['file' => ['fileName' => 'x.pdf']]), 'INVALID_INPUT', [400]);
        $this->expectRejected($post(['file' => ['fileName' => 'setup.exe', 'base64' => base64_encode("MZ\x90\x00\x03")]]), 'WS_FILE_TYPE', [400]);
        $this->expectRejected($post(['url' => 'javascript:alert(1)']), 'WS_URL_INVALID', [400]);
        $this->expectRejected($post(['url' => 'ftp://files.example/x']), 'WS_URL_INVALID', [400]);
        $this->expectRejected($post(['file' => self::pdfFile(), 'tagIds' => ['nope']]), 'WS_TAG_UNKNOWN', [400]);
        $this->expectRejected($post(['file' => self::pdfFile(), 'refType' => 'customer', 'refNumber' => 'NO-SUCH']), 'WS_RECORD_NOT_FOUND', [400]);
        $this->assertSame(0, Document::where('folder_id', $folder['id'])->count(), 'a refused upload leaves nothing');

        $d = $this->expectOk($post(['file' => self::pdfFile('عقد توريد.pdf', 'v1'), 'description' => 'عقد سنوي', 'tagIds' => [$tag['id']], 'refType' => 'customer', 'refNumber' => $customer->code]));
        $this->assertMatchesRegularExpression('/^DOC-\d{4}-\d{5}$/', $d['number']);
        $this->assertSame(['عقد توريد.pdf', 'file', null, $folder['id'], 'wm', 'application/pdf', 1, [$tag['name']], $customer->code, false, [['id' => $folder['id'], 'name' => $folder['name']]]],
            [$d['name'], $d['kind'], $d['url'], $d['folder']['id'], $d['owner']['username'], $d['mime'], $d['version'], array_column($d['tags'], 'name'), $d['ref']['number'], $d['archived'], $d['path']]);
        $this->assertSame([1, null, true, false, false], [$d['file']['versionNo'], $d['file']['note'], $d['file']['available'], $d['file']['fromOdoo'], $d['file']['removable']]);
        $this->assertSame(['edit' => true, 'move' => true, 'version' => true, 'archive' => true, 'restore' => false, 'download' => true], $d['can']);
        $this->assertCount(1, $d['versions']);
        $this->assertSame('wm', AuditLog::where('action', 'DOC.CREATE')->where('entity_id', $d['id'])->value('username'));
        $url = "/api/workspace/documents/{$d['number']}";
        // the bytes are kept by the file store of the system, under a key that never leaves the server
        $row = WsFile::findOrFail($d['file']['id']);
        $this->assertSame(['ops', 'stored', 1], [$row->storage, $row->storage_status, StoredFile::where('key', $row->storage_key)->count()]);
        $this->assertArrayNotHasKey('storageKey', $d['file']);
        $v1 = $this->getAs('wm', $d['file']['url']);
        $v1->assertOk();
        $this->assertStringContainsString('% v1', (string) $v1->getContent());
        $this->assertSame('application/pdf', $v1->headers->get('Content-Type'));

        // a new upload becomes the version in force; the old one stays and is still downloaded
        $this->expectRejected($this->postAs('wm', "{$url}/versions", []), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('wm', "{$url}/versions", ['file' => ['fileName' => 'x.exe', 'base64' => base64_encode("MZ\x90\x00")]]), 'WS_FILE_TYPE', [400]);
        $two = $this->expectOk($this->postAs('wm', "{$url}/versions", ['file' => self::officeFile('عقد توريد معدل.docx'), 'note' => 'بعد ملاحظات المورد']));
        $this->assertSame([2, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 2, 'بعد ملاحظات المورد', [2, 1]],
            [$two['version'], $two['mime'], $two['file']['versionNo'], $two['file']['note'], array_column($two['versions'], 'versionNo')]);
        $this->assertSame([$d['file']['id'], 'عقد توريد.docx'], [$two['versions'][1]['id'], $two['name']], 'a name that ended with the old type follows the new content');
        $old = $this->getAs('wm', $two['versions'][1]['url']);
        $this->assertStringContainsString('% v1', (string) $old->getContent());
        $this->assertStringContainsString(rawurlencode('عقد توريد (v1).pdf'), (string) $old->headers->get('Content-Disposition'), 'an older version says which one it is');
        $this->assertStringContainsString(rawurlencode('عقد توريد.docx'), (string) $this->getAs('wm', $two['file']['url'])->headers->get('Content-Disposition'), 'the version in force carries the name of the document');
        // a version is never removed
        $this->expectRejected($this->postAs('admin', "/api/workspace/files/{$d['file']['id']}/remove"), 'WS_FILE_IS_VERSION', [422]);
        $this->assertSame(2, WsFile::where('owner_type', 'document')->where('owner_id', $d['id'])->whereNull('removed_at')->count());

        // renamed, described, tagged, linked — what was sent empty is cleared
        $e = $this->expectOk($this->patchAs('wm', $url, ['name' => 'عقد التوريد السنوي 2027', 'description' => null, 'tagIds' => [], 'refType' => null, 'refNumber' => null]));
        $this->assertSame(['عقد التوريد السنوي 2027', null, [], null], [$e['name'], $e['description'], $e['tags'], $e['ref']]);
        $this->assertSame(1, AuditLog::where('action', 'DOC.UPDATE')->where('entity_id', $d['id'])->count());
        $this->expectOk($this->patchAs('wm', $url, ['name' => 'عقد التوريد السنوي 2027']));
        $this->assertSame(1, AuditLog::where('action', 'DOC.UPDATE')->where('entity_id', $d['id'])->count(), 'the same edit again writes nothing');
        $this->expectRejected($this->patchAs('wm', $url, ['name' => '']), 'INVALID_INPUT', [400]);

        // moved to another folder the caller may write
        $locked = $this->newFolder(['writeScope' => 'roles', 'writeRoles' => ['finance']]);
        $this->expectRejected($this->postAs('wm', "{$url}/move", ['folderId' => $locked['id']]), 'WS_FOLDER_FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('wm', "{$url}/move", ['folderId' => 'nope']), 'WS_FOLDER_NOT_FOUND', [404]);
        $m = $this->expectOk($this->postAs('wm', "{$url}/move", ['folderId' => $elsewhere['id']]));
        $this->assertSame([$elsewhere['id'], 1], [$m['folder']['id'], AuditLog::where('action', 'DOC.MOVE')->where('entity_id', $d['id'])->count()]);

        // archived and restored — never deleted
        $ar = $this->expectOk($this->postAs('wm', "{$url}/archive", ['reason' => 'انتهى العقد']));
        $this->assertSame([true, 'انتهى العقد', 'wm', false, true], [$ar['archived'], $ar['archiveReason'], $ar['archivedBy'], $ar['can']['edit'], $ar['can']['restore']]);
        $this->expectRejected($this->patchAs('wm', $url, ['name' => 'تعديل']), 'WS_ARCHIVED', [422]);
        $this->expectRejected($this->postAs('wm', "{$url}/versions", ['file' => self::pdfFile()]), 'WS_ARCHIVED', [422]);
        $this->expectRejected($this->postAs('wm', "{$url}/move", ['folderId' => $folder['id']]), 'WS_ARCHIVED', [422]);
        $this->assertSame([], $this->ws('wm', "documents?folder={$elsewhere['id']}")['items']);
        $this->assertSame([$d['number']], array_column($this->ws('wm', "documents?folder={$elsewhere['id']}&state=archived")['items'], 'number'));
        $this->assertSame([false, 1], [$this->expectOk($this->postAs('wm', "{$url}/restore"))['archived'], Document::where('id', $d['id'])->count()]);

        // a link: an address people open; it takes no versions
        $l = $this->expectOk($post(['url' => 'https://portal.example.sa/الأنظمة/اللائحة', 'name' => 'لائحة النقل']));
        $this->assertSame(['link', 'https://portal.example.sa/الأنظمة/اللائحة', null, 0, false, false], [$l['kind'], $l['url'], $l['file'], $l['version'], $l['can']['version'], $l['can']['download']]);
        $this->expectRejected($this->postAs('wm', "/api/workspace/documents/{$l['id']}/versions", ['file' => self::pdfFile()]), 'WS_DOCUMENT_IS_LINK', [422]);
        $this->assertSame('https://portal.example.sa/v2', $this->expectOk($this->patchAs('wm', "/api/workspace/documents/{$l['id']}", ['url' => 'https://portal.example.sa/v2']))['url']);
        $this->expectRejected($this->patchAs('wm', "/api/workspace/documents/{$l['id']}", ['url' => 'not a link']), 'WS_URL_INVALID', [400]);
        $this->expectRejected($this->getAs('wm', '/api/workspace/documents/DOC-NOPE'), 'WS_DOCUMENT_NOT_FOUND', [404]);
    }

    public function test_the_list_searches_filters_sorts_and_pages(): void
    {
        $root = $this->newFolder();
        $sub = $this->newFolder(['parentId' => $root['id']]);
        $customer = Customer::where('active', true)->orderBy('code')->firstOrFail();
        $tag = $this->expectOk($this->postAs('admin', '/api/workspace/doc-tags', ['name' => 'فاتورة '.self::tag(), 'folderId' => $root['id']]));
        $key = 'فيروز'.self::uid();
        $put = fn (array $body, string $folder, string $as = 'wm') => $this->expectOk($this->postAs($as, '/api/workspace/documents', $body + ['folderId' => $folder]));
        $a = $put(['name' => "{$key} كشف حساب", 'file' => self::pdfFile('a.pdf'), 'tagIds' => [$tag['id']], 'refType' => 'customer', 'refNumber' => $customer->code], $root['id']);
        $b = $put(['name' => "{$key} صورة الشحنة", 'file' => self::pngFile('b.png')], $root['id'], 'inv');
        $c = $put(['name' => "{$key} جدول الأسعار", 'file' => self::officeFile('c.xlsx'), 'description' => 'أسعار الربع القادم'], $sub['id']);
        $d = $put(['name' => "{$key} خطاب المورد", 'file' => self::officeFile('d.docx')], $sub['id']);
        $e = $put(['name' => "{$key} بوابة المورد", 'url' => 'https://supplier.example/portal'], $root['id']);
        $f = $put(['name' => "{$key} مستند قديم", 'file' => self::pdfFile('f.pdf')], $root['id']);
        $this->expectOk($this->postAs('wm', "/api/workspace/documents/{$f['id']}/archive"));

        $list = fn (string $query, string $as = 'wm') => $this->ws($as, "documents?{$query}");
        $numbers = fn (array $page) => array_column($page['items'], 'number');
        $here = $list("folder={$root['id']}&sort=number&order=asc");
        $this->assertSame(['items', 'total', 'page', 'pageSize', 'pages'], array_keys($here));
        $this->assertSame([$a['number'], $b['number'], $e['number']], $numbers($here), 'the documents of the folder itself, alive ones');
        $row = $here['items'][0];
        $this->assertSame(['id', 'number', 'name', 'kind', 'url', 'folder', 'owner', 'tags', 'mime', 'size', 'version', 'file', 'ref', 'archived', 'createdAt', 'updatedAt', 'createdBy', 'canWrite', 'sourceSystem', 'externalRef', 'owned'], array_keys($row));
        $this->assertSame([$a['number'], $b['number'], $c['number'], $d['number'], $e['number']], $numbers($list("folder={$root['id']}&deep=1&sort=number&order=asc")), 'with what stands under it');
        $this->assertSame([$c['number'], $d['number']], $numbers($list("folder={$sub['id']}&sort=number&order=asc")));
        $this->assertSame([$f['number']], $numbers($list("folder={$root['id']}&state=archived")));
        $this->assertCount(4, $list("folder={$root['id']}&state=all")['items']);
        // across every folder the caller reads
        $all = 'q='.urlencode($key).'&sort=number&order=asc';
        $this->assertSame([$a['number'], $b['number'], $c['number'], $d['number'], $e['number']], $numbers($list($all)));
        $this->assertSame([$a['number']], $numbers($list("{$all}&tag={$tag['id']}")));
        $this->assertSame([$a['number'], $c['number'], $d['number'], $e['number']], $numbers($list("{$all}&owner=me")));
        $this->assertSame([$b['number']], $numbers($list("{$all}&owner=".$this->userId('inv'))));
        $this->assertSame([$e['number']], $numbers($list("{$all}&kind=link")));
        $this->assertSame([$a['number'], $b['number'], $c['number'], $d['number']], $numbers($list("{$all}&kind=file")));
        $this->assertSame([$a['number']], $numbers($list("{$all}&type=pdf")));
        $this->assertSame([$b['number']], $numbers($list("{$all}&type=image")));
        $this->assertSame([$c['number']], $numbers($list("{$all}&type=sheet")));
        $this->assertSame([$d['number']], $numbers($list("{$all}&type=doc")));
        $this->assertSame([], $numbers($list("{$all}&type=other")));
        $this->assertSame([$a['number']], $numbers($list('refType=customer&refNumber='.urlencode($customer->code).'&q='.urlencode($key))), 'the papers of one record of OPS');
        $this->assertSame([$c['number']], $numbers($list('q='.urlencode('الربع القادم'))), 'the description is searched too');
        $this->assertSame([$d['number']], $numbers($list("q={$d['number']}")));
        $this->assertSame([], $numbers($list('q=zzz-no-such-document')));
        $this->assertSame([$a['number'], $b['number'], $c['number'], $d['number'], $e['number']], $numbers($list("{$all}&origin=ops")));
        // sort and paging
        $this->assertSame([$e['number'], $c['number'], $d['number'], $b['number'], $a['number']], $numbers($list('q='.urlencode($key).'&sort=name&order=asc')));
        $bySize = $numbers($list("{$all}&kind=file&sort=size&order=desc"));
        $this->assertSame($a['number'], $bySize[0], 'the largest first');
        $this->assertSame([$e['number'], $d['number']], $numbers($list('q='.urlencode($key).'&sort=createdAt&order=desc&pageSize=2')));
        $page = $list("{$all}&pageSize=2&page=3");
        $this->assertSame([5, 3, 3, [$e['number']]], [$page['total'], $page['page'], $page['pages'], $numbers($page)]);
        $this->expectRejected($this->getAs('wm', '/api/workspace/documents?state=sometimes'), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->getAs('wm', '/api/workspace/documents?type=video'), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->getAs('wm', '/api/workspace/documents?folder=nope'), 'WS_FOLDER_NOT_FOUND', [404]);

        // the tree counts the live documents of each folder; the top of the page counts what the caller reads
        $folders = collect($this->ws('wm', 'folders')['folders'])->keyBy('id');
        $this->assertSame([3, 2, $root['id']], [$folders[$root['id']]['documentCount'], $folders[$sub['id']]['documentCount'], $folders[$sub['id']]['parentId']]);
        $s = $this->ws('wm', 'summary')['documents'];
        $this->assertSame(['total', 'folders'], array_keys($s));
        $this->assertGreaterThanOrEqual(5, $s['total']);
        $this->assertGreaterThanOrEqual(2, $s['folders']);

        // the tags are kept by the documents manager
        $t = $this->expectOk($this->patchAs('admin', "/api/workspace/doc-tags/{$tag['id']}", ['group' => 'نوع الورقة', 'color' => 3, 'active' => false, 'folderId' => null]));
        $this->assertSame(['نوع الورقة', 3, false, null], [$t['group'], $t['color'], $t['active'], $t['folderId']]);
        $this->expectRejected($this->patchAs('wm', "/api/workspace/doc-tags/{$tag['id']}", ['name' => 'س']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->patchAs('admin', '/api/workspace/doc-tags/nope', ['name' => 'س']), 'WS_TAG_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('admin', '/api/workspace/doc-tags', ['name' => 'س', 'folderId' => 'nope']), 'WS_FOLDER_NOT_FOUND', [404]);
    }
}
