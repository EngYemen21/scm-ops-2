<?php

namespace App\Services\Integrations\Adapters;

use Illuminate\Http\Client\ConnectionException;
use Throwable;

/** The honest "not connected" vocabulary + small helpers shared by the adapters. */
final class Pending
{
    public const STATUS = 'integration_pending';

    public const DETAIL_AR = 'التكامل غير مُفعّل — لم يُضبط مزوّد الخدمة';

    public const DETAIL_EN = 'Integration pending — provider not configured';

    /** @return array{status:string, detail:string} */
    public static function result(): array
    {
        return ['status' => self::STATUS, 'detail' => self::DETAIL_EN];
    }

    /** Short, safe description of a failure for lastError / logs. Never includes headers or request bodies. */
    public static function describeError(Throwable $e): string
    {
        if ($e instanceof ConnectionException && stripos($e->getMessage(), 'timed out') !== false) {
            return 'timeout';
        }

        return mb_substr(class_basename($e).': '.$e->getMessage(), 0, 300);
    }

    /** Truncated response snippet for error details (bodies can echo secrets, so keep it very short). */
    public static function snippet(?string $text, int $n = 160): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/', ' ', (string) $text)), 0, $n);
    }
}
