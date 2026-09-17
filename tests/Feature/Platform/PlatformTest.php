<?php

namespace Tests\Feature\Platform;

use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\SystemSetting;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\SettingsService;
use Tests\ApiTestCase;

/** Activity feed, audit trail, notifications, settings and global search. */
class PlatformTest extends ApiTestCase
{
    // ───────────── activity & audit ─────────────
    public function test_activity_feed_is_paged_and_filtered(): void
    {
        $tag = 'ACT'.self::uid();
        $notify = app(NotifyService::class);
        foreach ([1, 2, 3] as $i) {
            $notify->activity(null, 'Trip', "id-{$tag}-{$i}", "{$tag}-{$i}", "نشاط {$tag} رقم {$i}", "Activity {$tag} #{$i}");
        }
        $notify->activity(null, 'SalesOrder', null, "{$tag}-SO", "طلب {$tag}");

        $page = $this->expectOk($this->getAs('sales', "/api/activity?q={$tag}&pageSize=2"));
        $this->assertSame(['items', 'total', 'page', 'pageSize', 'pages'], array_keys($page));
        $this->assertSame(4, $page['total']);
        $this->assertSame(2, $page['pages']);
        $this->assertCount(2, $page['items']);
        $this->assertSame("{$tag}-SO", $page['items'][0]['entityNumber'], 'newest first by default');
        $this->assertSame(['id', 'entityType', 'entityId', 'entityNumber', 'textAr', 'textEn', 'userId', 'username', 'at'], array_keys($page['items'][0]));

        $asc = $this->expectOk($this->getAs('sales', "/api/activity?q={$tag}&order=asc&page=2&pageSize=3"));
        $this->assertSame(["{$tag}-SO"], array_column($asc['items'], 'entityNumber'));

        $this->assertSame(3, $this->expectOk($this->getAs('sales', "/api/activity?q={$tag}&entity=trip"))['total'], 'entity type, case-insensitive');
        $this->assertSame(1, $this->expectOk($this->getAs('sales', "/api/activity?entity={$tag}-2"))['total'], 'entity number');
        $this->assertSame(1, $this->expectOk($this->getAs('sales', "/api/activity?entity=id-{$tag}-3"))['total'], 'entity id');
        $this->assertSame(4, $this->expectOk($this->getAs('sales', "/api/activity?q={$tag}&user=syst"))['total']);
        $this->assertSame(0, $this->expectOk($this->getAs('sales', "/api/activity?q={$tag}&user=nobody"))['total']);

        $today = now()->toDateString();
        $this->assertSame(4, $this->expectOk($this->getAs('sales', "/api/activity?q={$tag}&from={$today}&to={$today}"))['total']);
        $this->assertSame(0, $this->expectOk($this->getAs('sales', "/api/activity?q={$tag}&to=2020-01-01"))['total']);
        $this->expectRejected($this->getAs('sales', '/api/activity?from=not-a-date'), 'INVALID_DATE', [400]);
    }

