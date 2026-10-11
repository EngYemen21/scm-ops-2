<?php

use App\Http\Controllers\Api\Procurement\InvoicesController;
use App\Http\Controllers\Api\Procurement\PoController;
use App\Http\Controllers\Api\Procurement\PrController;
use App\Http\Controllers\Api\Procurement\ProcurementController;
use App\Http\Controllers\Api\Procurement\RfqController;
use App\Http\Controllers\Api\Procurement\SupplierDocumentsController;
use Illuminate\Support\Facades\Route;

// Procurement: replenishment suggestions → PR → RFQ / quotations / award → PO (approval chain) → send → expected inbound.
// Reads are open to every signed-in user; every mutation declares its permission.
Route::prefix('procurement')->group(function () {
    // dashboard & replenishment
    Route::get('dashboard', [ProcurementController::class, 'dashboard']);
    Route::get('suggestions', [ProcurementController::class, 'suggestions']);
    Route::post('suggestions/{sku}/pr', [ProcurementController::class, 'toPr'])->middleware('perm:pr.create');
    Route::post('suggestions/{sku}/rfq', [ProcurementController::class, 'toRfq'])->middleware('perm:rfq.create');
    Route::post('suggestions/{sku}/po', [ProcurementController::class, 'toPo'])->middleware('perm:po.create');

    // purchase requisitions
    Route::get('pr', [PrController::class, 'index']);
    Route::get('pr/{id}', [PrController::class, 'show']);
    Route::post('pr', [PrController::class, 'store'])->middleware('perm:pr.create');
    Route::post('pr/{id}/submit', [PrController::class, 'submit'])->middleware('perm:pr.create');
    Route::post('pr/{id}/approve', [PrController::class, 'approve'])->middleware('perm:pr.approve');
    Route::post('pr/{id}/reject', [PrController::class, 'reject'])->middleware('perm:pr.approve');
    Route::post('pr/{id}/to-rfq', [PrController::class, 'toRfq'])->middleware('perm:rfq.create');
    Route::post('pr/{id}/to-po', [PrController::class, 'toPo'])->middleware('perm:po.create');

    // RFQ
    Route::get('rfq', [RfqController::class, 'index']);
    Route::get('rfq/{id}', [RfqController::class, 'show']);
    Route::get('rfq/{id}/comparison', [RfqController::class, 'comparison']);
    Route::post('rfq', [RfqController::class, 'store'])->middleware('perm:rfq.create');
    Route::post('rfq/{id}/invite', [RfqController::class, 'invite'])->middleware('perm:rfq.create');
    Route::post('rfq/{id}/award', [RfqController::class, 'award'])->middleware('perm:rfq.award');
    Route::post('rfq/{id}/cancel', [RfqController::class, 'cancel'])->middleware('perm:rfq.create');

    // supplier quotations
    Route::get('quotations', [RfqController::class, 'quotations']);
    Route::get('quotations/{id}', [RfqController::class, 'quotation']);
    Route::post('quotations', [RfqController::class, 'storeQuotation'])->middleware('perm:supquote.create');

    // purchase orders
    Route::get('po', [PoController::class, 'index']);
    Route::get('po/{id}', [PoController::class, 'show']);
    Route::post('po', [PoController::class, 'store'])->middleware('perm:po.create');
    Route::post('po/{id}/submit', [PoController::class, 'submit'])->middleware('perm:po.create');
    Route::post('po/{id}/approve', [PoController::class, 'approve'])->middleware('perm:po.approve');
    Route::post('po/{id}/reject', [PoController::class, 'reject'])->middleware('perm:po.approve');
    Route::post('po/{id}/send', [PoController::class, 'send'])->middleware('perm:po.send');
    Route::post('po/{id}/confirm', [PoController::class, 'confirm'])->middleware('perm:po.send');
    Route::post('po/{id}/cancel', [PoController::class, 'cancel'])->middleware('perm:po.cancel');
    // delivery follow-up on an open PO (last contact / delay reason)
    Route::post('po/{id}/follow-up', [PoController::class, 'followUp'])->middleware('perm:po.send');

    // supplier invoices: three-way match → approval for payment → payment recorded
    Route::get('invoices', [InvoicesController::class, 'index']);
    Route::get('invoices/summary', [InvoicesController::class, 'summary']);
    Route::get('invoices/{id}', [InvoicesController::class, 'show']);
    Route::post('invoices', [InvoicesController::class, 'store'])->middleware('perm:invoice.register');
    Route::post('invoices/{id}/approve', [InvoicesController::class, 'approve'])->middleware('perm:invoice.approve');
    Route::post('invoices/{id}/pay', [InvoicesController::class, 'pay'])->middleware('perm:invoice.pay');
    Route::post('invoices/{id}/cancel', [InvoicesController::class, 'cancel'])->middleware('perm:invoice.register');
});

// Supplier performance (OTIF / fill rate / lead) — lives with procurement since it is computed from POs and GRNs.
Route::get('suppliers/{code}/performance', [ProcurementController::class, 'supplierPerformance']);

// Supplier qualification documents (CR, VAT certificate, licences…) with expiry — an expired mandatory one blocks new POs.
Route::get('suppliers/{code}/documents', [SupplierDocumentsController::class, 'index']);
Route::post('suppliers/{code}/documents', [SupplierDocumentsController::class, 'store'])->middleware('perm:supplier.manage');
Route::delete('suppliers/{code}/documents/{id}', [SupplierDocumentsController::class, 'destroy'])->middleware('perm:supplier.manage');
