<?php

namespace Tests\Feature\Tax;

use App\Models\AuditLog;
use App\Tax\Models\TaxCode;
use App\Tax\Models\TaxParty;
use App\Tax\Support\Money;
use Tests\ApiTestCase;

/**
 * The dictionary and the settings of the section: tax codes (kept consistent: use + kind → the lines of the return
 * they may feed, the rate of their kind, the authority's exemption reason, one default per use and rate), groups,
 * fiscal positions, the taxpayer and the tax details of customers — with permissions, validation and the audit trail.
 */
class TaxDictionaryTest extends ApiTestCase
{
    use TaxFixtures;

    public function test_tax_codes_are_kept_consistent_and_never_deleted(): void
    {
        $tag = self::uid();
        $lookups = $this->expectOk($this->getAs('finance', '/api/tax/lookups'));
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16], array_column($lookups['boxes'], 'box'));
        $this->assertContains('VATEX-SA-32', array_column($lookups['exemptions'], 'code'));
        $this->expectRejected($this->postAs('gm', '/api/tax/codes', ['code' => "X{$tag}", 'nameAr' => 'x', 'use' => 'sale', 'kind' => 'standard', 'rate' => 15]), 'FORBIDDEN', [403]);
        $this->assertSame(0, TaxCode::where('code', "X{$tag}")->count());

        $bad = fn (array $body, string $code) => $this->expectRejected($this->postAs('finance', '/api/tax/codes', $body + ['code' => "C{$tag}", 'nameAr' => 'رمز اختبار']), $code, [400]);
        $bad(['use' => 'sale', 'kind' => 'standard', 'rate' => 0], 'TAX_CODE_RATE');
        $bad(['use' => 'sale', 'kind' => 'exempt', 'rate' => 5], 'TAX_CODE_RATE');
        $bad(['use' => 'sale', 'kind' => 'import', 'rate' => 15], 'TAX_CODE_KIND');
        $bad(['use' => 'sale', 'kind' => 'standard', 'rate' => 15, 'returnBox' => 7], 'TAX_CODE_BOX');
        $bad(['use' => 'sale', 'kind' => 'zero', 'rate' => 0], 'TAX_CODE_REASON_REQUIRED');
        $bad(['use' => 'sale', 'kind' => 'zero', 'rate' => 0, 'exemptionReasonCode' => 'VATEX-SA-29'], 'TAX_CODE_REASON');
        $bad(['use' => 'sale', 'kind' => 'standard', 'rate' => 15, 'code' => 'bad code!'], 'TAX_CODE_CODE');

        $med = $this->expectOk($this->postAs('finance', '/api/tax/codes', ['code' => "z-med{$tag}", 'nameAr' => 'أدوية بنسبة صفرية', 'use' => 'sale', 'kind' => 'zero', 'rate' => 0, 'exemptionReasonCode' => 'VATEX-SA-35']));
        $this->assertSame([strtoupper("z-med{$tag}"), 3, 'Z', 'Medicines and medical equipment', 'الأدوية والمعدات الطبية'], [$med['code'], $med['returnBox'], $med['categoryCode'], $med['exemptionReasonEn'], $med['exemptionReasonAr']]);
        $this->expectRejected($this->postAs('finance', '/api/tax/codes', ['code' => "Z-MED{$tag}", 'nameAr' => 'مكرر', 'use' => 'sale', 'kind' => 'zero', 'rate' => 0, 'exemptionReasonCode' => 'VATEX-SA-35']), 'TAX_CODE_EXISTS', [409]);
        $this->assertTrue(AuditLog::where('action', 'TAX.CODE.CREATE')->where('entity_id', $med['id'])->exists());

        // one default per use and rate: a new default takes the place of the old one
        $other = $this->expectOk($this->postAs('finance', '/api/tax/codes', ['code' => "S15B{$tag}", 'nameAr' => 'مبيعات 15 بديلة', 'use' => 'sale', 'kind' => 'standard', 'rate' => 15, 'isDefault' => true]));
        $this->assertTrue($other['isDefault']);
        $this->assertSame(1, TaxCode::where('use', 'sale')->where('is_default', true)->get()->filter(fn ($c) => Money::rate($c->rate) === '15')->count());
        $this->assertFalse((bool) TaxCode::where('code', 'S-STD15')->value('is_default'));
        $this->expectOk($this->patchAs('finance', '/api/tax/codes/S-STD15', ['isDefault' => true]));
        $this->assertFalse((bool) TaxCode::where('id', $other['id'])->value('is_default'));
        $this->expectRejected($this->patchAs('finance', '/api/tax/codes/P-RC15', ['isDefault' => true]), 'TAX_CODE_DEFAULT_KIND', [422]);
        // archived, never deleted; an archived code is nobody's default
        $arch = $this->expectOk($this->patchAs('finance', "/api/tax/codes/{$other['id']}", ['active' => false]));
        $this->assertSame([false, false], [$arch['active'], $arch['isDefault']]);
        $this->expectRejected($this->patchAs('finance', "/api/tax/codes/{$other['id']}", ['isDefault' => true]), 'TAX_CODE_ARCHIVED', [422]);
        // the list: filters, search, sort, paging
        $list = $this->expectOk($this->getAs('finance', "/api/tax/codes?q={$tag}&sort=code&order=asc"));
        $this->assertSame([strtoupper("S15B{$tag}"), strtoupper("Z-MED{$tag}")], array_column($list['items'], 'code'));
        $this->assertSame(1, $this->expectOk($this->getAs('finance', "/api/tax/codes?q={$tag}&active=false"))['total']);
        $this->assertSame(1, $this->expectOk($this->getAs('finance', "/api/tax/codes?q={$tag}&box=3"))['total']);
    }

    public function test_groups_positions_the_taxpayer_and_the_parties(): void
    {
        $tag = self::uid();
        $g = $this->expectOk($this->postAs('finance', '/api/tax/groups', ['nameAr' => "مجموعة {$tag}"]));
        $code = $this->expectOk($this->postAs('finance', '/api/tax/codes', ['code' => "G{$tag}", 'nameAr' => 'رمز في مجموعة', 'use' => 'purchase', 'kind' => 'standard', 'rate' => 15, 'groupId' => $g['id']]));
        $this->expectRejected($this->patchAs('finance', "/api/tax/groups/{$g['id']}", ['active' => false]), 'TAX_GROUP_IN_USE', [422]);

        $p = $this->expectOk($this->postAs('finance', '/api/tax/positions', ['nameAr' => "خارج المملكة {$tag}", 'countryCode' => 'ae', 'lines' => [['fromCodeId' => $code['id'], 'toCodeId' => TaxCode::where('code', 'P-ZERO0')->value('id')]]]));
        $this->assertSame(['AE', 1], [$p['countryCode'], count($p['lines'])]);
        $this->expectRejected($this->patchAs('finance', "/api/tax/positions/{$p['id']}", ['lines' => [['fromCodeId' => $code['id'], 'toCodeId' => TaxCode::where('code', 'S-STD15')->value('id')]]]), 'TAX_POSITION_LINE', [400]);

        // the taxpayer: either manager keeps it; the rules of the authority on its fields
        $this->expectRejected($this->patchAs('gm', '/api/tax/profile', ['city' => 'جدة']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->patchAs('finance', '/api/tax/profile', ['buildingNo' => '12']), 'TAX_PROFILE_INVALID', [400]);
        $this->expectRejected($this->patchAs('finance', '/api/tax/profile', ['postalCode' => '1234']), 'TAX_PROFILE_INVALID', [400]);
        $profile = $this->expectOk($this->patchAs('finance', '/api/tax/profile', ['legalNameAr' => 'شركة العمليات التجريبية', 'vatNo' => '399999999900003', 'idScheme' => 'CRN', 'idValue' => '1010010000',
            'street' => 'طريق الملك فهد', 'buildingNo' => '2322', 'district' => 'العليا', 'city' => 'الرياض', 'postalCode' => '12345', 'filingFrequency' => 'monthly']));
        $this->assertSame([[], true, 'monthly'], [$profile['missing'], $profile['canIssue'], $profile['filingFrequency']]);

        // a customer's tax details: national address, identification, fiscal position, invoice kind
        $c = $this->customer("D{$tag}");
        $this->expectRejected($this->patchAs('finance', "/api/tax/parties/customer/{$c->id}", ['idScheme' => 'NAT']), 'TAX_PARTY_INVALID', [400]);
        $this->expectRejected($this->patchAs('finance', "/api/tax/parties/customer/{$c->id}", ['defaultCodeId' => $code['id']]), 'TAX_PARTY_INVALID', [400]);
        $party = $this->expectOk($this->patchAs('finance', "/api/tax/parties/customer/{$c->code}", ['street' => 'شارع الثلاثين', 'buildingNo' => '4321', 'district' => 'الروضة', 'city' => 'جدة',
            'postalCode' => '23435', 'invoiceKind' => 'simplified', 'fiscalPositionId' => $p['id']]));
        $this->assertSame(['simplified', $p['id'], true, 7], [$party['invoiceKind'], $party['fiscalPositionId'], $party['hasDetails'], $party['changed']]);
        $this->assertSame(1, TaxParty::where('partner_id', $c->id)->count());
        $list = $this->expectOk($this->getAs('finance', "/api/tax/parties?type=customer&q={$c->code}&state=complete"));
        $this->assertSame([1, 'جدة'], [$list['total'], $list['items'][0]['city']]);
        $this->assertSame(0, $this->expectOk($this->getAs('finance', "/api/tax/parties?type=customer&q={$c->code}&state=incomplete"))['total']);

        $overview = $this->expectOk($this->getAs('gm', '/api/tax/overview'));
        $this->assertSame('not_onboarded', $overview['einvoicing']['mode']);
        $this->assertArrayHasKey('suggest', $overview['returns']);
        $this->expectRejected($this->getAs('sales', '/api/tax/overview'), 'FORBIDDEN', [403]);
    }
}
