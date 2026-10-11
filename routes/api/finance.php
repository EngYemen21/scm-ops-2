<?php

use App\Finance\Http\FinanceController;
use App\Finance\Http\FinanceReportsController;
use App\Finance\Http\JournalsController;
use App\Finance\Http\ReceivablesController;
use Illuminate\Support\Facades\Route;

/*
 * Finance — /api/finance (docs/finance/ARCHITECTURE.ar.md). Reads need gl.view (statements/analyses: fin.report);
 * every mutation names its permission; maker-checker on manual journals is enforced in the service.
 */
Route::prefix('finance')->group(function () {
    Route::middleware('perm:gl.view')->group(function () {
        Route::get('board', [FinanceController::class, 'board']);
        Route::get('reconciliation', [FinanceController::class, 'reconciliation']);
        Route::get('accounts', [FinanceController::class, 'accounts']);
        Route::get('cost-centers', [FinanceController::class, 'costCenters']);
        Route::get('banks', [FinanceController::class, 'banks']);
        Route::get('periods', [FinanceController::class, 'periods']);
        Route::get('journals', [JournalsController::class, 'index']);
        Route::get('journals/source/{type}/{id}', [JournalsController::class, 'forSource']);
        Route::get('journals/{id}', [JournalsController::class, 'show']);
        Route::get('invoices', [ReceivablesController::class, 'invoices']);
        Route::get('invoices/summary', [ReceivablesController::class, 'invoicesSummary']);
        Route::get('invoices/{id}', [ReceivablesController::class, 'invoice']);
        Route::get('payments', [ReceivablesController::class, 'payments']);
        Route::get('payments/{id}', [ReceivablesController::class, 'payment']);
        Route::get('expenses', [ReceivablesController::class, 'expenses']);
        Route::get('expenses/{id}', [ReceivablesController::class, 'expense']);
        Route::get('assets', [FinanceController::class, 'assets']);
        Route::get('assets/{code}', [FinanceController::class, 'asset']);
    });

    Route::middleware('perm:fin.report')->prefix('reports')->group(function () {
        Route::get('trial-balance', [FinanceReportsController::class, 'trialBalance']);
        Route::get('ledger/{code}', [FinanceReportsController::class, 'ledger']);
        Route::get('income-statement', [FinanceReportsController::class, 'incomeStatement']);
        Route::get('balance-sheet', [FinanceReportsController::class, 'balanceSheet']);
        Route::get('cash-flow', [FinanceReportsController::class, 'cashFlow']);
        Route::get('statement/{type}/{code}', [FinanceReportsController::class, 'statement']);
        Route::get('aging/{type}', [FinanceReportsController::class, 'aging']);
        Route::get('vat', [FinanceReportsController::class, 'vat']);
        Route::get('inventory-valuation', [FinanceReportsController::class, 'inventoryValuation']);
        Route::get('profitability', [FinanceReportsController::class, 'profitability']);
        Route::get('fleet-costs', [FinanceReportsController::class, 'fleetCosts']);
        Route::get('budget', [FinanceReportsController::class, 'budget']);
    });
    Route::put('reports/budget', [FinanceReportsController::class, 'saveBudget'])->middleware('perm:gl.close');

    // chart of accounts, cost centers, banks
    Route::post('accounts', [FinanceController::class, 'storeAccount'])->middleware('perm:coa.manage');
    Route::patch('accounts/{code}', [FinanceController::class, 'updateAccount'])->middleware('perm:coa.manage');
    Route::post('cost-centers', [FinanceController::class, 'storeCostCenter'])->middleware('perm:coa.manage');
    Route::post('banks', [FinanceController::class, 'storeBank'])->middleware('perm:bank.manage');
    Route::patch('banks/{code}', [FinanceController::class, 'updateBank'])->middleware('perm:bank.manage');

    // periods
    Route::post('periods/{code}/close', [FinanceController::class, 'closePeriod'])->middleware('perm:gl.close');
    Route::post('periods/{code}/reopen', [FinanceController::class, 'reopenPeriod'])->middleware('perm:gl.close');

    // manual journals (maker-checker) and reversals
    Route::post('journals', [JournalsController::class, 'store'])->middleware('perm:gl.post');
    Route::patch('journals/{id}', [JournalsController::class, 'update'])->middleware('perm:gl.post');
    Route::delete('journals/{id}', [JournalsController::class, 'destroy'])->middleware('perm:gl.post');
    Route::post('journals/{id}/approve', [JournalsController::class, 'approve'])->middleware('perm:gl.approve');
    Route::post('journals/{id}/reverse', [JournalsController::class, 'reverse'])->middleware('perm:gl.approve');

    // receivables / payables documents
    Route::post('invoices/{id}/cancel', [ReceivablesController::class, 'cancelInvoice'])->middleware('perm:ar.manage');
    Route::post('receipts', [ReceivablesController::class, 'receive'])->middleware('perm:ar.receive');
    Route::post('payments', [ReceivablesController::class, 'pay'])->middleware('perm:ap.pay');
    Route::post('payments/{id}/void', [ReceivablesController::class, 'voidPayment'])->middleware('perm:gl.approve');

    // expenses and fixed assets
    Route::post('expenses', [ReceivablesController::class, 'storeExpense'])->middleware('perm:expense.create');
    Route::post('expenses/{id}/void', [ReceivablesController::class, 'voidExpense'])->middleware('perm:gl.approve');
    Route::post('assets', [FinanceController::class, 'storeAsset'])->middleware('perm:asset.manage');
    Route::post('assets/depreciate', [FinanceController::class, 'depreciate'])->middleware('perm:asset.manage');
    Route::post('assets/{code}/dispose', [FinanceController::class, 'disposeAsset'])->middleware('perm:asset.manage');
});
