<?php

use App\Pos\Http\OrdersController;
use App\Pos\Http\RegistersController;
use App\Pos\Http\SessionsController;
use Illuminate\Support\Facades\Route;

/*
 * «نقطة البيع» — /api/pos (docs/odoo/WAVE4.md §Q1). Reading needs page.pos; selling (sessions, sales, held sales,
 * cash in / out, refunds) needs pos.sell; the settings and the issue of stock owed by a sale need pos.manage. Checked in
 * the services as well: one sells only in one's own open session (pos.manage may act in any), a refund on a register
 * that says so needs pos.manage, cost and margin are shown only with inventory.view_cost.
 */
Route::prefix('pos')->group(function () {
    Route::middleware('perm:page.pos')->group(function () {
        Route::get('lookups', [RegistersController::class, 'lookups']);
        Route::get('registers', [RegistersController::class, 'index']);
        Route::get('registers/{id}', [RegistersController::class, 'show']);
        Route::get('payment-methods', [RegistersController::class, 'methods']);
        Route::get('denominations', [RegistersController::class, 'denominations']);
        Route::get('sessions', [SessionsController::class, 'index']);
        Route::get('sessions/current', [SessionsController::class, 'current']);
        Route::get('sessions/{id}', [SessionsController::class, 'show']);
        Route::get('sessions/{id}/report', [SessionsController::class, 'report']);
        Route::get('sessions/{id}/held', [SessionsController::class, 'held']);
        Route::get('orders', [OrdersController::class, 'index']);
        Route::get('orders/{id}', [OrdersController::class, 'show']);
        Route::get('orders/{id}/receipt', [OrdersController::class, 'receipt']);
        Route::get('catalog', [OrdersController::class, 'catalog']);
        Route::get('catalog/scan', [OrdersController::class, 'scan']);
    });

    Route::middleware('perm:pos.sell')->group(function () {
        Route::post('sessions', [SessionsController::class, 'open']);
        Route::post('sessions/{id}/close', [SessionsController::class, 'close']);
        Route::post('sessions/{id}/cash-moves', [SessionsController::class, 'cashMove']);
        Route::post('quote', [OrdersController::class, 'quote']);
        Route::post('orders', [OrdersController::class, 'sell']);
        Route::post('orders/{id}/refund', [OrdersController::class, 'refund']);
        Route::post('held', [SessionsController::class, 'hold']);
        Route::post('held/{id}/recall', [SessionsController::class, 'recall']);
        Route::post('held/{id}/discard', [SessionsController::class, 'discard']);
    });

    Route::middleware('perm:pos.manage')->group(function () {
        Route::post('registers', [RegistersController::class, 'store']);
        Route::patch('registers/{id}', [RegistersController::class, 'update']);
        Route::post('payment-methods', [RegistersController::class, 'storeMethod']);
        Route::patch('payment-methods/{id}', [RegistersController::class, 'updateMethod']);
        Route::post('denominations', [RegistersController::class, 'storeDenomination']);
        Route::patch('denominations/{id}', [RegistersController::class, 'updateDenomination']);
        Route::post('orders/{id}/issue-pending', [OrdersController::class, 'issuePending']);
    });
});
