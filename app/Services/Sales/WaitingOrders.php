<?php

namespace App\Services\Sales;

use Illuminate\Support\Facades\DB;

/**
 * Stock became available (a putaway confirmed, a transfer received, a return put back on the shelf): the OPS orders
 * that wait for it are tried right after the running transaction commits — oldest first, each in its own transaction —
 * instead of waiting for the next periodic run.
 *
 * A failure here never fails the stock operation that triggered it: the periodic run (`scm:backorders-allocate` under a
 * scheduler, OpsHousekeeping on the heartbeat) is the safety net. Orders of other systems are topped up by the
 * integration layer, not here.
 */
class WaitingOrders
{
    public static function stockArrived(int $limit = 20): void
    {
        DB::afterCommit(static function () use ($limit) {
            try {
                app(SalesService::class)->retryBackorders($limit);
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }
}
