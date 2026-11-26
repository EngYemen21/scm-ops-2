<?php

namespace Tests\Feature\Portal;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Product;
use App\Portal\Models\Application;
use App\Portal\Models\ApplicationDocument;
use App\Portal\Models\FavoriteItem;
use App\Portal\Models\FavoriteList;
use App\Portal\Models\LineChange;
use App\Portal\Models\OrderRequest;
use App\Portal\Models\OrderRequestLine;
use App\Portal\Models\PortalBranch;
use App\Portal\Models\PortalEvent;
use App\Portal\Models\Statement;
use App\Portal\Models\StatementLine;
use App\Portal\OdooPortalProjection;
use App\Support\AppError;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/**
 * From a small copy of Odoo to the customer portal: a plan that writes nothing, a first load, a second pass that
 * changes nothing, the change feed, links that appear later — and what OPS added is never overwritten. The copy is
 * built by inserting rows (no Odoo); the favourites cross two batch boundaries, so the batch writer is the code under
 * test.
 */
class PortalProjectionTest extends ApiTestCase
{
    use PortalFixtures;

    private const MODELS = ['portal.customer.branch', 'customer.portal.favorite.list', 'customer.portal.favorite', 'portal.onboarding.application', 'hub.order.request',
        'hub.order.request.line', 'hub.order.line.change', 'customer.statement', 'customer.statement.line'];

    /** How many favourites the first list holds: more than two batches of 500. */
    private const MANY = 1100;

    private Customer $a;

    private Customer $b;

    private Product $p1;

    private Product $p2;

    private Product $p3;

    private function projection(): OdooPortalProjection
    {
        return $this->app->make(OdooPortalProjection::class);
    }

