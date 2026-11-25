<?php

namespace App\Pos;

use App\Integration\Odoo\Models\OdooRecord;
use App\Integration\Odoo\Projection\OdooProjection;
use App\Integration\Odoo\Projection\Val;
use App\Integration\Services\ExternalRefs;
use App\Models\Customer;
use App\Models\Product;
use App\Pos\Models\PosDenomination;
use App\Pos\Models\PosOrder;
use App\Pos\Models\PosOrderLine;
use App\Pos\Models\PosOrderPayment;
use App\Pos\Models\PosPaymentMethod;
use App\Pos\Models\PosRegister;
use App\Pos\Models\PosSession;
use App\Pos\Support\Money;
use App\Services\Core\AuditService;
use App\Support\AppError;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * From the copy of Odoo's Point of Sale to the OPS section: Odoo's register, payment methods and cash denominations
 * become the section's settings; its sessions, orders, order lines and payments become the HISTORY of the counter —
 * `source_system = 'odoo'`, the Odoo id in `external_ref`, read-only (they moved no stock and posted no journal in OPS;
 * Odoo did both). Idempotent: a second pass changes nothing.
 *
 * LINKS by identity only: a product is the OPS product the master data linked to the Odoo product
 * (`int_external_refs`, `odoo` / `product`); a customer the OPS customer its partner (or the partner's company) is
 * linked to; a cashier the OPS user whose login or e-mail is the Odoo user's — exactly one, or none: then the name is
 * kept. A denomination OPS already has with the same value is the same coin or note (adopted, never doubled). Odoo's
 * register names an Odoo warehouse OPS has no link for: it is kept without a warehouse and said so.
 *
 * A record deleted in Odoo: a setting is switched off, a document keeps its row with `source_deleted_at`.
 * plan() runs where the tables do not exist yet (everything counts as new) and never writes.
 */
class OdooPosProjection extends OdooProjection
{
    private const TABLES = ['denominations' => PosDenomination::class, 'methods' => PosPaymentMethod::class, 'registers' => PosRegister::class, 'sessions' => PosSession::class,
        'orders' => PosOrder::class, 'lines' => PosOrderLine::class, 'payments' => PosOrderPayment::class];

    private const MODELS = ['denominations' => 'pos.bill', 'methods' => 'pos.payment.method', 'registers' => 'pos.config', 'sessions' => 'pos.session', 'orders' => 'pos.order',
        'lines' => 'pos.order.line', 'payments' => 'pos.payment'];

    /** Settings are switched off when Odoo deletes them; documents are kept and marked. */
    private const SETTINGS = ['denominations', 'methods', 'registers'];

    private bool $installed = true;

    /** @var array<string, array<int|string, Model>> entity → Odoo id → OPS row */
    private array $rows = [];

    /** @var array<string, array<int, true>> what a plan decided to create */
    private array $planned = [];

    /** @var array<string, string> lower-case login / e-mail → OPS user id ('' when two users answer) */
    private array $users = [];

    /** @var array<int, array{0:?string, 1:?string}> */
    private array $people = [];

    public function __construct(ExternalRefs $refs, private readonly AuditService $audit)
    {
        parent::__construct($refs);
    }

    public function key(): string
    {
        return 'pos';
    }

    public function models(): array
    {
        return array_values(self::MODELS);
    }

    protected function begin(): void
    {
        $this->installed = Schema::hasTable('pos_orders');
        if (! $this->installed && ! $this->dry) {
            throw AppError::rule('POS_NOT_INSTALLED', 'جداول قسم نقطة البيع لم تُنشأ بعد — شغّل الترحيل أولًا (php artisan migrate)', 'The tables of the point-of-sale section do not exist yet — run the migration first');
        }
        $this->planned = $this->people = $this->users = [];
        foreach (self::TABLES as $entity => $class) {
            $this->rows[$entity] = $this->installed ? $class::where('source_system', self::SYSTEM)->get()->keyBy('external_ref')->all() : [];
        }
        foreach (DB::table('users')->where('username', 'not like', 'svc.%')->get(['id', 'username', 'email']) as $u) {
            foreach (array_filter([mb_strtolower((string) $u->username), $u->email ? mb_strtolower((string) $u->email) : null]) as $k) {
                $this->users[$k] = isset($this->users[$k]) && $this->users[$k] !== (string) $u->id ? '' : (string) $u->id;
            }
        }
    }

    protected function project(OdooRecord $r): void
    {
        match ($r->model) {
            'pos.bill' => $this->denomination($r),
            'pos.payment.method' => $this->upsert('methods', $r, $this->methodValues($r), fn () => ['code' => 'ODOO-PM-'.$r->odoo_id, 'bank_account_id' => null]),
            'pos.config' => $this->register($r),
            'pos.session' => $this->upsert('sessions', $r, $this->sessionValues($r)),
            'pos.order' => $this->upsert('orders', $r, $this->orderValues($r)),
            'pos.order.line' => $this->line($r),
            'pos.payment' => $this->payment($r),
            default => null,
        };
    }

    // ───────────── settings ─────────────

    private function denomination(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $value = Money::cents($d['value'] ?? 0);
        $values = ['value' => Money::str($value), 'kind' => $value >= 500 ? 'note' : 'coin', 'label' => Val::text($d['name'] ?? null, 60) ?? Money::str($value), 'active' => $this->alive($r)];
        $this->upsert('denominations', $r, $values, null, function () use ($value) {
            // OPS already counts with this coin or note: the same thing, adopted
            return PosDenomination::whereNull('source_system')->get()->first(fn ($x) => Money::cents($x->value) === $value);
        });
    }

    private function methodValues(OdooRecord $r): array
    {
        $d = (array) $r->data;
        $name = Val::text($d['name'] ?? null, 120) ?? "#{$r->odoo_id}";
        // Odoo: a method without a journal is "customer account" (pay later); a counted one is cash; the rest is the card terminal
        $kind = ! Val::id($d['journal_id'] ?? null) ? 'account' : (Val::bool($d['is_cash_count'] ?? false) ? 'cash' : 'card');

        return ['name_ar' => $name, 'name_en' => $name, 'kind' => $kind, 'requires_reference' => false, 'sequence' => Val::int($d['sequence'] ?? 0), 'active' => $this->alive($r),
            'extra' => array_filter(['journal' => Val::label($d['journal_id'] ?? null), 'isCashCount' => Val::bool($d['is_cash_count'] ?? false), 'splitTransactions' => Val::bool($d['split_transactions'] ?? false)])];
    }

    private function register(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $name = Val::text($d['name'] ?? null, 120) ?? "#{$r->odoo_id}";
        $this->count('registers.noWarehouse', "{$name}: ".(Val::label($d['warehouse_id'] ?? null) ?? '—'));
        $values = [
            'name_ar' => $name, 'name_en' => $name, 'max_discount_pct' => Val::bool($d['manual_discount'] ?? false) ? '100.00' : '0.00', 'allow_negative_stock' => false,
            'refund_restock' => 'returns', 'refund_needs_manager' => true, 'receipt_format' => Val::bool($d['_nf_enable_a4_size_receipt'] ?? false) ? 'a4' : '80mm', 'active' => $this->alive($r),
            'extra' => array_filter(['warehouse' => Val::label($d['warehouse_id'] ?? null), 'journal' => Val::label($d['journal_id'] ?? null), 'invoiceJournal' => Val::label($d['invoice_journal_id'] ?? null),
                'pickingType' => Val::label($d['picking_type_id'] ?? null), 'taxIncluded' => Val::text($d['iface_tax_included'] ?? null)]),
        ];
        $row = $this->upsert('registers', $r, $values, fn () => ['code' => 'ODOO-'.$r->odoo_id, 'warehouse_id' => null]);
        if ($row && ! $this->dry) {
            $ids = array_values(array_filter(array_map(fn (int $id) => $this->ref('methods', $id), Val::ids($d['payment_method_ids'] ?? []))));
            sort($ids);
            $now = $row->methods()->pluck('pos_payment_methods.id')->map(fn ($x) => (string) $x)->sort()->values()->all();
            if ($now !== $ids) {
                $row->methods()->sync($ids);
            }
        }
    }

    // ───────────── history ─────────────

    private function sessionValues(OdooRecord $r): array
    {
        $d = (array) $r->data;
        [$cashierId, $cashierName] = $this->person($d['user_id'] ?? null);

        return [
            'number' => Val::text($d['name'] ?? null, 40) ?? "ODOO-S{$r->odoo_id}", 'register_id' => $this->ref('registers', Val::id($d['config_id'] ?? null)),
            'cashier_id' => $cashierId, 'cashier_name' => $cashierName ?? Val::label($d['user_id'] ?? null), 'state' => ($d['state'] ?? null) === 'closed' ? 'closed' : 'open',
            'opened_at' => Val::at($d['start_at'] ?? null), 'closed_at' => Val::at($d['stop_at'] ?? null),
            'opening_cash' => Money::str(Money::cents($d['cash_register_balance_start'] ?? 0)), 'counted_cash' => Money::str(Money::cents($d['cash_register_balance_end_real'] ?? 0)),
            'opening_note' => Val::plain($d['opening_notes'] ?? null, 500), 'closing_note' => Val::plain($d['closing_notes'] ?? null, 500),
            'extra' => array_filter(['state' => Val::text($d['state'] ?? null), 'cashJournal' => Val::label($d['cash_journal_id'] ?? null), 'cashRealTransaction' => Val::float($d['cash_real_transaction'] ?? 0) ?: null]),
            'source_updated_at' => Val::at($r->write_date),
        ];
    }

    private function orderValues(OdooRecord $r): array
    {
        $d = (array) $r->data;
        $total = Money::cents($d['amount_total'] ?? 0);
        $tax = Money::cents($d['amount_tax'] ?? 0);
        $refund = $total < 0;
        $net = abs($total) - abs($tax);
        $partner = Val::id($d['partner_id'] ?? null);
        [$cashierId, $cashierName] = $this->person($d['user_id'] ?? null);
        $session = $this->ref('sessions', Val::id($d['session_id'] ?? null));

        return [
            'number' => Val::text($d['name'] ?? null, 40) ?? "ODOO-O{$r->odoo_id}", 'kind' => $refund ? 'refund' : 'sale', 'session_id' => $session,
            'register_id' => $this->ref('registers', Val::id($d['config_id'] ?? null)), 'cashier_id' => $cashierId, 'cashier_name' => $cashierName ?? Val::text($d['cashier'] ?? null, 190),
            'customer_id' => $this->customer($partner), 'customer_name' => Val::label($d['partner_id'] ?? null), 'ordered_at' => Val::at($d['date_order'] ?? null), 'status' => 'done',
            'subtotal' => Money::str($net), 'discount_total' => '0.00', 'vat_pct' => $net > 0 ? Money::str(Money::mulDiv(abs($tax), 10000, $net)) : '0.00', 'vat_amount' => Money::str(abs($tax)),
            'total' => Money::str(abs($total)), 'tendered' => Money::str(abs(Money::cents($d['amount_paid'] ?? 0))), 'change_due' => Money::str(abs(Money::cents($d['amount_return'] ?? 0))),
            'on_account' => '0.00', 'refunded_total' => '0.00', 'pending_qty' => 0, 'note' => Val::plain($d['note'] ?? null, 500),
            'extra' => array_filter(['posReference' => Val::text($d['pos_reference'] ?? null), 'ticketCode' => Val::text($d['ticket_code'] ?? null), 'state' => Val::text($d['state'] ?? null)]),
            'source_updated_at' => Val::at($r->write_date),
        ];
    }

    private function line(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $orderId = $this->ref('orders', Val::id($d['order_id'] ?? null));
        if (! $orderId) {
            $this->count('lines.skippedNoOrder', "#{$r->odoo_id}");

            return;
        }
        $label = Val::label($d['product_id'] ?? null);
        $productId = $this->product(Val::id($d['product_id'] ?? null));
        $this->count($productId ? 'lines.withProduct' : 'lines.productNotLinked', $productId ? null : $label);
        $qty = abs(Val::float($d['qty'] ?? 0));
        $cogs = abs(Money::cents($d['total_cost'] ?? 0));
        $sku = $label && preg_match('/^\[([^\]]+)\]/u', $label, $m) ? mb_substr($m[1], 0, 60) : null;
        $this->upsert('lines', $r, [
            'order_id' => $orderId, 'line_no' => $r->odoo_id, 'product_id' => $productId, 'sku' => $sku, 'name' => Val::text($d['full_product_name'] ?? null, 250) ?? $label ?? "#{$r->odoo_id}",
            'qty' => number_format($qty, 3, '.', ''), 'unit_price' => Money::str(Money::cents($d['price_unit'] ?? 0)), 'discount_pct' => Money::str(Money::bp($d['discount'] ?? 0)),
            'line_total' => Money::str(abs(Money::cents($d['price_subtotal'] ?? 0))), 'issued_qty' => (int) round($qty), 'pending_qty' => 0, 'refunded_qty' => 0,
            'cogs' => Money::str($cogs), 'unit_cost' => $qty > 0 ? round($cogs / 100 / $qty, 4) : 0, 'price_source' => 'odoo',
            'extra' => array_filter(['priceSubtotalIncl' => Val::float($d['price_subtotal_incl'] ?? 0) ?: null, 'customerNote' => Val::text($d['customer_note'] ?? null), 'product' => $productId ? null : $label]),
        ]);
    }

    private function payment(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $orderId = $this->ref('orders', Val::id($d['pos_order_id'] ?? null));
        if (! $orderId) {
            $this->count('payments.skippedNoOrder', "#{$r->odoo_id}");

            return;
        }
        $methodId = $this->ref('methods', Val::id($d['payment_method_id'] ?? null));
        $method = $methodId ? ($this->rows['methods'][Val::id($d['payment_method_id'] ?? null)] ?? null) : null;
        $amount = Money::str(Money::cents($d['amount'] ?? 0));
        $this->upsert('payments', $r, [
            'order_id' => $orderId, 'line_no' => $r->odoo_id, 'method_id' => $methodId, 'method_code' => $method?->code ?? Val::label($d['payment_method_id'] ?? null),
            'kind' => $method?->kind ?? 'card', 'amount' => $amount, 'applied' => $amount, 'reference' => Val::text($d['transaction_id'] ?? null, 80),
            'extra' => array_filter(['isChange' => Val::bool($d['is_change'] ?? false), 'paidAt' => Val::text($d['payment_date'] ?? null), 'cardType' => Val::text($d['card_type'] ?? null)]),
        ]);
    }

    // ───────────── the one write path ─────────────

    /**
     * Creates or updates the OPS row of one Odoo record. `$onCreate` adds columns written only once; `$twin` finds an
     * OPS row to adopt instead of creating one. Returns the row (null in a plan that would create it).
     */
    private function upsert(string $entity, OdooRecord $r, array $values, ?callable $onCreate = null, ?callable $twin = null): ?Model
    {
        $row = $this->rows[$entity][$r->odoo_id] ?? null;
        $gone = $r->deleted_at !== null;
        $label = (string) ($values['number'] ?? $values['name_ar'] ?? $values['label'] ?? $r->display_name ?? "#{$r->odoo_id}");
        if (! $row) {
            if ($gone) {
                $this->count("{$entity}.skippedDeleted");

                return null;
            }
            $adopt = $twin && $this->installed ? $twin() : null;
            $this->count($entity.($adopt ? '.linked' : '.created'));
            if ($this->dry || $this->hasMarker($values)) {
                $this->planned[$entity][$r->odoo_id] = true;

                return null;
            }
            $origin = ['source_system' => self::SYSTEM, 'external_ref' => (string) $r->odoo_id];
            $row = DB::transaction(function () use ($entity, $values, $origin, $onCreate, $adopt, $label, $r) {
                if ($adopt) {
                    $adopt->update($values + $origin);
                    $row = $adopt;
                } else {
                    $row = (self::TABLES[$entity])::create($values + $origin + ($onCreate ? $onCreate() : []) + ['created_by' => $this->actor()->username]);
                }
                if (in_array($entity, self::SETTINGS, true) || $entity === 'sessions') {
                    $this->audit->log($this->actor(), ['action' => 'POS.'.strtoupper($entity).($adopt ? '.LINK' : '.CREATE'), 'entityType' => class_basename(self::TABLES[$entity]), 'entityId' => $row->getKey(),
                        'entityNumber' => $label, 'newValue' => ['origin' => 'from Odoo '.self::MODELS[$entity]." #{$r->odoo_id}"]]);
                }

                return $row;
            });
            $this->rows[$entity][$r->odoo_id] = $row;

            return $row;
        }
        if ($gone) {
            $values = in_array($entity, self::SETTINGS, true) ? ['active' => false] : ['source_deleted_at' => $r->deleted_at];
        }
        $changes = self::changes($row, $values);
        if (! $changes) {
            $this->count("{$entity}.unchanged");

            return $row;
        }
        $this->count($entity.($gone ? '.archived' : '.updated'));
        if ($this->dry || $this->hasMarker($changes)) {
            return $row;
        }
        DB::transaction(function () use ($entity, $row, $changes, $label) {
            $old = [];
            foreach (array_keys($changes) as $c) {
                $v = $row->getAttribute($c);
                $old[$c] = $v instanceof DateTimeInterface ? $v->format('Y-m-d H:i:s') : $v;
            }
            $row->update($changes);
            $this->audit->log($this->actor(), ['action' => 'POS.'.strtoupper($entity).'.UPDATE', 'entityType' => class_basename(self::TABLES[$entity]), 'entityId' => $row->getKey(), 'entityNumber' => $label,
                'oldValue' => $old, 'newValue' => array_map(fn ($v) => $v instanceof DateTimeInterface ? $v->format('Y-m-d H:i:s') : $v, $changes) + ['origin' => 'Odoo']]);
        });

        return $row;
    }

    private function hasMarker(array $values): bool
    {
        foreach ($values as $v) {
            if (is_string($v) && str_starts_with($v, 'new:')) {
                return true;
            }
        }

        return false;
    }

    /** The OPS id of a parent; one the pass has not met yet is made from the copy on the spot. In a plan: a marker. */
    private function ref(string $entity, ?int $odooId): ?string
    {
        if (! $odooId) {
            return null;
        }
        if ($row = $this->rows[$entity][$odooId] ?? null) {
            return (string) $row->getKey();
        }
        if (! isset($this->planned[$entity][$odooId]) && ($r = $this->mirrored(self::MODELS[$entity], $odooId))) {
            $this->project($r);
        }
        if ($row = $this->rows[$entity][$odooId] ?? null) {
            return (string) $row->getKey();
        }

        return isset($this->planned[$entity][$odooId]) ? "new:{$entity}:{$odooId}" : null;
    }

    /** @return array{0:?string, 1:?string} [OPS user id, name kept when there is none] */
    private function person(mixed $many2one): array
    {
        $odooId = Val::id($many2one);
        if (! $odooId) {
            return [null, null];
        }
        if (! isset($this->people[$odooId])) {
            $keys = [];
            if ($u = $this->mirrored('res.users', $odooId)) {
                $keys = [Val::text($u->data['login'] ?? null), Val::text($u->data['email'] ?? null), Val::text($this->mirrored('res.partner', Val::id($u->data['partner_id'] ?? null))?->data['email'] ?? null)];
            }
            $found = [];
            foreach (array_filter($keys) as $k) {
                $id = $this->users[mb_strtolower($k)] ?? null;
                if ($id) {
                    $found[$id] = true;
                }
            }
            $id = count($found) === 1 ? (string) array_key_first($found) : null;
            $this->people[$odooId] = [$id, $id ? null : (Val::label($many2one) ?? "#{$odooId}")];
            $this->count($id ? 'people.matched' : 'people.byName', $id ? null : $this->people[$odooId][1]);
        }

        return $this->people[$odooId];
    }

    private function customer(?int $partnerId): ?string
    {
        if (! $partnerId) {
            return null;
        }
        $id = $this->linked('customer', $partnerId);
        if (! $id && ($p = $this->mirrored('res.partner', $partnerId)) && ($parent = Val::id($p->data['parent_id'] ?? null))) {
            $id = $this->linked('customer', $parent);
        }

        return $id && Customer::whereKey($id)->exists() ? (string) $id : null;
    }

    private function product(?int $odooId): ?string
    {
        $id = $odooId ? $this->linked('product', $odooId) : null;

        return $id && Product::whereKey($id)->exists() ? (string) $id : null;
    }

    /** The values that differ from the row: a moment equals the same moment, 5 equals "5.00", arrays by content. */
    private static function changes(Model $row, array $values): array
    {
        $out = [];
        foreach ($values as $column => $value) {
            $current = $row->getAttribute($column);
            if ($current instanceof DateTimeInterface || $value instanceof DateTimeInterface) {
                $same = ($current instanceof DateTimeInterface ? $current->format('Y-m-d H:i:s') : $current) === ($value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value);
            } elseif (is_bool($current) || is_bool($value)) {
                $same = $current !== null && $value !== null && (bool) $current === (bool) $value;
            } elseif (is_array($current) || is_array($value)) {
                [$a, $b] = [(array) ($current ?: []), (array) ($value ?: [])];
                ksort($a);
                ksort($b);
                $same = $a == $b;
            } elseif (is_numeric($current) && is_numeric($value)) {
                $same = abs((float) $current - (float) $value) < 0.0005;
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
