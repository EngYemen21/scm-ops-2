<?php

namespace Tests\Feature\Tax;

use App\Integration\Models\IntExternalRef;
use App\Integration\Odoo\Models\OdooChange;
use App\Integration\Odoo\Models\OdooModel;
use App\Integration\Odoo\Models\OdooRecord;
use App\Support\AppError;
use App\Tax\Models\EinvoiceHistory;
use App\Tax\Models\FiscalPosition;
use App\Tax\Models\FiscalPositionLine;
use App\Tax\Models\TaxCode;
use App\Tax\Models\TaxGroup;
use App\Tax\OdooTaxProjection;
use App\Tax\Support\Money;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/**
 * From the copy of Odoo's accounting to the tax section (OdooTaxProjection). The copy is built here by hand with
 * records shaped exactly like the real copy's (`account.tax`, `account.tax.repartition.line` with the tags of the
 * Saudi localisation, `account.fiscal.position`, `account.edi.document` …) — Odoo itself is never called.
 * Proven: the plan writes nothing; the apply adopts the codes OPS made for the same treatment and makes the others with
 * the line of the return read from the tags; a second apply changes nothing; a change of the feed reaches its record;
 * what Odoo owns is refused while the section follows Odoo; the e-invoice history keeps every document's status.
 */
class OdooTaxProjectionTest extends ApiTestCase
{
    use TaxFixtures;

    private const MODELS = ['account.tax.group', 'account.account.tag', 'account.tax', 'account.tax.repartition.line', 'account.fiscal.position', 'account.fiscal.position.tax', 'account.edi.format', 'account.edi.document'];

    private static string $tag;

    private static ?string $customerId = null;

    protected function setUp(): void
    {
        parent::setUp();
        self::$tag ??= self::uid();
        config(['odoo.enabled' => true, 'odoo.project.tax' => true]);
    }

    private function rec(string $model, int $id, array $data, ?bool $active = null, ?string $display = null): void
    {
        $data += ['write_date' => '2025-02-15 13:19:37'];
        OdooRecord::updateOrCreate(['model' => $model, 'odoo_id' => $id], ['display_name' => $display ?? (is_string($data['name'] ?? null) ? $data['name'] : null), 'write_date' => $data['write_date'], 'active' => $active,
            'data' => $data, 'hash' => sha1(json_encode($data)), 'first_seen_at' => now(), 'changed_at' => now(), 'deleted_at' => null]);
    }

    private function odooWrites(string $model, int $id, array $values): void
    {
        $current = OdooRecord::where('model', $model)->where('odoo_id', $id)->first();
        $this->rec($model, $id, $values + ['write_date' => '2025-03-01 08:00:00'] + (array) $current->data, $current->active, $current->display_name);
        OdooChange::create(['model' => $model, 'odoo_id' => $id, 'kind' => 'updated', 'seen_at' => now()]);
    }

    private function projection(): OdooTaxProjection
    {
        return $this->app->make(OdooTaxProjection::class);
    }

    /** What the tables of the section hold that came from Odoo. */
    private function tables(): array
    {
        return [TaxGroup::where('source_system', 'odoo')->count(), TaxCode::where('source_system', 'odoo')->count(), FiscalPosition::where('source_system', 'odoo')->count(),
            FiscalPositionLine::where('source_system', 'odoo')->count(), EinvoiceHistory::count()];
    }

