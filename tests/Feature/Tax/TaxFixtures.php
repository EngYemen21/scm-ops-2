<?php

namespace Tests\Feature\Tax;

use App\Finance\Models\CustomerInvoice;
use App\Finance\Models\CustomerInvoiceLine;
use App\Finance\Services\LedgerService;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Support\AuthUser;
use App\Tax\Models\TaxProfile;
use App\Tax\Services\TaxProfileService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Documents of the books, made the way finance makes them: the document row and its journal posted through the
 * ledger service with the same roles, event keys and source the finance services use (`ci:` / `cn:` / `si:`), so the
 * tax section reads exactly what it reads in production. Amounts are given in riyals as decimal text.
 */
trait TaxFixtures
{
    /** The taxpayer, complete: what an e-invoice needs of the seller. */
    protected function completeProfile(array $over = []): TaxProfile
    {
        $p = app(TaxProfileService::class)->profile();
        $p->update($over + [
            'legal_name_ar' => 'شركة العمليات التجريبية', 'legal_name_en' => 'OPS Test Trading Co.', 'vat_no' => '399999999900003', 'id_scheme' => 'CRN', 'id_value' => '1010010000',
            'street' => 'طريق الملك فهد', 'building_no' => '2322', 'additional_no' => '1234', 'district' => 'العليا', 'city' => 'الرياض', 'postal_code' => '12345', 'country_code' => 'SA',
            'einvoice_auto' => true, 'einvoice_from' => '2020-01-01',
        ]);

        return $p->refresh();
    }

    protected function customer(string $tag, array $more = []): Customer
    {
        return Customer::create($more + ['code' => "TXC-{$tag}", 'name_ar' => "عميل ضريبة {$tag}", 'name_en' => "Tax customer {$tag}", 'city' => 'الرياض', 'zone' => 'العليا',
            'contact' => '0550000000', 'terms' => 'نقدي / محفظة'])->refresh();
    }

    protected function supplier(string $tag, array $more = []): Supplier
    {
        return Supplier::create($more + ['code' => "TXS-{$tag}", 'name_ar' => "مورد ضريبة {$tag}", 'name_en' => "Tax supplier {$tag}"])->refresh();
    }

    protected function product(): Product
    {
        return Product::where('sku', 'TAX-ITEM-1')->first()
            ?? Product::create(['sku' => 'TAX-ITEM-1', 'name_ar' => 'صنف اختبار الضريبة', 'name_en' => 'Tax test item', 'storage_class' => 'ambient', 'tracks_expiry' => false]);
    }

    /**
     * A customer invoice (or, with `$creditOf`, a credit note of that invoice) of one line `qty × price`, its VAT at
     * `$vatPct` rounded once, posted on `$date` exactly as CustomerInvoiceService posts it.
     */
    protected function customerInvoice(Customer $c, string $date, int $qty, string $price, float $vatPct = 15, ?CustomerInvoice $creditOf = null, ?string $notes = null): CustomerInvoice
    {
        return DB::transaction(function () use ($c, $date, $qty, $price, $vatPct, $creditOf, $notes) {
            $subtotal = round($qty * (float) $price, 2);
            $vat = round($subtotal * $vatPct / 100, 2);
            $credit = $creditOf !== null;
            $number = ($credit ? 'TCN-' : 'TCI-').strtoupper(Str::random(8));
            $inv = CustomerInvoice::create([
                'number' => $number, 'kind' => $credit ? 'credit_note' : 'invoice', 'customer_id' => $c->id, 'credit_of_id' => $creditOf?->id, 'invoice_date' => $date, 'due_date' => $date,
                'status' => 'issued', 'subtotal' => $subtotal, 'discount' => 0, 'vat_pct' => $vatPct, 'vat_amount' => $vat, 'total' => $subtotal + $vat, 'paid_amount' => 0, 'cogs_total' => 0,
                'notes' => $notes, 'created_by' => 'finance', 'created_at' => Carbon::parse($date.' 09:30:00', 'Asia/Riyadh')->utc(),
            ]);
            CustomerInvoiceLine::create(['invoice_id' => $inv->id, 'line_no' => 1, 'product_id' => $this->product()->id, 'qty' => $qty, 'price' => $price, 'disc_pct' => 0, 'line_total' => $subtotal, 'unit_cost' => 0, 'cogs' => 0]);
            $partner = ['partnerType' => 'customer', 'partnerId' => $c->id];
            $lines = $credit
                ? array_merge([['role' => 'revenue', 'debit' => $subtotal]], $vat > 0 ? [['role' => 'vat_output', 'debit' => $vat]] : [], [['role' => 'ar', 'credit' => $subtotal + $vat] + $partner])
                : array_merge([['role' => 'ar', 'debit' => $subtotal + $vat] + $partner, ['role' => 'revenue', 'credit' => $subtotal]], $vat > 0 ? [['role' => 'vat_output', 'credit' => $vat]] : []);
            $journal = app(LedgerService::class)->post(null, ['eventKey' => ($credit ? 'cn:' : 'ci:').$inv->id, 'date' => $date, 'source' => ['type' => 'CustomerInvoice', 'id' => $inv->id, 'number' => $number],
                'memo' => "test {$number}", 'lines' => $lines]);
            $inv->update(['journal_id' => $journal->id]);

            return $inv->refresh();
        });
    }