    public function test_audit_trail_needs_permission_and_filters(): void
    {
        $this->expectRejected($this->getAs('sales', '/api/audit'), 'FORBIDDEN', [403]);

        $tag = 'AUD'.self::uid();
        $audit = app(AuditService::class);
        $audit->log(null, ['action' => 'PO.APPROVE', 'entityType' => 'PurchaseOrder', 'entityId' => "id-{$tag}", 'entityNumber' => $tag, 'oldValue' => 'pending', 'newValue' => 'approved']);
        $audit->status(null, 'PurchaseOrder', "id-{$tag}", $tag, 'approved', 'sent');
        $audit->log(null, ['action' => 'PO.CANCEL', 'entityType' => 'SalesOrder', 'entityId' => "other-{$tag}", 'entityNumber' => "{$tag}-X"]);

        $all = $this->expectOk($this->getAs('gm', "/api/audit?q={$tag}&pageSize=2"));
        $this->assertSame(3, $all['total']);
        $this->assertCount(2, $all['items']);
        $this->assertSame(2, $all['pages']);
        $this->assertArrayHasKey('oldValue', $all['items'][0]);
        $this->assertArrayHasKey('entityNumber', $all['items'][0]);

        $status = $this->expectOk($this->getAs('gm', "/api/audit?q={$tag}&action=status"));
        $this->assertSame(1, $status['total'], 'action is a case-insensitive prefix');
        $this->assertSame('STATUS', $status['items'][0]['action']);
        $this->assertSame(2, $this->expectOk($this->getAs('gm', "/api/audit?q={$tag}&action=po."))['total']);
        $this->assertSame(2, $this->expectOk($this->getAs('gm', "/api/audit?q={$tag}&entityType=purchaseorder"))['total']);
        $this->assertSame(2, $this->expectOk($this->getAs('gm', "/api/audit?entity={$tag}"))['total'], 'entity number is an exact match');
        $this->assertSame(2, $this->expectOk($this->getAs('gm', "/api/audit?entity=id-{$tag}"))['total']);
        $this->assertSame(3, $this->expectOk($this->getAs('gm', "/api/audit?q={$tag}&user=system"))['total']);
        $this->assertSame(0, $this->expectOk($this->getAs('gm', "/api/audit?q={$tag}&from=2999-01-01"))['total']);

        // Logins are audited by the platform itself; the viewer can find them by user.
        $logins = $this->expectOk($this->getAs('gm', '/api/audit?action=SECURITY.LOGIN&user=gm'));
        $this->assertGreaterThanOrEqual(1, $logins['total']);
    }

    // ───────────── notifications ─────────────
    public function test_notifications_are_scoped_to_the_callers_roles_and_can_be_marked_read(): void
    {
        $tag = 'NTF'.self::uid();
        $notify = app(NotifyService::class);
        $notify->activity(null, 'PurchaseOrder', null, "{$tag}-1", "إشعار مشتريات {$tag}", null, ['proc']);
        $notify->activity(null, 'PurchaseOrder', null, "{$tag}-2", "إشعار مشتريات ومالية {$tag}", null, ['proc', 'finance']);
        $notify->activity(null, 'Trip', null, "{$tag}-3", "إشعار توزيع {$tag}", null, ['disp']);

        $mine = fn (string $user) => collect($this->expectOk($this->getAs($user, '/api/notifications?pageSize=200'))['items'])->filter(fn ($n) => str_starts_with((string) $n['entityNumber'], $tag))->values();
        $this->assertSame(["{$tag}-1", "{$tag}-2"], $mine('proc')->pluck('entityNumber')->sort()->values()->all());
        $this->assertSame(["{$tag}-2"], $mine('finance')->pluck('entityNumber')->all());
        $this->assertSame(["{$tag}-3"], $mine('disp')->pluck('entityNumber')->all());
        $this->assertCount(0, $mine('sales'));

        $list = $this->expectOk($this->getAs('proc', '/api/notifications?pageSize=1'));
        $this->assertSame(['items', 'total', 'page', 'pageSize', 'pages', 'unread'], array_keys($list));
        $this->assertCount(1, $list['items']);
        $this->assertFalse($list['items'][0]['read'], 'unread first');
        $this->assertSame('proc', $list['items'][0]['roleKey']);
        $unreadBefore = $list['unread'];
        $this->assertGreaterThanOrEqual(2, $unreadBefore);

        // Another role's notification is invisible: 404 and nothing changes.
        $foreign = $mine('disp')[0]['id'];
        $this->expectRejected($this->postAs('proc', "/api/notifications/{$foreign}/read"), 'NOTIFICATION_NOT_FOUND', [404]);
        $this->assertFalse((bool) Notification::find($foreign)->read);
        $this->expectRejected($this->postAs('proc', '/api/notifications/does-not-exist/read'), 'NOTIFICATION_NOT_FOUND', [404]);

        $own = $mine('proc')->firstWhere('entityNumber', "{$tag}-1")['id'];
        $read = $this->expectOk($this->postAs('proc', "/api/notifications/{$own}/read"));
        $this->assertSame(['id' => $own, 'read' => true, 'unread' => $unreadBefore - 1], $read);
        // Idempotent: marking it again is still a success.
        $this->assertSame($unreadBefore - 1, $this->expectOk($this->postAs('proc', "/api/notifications/{$own}/read"))['unread']);

        $unreadOnly = $this->expectOk($this->getAs('proc', '/api/notifications?unread=true&pageSize=200'));
        $this->assertSame($unreadBefore - 1, $unreadOnly['total']);
        $this->assertNotContains($own, array_column($unreadOnly['items'], 'id'));

        $all = $this->expectOk($this->postAs('proc', '/api/notifications/read-all'));
        $this->assertSame(['updated' => $unreadBefore - 1, 'unread' => 0], $all);
        $this->assertSame(0, $this->expectOk($this->getAs('proc', '/api/notifications'))['unread']);
        // The finance copy of the shared activity is a separate row and stays unread.
        $this->assertFalse($mine('finance')[0]['read']);
    }