    private function buildCopy(): void
    {
        foreach (['odoo_changes', 'odoo_records', 'odoo_models'] as $table) {
            DB::table($table)->whereIn('model', self::MODELS)->delete();
        }
        foreach (self::MODELS as $model) {
            OdooModel::create(['model' => $model, 'status' => 'active', 'full_state' => 'done', 'has_active' => in_array($model, ['account.tax', 'account.fiscal.position', 'account.account.tag'], true)]);
        }
        $this->rec('account.tax.group', 1, ['name' => 'VAT Taxes', 'sequence' => 10]);
        $this->rec('account.tax.group', 2, ['name' => 'Other Taxes', 'sequence' => 10]);
        $this->rec('account.tax.group', 3, ['name' => 'Withholding Tax', 'sequence' => 10]);
        foreach ([5 => '+1. Standard Rates 15% (Base)', 9 => '+3. Local Sales Subject to 0% (Base)', 11 => '+4. Export Sales (Base)', 25 => '+7. Standard rated 15% Purchases (Base)',
            29 => '+9. Imports subject to reverse charge mechanism (Base)', 45 => '+Withholding Tax 5% (Rental) (Base)', 15 => '+1. Standard Rates 15% (Tax)'] as $id => $name) {
            $this->rec('account.account.tag', $id, ['name' => $name, 'applicability' => 'taxes', 'tax_negate' => false, 'country_id' => [192, 'Saudi Arabia']], true);
        }
        $tax = fn (int $id, string $name, float $amount, string $use, array $group, array $more = []) => $this->rec('account.tax', $id, $more + ['name' => $name, 'amount' => $amount, 'sequence' => 1, 'amount_type' => 'percent',
            'tax_group_id' => $group, 'type_tax_use' => $use, 'price_include' => false, 'tax_exigibility' => 'on_invoice', 'l10n_sa_is_retention' => false], true);
        $tax(1, '15%', 15, 'sale', [1, 'VAT Taxes'], ['invoice_label' => 'Vat 15%']);
        $tax(2, '0%', 0, 'sale', [2, 'Other Taxes'], ['invoice_label' => 'Local Sales 0%']);
        $tax(3, '0% EX', 0, 'sale', [2, 'Other Taxes'], ['invoice_label' => 'Export Sales 0%']);
        $tax(5, '15%', 15, 'purchase', [1, 'VAT Taxes'], ['invoice_label' => 'Purchase Tax 15%']);
        $tax(6, '15% R C', 15, 'purchase', [1, 'VAT Taxes'], ['tax_scope' => 'service', 'invoice_label' => 'Reverse charge provision Tax 15%']);
        $tax(10, '5% WH R', 5, 'purchase', [3, 'Withholding Tax'], ['tax_scope' => 'service', 'description' => 'Withholding Tax 5% (Rental)']);
        $line = 0;
        foreach ([1 => 5, 2 => 9, 3 => 11, 5 => 25, 6 => 29, 10 => 45] as $taxId => $baseTag) {
            $this->rec('account.tax.repartition.line', ++$line, ['tax_id' => [$taxId, 'x'], 'tag_ids' => [$baseTag], 'sequence' => 1, 'document_type' => 'invoice', 'factor_percent' => 100, 'repartition_type' => 'base']);
            $this->rec('account.tax.repartition.line', ++$line, ['tax_id' => [$taxId, 'x'], 'tag_ids' => $taxId === 1 ? [15] : [], 'sequence' => 1, 'document_type' => 'invoice', 'factor_percent' => 100, 'repartition_type' => 'tax']);
        }
        $this->rec('res.country', 192, ['name' => 'Saudi Arabia', 'code' => 'SA']);
        $this->rec('account.fiscal.position', 1, ['name' => 'KSA', 'sequence' => 16, 'auto_apply' => true, 'country_id' => [192, 'Saudi Arabia'], 'vat_required' => false], true);
        $this->rec('account.fiscal.position', 2, ['name' => 'GCC', 'sequence' => 16, 'auto_apply' => true, 'vat_required' => false, 'country_group_id' => [4, 'Gulf Cooperation Council (GCC)']], true);
        $this->rec('account.fiscal.position.tax', 1, ['position_id' => [2, 'GCC'], 'tax_src_id' => [1, '15%'], 'tax_dest_id' => [3, '0% EX']]);
        $this->rec('account.edi.format', 1, ['code' => 'sa_zatca', 'name' => 'ZATCA (Saudi Arabia)']);
        // the invoices the documents belong to, and their customer
        $partner = 99000 + (int) substr(self::$tag, -3);
        $this->rec('res.partner', $partner, ['name' => 'مطعم الواحة', 'parent_id' => false]);
        $this->rec('account.move', 23861, ['name' => 'INV/2025/00602', 'move_type' => 'out_invoice', 'partner_id' => [$partner, 'مطعم الواحة'], 'invoice_date' => '2025-02-15', 'amount_total' => 1150.0, 'amount_tax' => 150.0,
            'l10n_sa_uuid' => 'a1b2c3d4-0000-4000-8000-000000000001', 'l10n_sa_chain_index' => 602, 'l10n_sa_confirmation_datetime' => '2025-02-15 13:19:36', 'state' => 'posted']);
        $this->rec('account.move', 23900, ['name' => 'RINV/2025/00010', 'move_type' => 'out_refund', 'partner_id' => [$partner, 'مطعم الواحة'], 'invoice_date' => '2025-02-20', 'amount_total' => 115.0, 'amount_tax' => 15.0, 'state' => 'posted']);
        $this->rec('account.edi.document', 1, ['state' => 'sent', 'move_id' => [23861, 'INV/2025/00602'], 'attachment_id' => [5275, '310495338800003_20250215T161937_INV-2025-00602.xml'], 'edi_format_id' => [1, 'ZATCA (Saudi Arabia)'],
            'create_date' => '2025-02-15 13:19:36'], null, '310495338800003_20250215T161937_INV-2025-00602.xml');
        $this->rec('account.edi.document', 2, ['state' => 'to_send', 'blocking_level' => 'error', 'error' => '<p>ZATCA: Cannot post invoice while chain head has not been posted</p>', 'move_id' => [23900, 'RINV/2025/00010'],
            'edi_format_id' => [1, 'ZATCA (Saudi Arabia)']]);
        $c = $this->customer('OD'.self::$tag);
        self::$customerId = $c->id;
        IntExternalRef::where('system', 'odoo')->where('entity', 'customer')->where('external_id', (string) $partner)->delete();
        IntExternalRef::create(['system' => 'odoo', 'entity' => 'customer', 'external_id' => (string) $partner, 'internal_id' => $c->id, 'internal_code' => $c->code]);
    }

