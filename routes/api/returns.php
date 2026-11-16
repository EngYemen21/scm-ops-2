<?php

use App\Http\Controllers\Api\Returns\ReturnsController;
use Illuminate\Support\Facades\Route;

// Returns. Goods that arrive: pending → approved → received (RET-01) → inspect → decision (closed).
// Goods taken from stock (return to a supplier / damaged): pending → approved (held in the bin) → shipped → closed.
// pending | approved → cancelled. Reads are open to every signed-in user.
Route::get('returns', [ReturnsController::class, 'index']);
Route::get('returns/stock-rows', [ReturnsController::class, 'stockRows'])->middleware('perm:return.create'); // before {id}
Route::get('returns/{id}', [ReturnsController::class, 'show']);
Route::post('returns', [ReturnsController::class, 'store'])->middleware('perm:return.create');
Route::post('returns/{id}/approve', [ReturnsController::class, 'approve'])->middleware('perm:return.flow');
Route::post('returns/{id}/reject', [ReturnsController::class, 'reject'])->middleware('perm:return.flow');
Route::post('returns/{id}/cancel', [ReturnsController::class, 'cancel'])->middleware('perm:return.flow');
Route::post('returns/{id}/receive', [ReturnsController::class, 'receive'])->middleware('perm:return.flow');
Route::post('returns/{id}/inspect', [ReturnsController::class, 'inspect'])->middleware('perm:return.flow');
Route::post('returns/{id}/decide', [ReturnsController::class, 'decide'])->middleware('perm:return.decide');
Route::post('returns/{id}/ship', [ReturnsController::class, 'ship'])->middleware('perm:return.flow');
Route::post('returns/{id}/debit-note', [ReturnsController::class, 'debitNote'])->middleware('perm:return.flow');
