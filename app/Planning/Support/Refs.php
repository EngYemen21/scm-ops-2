<?php

namespace App\Planning\Support;

use App\Planning\Models\WarehouseMap;
use Illuminate\Support\Facades\DB;

/**
 * Who an Odoo record is in OPS — by identity only, never guessed:
 *   a product, a customer, a supplier: the link of `int_external_refs` (system `odoo`, entities product / customer /
 *     supplier) that the master-data projection keeps; a contact of a company counts as its company
 *     (`commercial_partner_id`);
 *   a warehouse: the mapping a holder of `forecast.manage` filled in (planning_warehouse_maps); none = not mapped.
 * Read once per object (one request, one slice of a computation) and kept for its life.
 */
final class Refs
{
    public const SYSTEM = 'odoo';

    /** @var array<string, array<string, string>> entity → Odoo id → OPS id */
    private array $links = [];

    /** @var array<int, string>|null Odoo warehouse id → OPS warehouse id */
    private ?array $warehouses = null;

    /** @var array<int, array{usage:string, warehouse:?int}>|null Odoo location id → what it is */
    private ?array $locations = null;

    /** @var array<int, array{commercial:int, name:string}>|null Odoo partner id → its company and name */
    private ?array $partners = null;

    /** @var array<int, array{factor:float, category:?int}>|null Odoo unit id → factor */
    private ?array $uoms = null;

    public function link(string $entity, int|string|null $odooId): ?string
    {
        if ($odooId === null || $odooId === '' || $odooId === 0) {
            return null;
        }
        $this->links[$entity] ??= DB::table('int_external_refs')->where('system', self::SYSTEM)->where('entity', $entity)->pluck('internal_id', 'external_id')->map(fn ($v) => (string) $v)->all();

        return $this->links[$entity][(string) $odooId] ?? null;
    }

    /** @return array<string, string> OPS id → Odoo id of an entity (the reverse links) */
    public function reverse(string $entity): array
    {
        $this->link($entity, -1);

        return array_flip($this->links[$entity]);
    }

    /** The OPS warehouse a person mapped the Odoo warehouse to, or null (not mapped). */
    public function warehouse(?int $odooWarehouse): ?string
    {
        if (! $odooWarehouse) {
            return null;
        }
        $this->warehouses ??= WarehouseMap::where('system', self::SYSTEM)->whereNotNull('warehouse_id')->pluck('warehouse_id', 'external_id')->map(fn ($v) => (string) $v)->all();

        return $this->warehouses[(string) $odooWarehouse] ?? null;
    }

    /** @return array{usage:string, warehouse:?int}|null */
    public function location(?int $id): ?array
    {
        if (! $id) {
            return null;
        }
        if ($this->locations === null) {
            $this->locations = [];
            foreach (DB::table('odoo_records')->where('model', 'stock.location')->get(['odoo_id', 'data']) as $r) {
                $d = json_decode((string) $r->data, true) ?: [];
                $this->locations[(int) $r->odoo_id] = ['usage' => (string) ($d['usage'] ?? ''), 'warehouse' => is_array($d['warehouse_id'] ?? null) ? (int) $d['warehouse_id'][0] : null];
            }
        }

        return $this->locations[$id] ?? null;
    }

    /** The company a partner belongs to (itself when it is one) — the customer an order or a delivery counts for. */
    public function commercial(?int $partner): ?int
    {
        if (! $partner) {
            return null;
        }
        $this->loadPartners();

        return $this->partners[$partner]['commercial'] ?? $partner;
    }

    public function partnerName(?int $partner): ?string
    {
        if (! $partner) {
            return null;
        }
        $this->loadPartners();

        return $this->partners[$partner]['name'] ?? null;
    }

    /**
     * The key a customer's demand is counted under: the OPS customer when the Odoo partner (or its company) is linked,
     * else `odoo:<company id>` — still one customer for the reorder patterns, but outside any OPS customer filter.
     */
    public function customerKey(?int $partner): ?string
    {
        $company = $this->commercial($partner);
        if (! $company) {
            return null;
        }

        return $this->link('customer', $company) ?? $this->link('customer', $partner) ?? 'odoo:'.$company;
    }

    /** Converts a quantity between two Odoo units of one category (factor = units per reference unit). Null when they cannot be compared. */
    public function convert(float $qty, ?int $from, ?int $to): ?float
    {
        if (! $from || ! $to || $from === $to) {
            return $qty;
        }
        if ($this->uoms === null) {
            $this->uoms = [];
            foreach (DB::table('odoo_records')->where('model', 'uom.uom')->get(['odoo_id', 'data']) as $r) {
                $d = json_decode((string) $r->data, true) ?: [];
                $this->uoms[(int) $r->odoo_id] = ['factor' => (float) ($d['factor'] ?? 1) ?: 1.0, 'category' => is_array($d['category_id'] ?? null) ? (int) $d['category_id'][0] : null];
            }
        }
        $a = $this->uoms[$from] ?? null;
        $b = $this->uoms[$to] ?? null;
        if (! $a || ! $b || $a['category'] === null || $a['category'] !== $b['category']) {
            return null;
        }

        return $qty / $a['factor'] * $b['factor'];
    }

    private function loadPartners(): void
    {
        if ($this->partners !== null) {
            return;
        }
        $this->partners = [];
        foreach (DB::table('odoo_records')->where('model', 'res.partner')->orderBy('odoo_id')->lazy(1000) as $r) {
            $d = json_decode((string) $r->data, true) ?: [];
            $company = is_array($d['commercial_partner_id'] ?? null) ? (int) $d['commercial_partner_id'][0] : (is_array($d['parent_id'] ?? null) ? (int) $d['parent_id'][0] : (int) $r->odoo_id);
            $this->partners[(int) $r->odoo_id] = ['commercial' => $company ?: (int) $r->odoo_id, 'name' => (string) ($r->display_name ?? $d['name'] ?? '')];
        }
    }
}