    public function test_plan_then_apply_adopts_what_ops_made_and_makes_the_rest(): void
    {
        $this->buildCopy();
        $ops = TaxCode::whereIn('code', ['S-STD15', 'S-EXP0', 'P-STD15', 'P-RC15'])->pluck('id', 'code')->all();
        $this->assertCount(4, $ops, 'the first dictionary of the section is there');
        $before = $this->tables();
        $plan = $this->projection()->plan();
        $this->assertTrue($plan['dryRun']);
        $this->assertSame($before, $this->tables(), 'a plan writes nothing');
        $this->assertSame([2, 1], [$plan['counts']['groups.created'], $plan['counts']['groups.linked']], json_encode($plan['counts']));
        $this->assertSame([4, 2], [$plan['counts']['codes.linked'], $plan['counts']['codes.created']]);
        $this->assertSame([2, 1, 2], [$plan['counts']['positions.created'], $plan['counts']['positionLines.created'], $plan['counts']['history.created']]);

        $out = $this->projection()->apply();
        $this->assertSame($plan['counts'], $out['counts'], 'the apply does what the plan said');
        $code = fn (int $odooId) => TaxCode::where('source_system', 'odoo')->where('external_ref', (string) $odooId)->firstOrFail();
        // adopted, never doubled: the OPS code keeps its short code, its default and its exemption reason
        $this->assertSame([$ops['S-STD15'], 'S-STD15', true, 1, 'standard', 'S'], [$code(1)->id, $code(1)->code, $code(1)->is_default, $code(1)->return_box, $code(1)->kind, $code(1)->category_code]);
        $this->assertSame([$ops['S-EXP0'], 4, 'zero', 'VATEX-SA-32'], [$code(3)->id, $code(3)->return_box, $code(3)->kind, $code(3)->exemption_reason_code]);
        $this->assertSame([$ops['P-RC15'], 9, 'reverse_charge', 'service'], [$code(6)->id, $code(6)->return_box, $code(6)->kind, $code(6)->scope]);
        $this->assertSame($ops['P-STD15'], $code(5)->id);
        $this->assertSame('15%', $code(1)->name_en);
        // made from Odoo: the line read from the tag, the kind from the line; no reason Odoo does not give
        $this->assertSame(['S-0', 'sale', 'zero', 3, 'Z', null, false], [$code(2)->code, $code(2)->use, $code(2)->kind, $code(2)->return_box, $code(2)->category_code, $code(2)->exemption_reason_code, $code(2)->is_default]);
        $this->assertSame(['P-5-WH-R', 'withholding', null, '5'], [$code(10)->code, $code(10)->kind, $code(10)->return_box, Money::rate($code(10)->rate)]);
        $this->assertSame('ضريبة الاستقطاع', TaxGroup::where('source_system', 'odoo')->where('external_ref', '3')->value('name_ar'));
        $this->assertSame($code(10)->group_id, TaxGroup::where('source_system', 'odoo')->where('external_ref', '3')->value('id'));
        $ksa = FiscalPosition::where('source_system', 'odoo')->where('external_ref', '1')->firstOrFail();
        $this->assertSame(['داخل المملكة', 'KSA', 'SA', true], [$ksa->name_ar, $ksa->name_en, $ksa->country_code, $ksa->auto_apply]);
        $gccLine = FiscalPositionLine::where('source_system', 'odoo')->where('external_ref', '1')->firstOrFail();
        $this->assertSame([$code(1)->id, $code(3)->id], [$gccLine->from_code_id, $gccLine->to_code_id]);
        // the history of the archived invoices
        $sent = EinvoiceHistory::where('external_ref', '1')->firstOrFail();
        $this->assertSame(['accepted', 'INV/2025/00602', 'out_invoice', 'customer_invoice', self::$customerId, '1150.00', '150.00', 602, 'sa_zatca', '5275'],
            [$sent->status, $sent->move_number, $sent->move_type, $sent->archive_kind, $sent->customer_id, (string) $sent->amount_total, (string) $sent->amount_tax, $sent->chain_index, $sent->format_code, $sent->attachment_ref]);
        $stuck = EinvoiceHistory::where('external_ref', '2')->firstOrFail();
        $this->assertSame(['error', 'to_send', 'error', 'customer_credit_note'], [$stuck->status, $stuck->state, $stuck->blocking_level, $stuck->archive_kind]);
        $this->assertSame('ZATCA: Cannot post invoice while chain head has not been posted', $stuck->error);

        // a second whole pass changes nothing
        $again = $this->projection()->apply();
        $this->assertSame([], array_filter($again['counts'], fn ($n, $k) => ! str_ends_with($k, '.unchanged') && ! str_ends_with($k, '.read'), ARRAY_FILTER_USE_BOTH));
        $this->assertSame(1, TaxCode::where('code', 'S-STD15')->count());
    }

