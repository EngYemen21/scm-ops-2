<?php

use App\Http\Controllers\Api\Sales\ConsolidationsController;
use App\Http\Controllers\Api\Sales\OrdersController;
use App\Http\Controllers\Api\Sales\QuotationsController;
use Illuminate\Support\Facades\Route;

// Sales: quotations → sales orders (reserve / allocate FEFO) → fulfillment order, and order consolidation batches.
// Reads are open to every signed-in user; every change declares its permission.
Route::prefix('sales')->group(function () {
    Route::get('quotations', [QuotationsController::class, 'index']);
    Route::get('quotations/{id}', [QuotationsController::class, 'show']);
    Route::post('quotations', [QuotationsController::class, 'store'])->middleware('perm:sales.manage');
    Route::post('quotations/{id}/send', [QuotationsController::class, 'send'])->middleware('perm:sales.manage');
    Route::post('quotations/{id}/approve', [QuotationsController::class, 'approve'])->middleware('perm:sales.manage');
    Route::post('quotations/{id}/reject', [QuotationsController::class, 'reject'])->middleware('perm:sales.manage');
    Route::post('quotations/{id}/duplicate', [QuotationsController::class, 'duplicate'])->middleware('perm:sales.manage');
    Route::post('quotations/{id}/convert', [QuotationsController::class, 'convert'])->middleware('perm:so.reserve');

    Route::get('orders', [OrdersController::class, 'index']);
    Route::get('orders/{id}', [OrdersController::class, 'show']);
    Route::post('orders', [OrdersController::class, 'store'])->middleware('perm:so.reserve');
    Route::post('orders/{id}/allocate', [OrdersController::class, 'allocate'])->middleware('perm:so.allocate');
    Route::post('orders/{id}/fulfill', [OrdersController::class, 'fulfill'])->middleware('perm:consol.manage');
    Route::post('orders/{id}/cancel', [OrdersController::class, 'cancel'])->middleware('perm:so.cancel');

    Route::get('consolidations', [ConsolidationsController::class, 'index']);
    Route::get('consolidations/{id}', [ConsolidationsController::class, 'show']);
    Route::post('consolidations', [ConsolidationsController::class, 'store'])->middleware('perm:consol.manage');
    Route::post('consolidations/{id}/advance', [ConsolidationsController::class, 'advance'])->middleware('perm:consol.manage');
    Route::post('consolidations/{id}/add', [ConsolidationsController::class, 'add'])->middleware('perm:consol.manage');
    Route::post('consolidations/{id}/remove', [ConsolidationsController::class, 'remove'])->middleware('perm:consol.manage');
});
