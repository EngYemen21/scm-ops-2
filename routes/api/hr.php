<?php

use App\Hr\Http\AnyPermission;
use App\Hr\Http\ClaimsController;
use App\Hr\Http\ContractsController;
use App\Hr\Http\DirectoryController;
use App\Hr\Http\DocumentsController;
use App\Hr\Http\HrController;
use Illuminate\Support\Facades\Route;

/*
 * People and expenses — /api/hr (docs/odoo/WAVE2.md §7).
 *
 *   page.hr         open the section, read the staff directory (work details only)
 *   hr.manage       create / change employees, departments, job positions, documents
 *   hr.private      the personal file and the contracts, read and write — nothing of them is returned without it
 *   expense.claim   file one's own expense claims
 *   expense.review  decide claims, keep the expense categories
 *
 * `perm:a,b` = all of them; AnyPermission::of(a, b) = at least one (the service then decides what each caller gets).
 * The two steps that touch the books ask for the finance permission that guards the same act in the finance section:
 * `expense.create` to post or pay a claim, `gl.approve` to take a posting back.
 */
Route::prefix('hr')->group(function () {
    $anyone = AnyPermission::of('page.hr', 'hr.manage', 'hr.private', 'expense.claim', 'expense.review');
    $claims = AnyPermission::of('expense.claim', 'expense.review');
    $papers = AnyPermission::of('hr.manage', 'hr.private');

    // the top of the page and what its forms pick from: each part only for who may see it
    Route::get('summary', [HrController::class, 'summary'])->middleware($anyone);
    Route::get('lookups', [HrController::class, 'lookups'])->middleware($anyone);
    // a file is opened by whoever may read what it hangs on (FilesService::authorize)
    Route::get('files/{id}', [HrController::class, 'file'])->middleware($anyone);

    // ── the staff directory
    Route::middleware('perm:page.hr')->group(function () {
        Route::get('employees', [DirectoryController::class, 'employees']);
        Route::get('employees/{id}', [DirectoryController::class, 'employee']);
        Route::get('departments', [DirectoryController::class, 'departments']);
        Route::get('jobs', [DirectoryController::class, 'jobs']);
        Route::get('org', [DirectoryController::class, 'org']);
    });
    Route::middleware('perm:hr.manage')->group(function () {
        Route::post('employees', [DirectoryController::class, 'storeEmployee']);
        Route::patch('employees/{id}', [DirectoryController::class, 'updateEmployee']);
        Route::post('departments', [DirectoryController::class, 'storeDepartment']);
        Route::patch('departments/{id}', [DirectoryController::class, 'updateDepartment']);
        Route::post('jobs', [DirectoryController::class, 'storeJob']);
        Route::patch('jobs/{id}', [DirectoryController::class, 'updateJob']);
    });

    // ── the personal file and the contracts
    Route::middleware('perm:hr.private')->group(function () {
        Route::get('employees/{id}/private', [DirectoryController::class, 'privateFile']);
        Route::put('employees/{id}/private', [DirectoryController::class, 'updatePrivateFile']);
        Route::get('contracts', [ContractsController::class, 'index']);
        Route::get('contracts/{id}', [ContractsController::class, 'show']);
        Route::post('contracts', [ContractsController::class, 'store']);
        Route::patch('contracts/{id}', [ContractsController::class, 'update']);
        Route::post('contracts/{id}/start', [ContractsController::class, 'start']);
        Route::post('contracts/{id}/end', [ContractsController::class, 'end']);
        Route::post('contracts/{id}/cancel', [ContractsController::class, 'cancel']);
        Route::post('contracts/{id}/renew', [ContractsController::class, 'renew']);
    });

    // ── documents and what is due (a private document needs hr.private on top: DocumentsService)
    Route::middleware($papers)->group(function () {
        Route::get('documents', [DocumentsController::class, 'index']);
        Route::get('documents/{id}', [DocumentsController::class, 'show']);
        Route::get('alerts', [HrController::class, 'alerts']);
        Route::post('alerts/refresh', [HrController::class, 'refreshAlerts']);
    });
    Route::middleware('perm:hr.manage')->group(function () {
        Route::post('documents', [DocumentsController::class, 'store']);
        Route::patch('documents/{id}', [DocumentsController::class, 'update']);
        Route::post('documents/{id}/renew', [DocumentsController::class, 'renew']);
        Route::post('documents/{id}/void', [DocumentsController::class, 'void']);
        Route::post('documents/{id}/files', [DocumentsController::class, 'attach']);
        Route::delete('documents/{id}/files/{fileId}', [DocumentsController::class, 'removeFile']);
    });

    // ── expense categories
    Route::get('expense-categories', [HrController::class, 'categories'])->middleware($claims);
    Route::post('expense-categories', [HrController::class, 'storeCategory'])->middleware('perm:expense.review');
    Route::patch('expense-categories/{id}', [HrController::class, 'updateCategory'])->middleware('perm:expense.review');

    // ── expense claims
    Route::middleware($claims)->group(function () {
        Route::get('claims', [ClaimsController::class, 'index']);
        Route::get('claims/{id}', [ClaimsController::class, 'show']);
        // one's own claim — or, for a reviewer, a claim entered on behalf of an employee without a login
        Route::post('claims', [ClaimsController::class, 'store']);
        Route::patch('claims/{id}', [ClaimsController::class, 'update']);
        Route::post('claims/{id}/lines/{lineId}/receipts', [ClaimsController::class, 'addReceipt']);
        Route::delete('claims/{id}/receipts/{fileId}', [ClaimsController::class, 'removeReceipt']);
        Route::post('claims/{id}/submit', [ClaimsController::class, 'submit']);
        Route::post('claims/{id}/withdraw', [ClaimsController::class, 'withdraw']);
        Route::post('claims/{id}/cancel', [ClaimsController::class, 'cancel']);
    });
    Route::middleware('perm:expense.review')->group(function () {
        Route::post('claims/{id}/approve', [ClaimsController::class, 'approve']);
        Route::post('claims/{id}/refuse', [ClaimsController::class, 'refuse']);
        Route::post('claims/{id}/return', [ClaimsController::class, 'sendBack']);
        Route::post('claims/{id}/reopen', [ClaimsController::class, 'reopen']);
    });
    Route::post('claims/{id}/post', [ClaimsController::class, 'post'])->middleware('perm:expense.review,expense.create');
    Route::post('claims/{id}/pay', [ClaimsController::class, 'pay'])->middleware('perm:expense.review,expense.create');
    Route::post('claims/{id}/unpay', [ClaimsController::class, 'unpay'])->middleware('perm:expense.review,expense.create');
    Route::post('claims/{id}/unpost', [ClaimsController::class, 'unpost'])->middleware('perm:expense.review,gl.approve');
});
