<?php

namespace App\Tax\Console;

use App\Tax\Services\EinvoiceService;
use App\Tax\Services\EinvoiceSubmitter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * The e-invoicing clock (a scheduler runs it every few minutes — see "wiring"): makes the e-invoices of the OPS
 * invoices that have none yet, then sends what is due to the authority. A unit that is not onboarded sends nothing:
 * then this only makes the XML, the counter, the chain and the basic QR code.
 */
class EinvoicesCommand extends Command
{
    protected $signature = 'scm:tax-einvoices {--limit=200 : how many invoices to make at most} {--seconds=40 : how long to send at most} {--no-send : make only}';

    protected $description = 'Make the missing e-invoices and send the due ones to the tax authority (nothing is sent while the unit is not onboarded)';

    public function handle(EinvoiceService $register, EinvoiceSubmitter $queue): int
    {
        if (! Schema::hasTable('tax_einvoices')) {
            $this->warn('The tables of the tax section do not exist yet (php artisan migrate).');

            return self::SUCCESS;
        }
        $made = $register->makePending(null, max(1, (int) $this->option('limit')));
        $this->line("made: {$made['made']} · waiting for data: {$made['blocked']}".($made['reasons'] ? ' ('.json_encode($made['reasons']).')' : ''));
        if (! $this->option('no-send')) {
            $sent = $queue->run(50, max(1.0, (float) $this->option('seconds')));
            $this->line('sent: '.json_encode($sent));
        }

        return self::SUCCESS;
    }
}
