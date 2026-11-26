<?php

namespace App\Commissions\Support;

/**
 * A finance document — a customer invoice or a credit note — as the commission engine reads it. Never written: the
 * finance module owns its documents.
 */
final class Doc
{
    /**
     * @param  string  $date  the document's own date, `Y-m-d`
     * @param  string  $earnedOn  the day it counts for commission, `Y-m-d`: its date, or the day it was fully settled
     * @param  array<int, array{productId:string, categoryId:?string, net:float, cogs:float}>  $lines
     */
    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly string $number,
        public readonly string $date,
        public readonly string $earnedOn,
        public readonly string $customerId,
        public readonly ?string $soId,
        public readonly ?string $creditOfId,
        public readonly string $status,
        public readonly float $subtotal,
        public readonly float $vat,
        public readonly float $total,
        public readonly float $cogs,
        public readonly array $lines,
    ) {}

    /** The same document counted on another day (a credit note follows the settlement of the invoice it credits). */
    public function on(string $earnedOn): self
    {
        return new self($this->id, $this->kind, $this->number, $this->date, $earnedOn, $this->customerId, $this->soId, $this->creditOfId, $this->status,
            $this->subtotal, $this->vat, $this->total, $this->cogs, $this->lines);
    }
}
