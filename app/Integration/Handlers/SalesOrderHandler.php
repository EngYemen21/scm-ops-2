<?php

namespace App\Integration\Handlers;

use App\Integration\Models\IntInbox;
use App\Integration\Services\OrderIntakeService;
use App\Support\AuthUser;

/** `sales_order.confirmed` / `.cancelled` / `.received` from Sales → OrderIntakeService. */
final class SalesOrderHandler implements EventHandler
{
    public function __construct(private readonly OrderIntakeService $orders) {}

    public function handle(IntInbox $event, AuthUser $actor): array
    {
        return match ($event->type) {
            'sales_order.confirmed' => $this->orders->intake($event, $actor),
            'sales_order.cancelled' => $this->orders->cancel($event, $actor),
            'sales_order.received' => $this->orders->received($event, $actor),
            default => throw new Rejected('NO_HANDLER', "no handler for {$event->type}"),
        };
    }
}
