<?php

namespace Tests\Feature\Crm;

use App\Crm\Models\CrmLead;
use App\Integration\Services\ExternalRefs;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Quotation;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;
use Tests\Feature\Sales\SalesFixtures;

/**
 * What a won opportunity becomes. A customer — and never a second one for a company OPS already has: an existing
 * link first, then the VAT number, otherwise a person says which customer it is or that it is new. And a quotation,
 * which is a document of the sales module made through that module's own service.
 */
class CrmConversionTest extends ApiTestCase
{
    use CrmFixtures, SalesFixtures;

    /** A lead that was won (in the test pipeline). */
    private function wonLead(array $more = [], string $as = 'sales'): array
    {
        $l = $this->newLead($more, $as);

        return $this->expectOk($this->postAs($as, "/api/crm/leads/{$l['id']}/win", ['stageId' => $this->pipeline()['won']]));
    }

    private function customer(array $more = []): Customer
    {
        $tag = self::tag();

        return Customer::create($more + ['code' => "CUS-Q{$tag}", 'name_ar' => "عميل قائم {$tag}", 'name_en' => "Existing customer {$tag}", 'city' => 'الرياض', 'zone' => 'شمال الرياض', 'contact' => '0500000000', 'terms' => 'نقدي / محفظة']);
    }

    public function test_only_a_won_opportunity_becomes_a_customer(): void
    {
        $open = $this->newLead(['companyName' => 'مطعم مفتوح '.self::tag()]);
        $customers = Customer::count();
        $preview = $this->expectOk($this->getAs('gm', "/api/crm/leads/{$open['id']}/customer"));
        $this->assertSame([false, 'CRM_NOT_WON'], [$preview['allowed'], $preview['code']]);
        $this->assertStringContainsString('مفتوحة', $preview['reasonAr']);
        $this->expectRejected($this->postAs('sales', "/api/crm/leads/{$open['id']}/convert", ['confirmNew' => true, 'zone' => 'الرياض']), 'CRM_NOT_WON', [422]);
        $this->expectOk($this->postAs('sales', "/api/crm/leads/{$open['id']}/lose", ['reasonId' => $this->lostReason()]));
        $this->expectRejected($this->postAs('sales', "/api/crm/leads/{$open['id']}/convert", ['confirmNew' => true, 'zone' => 'الرياض']), 'CRM_NOT_WON', [422]);
        $this->assertSame($customers, Customer::count());
        $this->assertNull(CrmLead::findOrFail($open['id'])->customer_id);
    }

