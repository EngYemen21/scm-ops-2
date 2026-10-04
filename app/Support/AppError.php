<?php

namespace App\Support;

use RuntimeException;

/**
 * Typed application error. The exception handler (bootstrap/app.php) renders it as
 * { category, code, message, messageEn, details, requestId } with the HTTP status of its category, so the client
 * can tell validation / authorization / not-found / conflict / business-rule / system errors apart.
 *
 * Throw through the factories: throw AppError::rule('PO_TRANSITION', 'انتقال غير مسموح', 'Invalid transition');
 */
class AppError extends RuntimeException
{
    public const STATUS = [
        'VALIDATION' => 400,
        'UNAUTHORIZED' => 401,
        'FORBIDDEN' => 403,
        'NOT_FOUND' => 404,
        'CONFLICT' => 409,
        'BUSINESS_RULE' => 422,
        'RATE_LIMITED' => 429,
        'SYSTEM' => 500,
        'UNAVAILABLE' => 503,
    ];

    public function __construct(
        public readonly string $category,
        public readonly string $errorCode,
        public readonly string $messageAr,
        public readonly ?string $messageEn = null,
        public readonly mixed $details = null,
    ) {
        parent::__construct($messageAr);
    }

    public function status(): int
    {
        return self::STATUS[$this->category] ?? 500;
    }

    public function toBody(?string $requestId): array
    {
        return [
            'category' => $this->category,
            'code' => $this->errorCode,
            'message' => $this->messageAr,
            'messageEn' => $this->messageEn ?? $this->messageAr,
            'details' => $this->details,
            'requestId' => $requestId,
        ];
    }

    public static function validation(string $code, string $ar, ?string $en = null, mixed $details = null): self
    {
        return new self('VALIDATION', $code, $ar, $en, $details);
    }

    public static function unauthorized(string $code, string $ar, ?string $en = null): self
    {
        return new self('UNAUTHORIZED', $code, $ar, $en);
    }

    public static function forbidden(string $code, string $ar, ?string $en = null): self
    {
        return new self('FORBIDDEN', $code, $ar, $en);
    }

    public static function notFound(string $code, string $ar, ?string $en = null): self
    {
        return new self('NOT_FOUND', $code, $ar, $en);
    }

    public static function conflict(string $code, string $ar, ?string $en = null): self
    {
        return new self('CONFLICT', $code, $ar, $en);
    }

    public static function rule(string $code, string $ar, ?string $en = null, mixed $details = null): self
    {
        return new self('BUSINESS_RULE', $code, $ar, $en, $details);
    }

    public static function rateLimited(string $code, string $ar, ?string $en = null, mixed $details = null): self
    {
        return new self('RATE_LIMITED', $code, $ar, $en, $details);
    }

    public static function unavailable(string $code, string $ar, ?string $en = null): self
    {
        return new self('UNAVAILABLE', $code, $ar, $en);
    }
}