    // ───────────── settings ─────────────
    public function test_settings_update_is_audited_takes_effect_and_needs_permission(): void
    {
        $all = $this->expectOk($this->getAs('admin', '/api/settings'));
        $this->assertEqualsCanonicalizing(array_keys(SettingsService::DEFAULTS), array_keys($all));
        $this->assertSame(['value', 'group', 'description', 'isDefault'], array_slice(array_keys($all['sales.vatPct']), 0, 4));
        $before = $all['inventory.expiringSoonDays']['value'];
        $this->assertSame(30, $before);

        // Denied: 403, audited as a security event, and nothing changed.
        $denied = AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->count();
        $this->expectRejected($this->putAs('sales', '/api/settings/inventory.expiringSoonDays', ['value' => 99]), 'FORBIDDEN', [403]);
        $this->expectRejected($this->getAs('sales', '/api/settings'), 'FORBIDDEN', [403]);
        $this->assertSame($denied + 2, AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->count());
        $this->assertSame($before, SystemSetting::find('inventory.expiringSoonDays')->value);
        $this->assertSame(0, AuditLog::where('action', 'SETTING.UPDATE')->where('entity_id', 'inventory.expiringSoonDays')->count());

        $updated = $this->expectOk($this->putAs('admin', '/api/settings/inventory.expiringSoonDays', ['value' => 45]));
        $this->assertSame(['key', 'value', 'group', 'description', 'isDefault', 'updatedAt'], array_keys($updated));
        $this->assertSame(45, $updated['value']);
        $this->assertSame('inventory', $updated['group']);
        $this->assertFalse($updated['isDefault']);

        // Takes effect: through SettingsService and on the dashboard that reads it.
        $settings = app(SettingsService::class);
        $settings->flush();
        $this->assertSame(45, $settings->get('inventory.expiringSoonDays'));
        $dashboard = $this->expectOk($this->getAs('admin', '/api/dashboard'));
        $this->assertSame(45, $dashboard['expiringSoonDays']);
        $this->assertSame('Expiring ≤ 45d', collect($dashboard['kpis'])->firstWhere('key', 'expiringSoon')['labelEn']);
        $this->assertSame(45, $this->expectOk($this->getAs('admin', '/api/settings'))['inventory.expiringSoonDays']['value']);

        $audit = AuditLog::where('action', 'SETTING.UPDATE')->where('entity_id', 'inventory.expiringSoonDays')->latest('at')->first();
        $this->assertNotNull($audit);
        $this->assertSame('admin', $audit->username);
        $this->assertSame('SystemSetting', $audit->entity_type);
        $this->assertSame('30', $audit->old_value);
        $this->assertSame('45', $audit->new_value);

        // Structured values round-trip as JSON (objects, booleans).
        $company = ['nameAr' => 'شركة الاختبار', 'nameEn' => 'Test Co', 'currency' => 'SAR'];
        $this->assertSame($company, $this->expectOk($this->putAs('admin', '/api/settings/company', ['value' => $company]))['value']);
        $this->assertFalse($this->expectOk($this->putAs('admin', '/api/settings/sales.enforceCreditLimit', ['value' => false]))['value']);
        $settings->flush();
        $this->assertFalse($settings->get('sales.enforceCreditLimit'));

        $this->expectRejected($this->putAs('admin', '/api/settings/nope.key', ['value' => 1]), 'SETTING_NOT_FOUND', [404]);
        $this->expectRejected($this->putAs('admin', '/api/settings/sales.vatPct', []), 'INVALID_INPUT', [400]);

        // Leave the policies as the seed had them for the other test classes.
        $this->expectOk($this->putAs('admin', '/api/settings/inventory.expiringSoonDays', ['value' => $before]));
        $this->expectOk($this->putAs('admin', '/api/settings/sales.enforceCreditLimit', ['value' => true]));
        $this->expectOk($this->putAs('admin', '/api/settings/company', ['value' => SettingsService::DEFAULTS['company']['value']]));
    }

