<?php

namespace App\Pos\Support;

use RuntimeException;

/**
 * The steps of a sale and of a refund, named — so the tests can prove "all or none" by failing each step on purpose
 * and checking that nothing of the sale remains (stock, order, invoice, receipts, journals, numbers). In production
 * nothing is armed and `pass()` is one comparison with null.
 */
final class Checkpoint
{
    /** The steps of a sale, in the order they run inside its transaction. */
    public const SALE = ['order', 'stock', 'invoice', 'cogs', 'payments', 'commit'];

    /** The steps of a refund. */
    public const REFUND = ['refund.order', 'refund.stock', 'refund.credit', 'refund.payout', 'refund.commit'];

    private static ?string $armed = null;

    /** Arms a failure at a step (tests only); null disarms. */
    public static function failAt(?string $step): void
    {
        self::$armed = $step;
    }

    public static function pass(string $step): void
    {
        if (self::$armed !== null && self::$armed === $step) {
            throw new RuntimeException("pos: failure injected at step {$step}");
        }
    }
}
