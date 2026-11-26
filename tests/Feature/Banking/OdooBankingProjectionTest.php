<?php

namespace Tests\Feature\Banking;

use App\Banking\Models\PaymentTerm;
use App\Banking\Models\SourceJournal;
use App\Banking\Models\Statement;
use App\Banking\Models\StatementLine;
use App\Banking\Models\WriteoffRule;
use App\Banking\OdooBankingProjection;
use App\Finance\Models\BankAccount;
use App\Integration\Models\IntExternalRef;
use App\Integration\Odoo\Models\OdooChange;
use App\Integration\Odoo\Models\OdooModel;
use App\Integration\Odoo\Models\OdooRecord;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/**
 * From the copy of Odoo's banking to «البنوك والمطابقة» (OdooBankingProjection). The copy is built here by hand —
 * rows of `odoo_models` / `odoo_records` shaped like the real ones (account.journal, account.bank.statement(.line),
 * account.move, res.partner.bank, account.reconcile.model(.line), account.payment.term(.line)) — and Odoo is never
 * called. Proven: the plan writes nothing; the apply links bank and cash journals to OPS bank accounts by identity only
 * (IBAN, exact name) and never makes a bank account; the unmatched are left for a person; statement lines become
 * history; reconciliation models become rules; a second pass changes nothing; what Odoo owns is read-only while the
 * section follows it; a link a person removed is not made again.
 */
class OdooBankingProjectionTest extends ApiTestCase
{
    use BankingFixtures;

    private const IBAN = 'SA1180000123456789012345';

    private const MODELS = ['account.journal', 'account.bank.statement', 'account.bank.statement.line', 'account.reconcile.model', 'account.reconcile.model.line', 'account.payment.term', 'account.payment.term.line'];