    /** The copy the stories start from. */
    private function build(): void
    {
        $this->emptyMirror();
        $this->mirrorModels(self::MODELS);
        [$this->a, $this->b] = [$this->makeCustomer(['name_ar' => 'شركة ألف']), $this->makeCustomer(['name_ar' => 'شركة باء'])];
        [$this->p1, $this->p2, $this->p3] = [$this->makeProduct(), $this->makeProduct(), $this->makeProduct()];
        // partners: 501 is an OPS customer, 503 is a contact of 501, 502 and 504 are not OPS customers (yet)
        $this->mirror('res.partner', 501, ['name' => 'شركة ألف', 'parent_id' => false]);
        $this->mirror('res.partner', 502, ['name' => 'شركة باء', 'parent_id' => false]);
        $this->mirror('res.partner', 503, ['name' => 'مشتريات ألف', 'parent_id' => [501, 'شركة ألف']]);
        $this->mirror('res.partner', 504, ['name' => 'شركة جيم', 'parent_id' => false]);
        $this->linkCustomer(501, $this->a);
        $this->linkProduct(601, $this->p1);
        $this->linkProduct(602, $this->p2);
        $this->mirror('res.users', 7, ['hub_role' => 'operations'], ['name' => 'مسؤول طلبات الفرع']);
        $this->mirror('res.company', 1, ['name' => 'الشركة', 'currency_id' => [150, 'SAR']]);

        $this->mirror('portal.customer.branch', 11, ['name' => 'فرع العليا', 'company_partner_id' => [501, 'شركة ألف'], 'sequence' => 5, 'user_ids' => [7], 'code' => false, 'b2b_warehouse_id' => false], ['active' => true]);
        $this->mirror('portal.customer.branch', 12, ['name' => 'فرع جيم', 'company_partner_id' => [504, 'شركة جيم'], 'sequence' => 10, 'user_ids' => []], ['active' => true]);
        $this->mirror('portal.customer.branch', 13, ['name' => 'فرع مغلق', 'company_partner_id' => [501, 'شركة ألف'], 'sequence' => 20, 'user_ids' => []], ['active' => false]);

        $this->mirror('customer.portal.favorite.list', 21, ['name' => 'المشروبات', 'partner_id' => [501, 'شركة ألف'], 'sequence' => 10, 'product_count' => self::MANY]);
        $this->mirror('customer.portal.favorite.list', 22, ['name' => 'قائمة المشتريات', 'partner_id' => [503, 'شركة ألف, مشتريات ألف'], 'sequence' => 10, 'product_count' => 0]);
        $this->mirror('customer.portal.favorite.list', 23, ['name' => 'قائمة باء', 'partner_id' => [502, 'شركة باء'], 'sequence' => 10, 'product_count' => 5]);
        $rows = [];
        $favorite = fn (int $id, int $list, int $product, float $qty, int $sequence = 10) => ['model' => 'customer.portal.favorite', 'odoo_id' => $id, 'display_name' => "#{$id}", 'write_date' => '2026-09-01 08:00:00',
            'data' => json_encode(['list_id' => [$list, 'list'], 'partner_id' => [501, 'x'], 'product_id' => [$product, "[P{$product}] منتج {$product}"], 'default_quantity' => $qty, 'sequence' => $sequence]),
            'hash' => sha1((string) $id), 'version' => 1, 'first_seen_at' => now(), 'changed_at' => now()];
        for ($i = 0; $i < self::MANY; $i++) {
            $rows[] = $favorite(1000 + $i, 21, $i % 2 ? 602 : 601, 1 + $i % 7);
        }
        for ($i = 0; $i < 5; $i++) {
            $rows[] = $favorite(3000 + $i, 23, 603, 2.5); // a product OPS does not have yet
        }
        $rows[] = $favorite(3100, 999, 601, 1);            // a list the copy does not hold
        $rows[] = $favorite(3200, 21, 601, 1.0E+15);       // a quantity no column can hold
        foreach (array_chunk($rows, 400) as $chunk) {
            DB::table('odoo_records')->insert($chunk);
        }

        $this->mirror('portal.onboarding.application', 31, ['name' => 'ONB/2026/00031', 'state' => 'pending_review', 'language' => 'ar', 'company_name_ar' => 'مطعم الواحة', 'company_name_en' => 'Oasis',
            'commercial_registration' => '1010 123456', 'vat_number' => '310000000000003', 'legal_entity_type' => 'establishment', 'owner_full_name' => 'صاحب المنشأة', 'owner_national_id' => '1099887766',
            'mobile' => '0555000111', 'city' => 'الرياض', 'filename_cr' => 'cr.pdf', 'filename_vat' => 'vat.pdf', 'filename_owner_id' => false, 'terms_accepted' => false, 'created_partner_id' => false,
            'extraction_log' => 'نص مستخرج من أوراق المتقدم', 'create_date' => '2026-08-01 09:00:00', 'create_uid' => [2, 'بوابة العملاء']], ['write_date' => '2026-08-02 10:00:00']);
        $this->mirror('portal.onboarding.application', 32, ['name' => 'ONB/2026/00032', 'state' => 'expired', 'language' => 'en', 'company_name_ar' => false, 'create_date' => '2026-07-01 09:00:00']);
        $this->mirror('portal.onboarding.application', 33, ['name' => 'ONB/2026/00033', 'state' => 'activated', 'language' => 'ar', 'company_name_ar' => 'شركة ألف', 'created_partner_id' => [501, 'شركة ألف'],
            'activated_at' => '2026-07-05 10:00:00', 'create_date' => '2026-07-02 09:00:00']);
        $this->mirror('ir.attachment', 9001, ['res_model' => 'portal.onboarding.application', 'res_id' => 31, 'res_field' => 'doc_cr', 'name' => 'cr.pdf', 'mimetype' => 'application/pdf', 'file_size' => 1234]);
        $this->mirror('ir.attachment', 9002, ['res_model' => 'sale.order', 'res_id' => 31, 'res_field' => false, 'name' => 'another model.pdf', 'mimetype' => 'application/pdf', 'file_size' => 9]);

        $this->mirror('sale.order', 7001, ['name' => 'S07001', 'partner_id' => [501, 'شركة ألف'], 'branch_id' => [11, 'فرع العليا'], 'hub_request_id' => [41, 'REQ/2026/00041']]);
        $this->mirror('sale.order', 7002, ['name' => 'S07002', 'partner_id' => [502, 'شركة باء'], 'branch_id' => false, 'hub_request_id' => false]);
        $this->mirror('hub.order.request', 41, ['name' => 'REQ/2026/00041', 'state' => 'approved', 'company_partner_id' => [501, 'شركة ألف'], 'branch_id' => [11, 'فرع العليا'], 'requested_by_id' => [7, 'مسؤول طلبات الفرع'],
            'request_date' => '2026-09-01 07:00:00', 'approved_by_id' => [8, 'مدير المبيعات'], 'approval_date' => '2026-09-01 09:30:00', 'approval_duration_hours' => 2.5, 'amount_total' => 150.0,
            'currency_id' => [150, 'SAR'], 'pricelist_id' => [4, 'قائمة المطاعم'], 'sale_order_id' => [7001, 'S07001'], 'note' => false, 'rejection_reason' => false]);
        $this->mirror('hub.order.request.line', 52, ['request_id' => [41, 'REQ/2026/00041'], 'product_id' => [603, '[P603] منتج غير مربوط'], 'name' => '[P603] منتج غير مربوط', 'product_uom_qty' => 1.0, 'price_unit' => 50.0, 'price_subtotal' => 50.0]);
        $this->mirror('hub.order.request.line', 51, ['request_id' => [41, 'REQ/2026/00041'], 'product_id' => [601, '[P601] منتج'], 'name' => '[P601] منتج', 'product_uom_qty' => 2.0, 'price_unit' => 50.0, 'price_subtotal' => 100.0]);
        $change = fn (int $order, string $orderName, int $product, string $type, $label, $old, $new, string $at) => ['order_id' => [$order, $orderName], 'product_id' => [$product, "[P{$product}] منتج {$product}"],
            'product_name' => "اسم المنتج {$product} وقت التغيير", 'change_type' => $type, 'field_label' => $label, 'old_value' => $old, 'new_value' => $new, 'changed_by_id' => [7, 'مسؤول طلبات الفرع'], 'change_date' => $at];
        $this->mirror('hub.order.line.change', 61, $change(7001, 'S07001', 601, 'modified', 'Quantity', '2', '3', '2026-09-02 10:00:00'));
        $this->mirror('hub.order.line.change', 62, $change(7002, 'S07002', 603, 'added', false, false, '5', '2026-09-02 11:00:00'));
        $this->mirror('hub.order.line.change', 63, $change(7001, 'S07001', 602, 'modified', 'Unit Price', '20.0', '18.5', '2026-09-02 12:00:00'));

        $this->mirror('customer.statement', 71, ['name' => 'STMT/2026/0071', 'partner_id' => [501, 'شركة ألف'], 'company_id' => [1, 'الشركة'], 'date_from' => '2026-01-01', 'date_to' => '2026-01-31', 'opening_balance' => 100.0,
            'total_debit' => 500.0, 'total_credit' => 200.0, 'closing_balance' => 400.0, 'state' => 'generated', 'create_date' => '2026-02-01 08:00:00', 'create_uid' => [3, 'المحاسب']]);
        $this->mirror('customer.statement', 72, ['name' => 'STMT/2026/0072', 'partner_id' => [504, 'شركة جيم'], 'company_id' => [1, 'الشركة'], 'date_from' => '2026-02-01', 'date_to' => '2026-02-28', 'opening_balance' => -30.5,
            'total_debit' => 0.0, 'total_credit' => 0.0, 'closing_balance' => -30.5, 'state' => 'draft']);
        $this->mirror('customer.statement', 73, ['name' => 'STMT/2026/0073', 'partner_id' => [501, 'شركة ألف'], 'date_from' => false, 'date_to' => false, 'state' => 'draft']);
        $this->mirror('account.move', 9101, ['name' => 'INV/2026/00001', 'branch_id' => [11, 'فرع العليا'], 'move_type' => 'out_invoice']);
        $this->mirror('account.move', 9102, ['name' => 'PBNK1/2026/00001', 'branch_id' => false, 'move_type' => 'entry']);
        $this->mirror('customer.statement.line', 81, ['statement_id' => [71, 'STMT/2026/0071'], 'date' => '2026-01-05', 'transaction_type' => 'Invoice', 'reference' => 'INV/2026/00001', 'name' => 'فاتورة يناير', 'debit' => 500.0,
            'credit' => 0.0, 'amount' => 500.0, 'balance' => 600.0, 'move_id' => [9101, 'INV/2026/00001']]);
        $this->mirror('customer.statement.line', 82, ['statement_id' => [71, 'STMT/2026/0071'], 'date' => '2026-01-20', 'transaction_type' => 'Payment', 'reference' => 'PBNK1/2026/00001', 'name' => false, 'debit' => 0.0,
            'credit' => 200.0, 'amount' => -200.0, 'balance' => 400.0, 'move_id' => [9102, 'PBNK1/2026/00001']]);
        $this->mirror('customer.statement.line', 83, ['statement_id' => [999, 'gone'], 'date' => '2026-01-21', 'transaction_type' => 'Invoice', 'reference' => 'X', 'debit' => 1.0, 'credit' => 0.0, 'balance' => 1.0, 'move_id' => false]);
    }

