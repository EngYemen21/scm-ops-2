<?php

use App\Http\Controllers\Api\Integrations\IntegrationsController;
use Illuminate\Support\Facades\Route;

// Integration status, the outbox and attachments. Nothing here ever reports a delivery / upload that did not happen:
// unconfigured providers answer `integration_pending`. Running or retrying the outbox is an administrator action.
Route::prefix('integrations')->group(function () {
    Route::get('status', [IntegrationsController::class, 'status']);

    Route::get('outbox', [IntegrationsController::class, 'outbox']);
    Route::post('outbox/run', [IntegrationsController::class, 'run'])->middleware('perm:settings.manage');
    Route::post('outbox/{id}/retry', [IntegrationsController::class, 'retry'])->middleware('perm:settings.manage');

    Route::post('attachments', [IntegrationsController::class, 'attach']);
    Route::get('attachments', [IntegrationsController::class, 'attachments']);

    Route::post('pods/{podId}/attachments', [IntegrationsController::class, 'podAttach'])->middleware('perm:delivery.execute');
    Route::get('pods/{podId}/attachments', [IntegrationsController::class, 'podAttachments']);
});