    private static array $s = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->followOdoo(false);
    }

    private function followOdoo(bool $on): void
    {
        config(['odoo.enabled' => $on, 'odoo.project.banking' => true]);
    }

    private function rec(string $model, int $id, array $data, ?bool $active = null): void
    {
        $data = ($active === null ? [] : ['active' => $active]) + $data + ['write_date' => '2026-09-01 08:00:00'];
        OdooRecord::updateOrCreate(['model' => $model, 'odoo_id' => $id], ['display_name' => is_string($data['name'] ?? null) ? $data['name'] : null, 'write_date' => $data['write_date'], 'active' => $active,
            'data' => $data, 'hash' => sha1(json_encode($data)), 'first_seen_at' => now(), 'changed_at' => now(), 'deleted_at' => null]);
    }

    private function projection(): OdooBankingProjection
    {
        return $this->app->make(OdooBankingProjection::class);
    }

    private function tables(): array
    {
        return [SourceJournal::count(), Statement::count(), StatementLine::count(), WriteoffRule::count(), PaymentTerm::count(), BankAccount::count(), IntExternalRef::count(), AuditLog::count(),
            (int) DB::table('number_sequences')->where('key', 'BST')->value('next')];
    }

    public function test_01_the_plan_writes_nothing_and_the_apply_links_by_identity_only(): void
    {
        foreach (['odoo_changes', 'odoo_records', 'odoo_models'] as $table) {
            DB::table($table)->delete();
        }
        $tag = self::uid();
        $bank = $this->newBank('OB', 'sa11 8000 0123 4567 8901 2345');
        $cash = $this->newBank('OC', null, 'cash', "صندوق الفرع {$tag}");
        self::$s += ['bank' => $bank, 'cash' => $cash, 'tag' => $tag];
        foreach (self::MODELS as $model) {
            OdooModel::create(['model' => $model, 'status' => 'active', 'full_state' => 'done', 'has_active' => in_array($model, ['account.journal', 'account.reconcile.model', 'account.payment.term'], true)]);
        }
        $day = $this->day(-40);
        $this->rec('res.partner.bank', 501, ['acc_number' => 'SA11 8000 0123 4567 8901 2345', 'sanitized_acc_number' => self::IBAN, 'bank_id' => [7, 'مصرف الراجحي']], true);
        $this->rec('account.journal', 11, ['code' => 'BNK1', 'name' => 'Al Rajhi current', 'type' => 'bank', 'bank_account_id' => [501, 'SA11…'], 'currency_id' => [151, 'SAR'], 'default_account_id' => [80, '111001 الراجحي']], true);
        $this->rec('account.journal', 12, ['code' => 'CSH1', 'name' => "صندوق الفرع {$tag}", 'type' => 'cash', 'bank_account_id' => false, 'currency_id' => false], true);
        $this->rec('account.journal', 13, ['code' => 'BNK9', 'name' => "بنك لا يقابله حساب {$tag}", 'type' => 'bank', 'bank_account_id' => false], true);
        $this->rec('account.journal', 14, ['code' => 'INV', 'name' => 'Customer invoices', 'type' => 'sale'], true);
        $this->rec('account.move', 9001, ['date' => $day, 'journal_id' => [11, 'Al Rajhi current'], 'ref' => 'DEP-11', 'state' => 'posted']);
        $this->rec('account.move', 9002, ['date' => $this->day(-35), 'journal_id' => [12, 'صندوق'], 'ref' => false, 'state' => 'cancel']);
        $this->rec('account.bank.statement', 301, ['name' => 'ST/2026/01', 'journal_id' => [11, 'Al Rajhi current'], 'balance_start' => 0, 'balance_end_real' => 100.5, 'date' => $day]);
        $this->rec('account.bank.statement.line', 401, ['statement_id' => [301, 'ST/2026/01'], 'move_id' => [9001, 'BNK1/2026/00001'], 'amount' => 100.5, 'payment_ref' => 'إيداع عميل', 'partner_name' => 'مطعم الواحة',
            'is_reconciled' => true, 'internal_index' => '20260101000']);
        $this->rec('account.bank.statement.line', 402, ['statement_id' => false, 'move_id' => [9002, 'CSH1/2026/00001'], 'amount' => -20, 'payment_ref' => 'POS/00029', 'pos_session_id' => [29, 'POS/00029'], 'is_reconciled' => false]);
        $this->rec('account.reconcile.model', 21, ['name' => "Bank fees {$tag}", 'rule_type' => 'writeoff_button', 'match_nature' => 'amount_paid', 'match_label' => 'contains', 'match_label_param' => 'fee', 'sequence' => 5, 'match_journal_ids' => [11]], true);
        $this->rec('account.reconcile.model.line', 31, ['model_id' => [21, 'Bank fees'], 'label' => 'Bank fee', 'account_id' => [612, '620001 مصاريف بنكية'], 'amount_type' => 'percentage', 'amount_string' => '100']);
        $this->rec('account.reconcile.model', 22, ['name' => "Invoices matching {$tag}", 'rule_type' => 'invoice_matching', 'match_nature' => 'both', 'auto_reconcile' => true, 'sequence' => 10], true);
        $this->rec('account.payment.term', 41, ['name' => "30 Days {$tag}", 'sequence' => 3, 'early_discount' => false, 'note' => '<p>Payment within 30 days</p>'], true);
        $this->rec('account.payment.term.line', 51, ['payment_id' => [41, '30 Days'], 'value' => 'percent', 'value_amount' => 100, 'nb_days' => 30, 'delay_type' => 'days_after']);

        $p = $this->projection();
        $this->assertSame(['banking', true], [$p->key(), $p->ready()]);
        $before = $this->tables();
        $expected = ['journals.created' => 3, 'journals.skippedNotBank' => 1, 'journals.linked' => 2, 'journals.unlinked' => 1, 'statements.created' => 2, 'lines.created' => 2, 'lines.reconciled' => 1,
            'lines.cancelled' => 1, 'rules.created' => 2, 'rules.writeoff' => 1, 'rules.matchingModel' => 1, 'terms.created' => 1];
        $plan = $p->plan();
        $this->assertEquals($expected, $plan['counts']);
        $this->assertSame($before, $this->tables(), 'a plan writes nothing — no record, no number, no link, no bank account');
        foreach ($plan['notes']['journals.linked'] ?? [] as $note) {
            $this->assertStringNotContainsString('6789012345', $note, 'an account number is never written into a note');
        }

        $applied = $p->apply();
        $this->assertEquals($expected, $applied['counts']);
        $this->assertSame($before[5], BankAccount::count(), 'the projection never makes a bank account');
        $journals = SourceJournal::where('source_system', 'odoo')->orderBy('external_ref')->get()->keyBy('external_ref');
        $this->assertSame([$bank['id'], 'iban', 'bank'], [$journals['11']->bank_account_id, $journals['11']->linked_by, $journals['11']->kind]);
        $this->assertSame([$cash['id'], 'name', 'cash'], [$journals['12']->bank_account_id, $journals['12']->linked_by, $journals['12']->kind]);
        $this->assertNull($journals['13']->bank_account_id);
        $this->assertFalse($journals->has('14'), 'a sales journal is not this section\'s');
        $this->assertSame(['مصرف الراجحي', self::IBAN, '111001 الراجحي'], [$journals['11']->bank_name, $journals['11']->account_key, $journals['11']->ledger_account]);
        $this->assertSame($bank['id'], IntExternalRef::where('system', 'odoo')->where('entity', 'bank_account')->where('external_id', '11')->value('internal_id'));

        // history: statements and lines of the source, kept as they were, with what Odoo said of each
        $st = Statement::where('source_system', 'odoo')->where('external_ref', '301')->firstOrFail();
        $this->assertSame(['history', $bank['id'], '0.00', '100.50', $this->day(-40), 'ST/2026/01'], [$st->status, $st->bank_account_id, (string) $st->opening_balance, (string) $st->closing_balance, $st->date_from->toDateString(), $st->name]);
        $line = StatementLine::where('source_system', 'odoo')->where('external_ref', '401')->firstOrFail();
        $this->assertSame(['100.50', 'reconciled', 'DEP-11', 'إيداع عميل', 'BNK1/2026/00001', $bank['id']], [(string) $line->amount, $line->source_state, $line->reference, $line->description, $line->source_document, $line->bank_account_id]);
        $loose = StatementLine::where('source_system', 'odoo')->where('external_ref', '402')->firstOrFail();
        $this->assertSame(['cancelled', '-20.00', 'loose:12', $cash['id']], [$loose->source_state, (string) $loose->amount, $loose->statement->external_ref, $loose->bank_account_id]);
        $this->assertSame('POS/00029', $loose->extra['posSession']);

        // reconciliation models: a write-off model is a rule waiting for its OPS account and limit; a matching model never posts
        $fees = WriteoffRule::where('source_system', 'odoo')->where('external_ref', '21')->firstOrFail();
        $this->assertSame(['writeoff', 'out', 'fee', 'Bank fee', $bank['id'], null, null], [$fees->kind, $fees->nature, $fees->match_text, $fees->label, $fees->bank_account_id, $fees->account_id, $fees->max_amount]);
        $this->assertSame('620001 مصاريف بنكية', $fees->extra['lines'][0]['account']);
        $this->assertSame('matching', WriteoffRule::where('source_system', 'odoo')->where('external_ref', '22')->value('kind'));
        $term = PaymentTerm::where('source_system', 'odoo')->where('external_ref', '41')->firstOrFail();
        $this->assertEquals([30, 'Payment within 30 days', [['value' => 'percent', 'amount' => 100, 'days' => 30, 'delayType' => 'days_after']]], [$term->due_days, $term->note, $term->schedule]);

        // again: nothing new, nothing changed
        $after = $this->tables();
        $again = $p->apply();
        $this->assertEquals(['journals.unchanged' => 3, 'journals.skippedNotBank' => 1, 'journals.linkKept' => 2, 'journals.unlinked' => 1, 'statements.unchanged' => 1, 'lines.unchanged' => 2,
            'rules.unchanged' => 2, 'terms.unchanged' => 1], $again['counts']);
        $this->assertSame($after, $this->tables());
    }

    public function test_02_what_odoo_owns_is_read_only_while_the_section_follows_it(): void
    {
        $this->followOdoo(true);
        $fees = WriteoffRule::where('source_system', 'odoo')->where('external_ref', '21')->firstOrFail();
        $this->assertTrue($this->expectOk($this->getAs('gm', '/api/banking/lookups'))['follows']);
        $rule = $this->expectOk($this->getAs('gm', "/api/banking/rules/{$fees->id}"));
        $this->assertSame([true, false, 'NO_ACCOUNT'], [$rule['owned'], $rule['usable'], $rule['unusable']['code']]);
        $this->expectRejected($this->patchAs('finance', "/api/banking/rules/{$fees->id}", ['nameAr' => 'اسم آخر']), 'BANKING_OWNED_BY_SOURCE', [422]);
        // the OPS account and the limit are OPS's own
        $made = $this->expectOk($this->patchAs('finance', "/api/banking/rules/{$fees->id}", ['account' => $this->expenseAccount(), 'maxAmount' => '25.00']));
        $this->assertSame([true, '25.00', 'Bank fee'], [$made['usable'], $made['maxAmount'], $made['label']]);

        $history = Statement::where('source_system', 'odoo')->where('external_ref', '301')->firstOrFail();
        $detail = $this->statement($history->number);
        $this->assertSame([true, true, false, true], [$detail['history'], $detail['owned'], $detail['can']['lines'], $detail['can']['note']]);
        $this->expectRejected($this->postAs('finance', "/api/banking/statements/{$history->number}/lines", ['date' => $this->day(-40), 'amount' => '5']), 'BANKING_OWNED_BY_SOURCE', [422]);
        $this->expectRejected($this->postAs('finance', "/api/banking/statements/{$history->number}/reconcile"), 'BANKING_OWNED_BY_SOURCE', [422]);
        $line = StatementLine::where('source_system', 'odoo')->where('external_ref', '401')->firstOrFail();
        $this->expectRejected($this->patchAs('finance', "/api/banking/lines/{$line->id}", ['amount' => '1']), 'BANKING_OWNED_BY_SOURCE', [422]);
        $this->assertSame('ملاحظة من العمليات', $this->expectOk($this->patchAs('finance', "/api/banking/statements/{$history->number}", ['notes' => 'ملاحظة من العمليات']))['notes'], 'a note is OPS\'s own addition');
        // history never takes part in the OPS reconciliation: no proposals for its lines, no overlap with an OPS statement
        $this->assertSame([], $this->expectOk($this->getAs('finance', "/api/banking/lines/{$line->id}/suggestions"))['suggestions']);
        $ops = $this->newStatement(self::$s['bank']['code'], $this->day(-45), $this->day(-30), '0', '0');
        $this->assertSame('open', $ops['status']);
        $this->assertSame([1, 1], [
            $this->expectOk($this->getAs('gm', '/api/banking/statements?bank='.self::$s['bank']['code'].'&origin=odoo&status=history'))['total'],
            $this->expectOk($this->getAs('gm', '/api/banking/statements?bank='.self::$s['bank']['code'].'&origin=ops'))['total'],
        ]);

        // the source system's terms, read-only, as a dictionary
        $terms = $this->expectOk($this->getAs('gm', '/api/banking/payment-terms?origin=odoo&q='.urlencode(self::$s['tag'])))['items'];
        $this->assertSame([[30, true]], array_map(fn (array $t) => [$t['dueDays'], $t['owned']], $terms));
    }

    public function test_03_a_person_links_what_the_projection_could_not_and_a_removed_link_stays_removed(): void
    {
        $this->followOdoo(true);
        $list = $this->expectOk($this->getAs('gm', '/api/banking/journals?linked=0&q='.urlencode(self::$s['tag'])));
        $this->assertSame(1, $list['total']);
        $orphan = $list['items'][0];
        $this->assertSame(['BNK9', false], [$orphan['code'], $orphan['linked']]);
        $rajhi = SourceJournal::where('source_system', 'odoo')->where('external_ref', '11')->firstOrFail();
        // an account number leaves in full only for a banking permission
        $this->assertSame('••••2345', collect($this->expectOk($this->getAs('gm', '/api/banking/journals?q=BNK1'))['items'])->firstWhere('id', $rajhi->id)['accountNumber']);
        $this->assertSame('SA11 8000 0123 4567 8901 2345', collect($this->expectOk($this->getAs('finance', '/api/banking/journals?q=BNK1'))['items'])->firstWhere('id', $rajhi->id)['accountNumber']);

        // one bank account answers to one journal
        $this->expectRejected($this->postAs('finance', "/api/banking/journals/{$orphan['id']}/link", ['bankAccount' => self::$s['bank']['code']]), 'BANK_ACCOUNT_LINKED', [409]);
        $this->expectRejected($this->postAs('gm', "/api/banking/journals/{$orphan['id']}/link", ['bankAccount' => self::$s['bank']['code']]), 'FORBIDDEN', [403]);
        // adopting makes an OPS bank account through finance's own service — which needs bank.manage
        $this->expectRejected($this->postAs($this->clerk(), "/api/banking/journals/{$orphan['id']}/adopt", ['code' => 'ADP'.self::uid()]), 'FORBIDDEN', [403]);
        $banks = BankAccount::count();
        $adopted = $this->expectOk($this->postAs('finance', "/api/banking/journals/{$orphan['id']}/adopt", ['code' => 'ADP'.self::uid()]));
        $this->assertSame([true, 'adopted', $banks + 1], [$adopted['linked'], $adopted['linkedBy'], BankAccount::count()]);
        $this->assertNull(BankAccount::findOrFail(SourceJournal::find($orphan['id'])->bank_account_id)->iban, 'the account number is typed in finance by a person, never copied');
        $this->expectRejected($this->postAs('finance', "/api/banking/journals/{$orphan['id']}/adopt", []), 'BANK_JOURNAL_LINKED', [422]);

        // a person removes the projection's link: with a reason, and the next pass does not make it again
        $this->expectRejected($this->postAs('finance', "/api/banking/journals/{$rajhi->id}/unlink", ['reason' => 'لا']), null, [400]);
        $unlinked = $this->expectOk($this->postAs('finance', "/api/banking/journals/{$rajhi->id}/unlink", ['reason' => 'الدفتر لحساب آخر لدى البنك']));
        $this->assertFalse($unlinked['linked']);
        $this->assertNull(Statement::where('source_system', 'odoo')->where('external_ref', '301')->value('bank_account_id'), 'its history follows the link');
        $this->assertNull(IntExternalRef::where('system', 'odoo')->where('entity', 'bank_account')->where('external_id', '11')->first());
        $counts = $this->projection()->apply()['counts'];
        $this->assertSame([1, 2], [$counts['journals.unlinked'], $counts['journals.linkKept']], 'the journal a person unlinked waits for a person; the adopted one keeps its link');
        $this->assertNull(SourceJournal::find($rajhi->id)->bank_account_id);
        $relinked = $this->expectOk($this->postAs('finance', "/api/banking/journals/{$rajhi->id}/link", ['bankAccount' => self::$s['bank']['code']]));
        $this->assertSame(['manual', self::$s['bank']['id']], [$relinked['linkedBy'], Statement::where('source_system', 'odoo')->where('external_ref', '301')->value('bank_account_id')]);
    }

    public function test_04_a_change_in_odoo_reaches_the_section_through_the_feed(): void
    {
        $this->followOdoo(true);
        $current = OdooRecord::where('model', 'account.journal')->where('odoo_id', 11)->firstOrFail();
        $this->rec('account.journal', 11, ['name' => 'Al Rajhi — main', 'write_date' => '2026-09-10 11:00:00'] + (array) $current->data, true);
        OdooChange::create(['model' => 'account.journal', 'odoo_id' => 11, 'kind' => 'updated', 'seen_at' => now()]);
        $this->rec('account.payment.term.line', 51, ['payment_id' => [41, '30 Days'], 'value' => 'percent', 'value_amount' => 100, 'nb_days' => 45, 'delay_type' => 'days_after', 'write_date' => '2026-09-10 11:00:00']);
        OdooChange::create(['model' => 'account.payment.term.line', 'odoo_id' => 51, 'kind' => 'updated', 'seen_at' => now()]);
        $this->projection()->consume();
        $journal = SourceJournal::where('source_system', 'odoo')->where('external_ref', '11')->firstOrFail();
        $this->assertSame(['Al Rajhi — main', self::$s['bank']['id'], 'manual'], [$journal->name, $journal->bank_account_id, $journal->linked_by], 'Odoo\'s facts follow Odoo; the link is OPS\'s and stays');
        $this->assertSame(45, PaymentTerm::where('source_system', 'odoo')->where('external_ref', '41')->value('due_days'), 'a changed instalment re-reads its term');
        $this->assertSame(0, AuditLog::where('entity_type', 'BankSourceJournal')->where(fn ($w) => $w->where('new_value', 'like', '%6789012345%')->orWhere('old_value', 'like', '%6789012345%'))->count(), 'no account number in the trail');
    }
}
