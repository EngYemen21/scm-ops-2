<?php

namespace App\Credit\Notifications;

/** The answer of a channel nobody connected yet: not configured, nothing sent. */
final class NoProvider implements EmailGateway, WhatsAppGateway
{
    public const REASON = 'no provider is configured for this channel';

    public function configured(): bool
    {
        return false;
    }

    public function send(string $to, string $template, string $textAr, string $textEn, array $context = []): array
    {
        return ['ok' => false, 'error' => self::REASON];
    }
}