    public function test_plan_first_load_and_a_second_pass_that_changes_nothing(): void
    {
        $this->build();
        $projection = $this->projection();
        $this->assertSame(['portal', true], [$projection->key(), $projection->ready()]);

        // the plan counts and writes nothing
        $plan = $projection->plan();
        $this->assertTrue($plan['dryRun']);
        $expected = [
            'branches.created' => 3, 'branches.customerPending' => 1, 'lists.created' => 3, 'lists.customerPending' => 1,
            'favorites.created' => self::MANY + 6, 'favorites.productPending' => 5, 'favorites.skippedNoList' => 1,
            'applications.created' => 3, 'documents.created' => 2, 'requests.created' => 1, 'requestLines.created' => 2, 'requestLines.productPending' => 1,
            'lineChanges.created' => 3, 'lineChanges.customerPending' => 1, 'lineChanges.productPending' => 1,
            'statements.created' => 2, 'statements.customerPending' => 1, 'statements.skippedNoPeriod' => 1, 'statementLines.created' => 2, 'statementLines.skippedNoStatement' => 1,
        ];
        ksort($expected);
        $got = $plan['counts'];
        ksort($got);
        $this->assertSame($expected, $got);
        $this->assertSame([0, 0, 0, 0, 0, 0], [PortalBranch::count(), FavoriteList::count(), FavoriteItem::count(), Application::count(), LineChange::count(), Statement::count()], 'a plan writes nothing');

        // the first load
        $imports = AuditLog::where('action', 'PORTAL.IMPORT')->count();
        $applied = $projection->apply();
        $this->assertFalse($applied['dryRun']);
        $this->assertSame([self::MANY + 5, 1, 1], [$applied['counts']['favorites.created'], $applied['counts']['favorites.failed'], $applied['counts']['favorites.skippedNoList']]);
        $this->assertStringContainsString('customer.portal.favorite #3200', $applied['notes']['favorites.failed'][0], 'the one record that cannot be written is named; its batch was written without it');
        $rest = array_diff_key($applied['counts'], ['favorites.created' => 1, 'favorites.failed' => 1]);
        ksort($rest);
        $this->assertSame(array_diff_key($expected, ['favorites.created' => 1]), $rest, 'everything else is exactly what the plan said');

        // branches: Odoo's name, customer, order and people; linked to the OPS customer when its partner is
        $b11 = PortalBranch::where('external_ref', '11')->firstOrFail();
        $this->assertSame(['فرع العليا', $this->a->id, '501', 'شركة ألف', 5, true, 'odoo'], [$b11->name, $b11->customer_id, $b11->customer_ref, $b11->customer_name, $b11->sequence, $b11->active, $b11->source_system]);
        $this->assertSame([['ref' => '7', 'name' => 'مسؤول طلبات الفرع', 'role' => 'operations']], array_map(fn (array $u) => ['ref' => $u['ref'], 'name' => $u['name'], 'role' => $u['role']], $b11->portal_users));
        $b12 = PortalBranch::where('external_ref', '12')->firstOrFail();
        $this->assertSame([null, '504', 'شركة جيم'], [$b12->customer_id, $b12->customer_ref, $b12->customer_name], 'a partner OPS does not have keeps its Odoo id and name');
        $this->assertFalse(PortalBranch::where('external_ref', '13')->firstOrFail()->active, 'a branch archived in Odoo arrives archived');

        // lists: the customer of a contact is its company; items were written in batches and counted on the list
        $l21 = FavoriteList::where('external_ref', '21')->firstOrFail();
        $l22 = FavoriteList::where('external_ref', '22')->firstOrFail();
        $l23 = FavoriteList::where('external_ref', '23')->firstOrFail();
        $this->assertSame([$this->a->id, $this->a->id, null, '503'], [$l21->customer_id, $l22->customer_id, $l23->customer_id, $l22->customer_ref]);
        $this->assertSame([self::MANY, 0, 5], [$l21->items_count, $l22->items_count, $l23->items_count]);
        $this->assertSame([self::MANY, 5], [FavoriteItem::where('list_id', $l21->id)->count(), FavoriteItem::where('list_id', $l23->id)->count()]);
        $first = FavoriteItem::where('external_ref', '1000')->firstOrFail();
        $this->assertSame([$l21->id, $this->p1->id, '601', null, '1.000', 'odoo'], [$first->list_id, $first->product_id, $first->product_ref, $first->product_name, $first->qty, $first->source_system]);
        $pending = FavoriteItem::where('external_ref', '3000')->firstOrFail();
        $this->assertSame([null, '603', '[P603] منتج 603', '2.500'], [$pending->product_id, $pending->product_ref, $pending->product_name, $pending->qty]);

        // applications: Odoo's states in the words of the OPS review, Odoo's own word kept beside them
        $a31 = Application::where('external_ref', '31')->firstOrFail();
        $this->assertSame(['ONB/2026/00031', 'submitted', 'pending_review', 'مطعم الواحة', '1010123456', '310000000000003', '1099887766', 'establishment', 2, null],
            [$a31->number, $a31->status, $a31->source_state, $a31->company_name_ar, $a31->cr, $a31->vat_no, $a31->owner_national_id, $a31->legal_entity_type, $a31->documents_count, $a31->extra]);
        $this->assertSame('2026-08-01 09:00:00', $a31->created_at->format('Y-m-d H:i:s'), 'it is as old as it is in Odoo');
        $docs = ApplicationDocument::where('application_id', $a31->id)->get()->keyBy('kind');
        // linked to the attachment when the copy lists it, never copied; a paper only its file name tells of is still listed
        $this->assertSame(['odoo', '9001', 'application/pdf', 1234, null], [$docs['cr']->status, $docs['cr']->external_ref, $docs['cr']->mime, $docs['cr']->size, $docs['cr']->storage_key]);
        $this->assertSame(['odoo', '31:vat', 'vat.pdf'], [$docs['vat']->status, $docs['vat']->external_ref, $docs['vat']->file_name]);
        $this->assertSame(['expired', 'en', null], array_values(Application::where('external_ref', '32')->firstOrFail()->only(['status', 'language', 'company_name_ar'])));
        $a33 = Application::where('external_ref', '33')->firstOrFail();
        $this->assertSame(['approved', 'activated', $this->a->id, 'link', '501', '2026-07-05 10:00:00'], [$a33->status, $a33->source_state, $a33->customer_id, $a33->customer_by, $a33->customer_ref, $a33->approved_at->format('Y-m-d H:i:s')]);
        $this->assertSame(['approved', 'created'], PortalEvent::where('entity_id', $a33->id)->orderByDesc('at')->orderByDesc('id')->pluck('kind')->all());
        $this->assertSame(0, AuditLog::where('new_value', 'like', '%1099887766%')->count(), 'a person\'s identity number is not written to the audit trail');
        $this->assertStringNotContainsString('مستخرج', (string) json_encode($a31->getAttributes(), JSON_UNESCAPED_UNICODE), 'what Odoo extracted from the papers stays in the copy');

        // the order request, its lines in the order Odoo made them, the order it became
        $r41 = OrderRequest::where('external_ref', '41')->firstOrFail();
        $this->assertSame(['REQ/2026/00041', 'approved', $this->a->id, $b11->id, 'مسؤول طلبات الفرع', 'مدير المبيعات', 2.5, '150.00', 'SAR', 'قائمة المطاعم', '7001', 'S07001', 2],
            [$r41->number, $r41->status, $r41->customer_id, $r41->branch_id, $r41->requested_by, $r41->decided_by, $r41->decision_hours, $r41->amount_total, $r41->currency, $r41->price_list, $r41->order_ref, $r41->order_number, $r41->lines_count]);
        $this->assertSame([[1, '51', $this->p1->id, null], [2, '52', null, '[P603] منتج غير مربوط']],
            OrderRequestLine::where('request_id', $r41->id)->orderBy('line_no')->get()->map(fn ($l) => [$l->line_no, $l->external_ref, $l->product_id, $l->product_name])->all());

        // the log: who, what, before and after — and whose order it was
        $c61 = LineChange::where('external_ref', '61')->firstOrFail();
        $this->assertSame(['modified', 'qty', 'Quantity', '2', '3', 'مسؤول طلبات الفرع', '7001', 'S07001', $this->a->id, $b11->id, $r41->id, $this->p1->id, 'اسم المنتج 601 وقت التغيير', '2026-09-02 10:00:00'],
            [$c61->change_type, $c61->field, $c61->field_label, $c61->old_value, $c61->new_value, $c61->changed_by, $c61->order_ref, $c61->order_number, $c61->customer_id, $c61->branch_id, $c61->request_id, $c61->product_id,
                $c61->product_name, $c61->changed_at->format('Y-m-d H:i:s')]);
        $c62 = LineChange::where('external_ref', '62')->firstOrFail();
        $this->assertSame(['added', null, null, '5', null, '502', null, '603', null], [$c62->change_type, $c62->field, $c62->old_value, $c62->new_value, $c62->customer_id, $c62->customer_ref, $c62->product_id, $c62->product_ref, $c62->branch_id]);
        $this->assertSame('price', LineChange::where('external_ref', '63')->value('field'));

        // statements: Odoo's own figures, and the branch each document was for
        $s71 = Statement::where('external_ref', '71')->firstOrFail();
        $this->assertSame(['STMT/2026/0071', 'issued', $this->a->id, '2026-01-01', '2026-01-31', '100.00', '500.00', '200.00', '400.00', 'SAR', 2, 'المحاسب'],
            [$s71->number, $s71->status, $s71->customer_id, $s71->date_from->toDateString(), $s71->date_to->toDateString(), $s71->opening_balance, $s71->total_debit, $s71->total_credit, $s71->closing_balance, $s71->currency, $s71->lines_count, $s71->issued_by]);
        $this->assertSame([['invoice', 'INV/2026/00001', 'فاتورة يناير', '500.00', '0.00', '600.00', $b11->id, '9101'], ['payment', 'PBNK1/2026/00001', null, '0.00', '200.00', '400.00', null, '9102']],
            StatementLine::where('statement_id', $s71->id)->orderBy('date')->get()->map(fn ($l) => [$l->kind, $l->reference, $l->description, $l->debit, $l->credit, $l->balance, $l->branch_id, $l->doc_id])->all());
        $s72 = Statement::where('external_ref', '72')->firstOrFail();
        $this->assertSame(['draft', null, '504', '-30.50', null], [$s72->status, $s72->customer_id, $s72->customer_ref, $s72->closing_balance, $s72->issued_at]);
        $this->assertSame($imports + 1, AuditLog::where('action', 'PORTAL.IMPORT')->where('username', 'svc.odoo')->count(), 'the bulk of a pass is one audit line');
        $this->assertTrue(AuditLog::where('action', 'PORTAL.BRANCH.CREATE')->where('entity_id', $b11->id)->where('username', 'svc.odoo')->exists());

        // a second pass changes nothing
        $rows = fn () => [PortalBranch::count(), FavoriteList::count(), FavoriteItem::count(), Application::count(), ApplicationDocument::count(), OrderRequest::count(), OrderRequestLine::count(), LineChange::count(),
            Statement::count(), StatementLine::count(), PortalEvent::count(), AuditLog::where('username', 'svc.odoo')->count()];
        $before = $rows();
        $stamp = FavoriteItem::where('external_ref', '1000')->value('updated_at');
        $again = $projection->apply()['counts'];
        $this->assertSame([3, 3, self::MANY + 5, 3, 2, 1, 2, 3, 2, 2], [$again['branches.unchanged'], $again['lists.unchanged'], $again['favorites.unchanged'], $again['applications.unchanged'], $again['documents.unchanged'],
            $again['requests.unchanged'], $again['requestLines.unchanged'], $again['lineChanges.unchanged'], $again['statements.unchanged'], $again['statementLines.unchanged']]);
        $this->assertSame([], array_filter(array_keys($again), fn (string $k) => (bool) preg_match('/\.(created|updated|archived|removed)$/', $k)), 'nothing is written the second time');
        $this->assertSame($before, $rows());
        $this->assertEquals($stamp, FavoriteItem::where('external_ref', '1000')->value('updated_at'));
    }

