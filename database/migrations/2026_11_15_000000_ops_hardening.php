<?php

use App\Support\PermissionSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operational hardening (docs/ops-hardening/DECISIONS.ar.md): the schema of every decision taken on the six review
 * lists of 2026-11 — fleet, picking / packing, loading / dispatch, sales, returns / transfers, procurement / receiving.
 * Additive only: new tables and nullable / defaulted columns; no existing data is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── stock held for a document that is not a sales order (approved transfer, supplier return from the shelf).
        //    reserved += qty on the balance row, exactly like a sales allocation; reconcile() counts both.
        Schema::create('inventory_holds', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('owner_type');                       // WarehouseTransfer | ReturnOrder
            $t->ulid('owner_id');
            $t->string('owner_number')->nullable();
            $t->ulid('owner_line_id')->nullable();
            $t->ulid('product_id');
            $t->ulid('warehouse_id');
            $t->ulid('bin_id');
            $t->ulid('batch_id')->nullable();
            $t->integer('qty');
            $t->string('status')->default('active');        // active | consumed | released
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('released_at', 3)->nullable();
            $t->index(['owner_type', 'owner_id'], 'inventory_holds_owner_ix');
            $t->index(['product_id', 'bin_id'], 'inventory_holds_row_ix');
        });

        // ── one row and one label per carton of a packed order ("1 of N"); the code is what loading and delivery scan
        Schema::create('cartons', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('code')->unique();                   // FO-3750-C01
            $t->ulid('fo_id');
            $t->ulid('package_id')->nullable();
            $t->integer('seq');
            $t->double('weight_kg')->nullable();
            $t->string('status')->default('packed');        // packed | loaded | delivered | returned | void
            $t->dateTime('loaded_at', 3)->nullable();
            $t->dateTime('delivered_at', 3)->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->index(['fo_id'], 'cartons_fo_id_ix');
        });

        // ── files kept in the database when no object storage is configured (POD signature / photo, attachments).
        //    Serverless hosts have no persistent disk, so a local folder is not an option. Content is base64 text.
        Schema::create('stored_files', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('key')->unique();
            $t->string('mime')->nullable();
            $t->integer('size')->default(0);
            $t->string('sha256', 64)->nullable();
            $t->longText('content');
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
        });

        // ── supplier debit note: the financial document of goods sent back to a supplier (purchase price + VAT)
        Schema::create('fin_debit_notes', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('number')->unique();                 // DN-2026-00001
            $t->ulid('supplier_id');
            $t->ulid('return_id')->nullable();
            $t->ulid('po_id')->nullable();
            $t->ulid('grn_id')->nullable();
            $t->dateTime('date', 3);
            $t->decimal('subtotal', 16, 2)->default(0);     // Σ qty × purchase price
            $t->double('vat_pct')->default(0);
            $t->decimal('vat_amount', 16, 2)->default(0);
            $t->decimal('total', 16, 2)->default(0);
            $t->decimal('cost_total', 16, 2)->default(0);   // Σ qty × cost the stock left the books at
            $t->string('status')->default('issued');        // issued | cancelled
            $t->json('lines')->nullable();                  // [{sku, nameAr, qty, price, cost}]
            $t->text('notes')->nullable();
            $t->ulid('journal_id')->nullable();
            $t->string('created_by_id')->nullable();
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('cancelled_at', 3)->nullable();
            $t->index(['supplier_id'], 'fin_debit_notes_supplier_ix');
        });

        // ── sales: backorders are their own linked order; a short pick is recorded, not hidden
        Schema::table('sales_orders', function (Blueprint $t) {
            $t->ulid('backorder_of_id')->nullable();        // the order this one carries the unshipped remainder of
            $t->boolean('has_shortage')->default(false);    // something ordered could not be shipped on this order
            $t->string('discount_approved_by')->nullable(); // who allowed a discount above sales.maxDiscountPct
            $t->index(['backorder_of_id'], 'sales_orders_backorder_of_ix');
        });
        Schema::table('sales_order_lines', function (Blueprint $t) {
            $t->integer('ordered_qty')->nullable();         // what the customer asked for, when qty was reduced by a shortage
            $t->integer('short_qty')->default(0);           // moved to a backorder or cancelled
        });
        Schema::table('quotations', function (Blueprint $t) {
            $t->string('discount_approved_by')->nullable();
        });

        // ── picking / packing
        Schema::table('fo_lines', function (Blueprint $t) {
            $t->integer('short_qty')->default(0);
        });
        Schema::table('pick_lists', function (Blueprint $t) {
            $t->dateTime('assigned_at', 3)->nullable();     // assigned_to_id / assigned_to already exist
        });
        Schema::table('pick_tasks', function (Blueprint $t) {
            $t->boolean('manual')->default(false);          // confirmed without a scan (damaged label) by a supervisor
            $t->string('manual_reason')->nullable();
            $t->boolean('substituted')->default(false);     // created by a batch substitution
            $t->text('note')->nullable();
        });

        // ── trips / delivery
        Schema::table('trips', function (Blueprint $t) {
            $t->string('kind')->default('delivery');        // delivery | transfer
            $t->string('dock_code')->nullable();            // loading door / outbound lane
        });
        Schema::table('trip_stops', function (Blueprint $t) {
            $t->string('eta')->nullable();                  // planned arrival HH:mm, recomputed when the order of stops changes
            $t->string('eta_source')->nullable();           // maps | estimate
            $t->boolean('window_ok')->nullable();           // eta inside the customer's window
            $t->ulid('transfer_id')->nullable();            // a stop that delivers a warehouse transfer
        });
        Schema::table('trip_costs', function (Blueprint $t) {
            $t->dateTime('computed_at', 3)->nullable();
            $t->json('basis')->nullable();                  // how each figure was obtained: actual | estimated | not_configured
        });
        Schema::table('proof_of_delivery', function (Blueprint $t) {
            $t->json('scans')->nullable();                  // codes scanned at the stop
            $t->boolean('scan_ok')->nullable();
            $t->string('no_scan_reason')->nullable();
            $t->integer('delivered_cartons')->nullable();
        });

        // ── fleet master data and corrections
        Schema::table('vehicles', function (Blueprint $t) {
            $t->decimal('purchase_cost', 14, 2)->nullable();
            $t->decimal('salvage_value', 14, 2)->nullable();
            $t->integer('life_km')->nullable();             // depreciation per km = (purchase − salvage) / life_km
        });
        Schema::table('drivers', function (Blueprint $t) {
            $t->decimal('hourly_cost', 10, 2)->nullable();  // loaded cost per hour; null = fleet.driverHourlyCost
        });
        Schema::table('fuel_records', function (Blueprint $t) {
            $t->dateTime('voided_at', 3)->nullable();
            $t->string('voided_by')->nullable();
            $t->text('void_reason')->nullable();
            $t->dateTime('corrected_at', 3)->nullable();
            $t->string('corrected_by')->nullable();
            $t->text('correction_note')->nullable();
        });
        Schema::table('maintenance_orders', function (Blueprint $t) {
            $t->dateTime('cancelled_at', 3)->nullable();
            $t->string('cancelled_by')->nullable();
            $t->text('cancel_reason')->nullable();
        });

        // ── returns
        Schema::table('returns', function (Blueprint $t) {
            $t->string('source_mode')->nullable();          // stock = goods taken from their bins (purchase return)
            $t->ulid('po_id')->nullable();
            $t->ulid('grn_id')->nullable();
            $t->ulid('debit_note_id')->nullable();
            $t->dateTime('shipped_at', 3)->nullable();
            $t->dateTime('cancelled_at', 3)->nullable();
            $t->string('cancelled_by')->nullable();
            $t->text('cancel_reason')->nullable();
        });
        Schema::table('return_lines', function (Blueprint $t) {
            $t->integer('received_qty')->nullable();
            $t->ulid('from_bin_id')->nullable();            // purchase return from the shelf
            $t->decimal('unit_price', 14, 4)->nullable();   // purchase price credited by the supplier
            $t->ulid('supplier_id')->nullable();
        });
        Schema::table('return_decisions', function (Blueprint $t) {
            $t->ulid('line_id')->nullable();
            $t->ulid('batch_id')->nullable();
            $t->ulid('supplier_id')->nullable();
            $t->string('condition')->nullable();
        });

        // ── transfers
        Schema::table('warehouse_transfers', function (Blueprint $t) {
            $t->ulid('trip_id')->nullable();
            $t->ulid('vehicle_id')->nullable();
            $t->ulid('driver_id')->nullable();
            $t->string('carrier')->nullable();
            $t->string('waybill')->nullable();
            $t->string('variance_resolution')->nullable();  // loss | returned
            $t->text('variance_note')->nullable();
            $t->ulid('variance_journal_id')->nullable();
        });
        Schema::table('transfer_lines', function (Blueprint $t) {
            $t->integer('variance_qty')->default(0);
            $t->decimal('unit_cost', 14, 4)->nullable();    // cost the line left the source at — the receipt is valued at it
        });

        // ── receiving / procurement
        Schema::table('inbound_shipments', function (Blueprint $t) {
            $t->dateTime('refused_at', 3)->nullable();
            $t->string('refused_by')->nullable();
            $t->text('refused_reason')->nullable();
        });
        Schema::table('goods_receipts', function (Blueprint $t) {
            $t->dateTime('voided_at', 3)->nullable();
            $t->string('voided_by')->nullable();
            $t->text('void_reason')->nullable();
        });
        Schema::table('grn_lines', function (Blueprint $t) {
            $t->integer('split_no')->default(1);            // a receiving line split over several batches
        });
        Schema::table('grn_lines', function (Blueprint $t) {
            $t->unique(['grn_id', 'line_no', 'split_no'], 'grn_lines_grn_line_split_uq');
            $t->dropUnique('grn_lines_grn_id_line_no_uq');
        });
        Schema::table('putaway_tasks', function (Blueprint $t) {
            $t->integer('done_qty')->default(0);
        });
        Schema::table('po_lines', function (Blueprint $t) {
            $t->decimal('pr_est_price', 14, 4)->nullable(); // the requisition's estimate the PO price is compared with
        });
        Schema::table('purchase_orders', function (Blueprint $t) {
            $t->boolean('price_changed')->default(false);   // a price above the requisition estimate beyond the tolerance
            $t->text('price_note')->nullable();
            $t->string('closed_by')->nullable();                 // closed_at already exists
            $t->text('close_reason')->nullable();
        });

        // new permissions of this release + their default grants; existing permissions newly needed by a role
        PermissionSync::ensure();
        PermissionSync::grant(PermissionSync::GRANTS_2026_11);
    }

    public function down(): void
    {
        Schema::table('purchase_orders', fn (Blueprint $t) => $t->dropColumn(['price_changed', 'price_note', 'closed_by', 'close_reason']));
        Schema::table('po_lines', fn (Blueprint $t) => $t->dropColumn(['pr_est_price']));
        Schema::table('putaway_tasks', fn (Blueprint $t) => $t->dropColumn(['done_qty']));
        Schema::table('grn_lines', function (Blueprint $t) {
            $t->unique(['grn_id', 'line_no'], 'grn_lines_grn_id_line_no_uq');
            $t->dropUnique('grn_lines_grn_line_split_uq');
        });
        Schema::table('grn_lines', fn (Blueprint $t) => $t->dropColumn(['split_no']));
        Schema::table('goods_receipts', fn (Blueprint $t) => $t->dropColumn(['voided_at', 'voided_by', 'void_reason']));
        Schema::table('inbound_shipments', fn (Blueprint $t) => $t->dropColumn(['refused_at', 'refused_by', 'refused_reason']));
        Schema::table('transfer_lines', fn (Blueprint $t) => $t->dropColumn(['variance_qty', 'unit_cost']));
        Schema::table('warehouse_transfers', fn (Blueprint $t) => $t->dropColumn(['trip_id', 'vehicle_id', 'driver_id', 'carrier', 'waybill', 'variance_resolution', 'variance_note', 'variance_journal_id']));
        Schema::table('return_decisions', fn (Blueprint $t) => $t->dropColumn(['line_id', 'batch_id', 'supplier_id', 'condition']));
        Schema::table('return_lines', fn (Blueprint $t) => $t->dropColumn(['received_qty', 'from_bin_id', 'unit_price', 'supplier_id']));
        Schema::table('returns', fn (Blueprint $t) => $t->dropColumn(['source_mode', 'po_id', 'grn_id', 'debit_note_id', 'shipped_at', 'cancelled_at', 'cancelled_by', 'cancel_reason']));
        Schema::table('maintenance_orders', fn (Blueprint $t) => $t->dropColumn(['cancelled_at', 'cancelled_by', 'cancel_reason']));
        Schema::table('fuel_records', fn (Blueprint $t) => $t->dropColumn(['voided_at', 'voided_by', 'void_reason', 'corrected_at', 'corrected_by', 'correction_note']));
        Schema::table('drivers', fn (Blueprint $t) => $t->dropColumn(['hourly_cost']));
        Schema::table('vehicles', fn (Blueprint $t) => $t->dropColumn(['purchase_cost', 'salvage_value', 'life_km']));
        Schema::table('proof_of_delivery', fn (Blueprint $t) => $t->dropColumn(['scans', 'scan_ok', 'no_scan_reason', 'delivered_cartons']));
        Schema::table('trip_costs', fn (Blueprint $t) => $t->dropColumn(['computed_at', 'basis']));
        Schema::table('trip_stops', fn (Blueprint $t) => $t->dropColumn(['eta', 'eta_source', 'window_ok', 'transfer_id']));
        Schema::table('trips', fn (Blueprint $t) => $t->dropColumn(['kind', 'dock_code']));
        Schema::table('pick_tasks', fn (Blueprint $t) => $t->dropColumn(['manual', 'manual_reason', 'substituted', 'note']));
        Schema::table('pick_lists', fn (Blueprint $t) => $t->dropColumn(['assigned_at']));
        Schema::table('fo_lines', fn (Blueprint $t) => $t->dropColumn(['short_qty']));
        Schema::table('quotations', fn (Blueprint $t) => $t->dropColumn(['discount_approved_by']));
        Schema::table('sales_order_lines', fn (Blueprint $t) => $t->dropColumn(['ordered_qty', 'short_qty']));
        Schema::table('sales_orders', function (Blueprint $t) {
            $t->dropIndex('sales_orders_backorder_of_ix');
            $t->dropColumn(['backorder_of_id', 'has_shortage', 'discount_approved_by']);
        });
        Schema::dropIfExists('fin_debit_notes');
        Schema::dropIfExists('stored_files');
        Schema::dropIfExists('cartons');
        Schema::dropIfExists('inventory_holds');
    }
};
