<?php

namespace App\Integration\Odoo\Archive\Http;

use App\Integration\Odoo\Archive\ArchiveWriter;
use App\Support\AppError;
use Closure;
use Illuminate\Http\Request;

/**
 * The code can reach a server before its migration has run. Until the archive's index exists, the endpoints that
 * read it answer with a plain refusal (the catalog says `ready: false`) instead of a database error.
 */
class EnsureArchiveInstalled
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (! ArchiveWriter::installed()) {
            throw AppError::rule('ODOO_ARCHIVE_NOT_INSTALLED', 'فهرس أرشيف أودو لم يُنشأ على هذا الخادم بعد — شغّل php artisan migrate ثم php artisan scm:odoo-archive-index',
                'The index of the Odoo archive does not exist on this server yet — run php artisan migrate, then php artisan scm:odoo-archive-index');
        }

        return $next($request);
    }
}
