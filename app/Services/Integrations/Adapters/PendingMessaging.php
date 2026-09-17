<?php

namespace App\Services\Integrations\Adapters;

/** Default: both channels pending, nothing is sent. */
class PendingMessaging extends HttpMessaging
{
    public function __construct()
    {
        parent::__construct();
    }
}
