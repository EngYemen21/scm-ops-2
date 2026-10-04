<?php

namespace App\Integration\Handlers;

use App\Integration\Models\IntInbox;
use App\Integration\Services\ExternalRefs;
use App\Integration\Services\Mirror;
use App\Models\Customer;
use App\Models\CustomerSite;
use App\Services\Core\AuditService;
use App\Services\Master\PartnersService;
use App\Support\AuthUser;

/**
 * `customer.created` / `customer.updated` from Sales — the system of record for customers (ARCHITECTURE §3).
 * First time: an OPS customer is created and linked; afterwards Sales' fields overwrite OPS's copy. Branches become
 * delivery sites keyed by the branch key; a branch Sales no longer sends is deactivated, never deleted (orders keep
 * it). Coordinates from Sales are only a hint: a site OPS has verified on its map keeps its own.
 */
final class CustomerHandler implements EventHandler
{
    public function __construct(
        private readonly ExternalRefs $refs,
        private readonly Mirror $mirror,
        private readonly PartnersService $partners,
        private readonly AuditService $audit,
    ) {}

    public function handle(IntInbox $event, AuthUser $actor): array
    {
        $d = $event->data;
        $extId = trim((string) ($d['id'] ?? ''));
        $name = trim((string) ($d['name'] ?? ''));
        if ($extId === '' || $extId !== $event->subject) {
            throw new Rejected('CUSTOMER_ID_INVALID', 'data.id is required and must equal the subject');
        }
        if ($name === '') {
            throw new Rejected('CUSTOMER_NAME_MISSING', 'data.name is required');
        }
        $system = $event->source;
        $this->mirror->put($system, 'customer', $extId, $name, $d, $event->event_id);
        $city = self::str($d['city'] ?? null, 120);
        $sor = [
            'name_ar' => mb_substr($name, 0, 190), 'name_en' => mb_substr(trim((string) ($d['nameEn'] ?? '')) ?: $name, 0, 190), 'city' => $city,
            'cr' => self::str($d['cr'] ?? null, 40), 'vat_no' => self::str($d['vat'] ?? null, 40), 'active' => ($d['active'] ?? true) !== false,
        ];
        $customerId = $this->refs->internalId($system, 'customer', $extId);
        $created = false;
        if (! $customerId) {
            $r = $this->partners->createCustomer($actor, [
                'nameAr' => $sor['name_ar'], 'nameEn' => $sor['name_en'], 'city' => $city, 'zone' => $city ?? '—',
                'contact' => self::str($d['contact'] ?? null, 120) ?? '—', 'terms' => PartnersService::CASH_TERMS, 'creditLimit' => 0,
            ]);
            $customerId = $r['id'];
            $created = true;
        }
        $c = Customer::findOrFail($customerId);
        $before = $c->only(array_merge(array_keys($sor), ['source_system']));
        $c->update($sor + ['source_system' => $system]);
        if (! $created && $c->wasChanged()) {
            $this->audit->log($actor, ['action' => 'CUSTOMER.SYNC', 'entityType' => 'Customer', 'entityId' => $c->id, 'entityNumber' => $c->code,
                'oldValue' => $before, 'newValue' => $c->only(array_keys($sor))]);
        }
        $this->refs->link($system, 'customer', $extId, $c->id, $c->code, null, $actor->username);
        $sites = $this->syncSites($c, $extId, (array) ($d['branches'] ?? []));

        return ['customer' => $c->code, 'created' => $created, 'sites' => $sites, '_replay' => ['CUSTOMER_UNMAPPED'], '_resolve' => ['CUSTOMER_UNMAPPED' => $extId]];
    }

    /** @return array{active:int, deactivated:int} */
    private function syncSites(Customer $c, string $extId, array $branches): array
    {
        $seen = [];
        foreach ($branches as $b) {
            if (! is_array($b)) {
                continue;
            }
            $bName = self::str($b['name'] ?? null, 200);
            if (! $bName) {
                continue;
            }
            $key = self::str($b['key'] ?? null, 160) ?? $extId.':'.$bName;
            $seen[] = $key;
            $site = CustomerSite::firstOrNew(['customer_id' => $c->id, 'external_key' => $key]);
            $site->fill(['name' => $bName, 'city' => self::str($b['city'] ?? null, 120), 'address' => self::str($b['address'] ?? null, 500), 'active' => true]);
            $lat = $b['lat'] ?? null;
            $lng = $b['lng'] ?? null;
            if (! $site->coords_verified && is_numeric($lat) && is_numeric($lng) && abs($lat) <= 90 && abs($lng) <= 180) {
                $site->fill(['lat' => (float) $lat, 'lng' => (float) $lng]);
            }
            $site->save();
        }
        $off = CustomerSite::where('customer_id', $c->id)->whereNotNull('external_key')->where('active', true)
            ->when($seen, fn ($q) => $q->whereNotIn('external_key', $seen))->update(['active' => false]);

        return ['active' => count($seen), 'deactivated' => $off];
    }

    private static function str(mixed $v, int $max): ?string
    {
        $s = is_scalar($v) ? trim((string) $v) : '';

        return $s === '' ? null : mb_substr($s, 0, $max);
    }
}
