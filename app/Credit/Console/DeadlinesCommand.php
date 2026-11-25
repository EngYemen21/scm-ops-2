<?php

namespace App\Credit\Console;

use App\Credit\Services\DeadlinesService;
use Illuminate\Console\Command;

/**
 * The clock of the credit workflow: reminders before a stage's deadline, the overdue notice after it (repeated as the
 * settings say), the escalation, and the signature envelopes that waited past their time. Idempotent — running it twice
 * at the same moment tells nobody twice. Scheduled every quarter of an hour by CreditServiceProvider.
 */
class DeadlinesCommand extends Command
{
    protected $signature = 'scm:credit-deadlines';

    protected $description = 'Credit accounts: remind, mark overdue and escalate the stages that pass their target time; expire waiting signature envelopes';

    public function handle(DeadlinesService $deadlines): int
    {
        $out = $deadlines->run();
        if (! $out['installed']) {
            $this->line('not installed: the credit tables do not exist on this database');

            return 0;
        }
        $this->line(json_encode($out, JSON_UNESCAPED_UNICODE));

        return $out['failed'] > 0 ? 1 : 0;
    }
}
