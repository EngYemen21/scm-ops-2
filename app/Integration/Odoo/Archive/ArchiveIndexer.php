<?php

namespace App\Integration\Odoo\Archive;

/**
 * The door the mirror knocks on: after every page it writes into the copy of Odoo, and after it marks records
 * deleted, OdooSync calls `index($model, $odooIds)` and the archive's index (`odoo_docs`) follows the copy.
 *
 * The contract with the mirror is this class name and this method — nothing else. It can be called statically or on
 * an instance from the container (no constructor argument), and it never disturbs the caller: where the index's
 * tables do not exist yet it does nothing, and a fault in the indexing is reported and swallowed — the copy must
 * never wait for, or fail because of, the archive. What a failed call missed is caught up by
 * `php artisan scm:odoo-archive-index` (it finds what the copy changed by `changed_at`).
 */
final class ArchiveIndexer
{
    /**
     * (Re)indexes these records of one Odoo model as the copy holds them now. A record the copy marks deleted keeps
     * its row and leaves the lists; a record the copy no longer has loses its row.
     *
     * @param  int[]  $odooIds
     * @return int rows written
     */
    public static function index(string $model, array $odooIds): int
    {
        if ($model === '' || ! $odooIds) {
            return 0;
        }
        try {
            return ArchiveWriter::installed() ? app(ArchiveWriter::class)->write($model, $odooIds) : 0;
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }
}
