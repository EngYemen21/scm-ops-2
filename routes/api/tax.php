<?php

use App\Tax\Http\EinvoiceController;
use App\Tax\Http\EnsureTaxInstalled;
use App\Tax\Http\TaxController;
use App\Tax\Http\VatReturnController;
use Illuminate\Support\Facades\Route;

/*
 * «الضريبة والفوترة الإلكترونية» — /api/tax (docs/odoo/WAVE4.md, Q5).
 *
 *   page.tax          reads everything of the section (never a key, a certificate or a secret of a unit: no endpoint
 *                     returns them)
 *   vatreturn.manage  the dictionary (tax codes, groups, fiscal positions), the code of a document, and the VAT return
 *                     while it is a draft: make, refresh, adjust, explain the ledger, review, reopen, cancel
 *   vatreturn.file    files a reviewed return (somebody other than its reviewer), takes it back, locks it
 *   einvoice.manage   e-invoices (make, re-make, queue, send) and the e-invoicing units with their onboarding
 *
 * The taxpayer profile and the tax details of customers and suppliers serve both halves: vatreturn.manage OR
 * einvoice.manage keeps them (checked in the controller). Exporting the documents behind a figure also needs
 * report.export. A code, a group or a position that came from Odoo is read-only while the section follows Odoo
 * (422 TAX_OWNED_BY_SOURCE). Without its tables every call answers 422 TAX_NOT_INSTALLED.
 */
Route::prefix('tax')->middleware(EnsureTaxInstalled::class)->group(function () {
    Route::middleware('perm:page.tax')->group(function () {
        Route::get('overview', [TaxController::class, 'overview']);
        Route::get('lookups', [TaxController::class, 'lookups']);
        Route::get('codes', [TaxController::class, 'codes']);
        Route::get('positions', [TaxController::class, 'positions']);
        Route::get('profile', [TaxController::class, 'profile']);
        Route::get('parties', [TaxController::class, 'parties']);
        Route::get('parties/{type}/{id}', [TaxController::class, 'party']);
        Route::get('documents/{type}/{id}', [TaxController::class, 'document']);
        // the two settings both managers keep (the controller checks one of the two permissions)
        Route::patch('profile', [TaxController::class, 'updateProfile']);
        Route::patch('parties/{type}/{id}', [TaxController::class, 'saveParty']);
        // VAT returns
        Route::get('returns', [VatReturnController::class, 'index']);
        Route::get('returns/{id}', [VatReturnController::class, 'show']);
        Route::get('returns/{id}/entries', [VatReturnController::class, 'entries']);
        Route::get('returns/{id}/entries/export', [VatReturnController::class, 'export']);
        // e-invoices
        Route::get('einvoices/summary', [EinvoiceController::class, 'summary']);
        Route::get('einvoices/pending', [EinvoiceController::class, 'pending']);
        Route::get('einvoices/chain', [EinvoiceController::class, 'chain']);
        Route::get('einvoices', [EinvoiceController::class, 'index']);
        Route::get('einvoices/{id}', [EinvoiceController::class, 'show']);
        Route::get('einvoices/{id}/xml', [EinvoiceController::class, 'xml']);
        Route::get('units', [EinvoiceController::class, 'units']);
        Route::get('units/{id}', [EinvoiceController::class, 'unit']);
        Route::get('history', [EinvoiceController::class, 'historyIndex']);
        Route::get('history/{id}', [EinvoiceController::class, 'historyShow']);
    });

    Route::middleware('perm:vatreturn.manage')->group(function () {
        Route::post('codes', [TaxController::class, 'storeCode']);
        Route::patch('codes/{id}', [TaxController::class, 'updateCode']);
        Route::post('groups', [TaxController::class, 'storeGroup']);
        Route::patch('groups/{id}', [TaxController::class, 'updateGroup']);
        Route::post('positions', [TaxController::class, 'storePosition']);
        Route::patch('positions/{id}', [TaxController::class, 'updatePosition']);
        Route::post('documents/{type}/{id}/code', [TaxController::class, 'assignDocument']);
        Route::post('returns', [VatReturnController::class, 'store']);
        Route::patch('returns/{id}', [VatReturnController::class, 'update']);
        Route::post('returns/{id}/refresh', [VatReturnController::class, 'refresh']);
        Route::post('returns/{id}/adjustments', [VatReturnController::class, 'addAdjustment']);
        Route::post('returns/{id}/adjustments/{adjustment}/void', [VatReturnController::class, 'voidAdjustment']);
        Route::post('returns/{id}/explanations', [VatReturnController::class, 'explain']);
        Route::post('returns/{id}/explanations/{note}/withdraw', [VatReturnController::class, 'withdrawExplanation']);
        Route::post('returns/{id}/review', [VatReturnController::class, 'review']);
        Route::post('returns/{id}/reopen', [VatReturnController::class, 'reopen']);
        Route::post('returns/{id}/cancel', [VatReturnController::class, 'cancel']);
    });

    Route::middleware('perm:vatreturn.file')->group(function () {
        Route::post('returns/{id}/file', [VatReturnController::class, 'file']);
        Route::post('returns/{id}/unfile', [VatReturnController::class, 'unfile']);
        Route::post('returns/{id}/lock', [VatReturnController::class, 'lock']);
    });

    Route::middleware('perm:einvoice.manage')->group(function () {
        Route::post('einvoices/make', [EinvoiceController::class, 'make']);
        Route::post('einvoices/make-pending', [EinvoiceController::class, 'makePending']);
        Route::post('einvoices/queue-backlog', [EinvoiceController::class, 'queueBacklog']);
        Route::post('einvoices/run', [EinvoiceController::class, 'run']);
        Route::post('einvoices/{id}/remake', [EinvoiceController::class, 'remake']);
        Route::post('einvoices/{id}/queue', [EinvoiceController::class, 'queue']);
        Route::post('einvoices/{id}/unqueue', [EinvoiceController::class, 'unqueue']);
        Route::post('einvoices/{id}/send', [EinvoiceController::class, 'sendNow']);
        Route::post('units', [EinvoiceController::class, 'storeUnit']);
        Route::patch('units/{id}', [EinvoiceController::class, 'updateUnit']);
        Route::post('units/{id}/request', [EinvoiceController::class, 'makeRequest']);
        Route::post('units/{id}/compliance', [EinvoiceController::class, 'compliance']);
        Route::post('units/{id}/checks', [EinvoiceController::class, 'checks']);
        Route::post('units/{id}/production', [EinvoiceController::class, 'production']);
        Route::post('units/{id}/renew', [EinvoiceController::class, 'renew']);
        Route::post('units/{id}/retire', [EinvoiceController::class, 'retire']);
    });
});
