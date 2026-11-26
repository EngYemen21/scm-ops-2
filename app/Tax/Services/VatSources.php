<?php

namespace App\Tax\Services;

use App\Finance\Models\Account;
use App\Services\Core\SettingsService;
use App\Tax\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * What the books hold for a VAT return: every OPS document that was posted in a period, with its base, the VAT its
 * journal really put on the VAT accounts, the tax code it is of — and every movement on a VAT account that is NOT a
 * document of the return (a manual journal, a payment to the authority, an opening balance).
 *
 * THE RULE. A document enters the return through its journal, on the journal's date:
 *   customer invoice / credit note   `ci:` / `cn:` journals of fin_customer_invoices (the cost-of-sales journal is not VAT)
 *   supplier invoice                 its approval journal `si:` (an invoice that is not approved is not in the books)
 *   supplier debit note              `dn:`
 *   expense voucher                  `exp:` (HR claims and commission payments post through it)
 *   fuel record / maintenance order  `fuel:` / `maint:` (they carry VAT only when the fleet policy splits it out)
 * and a reversal journal (a cancelled invoice, a voided voucher) is its own entry with the opposite sign, on its own
 * date. So a figure of the return is always "these journals", and the VAT of the return IS the ledger's VAT for them.
 *
 * Amounts are halalas. The base comes from the document (invoices, notes) or from the journal itself (vouchers, fleet);
 * the VAT comes from the journal's lines on the VAT accounts, signed the natural way (output: credit − debit; input:
 * debit − credit). A reverse-charge purchase has no VAT in the books: its VAT is the code's rate on the base, declared
 * on both sides.
 */
class VatSources
{
    /** source type of a journal → [table, the side it stands on] */
    public const SOURCES = [
        'CustomerInvoice' => 'fin_customer_invoices', 'SupplierInvoice' => 'supplier_invoices', 'DebitNote' => 'fin_debit_notes',
        'Expense' => 'fin_expenses', 'FuelRecord' => 'fuel_records', 'MaintenanceOrder' => 'maintenance_orders',
    ];

    public const DOC_LABELS = [
        'invoice' => ['فاتورة عميل', 'Customer invoice'], 'credit_note' => ['إشعار دائن', 'Credit note'], 'supplier_invoice' => ['فاتورة مورد', 'Supplier invoice'],
        'debit_note' => ['إشعار مدين لمورد', 'Supplier debit note'], 'expense' => ['سند مصروف', 'Expense voucher'], 'fuel' => ['سجل وقود', 'Fuel record'], 'maintenance' => ['أمر صيانة', 'Maintenance order'],
    ];

    public function __construct(private readonly Classifier $classifier, private readonly SettingsService $settings) {}

    /** The VAT accounts of the ledger: id → [side, code, names]. The two control roles, and any other account of subtype `tax`. */
    public function accounts(): array
    {
        $out = [];
        foreach (Account::where(fn ($w) => $w->whereIn('role', ['vat_output', 'vat_input'])->orWhere('subtype', 'tax'))->orderBy('code')->get(['id', 'code', 'name_ar', 'name_en', 'type', 'role']) as $a) {
            $side = $a->role === 'vat_output' ? 'output' : ($a->role === 'vat_input' ? 'input' : ($a->type === 'liability' ? 'output' : 'input'));
            $out[(string) $a->id] = ['side' => $side, 'code' => $a->code, 'nameAr' => $a->name_ar, 'nameEn' => $a->name_en];
        }

        return $out;
    }

