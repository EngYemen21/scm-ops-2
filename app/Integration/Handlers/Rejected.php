<?php

namespace App\Integration\Handlers;

use RuntimeException;

/** The event can never be applied as sent (invalid content). Not retried; raised as an exception for both sides. */
final class Rejected extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly mixed $details = null)
    {
        parent::__construct($message);
    }
}
