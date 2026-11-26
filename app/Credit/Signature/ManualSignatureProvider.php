<?php

namespace App\Credit\Signature;

use App\Credit\Models\CreditEnvelope;

/**
 * "Signed agreement uploaded by hand": the agreement is printed from the request, a person hands it to the customer
 * (issuing the envelope records to whom and when), and the scan of the signed copy is uploaded — that upload is what
 * marks the envelope as signed. No outside service is called; nothing is pending on anybody but people.
 */
final class ManualSignatureProvider implements SignatureProvider
{
    public const KEY = 'manual';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): array
    {
        return ['ar' => 'توقيع يدوي — تُرفع الاتفاقية الموقَّعة', 'en' => 'By hand — the signed agreement is uploaded'];
    }

    public function configured(): bool
    {
        return true;
    }

    public function remote(): bool
    {
        return false;
    }

    public function send(CreditEnvelope $envelope, array $agreement): array
    {
        return ['status' => 'sent']; // handed to the customer by a person: there is nothing to transmit
    }

    public function status(CreditEnvelope $envelope): ?array
    {
        return null; // people record what happened: delivered, signed (with the scan), declined
    }

    public function void(CreditEnvelope $envelope, string $reason): void
    {
        // nothing was transmitted, so there is nothing to recall
    }
}
