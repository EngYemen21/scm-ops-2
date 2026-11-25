<?php

namespace Tests\Feature\Approvals;

use App\Approvals\Models\ApprovalApprover;
use App\Approvals\Models\ApprovalAttachment;
use App\Approvals\Models\ApprovalCategory;
use App\Approvals\Models\ApprovalCategoryApprover;
use App\Approvals\Models\ApprovalEvent;
use App\Approvals\Models\ApprovalItem;
use App\Approvals\Models\ApprovalRequest;
use App\Approvals\OdooApprovalsProjection;
use App\Integration\Models\IntExternalRef;
use App\Integration\Odoo\Models\OdooChange;
use App\Integration\Odoo\Models\OdooModel;
use App\Integration\Odoo\Models\OdooRecord;
use App\Integration\Odoo\Projection\OdooProjection;
use App\Integration\Services\ExternalRefs;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Notification;
use App\Models\Product;
use App\Models\Role;
use App\Models\StatusHistory;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserRole;
use App\Support\AppError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\ApiTestCase;

/**
 * Odoo's Approvals app → the OPS approvals section (OdooApprovalsProjection): what a first load creates and how
 * faithfully, that a second one changes nothing, who the people of Odoo are in OPS, who owns the records while the
 * area follows Odoo and after it is handed over, and how a change in Odoo reaches the OPS record through the feed.
 *
 * The mirror is built here by hand (rows of odoo_models / odoo_records / odoo_changes) — Odoo is never called.
 */
class OdooApprovalsProjectionTest extends ApiTestCase
{
    private const API = '/api/approvals';

