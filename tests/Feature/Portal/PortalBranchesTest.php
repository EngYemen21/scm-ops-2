<?php

namespace Tests\Feature\Portal;

use App\Models\AuditLog;
use App\Portal\Models\PortalBranch;
use App\Portal\Models\PortalEvent;
use Tests\ApiTestCase;

/**
 * Customer branches: who may read and who may change, entering and editing a branch, the rules of a delivery window,
 * archive / restore, the list — and what stays Odoo's on a branch that came from Odoo.
 */
class PortalBranchesTest extends ApiTestCase
{
    use PortalFixtures;

    public function test_reading_needs_page_portal_and_changing_needs_portal_manage(): void
    {
        $customer = $this->makeCustomer();
        // the warehouse manager does not open the page at all
        $this->expectRejected($this->getAs('wm', '/api/portal/summary'), 'FORBIDDEN', [403]);
        $this->expectRejected($this->getAs('wm', '/api/portal/branches'), 'FORBIDDEN', [403]);
        // the general manager reads it and changes nothing
        $this->expectOk($this->getAs('gm', '/api/portal/branches'));
        $before = PortalBranch::count();
        $this->expectRejected($this->postAs('gm', '/api/portal/branches', ['customerId' => $customer->code, 'name' => 'فرع ممنوع']), 'FORBIDDEN', [403]);
        $this->assertSame($before, PortalBranch::count(), 'a refused request changes nothing');
        $this->assertTrue(AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->where('username', 'gm')->where('entity_id', 'like', '%portal/branches%')->exists(), 'the refusal is on the audit trail');

        $summary = $this->expectOk($this->getAs('gm', '/api/portal/summary'));
        $this->assertSame(['following', 'branches', 'favorites', 'applications', 'requests', 'lineChanges', 'statements'], array_keys($summary));
        $this->assertSame(['total', 'active', 'unlinked'], array_keys($summary['branches']));
    }