    public function test_the_feed_keeps_the_section_current_and_links_appear_later(): void
    {
        $this->build();
        $projection = $this->projection();
        $projection->apply();
        $l21 = FavoriteList::where('external_ref', '21')->firstOrFail();
        $l23 = FavoriteList::where('external_ref', '23')->firstOrFail();
        $s71 = Statement::where('external_ref', '71')->firstOrFail();
        $this->assertSame(['changes' => 0, 'counts' => []], $projection->consume(), 'nothing changed in Odoo: nothing to do');

        // what people did in Odoo since
        $favorite = fn (int $list, int $product, float $qty) => ['list_id' => [$list, 'list'], 'partner_id' => [501, 'x'], 'product_id' => [$product, "[P{$product}] منتج {$product}"], 'default_quantity' => $qty, 'sequence' => 10];
        $this->mirror('customer.portal.favorite', 1000, $favorite(21, 601, 9), [], true);                       // another usual quantity
        $this->mirror('customer.portal.favorite', 1001, $favorite(21, 602, 2), ['deleted' => true], true);     // taken off the list
        $this->mirror('customer.portal.favorite', 5000, $favorite(22, 602, 4), [], true);                       // a new favourite
        $this->mirror('customer.portal.favorite.list', 23, ['name' => 'قائمة باء', 'partner_id' => [502, 'شركة باء'], 'sequence' => 10], ['deleted' => true], true);
        $this->mirror('portal.onboarding.application', 31, ['name' => 'ONB/2026/00031', 'state' => 'activated', 'language' => 'ar', 'company_name_ar' => 'مطعم الواحة', 'company_name_en' => 'Oasis',
            'commercial_registration' => '1010 123456', 'vat_number' => '310000000000003', 'legal_entity_type' => 'establishment', 'owner_full_name' => 'صاحب المنشأة', 'owner_national_id' => '1099887766',
            'mobile' => '0555000111', 'city' => 'الرياض', 'filename_cr' => 'cr.pdf', 'filename_vat' => false, 'terms_accepted' => true, 'created_partner_id' => [502, 'شركة باء'],
            'activated_at' => '2026-09-10 12:00:00', 'create_date' => '2026-08-01 09:00:00', 'write_uid' => [3, 'مراجع الطلبات']], ['write_date' => '2026-09-10 12:00:00'], true);
        $this->mirror('customer.statement.line', 82, ['statement_id' => [71, 'x'], 'date' => '2026-01-20', 'transaction_type' => 'Payment', 'reference' => 'PBNK1/2026/00001', 'debit' => 0.0, 'credit' => 200.0, 'balance' => 400.0,
            'move_id' => [9102, 'x']], ['deleted' => true], true);
        $this->mirror('hub.order.line.change', 64, ['order_id' => [7001, 'S07001'], 'product_id' => [601, 'x'], 'product_name' => 'منتج', 'change_type' => 'removed', 'field_label' => false, 'old_value' => '3', 'new_value' => false,
            'changed_by_id' => [8, 'مدير المبيعات'], 'change_date' => '2026-09-11 09:00:00'], [], true);
        $this->assertSame(7, $projection->waiting());

        $out = $projection->consume();
        $this->assertSame(7, $out['changes']);
        $this->assertSame([1, 1, 1, 1, 1, 1, 1, 1], [$out['counts']['favorites.updated'], $out['counts']['favorites.removed'], $out['counts']['favorites.created'], $out['counts']['lists.archived'],
            $out['counts']['applications.updated'], $out['counts']['documents.removed'], $out['counts']['statementLines.removed'], $out['counts']['lineChanges.created']]);
        $this->assertSame(0, $projection->waiting());
        $this->assertSame('9.000', FavoriteItem::where('external_ref', '1000')->value('qty'));
        $this->assertNull(FavoriteItem::where('external_ref', '1001')->first(), 'a favourite the customer removed is not a favourite any more');
        $this->assertSame([self::MANY - 1, 1], [$l21->fresh()->items_count, FavoriteList::where('external_ref', '22')->value('items_count')], 'the lists count what they hold');
        $gone = $l23->fresh();
        $this->assertSame([false, 5, 5], [$gone->active, $gone->items_count, FavoriteItem::where('list_id', $gone->id)->count()], 'a list that is gone from Odoo is archived and keeps its items');
        $this->assertNotNull($gone->source_deleted_at);
        $a31 = Application::where('external_ref', '31')->firstOrFail();
        $this->assertSame(['approved', 'activated', '502', null, true, 1], [$a31->status, $a31->source_state, $a31->customer_ref, $a31->customer_id, $a31->terms_accepted, $a31->documents_count]);
        $event = PortalEvent::where('entity_id', $a31->id)->orderByDesc('at')->orderByDesc('id')->first();
        $this->assertSame(['approved', 'odoo', 'مراجع الطلبات', '2026-09-10 12:00:00'], [$event->kind, $event->origin, $event->username, $event->at->format('Y-m-d H:i:s')], 'what happened in Odoo is told at the time it happened there');
        $this->assertNotNull(ApplicationDocument::where('application_id', $a31->id)->where('kind', 'vat')->value('removed_at'), 'a paper taken off in Odoo is taken off here — and kept');
        $this->assertSame([1, 1], [$s71->fresh()->lines_count, StatementLine::where('statement_id', $s71->id)->count()], 'Odoo replaced the statement\'s lines: OPS follows');
        $this->assertSame(['removed', $this->a->id], array_values(LineChange::where('external_ref', '64')->firstOrFail()->only(['change_type', 'customer_id'])));

        // the day Odoo's partner 502 and product 603 become an OPS customer and an OPS product, every record that named them is linked
        $this->linkCustomer(502, $this->b);
        $this->linkProduct(603, $this->p3);
        $this->assertSame(['changes' => 0, 'counts' => []], $projection->consume());
        $this->assertSame([$this->b->id, $this->b->id, 'link'], [$l23->fresh()->customer_id, $a31->fresh()->customer_id, $a31->fresh()->customer_by]);
        $c62 = LineChange::where('external_ref', '62')->firstOrFail();
        $this->assertSame([$this->b->id, $this->p3->id], [$c62->customer_id, $c62->product_id]);
        $this->assertSame([5, 0], [FavoriteItem::where('list_id', $l23->id)->where('product_id', $this->p3->id)->count(), FavoriteItem::where('list_id', $l23->id)->whereNull('product_id')->count()]);
        $this->assertSame($this->p3->id, OrderRequestLine::where('external_ref', '52')->value('product_id'));
        $this->assertNull(PortalBranch::where('external_ref', '12')->value('customer_id'), 'a partner that is still not an OPS customer stays as Odoo names it');
        $this->assertTrue(AuditLog::where('action', 'PORTAL.RELINK')->exists());
        // … and a pass afterwards agrees with what the links made
        $pass = $projection->apply()['counts'];
        $this->assertSame([], array_filter(array_keys($pass), fn (string $k) => (bool) preg_match('/^(lineChanges|requestLines|lists)\.(created|updated)$/', $k)));
    }

