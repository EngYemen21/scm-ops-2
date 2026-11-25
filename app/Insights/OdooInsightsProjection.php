<?php

namespace App\Insights;

use App\Insights\Models\BrandAlert;
use App\Insights\Models\BrandOwner;
use App\Insights\Models\ExpiryLevel;
use App\Insights\Models\ExpiryLog;
use App\Insights\Models\Franchisee;
use App\Integration\Odoo\Models\OdooRecord;
use App\Integration\Odoo\Projection\OdooProjection;
use App\Integration\Odoo\Projection\Val;
use App\Integration\Services\ExternalRefs;
use App\Models\Customer;
use App\Services\Core\AuditService;
use App\Support\AppError;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * From the copy of Odoo's "Brand Owner Dashboard" and "Inventory Expiry Tracker" to «العلامات والصلاحية والتحليلات»:
 *   expiry.notification.level    → ins_expiry_levels (+ recipients)
 *   brand.owner                  → ins_brand_owners (+ products, categories; franchisee_ids without a mapping become
 *                                  franchisees of their own)
 *   brand.franchisee.mapping     → ins_franchisees
 *   brand.owner.dashboard.alert  → ins_brand_alerts (occurrence key `odoo:<id>`)
 *   expiry.notification.log      → ins_expiry_log
 * Rows carry `source_system = 'odoo'` + `external_ref`; idempotent (a second pass changes nothing).
 *
 * The connection user of Odoo is refused the RECORDS of these five models today: their shapes are in
 * docs/odoo/shapes/unreadable.txt and this class is written against them. Until the access is given, the copy holds
 * none and `plan()` answers ODOO_COPY_INCOMPLETE; the day it is given the mirror copies them and this class loads them.
 *
 * LINKING by identity only: a user is the OPS user whose login or e-mail is the Odoo user's (exactly one, else kept by
 * name); a partner is the OPS customer linked to it (`int_external_refs` odoo/customer, or its company's); a product /
 * category the linked OPS one; a lot the OPS batch of that product with exactly that number. What cannot be linked is
 * counted with a note, never guessed. A record deleted in Odoo is archived / stopped here — never deleted.
 */
class OdooInsightsProjection extends OdooProjection
{
    private bool $installed = true;

    /** @var array<string, array<string, Model>> entity → external_ref → row */
    private array $rows = [];

    /** @var array<string, array<string, true>> what a plan decided to create */
    private array $planned = [];

    /** @var array<int, array{0:?string, 1:?string}> Odoo user → [OPS user id, name kept] */
    private array $people = [];

    private array $userByName = [];

    private array $userByEmail = [];

    private const CLASSES = ['levels' => ExpiryLevel::class, 'owners' => BrandOwner::class, 'franchisees' => Franchisee::class, 'alerts' => BrandAlert::class, 'log' => ExpiryLog::class];

    public function __construct(ExternalRefs $refs, private readonly AuditService $audit)
    {
        parent::__construct($refs);
    }

    public function key(): string
    {
        return 'insights';
    }

    public function models(): array
    {
        return ['expiry.notification.level', 'brand.owner', 'brand.franchisee.mapping', 'brand.owner.dashboard.alert', 'expiry.notification.log'];
    }

    protected function begin(): void
    {
        $this->installed = Schema::hasTable('ins_brand_owners');
        if (! $this->installed && ! $this->dry) {
            throw AppError::rule('INSIGHTS_NOT_INSTALLED', 'جداول قسم العلامات والصلاحية لم تُنشأ بعد — شغّل الترحيل أولًا', 'The tables of the brands & expiry section do not exist yet — run the migration first');
        }
        $this->planned = $this->people = $this->userByName = $this->userByEmail = [];
        foreach (self::CLASSES as $entity => $class) {
            $this->rows[$entity] = $this->installed ? $class::where('source_system', self::SYSTEM)->get()->keyBy('external_ref')->all() : [];
        }
        foreach (DB::table('users')->where('username', 'not like', 'svc.%')->get(['id', 'username', 'email']) as $u) {
            $this->userByName[mb_strtolower((string) $u->username)] = (string) $u->id;
            if ($u->email) {
                $this->userByEmail[mb_strtolower((string) $u->email)] = (string) $u->id;
            }
        }
    }

    protected function project(OdooRecord $r): void
    {
        match ($r->model) {
            'expiry.notification.level' => $this->level($r),
            'brand.owner' => $this->owner($r),
            'brand.franchisee.mapping' => $this->mapping($r),
            'brand.owner.dashboard.alert' => $this->alert($r),
            'expiry.notification.log' => $this->log($r),
            default => null,
        };
    }

    // ───────────── expiry levels ─────────────

    private function level(OdooRecord $r): void
    {
        $d = (array) $r->data;
        [$ids, $kept] = $this->people(Val::ids($d['user_ids'] ?? []));
        $values = [
            'name' => mb_substr(Val::text($d['name'] ?? null) ?? $r->display_name ?? "#{$r->odoo_id}", 0, 120), 'days_before_expiry' => Val::int($d['days_before_expiry'] ?? 0),
            'notification_type' => self::channel($d['notification_type'] ?? null) ?? 'inapp', 'sequence' => Val::int($d['sequence'] ?? 10), 'color' => max(0, min(11, Val::int($d['color'] ?? 0))),
            'active' => $this->alive($r), 'recipients_kept' => $kept ?: null,
        ];
        $row = $this->put('levels', $r, $values, 'ExpiryLevel');
        if ($row && ! $this->dry) {
            $current = $row->recipients()->pluck('users.id')->map(fn ($id) => (string) $id)->sort()->values()->all();
            sort($ids);
            if ($current !== $ids) {
                $row->recipients()->sync($ids);
            }
        }
    }

    // ───────────── brand owners ─────────────

    private function owner(OdooRecord $r): void
    {
        $d = (array) $r->data;
        [$userId, $userName] = $this->person($d['user_id'] ?? null);
        $existing = $this->rows['owners'][(string) $r->odoo_id] ?? null;
        if ($userId && BrandOwner::where('user_id', $userId)->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))->exists()) {
            $this->count('owners.userTaken', "brand.owner #{$r->odoo_id}");
            [$userId, $userName] = [null, Val::label($d['user_id'] ?? null)];
        }
        $state = in_array($d['state'] ?? null, BrandOwner::STATES, true) ? $d['state'] : 'draft';
        $gone = ! $this->alive($r);
        $partner = Val::id($d['partner_id'] ?? null);
        $values = [
            'name' => mb_substr(Val::text($d['name'] ?? null) ?? $r->display_name ?? "#{$r->odoo_id}", 0, 190), 'brand_name' => Val::text($d['brand_name'] ?? null, 120),
            'user_id' => $userId, 'user_name' => $userName ? mb_substr($userName, 0, 190) : null,
            'contact_customer_id' => $this->customer($partner), 'contact_name' => Val::label($d['partner_id'] ?? null) ? mb_substr(Val::label($d['partner_id']), 0, 190) : null,
            'state' => $gone ? 'archived' : $state, 'active' => ! $gone, 'date_start' => Val::date($d['date_start'] ?? null),
            'allow_invoice' => Val::bool($d['allow_invoice_access'] ?? false), 'allow_payment' => Val::bool($d['allow_payment_access'] ?? false),
            'allow_return' => Val::bool($d['allow_return_access'] ?? false), 'allow_margin' => Val::bool($d['allow_margin_access'] ?? false),
            'notes' => Val::plain($d['notes'] ?? null), 'source_updated_at' => Val::at($r->write_date),
        ];
        $row = $this->put('owners', $r, $values, 'BrandOwner');
        $products = $this->linkedAll('product', Val::ids($d['product_ids'] ?? []), 'owners.productNotLinked', $r);
        $categories = $this->linkedAll('category', Val::ids($d['product_category_ids'] ?? []), 'owners.categoryNotLinked', $r);
        if (! $row || $this->dry) {
            return;
        }
        $row->products()->sync($products);
        $row->categories()->sync($categories);
        // a franchisee Odoo names on the owner without a mapping row of its own
        foreach (Val::ids($d['franchisee_ids'] ?? []) as $pid) {
            $customer = $this->customer($pid);
            if (! $customer) {
                $this->count('franchisees.skippedNoCustomer', "res.partner #{$pid}");

                continue;
            }
            if (! Franchisee::where('owner_id', $row->id)->where('customer_id', $customer)->exists()) {
                $c = Customer::find($customer);
                Franchisee::create(['owner_id' => $row->id, 'customer_id' => $customer, 'region' => $c?->zone, 'city' => $c?->city, 'monthly_target' => 0, 'currency' => 'SAR', 'status' => 'active',
                    'active' => true, 'source_system' => self::SYSTEM, 'external_ref' => "owner:{$r->odoo_id}:partner:{$pid}", 'created_by' => $this->actor()->username]);
                $this->count('franchisees.fromOwner');
            }
        }
    }

    private function mapping(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $owner = $this->ref('owners', 'brand.owner', Val::id($d['brand_owner_id'] ?? null));
        if (! $owner) {
            $this->count('franchisees.skippedNoOwner', "brand.franchisee.mapping #{$r->odoo_id}");

            return;
        }
        $customer = $this->customer(Val::id($d['partner_id'] ?? null));
        if (! $customer) {
            $this->count('franchisees.skippedNoCustomer', 'res.partner #'.(Val::id($d['partner_id'] ?? null) ?? '?'));

            return;
        }
        $status = in_array($d['status'] ?? null, Franchisee::STATES, true) ? $d['status'] : 'active';
        $gone = ! $this->alive($r);
        $values = [
            'owner_id' => $owner, 'customer_id' => $customer, 'region' => Val::text($d['region'] ?? null, 120), 'city' => Val::text($d['city'] ?? null, 120),
            'date_start' => Val::date($d['date_start'] ?? null), 'monthly_target' => round(Val::float($d['monthly_purchase_target'] ?? 0), 2),
            'currency' => mb_substr((string) (Val::label($d['currency_id'] ?? null) ?? 'SAR'), 0, 3), 'status' => $gone ? 'stopped' : $status, 'active' => ! $gone, 'notes' => Val::plain($d['notes'] ?? null),
        ];
        // the franchisee the owner's own list made (or OPS made) for this customer is the same one: adopted, not doubled
        if (! isset($this->rows['franchisees'][(string) $r->odoo_id]) && ! str_starts_with($owner, 'new:') && $this->installed) {
            $twin = Franchisee::where('owner_id', $owner)->where('customer_id', $customer)->first();
            if ($twin) {
                $this->count('franchisees.adopted');
                if (! $this->dry) {
                    $twin->update(['source_system' => self::SYSTEM, 'external_ref' => (string) $r->odoo_id]);
                }
                $this->rows['franchisees'][(string) $r->odoo_id] = $twin;
            }
        }
        $this->put('franchisees', $r, $values, 'BrandFranchisee');
    }

    // ───────────── alerts and the expiry log ─────────────

    private function alert(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $owner = $this->ref('owners', 'brand.owner', Val::id($d['brand_owner_id'] ?? null));
        if (! $owner) {
            $this->count('alerts.skippedNoOwner', "brand.owner.dashboard.alert #{$r->odoo_id}");

            return;
        }
        $type = in_array($d['alert_type'] ?? null, BrandAlert::TYPES, true) ? $d['alert_type'] : null;
        if (! $type) {
            $this->count('alerts.skippedType', "brand.owner.dashboard.alert #{$r->odoo_id}");

            return;
        }
        $title = mb_substr(Val::text($d['name'] ?? null) ?? $r->display_name ?? $type, 0, 250);
        $text = Val::plain($d['description'] ?? null);
        $this->put('alerts', $r, [
            'owner_id' => $owner, 'customer_id' => $this->customer(Val::id($d['partner_id'] ?? null)), 'alert_type' => $type, 'occurrence_key' => "odoo:{$r->odoo_id}",
            'title_ar' => $title, 'title_en' => $title, 'description_ar' => $text, 'description_en' => $text,
            'severity' => in_array($d['severity'] ?? null, BrandAlert::SEVERITIES, true) ? $d['severity'] : 'info', 'date' => Val::date($d['date'] ?? null) ?? Val::date($r->write_date),
            'is_read' => Val::bool($d['is_read'] ?? false), 'action_model' => Val::text($d['action_model'] ?? null, 60), 'action_ref' => Val::int($d['action_res_id'] ?? 0) ? (string) Val::int($d['action_res_id']) : null,
        ], 'BrandAlert', deletable: false);
    }

    private function log(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $level = $this->ref('levels', 'expiry.notification.level', Val::id($d['level_id'] ?? null));
        if (! $level) {
            $this->count('log.skippedNoLevel', "expiry.notification.log #{$r->odoo_id}");

            return;
        }
        $lotId = Val::id($d['lot_id'] ?? null);
        $lot = (array) ($this->mirrored('stock.lot', $lotId)?->data ?? []);
        $product = $this->linked('product', Val::id($lot['product_id'] ?? $d['product_id'] ?? null));
        $lotName = Val::text($lot['name'] ?? $d['lot_name'] ?? null, 120) ?? Val::label($d['lot_id'] ?? null);
        $batch = $product && $lotName ? DB::table('batches')->where('product_id', $product)->where('batch_no', $lotName)->value('id') : null;
        if (! $batch) {
            $this->count('log.noOpsBatch');
        }
        if ($batch && ! isset($this->rows['log'][(string) $r->odoo_id]) && ! str_starts_with($level, 'new:') && $this->installed
            && ExpiryLog::where('level_id', $level)->where('batch_id', $batch)->exists()) {
            $this->count('log.alreadyAnnounced', "expiry.notification.log #{$r->odoo_id}");

            return;
        }
        [$ids] = $this->people(Val::ids($d['user_ids'] ?? []));
        $expiry = Val::at($lot['expiration_date'] ?? $d['expiry_date'] ?? null);
        $at = Val::at($d['notification_date'] ?? null) ?? Val::at($r->write_date);
        $this->put('log', $r, [
            'level_id' => $level, 'batch_id' => $batch, 'product_id' => $product, 'batch_no' => $lotName ?? "#{$lotId}", 'expiry_date' => $expiry,
            'qty_on_hand' => (int) round(Val::float($d['product_qty'] ?? 0)), 'days_left' => $expiry && $at ? (int) $at->copy()->startOfDay()->diffInDays($expiry->copy()->startOfDay(), false) : null,
            'notified_at' => $at, 'notification_type' => self::channel($d['notification_type'] ?? null) ?? 'inapp', 'recipients' => $ids, 'email_status' => 'odoo', 'odoo_lot_ref' => $lotId ? (string) $lotId : null,
        ], 'ExpiryLog', deletable: false);
    }

    // ───────────── helpers ─────────────

    /**
     * One row of an entity, created or brought up to date. A record deleted in Odoo is archived (`active` false) where
     * the table has it; an alert or a log line keeps what it said. @return Model|null (null in a plan that would create)
     */
    private function put(string $entity, OdooRecord $r, array $values, string $auditType, bool $deletable = true): ?Model
    {
        $ref = (string) $r->odoo_id;
        $row = $this->rows[$entity][$ref] ?? null;
        if (! $row) {
            if ($r->deleted_at !== null) {
                $this->count("{$entity}.skippedDeleted");

                return null;
            }
            $this->count("{$entity}.created");
            if ($this->dry || array_filter($values, fn ($v) => is_string($v) && str_starts_with($v, 'new:'))) {
                $this->planned[$entity][$ref] = true;

                return null;
            }
            $class = self::CLASSES[$entity];
            $row = DB::transaction(function () use ($class, $values, $ref, $entity, $auditType, $r) {
                $extra = in_array($entity, ['alerts', 'log'], true) ? [] : ['created_by' => $this->actor()->username];
                $row = $class::create($values + ['source_system' => self::SYSTEM, 'external_ref' => $ref] + $extra);
                $this->audit->log($this->actor(), ['action' => 'INSIGHTS.'.strtoupper($entity).'.CREATE', 'entityType' => $auditType, 'entityId' => $row->getKey(),
                    'newValue' => ['origin' => "from Odoo {$r->model} #{$ref}"]]);

                return $row;
            });

            return $this->rows[$entity][$ref] = $row;
        }
        if ($r->deleted_at !== null && $deletable) {
            $values = $entity === 'owners' ? ['state' => 'archived', 'active' => false] : ($entity === 'franchisees' ? ['status' => 'stopped', 'active' => false] : ['active' => false]);
        } elseif ($r->deleted_at !== null) {
            $this->count("{$entity}.unchanged");

            return $row;
        }
        $changes = self::changes($row, $values);
        if (! $changes) {
            $this->count("{$entity}.unchanged");

            return $row;
        }
        $this->count("{$entity}.".(($changes['active'] ?? null) === false ? 'archived' : 'updated'));
        if ($this->dry) {
            return $row;
        }
        DB::transaction(function () use ($row, $changes, $entity, $auditType) {
            $old = [];
            foreach (array_keys($changes) as $c) {
                $v = $row->getAttribute($c);
                $old[$c] = $v instanceof DateTimeInterface ? $v->format('Y-m-d H:i:s') : $v;
            }
            $row->update($changes);
            $this->audit->log($this->actor(), ['action' => 'INSIGHTS.'.strtoupper($entity).'.UPDATE', 'entityType' => $auditType, 'entityId' => $row->getKey(),
                'oldValue' => $old, 'newValue' => array_map(fn ($v) => $v instanceof DateTimeInterface ? $v->format('Y-m-d H:i:s') : $v, $changes) + ['origin' => 'Odoo']]);
        });

        return $row;
    }

    /** The OPS id of a parent (an owner, a level); one the pass has not met yet is made from the copy on the spot. */
    private function ref(string $entity, string $model, ?int $odooId): ?string
    {
        if (! $odooId) {
            return null;
        }
        if ($row = $this->rows[$entity][(string) $odooId] ?? null) {
            return (string) $row->getKey();
        }
        if (! isset($this->planned[$entity][(string) $odooId]) && ($r = $this->mirrored($model, $odooId))) {
            $this->project($r);
        }
        if ($row = $this->rows[$entity][(string) $odooId] ?? null) {
            return (string) $row->getKey();
        }

        return isset($this->planned[$entity][(string) $odooId]) ? "new:{$entity}:{$odooId}" : null;
    }

    /** @return string[] OPS ids of the linked records; the others are counted */
    private function linkedAll(string $entity, array $odooIds, string $missing, OdooRecord $r): array
    {
        $out = [];
        foreach ($odooIds as $id) {
            if ($ops = $this->linked($entity, $id)) {
                $out[] = $ops;
            } else {
                $this->count($missing, "{$r->model} #{$r->odoo_id} → {$id}");
            }
        }

        return array_values(array_unique($out));
    }

    /** The OPS customer an Odoo partner is linked to; for a contact, its company's. */
    private function customer(?int $partner): ?string
    {
        if (! $partner) {
            return null;
        }
        $id = $this->linked('customer', $partner) ?? (($parent = Val::id($this->mirrored('res.partner', $partner)?->data['parent_id'] ?? null)) ? $this->linked('customer', $parent) : null);

        return $id && Customer::whereKey($id)->exists() ? $id : null;
    }

    /** @return array{0:string[], 1:string[]} matched OPS user ids, names kept of the others */
    private function people(array $odooUsers): array
    {
        $ids = $kept = [];
        foreach ($odooUsers as $u) {
            [$id, $name] = $this->person([$u, null]);
            $id ? $ids[] = $id : $kept[] = $name;
        }

        return [array_values(array_unique($ids)), array_values(array_filter($kept))];
    }

    /** @return array{0:?string, 1:?string} the OPS user (by login or e-mail, exactly one) or the name to keep */
    private function person(mixed $many2one): array
    {
        $odooId = Val::id($many2one);
        if (! $odooId) {
            return [null, null];
        }
        if (! isset($this->people[$odooId])) {
            $name = Val::label($many2one);
            $keys = [];
            if ($u = $this->mirrored('res.users', $odooId)) {
                $keys[] = Val::text($u->data['login'] ?? null);
                $keys[] = Val::text($u->data['email'] ?? null);
                $keys[] = Val::text($this->mirrored('res.partner', Val::id($u->data['partner_id'] ?? null))?->data['email'] ?? null);
                $name ??= Val::text($u->display_name);
            }
            $found = [];
            foreach (array_filter($keys) as $k) {
                $k = mb_strtolower($k);
                foreach ([$this->userByEmail[$k] ?? null, $this->userByName[$k] ?? null] as $id) {
                    if ($id) {
                        $found[$id] = true;
                    }
                }
            }
            $id = count($found) === 1 ? (string) array_key_first($found) : null;
            $this->people[$odooId] = [$id, $id ? null : ($name ?? "#{$odooId}")];
            $this->count($id ? 'people.matched' : 'people.byName');
        }

        return $this->people[$odooId];
    }

    private static function channel(mixed $v): ?string
    {
        return match ($v) {
            'email' => 'email', 'chatter' => 'inapp', 'both' => 'both', default => null,
        };
    }

    /** @return array<string, mixed> the values that differ from the row */
    private static function changes(Model $row, array $values): array
    {
        $out = [];
        foreach ($values as $column => $value) {
            $current = $row->getAttribute($column);
            if ($current instanceof DateTimeInterface || $value instanceof DateTimeInterface) {
                $format = is_string($value) && strlen($value) === 10 ? 'Y-m-d' : 'Y-m-d H:i:s';
                $same = ($current instanceof DateTimeInterface ? $current->format($format) : $current) === ($value instanceof DateTimeInterface ? $value->format($format) : $value);
            } elseif (is_bool($current) || is_bool($value)) {
                $same = $current !== null && $value !== null && (bool) $current === (bool) $value;
            } elseif (is_array($current) || is_array($value)) {
                $a = (array) ($current ?: []);
                $b = (array) ($value ?: []);
                sort($a);
                sort($b);
                $same = $a == $b;
            } elseif (is_numeric($current) && is_numeric($value)) {
                $same = abs((float) $current - (float) $value) < 0.005;
            } else {
                $same = (string) ($current ?? '') === (string) ($value ?? '');
            }
            if (! $same) {
                $out[$column] = $value;
            }
        }

        return $out;
    }
}
