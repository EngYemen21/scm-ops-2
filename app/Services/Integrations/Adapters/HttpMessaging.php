<?php

namespace App\Services\Integrations\Adapters;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * WhatsApp (Meta Cloud API template message) + e-mail (SMTP). Each channel is independently configured; an
 * unconfigured channel returns `integration_pending` and sends nothing.
 *
 * - WHATSAPP_API_URL: a Meta Cloud API messages endpoint (…/{phone-number-id}/messages) or a compatible gateway.
 * - SMTP_URL: smtp://user:pass@host:587 or smtps://user:pass@host:465.
 */
class HttpMessaging implements MessagingAdapter
{
    public function __construct(
        private readonly ?string $whatsappUrl = null,
        private readonly ?string $whatsappToken = null,
        private readonly ?string $whatsappLanguage = null,
        private readonly ?string $smtpUrl = null,
        private readonly ?string $smtpFrom = null,
        private readonly int $timeoutMs = 15000,
    ) {}

    public function whatsappConfigured(): bool
    {
        return (bool) $this->whatsappUrl;
    }

    public function emailConfigured(): bool
    {
        return (bool) $this->smtpUrl;
    }

    public function sendWhatsApp(string $to, string $template, array $vars): array
    {
        if (! $this->whatsappUrl) {
            return Pending::result();
        }
        $body = [
            'messaging_product' => 'whatsapp', 'to' => preg_replace('/[^\d+]/', '', $to), 'type' => 'template',
            'template' => [
                'name' => $template, 'language' => ['code' => $this->whatsappLanguage ?: 'ar'],
                'components' => [['type' => 'body', 'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], array_values($vars))]],
            ],
        ];
        try {
            $request = Http::timeout($this->timeoutMs / 1000)->asJson();
            if ($this->whatsappToken) {
                $request = $request->withToken($this->whatsappToken);
            }
            $res = $request->post($this->whatsappUrl, $body);
            if (! $res->successful()) {
                return ['status' => 'error', 'detail' => trim("WhatsApp HTTP {$res->status()} ".Pending::snippet($res->body(), 100))];
            }
            $json = $res->json();

            return ['status' => 'ok', 'reference' => is_array($json) ? ($json['messages'][0]['id'] ?? null) : null];
        } catch (Throwable $e) {
            $detail = Pending::describeError($e);
            Log::warning("WhatsApp send failed (template {$template}): {$detail}");

            return ['status' => 'error', 'detail' => $detail];
        }
    }

    public function sendEmail(string $to, string $subject, string $html): array
    {
        if (! $this->smtpUrl) {
            return Pending::result();
        }
        try {
            $message = (new Email)->from(Address::create($this->smtpFrom ?: 'SCM OPS <no-reply@scm-ops.local>'))->to($to)->subject($subject)->html($html);
            (new Mailer(Transport::fromDsn($this->smtpUrl)))->send($message);

            return ['status' => 'ok', 'detail' => 'accepted by the SMTP server'];
        } catch (Throwable $e) {
            $detail = Pending::describeError($e);
            Log::warning("e-mail send failed (to {$to}): {$detail}");

            return ['status' => 'error', 'detail' => $detail];
        }
    }
}
