<?php

namespace App\Services\Integrations\Adapters;

/** B2B platform webhook: POST {B2B_WEBHOOK_URL} with `{ type, payload, source, at }`. Same contract as the ERP adapter. */
class B2bWebhook extends HttpErp
{
    protected string $label = 'webhook';

    protected function endpoint(string $type): string
    {
        return $this->baseUrl;
    }
}
