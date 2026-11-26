<?php

namespace App\Planning\Engine;

/** One bucket of time of a run — a history period or a forecast period. Both ends are days of the period. */
final class Period
{
    public function __construct(
        public readonly int $index,       // 1 = the earliest
        public readonly string $label,    // "2026-11" for a month, "2026-11-22" (its first day) for a week
        public readonly string $from,     // YYYY-MM-DD
        public readonly string $to,       // YYYY-MM-DD
        public readonly int $days,
    ) {}

    public function holds(string $day): bool
    {
        return $day >= $this->from && $day <= $this->to;
    }

    public function toArray(): array
    {
        return ['index' => $this->index, 'label' => $this->label, 'from' => $this->from, 'to' => $this->to, 'days' => $this->days];
    }
}