    public function test_what_odoo_owns_is_refused_and_a_change_of_the_feed_reaches_its_record(): void
    {
        $zero = TaxCode::where('source_system', 'odoo')->where('external_ref', '2')->firstOrFail();
        $this->expectRejected($this->patchAs('finance', "/api/tax/codes/{$zero->id}", ['nameAr' => 'اسم من العمليات']), 'TAX_OWNED_BY_SOURCE', [422]);
        $this->expectRejected($this->patchAs('finance', "/api/tax/codes/{$zero->id}", ['rate' => 5]), 'TAX_OWNED_BY_SOURCE', [422]);
        $this->assertSame('0', Money::rate($zero->refresh()->rate));
        // what is OPS's own stays open: the short code, and the exemption reason Odoo does not give
        $r = $this->expectOk($this->patchAs('finance', "/api/tax/codes/{$zero->id}", ['code' => 'S-ZERO-MED', 'exemptionReasonCode' => 'VATEX-SA-35']));
        $this->assertSame(['S-ZERO-MED', 'VATEX-SA-35', true], [$r['code'], $r['exemptionReasonCode'], $r['owned']]);
        $this->expectRejected($this->patchAs('finance', "/api/tax/codes/{$zero->id}", ['exemptionReasonCode' => 'VATEX-SA-29']), 'TAX_CODE_REASON', [400]);
        $group = TaxGroup::where('source_system', 'odoo')->where('external_ref', '1')->value('id');
        $this->expectRejected($this->patchAs('finance', "/api/tax/groups/{$group}", ['nameAr' => 'تغيير']), 'TAX_OWNED_BY_SOURCE', [422]);

        // Odoo changes a tax and archives the withholding one; an e-invoice document is sent at last
        $p = $this->projection();
        $this->odooWrites('account.tax', 1, ['invoice_label' => 'VAT 15% (KSA)']);
        $this->odooWrites('account.edi.document', 2, ['state' => 'sent', 'blocking_level' => false, 'error' => false]);
        OdooRecord::where('model', 'account.tax')->where('odoo_id', 10)->update(['deleted_at' => now()]);
        OdooChange::create(['model' => 'account.tax', 'odoo_id' => 10, 'kind' => 'deleted', 'seen_at' => now()]);
        $run = $p->consume();
        $this->assertSame(['codes.updated' => 1, 'history.updated' => 1, 'codes.archived' => 1], $run['counts']);
        $this->assertSame('VAT 15% (KSA)', TaxCode::where('code', 'S-STD15')->value('label'));
        $this->assertTrue((bool) TaxCode::where('code', 'S-STD15')->value('is_default'), 'what is OPS\'s own is untouched by a change of Odoo');
        $this->assertSame(['accepted', null], [EinvoiceHistory::where('external_ref', '2')->value('status'), EinvoiceHistory::where('external_ref', '2')->value('error')]);
        $this->assertFalse((bool) TaxCode::where('source_system', 'odoo')->where('external_ref', '10')->value('active'));
        // a repartition line that moves a tax to another line of the return is a change of that tax
        $line = OdooRecord::where('model', 'account.tax.repartition.line')->get()->first(fn ($l) => ($l->data['tax_id'][0] ?? null) === 2 && $l->data['repartition_type'] === 'base');
        $this->odooWrites('account.tax.repartition.line', $line->odoo_id, ['tag_ids' => [11]]);
        $this->assertSame(['codes.updated' => 1], $p->consume()['counts']);
        $this->assertSame(4, TaxCode::where('code', 'S-ZERO-MED')->value('return_box'));

        // the history on the screen
        $list = $this->expectOk($this->getAs('finance', '/api/tax/history?q=INV/2025/00602'));
        $this->assertSame([1, 'accepted', 'INV/2025/00602', self::$customerId], [$list['total'], $list['items'][0]['status'], $list['items'][0]['moveNumber'], $list['items'][0]['customerId']]);
        $this->assertSame(2, $this->expectOk($this->getAs('finance', '/api/tax/history?customer='.self::$customerId.'&sort=date&order=asc'))['total']);
        $one = $this->expectOk($this->getAs('finance', '/api/tax/history/1'));
        $this->assertSame('310495338800003_20250215T161937_INV-2025-00602.xml', $one['attachmentName']);

        // the switch off: the section is handed over and everything is edited here
        config(['odoo.project.tax' => false]);
        $this->assertSame('ضريبة 0', $this->expectOk($this->patchAs('finance', "/api/tax/codes/{$zero->id}", ['nameAr' => 'ضريبة 0']))['nameAr']);
    }

    public function test_an_incomplete_copy_is_refused_and_nothing_is_written(): void
    {
        OdooModel::where('model', 'account.edi.document')->update(['full_state' => 'running']);
        $before = $this->tables();
        try {
            $this->projection()->plan();
            $this->fail('a plan on an incomplete copy must be refused');
        } catch (AppError $e) {
            $this->assertSame('ODOO_COPY_INCOMPLETE', $e->errorCode);
        }
        $this->assertSame($before, $this->tables());
        OdooModel::where('model', 'account.edi.document')->update(['full_state' => 'done']);
    }
}
