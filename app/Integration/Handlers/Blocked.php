<?php

namespace App\Integration\Handlers;

use RuntimeException;

/** The event cannot be applied YET (e.g. an unmapped product). It is parked and replayed when the cause is fixed. */
final class Blocked extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?string $entity = null,
        public readonly ?string $entityRef = null,
        public readonly mixed $details = null,
    ) {
        parent::__construct($message);
    }
}
