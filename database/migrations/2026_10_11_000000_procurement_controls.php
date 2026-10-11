<?php

use App\Support\PermissionSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Procurement controls (docs/procurement/GAP-ANALYSIS.ar.md): landed cost in quotations, PO totals with discount /
 * shipping / VAT, PO follow-up, pack conversion on products, supplier profile + documents, supplier invoices with
 * three-way matching and payables. Additive only — existing rows keep working with the defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_quotations', function (Blueprint $table) {
            $table->decimal('shipping_cost', 14, 2)->default(0);   // compared as landed total, never unit price alone
            $table->integer('warranty_months')->nullable();
        });

        Schema::table('rfqs', function (Blueprint $table) {
            $table->text('award_reason')->nullable();      // why this quotation (mandatory when it is not the recommended one)
            $table->text('quotes_exception')->nullable();  // why fewer quotations than the policy minimum were accepted
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('shipping_cost', 14, 2)->default(0);
            $table->double('vat_pct')->default(15);
            $table->decimal('gross_total', 16, 2)->default(0);  // (total − discount + shipping) × (1 + VAT)
            $table->text('follow_up_note')->nullable();          // delivery follow-up: last contact / delay reason
            $table->dateTime('followed_up_at', 3)->nullable();
            $table->string('followed_up_by')->nullable();
            $table->dateTime('late_alerted_at', 3)->nullable();  // the overdue alert was sent once
        });

        Schema::table('products', function (Blueprint $table) {
            $table->integer('pack_qty')->default(1);            // base units per purchase pack (carton of 12)
            $table->ulid('pack_uom_id')->nullable();            // the purchase pack unit (carton, bag…)
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('status', 20)->default('active');    // active | review | suspended (active=false mirrors suspended)
            $table->string('bank_name')->nullable();
            $table->string('bank_account_name')->nullable();
            $table->text('address')->nullable();                 // national address
            $table->decimal('credit_limit', 14, 2)->nullable();
        });

        // Supplier qualification documents with expiry (CR, VAT certificate, licences…). The file itself: Integration Pending.
        Schema::create('supplier_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('supplier_id');
            $table->string('type', 40);                         // cr | vat | license | iso | insurance | other
            $table->string('number')->nullable();
            $table->dateTime('issued_at', 3)->nullable();
            $table->dateTime('expires_at', 3)->nullable();
            $table->boolean('mandatory')->default(false);       // an expired mandatory document blocks new POs
            $table->text('notes')->nullable();
            $table->string('file_name')->nullable();
            $table->string('created_by')->nullable();
            $table->dateTime('expiry_alerted_at', 3)->nullable();
            $table->timestamps(3);
            $table->index(['supplier_id'], 'supplier_documents_supplier_ix');
            $table->index(['expires_at'], 'supplier_documents_expires_ix');
        });

        // Supplier invoices: registered against a PO, matched three ways (PO price × GRN accepted quantity), then
        // approved for payment and marked paid. Payment execution itself happens in the finance system.
        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('number')->unique();                 // INV-YYYY-#####
            $table->ulid('po_id');
            $table->ulid('supplier_id');
            $table->string('supplier_invoice_no');
            $table->dateTime('invoice_date', 3);
            $table->dateTime('due_date', 3)->nullable();
            $table->decimal('subtotal', 16, 2)->default(0);     // Σ qty × price
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('shipping_cost', 14, 2)->default(0);
            $table->double('vat_pct')->default(15);
            $table->decimal('vat_amount', 16, 2)->default(0);
            $table->decimal('total', 16, 2)->default(0);
            $table->string('status', 20)->default('matched');   // matched | disputed | approved | paid | cancelled
            $table->json('variances')->nullable();              // [{lineNo, kind, expected, actual, diff}]
            $table->text('notes')->nullable();
            $table->string('registered_by')->nullable();
            $table->string('approved_by')->nullable();
            $table->dateTime('approved_at', 3)->nullable();
            $table->text('approval_note')->nullable();
            $table->dateTime('paid_at', 3)->nullable();
            $table->string('paid_by')->nullable();
            $table->string('payment_ref')->nullable();
            $table->timestamps(3);
            $table->unique(['supplier_id', 'supplier_invoice_no'], 'supplier_invoices_supplier_no_uq');
            $table->index(['po_id'], 'supplier_invoices_po_ix');
            $table->index(['status'], 'supplier_invoices_status_ix');
            $table->index(['due_date'], 'supplier_invoices_due_ix');
        });

        Schema::create('supplier_invoice_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('invoice_id');
            $table->integer('line_no');
            $table->ulid('po_line_id');
            $table->ulid('product_id');
            $table->integer('qty');
            $table->decimal('price', 14, 2);
            $table->decimal('po_price', 14, 2);                 // snapshot of the PO price it was matched against
            $table->integer('received_qty');                    // accepted on GRNs at registration time
            $table->integer('previously_invoiced_qty');
            $table->string('match', 20)->default('ok');         // ok | qty | price | both
            $table->unique(['invoice_id', 'line_no'], 'supplier_invoice_lines_line_uq');
            $table->index(['po_line_id'], 'supplier_invoice_lines_po_line_ix');
        });

        // existing orders: gross = goods × (1 + default VAT) so the follow-up board and the invoice match have a basis
        DB::table('purchase_orders')->where('gross_total', 0)->where('total', '>', 0)->update(['gross_total' => DB::raw('round(total * 1.15, 2)')]);

        // new permissions (supplier invoices) with their default role grants — only what is missing is inserted
        PermissionSync::ensure();
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_invoice_lines');
        Schema::dropIfExists('supplier_invoices');
        Schema::dropIfExists('supplier_documents');
        Schema::table('suppliers', fn (Blueprint $t) => $t->dropColumn(['status', 'bank_name', 'bank_account_name', 'address', 'credit_limit']));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['pack_qty', 'pack_uom_id']));
        Schema::table('purchase_orders', fn (Blueprint $t) => $t->dropColumn(['discount', 'shipping_cost', 'vat_pct', 'gross_total', 'follow_up_note', 'followed_up_at', 'followed_up_by', 'late_alerted_at']));
        Schema::table('rfqs', fn (Blueprint $t) => $t->dropColumn(['award_reason', 'quotes_exception']));
        Schema::table('supplier_quotations', fn (Blueprint $t) => $t->dropColumn(['shipping_cost', 'warranty_months']));
    }
};