    public function test_identity_names_the_customer_a_link_first_then_the_vat_number(): void
    {
        $customers = fn () => Customer::count();

        // ── 1. a link: the lead points at an Odoo partner that IS an OPS customer
        $linked = $this->customer();
        $partner = '77'.self::uid();
        $this->app->make(ExternalRefs::class)->link('odoo', 'customer', $partner, $linked->id, $linked->code);
        $a = $this->wonLead(['companyName' => 'اسم لا يشبه أحدًا '.self::tag()]);
        CrmLead::whereKey($a['id'])->update(['partner_ref' => $partner]); // what the projection of an Odoo lead keeps
        $before = $customers();
        $preview = $this->expectOk($this->getAs('sales', "/api/crm/leads/{$a['id']}/customer"));
        $this->assertSame([true, 'link', $linked->code, []], [$preview['allowed'], $preview['match']['by'], $preview['match']['customer']['code'], $preview['candidates']]);
        $done = $this->expectOk($this->postAs('sales', "/api/crm/leads/{$a['id']}/convert")); // nothing to decide: no body
        $this->assertSame(['link', $linked->code, $linked->code, 'sales'], [$done['conversion']['by'], $done['conversion']['customer']['code'], $done['customer']['code'], $done['convertedBy']]);
        $this->assertSame(['converted', 'link', $linked->code], [$done['events'][0]['kind'], $done['events'][0]['data']['by'], $done['events'][0]['data']['customerCode']]);
        $this->assertSame([false, true], [$done['can']['convert'], $done['can']['quote']]);
        $this->assertSame('sales', AuditLog::where('action', 'LEAD.CONVERT')->where('entity_id', $a['id'])->value('username'));
        // again: it is done already — nothing is created, nothing is written twice
        $again = $this->expectOk($this->postAs('sales', "/api/crm/leads/{$a['id']}/convert", ['confirmNew' => true, 'zone' => 'الرياض']));
        $this->assertSame(['already', $linked->code], [$again['conversion']['by'], $again['conversion']['customer']['code']]);
        $this->assertSame(1, AuditLog::where('action', 'LEAD.CONVERT')->where('entity_id', $a['id'])->count());
        $this->assertSame($before, $customers());

        // ── 2. the VAT number: the customer that carries it is that company, however the number was typed
        $vat = '31'.str_pad(self::uid(), 12, '0', STR_PAD_LEFT).'3';
        $taxed = $this->customer(['vat_no' => $vat]);
        $b = $this->wonLead(['companyName' => 'شركة أخرى تمامًا '.self::tag(), 'vatNo' => substr($vat, 0, 4).'-'.substr($vat, 4, 4).' '.substr($vat, 8)]);
        $preview = $this->expectOk($this->getAs('sales', "/api/crm/leads/{$b['id']}/customer"));
        $this->assertSame(['vat', $taxed->code, $vat], [$preview['match']['by'], $preview['match']['customer']['code'], $preview['vatNo']]);
        // a person who names another customer is told whom the number names
        $other = $this->customer();
        $mismatch = $this->expectRejected($this->postAs('sales', "/api/crm/leads/{$b['id']}/convert", ['customerId' => $other->code]), 'CRM_CUSTOMER_MISMATCH', [422]);
        $this->assertSame(['vat', $taxed->code], [$mismatch['details']['by'], $mismatch['details']['customer']['code']]);
        // "it is new" does not make a second customer for a number OPS already has
        $done = $this->expectOk($this->postAs('sales', "/api/crm/leads/{$b['id']}/convert", ['confirmNew' => true, 'zone' => 'الرياض']));
        $this->assertSame(['vat', $taxed->code], [$done['conversion']['by'], $done['customer']['code']]);
        $this->assertSame($before + 2, $customers(), 'only the two customers the test itself made');

        // ── a VAT number typed in the dialog is checked the same way
        $c = $this->wonLead(['companyName' => 'بلا رقم ضريبي '.self::tag()]);
        $typed = $this->expectOk($this->getAs('sales', "/api/crm/leads/{$c['id']}/customer?vatNo=".rawurlencode($vat)));
        $this->assertSame(['vat', $taxed->code], [$typed['match']['by'], $typed['match']['customer']['code']]);
        $done = $this->expectOk($this->postAs('sales', "/api/crm/leads/{$c['id']}/convert", ['confirmNew' => true, 'vatNo' => $vat, 'zone' => 'الرياض']));
        $this->assertSame(['vat', $taxed->code, $vat], [$done['conversion']['by'], $done['customer']['code'], $done['vatNo']]);
        $this->assertSame($before + 2, $customers());
    }

