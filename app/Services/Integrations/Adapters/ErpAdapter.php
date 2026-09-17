<?php

namespace App\Services\Integrations\Adapters;

/**
 * ERP / B2B platform. Adapter contract shared by every integration in this folder: each has a default "pending"
 * implementation that stores / sends NOTHING and reports `integration_pending` honestly. Nothing in this layer may
 * fake a success.
 *
 * A delivery result is `['status' => 'ok'|'integration_pending'|'error', 'detail' => ?string, 'reference' => ?string]`;
 * `ok` only when the remote side actually accepted the request.
 */
interface ErpAdapter
{
    public function configured(): bool;

    /**
     * Pushes a business document (e.g. ShipmentDispatched, InventoryReceived) to the ERP / B2B platform.
     *
     * @return array{status:string, detail?:?string, reference?:?string}
     */
    public function pushDocument(string $type, array $payload): array;
}
