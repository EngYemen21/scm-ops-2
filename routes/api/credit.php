<?php

use App\Credit\CreditServiceProvider;
use App\Credit\Http\ConfigController;
use App\Credit\Http\DocumentsController;
use App\Credit\Http\RequestsController;
use App\Credit\Http\WorkflowController;
use Illuminate\Support\Facades\Route;

/*
 * «حسابات الائتمان الآجل» — /api/credit (docs/odoo/WAVE4.md §Q3). Everything needs page.credit. Raising a request needs
 * credit.request; the section's configuration and its clock need credit.manage. Every other move is checked by the
 * services against the request and its stage: the requester's own moves (complete, send, withdraw, cancel), a stage
 * decision (credit.review / credit.approve, the final approval credit.approve only, never on one's own request), the
 * manager's exits. A request that came from Odoo is read-only while the section follows Odoo (422
 * CREDIT_OWNED_BY_SOURCE) — comments are OPS's own and stay open.
 * Personal values and papers: GET requests/{id}/private and requests/{id}/documents/{file}, audited (Privacy).
 */
Route::prefix('credit')->middleware('perm:page.credit')->group(function () {
    Route::get('lookups', [ConfigController::class, 'lookups']);
    Route::get('summary', [RequestsController::class, 'summary']);
    Route::get('customers', [RequestsController::class, 'customers']);
    Route::get('terms', [ConfigController::class, 'terms']);

    Route::get('requests', [RequestsController::class, 'index']);
    Route::get('requests/{id}', [RequestsController::class, 'show']);
    Route::get('requests/{id}/private', [RequestsController::class, 'privateValues']);
    Route::get('requests/{id}/agreement', [RequestsController::class, 'agreement']);
    Route::get('requests/{id}/documents/{file}', [DocumentsController::class, 'file']);

    Route::post('requests', [RequestsController::class, 'store'])->middleware('perm:credit.request');
    Route::patch('requests/{id}', [RequestsController::class, 'update']);
    Route::post('requests/{id}/accept-terms', [RequestsController::class, 'acceptTerms']);
    Route::post('requests/{id}/comments', [RequestsController::class, 'comment']);

    // the workflow
    Route::post('requests/{id}/submit', [WorkflowController::class, 'submit']);
    Route::post('requests/{id}/approve', [WorkflowController::class, 'approve']);
    Route::post('requests/{id}/reject', [WorkflowController::class, 'reject']);
    Route::post('requests/{id}/request-changes', [WorkflowController::class, 'requestChanges']);
    Route::post('requests/{id}/assess', [WorkflowController::class, 'assess']);
    Route::post('requests/{id}/withdraw', [WorkflowController::class, 'withdraw']);
    Route::post('requests/{id}/cancel', [WorkflowController::class, 'cancel']);
    Route::post('requests/{id}/reopen', [WorkflowController::class, 'reopen']);
    Route::post('requests/{id}/apply', [WorkflowController::class, 'apply']);
    Route::post('requests/{id}/skip', [WorkflowController::class, 'skip'])->middleware('perm:credit.manage');
    Route::post('requests/{id}/refresh-approvers', [WorkflowController::class, 'refreshApprovers'])->middleware('perm:credit.manage');
    Route::post('requests/{id}/restore-customer', [WorkflowController::class, 'restoreCustomer'])->middleware('perm:credit.manage');

    // papers and signature
    Route::post('requests/{id}/documents', [DocumentsController::class, 'add']);
    Route::post('requests/{id}/documents/{file}/remove', [DocumentsController::class, 'remove']);
    Route::post('requests/{id}/envelopes', [DocumentsController::class, 'issue']);
    Route::post('requests/{id}/envelopes/{envelope}/delivered', [DocumentsController::class, 'delivered']);
    Route::post('requests/{id}/envelopes/{envelope}/declined', [DocumentsController::class, 'declined']);
    Route::post('requests/{id}/envelopes/{envelope}/void', [DocumentsController::class, 'void']);
    Route::post('requests/{id}/envelopes/{envelope}/signed', [DocumentsController::class, 'signed']);
    Route::post('requests/{id}/envelopes/{envelope}/refresh', [DocumentsController::class, 'refresh']);

    // what the section is made of
    Route::middleware('perm:credit.manage')->group(function () {
        Route::post('stages', [ConfigController::class, 'storeStage']);
        Route::patch('stages/{id}', [ConfigController::class, 'updateStage']);
        Route::post('terms', [ConfigController::class, 'storeTerms']);
        Route::patch('terms/{id}', [ConfigController::class, 'updateTerms']);
        Route::post('terms/{id}/publish', [ConfigController::class, 'publishTerms']);
        Route::post('terms/{id}/retire', [ConfigController::class, 'retireTerms']);
        Route::post('payment-methods', [ConfigController::class, 'storeMethod']);
        Route::patch('payment-methods/{id}', [ConfigController::class, 'updateMethod']);
        Route::put('settings/{group}', [ConfigController::class, 'putSettings'])->where('group', 'calendar|documents|reminders|signature');
        Route::post('deadlines/run', [WorkflowController::class, 'runDeadlines']);
    });
});

// The command of the section's clock (`php artisan scm:credit-deadlines`, every 15 minutes) is registered from here,
// with the section's own provider, so that no shared file has to name it.
if (! app()->providerIsLoaded(CreditServiceProvider::class)) {
    app()->register(CreditServiceProvider::class);
}