    public function test_without_identity_a_person_says_which_customer_it_is_or_that_it_is_new(): void
    {
        $tag = self::tag();
        $name = "مطعم التوأم {$tag}";
        $phone = '05'.str_pad(self::uid(), 8, '7', STR_PAD_LEFT);
        $twin = $this->customer(['name_ar' => $name, 'contact' => "خالد · {$phone}"]);
        $lead = $this->wonLead(['companyName' => $name, 'contactName' => 'خالد', 'mobile' => $phone, 'city' => 'جدة', 'street' => 'شارع التحلية']);
        $customers = Customer::count();

        // the same name and the same phone are shown — and decide nothing
        $preview = $this->expectOk($this->getAs('sales', "/api/crm/leads/{$lead['id']}/customer"));
        $this->assertNull($preview['match']);
        $this->assertSame([$twin->code, ['sameName', 'samePhone']], [$preview['candidates'][0]['code'], $preview['candidates'][0]['why']]);
        $this->assertSame([$name, 'جدة', "خالد · {$phone}", 'شارع التحلية'], [$preview['suggested']['nameAr'], $preview['suggested']['city'], $preview['suggested']['contact'], $preview['suggested']['address']]);
        $refused = $this->expectRejected($this->postAs('sales', "/api/crm/leads/{$lead['id']}/convert"), 'CRM_CUSTOMER_CONFIRM', [422]);
        $this->assertSame($twin->code, $refused['details']['candidates'][0]['code']);
        $this->assertSame([$customers, null], [Customer::count(), CrmLead::findOrFail($lead['id'])->customer_id]);
        $this->expectRejected($this->postAs('sales', "/api/crm/leads/{$lead['id']}/convert", ['customerId' => 'CUS-NOPE']), 'CUSTOMER_NOT_FOUND', [404]);

        // "it is this existing customer"
        $done = $this->expectOk($this->postAs('sales', "/api/crm/leads/{$lead['id']}/convert", ['customerId' => $twin->code]));
        $this->assertSame(['person', $twin->code], [$done['conversion']['by'], $done['customer']['code']]);
        $this->assertSame($customers, Customer::count(), 'a customer OPS already has is linked, not made again');

        // "it is new": the customers' own service makes it, with the identity the next opportunity is recognised by
        $vat = '30'.str_pad(self::uid(), 12, '0', STR_PAD_LEFT).'3';
        $fresh = $this->wonLead(['companyName' => "كافيه الزاوية {$tag}", 'contactName' => 'منى', 'mobile' => '0559998877', 'city' => 'الرياض']);
        // credit terms need a credit limit — the customers' rule, kept
        $this->expectRejected($this->postAs('sales', "/api/crm/leads/{$fresh['id']}/convert", ['confirmNew' => true, 'zone' => 'العليا', 'terms' => 'آجل 30']), 'CUSTOMER_CREDIT_LIMIT', [422]);
        $this->assertSame([$customers, null], [Customer::count(), CrmLead::findOrFail($fresh['id'])->customer_id]);
        $done = $this->expectOk($this->postAs('sales', "/api/crm/leads/{$fresh['id']}/convert", ['confirmNew' => true, 'zone' => 'العليا', 'vatNo' => $vat, 'cr' => '1010998877', 'terms' => 'آجل 30', 'creditLimit' => 15000]));
        $made = Customer::findOrFail($done['customer']['id']);
        $this->assertSame('new', $done['conversion']['by']);
        $this->assertMatchesRegularExpression('/^CUS-/', $made->code);
        $this->assertSame(["كافيه الزاوية {$tag}", 'الرياض', 'العليا', 'منى · 0559998877', $vat, '1010998877', 'آجل 30', '15000.00', null, true],
            [$made->name_ar, $made->city, $made->zone, $made->contact, $made->vat_no, $made->cr, $made->terms, $made->credit_limit, $made->source_system, (bool) $made->active]);
        $this->assertSame('sales', AuditLog::where('action', 'CUSTOMER.CREATE')->where('entity_id', $made->id)->value('username'));
        $this->assertSame($customers + 1, Customer::count());

        // the next opportunity of the same company finds it by its VAT number: never a duplicate
        $next = $this->wonLead(['companyName' => 'اسم مكتوب بشكل آخر '.self::tag(), 'vatNo' => $vat]);
        $done = $this->expectOk($this->postAs('sales', "/api/crm/leads/{$next['id']}/convert", ['confirmNew' => true, 'zone' => 'العليا']));
        $this->assertSame(['vat', $made->code], [$done['conversion']['by'], $done['customer']['code']]);
        $this->assertSame($customers + 1, Customer::count());

        // an archived customer is not given new business silently
        $archived = $this->customer(['active' => false]);
        $stuck = $this->wonLead(['companyName' => 'عميل موقوف '.self::tag()]);
        $this->expectRejected($this->postAs('sales', "/api/crm/leads/{$stuck['id']}/convert", ['customerId' => $archived->code]), 'CUSTOMER_INACTIVE', [422]);
    }