    /** An approved supplier invoice of `$subtotal` with its VAT at `$vatPct`, posted on `$date` as the approval posts it. */
    protected function supplierInvoice(Supplier $s, string $date, string $subtotal, float $vatPct = 15): string
    {
        return DB::transaction(function () use ($s, $date, $subtotal, $vatPct) {
            $sub = (float) $subtotal;
            $vat = round($sub * $vatPct / 100, 2);
            $id = (string) Str::ulid();
            $number = 'TSI-'.strtoupper(Str::random(8));
            DB::table('supplier_invoices')->insert(['id' => $id, 'number' => $number, 'po_id' => (string) Str::ulid(), 'supplier_id' => $s->id, 'supplier_invoice_no' => $number, 'invoice_date' => $date,
                'subtotal' => $sub, 'discount' => 0, 'shipping_cost' => 0, 'vat_pct' => $vatPct, 'vat_amount' => $vat, 'total' => $sub + $vat, 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
            $journal = app(LedgerService::class)->post(null, ['eventKey' => "si:{$id}", 'date' => $date, 'source' => ['type' => 'SupplierInvoice', 'id' => $id, 'number' => $number], 'memo' => "test {$number}",
                'lines' => array_merge([['role' => 'grni', 'debit' => $sub]], $vat > 0 ? [['role' => 'vat_input', 'debit' => $vat]] : [], [['role' => 'ap', 'credit' => $sub + $vat, 'partnerType' => 'supplier', 'partnerId' => $s->id]])]);
            DB::table('supplier_invoices')->where('id', $id)->update(['journal_id' => $journal->id]);

            return $id;
        });
    }

    /** A movement on a VAT account that no document stands behind (a payment to the authority). @return string the VAT line's id */
    protected function vatPayment(string $date, string $amount, string $role = 'vat_output'): string
    {
        return DB::transaction(function () use ($date, $amount, $role) {
            $j = app(LedgerService::class)->post(null, ['date' => $date, 'memo' => 'سداد ضريبة للهيئة (اختبار)', 'lines' => [['role' => $role, 'debit' => (float) $amount], ['role' => 'bank', 'credit' => (float) $amount]]]);

            return (string) $j->lines->firstWhere('debit', '>', 0)->id;
        });
    }

    protected static function actor(string $username): AuthUser
    {
        $u = DB::table('users')->where('username', $username)->first();

        return new AuthUser((string) $u->id, $u->username, $u->name_ar, $u->name_en, [], ['page.tax', 'vatreturn.manage', 'vatreturn.file', 'einvoice.manage'], [], null, 'tax-test');
    }
}
