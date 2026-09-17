<?php

use App\Http\Controllers\Api\Procurement\PoController;
use App\Http\Controllers\Api\Procurement\PrController;
use App\Http\Controllers\Api\Procurement\ProcurementController;
use App\Http\Controllers\Api\Procurement\RfqController;
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
});

// Supplier performance (OTIF / fill rate / lead) — lives with procurement since it is computed from POs and GRNs.
Route::get('suppliers/{code}/performance', [ProcurementController::class, 'supplierPerformance']);
