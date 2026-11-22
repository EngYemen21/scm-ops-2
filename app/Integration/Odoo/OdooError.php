<?php

namespace App\Integration\Odoo;

use RuntimeException;

/**
 * Anything Odoo (or the road to it) answered that is not data.
 *
 *  - access:    the Odoo user may not read this model or these records — a fact about the user's rights, not a fault;
 *  - missing:   the model or a field does not exist in this Odoo;
 *  - auth:      Odoo refused the login / key;
 *  - transport: no answer, a timeout, an HTTP error — worth trying again later;
 *  - odoo:      Odoo raised something else while reading (a field that cannot be computed, a broken record …).
 */
class OdooError extends RuntimeException
{
    public function __construct(public readonly string $kind, string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }

    public function retryable(): bool
    {
        return $this->kind === 'transport';
    }
}