    private Supplier $supplier;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['odoo_changes', 'odoo_records', 'odoo_models', 'approval_requests', 'approval_categories'] as $table) {
            DB::table($table)->delete(); // the lines, items, events and files of a request go with it
        }
        IntExternalRef::where('system', 'odoo')->delete();
        Notification::where('entity_type', 'ApprovalRequest')->delete();
        config(['odoo.enabled' => true, 'odoo.project.approvals' => true]); // the area follows Odoo
        // the people of OPS the people of Odoo are recognised as
        User::where('username', 'proc')->update(['email' => 'buyer@example.test']);
        User::where('username', 'gm')->update(['email' => 'gm.office@example.test']);
        User::whereIn('username', ['cfo.person', 'twin.a', 'cfo@example.test'])->delete();
        $this->supplier = Supplier::where('active', true)->orderBy('code')->firstOrFail();
        $this->customer = Customer::where('active', true)->orderBy('code')->firstOrFail();
        $this->product = Product::where('active', true)->orderBy('sku')->firstOrFail();
        $refs = $this->app->make(ExternalRefs::class);
        $refs->link('odoo', 'supplier', '127', $this->supplier->id, $this->supplier->code);
        $refs->link('odoo', 'customer', '494', $this->customer->id, $this->customer->code);
        $refs->link('odoo', 'product', '10753', $this->product->id, $this->product->sku);
        $this->buildMirror();
    }

    // ───────────── a small copy of Odoo ─────────────

    private function mirror(string $model, int $id, array $data, array $over = []): void
    {
        OdooModel::updateOrCreate(['model' => $model], ['status' => 'active', 'full_state' => 'done', 'label' => $model]);
        OdooRecord::updateOrCreate(['model' => $model, 'odoo_id' => $id], [
            'display_name' => $data['name'] ?? null, 'write_date' => $data['write_date'] ?? null, 'active' => array_key_exists('active', $data) ? (bool) $data['active'] : null,
            'data' => $data, 'hash' => sha1((string) json_encode($data)), 'first_seen_at' => now(), 'changed_at' => now(), 'deleted_at' => $over['deleted_at'] ?? null,
        ]);
    }

    /** What the mirror does when Odoo writes a record: the new values, and a line in the change feed. */
    private function odooWrites(string $model, int $id, array $changes = [], string $kind = 'updated'): void
    {
        $current = (array) (OdooRecord::where('model', $model)->where('odoo_id', $id)->first()?->data ?? []);
        $this->mirror($model, $id, $changes + $current, $kind === 'deleted' ? ['deleted_at' => now()] : []);
        OdooChange::create(['model' => $model, 'odoo_id' => $id, 'kind' => $kind, 'seen_at' => now()]);
    }

    private function request(int $id, int $category, string $status, array $more = []): void
    {
        $this->mirror('approval.request', $id, $more + [
            'name' => "طلب رقم {$id}", 'category_id' => [$category, 'x'], 'request_status' => $status, 'request_owner_id' => [27, 'محمد المشتري'], 'create_uid' => [27, 'محمد المشتري'],
            'write_uid' => [27, 'محمد المشتري'], 'company_id' => [1, 'شركة الاختبار'], 'amount' => 0, 'quantity' => 0, 'date' => false, 'date_start' => false, 'date_end' => false,
            'date_confirmed' => "2025-07-01 08:00:0{$this->digit($id)}", 'location' => false, 'partner_id' => false, 'reference' => false, 'reason' => false,
            'create_date' => '2025-07-01 08:00:00', 'write_date' => '2025-07-01 09:00:00',
        ]);
    }

    private function digit(int $n): int
    {
        return $n % 10;
    }

    /** One approver line. `$writer` = who wrote it last in Odoo (the approver himself when he decided). */
    private function line(int $id, int $request, int $user, string $status, int $sequence, ?int $writer = null, string $at = '2025-07-01 08:30:00', bool $required = true): void
    {
        $names = [12 => 'مدير المالية في أودو', 40 => 'المدير', 6 => 'المدير المالي الأعلى', 27 => 'محمد المشتري'];
        $this->mirror('approval.approver', $id, ['request_id' => [$request, 'x'], 'user_id' => [$user, $names[$user]], 'status' => $status, 'sequence' => $sequence, 'required' => $required,
            'write_uid' => [$writer ?? $user, $names[$writer ?? $user]], 'create_uid' => [27, $names[27]], 'create_date' => '2025-07-01 08:00:00', 'write_date' => $at, 'company_id' => [1, 'شركة الاختبار']]);
    }

    private function buildMirror(): void
    {
        // four users of Odoo: one is an OPS user by e-mail (27 → proc), one by username (12 → finance), one by the e-mail of
        // his contact (40 → gm), and one has no OPS user (6)
        $this->mirror('res.users', 27, ['login' => 'Buyer@Example.test', 'partner_id' => [927, 'محمد المشتري']]);
        $this->mirror('res.users', 12, ['login' => 'finance', 'partner_id' => [912, 'مدير المالية في أودو']]);
        $this->mirror('res.users', 40, ['login' => 'boss', 'partner_id' => [940, 'المدير']]);
        $this->mirror('res.partner', 940, ['name' => 'المدير', 'email' => 'GM.Office@example.test']);
        $this->mirror('res.users', 6, ['login' => 'cfo@example.test', 'partner_id' => [906, 'المدير المالي الأعلى']]);

        $this->mirror('approval.category', 5, ['name' => 'Payment Application', 'active' => true, 'sequence' => 40, 'description' => false, 'approver_sequence' => true, 'approval_minimum' => 3,
            'has_amount' => 'required', 'has_partner' => 'optional', 'has_reference' => 'optional', 'has_date' => 'optional', 'has_payment_method' => 'required', 'has_period' => 'no', 'has_product' => 'no',
            'has_quantity' => 'no', 'has_location' => 'no', 'requirer_document' => 'optional', 'sequence_code' => 'BT', 'automated_sequence' => false, 'company_id' => [1, 'شركة الاختبار'],
            'is_default_for_purchase_transfers' => true, 'create_date' => '2025-06-01 08:00:00', 'write_date' => '2025-06-02 08:00:00']);
        $this->mirror('approval.category', 11, ['name' => 'طلب مرتجع', 'active' => true, 'sequence' => 0, 'description' => 'إرجاع بضاعة من عميل', 'approver_sequence' => false, 'approval_minimum' => 2,
            'has_amount' => 'optional', 'has_partner' => 'required', 'has_reference' => 'required', 'has_product' => 'optional', 'requirer_document' => 'optional', 'sequence_code' => 'R', 'automated_sequence' => true,
            'create_date' => '2025-06-01 08:00:00', 'write_date' => '2025-06-02 08:00:00']);
        $this->mirror('approval.category', 1, ['name' => 'Business Trip', 'active' => false, 'sequence' => 1, 'approval_minimum' => 1, 'has_period' => 'required', 'has_location' => 'required',
            'manager_approval' => 'approver', 'requirer_document' => 'optional', 'create_date' => '2025-06-01 08:00:00', 'write_date' => '2025-06-01 08:00:00']);
        foreach ([[3, 5, 12, true, 10], [6, 5, 40, true, 11], [4, 5, 6, true, 12], [8, 11, 6, false, 10], [9, 11, 12, true, 11]] as [$id, $category, $user, $required, $sequence]) {
            $this->mirror('approval.category.approver', $id, ['category_id' => [$category, 'x'], 'user_id' => [$user, ['12' => 'مدير المالية في أودو', '40' => 'المدير', '6' => 'المدير المالي الأعلى'][$user]],
                'required' => $required, 'sequence' => $sequence, 'create_date' => '2025-06-01 08:00:00', 'write_date' => '2025-06-01 08:00:00']);
        }

        // #2 approved by its three approvers, one after the other
        $this->request(2, 5, 'approved', ['name' => 'طلب تحويل P02977 - شركة المراعي', 'amount' => 3953.36, 'date' => '2025-06-30 00:00:00', 'date_confirmed' => '2025-06-30 07:40:57', 'partner_id' => [127, 'شركة المراعي'],
            'reference' => 'P02977', 'reason' => '<p>طلب موافقة لأمر الشراء P02977</p><p>عاجل</p>', 'purchase_order_id' => [2977, 'P02977 (108339)'], 'message_main_attachment_id' => [11820, 'receipt.jpg'],
            'create_date' => '2025-06-30 07:40:55', 'write_date' => '2025-06-30 08:41:34', 'write_uid' => [6, 'المدير المالي الأعلى']]);
        $this->line(5, 2, 12, 'approved', 10, null, '2025-06-30 07:41:46');
        $this->line(6, 2, 40, 'approved', 11, null, '2025-06-30 07:50:00');
        $this->line(7, 2, 6, 'approved', 12, null, '2025-06-30 08:41:33');
        $this->mirror('ir.attachment', 11820, ['name' => 'receipt.jpg', 'mimetype' => 'image/jpeg', 'file_size' => 2048, 'type' => 'binary', 'res_model' => 'approval.request', 'res_id' => 2,
            'create_uid' => [27, 'محمد المشتري'], 'create_date' => '2025-06-30 07:41:00', 'res_field' => false]);
        $this->mirror('ir.attachment', 11821, ['name' => 'a link', 'type' => 'url', 'res_model' => 'approval.request', 'res_id' => 2, 'res_field' => false]);
        $this->mirror('ir.attachment', 11900, ['name' => 'invoice.pdf', 'type' => 'binary', 'res_model' => 'sale.order', 'res_id' => 2, 'res_field' => false]);

        // #10 refused by the second approver; Odoo wrote "refused" on the third line too, in the refuser's name
        $this->request(10, 5, 'refused', ['amount' => 9000, 'write_date' => '2025-07-02 10:00:01', 'write_uid' => [40, 'المدير']]);
        $this->line(20, 10, 12, 'approved', 10, null, '2025-07-02 09:00:00');
        $this->line(21, 10, 40, 'refused', 11, null, '2025-07-02 10:00:00');
        $this->line(22, 10, 6, 'refused', 12, 40, '2025-07-02 10:00:00');

        // #20 waits for the OPS user finance; it asks for a product
        $this->request(20, 11, 'pending', ['name' => 'R00020', 'partner_id' => [494, 'مطعم الواحة'], 'reference' => 'S07699']);
        $this->line(30, 20, 12, 'pending', 11, 27);
        $this->line(31, 20, 6, 'approved', 10, null, '2025-07-01 08:45:00', false);
        $this->mirror('approval.product.line', 1, ['approval_request_id' => [20, 'R00020'], 'product_id' => [10753, '[P00963] كوب أسود'], 'description' => '[P00963] أكواب سوداء', 'quantity' => 5,
            'product_uom_id' => [1, 'Units'], 'warehouse_id' => [1, 'Riyadh warehouse'], 'create_date' => '2025-07-01 08:00:00', 'write_date' => '2025-07-01 08:00:00']);

        // #30 raised by somebody who has no OPS user, then cancelled by him
        $this->request(30, 11, 'cancel', ['request_owner_id' => [6, 'المدير المالي الأعلى'], 'create_uid' => [6, 'المدير المالي الأعلى'], 'write_uid' => [6, 'المدير المالي الأعلى'],
            'partner_id' => [888, 'عميل غير مربوط'], 'write_date' => '2025-07-03 11:00:00']);
        $this->line(40, 30, 12, 'cancel', 11, 6, '2025-07-03 11:00:00');
        $this->line(41, 30, 6, 'cancel', 10, null, '2025-07-03 11:00:00');

        // #40 not sent yet
        $this->request(40, 5, 'new', ['date_confirmed' => false, 'amount' => 120.5]);
        $this->line(50, 40, 12, 'new', 10, 27);

        // #60 the first approver refused, then took his refusal back: Odoo leaves the line after him — and the request — refused
        $this->request(60, 5, 'refused', ['amount' => 700, 'write_date' => '2025-07-04 12:00:00', 'write_uid' => [12, 'مدير المالية في أودو']]);
        $this->line(70, 60, 12, 'pending', 10, null, '2025-07-04 12:30:00');
        $this->line(71, 60, 40, 'refused', 11, 12, '2025-07-04 12:00:00');
    }

    private function projection(): OdooApprovalsProjection
    {
        return $this->app->make(OdooApprovalsProjection::class);
    }

    private function ops(int $odooId): ApprovalRequest
    {
        return ApprovalRequest::where('source_system', 'odoo')->where('external_ref', (string) $odooId)->firstOrFail();
    }

    private function show(string $user, int $odooId): array
    {
        return $this->expectOk($this->getAs($user, self::API.'/requests/'.$this->ops($odooId)->number));
    }

    /** @return array<string, string> name of the approver → status of his line */
    private function lines(array $request): array
    {
        return array_column(array_map(fn (array $a) => ['u' => $a['user']['username'] ?? $a['user']['nameAr'], 's' => $a['status']], $request['approvers']), 's', 'u');
    }

    private const FIRST_LOAD = ['category.created' => 3, 'categoryApprover.created' => 5, 'request.created' => 6, 'approver.created' => 13, 'item.created' => 1, 'attachment.created' => 1,
        'people.matched' => 3, 'people.keptByName' => 1];

    public function test_a_plan_writes_nothing_and_a_first_load_brings_the_categories_and_the_whole_history(): void
    {
        $projection = $this->projection();
        $this->assertSame(['approvals', true, true], [$projection->key(), $projection->enabled(), $projection->ready()]);
        $audits = AuditLog::count();
        $plan = $projection->plan();
        $this->assertTrue($plan['dryRun']);
        $this->assertEquals(self::FIRST_LOAD, $plan['counts']);
        $this->assertSame([0, 0, 0, 0, $audits], [ApprovalCategory::count(), ApprovalRequest::count(), ApprovalEvent::count(), ApprovalAttachment::count(), AuditLog::count()], 'a plan writes nothing');

        $applied = $projection->apply();
        $this->assertFalse($applied['dryRun']);
        $this->assertEquals(self::FIRST_LOAD, $applied['counts'], 'the plan said what the load would do');
        $this->assertArrayNotHasKey('failed', $applied);
        $this->assertSame(0, Notification::where('entity_type', 'ApprovalRequest')->count(), 'history notifies nobody');

        // ── categories: Odoo's rules in OPS words; an archived one is there, stopped
        $c = $this->expectOk($this->getAs('admin', self::API.'/categories/OD-5'));
        $this->assertSame(['Payment Application', 'Payment Application', true, 40, 'sequence', 3, true, 'odoo', '5', true],
            [$c['nameAr'], $c['nameEn'], $c['active'], $c['sort'], $c['approvalMode'], $c['minApprovals'], $c['allowSelfApproval'], $c['source'], $c['externalRef'], $c['owned']]);
        $this->assertSame(['amount' => 'required', 'date' => 'optional', 'period' => 'no', 'partner' => 'optional', 'reference' => 'optional', 'quantity' => 'no', 'items' => 'no', 'location' => 'no',
            'paymentMethod' => 'required', 'document' => 'optional'], $c['fields']);
        $this->assertEquals(['company' => 'شركة الاختبار', 'sequenceCode' => 'BT', 'defaultForPurchaseTransfers' => true], $c['sourceMeta'], 'what OPS has no rule for is kept as Odoo has it');
        $this->assertSame([['finance', true, 10, true], ['gm', true, 11, true], [null, false, 12, true]], array_map(fn ($a) => [$a['user']['username'], $a['user']['linked'], $a['sequence'], $a['required']], $c['approvers']));
        $this->assertSame('المدير المالي الأعلى', $c['approvers'][2]['user']['nameAr'], 'somebody with no OPS user is kept by name');
        $this->assertSame(['counts' => ['requests' => 4, 'pending' => 0]], array_intersect_key($c, ['counts' => 1]));
        $trip = ApprovalCategory::where('code', 'OD-1')->firstOrFail();
        $this->assertSame([false, 'required', 'approver'], [(bool) $trip->active, $trip->fields['period'], $trip->source_meta['managerApproval']]);
        $this->assertSame(['طلب مرتجع', 'إرجاع بضاعة من عميل', 'parallel', 2], array_values(ApprovalCategory::where('code', 'OD-11')->firstOrFail()->only(['name_ar', 'description', 'approval_mode', 'min_approvals'])));

        // ── an approved request: every value in its place, the people recognised, the dates Odoo's
        $r = $this->show('proc', 2);
        $this->assertSame(['APR-OD-00002', 'طلب تحويل P02977 - شركة المراعي', 'approved', 'OD-5', '3953.36', 'SAR', null, '2025-06-30', 'P02977', "طلب موافقة لأمر الشراء P02977\nعاجل", 'sequence', 3, 'odoo', '2', true],
            [$r['number'], $r['title'], $r['status'], $r['category']['code'], $r['amount'], $r['currency'], $r['quantity'], $r['date'], $r['reference'], $r['reason'], $r['approvalMode'], $r['minApprovals'],
                $r['source'], $r['externalRef'], $r['owned']]);
        $this->assertSame(['proc', true, true], [$r['requester']['username'], $r['requester']['linked'], $r['my']['isRequester']], 'the Odoo user whose login is an OPS e-mail is that OPS user');
        $this->assertSame(['supplier', $this->supplier->id, $this->supplier->code, 'شركة المراعي'], [$r['partner']['type'], $r['partner']['id'], $r['partner']['code'], $r['partner']['name']], 'the partner is the OPS supplier the master data linked');
        $this->assertSame(['2025-06-30T07:40:55', '2025-06-30T07:40:57', '2025-06-30T08:41:34'], [substr($r['createdAt'], 0, 19), substr($r['submittedAt'], 0, 19), substr($r['decidedAt'], 0, 19)]);
        $this->assertEquals(['id' => 2977, 'name' => 'P02977 (108339)'], $r['sourceMeta']['purchaseOrder']);
        $this->assertSame([['finance', 'approved', '2025-06-30T07:41:46'], ['gm', 'approved', '2025-06-30T07:50:00'], [null, 'approved', '2025-06-30T08:41:33']],
            array_map(fn ($a) => [$a['user']['username'], $a['status'], substr((string) $a['decidedAt'], 0, 19)], $r['approvers']));
        $this->assertSame(['approved' => 3, 'needed' => 3, 'total' => 3], $r['progress']);
        $this->assertSame([['created', 'proc'], ['submitted', 'proc'], ['approved', 'finance'], ['approved', 'gm'], ['approved', null]], array_map(fn ($e) => [$e['kind'], $e['by']['username']], $r['timeline']));
        $this->assertSame(['odoo'], array_values(array_unique(array_column($r['timeline'], 'source'))));
        $this->assertSame([['receipt.jpg', 'image/jpeg', 2048, 'odoo', false, false]], array_map(fn ($a) => [$a['fileName'], $a['mime'], $a['size'], $a['source'], $a['available'], $a['canRemove']], $r['attachments']),
            'the files Odoo lists are listed; a link and the file of another document are not');

        // ── a refusal: the line Odoo marked "refused" in the refuser's name is not a decision of its approver
        $refused = $this->show('proc', 10);
        $this->assertSame(['refused', ['finance' => 'approved', 'gm' => 'refused', 'المدير المالي الأعلى' => 'waiting']], [$refused['status'], $this->lines($refused)]);
        $this->assertSame(['refused', 'waiting'], [ApprovalApprover::where('external_ref', '22')->value('source_status'), ApprovalApprover::where('external_ref', '22')->value('status')], 'Odoo\'s own word is kept beside it');
        $this->assertSame([['approved', 'finance'], ['refused', 'gm']], array_map(fn ($e) => [$e['kind'], $e['by']['username']], array_slice($refused['timeline'], 2)), 'one refusal, by the one who refused');
        // …and a refusal its refuser took back, which Odoo leaves on the line after him: that approver did not refuse,
        // the request is refused as Odoo holds it, and the timeline says who wrote the refusal and where Odoo kept it
        $leftover = $this->show('proc', 60);
        $this->assertSame(['refused', ['finance' => 'pending', 'gm' => 'waiting']], [$leftover['status'], $this->lines($leftover)]);
        $told = collect($leftover['timeline'])->last();
        $this->assertSame(['refused', 'finance', 'المدير', 1], [$told['kind'], $told['by']['username'], $told['meta']['recordedOn'], collect($leftover['timeline'])->where('kind', 'refused')->count()]);
        $this->assertSame('pending', $this->show('finance', 60)['my']['lineStatus']);

        // ── a request that waits, with a product line and a customer
        $waiting = $this->show('finance', 20);
        $this->assertSame(['pending', ['المدير المالي الأعلى' => 'approved', 'finance' => 'pending'], 'customer', $this->customer->id, 'parallel', 2],
            [$waiting['status'], $this->lines($waiting), $waiting['partner']['type'], $waiting['partner']['id'], $waiting['approvalMode'], $waiting['minApprovals']]);
        $this->assertSame([['[P00963] أكواب سوداء', '5.000', 'Units', 'Riyadh warehouse', $this->product->sku]],
            array_map(fn ($i) => [$i['description'], $i['quantity'], $i['uom'], $i['warehouse'], $i['product']['sku']], $waiting['items']));
        // ── cancelled, raised by somebody OPS does not know; and a draft
        $cancelled = $this->show('admin', 30);
        $this->assertSame(['cancelled', null, false, 'المدير المالي الأعلى', 'المدير المالي الأعلى', 'other', null, 'عميل غير مربوط'],
            [$cancelled['status'], $cancelled['requester']['id'], $cancelled['requester']['linked'], $cancelled['requester']['nameAr'], $cancelled['cancelledBy'], $cancelled['partner']['type'], $cancelled['partner']['id'], $cancelled['partner']['name']]);
        $this->assertSame(['created', 'submitted', 'cancelled'], array_column($cancelled['timeline'], 'kind'));
        $this->assertSame(['cancelled'], array_values(array_unique($this->lines($cancelled))));
        $draft = $this->show('proc', 40);
        $this->assertSame(['draft', '120.50', null, ['created']], [$draft['status'], $draft['amount'], $draft['submittedAt'], array_column($draft['timeline'], 'kind')]);

        // ── the lists: Odoo's history sits with the OPS requests, in everybody's own scope
        $numbers = fn (string $user, string $query) => array_column($this->expectOk($this->getAs($user, self::API."/requests?{$query}"))['items'], 'number');
        $this->assertSame(['APR-OD-00060', 'APR-OD-00040', 'APR-OD-00020', 'APR-OD-00010', 'APR-OD-00002'], $numbers('proc', 'scope=mine&source=odoo&sort=number&order=desc'));
        $this->assertSame(['APR-OD-00020'], $numbers('finance', 'scope=waiting'), 'a line that is asked on a request that is closed waits for nobody');
        $this->assertSame(['APR-OD-00002', 'APR-OD-00010', 'APR-OD-00060'], $numbers('gm', 'scope=involved&sort=number&order=asc'));
        $this->assertCount(6, $numbers('admin', 'scope=all&source=odoo'));
        $this->assertSame([], $numbers('admin', 'scope=all&source=ops'));
        $summary = $this->expectOk($this->getAs('finance', self::API.'/summary'));
        $this->assertSame([1, true, true], [$summary['waiting'], $summary['odooOwns'], $summary['hasOdoo']]);
        // one import line per request in the audit trail, in the name of the service user
        $imports = AuditLog::where('action', 'APPROVAL.IMPORT')->whereIn('entity_id', ApprovalRequest::pluck('id')->all());
        $this->assertSame([6, ['svc.odoo']], [$imports->count(), $imports->distinct()->pluck('username')->all()]);

        // ── again: nothing new, nothing changed, nothing told twice
        $events = ApprovalEvent::count();
        $audits = AuditLog::count();
        $again = $projection->apply();
        $this->assertEquals(['category.unchanged' => 3, 'categoryApprover.unchanged' => 5, 'request.unchanged' => 6, 'approver.unchanged' => 13, 'item.unchanged' => 1, 'attachment.unchanged' => 1,
            'people.matched' => 3, 'people.keptByName' => 1], $again['counts']);
        $this->assertSame([$events, $audits, 6, 13, 1, 1], [ApprovalEvent::count(), AuditLog::count(), ApprovalRequest::count(), ApprovalApprover::count(), ApprovalItem::count(), ApprovalAttachment::count()]);
        $this->assertEquals($again['counts'], $projection->plan()['counts']);
    }

    public function test_what_came_from_odoo_is_shown_and_not_changed_while_odoo_owns_it_and_ops_requests_work_fully(): void
    {
        $this->projection()->apply();
        $pending = $this->ops(20)->number;
        $draft = $this->ops(40)->number;
        $approved = $this->ops(2)->number;
        $cancelled = $this->ops(30)->number;

        // the approver sees the request that waits for him in Odoo, and why he cannot decide it here
        $asFinance = $this->expectOk($this->getAs('finance', self::API."/requests/{$pending}"));
        $this->assertSame([true, false, false, false, 'APPROVAL_OWNED_BY_SOURCE', true, true],
            [$asFinance['owned'], $asFinance['can']['approve'], $asFinance['can']['withdraw'], $asFinance['can']['cancel'], $asFinance['decisionBlock']['code'], $asFinance['can']['comment'], $asFinance['can']['attach']]);
        $refusals = [
            $this->postAs('finance', self::API."/requests/{$pending}/approve"),
            $this->postAs('finance', self::API."/requests/{$pending}/refuse", ['comment' => 'لا أوافق']),
            $this->postAs('finance', self::API."/requests/{$approved}/withdraw"),
            $this->patchAs('proc', self::API."/requests/{$draft}", ['amount' => 1]),
            $this->postAs('proc', self::API."/requests/{$draft}/submit"),
            $this->postAs('proc', self::API."/requests/{$pending}/cancel", ['reason' => 'لم تعد مطلوبة']),
            $this->postAs('admin', self::API."/requests/{$cancelled}/reopen"),
            $this->postAs('admin', self::API."/requests/{$pending}/approvers", ['user' => 'wm']),
            $this->postAs('admin', self::API."/requests/{$pending}/approvers/{$asFinance['approvers'][1]['id']}/remove"),
        ];
        foreach ($refusals as $refusal) {
            $body = $this->expectRejected($refusal, 'APPROVAL_OWNED_BY_SOURCE', [422]);
            $this->assertStringContainsString('أودو', $body['message']);
        }
        $this->expectRejected($this->patchAs('admin', self::API.'/categories/OD-11', ['minApprovals' => 1]), 'APPROVAL_CATEGORY_OWNED_BY_SOURCE', [422]);
        $this->assertSame(['pending', 2], [$this->ops(20)->status, (int) ApprovalCategory::where('code', 'OD-11')->value('min_approvals')], 'nothing changed');

        // what is OPS's own can be added: a comment and a file — and the next load from Odoo leaves them alone
        $this->expectOk($this->postAs('proc', self::API."/requests/{$pending}/comments", ['text' => 'بانتظار الاستلام في المستودع']));
        $with = $this->expectOk($this->postAs('proc', self::API."/requests/{$approved}/attachments", ['fileName' => 'محضر.png', 'mime' => 'image/png', 'base64' => self::proofImage()]));
        $this->assertSame([['receipt.jpg', 'odoo', false], ['محضر.png', null, false]], array_map(fn ($a) => [$a['fileName'], $a['source'], $a['canRemove']], $with['attachments']),
            'a file is evidence once the request was sent: neither Odoo\'s nor the requester\'s own is his to remove');
        $this->expectRejected($this->postAs('admin', self::API."/requests/{$approved}/attachments/{$with['attachments'][0]['id']}/remove"), 'APPROVAL_OWNED_BY_SOURCE', [422]);
        $again = $this->projection()->apply();
        $this->assertSame([6, 1], [$again['counts']['request.unchanged'], $again['counts']['attachment.unchanged']]);
        $after = $this->show('proc', 2);
        $this->assertSame(['receipt.jpg', 'محضر.png'], array_column(array_filter($after['attachments'], fn ($a) => ! $a['removed']), 'fileName'));
        $this->assertSame('comment', collect($this->show('proc', 20)['timeline'])->last()['kind']);

        // a request raised in OPS is OPS's, also with a category of Odoo: it is asked of the approvers who are OPS users
        $own = $this->expectOk($this->postAs('sales', self::API.'/requests', ['category' => 'OD-11', 'partnerType' => 'customer', 'partner' => $this->customer->code, 'reference' => 'SO-77', 'submit' => true]));
        $this->assertSame([null, false, 'pending', ['finance' => 'pending'], true], [$own['source'], $own['owned'], $own['status'], $this->lines($own), $own['can']['cancel']],
            'the approver who has no OPS user and is not required is left out');
        $this->assertMatchesRegularExpression('/^APR-\d{4}-\d{5}$/', $own['number']);
        $this->assertSame('approved', $this->expectOk($this->postAs('finance', self::API."/requests/{$own['number']}/approve"))['status']);
        // …but not when a REQUIRED approver of the category has no OPS user yet
        $this->expectRejected($this->postAs('sales', self::API.'/requests', ['category' => 'OD-5', 'amount' => 500, 'paymentMethod' => 'bank', 'submit' => true]), 'APPROVAL_APPROVER_UNMATCHED', [422]);
        // a category made in OPS is edited in OPS while Odoo's stay Odoo's
        $mine = $this->expectOk($this->postAs('admin', self::API.'/categories', ['nameAr' => 'نوع محلي '.self::uid(), 'approvers' => [['user' => 'finance']]]));
        $this->assertFalse($this->expectOk($this->patchAs('admin', self::API."/categories/{$mine['code']}", ['description' => 'من العمليات']))['owned']);
    }

    public function test_what_changes_in_odoo_reaches_the_ops_records_through_the_feed(): void
    {
        $projection = $this->projection();
        $projection->apply();
        $this->assertSame(['changes' => 0, 'counts' => []], $projection->consume(), 'a quiet feed costs nothing');
        $history = fn (int $id) => StatusHistory::where('entity_type', 'ApprovalRequest')->where('entity_id', $this->ops($id)->id)->orderBy('at')->orderBy('id')->get()->map(fn ($h) => [$h->from_status, $h->to_status, $h->username])->all();

        // ── finance approves in Odoo: the line, then the request
        $this->odooWrites('approval.approver', 30, ['status' => 'approved', 'write_uid' => [12, 'مدير المالية في أودو'], 'write_date' => '2025-07-05 09:00:00']);
        $this->odooWrites('approval.request', 20, ['request_status' => 'approved', 'write_uid' => [12, 'مدير المالية في أودو'], 'write_date' => '2025-07-05 09:00:01']);
        $run = $projection->consume();
        $this->assertSame([2, 1, 1], [$run['changes'], $run['counts']['approver.updated'], $run['counts']['request.updated']]);
        $this->assertArrayNotHasKey('failed', $run);
        $done = $this->show('finance', 20);
        $this->assertSame(['approved', '2025-07-05T09:00:01', ['المدير المالي الأعلى' => 'approved', 'finance' => 'approved'], '2025-07-05T09:00:00'],
            [$done['status'], substr($done['decidedAt'], 0, 19), $this->lines($done), substr($done['approvers'][1]['decidedAt'], 0, 19)]);
        $last = collect($done['timeline'])->last();
        $this->assertSame(['approved', 'finance', 'odoo'], [$last['kind'], $last['by']['username'], $last['source']]);
        $this->assertSame([['pending', 'approved', 'svc.odoo']], $history(20));
        $this->assertSame('svc.odoo', AuditLog::where('action', 'APPROVAL.APPROVE')->where('entity_id', $this->ops(20)->id)->value('username'));
        $this->assertSame(0, Notification::where('entity_type', 'ApprovalRequest')->count(), 'Odoo told its own users');
        $this->assertSame(0, OdooChange::whereNull('processed_at')->count());
        $this->assertSame(0, $projection->consume()['changes']);

        // ── a new request arrives, its lines before it in the feed: nothing is skipped, nothing is made twice
        $this->request(50, 11, 'pending', ['name' => 'R00050', 'partner_id' => [494, 'مطعم الواحة'], 'reference' => 'S09001', 'create_date' => '2025-07-06 08:00:00', 'date_confirmed' => '2025-07-06 08:00:05',
            'write_date' => '2025-07-06 08:00:05']);
        $this->line(60, 50, 6, 'pending', 10, 27, '2025-07-06 08:00:05', false);
        $this->line(61, 50, 12, 'pending', 11, 27, '2025-07-06 08:00:05');
        $this->mirror('approval.product.line', 2, ['approval_request_id' => [50, 'R00050'], 'product_id' => false, 'description' => 'كراتين تالفة', 'quantity' => 12.5, 'product_uom_id' => [1, 'Units']]);
        foreach ([['approval.approver', 60], ['approval.approver', 61], ['approval.product.line', 2], ['approval.request', 50]] as [$model, $id]) {
            OdooChange::create(['model' => $model, 'odoo_id' => $id, 'kind' => 'created', 'seen_at' => now()]);
        }
        $run = $projection->consume();
        $this->assertSame([4, 1, 2, 1], [$run['changes'], $run['counts']['request.created'], $run['counts']['approver.created'], $run['counts']['item.created']]);
        $this->assertArrayNotHasKey('approver.skippedNoRequest', $run['counts']);
        $new = $this->show('finance', 50);
        $this->assertSame(['pending', ['المدير المالي الأعلى' => 'pending', 'finance' => 'pending'], [['كراتين تالفة', '12.500']], ['created', 'submitted']],
            [$new['status'], $this->lines($new), array_map(fn ($i) => [$i['description'], $i['quantity']], $new['items']), array_column($new['timeline'], 'kind')]);
        $this->assertSame([1, 2], [ApprovalRequest::where('external_ref', '50')->count(), ApprovalApprover::where('request_id', $new['id'])->count()]);

        // ── Odoo takes a line off the request, drops the product line, and a file is attached
        $this->odooWrites('approval.approver', 60, [], 'deleted');
        $this->odooWrites('approval.product.line', 2, [], 'deleted');
        $this->mirror('ir.attachment', 12000, ['name' => 'صورة التالف.jpg', 'mimetype' => 'image/jpeg', 'file_size' => 900, 'type' => 'binary', 'res_model' => 'approval.request', 'res_id' => 50]);
        $this->odooWrites('approval.request', 50, ['message_main_attachment_id' => [12000, 'صورة التالف.jpg'], 'write_date' => '2025-07-06 08:10:00']);
        $run = $projection->consume();
        $this->assertSame([1, 1, 1, 1], [$run['counts']['approver.updated'], $run['counts']['item.removed'], $run['counts']['attachment.created'], $run['counts']['request.updated']]);
        $new = $this->show('finance', 50);
        $this->assertSame([['المدير المالي الأعلى' => 'removed', 'finance' => 'pending'], [], ['صورة التالف.jpg']], [$this->lines($new), $new['items'], array_column($new['attachments'], 'fileName')]);
        // the file disappears from Odoo: it stays listed here as removed, never deleted
        OdooRecord::where('model', 'ir.attachment')->where('odoo_id', 12000)->update(['deleted_at' => now()]);
        $this->odooWrites('approval.request', 50, ['write_date' => '2025-07-06 08:20:00']);
        $this->assertSame(1, $projection->consume()['counts']['attachment.removed']);
        $this->assertSame([true, 'Odoo'], [$this->show('finance', 50)['attachments'][0]['removed'], $this->show('finance', 50)['attachments'][0]['removedBy']]);

        // ── an approver takes his approval back in Odoo, then refuses: both are told, in order
        $this->odooWrites('approval.approver', 5, ['status' => 'pending', 'write_date' => '2025-07-07 10:00:00']);
        $this->odooWrites('approval.request', 2, ['request_status' => 'pending', 'write_date' => '2025-07-07 10:00:01']);
        $projection->consume();
        $this->odooWrites('approval.approver', 5, ['status' => 'refused', 'write_date' => '2025-07-07 11:00:00']);
        $this->odooWrites('approval.request', 2, ['request_status' => 'refused', 'write_date' => '2025-07-07 11:00:01']);
        $projection->consume();
        $reversed = $this->show('proc', 2);
        $this->assertSame(['refused', 'refused', ['withdrawn', 'refused']], [$reversed['status'], $reversed['approvers'][0]['status'], array_slice(array_column($reversed['timeline'], 'kind'), -2)]);
        $this->assertSame([['approved', 'pending', 'svc.odoo'], ['pending', 'refused', 'svc.odoo']], $history(2));

        // ── the category changes in Odoo, one of its approvers is dropped, and it is archived
        $this->odooWrites('approval.category', 5, ['name' => 'طلب صرف Payment Application', 'approval_minimum' => 2, 'active' => false, 'write_date' => '2025-07-08 08:00:00']);
        $this->odooWrites('approval.category.approver', 4, [], 'deleted');
        $run = $projection->consume();
        $this->assertSame([1, 1, 4], [$run['counts']['category.updated'], $run['counts']['categoryApprover.removed'], $run['counts']['request.ruleFollowed']]);
        $this->assertSame(2, (int) $this->ops(2)->min_approvals, 'Odoo reads the rule of a request from its category: its requests follow the category here too');
        $category = ApprovalCategory::where('code', 'OD-5')->firstOrFail();
        $this->assertSame(['طلب صرف', 'Payment Application', 2, false, 2], [$category->name_ar, $category->name_en, $category->min_approvals, (bool) $category->active, ApprovalCategoryApprover::where('category_id', $category->id)->count()]);
        $this->assertSame('svc.odoo', AuditLog::where('action', 'APPROVAL_CATEGORY.UPDATE')->where('entity_id', $category->id)->value('username'));

        // ── a request deleted in Odoo is history here: closed, with the reason, never deleted
        $this->odooWrites('approval.request', 40, [], 'deleted');
        $projection->consume();
        $gone = $this->show('proc', 40);
        $this->assertSame(['cancelled', 'حُذف الطلب من أودو', 'Odoo', true], [$gone['status'], $gone['cancelReason'], $gone['cancelledBy'], $gone['sourceMeta']['deleted']]);

        // ── a record that cannot be applied is counted and reported, and does not hold the others back
        $this->request(70, 99, 'pending', ['name' => 'بلا نوع']);
        OdooChange::create(['model' => 'approval.request', 'odoo_id' => 70, 'kind' => 'created', 'seen_at' => now()]);
        $this->odooWrites('approval.approver', 61, ['status' => 'approved', 'write_uid' => [12, 'مدير المالية في أودو'], 'write_date' => '2025-07-09 09:00:00']);
        $run = $projection->consume();
        $this->assertSame([2, 1, 1, 1], [$run['changes'], $run['failed'], $run['counts']['failed'], $run['counts']['approver.updated']]);
        $this->assertStringContainsString('approval.request #70', $run['notes']['failed'][0]);
        $this->assertSame([0, 0], [ApprovalRequest::where('external_ref', '70')->count(), OdooChange::whereNull('processed_at')->count()], 'nothing is left to block the next run');
        $this->assertSame('approved', ApprovalApprover::where('external_ref', '61')->value('status'));

        // a whole pass afterwards agrees with what the feed built, record by record
        $whole = $projection->plan()['counts'];
        $this->assertSame([], array_filter($whole, fn ($n, $what) => str_ends_with($what, '.created') || str_ends_with($what, '.updated') || str_ends_with($what, '.removed'), ARRAY_FILTER_USE_BOTH));
    }

    public function test_after_the_handover_everything_is_ops_and_a_late_load_overwrites_nothing(): void
    {
        $projection = $this->projection();
        $projection->apply();
        config(['odoo.project.approvals' => false]); // the area is handed over to OPS
        $this->assertFalse(OdooProjection::owns('approvals'));

        // the request that waited for finance in Odoo is decided here now, and it is told like any OPS request
        $pending = $this->show('finance', 20);
        $this->assertSame([false, true, null], [$pending['owned'], $pending['can']['approve'], $pending['decisionBlock']]);
        $done = $this->expectOk($this->postAs('finance', self::API."/requests/{$pending['number']}/approve", ['comment' => 'اعتُمد من نظام العمليات']));
        $this->assertSame(['approved', ['approved' => 2, 'needed' => 2, 'total' => 2]], [$done['status'], $done['progress']]);
        $this->assertCount(1, Notification::where('user_id', User::where('username', 'proc')->value('id'))->where('entity_id', $done['id'])->get(), 'the requester is told');
        // a category of Odoo is edited here; a draft of Odoo is corrected and sent here
        $this->assertSame(1, $this->expectOk($this->patchAs('admin', self::API.'/categories/OD-11', ['minApprovals' => 1]))['minApprovals']);
        $draft = $this->ops(40)->number;
        $this->assertSame('150.00', $this->expectOk($this->patchAs('proc', self::API."/requests/{$draft}", ['amount' => 150, 'paymentMethod' => 'bank']))['amount']);
        // …its category asks a required approver who has no OPS user yet: it says so instead of sending to nobody
        $this->expectRejected($this->postAs('proc', self::API."/requests/{$draft}/submit"), 'APPROVAL_APPROVER_UNMATCHED', [422]);

        // the day that person gets an OPS account with his e-mail, he IS the person of the history — nothing to migrate
        $cfo = User::create(['username' => 'cfo.person', 'email' => 'CFO@Example.test', 'password_hash' => Hash::make((string) env('SEED_PASSWORD')), 'name_ar' => 'سامي المدير المالي', 'name_en' => 'Sami CFO', 'active' => true]);
        UserRole::create(['user_id' => $cfo->id, 'role_id' => Role::where('key', 'finance')->value('id')]);
        $numbers = fn (string $query) => array_column($this->expectOk($this->getAs('cfo.person', self::API."/requests?{$query}"))['items'], 'number');
        $this->assertSame(['APR-OD-00002', 'APR-OD-00010', 'APR-OD-00020', 'APR-OD-00030'], $numbers('scope=involved&sort=number&order=asc'), 'every request of the history he was an approver of');
        $this->assertSame(['APR-OD-00030'], $numbers('scope=mine'), 'the request he raised in Odoo');
        $this->assertTrue($this->show('cfo.person', 30)['my']['isRequester']);
        $sent = $this->expectOk($this->postAs('proc', self::API."/requests/{$draft}/submit"));
        $this->assertSame(['pending', ['finance' => 'pending', 'gm' => 'waiting', 'cfo.person' => 'waiting']], [$sent['status'], $this->lines($sent)], 'the category\'s approvers, one after the other');
        $this->expectOk($this->postAs('finance', self::API."/requests/{$draft}/approve"));
        $this->expectOk($this->postAs('gm', self::API."/requests/{$draft}/approve"));
        $this->assertSame(['APR-OD-00040'], $numbers('scope=waiting'));
        $this->assertSame('approved', $this->expectOk($this->postAs('cfo.person', self::API."/requests/{$draft}/approve"))['status']);
        // a file of Odoo is the manager's to take off now
        $file = $this->show('admin', 2)['attachments'][0];
        $this->assertTrue($file['canRemove']);

        // somebody runs the load again, and Odoo has moved on meanwhile: what OPS holds stays; only what is missing is added
        $this->mirror('approval.request', 20, ['request_status' => 'refused', 'write_date' => '2025-08-01 08:00:00'] + (array) OdooRecord::where('model', 'approval.request')->where('odoo_id', 20)->first()->data);
        $this->request(80, 11, 'approved', ['name' => 'R00080', 'partner_id' => [494, 'مطعم الواحة'], 'reference' => 'S09500']);
        $this->line(90, 80, 12, 'approved', 11);
        $late = $projection->apply();
        $this->assertSame([6, 1, 13, 1, 3], [$late['counts']['request.kept'], $late['counts']['request.created'], $late['counts']['approver.kept'], $late['counts']['approver.created'], $late['counts']['category.kept']]);
        $this->assertArrayNotHasKey('request.updated', $late['counts']);
        $this->assertSame(['approved', 'approved', 1, 'approved'], [$this->ops(20)->status, $this->ops(40)->status, (int) ApprovalCategory::where('code', 'OD-11')->value('min_approvals'), $this->ops(80)->status]);
    }

    public function test_people_are_recognised_by_identity_only_and_an_account_opened_later_is_linked_by_the_next_load(): void
    {
        // two OPS users carry what one Odoo user would be recognised by: nobody guesses between them
        User::where('username', 'gm')->update(['email' => null]);
        $a = User::create(['username' => 'twin.a', 'email' => 'cfo@example.test', 'password_hash' => 'x', 'name_ar' => 'أ', 'name_en' => 'A', 'active' => true]);
        $b = User::create(['username' => 'cfo@example.test', 'email' => null, 'password_hash' => 'x', 'name_ar' => 'ب', 'name_en' => 'B', 'active' => true]);
        $projection = $this->projection();
        $first = $projection->apply();
        $this->assertSame([2, 2], [$first['counts']['people.matched'], $first['counts']['people.keptByName']], 'the e-mail of the contact is gone and one login is carried by two users');
        $this->assertSame([null, 'cfo@example.test', null, 'boss'], [
            ApprovalApprover::where('external_ref', '7')->value('user_id'), ApprovalApprover::where('external_ref', '7')->value('user_login'),
            ApprovalApprover::where('external_ref', '6')->value('user_id'), ApprovalApprover::where('external_ref', '6')->value('user_login'),
        ]);
        $b->delete();
        // one of them is left: he reads his history at once (by his e-mail), and the next load writes the link down
        UserRole::create(['user_id' => $a->id, 'role_id' => Role::where('key', 'finance')->value('id')]);
        $a->update(['password_hash' => Hash::make((string) env('SEED_PASSWORD'))]);
        $this->assertContains('APR-OD-00002', array_column($this->expectOk($this->getAs('twin.a', self::API.'/requests?scope=involved'))['items'], 'number'));
        User::where('username', 'gm')->update(['email' => 'gm.office@example.test']);
        $second = $projection->apply();
        $this->assertSame([4, 7, 3, 1], [$second['counts']['people.matched'], $second['counts']['approver.updated'], $second['counts']['categoryApprover.updated'], $second['counts']['request.updated']],
            'every line of the two people, on requests and on categories, and the request one of them raised now carry the OPS user');
        $this->assertArrayNotHasKey('people.keptByName', $second['counts']);
        $this->assertSame([$a->id, (string) User::where('username', 'gm')->value('id'), $a->id], [
            ApprovalApprover::where('external_ref', '7')->value('user_id'), ApprovalApprover::where('external_ref', '6')->value('user_id'), $this->ops(30)->requester_id,
        ]);
        $this->assertSame('twin.a', $this->show('admin', 30)['requester']['username']);
    }

    public function test_a_file_of_odoo_opens_once_the_odoo_file_archive_keeps_its_content(): void
    {
        if (! Schema::hasTable('odoo_files')) {
            $this->markTestSkipped('the Odoo file archive (odoo_files) is not installed here');
        }
        $this->projection()->apply();
        $r = $this->show('proc', 2);
        $file = $r['attachments'][0];
        $get = fn (string $user) => $this->get(self::API."/requests/{$r['number']}/attachments/{$file['id']}", ['Authorization' => 'Bearer '.$this->token($user), 'Accept' => 'application/json']);
        $this->assertFalse($file['available']);
        $this->expectRejected($get('proc'), 'ODOO_FILE_NOT_ARCHIVED', [404]);

        // the backup keeps the content (scm:odoo-files): the same row of the request now opens it
        Storage::fake('local');
        $bytes = "\xFF\xD8\xFF\xE0 a picture of a receipt";
        Storage::disk('local')->put('odoo-files/820/11820', $bytes);
        $row = ['attachment_id' => 11820, 'res_model' => 'approval.request', 'res_id' => 2, 'name' => 'receipt.jpg', 'mimetype' => 'image/jpeg', 'size' => strlen($bytes), 'checksum' => sha1($bytes),
            'status' => 'stored', 'disk' => 'local', 'path' => 'odoo-files/820/11820', 'stored_at' => now()];
        DB::table('odoo_files')->where('attachment_id', 11820)->delete();
        DB::table('odoo_files')->insert(array_intersect_key($row, array_flip(Schema::getColumnListing('odoo_files'))));
        $this->assertTrue($this->show('proc', 2)['attachments'][0]['available']);
        $served = $get('proc');
        $served->assertOk();
        $this->assertSame([$bytes, 'image/jpeg'], [$served->streamedContent(), $served->headers->get('Content-Type')]);
        $this->expectRejected($get('sales'), 'APPROVAL_NOT_VISIBLE', [403]);
        // the file is gone from the disk: said plainly, not a broken download
        Storage::disk('local')->delete('odoo-files/820/11820');
        $this->expectRejected($get('proc'), 'ODOO_FILE_MISSING', [404]);
        DB::table('odoo_files')->where('attachment_id', 11820)->delete();
    }

    public function test_the_load_waits_for_a_complete_copy(): void
    {
        OdooModel::where('model', 'approval.approver')->update(['full_state' => 'running']);
        $projection = $this->projection();
        $this->assertFalse($projection->ready());
        try {
            $projection->plan();
            $this->fail('a plan on an incomplete copy must be refused');
        } catch (AppError $e) {
            $this->assertSame('ODOO_COPY_INCOMPLETE', $e->errorCode);
        }
        $this->assertSame(0, ApprovalRequest::count());
    }
}
