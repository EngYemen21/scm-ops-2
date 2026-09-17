<?php

namespace App\Services\Platform;

use Illuminate\Support\Carbon;

/** Inputs of one report run: the caller's filters plus the instants the SQL is evaluated against. */
final class ReportContext
{
    public function __construct(
        public readonly ?string $q,
        public readonly ?string $status,
        public readonly ?string $whId,
        public readonly Carbon $today,
        public readonly Carbon $now,
        public readonly Carbon $from,
        public readonly Carbon $to,
    ) {}
}
