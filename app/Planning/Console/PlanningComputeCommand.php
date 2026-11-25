<?php

namespace App\Planning\Console;

use App\Planning\Models\Run;
use App\Planning\Services\Computer;
use App\Support\AppError;
use Illuminate\Console\Command;

/**
 * Pushes on the forecast runs that are being computed (`computing`), slice after slice, for at most the time it is
 * given — the way a scheduler or a person finishes a large run without keeping a browser open. Safe to run while the
 * page is also pushing the same run: one worker holds a run at a time, the other one skips it.
 */
class PlanningComputeCommand extends Command
{
    protected $signature = 'scm:planning-compute {run? : number or id of one run (default: every run being computed)} {--seconds=50 : how long to work}';

    protected $description = 'Advance the demand-forecast runs that are being computed (resumable slices)';

    public function handle(Computer $computer): int
    {
        $until = microtime(true) + max(1, (int) $this->option('seconds'));
        $q = Run::where('status', 'computing')->orderBy('compute_started_at')->orderBy('id');
        if ($one = $this->argument('run')) {
            $q->where(fn ($w) => $w->where('id', $one)->orWhere('number', $one));
        }
        $runs = $q->get(['id', 'number']);
        if ($runs->isEmpty()) {
            $this->info('No run is being computed.');

            return self::SUCCESS;
        }
        foreach ($runs as $run) {
            while (microtime(true) < $until) {
                try {
                    $worked = $computer->advance($run->id, min(20.0, $until - microtime(true)));
                } catch (AppError $e) {
                    $this->error("{$run->number}: {$e->messageEn}");
                    break;
                }
                $fresh = Run::whereKey($run->id)->first(['status', 'progress_done', 'progress_total']);
                $this->line(sprintf('%s  %s  %d / %d', $run->number, $fresh->status, $fresh->progress_done, $fresh->progress_total));
                if (! $worked || $fresh->status !== 'computing') {
                    break;
                }
            }
        }

        return self::SUCCESS;
    }
}
