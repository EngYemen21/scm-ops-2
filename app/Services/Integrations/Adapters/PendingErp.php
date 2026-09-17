<?php

namespace App\Services\Integrations\Adapters;

/** Default: no ERP / B2B platform — documents stay in the outbox as pending. */
class PendingErp implements ErpAdapter
{
    public function configured(): bool
    {
        return false;
    }

    public function pushDocument(string $type, array $payload): array
    {
        return Pending::result();
    }
}