    public function test_several_customers_with_one_vat_number_are_a_persons_choice(): void
    {
        $vat = '32'.str_pad(self::uid(), 12, '0', STR_PAD_LEFT).'3';
        $north = $this->customer(['vat_no' => $vat]);
        $south = $this->customer(['vat_no' => substr($vat, 0, 5).' '.substr($vat, 5)]); // the same number, typed with a space
        $lead = $this->wonLead(['companyName' => 'فرع جديد '.self::tag(), 'vatNo' => $vat]);

        $preview = $this->expectOk($this->getAs('sales', "/api/crm/leads/{$lead['id']}/customer"));
        $this->assertNull($preview['match'], 'two branches carry the number: it names neither');
        $this->assertEqualsCanonicalizing([$north->code, $south->code], array_column(array_slice($preview['candidates'], 0, 2), 'code'));
        $this->assertSame(['sameVat'], $preview['candidates'][0]['why']);
        $this->expectRejected($this->postAs('sales', "/api/crm/leads/{$lead['id']}/convert"), 'CRM_CUSTOMER_CONFIRM', [422]);
        $done = $this->expectOk($this->postAs('sales', "/api/crm/leads/{$lead['id']}/convert", ['customerId' => $south->id]));
        $this->assertSame(['person', $south->code], [$done['conversion']['by'], $done['customer']['code']]);
    }

    public function test_a_new_customer_needs_customer_manage_and_linking_one_does_not(): void
    {
        $rep = $this->pipelineOnlyUser(); // page.crm + crm.manage only
        $lead = $this->wonLead(['companyName' => 'مطعم بلا صلاحية '.self::tag()], $rep);
        $customers = Customer::count();

        $this->expectRejected($this->postAs($rep, "/api/crm/leads/{$lead['id']}/convert", ['confirmNew' => true, 'zone' => 'الرياض']), 'FORBIDDEN', [403]);
        $this->assertSame([$customers, null], [Customer::count(), CrmLead::findOrFail($lead['id'])->customer_id]);
        $denied = AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->where('username', $rep)->orderByDesc('at')->orderByDesc('id')->firstOrFail();
        $this->assertSame('denied: customer.manage', $denied->new_value);

        $existing = $this->customer();
        $done = $this->expectOk($this->postAs($rep, "/api/crm/leads/{$lead['id']}/convert", ['customerId' => $existing->code]));
        $this->assertSame(['person', $existing->code], [$done['conversion']['by'], $done['customer']['code']]);
    }

