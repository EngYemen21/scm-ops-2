<?php

namespace App\Integration\Odoo\Archive\Console;

use App\Integration\Odoo\Archive\ArchiveWriter;
use Illuminate\Console\Command;

/**
 * Builds the index of the Odoo archive from what is already copied. Safe to stop at any moment and to run again: a
 * model that was built is only caught up (what the copy changed since), a model that was stopped goes on from the
 * id it reached, a model whose definitions changed (config/odoo_archive.php) is indexed again by itself.
 * It reads the copy and writes `odoo_docs`: Odoo is not asked, and the copy is not touched.
 */
class ArchiveIndexCommand extends Command
{
    protected $signature = 'scm:odoo-archive-index
        {--model=* : only these Odoo models (sale.order, account.move …)}
        {--fresh : drop their rows first and index them from the first record}
        {--seconds=0 : stop between two steps after about this many seconds (run again to continue)}';

    protected $description = 'Odoo archive: build / catch up the index of the copied Odoo records (odoo_docs)';

    public function handle(ArchiveWriter $writer): int
    {
        if (! ArchiveWriter::installed()) {
            $this->warn('the odoo_docs tables do not exist on this database yet: run php artisan migrate');

            return 1;
        }
        $seconds = max(0, (int) $this->option('seconds'));
        $out = $writer->build($this->option('model') ?: null, (bool) $this->option('fresh'), fn (string $line) => $this->line($line), $seconds ? microtime(true) + $seconds : null);
        $failed = count($out['failed'] ?? []);
        $this->line(sprintf('%s: %d models, %d rows written, %d removed, %.1f s', $failed ? "{$failed} model(s) FAILED — see above and the log; run again"
            : ($out['complete'] ? 'index complete' : 'stopped — run again to continue'), $out['models'], $out['written'], $out['removed'], $out['seconds']));

        return $failed ? 1 : 0;
    }
}
