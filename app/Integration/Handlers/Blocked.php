<?php

namespace App\Integration\Handlers;

use RuntimeException;

/**
 * The event cannot be applied YET (e.g. an unmapped product). It is parked and replayed when the cause is fixed.
 * $entityRef may list several records (one exception is raised per record, e.g. each unmapped product of an order).
 */
final class Blocked extends RuntimeException
{
    /** @param string|string[]|null $entityRef */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?string $entity = null,
        public readonly string|array|null $entityRef = null,
        public readonly mixed $details = null,
    ) {
        parent::__construct($message);
    }
}