    public function test_a_quotation_is_made_through_the_sales_module_or_linked_to_the_lead(): void
    {
        $customer = $this->makeCustomer(50000);
        $product = $this->makeProduct();
        $valid = now()->addDays(14)->toDateString();
        $line = ['sku' => $product->sku, 'qty' => 10, 'price' => 25];

        // a quotation is made for a customer
        $bare = $this->newLead();
        $this->assertFalse($bare['can']['quote']);
        $this->expectRejected($this->postAs('sales', "/api/crm/leads/{$bare['id']}/quotations", ['validUntil' => $valid, 'lines' => [$line]]), 'CRM_NO_CUSTOMER', [422]);

        // an opportunity with an existing customer is quoted while it is still open
        $lead = $this->newLead(['customerId' => $customer->code, 'name' => 'توريد شهري '.self::tag()]);
        $this->assertSame([$customer->code, true], [$lead['customer']['code'], $lead['can']['quote']]);
        $url = "/api/crm/leads/{$lead['id']}/quotations";
        $this->expectRejected($this->postAs('sales', $url, ['validUntil' => $valid]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', $url, ['validUntil' => $valid, 'lines' => [['sku' => $product->sku, 'qty' => 0, 'price' => 25]]]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', $url, ['validUntil' => now()->subDay()->toDateString(), 'lines' => [$line]]), 'VALID_PAST', [400]);
        $this->expectRejected($this->postAs('sales', $url, ['validUntil' => $valid, 'lines' => [['sku' => 'NO-SUCH-SKU', 'qty' => 1, 'price' => 5]]]), 'SKU_NOT_FOUND', [400]);
        $this->assertSame(0, DB::table('crm_lead_quotations')->where('lead_id', $lead['id'])->count(), 'a refused quotation leaves nothing behind');

        $quoted = $this->expectOk($this->postAs('sales', $url, ['validUntil' => $valid, 'terms' => 'آجل 30', 'lines' => [$line]]));
        $this->assertCount(1, $quoted['quotations']);
        $q = $quoted['quotations'][0];
        $this->assertMatchesRegularExpression('/^QT-/', $q['number']);
        $this->assertSame(['draft', 1, 'sales'], [$q['status'], $q['lines'], $q['linkedBy']]);
        $this->assertEqualsWithDelta(10 * 25 * 1.15, $q['totals']['total'], 0.001, 'the figures of the sales module, VAT included');
        $row = Quotation::where('number', $q['number'])->firstOrFail();
        $this->assertSame([$customer->id, 'sales'], [$row->customer_id, $row->created_by]);
        $this->assertStringContainsString($lead['number'], (string) $row->notes, 'the quotation says which opportunity it answers');
        $this->assertSame(['quotation', $q['number'], 'created'], [$quoted['events'][0]['kind'], $quoted['events'][0]['data']['number'], $quoted['events'][0]['data']['action']]);
        $this->assertSame($q['number'], $this->expectOk($this->getAs('sales', "/api/sales/quotations/{$q['number']}"))['number'], 'it is an ordinary quotation of the sales screens');

        // one made on the sales screen is linked by its number — once
        $made = $this->expectOk($this->postAs('sales', '/api/sales/quotations', ['customerCode' => $customer->code, 'validUntil' => $valid, 'lines' => [$line]]));
        $this->expectRejected($this->postAs('sales', $url, ['number' => 'QT-NOPE']), 'QT_NOT_FOUND', [404]);
        $linked = $this->expectOk($this->postAs('sales', $url, ['number' => $made['number']]));
        $this->assertSame([$made['number'], $q['number']], array_column($linked['quotations'], 'number'), 'newest first');
        $this->expectOk($this->postAs('sales', $url, ['number' => $made['number']]));
        $this->assertSame(2, DB::table('crm_lead_quotations')->where('lead_id', $lead['id'])->count());
        // … not to a second opportunity, and not across customers
        $second = $this->newLead(['customerId' => $customer->code]);
        $this->expectRejected($this->postAs('sales', "/api/crm/leads/{$second['id']}/quotations", ['number' => $made['number']]), 'CRM_QUOTATION_LINKED', [422]);
        $stranger = $this->newLead(['customerId' => $this->makeCustomer()->code]);
        $foreign = $this->expectOk($this->postAs('sales', '/api/sales/quotations', ['customerCode' => $customer->code, 'validUntil' => $valid, 'lines' => [$line]]));
        $this->expectRejected($this->postAs('sales', "/api/crm/leads/{$stranger['id']}/quotations", ['number' => $foreign['number']]), 'CRM_QUOTATION_CUSTOMER', [422]);

        // the customer of an opportunity that has quotations is not swapped under them
        $this->expectRejected($this->patchAs('sales', "/api/crm/leads/{$lead['id']}", ['customerId' => $this->makeCustomer()->code]), 'CRM_CUSTOMER_LOCKED', [422]);

        // a new quotation needs sales.manage; linking does not
        $rep = $this->pipelineOnlyUser();
        $this->expectRejected($this->postAs($rep, "/api/crm/leads/{$second['id']}/quotations", ['validUntil' => $valid, 'lines' => [$line]]), 'FORBIDDEN', [403]);
        $this->assertSame(1, count($this->expectOk($this->postAs($rep, "/api/crm/leads/{$second['id']}/quotations", ['number' => $foreign['number']]))['quotations']));

        // a wrong link is taken off — the quotation itself stays the sales module's
        $off = $this->expectOk($this->postAs('sales', "{$url}/{$made['number']}/unlink"));
        $this->assertSame([$q['number']], array_column($off['quotations'], 'number'));
        $this->assertSame(['quotation', 'unlinked'], [$off['events'][0]['kind'], $off['events'][0]['data']['action']]);
        $this->expectRejected($this->postAs('sales', "{$url}/{$made['number']}/unlink"), 'CRM_QUOTATION_NOT_LINKED', [422]);
        $this->assertNotNull(Quotation::where('number', $made['number'])->first());

        // a lost opportunity is not quoted
        $this->expectOk($this->postAs('sales', "/api/crm/leads/{$lead['id']}/lose", ['reasonId' => $this->lostReason()]));
        $this->expectRejected($this->postAs('sales', $url, ['validUntil' => $valid, 'lines' => [$line]]), 'CRM_LEAD_LOST', [422]);
    }
}