    /**
     * @param  string|null  $createdFrom  only journals written at or after this moment (what reached a period after it was declared)
     * @return array{entries: array<int, array<string, mixed>>, other: array<int, array<string, mixed>>, ledger: array{output:int, input:int}, accounts: array}
     */
    public function collect(string $from, string $to, ?string $createdFrom = null): array
    {
        $this->classifier->flush();
        $accounts = $this->accounts();
        $window = fn ($q, string $alias) => $q->where("{$alias}.status", '!=', 'draft')->whereBetween("{$alias}.journal_date", [$from, $to])
            ->when($createdFrom, fn ($w) => $w->where("{$alias}.created_at", '>=', $createdFrom));

        // ── every movement on a VAT account in the window
        $lines = $accounts ? $window(DB::table('fin_journal_lines as l')->join('fin_journals as j', 'j.id', '=', 'l.journal_id'), 'j')->whereIn('l.account_id', array_keys($accounts))
            ->orderBy('j.journal_date')->orderBy('j.number')->orderBy('l.line_no')
            ->get(['l.id', 'l.journal_id', 'l.account_id', 'l.debit', 'l.credit', 'l.description', 'j.number as journal', 'j.journal_date as date', 'j.memo', 'j.source_type', 'j.source_number', 'j.kind'])
            : collect();
        $vat = [];      // journal id → ['output' => halalas, 'input' => halalas]
        $ledger = ['output' => 0, 'input' => 0];
        foreach ($lines as $l) {
            $side = $accounts[(string) $l->account_id]['side'];
            $amount = $side === 'output' ? Money::minor($l->credit) - Money::minor($l->debit) : Money::minor($l->debit) - Money::minor($l->credit);
            $vat[(string) $l->journal_id][$side] = ($vat[(string) $l->journal_id][$side] ?? 0) + $amount;
            $ledger[$side] += $amount;
        }

        // ── the journals of documents
        $journals = $window(DB::table('fin_journals as j'), 'j')->whereIn('j.source_type', array_keys(self::SOURCES))->orderBy('j.journal_date')->orderBy('j.number')
            ->get(['j.id', 'j.number', 'j.journal_date', 'j.kind', 'j.source_type', 'j.source_id', 'j.source_number', 'j.reversal_of_id', 'j.total_debit']);
        $docs = $this->documents($journals);
        $fleetRate = Money::rate($this->settings->get('sales.vatPct') ?? 15);

        $entries = [];
        $consumed = []; // "journal id|side" → the VAT lines of that side belong to a document
        foreach ($journals as $j) {
            $doc = $docs[$j->source_type][(string) $j->source_id] ?? null;
            if (! $doc) {
                continue; // a journal that names a document which is not there: its VAT lines stay "other" and are explained by a person
            }
            $reversal = $j->kind === 'reversal';
            if ($j->source_type === 'CustomerInvoice') {
                // the invoice has two journals: revenue + VAT, and cost of sales. Only the first (and its reversal) is VAT.
                if ((string) ($reversal ? $j->reversal_of_id : $j->id) !== (string) $doc->journal_id) {
                    continue;
                }
            }
            $side = $j->source_type === 'CustomerInvoice' ? 'sale' : 'purchase';
            $ledgerSide = $side === 'sale' ? 'output' : 'input';
            $ledgerVat = $vat[(string) $j->id][$ledgerSide] ?? 0;
            $consumed[$j->id.'|'.$ledgerSide] = true;
            [$kind, $noteSign] = match ($j->source_type) {
                'CustomerInvoice' => [$doc->kind === 'credit_note' ? 'credit_note' : 'invoice', $doc->kind === 'credit_note' ? -1 : 1],
                'SupplierInvoice' => ['supplier_invoice', 1], 'DebitNote' => ['debit_note', -1], 'Expense' => ['expense', 1], 'FuelRecord' => ['fuel', 1], default => ['maintenance', 1],
            };
            $sign = $noteSign * ($reversal ? -1 : 1);
            // base and the document's own VAT, in halalas, unsigned
            [$base, $docVat, $rate] = match ($j->source_type) {
                'CustomerInvoice' => [Money::minor($doc->subtotal) - Money::minor($doc->discount), Money::minor($doc->vat_amount), Money::rate($doc->vat_pct)],
                'SupplierInvoice' => [Money::minor($doc->subtotal) - Money::minor($doc->discount) + Money::minor($doc->shipping_cost), Money::minor($doc->vat_amount), Money::rate($doc->vat_pct)],
                'DebitNote' => [Money::minor($doc->subtotal), Money::minor($doc->vat_amount), Money::rate($doc->vat_pct)],
                // a voucher is read from its own journal (a corrected fuel record has one journal per version)
                'Expense' => [Money::minor($j->total_debit) - abs($ledgerVat), abs($ledgerVat), abs($ledgerVat) > 0 ? Money::rate($doc->vat_pct) : '0'],
                default => [Money::minor($j->total_debit) - abs($ledgerVat), abs($ledgerVat), abs($ledgerVat) > 0 ? $fleetRate : '0'],
            };
            $partnerType = $side === 'sale' ? 'customer' : 'supplier';
            $partnerId = $side === 'sale' ? ($doc->customer_id ?? null) : ($doc->supplier_id ?? null);
            $entries[] = [
                'entry_key' => (string) $j->id, 'journal_id' => (string) $j->id, 'journal_number' => $j->number, 'entry_date' => substr((string) $j->journal_date, 0, 10), 'doc_type' => $j->source_type, 'doc_id' => (string) $j->source_id,
                'doc_number' => (string) ($doc->number ?? $j->source_number), 'doc_kind' => $kind, 'reversal' => $reversal, 'side' => $side, 'col' => $reversal || $noteSign < 0 ? 'adjustment' : 'amount',
                'base' => $sign * $base, 'vat' => $ledgerVat, 'doc_vat' => $sign * $docVat, 'rate' => $rate,
                'partner_type' => $partnerId ? $partnerType : null, 'partner_id' => $partnerId ? (string) $partnerId : null,
                'parent_id' => $kind === 'credit_note' && ! empty($doc->credit_of_id) ? (string) $doc->credit_of_id : null,
            ];
        }
        // a supplier debit note that had nothing to post (no VAT, claimed at cost) has no journal — it still lowers the purchases of its day
        $silent = DB::table('fin_debit_notes')->whereNull('journal_id')->where('status', 'issued')->whereBetween('date', ["{$from} 00:00:00", "{$to} 23:59:59.999"])
            ->when($createdFrom, fn ($w) => $w->where('created_at', '>=', $createdFrom))->orderBy('date')->orderBy('number')->get(['id', 'number', 'supplier_id', 'subtotal', 'vat_pct', 'vat_amount', 'date']);
        foreach ($silent as $note) {
            $entries[] = [
                'entry_key' => "doc:DebitNote:{$note->id}", 'journal_id' => null, 'journal_number' => null, 'entry_date' => substr((string) $note->date, 0, 10), 'doc_type' => 'DebitNote', 'doc_id' => (string) $note->id,
                'doc_number' => (string) $note->number, 'doc_kind' => 'debit_note', 'reversal' => false, 'side' => 'purchase', 'col' => 'adjustment',
                'base' => -Money::minor($note->subtotal), 'vat' => 0, 'doc_vat' => -Money::minor($note->vat_amount), 'rate' => Money::rate($note->vat_pct),
                'partner_type' => 'supplier', 'partner_id' => (string) $note->supplier_id,
            ];
        }

        // ── the code of each document, and what follows from it
        $found = $this->classifier->resolve(array_map(fn (array $e) => ['docType' => $e['doc_type'], 'docId' => $e['doc_id'], 'use' => $e['side'], 'rate' => $e['rate'],
            'partnerType' => $e['partner_type'], 'partnerId' => $e['partner_id'], 'parentId' => $e['parent_id'] ?? null], $entries));
        $names = $this->partnerNames($entries);
        foreach ($entries as $i => $e) {
            $hit = $found["{$e['doc_type']}:{$e['doc_id']}"];
            $code = $hit['code'];
            // a code that should stand on a line of the return and names none is a code a person still has to complete
            $how = $code && $code->return_box === null && $code->kind !== 'out_of_scope' ? 'nobox' : $hit['how'];
            $entries[$i] += ['code_id' => $code?->id, 'code' => $code?->code, 'classified' => $how, 'box' => $code?->return_box,
                'partner_name' => $e['partner_id'] ? ($names[$e['partner_type']][$e['partner_id']] ?? null) : null, 'notional' => false];
            if ($code && $code->kind === 'reverse_charge') {
                // not in the books: the rate of the code on the base, due and deductible at once
                $entries[$i]['vat'] = Money::percent($e['base'], Money::rate($code->rate));
                $entries[$i]['doc_vat'] = $entries[$i]['vat'];
                $entries[$i]['notional'] = true;
            }
        }

        // ── the movements no document stands behind
        $other = [];
        foreach ($lines as $l) {
            $side = $accounts[(string) $l->account_id]['side'];
            if (isset($consumed[$l->journal_id.'|'.$side])) {
                continue;
            }
            $other[] = [
                'journalLineId' => (string) $l->id, 'journalId' => (string) $l->journal_id, 'journal' => $l->journal, 'date' => substr((string) $l->date, 0, 10), 'side' => $side,
                'account' => $accounts[(string) $l->account_id]['code'], 'accountNameAr' => $accounts[(string) $l->account_id]['nameAr'], 'accountNameEn' => $accounts[(string) $l->account_id]['nameEn'],
                'debit' => Money::minor($l->debit), 'credit' => Money::minor($l->credit),
                'amount' => $side === 'output' ? Money::minor($l->credit) - Money::minor($l->debit) : Money::minor($l->debit) - Money::minor($l->credit),
                'text' => $l->description ?: $l->memo, 'sourceType' => $l->source_type, 'sourceNumber' => $l->source_number, 'kind' => $l->kind,
            ];
        }

        return ['entries' => $entries, 'other' => $other, 'ledger' => $ledger, 'accounts' => $accounts];
    }

