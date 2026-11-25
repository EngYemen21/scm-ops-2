<?php

use App\Portal\Http\ApplicationsController;
use App\Portal\Http\BranchesController;
use App\Portal\Http\FavoritesController;
use App\Portal\Http\RequestsController;
use App\Portal\Http\StatementsController;
use Illuminate\Support\Facades\Route;

/*
 * «بوابة العملاء» — /api/portal (docs/odoo/WAVE3.md, P3): the staff side of what customers do through the portal, and
 * the calls the company's sales platform makes for them (as a service user that holds these permissions).
 * Reading the section needs page.portal; every change needs portal.manage. Three steps reach into another module and
 * need its permission as well, checked in the service and audited when refused:
 *   a NEW customer made by approving an application (customer.manage) · a quotation made from a favourite list or from
 *   an order request (sales.manage). Exporting a statement's lines needs report.export.
 * A record that came from Odoo is read-only while the section follows Odoo (422 PORTAL_OWNED_BY_SOURCE) — what is
 * OPS's own on it (a branch's address, contact and delivery window, the branch a list is kept for, notes, documents,
 * "order again") stays open.
 * A record is named in a path by its id; the ones OPS numbers itself (APL-, ORQ-, SOA-) also answer to their number.
 */
Route::prefix('portal')->group(function () {
    Route::middleware('perm:page.portal')->group(function () {
        Route::get('summary', [BranchesController::class, 'summary']);

        Route::get('branches', [BranchesController::class, 'index']);
        Route::get('branches/{id}', [BranchesController::class, 'show']);

        // a favourite list is read by its header; its items one page at a time
        Route::get('favorites/lists', [FavoritesController::class, 'lists']);
        Route::get('favorites/lists/{id}', [FavoritesController::class, 'showList']);
        Route::get('favorites/lists/{id}/items', [FavoritesController::class, 'items']);
        Route::get('favorites/lists/{id}/order-again', [FavoritesController::class, 'orderAgainPreview']);

        Route::get('applications', [ApplicationsController::class, 'index']);
        Route::get('applications/{id}', [ApplicationsController::class, 'show']);
        // papers that show a person's identity or a bank account also need portal.manage (checked in the service)
        Route::get('applications/{id}/documents/{document}', [ApplicationsController::class, 'file']);

        Route::get('requests', [RequestsController::class, 'index']);
        Route::get('requests/{id}', [RequestsController::class, 'show']);
        Route::get('line-changes', [RequestsController::class, 'changes']);

        Route::get('statements', [StatementsController::class, 'index']);
        Route::get('statements/preview', [StatementsController::class, 'preview']);
        Route::get('statements/{id}', [StatementsController::class, 'show']);
        Route::get('statements/{id}/lines', [StatementsController::class, 'lines']);
        Route::get('statements/{id}/export', [StatementsController::class, 'export'])->middleware('perm:report.export');
    });

    Route::middleware('perm:portal.manage')->group(function () {
        Route::post('branches', [BranchesController::class, 'store']);
        Route::patch('branches/{id}', [BranchesController::class, 'update']);
        Route::post('branches/{id}/archive', [BranchesController::class, 'archive']);
        Route::post('branches/{id}/restore', [BranchesController::class, 'restore']);

        Route::post('favorites/lists', [FavoritesController::class, 'storeList']);
        Route::patch('favorites/lists/{id}', [FavoritesController::class, 'updateList']);
        Route::post('favorites/lists/{id}/archive', [FavoritesController::class, 'archiveList']);
        Route::post('favorites/lists/{id}/restore', [FavoritesController::class, 'restoreList']);
        Route::post('favorites/lists/{id}/items', [FavoritesController::class, 'addItem']);
        Route::patch('favorites/lists/{id}/items/{item}', [FavoritesController::class, 'updateItem']);
        Route::delete('favorites/lists/{id}/items/{item}', [FavoritesController::class, 'removeItem']);
        Route::post('favorites/lists/{id}/items/{item}/move', [FavoritesController::class, 'moveItem']);
        // a draft quotation of the sales module: also needs sales.manage
        Route::post('favorites/lists/{id}/order-again', [FavoritesController::class, 'orderAgain']);

        Route::post('applications', [ApplicationsController::class, 'store']);
        Route::patch('applications/{id}', [ApplicationsController::class, 'update']);
        Route::post('applications/{id}/submit', [ApplicationsController::class, 'submit']);
        Route::post('applications/{id}/request-correction', [ApplicationsController::class, 'requestCorrection']);
        // the company becomes a customer: a NEW one also needs customer.manage
        Route::post('applications/{id}/approve', [ApplicationsController::class, 'approve']);
        Route::post('applications/{id}/reject', [ApplicationsController::class, 'reject']);
        Route::post('applications/{id}/expire', [ApplicationsController::class, 'expire']);
        Route::post('applications/{id}/reopen', [ApplicationsController::class, 'reopen']);
        Route::post('applications/{id}/notes', [ApplicationsController::class, 'note']);
        Route::post('applications/{id}/documents', [ApplicationsController::class, 'attach']);
        Route::post('applications/{id}/documents/{document}/remove', [ApplicationsController::class, 'detach']);

        Route::post('requests', [RequestsController::class, 'store']);
        Route::patch('requests/{id}/lines', [RequestsController::class, 'lines']);
        // a draft quotation of the sales module: also needs sales.manage
        Route::post('requests/{id}/approve', [RequestsController::class, 'approve']);
        Route::post('requests/{id}/reject', [RequestsController::class, 'reject']);
        Route::post('requests/{id}/cancel', [RequestsController::class, 'cancel']);
        Route::post('requests/{id}/notes', [RequestsController::class, 'note']);

        Route::post('statements', [StatementsController::class, 'issue']);
        Route::post('statements/{id}/void', [StatementsController::class, 'void']);
    });
});
