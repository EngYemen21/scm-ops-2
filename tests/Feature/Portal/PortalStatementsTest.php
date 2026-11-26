<?php

namespace Tests\Feature\Portal;

use App\Finance\Models\CustomerInvoice;
use App\Finance\Models\Payment;
use App\Models\AuditLog;
use App\Portal\Models\PortalBranch;
use App\Portal\Models\Statement;
use App\Portal\Models\StatementLine;
use Tests\ApiTestCase;

/**
 * Customer statements: worked out from the OPS receivables (invoices, receipts and their cancellations) for a
 * customer or one branch, previewed, issued as a stored snapshot and voided; Odoo's statements kept as Odoo printed
 * them; and the finance tables are only ever read.
 */
class PortalStatementsTest extends ApiTestCase
{
    use PortalFixtures;

    private function preview(string $query, string $as = 'gm'): array
    {
        return $this->expectOk($this->getAs($as, '/api/portal/statements/preview?'.$query));
    }

    public function test_a_statement_is_worked_out_from_the_receivables_issued_and_voided(): void
    {
        $customer = $this->makeCustomer();
        $day = fn (int $ago) => now()->subDays($ago)->toDateString();
        // before the period: an invoice of 1,000, half of it paid
        $old = $this->receivable($customer, 1000, null, $day(40));
        $this->expectOk($this->postAs('finance', '/api/finance/receipts', ['customer' => $customer->code, 'amount' => 400, 'date' => $day(35), 'reference' => 'DEP-OLD', 'allocations' => [['invoice' => $old->number, 'amount' => 400]]]));
        // in the period: two invoices, a receipt, a receipt that bounced and an invoice issued by mistake
        $first = $this->receivable($customer, 300, null, $day(10));
        $second = $this->receivable($customer, 250.5, null, $day(6));
        $paid = $this->expectOk($this->postAs('finance', '/api/finance/receipts', ['customer' => $customer->code, 'amount' => 500, 'date' => $day(8), 'reference' => 'DEP-1', 'allocations' => [['invoice' => $old->number, 'amount' => 500]]]));
        $bounced = $this->expectOk($this->postAs('finance', '/api/finance/receipts', ['customer' => $customer->code, 'amount' => 120, 'date' => $day(5)]));
        $this->expectOk($this->postAs('finance', "/api/finance/payments/{$bounced['number']}/void", ['reason' => 'شيك مرتجع']));
        $mistake = $this->receivable($customer, 75, null, $day(4));
        $this->expectOk($this->postAs('finance', "/api/finance/invoices/{$mistake->number}/cancel", ['reason' => 'صدرت بالخطأ']));
        $touched = [CustomerInvoice::where('customer_id', $customer->id)->count(), Payment::where('partner_id', $customer->id)->count()];

        $query = "customer={$customer->code}&from={$day(20)}&to={$day(0)}";
        $p = $this->preview($query);
        // opening = what was owed before the first day: 1,000 − 400
        $this->assertSame(['600.00', '745.50', '695.00', '650.50', 'SAR', 7], [$p['openingBalance'], $p['totalDebit'], $p['totalCredit'], $p['closingBalance'], $p['currency'], $p['linesCount']]);
        $this->assertSame(['invoice' => 3, 'credit_note' => 0, 'payment' => 2, 'reversal' => 2], $p['counts']);
        $this->assertSame([
            [$day(10), 'invoice', $first->number, '300.00', '0.00', '900.00'],
            [$day(8), 'payment', $paid['number'], '0.00', '500.00', '400.00'],
            [$day(6), 'invoice', $second->number, '250.50', '0.00', '650.50'],
            [$day(5), 'payment', $bounced['number'], '0.00', '120.00', '530.50'],
            [$day(4), 'invoice', $mistake->number, '75.00', '0.00', '605.50'],
            // nothing in the books is deleted: what was cancelled today is a line of today
            [$day(0), 'reversal', $bounced['number'], '120.00', '0.00', '725.50'],
            [$day(0), 'reversal', $mistake->number, '0.00', '75.00', '650.50'],
        ], array_map(fn (array $l) => [$l['date'], $l['kind'], $l['reference'], $l['debit'], $l['credit'], $l['balance']], $p['lines']));
        $this->assertSame(['650.50', true], [$p['books']['closing'], $p['books']['agrees']], 'the statement agrees with the customer\'s account in the general ledger');
        $this->assertSame([false, null, null], [$p['more'], $p['existing'], $p['branch']]);
        // a period that ends before the cancellations still shows what was owed then — and agrees with the books of that day
        $earlier = $this->preview("customer={$customer->code}&from={$day(20)}&to={$day(1)}");
        $this->assertSame(['600.00', '605.50', true, 5], [$earlier['openingBalance'], $earlier['closingBalance'], $earlier['books']['agrees'], $earlier['linesCount']]);
        // … and the next period opens with exactly that
        $this->assertSame('605.50', $this->preview("customer={$customer->code}&from={$day(0)}&to={$day(0)}")['openingBalance']);
        $this->assertSame(0, Statement::where('customer_id', $customer->id)->count(), 'a preview stores nothing');

        // issued: numbered and stored line by line
        $body = ['customer' => $customer->code, 'from' => $day(20), 'to' => $day(0), 'note' => 'كشف شهري'];
        $this->expectRejected($this->postAs('gm', '/api/portal/statements', $body), 'FORBIDDEN', [403]);
        $s = $this->expectOk($this->postAs('sales', '/api/portal/statements', $body));
        $this->assertStringStartsWith('SOA-', $s['number']);
        $this->assertSame(['issued', '600.00', '745.50', '695.00', '650.50', 7, 'sales', '650.50', 'كشف شهري', null, false],
            [$s['status'], $s['openingBalance'], $s['totalDebit'], $s['totalCredit'], $s['closingBalance'], $s['linesCount'], $s['issuedBy'], $s['booksClosing'], $s['note'], $s['sourceSystem'], $s['owned']]);
        $this->assertSame([$customer->code, ['void' => true], ['issued']], [$s['customer']['code'], $s['can'], array_column($s['events'], 'kind')]);
        $this->assertTrue(AuditLog::where('action', 'PORTAL.STATEMENT.ISSUE')->where('entity_id', $s['id'])->where('username', 'sales')->exists());
        $lines = $this->expectOk($this->getAs('gm', "/api/portal/statements/{$s['id']}/lines"));
        $this->assertSame([7, ['debit' => '745.50', 'credit' => '695.00']], [$lines['total'], $lines['totals']]);
        $this->assertSame(array_column($p['lines'], 'balance'), array_column($lines['items'], 'balance'));
        $this->assertSame(['CustomerInvoice', $first->id], [$lines['items'][0]['docType'], $lines['items'][0]['docId']]);
        $payments = $this->expectOk($this->getAs('gm', "/api/portal/statements/{$s['id']}/lines?kind=payment&pageSize=1&page=2"));
        $this->assertSame([[$bounced['number']], 2, 2, ['debit' => '0.00', 'credit' => '620.00']], [array_column($payments['items'], 'reference'), $payments['total'], $payments['pages'], $payments['totals']]);
        $this->assertSame($s['id'], $this->expectOk($this->getAs('gm', "/api/portal/statements/{$s['number']}"))['id']);

        // a snapshot: what the customer was given does not move when the account does
        $this->receivable($customer, 999, null, $day(2));
        $this->assertSame('1649.50', $this->preview($query)['closingBalance']);
        $this->assertSame('650.50', $this->expectOk($this->getAs('gm', "/api/portal/statements/{$s['id']}"))['closingBalance']);
        // the same customer and period stands once; it is replaced by voiding it — never by editing or deleting
        $this->assertSame($s['number'], $this->preview($query)['existing']);
        $twice = $this->expectRejected($this->postAs('sales', '/api/portal/statements', $body), 'PORTAL_STATEMENT_EXISTS', [422]);
        $this->assertSame($s['number'], $twice['details']['number']);
        $this->expectRejected($this->postAs('sales', "/api/portal/statements/{$s['id']}/void"), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('gm', "/api/portal/statements/{$s['id']}/void", ['reason' => 'صدر قبل تسجيل فاتورة']), 'FORBIDDEN', [403]);
        $v = $this->expectOk($this->postAs('sales', "/api/portal/statements/{$s['id']}/void", ['reason' => 'صدر قبل تسجيل فاتورة']));
        $this->assertSame(['void', 'صدر قبل تسجيل فاتورة', 'sales', ['void' => false], 7], [$v['status'], $v['voidReason'], $v['voidedBy'], $v['can'], StatementLine::where('statement_id', $s['id'])->count()]);
        $this->expectRejected($this->postAs('sales', "/api/portal/statements/{$s['id']}/void", ['reason' => 'مرة أخرى']), 'PORTAL_STATEMENT_STATE', [422]);
        $again = $this->expectOk($this->postAs('sales', '/api/portal/statements', $body));
        $this->assertSame(['1649.50', 8], [$again['closingBalance'], $again['linesCount']]);
        $this->assertNotSame($s['number'], $again['number']);

        // the spreadsheet of a statement needs the export permission
        $csv = $this->get("/api/portal/statements/{$again['id']}/export", ['Authorization' => 'Bearer '.$this->token('gm')]);
        $csv->assertOk();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv->getContent());
        $this->assertStringContainsString($first->number, $csv->getContent());
        $this->assertStringContainsString('"1649.50"', $csv->getContent());
        $this->assertSame(12, substr_count(trim($csv->getContent()), "\r\n") + 1, 'a title, a header, the opening balance, eight lines and the totals');
        $this->expectRejected($this->getAs($this->portalOnlyUser(), "/api/portal/statements/{$again['id']}/export"), 'FORBIDDEN', [403]);

