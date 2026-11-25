<?php

namespace Tests\Feature\Insights;

use App\Insights\Services\OdooHistory;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/**
 * The copy of Odoo's customer invoices that gives the brand dashboards their past: from `odoo_records` (never Odoo)
 * into ins_odoo_invoices / ins_odoo_sale_lines — product lines only, credit notes negative, resumable in slices, then
 * following the change feed (a cancelled invoice, a deleted credit note).
 */
class OdooHistoryTest extends ApiTestCase
{
    use InsightsFixtures;
    use OdooFixtures;

    public function test_the_first_pass_the_slices_and_the_feed(): void
    {
        $base = 8_000_000 + ((int) self::uid() % 100000) * 10;
        [$m1, $m2, $m3] = [$base + 1, $base + 2, $base + 3];
        $partner = [$base, 'شركة الاختبار'];
        $this->odooModel('account.move');
        $this->odooModel('account.move.line');
        $this->rec('account.move', $m1, ['name' => "INV/2025/{$m1}", 'move_type' => 'out_invoice', 'state' => 'posted', 'payment_state' => 'partial', 'partner_id' => [$base + 9, 'جهة اتصال'],
            'commercial_partner_id' => $partner, 'invoice_date' => '2025-11-02', 'invoice_date_due' => '2025-12-02', 'amount_untaxed' => 1000.004, 'amount_total' => 1150.0, 'amount_residual' => 150.0, 'currency_id' => [148, 'SAR']]);
        $this->rec('account.move', $m2, ['name' => "RINV/2025/{$m2}", 'move_type' => 'out_refund', 'state' => 'posted', 'payment_state' => 'paid', 'partner_id' => $partner, 'commercial_partner_id' => $partner,
            'invoice_date' => '2025-11-20', 'invoice_date_due' => false, 'amount_untaxed' => 100.0, 'amount_total' => 115.0, 'amount_residual' => 0.0, 'currency_id' => [148, 'SAR']]);
        $this->rec('account.move', $m3, ['name' => "MISC/{$m3}", 'move_type' => 'entry', 'state' => 'posted', 'partner_id' => $partner, 'invoice_date' => false]);
        $line = fn (int $id, int $move, array $d) => $this->rec('account.move.line', $id, $d + ['move_id' => [$move, 'x'], 'product_id' => [$base + 50, '[SKU] صنف'], 'quantity' => 4.0, 'price_subtotal' => 600.0, 'display_type' => 'product']);
        $line($base * 10 + 1, $m1, []);
        $line($base * 10 + 2, $m1, ['display_type' => 'tax', 'product_id' => false, 'price_subtotal' => 0.0]);
        $line($base * 10 + 3, $m1, ['display_type' => 'cogs', 'price_subtotal' => 0.0]);
        $line($base * 10 + 4, $m1, ['product_id' => [$base + 51, 'صنف آخر'], 'quantity' => 2.0, 'price_subtotal' => 400.004]);
        $line($base * 10 + 5, $m2, ['quantity' => 1.0, 'price_subtotal' => 100.0]);
        $line($base * 10 + 6, $m3, ['price_subtotal' => 999.0]);

        $h = app(OdooHistory::class);
        // tiny slices: every call stops after one slice and keeps its place
        $out = $h->advance(0, fresh: true);
        for ($i = 0; $i < 400 && ! $out['done']; $i++) {
            $out = $h->advance(0);
        }
        $this->assertTrue($out['done']);
        $this->assertSame('feed', $h->status()['phase']);

        $inv = DB::table('ins_odoo_invoices')->whereIn('odoo_id', [$m1, $m2, $m3])->orderBy('odoo_id')->get()->keyBy('odoo_id');
        $this->assertSame([$m1, $m2], $inv->keys()->map(fn ($k) => (int) $k)->all(), 'a journal entry is not an invoice');
        $this->assertSame([(string) $base, 'شركة الاختبار', '1000.00', '150.00', '2025-12-02'], [$inv[$m1]->partner_ref, $inv[$m1]->partner_name, $inv[$m1]->amount_untaxed, $inv[$m1]->residual, substr($inv[$m1]->due_date, 0, 10)],
            'the commercial partner, Odoo\'s figures rounded once');
        $lines = DB::table('ins_odoo_sale_lines')->whereIn('move_id', [$m1, $m2, $m3])->orderBy('odoo_id')->get(['odoo_id', 'qty', 'amount', 'product_ref', 'state']);
        $this->assertSame([[$base * 10 + 1, '4.000', '600.00'], [$base * 10 + 4, '2.000', '400.00'], [$base * 10 + 5, '-1.000', '-100.00']],
            $lines->map(fn ($l) => [(int) $l->odoo_id, (string) $l->qty, (string) $l->amount])->all(), 'product lines only; a credit note is negative');

        // a second whole pass changes nothing
        $h->advance(25, fresh: true);
        $this->assertSame(3, DB::table('ins_odoo_sale_lines')->whereIn('move_id', [$m1, $m2, $m3])->count());

        // the feed: an invoice cancelled in Odoo, a credit note deleted, a line corrected
        $this->odooWrites('account.move', $m1, ['state' => 'cancel']);
        $this->odooWrites('account.move', $m2, [], delete: true);
        $this->odooWrites('account.move.line', $base * 10 + 4, ['price_subtotal' => 450.0]);
        $this->assertGreaterThanOrEqual(3, $h->status()['waiting']);
        $fed = $h->advance(25);
        $this->assertGreaterThanOrEqual(3, $fed['changes']);
        $this->assertSame(0, $h->status()['waiting']);
        $this->assertSame('cancel', DB::table('ins_odoo_invoices')->where('odoo_id', $m1)->value('state'));
        $this->assertSame(['cancel', 'cancel'], DB::table('ins_odoo_sale_lines')->where('move_id', $m1)->orderBy('odoo_id')->pluck('state')->all());
        $this->assertSame('450.00', (string) DB::table('ins_odoo_sale_lines')->where('odoo_id', $base * 10 + 4)->value('amount'));
        $this->assertFalse(DB::table('ins_odoo_invoices')->where('odoo_id', $m2)->exists());
        $this->assertFalse(DB::table('ins_odoo_sale_lines')->where('move_id', $m2)->exists());

        // the API: status for the company's users, a sync step for brandowner.manage
        $plain = $this->makeUser(['page.insights']);
        $this->assertSame('feed', $this->expectOk($this->getAs($plain->username, '/api/insights/history'))['copy']['phase']);
        $this->expectRejected($this->postAs($plain->username, '/api/insights/history/sync'), 'FORBIDDEN', [403]);
        $this->assertTrue($this->expectOk($this->postAs('admin', '/api/insights/history/sync', ['seconds' => 2]))['done']);
    }
}
