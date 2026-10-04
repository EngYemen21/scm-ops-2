<?php

namespace App\Integration\Handlers;

use App\Integration\Models\IntInbox;
use App\Support\AuthUser;

/**
 * Applies one received event to OPS. Runs inside the inbox transaction (its effect and the subject's sequence commit
 * together). Must be idempotent: a replay of the same event must not change anything twice.
 *
 * Throw Blocked when something must happen first (e.g. a product mapping) — the event is parked and replayed later;
 * throw Rejected when the event can never be applied as sent; any other exception is retried with back-off.
 */
interface EventHandler
{
    /** @return array summary stored on the inbox row and returned to the sender */
    public function handle(IntInbox $event, AuthUser $actor): array;
}
