<?php

namespace Tests\Feature\Tax;

use App\Models\AuditLog;
use App\Models\StatusHistory;
use App\Tax\Models\TaxCode;
use App\Tax\Models\VatReturn;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/**
 * The VAT return of a period, figure by figure, against numbers worked out by hand (period June 2025, monthly):
 *
 *   invoice        1,000.00 at 15 %                  line 1   amount 1,000.00   VAT 150.00
 *   credit note      200.00 of that invoice          line 1   adjustment −200.00  VAT −30.00
 *   export           500.00 at 0 % (own export code) line 4   amount 500.00
 *   supplier bill    400.00 at 15 %                  line 7   amount 400.00     VAT 60.00
 *   import service   300.00, reverse charge 15 %     line 9   amount 300.00     VAT 45.00 (due and deductible)
 *   payment to the authority 70.00 on the output VAT account: no document — explained by a person
 *
 *   output VAT = 120 + 45 = 165.00 · input VAT = 60 + 45 = 105.00 · due = 60.00 · ledger output = 150 − 30 − 70 = 50.00
 */
class VatReturnTest extends ApiTestCase
{
    use TaxFixtures;

    private static string $tag;

    private static ?string $returnId = null;

    private static array $docs = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::$tag ??= self::uid();
    }

    public function test_the_books_of_june_become_the_form_with_every_figure_explained(): void
    {
        $tag = self::$tag;
        $c = $this->customer("R{$tag}", ['vat_no' => '300011112222003']);
        $s = $this->supplier("R{$tag}");
        $abroad = $this->supplier("RA{$tag}");
        $inv = $this->customerInvoice($c, '2025-06-05', 10, '100.00');
        $cn = $this->customerInvoice($c, '2025-06-12', 2, '100.00', 15, $inv, 'مرتجع جزئي');
        $export = $this->customerInvoice($c, '2025-06-15', 5, '100.00', 0);
        $bill = $this->supplierInvoice($s, '2025-06-08', '400.00');
        $rc = $this->supplierInvoice($abroad, '2025-06-20', '300.00', 0);
        $paymentLine = $this->vatPayment('2025-06-25', '70.00');
        self::$docs = ['inv' => $inv->id, 'cn' => $cn->id, 'export' => $export->id, 'bill' => $bill, 'rc' => $rc, 'payment' => $paymentLine];

        // a user without the section sees nothing of it
        $this->expectRejected($this->getAs('sales', '/api/tax/returns'), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('sales', '/api/tax/returns', ['frequency' => 'monthly', 'year' => 2025, 'period' => 6]), 'FORBIDDEN', [403]);
        $this->assertSame(0, VatReturn::where('period_code', '2025-06')->count());

        $r = $this->expectOk($this->postAs('finance', '/api/tax/returns', ['frequency' => 'monthly', 'year' => 2025, 'period' => 6]));
        self::$returnId = $r['id'];
        $this->assertSame(['2025-06', 'draft', '2025-06-01', '2025-06-30'], [$r['periodCode'], $r['status'], $r['periodFrom'], $r['periodTo']]);
        // a second return of the same period is refused
        $this->expectRejected($this->postAs('finance', '/api/tax/returns', ['frequency' => 'quarterly', 'year' => 2025, 'period' => 2]), 'VAT_RETURN_EXISTS', [409]);

        // the export and the import service carry no VAT and no default code says why: they wait for a person, and the
        // return cannot be reviewed
        $this->assertSame(2, $r['unclassified']['count']);
        $this->assertSame('800.00', $r['unclassified']['base']);
        $waiting = $this->expectOk($this->getAs('finance', "/api/tax/returns/{$r['id']}/entries?box=unclassified"));
        $this->assertEqualsCanonicalizing([$export->number, DB::table('supplier_invoices')->where('id', $rc)->value('number')], array_column($waiting['items'], 'docNumber'));
        $blockers = array_column(array_filter($r['checks'], fn ($c) => $c['level'] === 'blocker'), 'code');
        $this->assertContains('VAT_RETURN_UNCLASSIFIED', $blockers);
        $this->assertContains('VAT_RETURN_UNRECONCILED', $blockers);
        $this->assertFalse($r['can']['review']);
        $this->expectRejected($this->postAs('finance', "/api/tax/returns/{$r['id']}/review"), 'VAT_RETURN_UNCLASSIFIED', [422]);

        // a code whose rate does not fit the document is refused; the export code and the reverse-charge code are given
        $this->expectRejected($this->postAs('finance', "/api/tax/documents/CustomerInvoice/{$export->id}/code", ['codeId' => 'S-STD15']), 'TAX_CODE_RATE_MISMATCH', [422]);
        // (codes of this story's own: the dictionary's codes may belong to Odoo when the section follows it)
        $this->expectOk($this->postAs('finance', '/api/tax/codes', ['code' => "VX{$tag}", 'nameAr' => 'صادرات (اختبار الإقرار)', 'use' => 'sale', 'kind' => 'zero', 'rate' => 0, 'returnBox' => 4, 'exemptionReasonCode' => 'VATEX-SA-32']));
        $this->expectOk($this->postAs('finance', '/api/tax/codes', ['code' => "VR{$tag}", 'nameAr' => 'احتساب عكسي (اختبار الإقرار)', 'use' => 'purchase', 'kind' => 'reverse_charge', 'rate' => 15]));
        $this->expectOk($this->postAs('finance', "/api/tax/documents/CustomerInvoice/{$export->id}/code", ['codeId' => "VX{$tag}", 'reason' => 'شحنة إلى الكويت']));
        $doc = $this->expectOk($this->postAs('finance', "/api/tax/documents/SupplierInvoice/{$rc}/code", ['codeId' => "VR{$tag}", 'reason' => 'خدمة من مورد خارج المملكة']));
        $this->assertSame(["VR{$tag}", 'explicit'], [$doc['code']['code'], $doc['how']]);
        $this->assertTrue(AuditLog::where('action', 'TAX.DOCUMENT.CODE')->where('entity_id', $rc)->exists());

        $r = $this->expectOk($this->getAs('finance', "/api/tax/returns/{$r['id']}"));
        $line = fn (int $box) => collect($r['lines'])->firstWhere('box', $box);
        $this->assertSame(['1000.00', '-200.00', '800.00', '120.00', 2], [$line(1)['amount'], $line(1)['adjustment'], $line(1)['total'], $line(1)['vat'], $line(1)['count']]);
        $this->assertSame(['500.00', '0.00', 1], [$line(4)['amount'], $line(4)['vat'], $line(4)['count']]);
        $this->assertSame(['400.00', '60.00'], [$line(7)['amount'], $line(7)['vat']]);
        $this->assertSame(['300.00', '45.00'], [$line(9)['amount'], $line(9)['vat']]);
        $this->assertSame(['1300.00', '120.00'], [$line(6)['total'], $line(6)['vat']], 'line 6 is the sum of the sales lines');
        $this->assertSame(['700.00', '105.00'], [$line(12)['total'], $line(12)['vat']], 'line 12 is the sum of the purchases lines');
        $this->assertSame(['165.00', '105.00', '45.00', '60.00', '0.00', '60.00'],
            [$r['totals']['outputVat'], $r['totals']['inputVat'], $r['totals']['reverseChargeVat'], $r['totals']['vatDuePeriod'], $r['totals']['corrections'], $r['totals']['netDue']]);
        $this->assertSame(0, $r['unclassified']['count']);

        // the ledger: 150 − 30 − 70 = 50 on the output account; the payment is the one movement without a document
        $rec = $r['reconciliation'];
        $this->assertSame(['50.00', '120.00', '-70.00'], [$rec['output']['ledger'], $rec['output']['documents'], $rec['output']['unexplained']]);
        $this->assertSame(['60.00', '60.00', '0.00'], [$rec['input']['ledger'], $rec['input']['documents'], $rec['input']['unexplained']]);
        $this->assertSame('70.00', $rec['unexplained']);
        $this->assertFalse($rec['balanced']);
        $item = collect($rec['items'])->firstWhere('journalLineId', $paymentLine);
        $this->assertNotNull($item);
        $this->assertSame(['output', '-70.00', false], [$item['side'], $item['amount'], $item['explained']]);

        // a person says what it is: a payment to the authority — then everything is explained to the halala
        $this->expectRejected($this->postAs('finance', "/api/tax/returns/{$r['id']}/explanations", ['journalLineId' => $paymentLine, 'treatment' => 'settlement', 'reason' => 'x']), 'REASON_REQUIRED', [400]);
        $r = $this->expectOk($this->postAs('finance', "/api/tax/returns/{$r['id']}/explanations", ['journalLineId' => $paymentLine, 'treatment' => 'settlement', 'reason' => 'سداد ضريبة الربع الأول للهيئة']));
        $this->expectRejected($this->postAs('finance', "/api/tax/returns/{$r['id']}/explanations", ['journalLineId' => $paymentLine, 'treatment' => 'other', 'reason' => 'مرة ثانية للحركة نفسها']), 'VAT_LEDGER_EXPLAINED', [409]);
        $rec = $r['reconciliation'];
        $this->assertSame(['0.00', '-70.00', '45.00', '165.00', true], [$rec['output']['unexplained'], $rec['output']['outside'], $rec['output']['reverseCharge'], $rec['output']['return'], $rec['output']['holds']]);
        $this->assertSame(['105.00', true], [$rec['input']['return'], $rec['input']['holds']]);
        $this->assertTrue($rec['balanced']);
        $this->assertSame('0.00', $rec['unexplained']);
        $this->assertTrue($r['can']['review']);
    }

    public function test_every_figure_opens_on_the_documents_behind_it(): void
    {
        $id = self::$returnId;
        $page = $this->expectOk($this->getAs('finance', "/api/tax/returns/{$id}/entries?box=1"));
        $this->assertSame(2, $page['total']);
        $this->assertSame(['800.00', '120.00'], [$page['totals']['base'], $page['totals']['vat']]);
        $byKind = collect($page['items'])->keyBy('docKind');
        $this->assertSame(['1000.00', '150.00', 'amount'], [$byKind['invoice']['base'], $byKind['invoice']['vat'], $byKind['invoice']['column']]);
        $this->assertSame(['-200.00', '-30.00', 'adjustment'], [$byKind['credit_note']['base'], $byKind['credit_note']['vat'], $byKind['credit_note']['column']]);
        // the credit note follows the decision taken for its invoice, and names it
        $this->assertSame('S-STD15', $byKind['credit_note']['code']);

        $nine = $this->expectOk($this->getAs('finance', "/api/tax/returns/{$id}/entries?box=9"));
        $this->assertSame([1, '300.00', '45.00', true, 'explicit'], [$nine['total'], $nine['items'][0]['base'], $nine['items'][0]['vat'], $nine['items'][0]['notional'], $nine['items'][0]['classified']]);
        $this->assertSame(['1300.00', 3], [$this->expectOk($this->getAs('finance', "/api/tax/returns/{$id}/entries?box=6"))['totals']['base'], $this->expectOk($this->getAs('finance', "/api/tax/returns/{$id}/entries?box=6"))['total']]);
        // search, sort and paging of the documents
        $found = $this->expectOk($this->getAs('finance', "/api/tax/returns/{$id}/entries?q=TCN-"));
        $this->assertSame(1, $found['total']);
        $sorted = $this->expectOk($this->getAs('finance', "/api/tax/returns/{$id}/entries?sort=base&order=desc&pageSize=2&page=1"));
        $this->assertSame(['1000.00', '500.00'], array_column($sorted['items'], 'base'));
        $this->assertSame(3, $sorted['pages']);

        // the export needs report.export (finance has it); its rows are the rows of the figure
        $csv = $this->get("/api/tax/returns/{$id}/entries/export?box=1", ['Authorization' => 'Bearer '.$this->token('finance')]);
        $csv->assertOk();
        $body = $csv->streamedContent();
        $this->assertStringStartsWith("\u{FEFF}date,document", $body);
        $this->assertSame(3, substr_count(trim($body), "\n") + 1, 'a header and the two documents of line 1');
    }

    public function test_adjustments_credit_and_the_way_from_draft_to_locked(): void
    {
        $id = self::$returnId;
        // an adjustment line moves its figure and is voided, never removed
        $this->expectRejected($this->postAs('finance', "/api/tax/returns/{$id}/adjustments", ['box' => 3, 'base' => '10.00', 'vat' => '1.50', 'reason' => 'سطر بلا ضريبة']), 'VAT_ADJUSTMENT_NO_VAT', [400]);
        $this->expectRejected($this->postAs('finance', "/api/tax/returns/{$id}/adjustments", ['box' => 1, 'base' => '10.005', 'reason' => 'ثلاث خانات']), 'AMOUNT_INVALID', [400]);
        $r = $this->expectOk($this->postAs('finance', "/api/tax/returns/{$id}/adjustments", ['box' => 1, 'base' => '100.00', 'vat' => '15.00', 'reason' => 'فاتورة يدوية لم تُسجل']));
        $this->assertSame(['-100.00', '135.00', '180.00', '75.00'], [collect($r['lines'])->firstWhere('box', 1)['adjustment'], collect($r['lines'])->firstWhere('box', 1)['vat'], $r['totals']['outputVat'], $r['totals']['netDue']]);
        // a figure that is no document of the books does not exist in the ledger: the comparison says so
        $this->assertSame('15.00', $r['reconciliation']['output']['adjustments']);
        $this->assertTrue($r['reconciliation']['output']['holds']);
        $adj = $r['adjustments'][0]['id'];
        $r = $this->expectOk($this->postAs('finance', "/api/tax/returns/{$id}/adjustments/{$adj}/void", ['reason' => 'أُدخلت بالخطأ']));
        $this->assertTrue($r['adjustments'][0]['voided']);
        $this->assertSame('60.00', $r['totals']['netDue']);
        // the credit brought forward (line 15)
        $this->expectRejected($this->patchAs('finance', "/api/tax/returns/{$id}", ['creditCarried' => '-5']), 'VAT_CREDIT_NEGATIVE', [400]);
        $r = $this->expectOk($this->patchAs('finance', "/api/tax/returns/{$id}", ['creditCarried' => '10.00', 'notes' => 'رصيد من مايو']));
        $this->assertSame(['10.00', '50.00'], [$r['totals']['creditCarried'], $r['totals']['netDue']]);

        // reviewed: frozen
        $r = $this->expectOk($this->postAs('finance', "/api/tax/returns/{$id}/review", ['note' => 'مطابق']));
        $this->assertSame(['reviewed', 'finance', '50.00'], [$r['status'], $r['reviewedBy'], $r['netDue']]);
        $this->expectRejected($this->postAs('finance', "/api/tax/returns/{$id}/adjustments", ['box' => 1, 'base' => '1.00', 'reason' => 'بعد المراجعة']), 'VAT_RETURN_FROZEN', [422]);
        $this->expectRejected($this->postAs('finance', '/api/tax/documents/CustomerInvoice/'.self::$docs['export'].'/code', ['codeId' => null]), 'VAT_DOCUMENT_DECLARED', [422]);
        $this->expectRejected($this->patchAs('finance', '/api/tax/codes/VX'.self::$tag, ['returnBox' => 3]), 'TAX_CODE_IN_USE', [422]);
        $this->expectRejected($this->postAs('finance', "/api/tax/returns/{$id}/lock"), 'FORBIDDEN', [403]);

        // filing: somebody else, with the authority's reference and a day after the period
        $this->expectRejected($this->postAs('gm', "/api/tax/returns/{$id}/file", ['filedOn' => '2025-07-10']), 'VAT_RETURN_REFERENCE', [400]);
        $this->expectRejected($this->postAs('gm', "/api/tax/returns/{$id}/file", ['reference' => 'ZATCA-1', 'filedOn' => '2025-06-30']), 'VAT_RETURN_FILED_ON', [400]);
        $this->expectRejected($this->postAs('gm', "/api/tax/returns/{$id}/lock"), 'VAT_RETURN_TRANSITION', [422]);
        $r = $this->expectOk($this->postAs('gm', "/api/tax/returns/{$id}/file", ['reference' => 'ZATCA-2025-06-001', 'filedOn' => '2025-07-10']));
        $this->assertSame(['filed', 'ZATCA-2025-06-001', '2025-07-10', 'gm'], [$r['status'], $r['filedReference'], $r['filedOn'], $r['filedBy']]);
        $this->expectRejected($this->postAs('gm', "/api/tax/returns/{$id}/unfile", ['reason' => 'خطأ']), 'REASON_REQUIRED', [400]);
        $r = $this->expectOk($this->postAs('gm', "/api/tax/returns/{$id}/lock"));
        $this->assertSame('locked', $r['status']);
        $this->assertSame([], array_keys(array_filter($r['can'])));
        foreach (['reopen' => 'finance', 'unfile' => 'gm', 'cancel' => 'finance'] as $action => $user) {
            $this->expectRejected($this->postAs($user, "/api/tax/returns/{$id}/{$action}", ['reason' => 'محاولة بعد الإقفال']), 'VAT_RETURN_TRANSITION', [422]);
        }
        $this->assertSame(['draft', 'reviewed', 'filed', 'locked'], StatusHistory::where('entity_type', 'VatReturn')->where('entity_id', $id)->orderBy('at')->orderBy('id')->pluck('to_status')->all());
    }

    public function test_a_document_booked_into_a_declared_period_is_a_correction_of_the_next_return(): void
    {
        $c = $this->customer('L'.self::$tag);
        // booked into June after June was filed and locked
        $late = $this->customerInvoice($c, '2025-06-28', 4, '50.00');
        $june = $this->expectOk($this->getAs('finance', '/api/tax/returns/2025-06'));
        $this->assertSame('120.00', collect($june['lines'])->firstWhere('box', 1)['vat'], 'a locked return keeps what it declared');
        $this->assertTrue($june['drift']['changed']);

        $july = $this->expectOk($this->postAs('finance', '/api/tax/returns', ['frequency' => 'monthly', 'year' => 2025, 'period' => 7]));
        $this->assertSame(['1', '200.00', '30.00', '30.00', '30.00'], [(string) $july['corrections']['late']['count'], $july['corrections']['late']['saleBase'], $july['corrections']['late']['saleVat'],
            $july['corrections']['total'], $july['totals']['netDue']]);
        $rows = $this->expectOk($this->getAs('finance', "/api/tax/returns/{$july['id']}/entries?box=corrections"));
        $this->assertSame([1, $late->number, '2025-06', true], [$rows['total'], $rows['items'][0]['docNumber'], $rows['items'][0]['lateOfPeriod'], $rows['items'][0]['late']]);

        // the period that has not ended cannot be reviewed; a draft made by mistake is cancelled and its period is free again
        $sep = $this->expectOk($this->postAs('finance', '/api/tax/returns', ['frequency' => 'monthly', 'year' => 2026, 'period' => 9]));
        $this->expectRejected($this->postAs('finance', "/api/tax/returns/{$sep['id']}/review"), 'VAT_RETURN_PERIOD_OPEN', [422]);
        $this->expectRejected($this->postAs('finance', "/api/tax/returns/{$sep['id']}/cancel"), 'REASON_REQUIRED', [400]);
        $this->assertSame('cancelled', $this->expectOk($this->postAs('finance', "/api/tax/returns/{$sep['id']}/cancel", ['reason' => 'أُنشئ قبل نهاية الشهر']))['status']);
        $again = $this->expectOk($this->postAs('finance', '/api/tax/returns', ['frequency' => 'monthly', 'year' => 2026, 'period' => 9]));
        $this->assertNotSame($sep['id'], $again['id']);
        $list = $this->expectOk($this->getAs('finance', '/api/tax/returns?year=2025&sort=period&order=asc'));
        $this->assertSame(['2025-06', '2025-07'], array_column($list['items'], 'periodCode'));
        $this->assertSame(1, $this->expectOk($this->getAs('finance', '/api/tax/returns?status=cancelled'))['total']);
        $this->assertNotNull(TaxCode::where('code', 'S-STD15')->value('id'));
    }
}