    /**
     * The documents the journals name, read once per kind.
     *
     * @return array<string, array<string, object>> source type → id → row
     */
    private function documents(iterable $journals): array
    {
        $ids = [];
        foreach ($journals as $j) {
            if ($j->source_id !== null) {
                $ids[$j->source_type][(string) $j->source_id] = true;
            }
        }
        $columns = [
            'CustomerInvoice' => ['id', 'number', 'kind', 'customer_id', 'subtotal', 'discount', 'vat_pct', 'vat_amount', 'journal_id', 'credit_of_id'],
            'SupplierInvoice' => ['id', 'number', 'supplier_id', 'subtotal', 'discount', 'shipping_cost', 'vat_pct', 'vat_amount'],
            'DebitNote' => ['id', 'number', 'supplier_id', 'subtotal', 'vat_pct', 'vat_amount'],
            'Expense' => ['id', 'number', 'supplier_id', 'vat_pct'],
            'FuelRecord' => ['id', 'number'], 'MaintenanceOrder' => ['id', 'number'],
        ];
        $out = [];
        foreach ($ids as $type => $set) {
            foreach (array_chunk(array_keys($set), 1000) as $chunk) {
                foreach (DB::table(self::SOURCES[$type])->whereIn('id', $chunk)->get($columns[$type]) as $row) {
                    $out[$type][(string) $row->id] = $row;
                }
            }
        }

        return $out;
    }

    /** @return array{customer?: array<string, string>, supplier?: array<string, string>} partner id → name */
    private function partnerNames(array $entries): array
    {
        $ids = ['customer' => [], 'supplier' => []];
        foreach ($entries as $e) {
            if ($e['partner_id']) {
                $ids[$e['partner_type']][$e['partner_id']] = true;
            }
        }
        $out = [];
        foreach (['customer' => 'customers', 'supplier' => 'suppliers'] as $type => $table) {
            foreach (array_chunk(array_keys($ids[$type]), 1000) as $chunk) {
                foreach (DB::table($table)->whereIn('id', $chunk)->get(['id', 'name_ar']) as $row) {
                    $out[$type][(string) $row->id] = mb_substr((string) $row->name_ar, 0, 190);
                }
            }
        }

        return $out;
    }
}
