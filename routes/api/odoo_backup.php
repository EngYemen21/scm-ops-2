<?php

use App\Integration\Odoo\OdooFiles;
use Illuminate\Support\Facades\Route;

// The content of an Odoo attachment that OPS keeps (docs/odoo/ARCHITECTURE.ar.md §8; `scm:odoo-files` archives it).
// Who may read it is decided in OdooFiles::download: the Odoo archive page or the integration tower, and for a file
// that hangs on people's records `odoo.private` as well.
Route::prefix('odoo-backup')->group(function () {
    Route::get('files/{attachmentId}', fn (int $attachmentId, OdooFiles $files) => $files->download($attachmentId))->whereNumber('attachmentId');
});
