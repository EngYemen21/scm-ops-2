<?php

namespace App\Integration\Services;

use App\Models\SalesOrder;

/**
 * Lifecycle events of orders that came from another system (ARCHITECTURE §6–§7). The OPS services call emit() at each
 * status change; it does nothing for OPS's own orders. Written in the caller's transaction (transactional outbox), so
 * Sales learns of a step exactly when it is committed, and every event carries the Sales order id as correlation id.
 */
class OrderEvents
{
    public function __construct(private readonly EventPublisher $publisher) {}

    public function emit(?string $soId, string $type, array $data = []): void
    {
        if (! $soId) {
            return;
        }
        $so = SalesOrder::find($soId, ['id', 'number', 'status', 'fulfil_status', 'source_system', 'external_ref']);
        if (! $so || ! $so->source_system || ! $so->external_ref) {
            return;
        }
        $status = $so->status === 'confirmed' ? ($so->fulfil_status === 'partial' ? 'partially_reserved' : 'backordered') : (OrderIntakeService::SALES_STATUS[$so->status] ?? $so->status);
        $this->publisher->publish($type, $so->external_ref, ['opsOrder' => $so->number, 'status' => $status, 'at' => now()->toIso8601ZuluString('millisecond')] + $data, $so->external_ref);
    }
}