        // the receivables were read, never written
        $this->assertSame([$touched[0] + 1, $touched[1]], [CustomerInvoice::where('customer_id', $customer->id)->count(), Payment::where('partner_id', $customer->id)->count()]);
    }

    public function test_a_branch_statement_follows_the_branch_delivery_site(): void
    {
        $customer = $this->makeCustomer();
        $site = $this->makeSite($customer, 'موقع فرع الشمال');
        $north = $this->expectOk($this->postAs('sales', '/api/portal/branches', ['customerId' => $customer->code, 'name' => 'فرع الشمال', 'siteId' => $site->id]));
        $south = $this->expectOk($this->postAs('sales', '/api/portal/branches', ['customerId' => $customer->code, 'name' => 'فرع الجنوب']));
        $day = fn (int $ago) => now()->subDays($ago)->toDateString();
        $toNorth = $this->receivable($customer, 800, $this->makeOrder($customer, $site), $day(9));
        $elsewhere = $this->receivable($customer, 500, $this->makeOrder($customer), $day(9));
        // one receipt of 700: 300 for the branch's invoice, 200 for the other, 200 on account
        $receipt = $this->expectOk($this->postAs('finance', '/api/finance/receipts', ['customer' => $customer->code, 'amount' => 700, 'date' => $day(3),
            'allocations' => [['invoice' => $toNorth->number, 'amount' => 300], ['invoice' => $elsewhere->number, 'amount' => 200]]]));

        $range = "from={$day(15)}&to={$day(0)}";
        $whole = $this->preview("customer={$customer->code}&{$range}");
        $this->assertSame(['1300.00', '700.00', '600.00', true], [$whole['totalDebit'], $whole['totalCredit'], $whole['closingBalance'], $whole['books']['agrees']]);
        $branch = $this->preview("customer={$customer->code}&branch={$north['id']}&{$range}");
        $this->assertSame(['0.00', '800.00', '300.00', '500.00', $north['id']], [$branch['openingBalance'], $branch['totalDebit'], $branch['totalCredit'], $branch['closingBalance'], $branch['branch']['id']]);
        $this->assertSame([['invoice', $toNorth->number], ['payment', $receipt['number']]], array_map(fn (array $l) => [$l['kind'], $l['reference']], $branch['lines']));
        $this->assertStringContainsString($toNorth->number, $branch['lines'][1]['description'], 'a receipt line of a branch says which invoice it settled');
        $this->assertSame(['closing' => null, 'agrees' => null], $branch['books'], 'the ledger keeps no branch: nothing is compared');
        // a later period opens with what the branch still owed
        $this->assertSame('500.00', $this->preview("customer={$customer->code}&branch={$north['id']}&from={$day(1)}&to={$day(0)}")['openingBalance']);

        // a branch that is not tied to a delivery site has no documents of its own — and says so
        $this->expectRejected($this->getAs('gm', "/api/portal/statements/preview?customer={$customer->code}&branch={$south['id']}&{$range}"), 'PORTAL_BRANCH_NO_SITE', [422]);
        $other = $this->expectOk($this->postAs('sales', '/api/portal/branches', ['customerId' => $this->makeCustomer()->code, 'name' => 'فرع عميل آخر']));
        $this->expectRejected($this->getAs('gm', "/api/portal/statements/preview?customer={$customer->code}&branch={$other['id']}&{$range}"), 'PORTAL_BRANCH_CUSTOMER', [422]);

        $s = $this->expectOk($this->postAs('sales', '/api/portal/statements', ['customer' => $customer->code, 'branch' => $north['id'], 'from' => $day(15), 'to' => $day(0)]));
        $this->assertSame([$north['id'], '500.00', null, 2], [$s['branch']['id'], $s['closingBalance'], $s['booksClosing'], $s['linesCount']]);
        $this->assertSame([$north['id']], array_column($s['branches'], 'id'));
        // the customer's own statement of the same period is another document
        $all = $this->expectOk($this->postAs('sales', '/api/portal/statements', ['customer' => $customer->code, 'from' => $day(15), 'to' => $day(0)]));
        $this->assertSame([null, '600.00'], [$all['branch'], $all['closingBalance']]);
        $list = fn (string $q) => array_column($this->expectOk($this->getAs('gm', "/api/portal/statements?customer={$customer->code}&".$q))['items'], 'id');
        $this->assertEqualsCanonicalizing([$s['id'], $all['id']], $list(''));
        $this->assertSame([$s['id']], $list("branch={$north['id']}"));
        $this->assertSame(1, $this->expectOk($this->getAs('gm', "/api/portal/branches/{$north['id']}"))['counts']['statements']);
    }

    public function test_the_rules_of_a_period_and_who_may_ask(): void
    {
        $customer = $this->makeCustomer();
        $today = now()->toDateString();
        $bad = fn (string $query) => $this->getAs('gm', '/api/portal/statements/preview?'.$query);
        $this->expectRejected($bad("customer={$customer->code}&from={$today}"), 'INVALID_INPUT', [400]);
        $this->expectRejected($bad("customer={$customer->code}&from=01/09/2026&to={$today}"), 'INVALID_INPUT', [400]);
        $this->expectRejected($bad("customer={$customer->code}&from={$today}&to=".now()->subDay()->toDateString()), 'PORTAL_PERIOD', [400]);
        $this->expectRejected($bad("customer={$customer->code}&from=".now()->subYears(3)->toDateString()."&to={$today}"), 'PORTAL_PERIOD', [400]);
        $this->expectRejected($bad("customer=CUS-NOBODY&from={$today}&to={$today}"), 'CUSTOMER_NOT_FOUND', [400]);
        $this->expectRejected($this->getAs('wm', "/api/portal/statements/preview?customer={$customer->code}&from={$today}&to={$today}"), 'FORBIDDEN', [403]);
        $this->expectRejected($this->getAs('wm', '/api/portal/statements'), 'FORBIDDEN', [403]);
        // a customer with no document at all still has a statement: an empty one
        $empty = $this->preview("customer={$customer->id}&from={$today}&to={$today}");
        $this->assertSame(['0.00', '0.00', [], true], [$empty['openingBalance'], $empty['closingBalance'], $empty['lines'], $empty['books']['agrees']]);
        $this->expectRejected($this->getAs('gm', '/api/portal/statements/01NOSUCHSTATEMENT0000000000'), 'PORTAL_STATEMENT_NOT_FOUND', [404]);
    }

    public function test_a_statement_of_odoo_is_kept_as_odoo_printed_it(): void
    {
        $tag = self::tag();
        $customer = $this->makeCustomer(['name_ar' => "مطابخ السحاب {$tag}"]);
        $branch = PortalBranch::create(['customer_id' => $customer->id, 'name' => "فرع السحاب {$tag}", 'sequence' => 10, 'active' => true]);
        $s = Statement::create(['number' => 'STMT/2026/'.random_int(1000, 9999), 'status' => 'issued', 'customer_id' => $customer->id, 'customer_ref' => '88', 'customer_name' => 'عميل أودو', 'date_from' => '2026-01-01',
            'date_to' => '2026-03-31', 'opening_balance' => -50.25, 'total_debit' => 900, 'total_credit' => 350, 'closing_balance' => 499.75, 'currency' => 'SAR', 'lines_count' => 3, 'issued_at' => now()->subMonths(5),
            'issued_by' => 'محاسب أودو', 'source_system' => 'odoo', 'external_ref' => (string) random_int(100000, 999999)]);
        foreach ([['2026-01-10', 'invoice', 'INV/2026/00011', 600, 0, 549.75, $branch->id], ['2026-02-02', 'payment', 'PBNK1/2026/00007', 0, 350, 199.75, null], ['2026-03-15', 'invoice', 'INV/2026/00098', 300, 0, 499.75, null]] as $i => [$date, $kind, $ref, $debit, $credit, $balance, $branchId]) {
            StatementLine::create(['statement_id' => $s->id, 'date' => $date, 'seq' => 100 + $i, 'kind' => $kind, 'reference' => $ref, 'description' => 'كما في أودو', 'debit' => $debit, 'credit' => $credit, 'balance' => $balance,
                'branch_id' => $branchId, 'doc_type' => 'account.move', 'doc_id' => (string) (500 + $i), 'source_system' => 'odoo', 'external_ref' => (string) random_int(1000000, 9999999)]);
        }
        // an older statement of a customer OPS does not have yet
        $foreign = Statement::create(['number' => 'STMT/2025/'.random_int(1000, 9999), 'status' => 'issued', 'customer_ref' => '9100', 'customer_name' => "عميل غير مربوط {$tag}", 'date_from' => '2025-06-01', 'date_to' => '2025-06-30',
            'closing_balance' => 10, 'source_system' => 'odoo', 'external_ref' => (string) random_int(100000, 999999)]);

        $this->followOdoo(true);
        $shown = $this->expectOk($this->getAs('gm', "/api/portal/statements/{$s->id}"));
        // Odoo's own figures — a credit balance at the start included — and nothing recomputed
        $this->assertSame(['-50.25', '900.00', '350.00', '499.75', 'odoo', true, ['void' => false]], [$shown['openingBalance'], $shown['totalDebit'], $shown['totalCredit'], $shown['closingBalance'], $shown['sourceSystem'], $shown['owned'], $shown['can']]);
        $this->assertSame([['id' => $branch->id, 'name' => $branch->name]], $shown['branches']);
        $this->expectRejected($this->postAs('sales', "/api/portal/statements/{$s->id}/void", ['reason' => 'ليس لنا']), 'PORTAL_OWNED_BY_SOURCE', [422]);
        $lines = $this->expectOk($this->getAs('gm', "/api/portal/statements/{$s->id}/lines"));
        $this->assertSame(['INV/2026/00011', 'PBNK1/2026/00007', 'INV/2026/00098'], array_column($lines['items'], 'reference'));
        $this->assertSame(['549.75', '199.75', '499.75'], array_column($lines['items'], 'balance'));
        // read branch by branch: the documents that name the branch, and their own totals
        $ofBranch = $this->expectOk($this->getAs('gm', "/api/portal/statements/{$s->id}/lines?branch={$branch->id}"));
        $this->assertSame([['INV/2026/00011'], ['debit' => '600.00', 'credit' => '0.00'], $branch->id], [array_column($ofBranch['items'], 'reference'), $ofBranch['totals'], $ofBranch['items'][0]['branch']['id']]);
        $this->assertSame(2, $this->expectOk($this->getAs('gm', "/api/portal/statements/{$s->id}/lines?branch=none"))['total']);

        $list = fn (string $q) => $this->expectOk($this->getAs('gm', '/api/portal/statements?'.$q));
        $this->assertSame([$s->id], array_column($list("customer={$customer->code}")['items'], 'id'));
        $this->assertSame([$s->id], array_column($list("branch={$branch->id}")['items'], 'id'), 'a customer statement whose documents name the branch');
        $this->assertSame([$s->id], array_column($list("customer={$customer->code}&from=2026-03-01&to=2026-04-30")['items'], 'id'), 'a period that touches the days asked for');
        $this->assertSame(0, $list("customer={$customer->code}&from=2026-04-01")['total']);
        $this->assertSame(0, $list("customer={$customer->code}&origin=ops")['total']);
        $found = $list('q='.urlencode("غير مربوط {$tag}"));
        $this->assertSame([[$foreign->id, false, '9100']], array_map(fn (array $x) => [$x['id'], $x['customer']['linked'], $x['customer']['ref']], $found['items']));
        $this->assertSame([$foreign->id], array_column($list('customer=ref:9100&q='.urlencode($tag))['items'], 'id'));

        // its spreadsheet: text that came from outside never opens as a formula, and a figure stays a figure
        StatementLine::where('statement_id', $s->id)->where('reference', 'INV/2026/00098')->update(['description' => '=2+5']);
        $csv = $this->get("/api/portal/statements/{$s->id}/export", ['Authorization' => 'Bearer '.$this->token('gm')])->assertOk()->getContent();
        $this->assertStringContainsString('"\'=2+5"', $csv);
        $this->assertStringNotContainsString('"=2+5"', $csv);
        $this->assertStringContainsString('"-50.25"', $csv, 'a credit balance is a number, not text to neutralise');
        $this->assertSame(7, substr_count(trim($csv), "\r\n") + 1, 'a title, a header, the opening balance, three lines and the totals');

        // handed over: a statement of the past can be voided here
        $this->followOdoo(false);
        $this->assertSame('void', $this->expectOk($this->postAs('sales', "/api/portal/statements/{$s->id}/void", ['reason' => 'استُبدل بكشف مصحَّح']))['status']);
    }
}
