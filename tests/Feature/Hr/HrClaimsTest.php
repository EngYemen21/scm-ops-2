<?php

namespace Tests\Feature\Hr;

use App\Finance\Models\Expense;
use App\Finance\Models\Journal;
use App\Finance\Models\JournalLine;
use App\Hr\Models\Claim;
use App\Hr\Models\ClaimLine;
use App\Hr\Models\HrFile;
use App\Hr\Services\ClaimsService;
use App\Models\Notification;
use App\Models\StatusHistory;
use App\Services\Core\SettingsService;
use Tests\ApiTestCase;

/**
 * Expense claims from the first line to the books: an employee files a claim with lines and receipts, a reviewer
 * approves, refuses or returns it, finance posts it as expense vouchers of the finance module and pays it — with every
 * illegal move refused, every permission enforced, and the ledger checked to the cent.
 */
class HrClaimsTest extends ApiTestCase
{
    use HrFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sealNetwork();
        $this->followOdoo(false);
    }

    private function day(int $offset): string
    {
        return now()->addDays($offset)->toDateString();
    }

    /** Three lines: 200 + 15 % VAT paid by the employee · a receipt of 115 that includes VAT, paid by the employee · a 50 fee the company paid. */
    private function lines(): array
    {
        return [
            ['date' => $this->day(-3), 'category' => 'TRAVEL', 'description' => 'تذكرة سفر إلى جدة', 'amount' => 200, 'vatPct' => 15, 'paidBy' => 'employee'],
            ['date' => $this->day(-2), 'category' => 'MEALS', 'description' => 'عشاء عمل مع عميل', 'amount' => 115, 'amountIncludesVat' => true, 'paidBy' => 'employee'],
            ['date' => $this->day(-1), 'category' => 'GOVT', 'description' => 'رسم تصديق مستند', 'amount' => 50, 'paidBy' => 'company'],
        ];
    }

    private function claim(string $user = 'sales', ?array $lines = null, array $more = []): array
    {
        return $this->expectOk($this->postAs($user, '/api/hr/claims', $more + ['title' => 'رحلة عمل '.self::uid(), 'lines' => $lines ?? $this->lines()]));
    }

    private function approved(string $user = 'sales', ?array $lines = null): array
    {
        $c = $this->claim($user, $lines);
        $this->expectOk($this->postAs($user, "/api/hr/claims/{$c['id']}/submit"));

        return $this->expectOk($this->postAs($user === 'gm' ? 'finance' : 'gm', "/api/hr/claims/{$c['id']}/approve"));
    }

    /** Debit minus credit of the account that carries a posting role, over the journals of the given vouchers. */
    private function gl(string $role, array $voucherNumbers): float
    {
        return round((float) JournalLine::query()->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')->join('fin_accounts', 'fin_accounts.id', '=', 'fin_journal_lines.account_id')
            ->whereIn('fin_journals.source_number', $voucherNumbers)->where('fin_accounts.role', $role)
            ->selectRaw('coalesce(sum(fin_journal_lines.debit), 0) - coalesce(sum(fin_journal_lines.credit), 0) as b')->value('b'), 2);
    }

    public function test_figures_are_computed_the_way_the_voucher_will_compute_them(): void
    {
        $this->assertSame(['amount' => 200.0, 'vat_pct' => 15.0, 'vat_amount' => 30.0, 'total' => 230.0], ClaimsService::figures(200, 15, false));
        $this->assertSame(['amount' => 100.0, 'vat_pct' => 15.0, 'vat_amount' => 15.0, 'total' => 115.0], ClaimsService::figures(115, 15, true));
        $this->assertSame(['amount' => 50.0, 'vat_pct' => 0.0, 'vat_amount' => 0.0, 'total' => 50.0], ClaimsService::figures(50, 0, true));
        // the total of a receipt is kept to the cent whenever an amount before VAT exists that gives it
        foreach ([10.00, 99.99, 1234.56, 0.23, 57.50, 3.45] as $gross) {
            $f = ClaimsService::figures($gross, 15, true);
            $this->assertEqualsWithDelta($gross, $f['total'], 0.011, "receipt {$gross}");
            $this->assertSame(round($f['amount'] * 0.15, 2), $f['vat_amount'], 'VAT is always amount × rate, as the voucher computes it');
            $this->assertSame(round($f['amount'] + $f['vat_amount'], 2), $f['total']);
        }
    }

    public function test_an_employee_files_a_claim_with_lines_and_receipts(): void
    {
        // a worker has no part in this; filing needs expense.claim
        $this->expectRejected($this->getAs('worker', '/api/hr/claims'), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('worker', '/api/hr/claims', ['title' => 'لا تُنشأ', 'lines' => $this->lines()]), 'FORBIDDEN', [403]);

        $c = $this->claim('sales');
        $this->assertMatchesRegularExpression('/^CLM-\d{4}-\d{5}$/', $c['number']);
        $this->assertSame(['draft', true, 3, 3], [$c['state'], $c['mine'], $c['lineCount'], $c['linesWithoutReceipt']]);
        $this->assertSame(['350.00', '45.00', '395.00', '345.00', '50.00'], [$c['amount'], $c['vatAmount'], $c['total'], $c['employeeDue'], $c['companyPaid']]);
        $this->assertSame([['200.00', '30.00', '230.00'], ['100.00', '15.00', '115.00'], ['50.00', '0.00', '50.00']], array_map(fn ($l) => [$l['amount'], $l['vatAmount'], $l['total']], $c['lines']));
        $this->assertSame('sales', $c['claimant']['username']);
        $this->assertNull($c['employee'], 'a login without an employee record still files its own claims');
        $this->assertTrue($c['can']['edit'] && $c['can']['submit'] && $c['can']['cancel'] && ! $c['can']['approve'] && ! $c['can']['post']);
        // what a line is charged to is the reviewers' business
        $this->assertArrayNotHasKey('accountCode', $c['lines'][0]);
        $this->assertArrayNotHasKey('voucher', $c['lines'][0]);

        // lines are checked one by one and the answer names the field
        $bad = $this->expectRejected($this->postAs('sales', '/api/hr/claims', ['title' => 'سطور خاطئة', 'lines' => [
            ['date' => $this->day(2), 'category' => 'TRAVEL', 'description' => 'في المستقبل', 'amount' => 10],
            ['date' => $this->day(-1), 'category' => 'NO-SUCH-CATEGORY', 'description' => 'فئة مجهولة', 'amount' => 10],
        ]]), 'INVALID_INPUT', [400]);
        $this->assertEqualsCanonicalizing(['lines.0.date', 'lines.1.category'], array_column($bad['details'], 'path'));
        $this->expectRejected($this->postAs('sales', '/api/hr/claims', ['title' => 'مبلغ صفر', 'lines' => [['date' => $this->day(-1), 'category' => 'TRAVEL', 'description' => 'صفر', 'amount' => 0]]]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', '/api/hr/claims', ['title' => 'x', 'lines' => $this->lines()]), 'INVALID_INPUT', [400]);

        // receipts: a picture or a PDF on a line
        [$l1, $l2, $l3] = array_column($c['lines'], 'id');
        $with = $this->expectOk($this->postAs('sales', "/api/hr/claims/{$c['id']}/lines/{$l1}/receipts", self::pngFile('إيصال.png')));
        $this->assertSame(2, $with['linesWithoutReceipt']);
        $receipt = $with['lines'][0]['receipts'][0];
        $this->assertSame(['إيصال.png', 'image/png'], [$receipt['name'], $receipt['mime']]);
        $this->expectRejected($this->postAs('sales', "/api/hr/claims/{$c['id']}/lines/{$l1}/receipts", ['fileName' => 'x.png', 'base64' => base64_encode('GIF?not really')]), 'HR_FILE_TYPE', [400]);
        $this->expectRejected($this->postAs('sales', "/api/hr/claims/{$c['id']}/lines/no-such-line/receipts", self::pngFile()), 'CLAIM_LINE_NOT_FOUND', [404]);
        $this->assertStringStartsWith('data:image/png;base64,', $this->expectOk($this->getAs('sales', $receipt['url'].'?as=data'))['dataUrl']);

        // the claim and its receipts belong to the claimant and to the reviewers — to nobody else
        $this->expectRejected($this->getAs('proc', "/api/hr/claims/{$c['id']}"), 'CLAIM_NOT_YOURS', [403]);
        $this->expectRejected($this->getAs('proc', $receipt['url']), 'CLAIM_NOT_YOURS', [403]);
        $this->expectRejected($this->patchAs('proc', "/api/hr/claims/{$c['id']}", ['title' => 'عنوان مسروق']), 'CLAIM_NOT_YOURS', [403]);
        $this->expectRejected($this->postAs('proc', "/api/hr/claims/{$c['id']}/submit"), 'CLAIM_NOT_YOURS', [403]);
        $this->assertNotContains($c['id'], array_column($this->expectOk($this->getAs('proc', '/api/hr/claims'))['items'], 'id'));
        $this->expectRejected($this->getAs('sales', '/api/hr/claims?scope=all'), 'FORBIDDEN', [403]);
        $this->expectRejected($this->getAs('sales', '/api/hr/claims?scope=review'), 'FORBIDDEN', [403]);
        $this->getAs('gm', $receipt['url'])->assertOk()->assertHeader('Content-Type', 'image/png');
        $seen = $this->expectOk($this->getAs('gm', "/api/hr/claims/{$c['number']}"));
        $this->assertFalse($seen['mine']);
        $this->assertArrayHasKey('accountCode', $seen['lines'][0]);

        // a draft is rewritten by its author: a changed line, a removed line (its receipt goes with it), a new line
        $edited = $this->expectOk($this->patchAs('sales', "/api/hr/claims/{$c['id']}", ['title' => 'رحلة جدة — معدّلة', 'lines' => [
            ['id' => $l2, 'date' => $this->day(-2), 'category' => 'MEALS', 'description' => 'عشاء عمل', 'amount' => 230, 'amountIncludesVat' => true],
            ['id' => $l3, 'date' => $this->day(-1), 'category' => 'GOVT', 'description' => 'رسم تصديق مستند', 'amount' => 50, 'paidBy' => 'company'],
            ['date' => $this->day(0), 'category' => 'FUEL', 'description' => 'وقود', 'amount' => 80, 'vatPct' => 15],
        ]]));
        $this->assertSame(['رحلة جدة — معدّلة', '330.00', '42.00', '372.00', '322.00', 3], [$edited['title'], $edited['amount'], $edited['vatAmount'], $edited['total'], $edited['employeeDue'], $edited['lineCount']]);
        $this->assertSame([1, 2, 3], array_column($edited['lines'], 'lineNo'));
        $this->assertNull(ClaimLine::find($l1));
        $this->assertNotNull(HrFile::find($receipt['id'])->removed_at);
        $this->expectRejected($this->getAs('sales', $receipt['url']), 'HR_FILE_NOT_FOUND', [404]);
        $this->expectRejected($this->patchAs('sales', "/api/hr/claims/{$c['id']}", ['lines' => [['id' => $l1, 'date' => $this->day(-1), 'category' => 'FUEL', 'description' => 'سطر من مطالبة أخرى', 'amount' => 5]]]), 'INVALID_INPUT', [400]);

        // the lists: mine for the claimant, everything for a reviewer, with search, state filter, totals and paging
        $mine = $this->expectOk($this->getAs('sales', '/api/hr/claims?q='.$c['number']));
        $this->assertSame([1, $c['id'], '372.00'], [$mine['total'], $mine['items'][0]['id'], $mine['totals']['total']]);
        $this->assertSame(1, $this->expectOk($this->getAs('gm', '/api/hr/claims?scope=all&state=draft&q='.$c['number']))['total']);
        $this->assertSame(0, $this->expectOk($this->getAs('gm', '/api/hr/claims?scope=all&state=paid&q='.$c['number']))['total']);
        $this->assertSame(0, $this->expectOk($this->getAs('gm', '/api/hr/claims?scope=review&q='.$c['number']))['total'], 'a draft waits for nobody');
        $this->assertSame(1, count($this->expectOk($this->getAs('gm', '/api/hr/claims?scope=all&pageSize=1'))['items']));
    }

    public function test_a_reviewer_approves_refuses_or_returns_and_nothing_else_moves_a_claim(): void
    {
        $c = $this->claim('sales');
        $id = $c['id'];
        // an empty claim is not submitted
        $empty = $this->claim('sales', []);
        $this->expectRejected($this->postAs('sales', "/api/hr/claims/{$empty['id']}/submit"), 'CLAIM_EMPTY', [422]);
        // nothing can be decided before it is submitted
        $this->expectRejected($this->postAs('gm', "/api/hr/claims/{$id}/approve"), 'CLAIM_STATE', [422]);
        $this->expectRejected($this->postAs('sales', "/api/hr/claims/{$id}/withdraw"), 'CLAIM_STATE', [422]);

        $before = Notification::where('entity_type', 'ExpenseClaim')->where('entity_id', $id)->count();
        $submitted = $this->expectOk($this->postAs('sales', "/api/hr/claims/{$id}/submit"));
        $this->assertSame('submitted', $submitted['state']);
        $this->assertNotNull($submitted['submittedAt']);
        // the reviewers are told: every role that holds expense.review
        $this->assertEqualsCanonicalizing(['super', 'gm', 'finance'], Notification::where('entity_type', 'ExpenseClaim')->where('entity_id', $id)->pluck('role_key')->all());
        $this->assertSame(0, $before);
        $this->assertSame(1, $this->expectOk($this->getAs('finance', '/api/hr/claims?scope=review&q='.$c['number']))['total']);

        // submitted: the claimant no longer edits it, and does not decide it
        $this->expectRejected($this->patchAs('sales', "/api/hr/claims/{$id}", ['title' => 'تعديل بعد الإرسال']), 'CLAIM_STATE', [422]);
        $this->expectRejected($this->postAs('sales', "/api/hr/claims/{$id}/approve"), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('sales', "/api/hr/claims/{$id}/submit"), 'CLAIM_TRANSITION', [422]);
        // …but may still add a missing receipt, or take the claim back
        $this->expectOk($this->postAs('sales', "/api/hr/claims/{$id}/lines/{$c['lines'][0]['id']}/receipts", self::pdfFile()));
        $this->assertSame('draft', $this->expectOk($this->postAs('sales', "/api/hr/claims/{$id}/withdraw"))['state']);
        $this->expectOk($this->postAs('sales', "/api/hr/claims/{$id}/submit"));

        // returned for correction: a draft again, carrying the reviewer's words; the claimant is told personally
        $this->expectRejected($this->postAs('gm', "/api/hr/claims/{$id}/return", ['reason' => 'x']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('gm', "/api/hr/claims/{$id}/return"), 'INVALID_INPUT', [400]);
        $returned = $this->expectOk($this->postAs('gm', "/api/hr/claims/{$id}/return", ['reason' => 'أرفق إيصال العشاء']));
        $this->assertSame(['draft', 'أرفق إيصال العشاء', 'gm'], [$returned['state'], $returned['decisionNote'], $returned['decidedBy']]);
        $this->assertTrue(Notification::where('user_id', $this->userId('sales'))->where('entity_id', $id)->where('text_ar', 'like', '%أُعيدت%')->exists());
        $this->expectOk($this->postAs('sales', "/api/hr/claims/{$id}/lines/{$c['lines'][1]['id']}/receipts", self::pngFile()));
        $again = $this->expectOk($this->postAs('sales', "/api/hr/claims/{$id}/submit"));
        $this->assertNull($again['decisionNote'], 'a resubmitted claim no longer shows the old remark — the history keeps it');

        // approved
        $approved = $this->expectOk($this->postAs('gm', "/api/hr/claims/{$id}/approve", ['note' => 'موافق']));
        $this->assertSame(['approved', 'gm', 'موافق'], [$approved['state'], $approved['decidedBy'], $approved['decisionNote']]);
        $this->assertFalse($approved['can']['post'], 'the general manager approves; posting to the books is finance\'s');
        $this->assertTrue($this->expectOk($this->getAs('finance', "/api/hr/claims/{$id}"))['can']['post']);
        $this->assertSame(['draft', 'submitted', 'draft', 'submitted', 'draft', 'submitted', 'approved'], array_column($approved['history'], 'to'));
        $this->assertSame(7, StatusHistory::where('entity_type', 'ExpenseClaim')->where('entity_id', $id)->count());
        // a decided claim is not decided again, edited or withdrawn
        foreach (['approve' => [], 'refuse' => ['reason' => 'سبب متأخر'], 'return' => ['reason' => 'سبب متأخر']] as $action => $body) {
            $this->expectRejected($this->postAs('finance', "/api/hr/claims/{$id}/{$action}", $body), 'CLAIM_STATE', [422]);
        }
        $this->expectRejected($this->postAs('sales', "/api/hr/claims/{$id}/withdraw"), 'CLAIM_STATE', [422]);
        $this->expectRejected($this->postAs('sales', "/api/hr/claims/{$id}/cancel"), 'CLAIM_TRANSITION', [422]);
        // "submit" is never the claimant's way to take an approval back (the state table holds approved → submitted for the reviewer's reopen)
        $this->expectRejected($this->postAs('sales', "/api/hr/claims/{$id}/submit"), 'CLAIM_TRANSITION', [422]);
        $this->assertSame('approved', Claim::find($id)->state);
        $this->expectRejected($this->postAs('finance', "/api/hr/claims/{$id}/pay", ['reference' => 'TRF-0']), 'CLAIM_TRANSITION', [422]);
        // an approval can be taken back before anything is posted
        $this->assertSame('submitted', $this->expectOk($this->postAs('finance', "/api/hr/claims/{$id}/reopen", ['reason' => 'اعتُمدت قبل اكتمال الإيصالات']))['state']);

        // refused, with a reason: final
        $refused = $this->expectOk($this->postAs('finance', "/api/hr/claims/{$id}/refuse", ['reason' => 'مصروف شخصي لا يخص العمل']));
        $this->assertSame(['refused', 'مصروف شخصي لا يخص العمل'], [$refused['state'], $refused['decisionNote']]);
        foreach (['submit', 'withdraw', 'cancel'] as $action) {
            $this->expectRejected($this->postAs('sales', "/api/hr/claims/{$id}/{$action}"), null, [422]);
        }
        $this->expectRejected($this->postAs('gm', "/api/hr/claims/{$id}/approve"), 'CLAIM_STATE', [422]);

        // a draft its author no longer wants is cancelled, not deleted
        $this->assertSame('cancelled', $this->expectOk($this->postAs('sales', "/api/hr/claims/{$empty['id']}/cancel", ['reason' => 'أُنشئت بالخطأ']))['state']);
        $this->expectRejected($this->postAs('sales', "/api/hr/claims/{$empty['id']}/submit"), 'CLAIM_TRANSITION', [422]);
        $this->assertNotNull(Claim::find($empty['id']));
    }

    public function test_nobody_decides_their_own_claim(): void
    {
        // the general manager files a claim: another reviewer decides it
        $own = $this->claim('gm');
        $this->expectOk($this->postAs('gm', "/api/hr/claims/{$own['id']}/submit"));
        $this->assertTrue($this->expectOk($this->getAs('gm', "/api/hr/claims/{$own['id']}"))['can']['selfReview']);
        $this->expectRejected($this->postAs('gm', "/api/hr/claims/{$own['id']}/approve"), 'CLAIM_SELF_REVIEW', [422]);
        $this->expectRejected($this->postAs('gm', "/api/hr/claims/{$own['id']}/refuse", ['reason' => 'أرفض مطالبتي']), 'CLAIM_SELF_REVIEW', [422]);
        $this->assertSame('approved', $this->expectOk($this->postAs('finance', "/api/hr/claims/{$own['id']}/approve"))['state']);

        // a reviewer enters a claim for an employee who has no login — and then is its maker, not its checker
        $e = $this->makeEmployee(['nameAr' => 'سائق بلا حساب '.self::uid()]);
        $this->expectRejected($this->postAs('sales', '/api/hr/claims', ['title' => 'عن موظف آخر', 'employee' => $e['id'], 'lines' => $this->lines()]), 'CLAIM_ON_BEHALF', [403]);
        $behalf = $this->claim('finance', null, ['employee' => $e['code']]);
        $this->assertSame([$e['code'], null, true], [$behalf['employee']['code'], $behalf['claimant'], $behalf['mine']]);
        $this->expectOk($this->postAs('finance', "/api/hr/claims/{$behalf['id']}/submit"));
        $this->expectRejected($this->postAs('finance', "/api/hr/claims/{$behalf['id']}/approve"), 'CLAIM_SELF_REVIEW', [422]);
        // the company's existing policy switch for segregation of duties covers claims too
        $settings = app(SettingsService::class);
        $settings->set('procurement.allowSelfApproval', true);
        $settings->flush();
        $this->assertSame('approved', $this->expectOk($this->postAs('finance', "/api/hr/claims/{$behalf['id']}/approve"))['state']);
        $settings->set('procurement.allowSelfApproval', false);
        $settings->flush();

        // once HR links the employee to a login, the claims entered for them are theirs to see
        $this->expectRejected($this->getAs('inv', "/api/hr/claims/{$behalf['id']}"), 'CLAIM_NOT_YOURS', [403]);
        $this->expectOk($this->patchAs('admin', "/api/hr/employees/{$e['id']}", ['userId' => 'inv']));
        $this->assertTrue($this->expectOk($this->getAs('inv', "/api/hr/claims/{$behalf['id']}"))['mine']);
        $this->assertContains($behalf['id'], array_column($this->expectOk($this->getAs('inv', '/api/hr/claims'))['items'], 'id'));
        // …and a claim they file themselves carries their employee record
        $this->assertSame($e['code'], $this->claim('inv')['employee']['code']);
        $this->expectOk($this->patchAs('admin', "/api/hr/employees/{$e['id']}", ['userId' => null]));
    }

    public function test_an_approved_claim_is_posted_as_finance_vouchers_and_paid(): void
    {
        $c = $this->approved('sales');
        $id = $c['id'];
        $number = $c['number'];
        $vouchers = fn () => Expense::where('reference', $number)->orderBy('created_at')->orderBy('id')->get();

        // approving is the reviewer's act; the books are finance's
        $this->expectRejected($this->postAs('gm', "/api/hr/claims/{$id}/post", ['bankAccount' => 'BANK']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('proc', "/api/hr/claims/{$id}/post", ['bankAccount' => 'BANK']), 'FORBIDDEN', [403]);
        // the line the company paid left a bank account or a cash box: it must be named — and a refusal posts nothing
        $this->expectRejected($this->postAs('finance', "/api/hr/claims/{$id}/post"), 'CLAIM_BANK_REQUIRED', [422]);
        $this->expectRejected($this->postAs('finance', "/api/hr/claims/{$id}/post", ['bankAccount' => 'NO-SUCH-BANK']), 'BANK_ACCOUNT_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('finance', "/api/hr/claims/{$id}/post", ['bankAccount' => 'BANK', 'date' => $this->day(3)]), 'DATE_FUTURE', [400]);
        $this->expectRejected($this->postAs('finance', "/api/hr/claims/{$id}/post", ['bankAccount' => 'BANK', 'reimburse' => 'now']), 'PAYMENT_REF_REQUIRED', [400]);
        $this->assertCount(0, $vouchers());
        $this->assertSame('approved', Claim::find($id)->state);

        // posted: one voucher per line, made by the finance module, each line's total to the cent
        $posted = $this->expectOk($this->postAs('finance', "/api/hr/claims/{$id}/post", ['bankAccount' => 'BANK']));
        $this->assertSame(['posted', 'accrued', 'ops', 'finance'], [$posted['state'], $posted['settlement'], $posted['postedIn'], $posted['postedBy']]);
        $v = $vouchers();
        $this->assertCount(3, $v);
        $this->assertSame([['200.00', '30.00', '230.00', 'accrued'], ['100.00', '15.00', '115.00', 'accrued'], ['50.00', '0.00', '50.00', 'bank']], $v->map(fn (Expense $e) => [$e->amount, $e->vat_amount, $e->total, $e->settlement])->all());
        $this->assertSame([$this->day(-3), $this->day(-2), $this->day(-1)], $v->map(fn (Expense $e) => $e->expense_date->toDateString())->all(), 'each voucher is dated as its expense');
        $this->assertStringContainsString($number, $v[0]->description);
        $numbers = $v->pluck('number')->all();
        $this->assertSame($numbers, array_map(fn ($l) => $l['voucher']['number'], $posted['lines']));
        $this->assertSame(['posted', 'posted', 'posted'], array_map(fn ($l) => $l['voucher']['status'], $posted['lines']));
        // the ledger: expenses and input VAT debited, what the employee paid accrued, what the company paid out of the bank
        $this->assertSame(350.00, $this->gl('admin_expenses', $numbers));
        $this->assertSame(45.00, $this->gl('vat_input', $numbers));
        $this->assertSame(-345.00, $this->gl('accrued_expenses', $numbers));
        $this->assertSame(-50.00, $this->gl('bank', $numbers));
        foreach (Journal::whereIn('source_number', $numbers)->get() as $j) {
            $this->assertSame((float) $j->total_debit, (float) $j->total_credit);
        }
        // the claimant sees the state, not the accounts
        $own = $this->expectOk($this->getAs('sales', "/api/hr/claims/{$id}"));
        $this->assertSame('posted', $own['state']);
        $this->assertArrayNotHasKey('voucher', $own['lines'][0]);
        // posting twice is refused and makes no second voucher
        $this->expectRejected($this->postAs('finance', "/api/hr/claims/{$id}/post", ['bankAccount' => 'BANK']), 'CLAIM_TRANSITION', [422]);
        $this->assertCount(3, $vouchers());
        $this->assertSame(1, $this->expectOk($this->getAs('finance', '/api/hr/claims?scope=review&state=posted&q='.$number))['total']);

        // paid: recorded with its reference; the finance module has no flow that settles an accrual, so no journal is made here
        $this->expectRejected($this->postAs('gm', "/api/hr/claims/{$id}/pay", ['reference' => 'TRF-1']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('finance', "/api/hr/claims/{$id}/pay"), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('finance', "/api/hr/claims/{$id}/pay", ['reference' => 'TRF-1', 'date' => $this->day(1)]), 'DATE_FUTURE', [400]);
        $journals = Journal::count();
        $paid = $this->expectOk($this->postAs('finance', "/api/hr/claims/{$id}/pay", ['reference' => 'TRF-2026-001', 'bankAccount' => 'BANK']));
        $this->assertSame(['paid', 'TRF-2026-001', 'bank', 'BANK', 'finance'], [$paid['state'], $paid['paymentRef'], $paid['paymentMethod'], $paid['paymentAccount'], $paid['paidBy']]);
        $this->assertSame($journals, Journal::count());
        $this->assertSame(-345.00, $this->gl('accrued_expenses', $numbers), 'the accrual stays for finance to clear (see the wiring note)');
        $this->assertTrue(Notification::where('user_id', $this->userId('sales'))->where('entity_id', $id)->where('text_ar', 'like', '%TRF-2026-001%')->exists());
        $this->assertSame(['draft', 'submitted', 'approved', 'posted', 'paid'], array_column($paid['history'], 'to'));
        $this->expectRejected($this->postAs('finance', "/api/hr/claims/{$id}/pay", ['reference' => 'TRF-2']), 'CLAIM_TRANSITION', [422]);
        // a paid claim is never posted a second time (the state table holds paid → posted for taking a payment back)
        $this->expectRejected($this->postAs('finance', "/api/hr/claims/{$id}/post", ['bankAccount' => 'BANK']), 'CLAIM_TRANSITION', [422]);
        $this->assertCount(3, $vouchers());

        // exits instead of dead ends: the payment is taken back, then the posting — the vouchers are voided by the finance module
        $this->expectRejected($this->postAs('finance', "/api/hr/claims/{$id}/unpost", ['reason' => 'ترحيل بحساب خاطئ']), 'CLAIM_PAID', [422]);
        $this->expectRejected($this->postAs('finance', "/api/hr/claims/{$id}/unpay", ['reason' => 'x']), 'INVALID_INPUT', [400]);
        $unpaid = $this->expectOk($this->postAs('finance', "/api/hr/claims/{$id}/unpay", ['reason' => 'سُجِّل السداد قبل تنفيذ الحوالة']));
        $this->assertSame(['posted', null, null], [$unpaid['state'], $unpaid['paymentRef'], $unpaid['paidAt']]);
        $this->assertTrue($unpaid['can']['pay'] && $unpaid['can']['unpost']);
    }

    public function test_a_posting_is_reversed_and_a_claim_can_be_settled_on_the_spot(): void
    {
        $c = $this->approved('sales');
        $id = $c['id'];
        $number = $c['number'];
        $posted = $this->expectOk($this->postAs('finance', "/api/hr/claims/{$id}/post", ['bankAccount' => 'BANK']));
        $first = array_map(fn ($l) => $l['voucher']['number'], $posted['lines']);

        // reversing the posting needs the finance permission that voids a voucher (gl.approve) on top of expense.review
        $this->expectRejected($this->postAs('proc', "/api/hr/claims/{$id}/unpost", ['reason' => 'ترحيل بحساب خاطئ']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('finance', "/api/hr/claims/{$id}/unpost"), 'INVALID_INPUT', [400]);
        $back = $this->expectOk($this->postAs('finance', "/api/hr/claims/{$id}/unpost", ['reason' => 'ترحيل بحساب خاطئ']));
        $this->assertSame(['approved', null, null], [$back['state'], $back['settlement'], $back['postedIn']]);
        $this->assertSame(['void', 'void', 'void'], Expense::whereIn('number', $first)->pluck('status')->all());
        foreach (['admin_expenses', 'vat_input', 'accrued_expenses', 'bank'] as $role) {
            $this->assertSame(0.0, $this->gl($role, $first), "the reversal brings {$role} back to zero");
        }
        $this->assertSame([null, null, null], array_map(fn ($l) => $l['voucher'], $back['lines']));
        $this->assertStringContainsString($first[0], collect($back['history'])->last()['note']);

        // posted again, this time reimbursed on the spot from the cash box: the vouchers are the payment
        $settled = $this->expectOk($this->postAs('finance', "/api/hr/claims/{$id}/post", ['bankAccount' => 'CASH', 'reimburse' => 'now', 'reference' => 'سند صرف 118', 'date' => $this->day(0)]));
        $this->assertSame(['paid', 'bank', 'سند صرف 118', 'cash', 'CASH'], [$settled['state'], $settled['settlement'], $settled['paymentRef'], $settled['paymentMethod'], $settled['paymentAccount']]);
        $second = array_map(fn ($l) => $l['voucher']['number'], $settled['lines']);
        $this->assertCount(0, array_intersect($first, $second));
        $this->assertSame(['bank', 'bank', 'bank'], Expense::whereIn('number', $second)->pluck('settlement')->all());
        $this->assertSame([$this->day(0)], Expense::whereIn('number', $second)->get()->map(fn ($e) => $e->expense_date->toDateString())->unique()->values()->all(), 'one accounting date when finance names it');
        $this->assertSame(-395.00, $this->gl('cash', $second));
        $this->assertSame(0.0, $this->gl('accrued_expenses', $second));
        $this->assertSame(['posted', 'paid'], array_slice(array_column($settled['history'], 'to'), -2));
        // there is no separate payment to take back; the posting itself is reversed
        $this->expectRejected($this->postAs('finance', "/api/hr/claims/{$id}/unpay", ['reason' => 'لا سداد مستقل هنا']), 'CLAIM_SETTLED_BY_POSTING', [422]);
        $this->assertTrue($settled['can']['unpost'] && ! $settled['can']['unpay']);

        // a voucher voided from the finance screen shows as void on the claim, and reversing the claim skips it
        $this->expectOk($this->postAs('finance', "/api/finance/expenses/{$second[2]}/void", ['reason' => 'أُلغي من شاشة المالية']));
        $this->assertSame(['posted', 'posted', 'void'], array_map(fn ($l) => $l['voucher']['status'], $this->expectOk($this->getAs('finance', "/api/hr/claims/{$id}"))['lines']));
        $this->assertSame('approved', $this->expectOk($this->postAs('finance', "/api/hr/claims/{$id}/unpost", ['reason' => 'تصحيح بعد إلغاء سند']))['state']);
        $this->assertSame(0.0, $this->gl('cash', $second));
        $this->assertSame(6, Expense::where('reference', $number)->count(), 'nothing was deleted: six vouchers, all void');

        // a claim whose every line was paid by the company owes the employee nothing: it is settled the moment it is posted
        $company = $this->approved('sales', [['date' => $this->day(-1), 'category' => 'SUPPLIES', 'description' => 'أحبار طابعة', 'amount' => 300, 'vatPct' => 15, 'paidBy' => 'company']]);
        $done = $this->expectOk($this->postAs('finance', "/api/hr/claims/{$company['id']}/post", ['bankAccount' => 'BANK', 'costCenter' => 'ADMIN']));
        $this->assertSame(['paid', 'none', '0.00', null], [$done['state'], $done['settlement'], $done['employeeDue'], $done['paymentRef']]);
        $voucher = Expense::where('reference', $company['number'])->first();
        $this->assertSame('ADMIN', $voucher->costCenter->code);
        $this->assertSame(-345.00, $this->gl('bank', [$voucher->number]));
    }

    public function test_a_line_needs_an_expense_account_and_reviewers_keep_the_categories(): void
    {
        $tag = self::uid();
        // categories are read by whoever files or reviews; kept by the reviewers
        $this->expectRejected($this->postAs('sales', '/api/hr/expense-categories', ['code' => "X{$tag}", 'nameAr' => 'لا تُنشأ']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('finance', '/api/hr/expense-categories', ['code' => "C{$tag}", 'nameAr' => 'حساب إيرادات', 'accountCode' => '4100']), 'EXPENSE_ACCOUNT', [422]);
        $this->expectRejected($this->postAs('finance', '/api/hr/expense-categories', ['code' => 'TRAVEL', 'nameAr' => 'مكرر']), 'CATEGORY_CODE_TAKEN', [409]);
        $cat = $this->expectOk($this->postAs('finance', '/api/hr/expense-categories', ['code' => "c{$tag}", 'nameAr' => "تدريب {$tag}", 'vatPct' => 15]));
        $this->assertSame(["C{$tag}", null, 15.0], [$cat['code'], $cat['accountCode'], (float) $cat['vatPct']]);
        $this->assertContains("C{$tag}", array_column($this->expectOk($this->getAs('sales', '/api/hr/expense-categories'))['items'], 'code'));

        // a line whose category has no account cannot be posted until finance names one
        $c = $this->approved('sales', [['date' => $this->day(-1), 'category' => "C{$tag}", 'description' => 'دورة تدريبية', 'amount' => 1000]]);
        $this->assertSame('1150.00', $c['total'], 'the category proposes its VAT rate');
        $refused = $this->expectRejected($this->postAs('finance', "/api/hr/claims/{$c['id']}/post"), 'CLAIM_ACCOUNT_REQUIRED', [422]);
        $this->assertSame(1, $refused['details']['lineNo']);
        $this->expectRejected($this->postAs('finance', "/api/hr/claims/{$c['id']}/post", ['accounts' => [$c['lines'][0]['id'] => '4100']]), 'EXPENSE_ACCOUNT', [422]);
        $this->assertSame(0, Expense::where('reference', $c['number'])->count());
        $posted = $this->expectOk($this->postAs('finance', "/api/hr/claims/{$c['id']}/post", ['accounts' => [$c['lines'][0]['id'] => '6230']]));
        $this->assertSame(['posted', '6230'], [$posted['state'], $posted['lines'][0]['accountCode']]);
        $this->assertSame('6230', Expense::where('reference', $c['number'])->first()->account->code);

        // mapping the category serves every later claim; an archived category is no longer offered
        $this->assertSame('6210', $this->expectOk($this->patchAs('finance', "/api/hr/expense-categories/{$cat['id']}", ['accountCode' => '6210']))['accountCode']);
        $this->expectOk($this->patchAs('finance', "/api/hr/expense-categories/{$cat['id']}", ['active' => false]));
        $this->assertNotContains("C{$tag}", array_column($this->expectOk($this->getAs('sales', '/api/hr/expense-categories'))['items'], 'code'));
        $this->expectRejected($this->postAs('sales', '/api/hr/claims', ['title' => 'فئة مؤرشفة', 'lines' => [['date' => $this->day(-1), 'category' => "C{$tag}", 'description' => 'لا تُقبل', 'amount' => 5]]]), 'INVALID_INPUT', [400]);

        // the summary counts what waits for each person
        $review = $this->expectOk($this->getAs('finance', '/api/hr/summary'))['claims']['review'];
        $this->assertGreaterThanOrEqual(1, $review['toPay']);
        $mine = $this->expectOk($this->getAs('sales', '/api/hr/summary'))['claims']['mine'];
        $this->assertGreaterThanOrEqual(1150.0, (float) $mine['toReceive']);
    }
}