    // ───────────── global search ─────────────
    public function test_global_search_returns_entity_type_and_number_for_deep_links(): void
    {
        $find = function (string $q, string $type) {
            $res = $this->expectOk($this->getAs('sales', '/api/search?q='.rawurlencode($q)));
            $this->assertSame(['q', 'hits'], array_keys($res));
            foreach ($res['hits'] as $hit) {
                $this->assertSame(['type', 'number', 'title', 'subtitle', 'path'], array_keys($hit));
            }
            foreach (array_count_values(array_column($res['hits'], 'type')) as $count) {
                $this->assertLessThanOrEqual(5, $count, 'at most 5 hits per document type');
            }

            return collect($res['hits'])->where('type', $type)->values();
        };

        $po = $find('PO-2026-00461', 'po');
        $this->assertCount(1, $po);
        $this->assertSame(['number' => 'PO-2026-00461', 'path' => '/po/PO-2026-00461'], ['number' => $po[0]['number'], 'path' => $po[0]['path']]);
        $this->assertStringContainsString('ر.س', $po[0]['subtitle']);

        $so = $find('so-2026-00111', 'so');
        $this->assertSame('/so/SO-2026-00111', $so[0]['path'], 'numbers match case-insensitively');

        $trip = $find('TRP-2026-0031', 'trip');
        $this->assertSame('TRP-2026-0031', $trip[0]['number']);
        $this->assertSame('/trip/TRP-2026-0031', $trip[0]['path']);
        $this->assertContains('TRP-2026-0031', $find('غرب الرياض', 'trip')->pluck('number')->all(), 'trips are found by route name');

        $bySku = $find('P01552', 'product');
        $this->assertSame('P01552', $bySku[0]['number']);
        $this->assertSame('/product/P01552', $bySku[0]['path']);
        $this->assertContains('P01552', $find('كاجو فيتنامي', 'product')->pluck('number')->all(), 'Arabic name');
        $this->assertContains('P01552', $find('vietnamese cashew', 'product')->pluck('number')->all(), 'English name, case-insensitive');

        $this->assertCount(5, $find('PO-2026', 'po'));
        $this->assertSame([], $this->expectOk($this->getAs('sales', '/api/search?q='.rawurlencode('zz%__no_such_thing')))['hits'], 'wildcards are literals');
        $this->expectRejected($this->getAs('sales', '/api/search'), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->getAs('sales', '/api/search?q=%20%20'), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->getAs('sales', '/api/search?q='.str_repeat('x', 81)), 'INVALID_INPUT', [400]);
    }
}
