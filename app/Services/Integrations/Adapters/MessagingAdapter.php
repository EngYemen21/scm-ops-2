<?php

namespace App\Services\Integrations\Adapters;

/** Messaging (WhatsApp / e-mail). Each channel is configured independently. */
interface MessagingAdapter
{
    public function whatsappConfigured(): bool;

    public function emailConfigured(): bool;

    /**
     * @param  array<string, string|int|float>  $vars
     * @return array{status:string, detail?:?string, reference?:?string}
     */
    public function sendWhatsApp(string $to, string $template, array $vars): array;

    /** @return array{status:string, detail?:?string, reference?:?string} */
    public function sendEmail(string $to, string $subject, string $html): array;
}
