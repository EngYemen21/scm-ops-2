<?php

use App\ProductRequests\Http\AnyPermission;
use App\ProductRequests\Http\RequestsController;
use Illuminate\Support\Facades\Route;

/*
 * «طلبات الأصناف الجديدة» — new-product requests, /api/product-requests (docs/odoo/WAVE3.md, P4).
 *
 *   page.preq     open the section and read it: the lists, the board, a request with its history and pictures
 *   preq.create   raise a request and work one's own (edit while it is a draft or was sent back, send, cancel)
 *   preq.review   work any request that was sent: take it into review, correct it, send it back, approve it (the
 *                 product is created in the catalogue), reject it — and say which product an approved request is
 *
 * `perm:a` = that permission; AnyPermission::of(a, b) = at least one (the service then decides what the caller may do
 * with THIS request: its author, or a reviewer). A request that came from Odoo is refused these moves with
 * PREQ_OWNED_BY_SOURCE (422) while the section follows Odoo; comments, pictures and the product link stay open.
 */
Route::prefix('product-requests')->middleware('perm:page.preq')->group(function () {
    $either = AnyPermission::of('preq.create', 'preq.review');

    Route::get('summary', [RequestsController::class, 'summary']);
    Route::get('lookups', [RequestsController::class, 'lookups']);
    Route::get('board', [RequestsController::class, 'board']);
    Route::get('duplicates', [RequestsController::class, 'duplicates']);
    Route::get('estimate', [RequestsController::class, 'estimate']);
    Route::get('next-sku', [RequestsController::class, 'nextSku'])->middleware('perm:preq.review');

    Route::get('/', [RequestsController::class, 'index']);
    Route::get('{id}', [RequestsController::class, 'show']);
    Route::post('/', [RequestsController::class, 'store'])->middleware('perm:preq.create');
    Route::patch('{id}', [RequestsController::class, 'update'])->middleware($either);

    // the author
    Route::post('{id}/submit', [RequestsController::class, 'submit'])->middleware('perm:preq.create');
    Route::post('{id}/cancel', [RequestsController::class, 'cancel'])->middleware($either);

    // the reviewers
    Route::middleware('perm:preq.review')->group(function () {
        Route::post('{id}/start-review', [RequestsController::class, 'startReview']);
        Route::post('{id}/send-back', [RequestsController::class, 'sendBack']);
        Route::post('{id}/approve', [RequestsController::class, 'approve']);
        Route::post('{id}/reject', [RequestsController::class, 'reject']);
        Route::post('{id}/link-product', [RequestsController::class, 'linkProduct']);
    });

    // what OPS adds to any request it can see
    Route::post('{id}/comments', [RequestsController::class, 'comment'])->middleware($either);
    Route::post('{id}/pictures', [RequestsController::class, 'addPicture'])->middleware($either);
    Route::get('{id}/pictures/{picture}', [RequestsController::class, 'picture']);
    Route::post('{id}/pictures/{picture}/remove', [RequestsController::class, 'removePicture'])->middleware($either);
});