    public function test_a_branch_is_entered_edited_and_audited(): void
    {
        $customer = $this->makeCustomer();
        $site = $this->makeSite($customer);
        $b = $this->expectOk($this->postAs('sales', '/api/portal/branches', [
            'customerId' => $customer->code, 'name' => '  فرع العليا  ', 'code' => 'OLY-1', 'contactName' => 'مدير الفرع', 'phone' => '0551234567', 'city' => 'الرياض', 'district' => 'العليا',
            'address' => 'شارع التحلية', 'windowFrom' => '08:00', 'windowTo' => '11:30', 'deliveryDays' => ['sun', 'tue', 'thu'], 'siteId' => $site->id, 'lat' => 24.7, 'lng' => 46.68,
        ]));
        $this->assertSame(['فرع العليا', 'OLY-1', '08:00–11:30', ['sun', 'tue', 'thu'], true, true, null, false],
            [$b['name'], $b['code'], $b['window'], $b['deliveryDays'], $b['active'], $b['located'], $b['sourceSystem'], $b['owned']]);
        $this->assertSame([$customer->code, true], [$b['customer']['code'], $b['customer']['linked']]);
        $this->assertSame([$site->id, 'موقع الفرع'], [$b['site']['id'], $b['site']['name']]);
        $this->assertSame([$site->id], array_column($b['sites'], 'id'), 'the customer\'s delivery sites are offered to the picker');
        $this->assertSame(['edit' => true, 'editIdentity' => true, 'archive' => true, 'restore' => false], $b['can']);
        $this->assertSame(['created'], array_column($b['events'], 'kind'));
        $this->assertTrue(AuditLog::where('action', 'PORTAL.BRANCH.CREATE')->where('entity_id', $b['id'])->where('username', 'sales')->exists());

        // an edit: an absent key is left alone, an empty one clears
        $e = $this->expectOk($this->patchAs('sales', "/api/portal/branches/{$b['id']}", ['phone' => '0559999999', 'district' => null, 'deliveryDays' => ['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri']]));
        $this->assertSame(['0559999999', null, null, 'مدير الفرع'], [$e['phone'], $e['district'], $e['deliveryDays'], $e['contactName']], 'every day of the week is no restriction');
        $this->assertSame(['phone', 'district', 'deliveryDays'], $e['events'][0]['data']['fields']);
        $this->assertTrue(AuditLog::where('action', 'PORTAL.BRANCH.UPDATE')->where('entity_id', $b['id'])->exists());
        // sending what it already holds changes nothing and writes nothing
        $events = PortalEvent::where('entity_id', $b['id'])->count();
        $this->expectOk($this->patchAs('sales', "/api/portal/branches/{$b['id']}", ['phone' => '0559999999', 'name' => 'فرع العليا']));
        $this->assertSame($events, PortalEvent::where('entity_id', $b['id'])->count());

        // the rules
        $this->expectRejected($this->postAs('sales', '/api/portal/branches', ['customerId' => $customer->code, 'name' => 'فرع العليا']), 'PORTAL_BRANCH_EXISTS', [422]);
        $this->expectRejected($this->postAs('sales', '/api/portal/branches', ['customerId' => 'CUS-NOPE', 'name' => 'فرع']), 'CUSTOMER_NOT_FOUND', [400]);
        $this->expectRejected($this->postAs('sales', '/api/portal/branches', ['customerId' => $customer->code, 'name' => 'فرع النافذة', 'windowFrom' => '09:00']), 'PORTAL_WINDOW', [400]);
        $this->expectRejected($this->patchAs('sales', "/api/portal/branches/{$b['id']}", ['windowFrom' => '12:00']), 'PORTAL_WINDOW', [400]);
        $this->expectRejected($this->patchAs('sales', "/api/portal/branches/{$b['id']}", ['windowFrom' => '25:00']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->patchAs('sales', "/api/portal/branches/{$b['id']}", ['lat' => 24.7, 'lng' => null]), 'PORTAL_COORDINATES', [400]);
        $other = $this->makeCustomer();
        $this->expectRejected($this->patchAs('sales', "/api/portal/branches/{$b['id']}", ['siteId' => $this->makeSite($other)->id]), 'PORTAL_SITE_UNKNOWN', [400]);
        $this->expectRejected($this->patchAs('sales', "/api/portal/branches/{$b['id']}", ['customerId' => $other->code]), 'PORTAL_BRANCH_CUSTOMER_FIXED', [422]);
        $inactive = $this->makeCustomer(['active' => false]);
        $this->expectRejected($this->postAs('sales', '/api/portal/branches', ['customerId' => $inactive->code, 'name' => 'فرع']), 'CUSTOMER_INACTIVE', [422]);
        $this->expectRejected($this->getAs('sales', '/api/portal/branches/01NOSUCHBRANCH0000000000000'), 'PORTAL_BRANCH_NOT_FOUND', [404]);
    }

    public function test_archive_and_restore_never_delete(): void
    {
        $customer = $this->makeCustomer();
        // the figures of the page head move with it
        $head = fn () => $this->expectOk($this->getAs('gm', '/api/portal/summary'))['branches'];
        $zero = $head();
        $b = $this->expectOk($this->postAs('sales', '/api/portal/branches', ['customerId' => $customer->id, 'name' => 'فرع الملز']));
        $this->assertSame(['total' => $zero['total'] + 1, 'active' => $zero['active'] + 1, 'unlinked' => $zero['unlinked']], $head());
        $a = $this->expectOk($this->postAs('sales', "/api/portal/branches/{$b['id']}/archive", ['reason' => 'أُغلق الفرع']));
        $this->assertSame(['total' => $zero['total'] + 1, 'active' => $zero['active'], 'unlinked' => $zero['unlinked']], $head(), 'an archived branch is still held, and no longer counted as live');
        $this->assertSame([false, 'أُغلق الفرع', 'sales'], [$a['active'], $a['archiveReason'], $a['archivedBy']]);
        $this->assertSame(['edit' => true, 'editIdentity' => true, 'archive' => false, 'restore' => true], $a['can']);
        $this->expectRejected($this->postAs('sales', "/api/portal/branches/{$b['id']}/archive"), 'PORTAL_BRANCH_ARCHIVED', [422]);
        $this->assertNotNull(PortalBranch::find($b['id']), 'archived, not deleted');

        // the name is free again while it is archived — and taken again when another branch carries it
        $twin = $this->expectOk($this->postAs('sales', '/api/portal/branches', ['customerId' => $customer->id, 'name' => 'فرع الملز']));
        $this->expectRejected($this->postAs('sales', "/api/portal/branches/{$b['id']}/restore"), 'PORTAL_BRANCH_EXISTS', [422]);
        $this->expectOk($this->postAs('sales', "/api/portal/branches/{$twin['id']}/archive"));
        $r = $this->expectOk($this->postAs('sales', "/api/portal/branches/{$b['id']}/restore")); // action button: no body
        $this->assertSame([true, null], [$r['active'], $r['archivedAt']]);
        $this->expectRejected($this->postAs('sales', "/api/portal/branches/{$b['id']}/restore"), 'PORTAL_BRANCH_ACTIVE', [422]);
        $this->assertSame(['restored', 'archived', 'created'], array_column($r['events'], 'kind'));
    }

    public function test_the_list_searches_filters_sorts_and_pages(): void
    {
        $tag = self::tag();
        $customer = $this->makeCustomer(['name_ar' => "مطاعم الواحة {$tag}"]);
        $site = $this->makeSite($customer);
        $ids = [];
        foreach ([['ألف', 'جدة', 30], ['باء', 'الرياض', 10], ['جيم', null, 20]] as [$name, $city, $sequence]) {
            $ids[$name] = $this->expectOk($this->postAs('sales', '/api/portal/branches', ['customerId' => $customer->code, 'name' => "فرع {$name} {$tag}", 'city' => $city, 'sequence' => $sequence]
                + ($name === 'ألف' ? ['siteId' => $site->id] : [])))['id'];
        }
        $this->expectOk($this->postAs('sales', "/api/portal/branches/{$ids['جيم']}/archive"));
        $list = fn (string $query) => $this->expectOk($this->getAs('gm', '/api/portal/branches?'.$query));

        $all = $list("customer={$customer->code}&state=all&sort=sequence&order=asc");
        $this->assertSame([3, 1, 25, 1], [$all['total'], $all['page'], $all['pageSize'], $all['pages']]);
        $this->assertSame([$ids['باء'], $ids['جيم'], $ids['ألف']], array_column($all['items'], 'id'));
        $this->assertSame(2, $list("customer={$customer->code}")['total'], 'archived branches are left out unless asked for');
        $this->assertSame([$ids['جيم']], array_column($list("customer={$customer->code}&state=archived")['items'], 'id'));
        $this->assertSame([$ids['ألف']], array_column($list("customer={$customer->code}&site=yes")['items'], 'id'));
        $this->assertSame([$ids['باء']], array_column($list("customer={$customer->code}&site=no")['items'], 'id'));
        $this->assertSame(0, $list("customer={$customer->code}&origin=odoo")['total']);
        $this->assertSame(2, $list("customer={$customer->code}&origin=ops&linked=yes")['total']);
        $this->assertSame(0, $list('customer=CUS-NOBODY')['total'], 'a customer nobody answers to matches nothing');
        // search: by the branch, by its city, by its customer's name
        $this->assertSame([$ids['باء']], array_column($list('q='.urlencode("فرع باء {$tag}"))['items'], 'id'));
        $this->assertSame(2, $list('q='.urlencode("الواحة {$tag}"))['total']);
        $this->assertSame([$ids['ألف']], array_column($list("customer={$customer->code}&q=".urlencode('جدة'))['items'], 'id'));
        // a city nobody typed comes last whichever way the list is sorted
        $this->assertSame([$ids['ألف'], $ids['باء'], $ids['جيم']], array_column($list("customer={$customer->code}&state=all&sort=city&order=desc")['items'], 'id'));
        // paging
        $page2 = $list("customer={$customer->code}&state=all&sort=sequence&order=asc&pageSize=2&page=2");
        $this->assertSame([[$ids['ألف']], 3, 2], [array_column($page2['items'], 'id'), $page2['total'], $page2['pages']]);
        $this->expectRejected($this->getAs('gm', '/api/portal/branches?state=nonsense'), 'INVALID_INPUT', [400]);
    }

    public function test_what_odoo_owns_on_a_branch_is_refused_and_what_ops_adds_is_saved(): void
    {
        $customer = $this->makeCustomer();
        $branch = PortalBranch::create(['customer_id' => $customer->id, 'customer_ref' => '700', 'customer_name' => 'شركة من أودو', 'name' => 'فرع أودو '.self::tag(), 'sequence' => 10, 'active' => true,
            'source_system' => 'odoo', 'external_ref' => (string) random_int(100000, 999999), 'portal_users' => [['ref' => '9', 'name' => 'مسؤول الطلبات', 'role' => 'operations']]]);

        $this->followOdoo(true);
        $shown = $this->expectOk($this->getAs('sales', "/api/portal/branches/{$branch->id}"));
        $this->assertSame([true, 'odoo', 1], [$shown['owned'], $shown['sourceSystem'], $shown['usersCount']]);
        $this->assertSame(['edit' => true, 'editIdentity' => false, 'archive' => false, 'restore' => false], $shown['can']);
        // the name is Odoo's …
        $refused = $this->expectRejected($this->patchAs('sales', "/api/portal/branches/{$branch->id}", ['name' => 'اسم آخر', 'phone' => '0550000001']), 'PORTAL_OWNED_BY_SOURCE', [422]);
        $this->assertSame(['name'], $refused['details']['fields']);
        $this->assertNull($branch->fresh()->phone, 'a refused request saves nothing of itself');
        $this->expectRejected($this->postAs('sales', "/api/portal/branches/{$branch->id}/archive"), 'PORTAL_OWNED_BY_SOURCE', [422]);
        // … the address, the contact and the delivery window are OPS's own
        $saved = $this->expectOk($this->patchAs('sales', "/api/portal/branches/{$branch->id}", ['name' => $branch->name, 'phone' => '0550000001', 'windowFrom' => '07:00', 'windowTo' => '10:00', 'notes' => 'البوابة الخلفية']));
        $this->assertSame(['0550000001', '07:00–10:00', 'البوابة الخلفية'], [$saved['phone'], $saved['window'], $saved['notes']]);

        // handed over: everything is edited here
        $this->followOdoo(false);
        $renamed = $this->expectOk($this->patchAs('sales', "/api/portal/branches/{$branch->id}", ['name' => 'فرع بعد التسليم '.self::tag()]));
        $this->assertSame([false, true], [$renamed['owned'], $renamed['can']['archive']]);
    }
}
