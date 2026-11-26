<?php

namespace App\Credit\Notifications;

/**
 * A channel that leaves the system — e-mail, WhatsApp. The credit section talks to this interface only; which
 * provider stands behind it is a deployment's decision (bind an implementation of EmailGateway / WhatsAppGateway in a
 * service provider and give it its credentials). Until then NoProvider answers "not configured" and every message of
 * the channel is logged as skipped — nothing is ever reported as sent that was not handed to a provider.
 */
interface Gateway
{
    /** A provider with its credentials stands behind the channel. */
    public function configured(): bool;

    /**
     * Hands one message to the provider.
     *
     * @param  string  $to  the address or number
     * @param  string  $template  the code of the message (the provider may map it to its own template)
     * @param  array<string, mixed>  $context  the request's number and what the message is about — never a personal value
     * @return array{ok:bool, id?:?string, error?:?string}
     */
    public function send(string $to, string $template, string $textAr, string $textEn, array $context = []): array;
}
