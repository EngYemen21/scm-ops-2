<?php

namespace Tests\Feature\Tax;

use App\Finance\Models\CustomerInvoice;
use App\Tax\Models\Einvoice;
use App\Tax\Models\EinvoiceUnit;
use App\Tax\Services\EinvoiceService;
use App\Tax\Zatca\Codes;
use App\Tax\Zatca\InvoiceHash;
use App\Tax\Zatca\Qr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\ApiTestCase;

/**
 * E-invoicing as it runs by default — NOT onboarded: the UBL XML, the counter and the previous-hash chain, the SHA-256
 * hash and the QR code with its five basic fields are made for every customer invoice and credit note, and nothing
 * leaves the building (Http::fake records every attempt; none is made). The counter is gap-free and the chain holds
 * when several processes post at the same moment.
 */
class EinvoiceChainTest extends ApiTestCase
{
    use TaxFixtures;

    private static string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        self::$tag ??= self::uid();
        Http::fake();
        Http::preventStrayRequests();
    }

    private function xpath(string $xml): \DOMXPath
    {
        return InvoiceHash::xpath(InvoiceHash::load($xml));
    }

    private function xml(string $id): string
    {
        $res = $this->get("/api/tax/einvoices/{$id}/xml", ['Authorization' => 'Bearer '.$this->token('finance')]);
        $res->assertOk();

        return (string) $res->getContent();
    }

    public function test_not_onboarded_the_documents_are_made_chained_and_kept_and_nothing_is_sent(): void
    {
        $tag = self::$tag;
        $c = $this->customer("E{$tag}", ['vat_no' => '300011112222003', 'address' => 'شارع التحلية']);
        $inv = $this->customerInvoice($c, '2026-09-10', 10, '100.00');

        // who may: reading needs page.tax, making needs einvoice.manage
        $this->expectRejected($this->getAs('sales', '/api/tax/einvoices'), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('gm', '/api/tax/einvoices/make', ['invoice' => $inv->number]), 'FORBIDDEN', [403]);

        // the taxpayer is not complete yet: nothing can be made, and the screen says what is missing
        DB::table('tax_profile')->update(['vat_no' => null]);
        $this->expectRejected($this->postAs('finance', '/api/tax/einvoices/make', ['invoice' => $inv->number]), 'EINVOICE_SELLER_PROFILE', [422]);
        $this->assertNull(app(EinvoiceService::class)->issueFor($inv), 'the posting of an invoice is never stopped by its e-invoice');
        $summary = $this->expectOk($this->getAs('finance', '/api/tax/einvoices/summary'));
        $this->assertFalse($summary['profileReady']);
        $this->assertContains('vatNo', array_column($summary['profileMissing'], 'field'));
        $this->expectRejected($this->patchAs('finance', '/api/tax/profile', ['vatNo' => '123456789012345']), 'TAX_PROFILE_INVALID', [400]);
        $this->completeProfile();

        $e = $this->expectOk($this->postAs('finance', '/api/tax/einvoices/make', ['invoice' => $inv->number]));
        $this->assertSame(['not_sent', false, true, 'standard', '388', '0100000', '1150.00', '150.00'], [$e['status'], $e['signed'], $e['notOnboarded'], $e['profile'], $e['typeCode'], $e['transactionCode'], $e['total'], $e['vat']]);
        $this->assertFalse($e['can']['queue']);
        // made once: asking again gives the same document
        $this->assertSame($e['id'], $this->expectOk($this->postAs('finance', '/api/tax/einvoices/make', ['invoice' => $inv->id]))['id']);

        // the XML: what the standard asks of a tax invoice, the counter, the previous hash, and the hash of its canonical form
        $xml = $this->xml($e['id']);
        $x = $this->xpath($xml);
        $v = fn (string $q) => trim((string) $x->query($q)->item(0)?->textContent);
        $this->assertSame('reporting:1.0', $v('/inv:Invoice/cbc:ProfileID'));
        $this->assertSame($inv->number, $v('/inv:Invoice/cbc:ID'));
        $this->assertSame($e['uuid'], $v('/inv:Invoice/cbc:UUID'));
        $this->assertSame((string) $e['icv'], $v("//cac:AdditionalDocumentReference[cbc:ID='ICV']/cbc:UUID"));
        $this->assertSame($e['previousHash'], $v("//cac:AdditionalDocumentReference[cbc:ID='PIH']/cac:Attachment/cbc:EmbeddedDocumentBinaryObject"));
        $this->assertSame(['1000.00', '1000.00', '1150.00', '1150.00', '150.00'], [$v('//cac:LegalMonetaryTotal/cbc:LineExtensionAmount'), $v('//cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount'),
            $v('//cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount'), $v('//cac:LegalMonetaryTotal/cbc:PayableAmount'), $v('/inv:Invoice/cac:TaxTotal/cbc:TaxAmount')]);
        $this->assertSame(['S', '15.00'], [$v('//cac:TaxSubtotal/cac:TaxCategory/cbc:ID'), $v('//cac:TaxSubtotal/cac:TaxCategory/cbc:Percent')]);
        $this->assertSame('300011112222003', $v('//cac:AccountingCustomerParty//cac:PartyTaxScheme/cbc:CompanyID'));
        $this->assertSame('399999999900003', $v('//cac:AccountingSupplierParty//cac:PartyTaxScheme/cbc:CompanyID'));
        $this->assertSame(0, $x->query('//ext:UBLExtensions')->length, 'not onboarded: no stamp');
        $this->assertSame($e['hash'], InvoiceHash::base64($xml));
        // the QR code: the five basic fields, as the XML prints them
        $qr = Qr::decode($e['qr']);
        $this->assertSame([1, 2, 3, 4, 5], array_keys($qr));
        $this->assertSame(['شركة العمليات التجريبية', '399999999900003', $v('/inv:Invoice/cbc:IssueDate').'T'.$v('/inv:Invoice/cbc:IssueTime'), '1150.00', '150.00'], array_values($qr));
        $this->assertSame($e['qr'], $v("//cac:AdditionalDocumentReference[cbc:ID='QR']/cac:Attachment/cbc:EmbeddedDocumentBinaryObject"));

        // a credit note names its invoice and its reason, and is of the invoice's kind
        $cn = $this->customerInvoice($c, '2026-09-11', 2, '100.00', 15, $inv, 'مرتجع تالف');
        $n = $this->expectOk($this->postAs('finance', '/api/tax/einvoices/make', ['invoice' => $cn->number]));
        $nx = $this->xpath($this->xml($n['id']));
        $this->assertSame(['381', '0100000', $inv->number, 'مرتجع تالف', '230.00'], [$n['typeCode'], $n['transactionCode'],
            trim((string) $nx->query('//cac:BillingReference/cac:InvoiceDocumentReference/cbc:ID')->item(0)?->textContent),
            trim((string) $nx->query('//cac:PaymentMeans/cbc:InstructionNote')->item(0)?->textContent), $n['total']]);
        $this->assertSame($e['hash'], $n['previousHash'], 'each document carries the hash of the one before it');
        $this->assertSame($e['icv'] + 1, $n['icv']);

        // a sale without VAT needs a code that says why; an export prints the authority's reason and no buyer VAT number
        $export = $this->customerInvoice($c, '2026-09-12', 1, '500.00', 0);
        $this->expectRejected($this->postAs('finance', '/api/tax/einvoices/make', ['invoice' => $export->number]), 'EINVOICE_TAX_CODE_REQUIRED', [422]);
        $pending = $this->expectOk($this->getAs('finance', '/api/tax/einvoices/pending?q='.$export->number));
        $this->assertSame([1, 'EINVOICE_TAX_CODE_REQUIRED'], [$pending['total'], $pending['items'][0]['blocked']['code']]);
        $this->expectOk($this->postAs('finance', "/api/tax/documents/CustomerInvoice/{$export->id}/code", ['codeId' => 'S-EXP0']));
        $ex = $this->expectOk($this->postAs('finance', '/api/tax/einvoices/make', ['invoice' => $export->number]));
        $xx = $this->xpath($this->xml($ex['id']));
        $this->assertSame(['0100100', 'Z', 'VATEX-SA-32', 0], [$ex['transactionCode'], trim($xx->query('//cac:TaxSubtotal/cac:TaxCategory/cbc:ID')->item(0)->textContent),
            trim($xx->query('//cac:TaxSubtotal/cac:TaxCategory/cbc:TaxExemptionReasonCode')->item(0)->textContent), $xx->query('//cac:AccountingCustomerParty//cac:PartyTaxScheme')->length]);

        // a person with no VAT number or registration gets a simplified invoice
        $walkIn = $this->customer("W{$tag}");
        $simple = $this->expectOk($this->postAs('finance', '/api/tax/einvoices/make', ['invoice' => $this->customerInvoice($walkIn, '2026-09-12', 1, '10.00')->number]));
        $this->assertSame(['simplified', '0200000', '388'], [$simple['profile'], $simple['transactionCode'], $simple['typeCode']]);

        // re-made: a NEW document with the next counter; the old one keeps its place in the chain
        $this->expectRejected($this->postAs('finance', "/api/tax/einvoices/{$e['id']}/remake", ['reason' => 'خطأ']), 'REASON_REQUIRED', [400]);
        DB::table('customers')->where('id', $c->id)->update(['address' => 'شارع العليا العام']);
        $again = $this->expectOk($this->postAs('finance', "/api/tax/einvoices/{$e['id']}/remake", ['reason' => 'تصحيح عنوان المشتري']));
        $this->assertSame([2, true], [$again['attemptNo'], $again['isCurrent']]);
        $old = $this->expectOk($this->getAs('finance', "/api/tax/einvoices/{$e['id']}"));
        $this->assertSame(['withdrawn', false, $again['id']], [$old['status'], $old['isCurrent'], $old['supersededById']]);
        $this->expectRejected($this->postAs('finance', "/api/tax/einvoices/{$e['id']}/remake", ['reason' => 'مرة ثانية للقديمة']), 'EINVOICE_NOT_CURRENT', [422]);

        // the register: filters, search, sort
        $list = $this->expectOk($this->getAs('finance', '/api/tax/einvoices?q='.$inv->number.'&current=all&sort=icv&order=asc'));
        $this->assertSame([2, [$e['icv'], $again['icv']]], [$list['total'], array_column($list['items'], 'icv')]);
        $this->assertSame(1, $this->expectOk($this->getAs('finance', '/api/tax/einvoices?q='.$inv->number))['total'], 'by default only the current document of an invoice');
        $this->assertContains($simple['id'], array_column($this->expectOk($this->getAs('finance', '/api/tax/einvoices?profile=simplified&pageSize=200'))['items'], 'id'));

        // nothing is queued or sent while the unit is not onboarded
        $this->expectRejected($this->postAs('finance', "/api/tax/einvoices/{$again['id']}/queue"), 'EINVOICE_NOT_ONBOARDED', [422]);
        $run = $this->expectOk($this->postAs('finance', '/api/tax/einvoices/run'));
        $this->assertSame(0, $run['sent']['sent']);
        $summary = $this->expectOk($this->getAs('finance', '/api/tax/einvoices/summary'));
        $this->assertSame('not_onboarded', $summary['mode']);
        $this->assertSame(0, Einvoice::where('status', 'queued')->count());
        Http::assertNothingSent();

        // the chain proves itself from what is stored — and finds a document whose content was changed afterwards
        $chain = $this->expectOk($this->getAs('finance', '/api/tax/einvoices/chain'));
        $this->assertTrue($chain['ok'], json_encode($chain['breaks']));
        $first = Einvoice::where('unit_id', $chain['unit']['id'])->orderBy('icv')->first();
        $this->assertSame([1, Codes::FIRST_PREVIOUS_HASH], [$first->icv, $first->prev_hash]);
        $row = Einvoice::find($n['id']);
        DB::table('tax_einvoices')->where('id', $row->id)->update(['xml' => str_replace('230.00', '231.00', $row->xml)]);
        $broken = $this->expectOk($this->getAs('finance', '/api/tax/einvoices/chain'));
        $this->assertFalse($broken['ok']);
        $this->assertSame([['icv' => $row->icv, 'kind' => 'content']], array_map(fn ($b) => ['icv' => $b['icv'], 'kind' => $b['kind']], $broken['breaks']));
        DB::table('tax_einvoices')->where('id', $row->id)->update(['xml' => $row->xml]);
    }

    public function test_the_counter_is_gap_free_when_several_processes_post_at_once(): void
    {
        $this->completeProfile();
        $c = $this->customer('P'.self::$tag, ['vat_no' => '300011112222003']);
        $ids = [];
        for ($i = 0; $i < 16; $i++) {
            $ids[] = $this->customerInvoice($c, '2026-09-14', 1, (string) (10 + $i).'.00')->id;
        }
        $unit = app(EinvoiceService::class)->unit();
        $before = (int) EinvoiceUnit::find($unit->id)->icv;
        $env = ['DB_CONNECTION' => config('database.default'), 'DB_DATABASE' => config('database.connections.'.config('database.default').'.database'), 'APP_ENV' => 'testing'] + getenv();
        $start = microtime(true) + 2.5;
        $procs = [];
        foreach (array_chunk($ids, 4) as $k => $chunk) {
            $cmd = array_merge([PHP_BINARY, __DIR__.'/concurrent_make.php', base_path(), (string) $start], $chunk);
            $procs[$k] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$k], base_path(), $env);
        }
        $icvs = [];
        foreach ($procs as $k => $p) {
            $out = stream_get_contents($pipes[$k][1]);
            $err = stream_get_contents($pipes[$k][2]);
            $this->assertSame(0, proc_close($p), "process {$k} failed: {$err}");
            $icvs = array_merge($icvs, (array) json_decode(trim($out), true));
        }
        // four connections, sixteen documents: sixteen different counters, one after the other, no gap
        sort($icvs);
        $this->assertSame(range($before + 1, $before + 16), $icvs);
        $this->assertSame($before + 16, (int) EinvoiceUnit::find($unit->id)->icv);
        foreach ($ids as $id) {
            $this->assertSame(1, Einvoice::where('doc_type', 'CustomerInvoice')->where('doc_id', $id)->where('is_current', true)->count());
        }
        // and every one of them carries the hash of the one before it, whichever process made it
        $rows = Einvoice::where('unit_id', $unit->id)->where('icv', '>', $before)->orderBy('icv')->get(['icv', 'hash', 'prev_hash']);
        $previous = Einvoice::where('unit_id', $unit->id)->where('icv', $before)->value('hash') ?? Codes::FIRST_PREVIOUS_HASH;
        foreach ($rows as $r) {
            $this->assertSame($previous, $r->prev_hash, "icv {$r->icv}");
            $previous = $r->hash;
        }
        $this->assertTrue($this->expectOk($this->getAs('finance', '/api/tax/einvoices/chain'))['ok']);
        $this->assertSame(16, CustomerInvoice::whereIn('id', $ids)->count());
        Http::assertNothingSent();
    }
}
