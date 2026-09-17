<?php

namespace App\Services\Platform;

use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\FulfillmentOrder;
use App\Models\GoodsReceipt;
use App\Models\InboundShipment;
use App\Models\Notification;
use App\Models\OpsException;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\ReturnOrder;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Models\WarehouseTransfer;
use App\Services\Core\AuditService;
use App\Services\Core\SettingsService;
use App\Services\Platform\ReportSupport as R;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Activity feed, audit trail, role/user notifications, settings and global search — the platform pages. */
class PlatformService
{
    public function __construct(private readonly AuditService $audit, private readonly SettingsService $settings) {}

    // ───────────── activity & audit ─────────────
    /** @param  array{entity?:?string, user?:?string, from?:?string, to?:?string}  $f */
    public function activity(Paging $page, array $f): array
    {
        $query = ActivityLog::query();
        $this->atRange($query, $f);
        if ($entity = self::clean($f['entity'] ?? null)) {
            $query->where(fn ($w) => $w->where('entity_type', $entity)->orWhere('entity_number', 'like', R::like($entity))->orWhere('entity_id', $entity));
        }
        if ($user = self::clean($f['user'] ?? null)) {
            $query->where('username', 'like', R::like($user));
        }
        if ($page->q) {
            $like = R::like($page->q);
            $query->where(fn ($w) => $w->where('text_ar', 'like', $like)->orWhere('text_en', 'like', $like)->orWhere('entity_number', 'like', $like)->orWhere('username', 'like', $like));
        }

        return $page->paginate($query->orderBy('at', $page->order)->orderBy('id', $page->order));
    }

    /** @param  array{entity?:?string, user?:?string, from?:?string, to?:?string, entityType?:?string, action?:?string}  $f */
    public function auditTrail(Paging $page, array $f): array
    {
        $query = AuditLog::query();
        $this->atRange($query, $f);
        if ($entityType = self::clean($f['entityType'] ?? null)) {
            $query->where('entity_type', $entityType);
        }
        if ($entity = self::clean($f['entity'] ?? null)) {
            $query->where(fn ($w) => $w->where('entity_id', $entity)->orWhere('entity_number', $entity));
        }
        if ($user = self::clean($f['user'] ?? null)) {
            $query->where(fn ($w) => $w->where('username', 'like', R::like($user))->orWhere('user_id', $user));
        }
        if ($action = self::clean($f['action'] ?? null)) {
            $query->where('action', 'like', addcslashes(mb_strtoupper($action), '%_\\').'%');
        }
        if ($page->q) {
            $like = R::like($page->q);
            $query->where(function ($w) use ($like) {
                foreach (['entity_number', 'username', 'action', 'field', 'old_value', 'new_value'] as $column) {
                    $w->orWhere($column, 'like', $like);
                }
            });
        }

        return $page->paginate($query->orderBy('at', $page->order)->orderBy('id', $page->order));
    }

    private function atRange(Builder $query, array $f): void
    {
        if ($from = R::parseDate($f['from'] ?? null)) {
            $query->where('at', '>=', R::db($from));
        }
        if ($to = R::parseDate($f['to'] ?? null, true)) {
            $query->where('at', '<=', R::db($to));
        }
    }

    private static function clean(mixed $v): ?string
    {
        $s = is_scalar($v) ? trim((string) $v) : '';

        return $s === '' ? null : $s;
    }

    // ───────────── notifications ─────────────
    /** Notifications addressed to the caller personally or to one of the caller's roles. */
    private function mine(AuthUser $user): Builder
    {
        return Notification::query()->where(function ($w) use ($user) {
            $w->where('user_id', $user->id);
            if ($user->roles) {
                $w->orWhereIn('role_key', $user->roles);
            }
        });
    }

    public function notifications(AuthUser $user, Paging $page, ?string $unread): array
    {
        $query = $this->mine($user);
        if ($unread === 'true' || $unread === '1') {
            $query->where('read', false);
        }

        return $page->paginate($query->orderBy('read')->orderByDesc('at')->orderByDesc('id')) + ['unread' => $this->unreadCount($user)];
    }

    public function markRead(AuthUser $user, string $id): array
    {
        if (! $this->mine($user)->where('id', $id)->exists()) {
            throw AppError::notFound('NOTIFICATION_NOT_FOUND', 'الإشعار غير موجود', 'Notification not found');
        }
        $this->mine($user)->where('id', $id)->update(['read' => true]);

        return ['id' => $id, 'read' => true, 'unread' => $this->unreadCount($user)];
    }

    public function markAllRead(AuthUser $user): array
    {
        $updated = $this->mine($user)->where('read', false)->update(['read' => true]);

        return ['updated' => $updated, 'unread' => 0];
    }

    private function unreadCount(AuthUser $user): int
    {
        return $this->mine($user)->where('read', false)->count();
    }

    // ───────────── settings ─────────────
    public function allSettings(): array
    {
        return $this->settings->all();
    }

    public function setSetting(AuthUser $user, string $key, mixed $value): array
    {
        if (! array_key_exists($key, SettingsService::DEFAULTS)) {
            throw AppError::notFound('SETTING_NOT_FOUND', "الإعداد «{$key}» غير معرّف", "Unknown setting \"{$key}\"");
        }
        DB::transaction(function () use ($user, $key, $value) {
            $old = $this->settings->get($key);
            $this->settings->set($key, $value, $user->id);
            $this->audit->log($user, ['action' => 'SETTING.UPDATE', 'entityType' => 'SystemSetting', 'entityId' => $key, 'entityNumber' => $key, 'field' => $key, 'oldValue' => $old, 'newValue' => $value]);
        });
        $default = SettingsService::DEFAULTS[$key];

        return ['key' => $key, 'value' => $value, 'group' => $default['group'], 'description' => $default['description'], 'isDefault' => false, 'updatedAt' => R::iso(now())];
    }

