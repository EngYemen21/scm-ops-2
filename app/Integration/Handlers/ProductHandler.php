<?php

namespace App\Integration\Handlers;

use App\Integration\Models\IntInbox;
use App\Integration\Services\ExternalRefs;
use App\Integration\Services\IntegrationExceptions;
use App\Integration\Services\Mirror;
use App\Models\Product;
use App\Support\AuthUser;

/**
 * `product.created` / `product.updated` from Sales: its COMMERCIAL view of a product (name, unit, category, price).
 * OPS owns the operational product master, so nothing is created here — the record is kept for the mapping screen,
 * and an unmapped product is listed for the data steward (info) until someone links it to an OPS SKU.
 */
final class ProductHandler implements EventHandler
{
    public function __construct(private readonly ExternalRefs $refs, private readonly Mirror $mirror, private readonly IntegrationExceptions $exceptions) {}

    public function handle(IntInbox $event, AuthUser $actor): array
    {
        $d = $event->data;
        $extId = trim((string) ($d['id'] ?? ''));
        if ($extId === '' || $extId !== $event->subject) {
            throw new Rejected('PRODUCT_ID_INVALID', 'data.id is required and must equal the subject');
        }
        $name = trim((string) ($d['name'] ?? '')) ?: $extId;
        $this->mirror->put($event->source, 'product', $extId, $name, $d, $event->event_id);
        $productId = $this->refs->internalId($event->source, 'product', $extId);
        if (! $productId) {
            $this->exceptions->raise('PRODUCT_UNMAPPED', "منتج المبيعات {$extId} ({$name}) غير مربوط بصنف في العمليات", [
                'system' => $event->source, 'entity' => 'product', 'entityRef' => $extId, 'severity' => 'info', 'eventId' => $event->event_id,
                'details' => ['name' => $name, 'unit' => $d['unit'] ?? null],
            ]);

            return ['mapped' => false];
        }

        return ['mapped' => true, 'sku' => Product::whereKey($productId)->value('sku')];
    }
}
