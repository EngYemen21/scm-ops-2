<?php

namespace Tests\Feature\Insights;

use App\Insights\Models\ExpiryLog;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Notification;
use Tests\ApiTestCase;
use Tests\Feature\Inventory\InventoryFixtures;

/**
 * Expiry alert levels against this story's own batches: each lot with stock is announced once per level whose window
 * it has entered, never twice; expired lots, lots without stock and lots far from expiry are not.
 */
class ExpiryAlertsTest extends ApiTestCase
{
    use InsightsFixtures;
    use InventoryFixtures;

    public function test_levels_announce_each_lot_once_per_level(): void
    {
        $tag = self::tag();
        $zone = $this->makeZone('RYD', "X{$tag}", ["X{$tag}-1"]);
        $bin = $zone['bins']["X{$tag}-1"];
        ['product' => $p, 'batch' => $b3] = $this->makeProduct("EX{$tag}", 'ambient', 200);
        $batch = fn (string $no, int $days) => Batch::create(['product_id' => $p->id, 'batch_no' => "{$no}-{$tag}", 'expiry_date' => now()->startOfDay()->addDays($days)]);
        $b1 = $batch('B1', 5);
        $b2 = $batch('B2', 25);
        $b4 = $batch('B4', 10);       // no stock
        $b5 = $batch('B5', -3);       // expired
        foreach ([[$b1, 10], [$b2, 4], [$b3, 3], [$b5, 2]] as [$b, $q]) {
            $this->stock($p, $b, $zone['wh'], $bin, $q, "OPEN-{$tag}");
        }
        $wmUser = $this->makeUser(['page.insights']);
        $plain = $this->makeUser(['page.insights']);

        // only expiryalert.manage defines levels; recipients are required
        $this->expectRejected($this->postAs($plain->username, '/api/insights/expiry/levels', ['name' => 'x', 'daysBeforeExpiry' => 30, 'userIds' => [$wmUser->id]]), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('admin', '/api/insights/expiry/levels', ['name' => 'بلا مستلم', 'daysBeforeExpiry' => 30]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->getAs($plain->username, '/api/insights/expiry/recipients'), 'FORBIDDEN', [403]);
        $this->assertContains($wmUser->username, array_column($this->expectOk($this->getAs('inv', '/api/insights/expiry/recipients'))['items'], 'username'));
        $l30 = $this->expectOk($this->postAs('admin', '/api/insights/expiry/levels', ['name' => "شهر {$tag}", 'daysBeforeExpiry' => 30, 'notificationType' => 'inapp', 'userIds' => [$wmUser->id]]));
        $l7 = $this->expectOk($this->postAs('admin', '/api/insights/expiry/levels', ['name' => "أسبوع {$tag}", 'daysBeforeExpiry' => 7, 'notificationType' => 'both', 'userIds' => [$wmUser->id, $plain->id]]));
        $this->assertSame(1, AuditLog::where('entity_id', $l30['id'])->where('action', 'EXPIRY_LEVEL.CREATE')->count());

        $mine = fn () => ExpiryLog::whereIn('batch_id', [$b1->id, $b2->id, $b3->id, $b4->id, $b5->id])->whereIn('level_id', [$l30['id'], $l7['id']])->get()
            ->map(fn ($l) => ($l->level_id === $l30['id'] ? 'L30' : 'L7').':'.$l->batch_no)->sort()->values()->all();
        $this->expectRejected($this->postAs($plain->username, '/api/insights/expiry/run'), 'FORBIDDEN', [403]);
        $this->expectOk($this->postAs('admin', '/api/insights/expiry/run'));
        $this->assertSame(["L30:B1-{$tag}", "L30:B2-{$tag}", "L7:B1-{$tag}"], $mine());
        $row = ExpiryLog::where('level_id', $l7['id'])->where('batch_id', $b1->id)->first();
        $this->assertSame(10, $row->qty_on_hand);
        $this->assertSame(5, $row->days_left);
        $this->assertSame('skipped', $row->email_status, 'no mail provider: recorded as skipped, never as sent');
        $this->assertSame('none', ExpiryLog::where('level_id', $l30['id'])->where('batch_id', $b1->id)->value('email_status'));
        $this->assertSame(1, Notification::where('user_id', $plain->id)->where('entity_id', $l7['id'])->count());
        $this->assertSame(0, Notification::where('user_id', $plain->id)->where('entity_id', $l30['id'])->count());

        // never twice
        $this->expectOk($this->postAs('admin', '/api/insights/expiry/run'));
        $this->assertCount(3, $mine());
        $this->assertSame(1, Notification::where('user_id', $plain->id)->where('entity_id', $l7['id'])->count());

        // the log and what is coming, read by anyone of the section
        $log = $this->expectOk($this->getAs($plain->username, '/api/insights/expiry/log?sku='.$p->sku));
        $this->assertSame(3, $log['total'], json_encode(array_map(fn ($i) => [$i['level']['name'], $i['batchNo']], $log['items']), JSON_UNESCAPED_UNICODE));
        $this->assertSame($wmUser->username, $log['items'][0]['recipients'][0]['username']);
        $up = $this->expectOk($this->getAs($plain->username, '/api/insights/expiry/upcoming?q='.$p->sku));
        $this->assertSame(["B1-{$tag}", "B2-{$tag}"], array_column($up['items'], 'batchNo'), 'stocked lots within the widest level, soonest first');
        $levelsOfB2 = collect($up['items'][1]['levels'])->keyBy('id');
        $this->assertNotNull($levelsOfB2[$l30['id']]['notifiedAt']);
        $this->assertFalse($levelsOfB2[$l7['id']]['due']);

        // an inactive level announces nothing; the active one goes on
        $this->expectOk($this->patchAs('admin', "/api/insights/expiry/levels/{$l7['id']}", ['active' => false]));
        $b6 = $batch('B6', 3);
        $this->stock($p, $b6, $zone['wh'], $bin, 1, "OPEN-{$tag}");
        $this->expectOk($this->postAs('admin', '/api/insights/expiry/run'));
        $this->assertSame(["L30:B1-{$tag}", "L30:B2-{$tag}", "L7:B1-{$tag}"], $mine(), 'b6 is not in the watched list of the closure');
        $this->assertTrue(ExpiryLog::where('level_id', $l30['id'])->where('batch_id', $b6->id)->exists());
        $this->assertFalse(ExpiryLog::where('level_id', $l7['id'])->where('batch_id', $b6->id)->exists());
        $levels = collect($this->expectOk($this->getAs($plain->username, '/api/insights/expiry/levels'))['items'])->keyBy('id');
        $this->assertSame(ExpiryLog::where('level_id', $l30['id'])->count(), $levels[$l30['id']]['notifications'], 'lots of other stories are announced too');
        $this->assertFalse($levels[$l7['id']]['active']);
        $this->assertReconciled();
    }
}