    // ───────────── global search ─────────────
    /**
     * Up to 5 hits per document type; each hit carries the entity type, its number and the path the web opens.
     *
     * @return array{q:string, hits:list<array{type:string, number:string, title:string, subtitle:string, path:string}>}
     */
    public function search(string $q): array
    {
        $s = trim($q);
        if ($s === '') {
            return ['q' => $s, 'hits' => []];
        }
        $like = R::like($s);
        $find = function (string $model, array $columns, array $with = [], ?callable $extra = null) use ($like) {
            return $model::query()->with($with)->where(function ($w) use ($columns, $like, $extra) {
                foreach ($columns as $column) {
                    $w->orWhere($column, 'like', $like);
                }
                if ($extra) {
                    $extra($w);
                }
            })->orderBy($columns[0])->limit(5)->get();
        };
        $hit = fn (string $type, string $number, string $title, string $subtitle, string $path) => compact('type', 'number', 'title', 'subtitle', 'path');
        $hits = [];

        foreach ($find(PurchaseOrder::class, ['number', 'reference'], ['supplier']) as $r) {
            $hits[] = $hit('po', $r->number, $r->number, "{$r->supplier?->name_ar} · {$r->status} · ".R::fmt($r->total).' ر.س', "/po/{$r->number}");
        }
        foreach ($find(InboundShipment::class, ['number', 'carrier'], ['supplier', 'po']) as $r) {
            $hits[] = $hit('shipment', $r->number, $r->number, "{$r->supplier?->name_ar} · {$r->po?->number} · {$r->status}", "/shipments/{$r->number}");
        }
        foreach ($find(GoodsReceipt::class, ['number'], ['supplier', 'po']) as $r) {
            $hits[] = $hit('grn', $r->number, $r->number, "{$r->supplier?->name_ar} · {$r->po?->number} · ".substr((string) R::iso($r->posted_at), 0, 10), "/grn/{$r->number}");
        }
        foreach ($find(SalesOrder::class, ['number'], ['customer']) as $r) {
            $hits[] = $hit('so', $r->number, $r->number, "{$r->customer?->name_ar} · {$r->status}", "/so/{$r->number}");
        }
        foreach ($find(FulfillmentOrder::class, ['number'], ['customer']) as $r) {
            $hits[] = $hit('fo', $r->number, $r->number, "{$r->customer?->name_ar} · {$r->status}", "/fo/{$r->number}");
        }
        foreach ($find(Trip::class, ['number', 'route_ar'], ['vehicle']) as $r) {
            $hits[] = $hit('trip', $r->number, $r->number, ($r->route_ar ?: '').' · '.($r->vehicle?->code ?: '—')." · {$r->status}", "/trip/{$r->number}");
        }
        foreach ($find(ReturnOrder::class, ['number', 'reference'], ['customer', 'supplier']) as $r) {
            $hits[] = $hit('return', $r->number, $r->number, "{$r->type} · ".($r->customer?->name_ar ?: $r->supplier?->name_ar ?: '')." · {$r->status}", "/rtn/{$r->number}");
        }
        foreach ($find(WarehouseTransfer::class, ['number'], ['fromWarehouse', 'toWarehouse']) as $r) {
            $hits[] = $hit('transfer', $r->number, $r->number, "{$r->fromWarehouse?->code} → {$r->toWarehouse?->code} · {$r->status}", "/trf/{$r->number}");
        }
        foreach ($find(OpsException::class, ['number', 'document_number', 'entity_number']) as $r) {
            $hits[] = $hit('exception', $r->number, $r->number, "{$r->kind} · {$r->text_ar} · {$r->status}", "/exc/{$r->number}");
        }
        $byBarcode = fn ($w) => $w->orWhereHas('barcodes', fn ($b) => $b->where('barcode', 'like', $like));
        foreach ($find(Product::class, ['sku', 'name_ar', 'name_en'], [], $byBarcode) as $r) {
            $hits[] = $hit('product', $r->sku, "{$r->sku} — {$r->name_ar}", "{$r->name_en} · {$r->storage_class}", "/product/{$r->sku}");
        }
        foreach ($find(Customer::class, ['code', 'name_ar', 'name_en']) as $r) {
            $hits[] = $hit('customer', $r->code, $r->name_ar, "{$r->code} · {$r->name_en}".($r->city ? ' · '.$r->city : ''), '/sales?customer='.rawurlencode($r->code));
        }
        foreach ($find(Supplier::class, ['code', 'name_ar', 'name_en']) as $r) {
            $hits[] = $hit('supplier', $r->code, $r->name_ar, "{$r->code} · {$r->name_en}".($r->category ? ' · '.$r->category : ''), '/procurement?supplier='.rawurlencode($r->code));
        }
        foreach ($find(Vehicle::class, ['code', 'plate_ar', 'plate_en']) as $r) {
            $hits[] = $hit('vehicle', $r->code, "{$r->code} — {$r->plate_ar}", "{$r->kind} · {$r->state}", '/fleet?vehicle='.rawurlencode($r->code));
        }
        foreach ($find(Driver::class, ['code', 'name_ar', 'name_en', 'mobile']) as $r) {
            $hits[] = $hit('driver', $r->code, $r->name_ar, "{$r->code} · {$r->state}".($r->mobile ? ' · '.$r->mobile : ''), '/fleet?driver='.rawurlencode($r->code));
        }

        return ['q' => $s, 'hits' => $hits];
    }
}
