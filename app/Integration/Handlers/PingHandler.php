<?php

namespace App\Integration\Handlers;

use App\Integration\Models\IntInbox;
use App\Support\AuthUser;

/** `integration.ping` — a connectivity test that goes through the whole inbox path and changes nothing. */
final class PingHandler implements EventHandler
{
    public function handle(IntInbox $event, AuthUser $actor): array
    {
        return ['pong' => true, 'as' => $actor->username];
    }
}
