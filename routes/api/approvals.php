<?php

use App\Approvals\Http\CategoriesController;
use App\Approvals\Http\RequestsController;
use Illuminate\Support\Facades\Route;

/*
 * «الاعتمادات» — approval requests, /api/approvals (docs/odoo/WAVE2.md §5).
 *
 * Opening the section needs page.approvals. Raising a request (and editing or sending one's own draft) needs
 * approval.request; categories need approval.manage. Deciding a request carries no permission of its own on purpose:
 * it is the right of the approvers named on that request, checked by the service — as are seeing a request (its
 * requester, its approvers, the managers) and cancelling or recalling it (its requester, a manager).
 */
Route::prefix('approvals')->middleware('perm:page.approvals')->group(function () {
    Route::get('summary', [RequestsController::class, 'summary']);
    Route::get('lookups', [CategoriesController::class, 'lookups']);
    Route::get('partners', [CategoriesController::class, 'partners']);
    Route::get('products', [CategoriesController::class, 'products']);

    // categories: who approves, in which order, how many, which fields
    Route::get('categories', [CategoriesController::class, 'index']);
    Route::get('categories/{id}', [CategoriesController::class, 'show']);
    Route::post('categories', [CategoriesController::class, 'store'])->middleware('perm:approval.manage');
    Route::patch('categories/{id}', [CategoriesController::class, 'update'])->middleware('perm:approval.manage');

    // requests: ?scope=waiting (for my decision) | mine | involved (I am an approver) | all (approval.manage)
    Route::get('requests', [RequestsController::class, 'index']);
    Route::get('requests/{id}', [RequestsController::class, 'show']);
    Route::post('requests', [RequestsController::class, 'store'])->middleware('perm:approval.request');
    Route::patch('requests/{id}', [RequestsController::class, 'update'])->middleware('perm:approval.request');
    Route::post('requests/{id}/submit', [RequestsController::class, 'submit'])->middleware('perm:approval.request');

    // the decisions of the named approvers
    Route::post('requests/{id}/approve', [RequestsController::class, 'approve']);
    Route::post('requests/{id}/refuse', [RequestsController::class, 'refuse']);
    Route::post('requests/{id}/withdraw', [RequestsController::class, 'withdraw']);

    // the requester, or a manager
    Route::post('requests/{id}/cancel', [RequestsController::class, 'cancel']);
    Route::post('requests/{id}/reopen', [RequestsController::class, 'reopen']);
    Route::post('requests/{id}/approvers', [RequestsController::class, 'addApprover']);
    Route::post('requests/{id}/approvers/{line}/remove', [RequestsController::class, 'removeApprover']);

    // anybody who sees the request
    Route::post('requests/{id}/comments', [RequestsController::class, 'comment']);
    Route::post('requests/{id}/attachments', [RequestsController::class, 'attach']);
    Route::get('requests/{id}/attachments/{attachment}', [RequestsController::class, 'file']);
    Route::post('requests/{id}/attachments/{attachment}/remove', [RequestsController::class, 'detach']);
});