    public function test_what_ops_added_is_never_overwritten_and_an_incomplete_copy_is_not_guessed_from(): void
    {
        $this->build();
        $projection = $this->projection();
        $projection->apply();
        $this->followOdoo(true);
        $b11 = PortalBranch::where('external_ref', '11')->firstOrFail();
        $l21 = FavoriteList::where('external_ref', '21')->firstOrFail();
        // OPS adds what Odoo does not hold
        $this->expectOk($this->patchAs('sales', "/api/portal/branches/{$b11->id}", ['phone' => '0551230000', 'windowFrom' => '06:00', 'windowTo' => '09:00', 'address' => 'حي العليا — البوابة الخلفية']));
        $this->expectOk($this->patchAs('sales', "/api/portal/favorites/lists/{$l21->id}", ['branchId' => $b11->id, 'notes' => 'قائمة فرع العليا']));
        // Odoo renames the branch
        $this->mirror('portal.customer.branch', 11, ['name' => 'فرع العليا الرئيسي', 'company_partner_id' => [501, 'شركة ألف'], 'sequence' => 5, 'user_ids' => [7]], ['active' => true], true);
        $out = $projection->consume();
        $this->assertSame([1, 1], [$out['changes'], $out['counts']['branches.updated']]);
        $b11->refresh();
        $this->assertSame(['فرع العليا الرئيسي', '0551230000', '06:00', '09:00', 'حي العليا — البوابة الخلفية'], [$b11->name, $b11->phone, $b11->window_from, $b11->window_to, $b11->address]);
        $pass = $projection->apply()['counts'];
        $this->assertSame([3, 3], [$pass['branches.unchanged'], $pass['lists.unchanged']]);
        $this->assertSame([$b11->id, 'قائمة فرع العليا'], array_values($l21->fresh()->only(['branch_id', 'notes'])));
        // the screens show it as Odoo's, with what OPS added
        $shown = $this->expectOk($this->getAs('gm', "/api/portal/favorites/lists/{$l21->id}"));
        $this->assertSame([true, self::MANY, $b11->id], [$shown['owned'], $shown['itemsCount'], $shown['branch']['id']]);
        $page = $this->expectOk($this->getAs('gm', "/api/portal/favorites/lists/{$l21->id}/items?pageSize=50&page=22"));
        $this->assertSame([self::MANY, 22, 50], [$page['total'], $page['pages'], count($page['items'])], 'eleven hundred favourites are read fifty at a time');

        // a copy that is not complete is not guessed from
        DB::table('odoo_models')->where('model', 'customer.statement.line')->update(['full_state' => 'running']);
        $this->assertFalse($projection->ready());
        try {
            $projection->plan();
            $this->fail('expected ODOO_COPY_INCOMPLETE');
        } catch (AppError $e) {
            $this->assertSame('ODOO_COPY_INCOMPLETE', $e->errorCode);
        }
    }
}
