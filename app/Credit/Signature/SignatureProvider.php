<?php

namespace App\Credit\Signature;

use App\Credit\Models\CreditEnvelope;
use Illuminate\Container\Attributes\Bind;

/**
 * How the agreement of a credit request gets signed. The credit section knows this interface only — it is neutral to
 * the provider. This installation runs ManualSignatureProvider: a person hands the printed agreement to the customer
 * and uploads the signed copy. A real e-signature provider is another implementation of this interface, bound in a
 * service provider and given its credentials (see the section's hand-over notes, "wiring"); nothing else changes.
 *
 * The statuses are the envelope's own (Flow::ENVELOPE_STATUSES): draft → sent → delivered → signed | declined |
 * expired, `failed` when it could not be sent, `voided` when a person withdrew it.
 */
#[Bind(ManualSignatureProvider::class)]
interface SignatureProvider
{
    /** The key kept on every envelope this provider handles (`credit_envelopes.provider`). */
    public function key(): string;

    /** @return array{ar:string, en:string} the provider's name for people */
    public function label(): array;

    /** The provider can be used: its credentials are there. */
    public function configured(): bool;

    /**
     * Does the provider itself carry the agreement to the signer and bring the signed copy back (true), or does a
     * person hand it over and upload what came back (false)?
     */
    public function remote(): bool;

    /**
     * Hands the agreement over for signature.
     *
     * @param  array{html:string, hash:string, number:string}  $agreement  the bilingual agreement as it stands now, and the proof of its text
     * @return array{status:string, externalId?:?string, payload?:?array, error?:?string} status `sent`, or `failed` with the error
     */
    public function send(CreditEnvelope $envelope, array $agreement): array;

    /**
     * Where the envelope stands at the provider, when that is news.
     *
     * @return array{status:string, signedPdf?:?string, payload?:?array, error?:?string}|null `signedPdf` = the bytes of the signed agreement
     */
    public function status(CreditEnvelope $envelope): ?array;

    /** Withdraws an envelope that still waits for the signer. */
    public function void(CreditEnvelope $envelope, string $reason): void;
}
