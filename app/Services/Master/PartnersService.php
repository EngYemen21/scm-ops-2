<?php

namespace App\Services\Master;

use App\Models\Customer;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Services\Core\AuditService;
use App\Services\Core\NumberingService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Facades\DB;

/**
 * Suppliers and customers (master data). Payload arrays are camelCase and already validated by the controller;
 * a key that is present with a null value means "sent empty" (clear it), an absent key means "leave as is".
 */
class PartnersService
{
    /** Prototype supplier category keys → bilingual labels. Free-text categories are stored as given. */
    public const SUPPLIER_CATEGORIES = [
        'dry' => ['أغذية جافة', 'Dry food'], 'bev' => ['مشروبات', 'Beverages'], 'frz' => ['مجمدات ومبردات', 'Frozen & chilled'],
        'pkg' => ['تغليف وورقيات', 'Packaging'], 'cln' => ['منظفات', 'Cleaning'], 'eqp' => ['معدات', 'Equipment'],
    ];

    public const CASH_TERMS = 'نقدي / محفظة';

    public const NEW_SUPPLIER_SCORE = 70;

    private const CLOSED_SO = ['delivered', 'completed', 'cancelled', 'returned'];

    private const CLOSED_PO = ['closed', 'cancelled'];

    public function __construct(
        private readonly AuditService $audit,
        private readonly NumberingService $numbering,
    ) {}

    // ───────────────────────────── suppliers ─────────────────────────────

    /** @param  array{category?:?string, active?:?string, isNew?:?string}  $filters */
    public function listSuppliers(Paging $page, array $filters): array
    {
        $query = Supplier::query()->withCount(['products', 'pos']);
        $active = self::flag($filters['active'] ?? null);
        if ($active !== null) {
            $query->where('active', $active);
        }
        if (self::flag($filters['isNew'] ?? null) === true) {
            $query->where('is_new', true);
        }
        if (! empty($filters['category'])) {
            $category = $filters['category'];
            $label = self::SUPPLIER_CATEGORIES[$category] ?? null;
            $query->where(function ($w) use ($category, $label) {
                if ($label) {
                    $w->where('category', $label[0])->orWhere('category_en', $label[1]);
                } else {
                    $w->where('category', 'like', "%{$category}%")->orWhere('category_en', 'like', "%{$category}%");
                }
            });
        }
        if ($page->q) {
            $q = $page->q;
            $query->where(function ($w) use ($q) {
                foreach (['code', 'name_ar', 'name_en', 'cr', 'vat', 'contact'] as $column) {
                    $w->orWhere($column, 'like', "%{$q}%");
                }
            });
        }
        $sortable = ['code' => 'code', 'nameAr' => 'name_ar', 'score' => 'score', 'otif' => 'otif', 'leadDays' => 'lead_days', 'ordersCount' => 'orders_count', 'totalValue' => 'total_value', 'createdAt' => 'created_at'];
        if (isset($sortable[$page->sort ?? ''])) {
            $query->orderBy($sortable[$page->sort], $page->order);
        } else {
            $query->orderBy('code');
        }
        $query->orderBy('id');

        return $page->paginate($query, fn (Supplier $s) => Shape::withCounts($s, ['products', 'pos']));
    }

    public function getSupplier(string $idOrCode): array
    {
        $relations = ['pos', 'quotations', 'rfqs', 'shipments', 'grns', 'returns'];
        $s = Supplier::where(fn ($w) => $w->where('id', $idOrCode)->orWhere('code', $idOrCode))
            ->with(['products.product:id,sku,name_ar,name_en,active'])->withCount($relations)->first()
            ?? throw AppError::notFound('SUPPLIER_NOT_FOUND', 'المورد غير موجود', 'Supplier not found');
        $since = now()->subDays(90);
        $recentPos = PurchaseOrder::where('supplier_id', $s->id)->orderByDesc('created_at')->orderByDesc('id')->limit(5)->get()
            ->map(fn (PurchaseOrder $po) => array_intersect_key($po->toArray(), array_flip(['id', 'number', 'status', 'total', 'dueDate', 'createdAt'])))
            ->values()->all();

        return Shape::withCounts($s, $relations) + [
            'recentPosCount' => PurchaseOrder::where('supplier_id', $s->id)->where('created_at', '>=', $since)->count(),
            'openPosCount' => PurchaseOrder::where('supplier_id', $s->id)->whereNotIn('status', self::CLOSED_PO)->count(),
            'recentPos' => $recentPos,
            'blocksPo' => $s->score < 65,
        ];
    }

    /** New supplier: code from the SUP sequence (SUP-060…), provisional score 70 and isNew until 3 deliveries. */
    public function createSupplier(AuthUser $actor, array $dto): array
    {
        $this->validateSupplierIds($dto['cr'] ?? '', $dto['vat'] ?? '');
        $cr = self::digitsOnly($dto['cr']);
        $vat = self::digitsOnly($dto['vat']);
        $dupe = Supplier::where('cr', $cr)->orWhere('vat', $vat)->first();
        if ($dupe) {
            $which = $dupe->cr === $cr ? 'السجل التجاري' : 'الرقم الضريبي';
            throw AppError::rule('SUPPLIER_DUPLICATE', "{$which} مسجل للمورد {$dupe->name_ar} ({$dupe->code})", "CR/VAT already registered for supplier {$dupe->code}");
        }
        $score = self::NEW_SUPPLIER_SCORE;
        $s = DB::transaction(function () use ($actor, $dto, $cr, $vat, $score) {
            $code = $this->numbering->next('SUP');
            $r = Supplier::create([
                'code' => $code, 'name_ar' => $dto['nameAr'], 'name_en' => ($dto['nameEn'] ?? null) ?: $dto['nameAr'],
                ...Shape::snake($this->categoryPair($dto['category'] ?? null)),
                'lead_days' => $dto['leadDays'] ?? 5, 'score' => $score, 'otif' => 0, 'fill_rate' => 0, 'orders_count' => 0, 'is_new' => true,
                'cr' => $cr, 'vat' => $vat, 'contact' => $dto['contact'], 'email' => ($dto['email'] ?? null) ?: null, 'terms' => ($dto['terms'] ?? null) ?: null,
                'iban' => self::iban($dto['iban'] ?? null), 'min_order' => $dto['minOrder'] ?? null, 'notes' => ($dto['notes'] ?? null) ?: null,
            ]);
            $this->audit->log($actor, ['action' => 'SUPPLIER.CREATE', 'entityType' => 'Supplier', 'entityId' => $r->id, 'entityNumber' => $code,
                'newValue' => ['cr' => $cr, 'vat' => $vat, 'score' => $score, 'isNew' => true] + $dto]);

            return $r->refresh();
        });

        return $s->toArray() + [
            'messageAr' => "أُضيف المورد {$s->name_ar} ({$s->code}) — تقييم مبدئي {$score} حتى أول 3 توريدات",
            'messageEn' => "Supplier {$s->code} added — provisional score {$score} until first 3 deliveries",
        ];
    }

    public function updateSupplier(AuthUser $actor, string $idOrCode, array $dto): array
    {
        $s = Supplier::where('id', $idOrCode)->orWhere('code', $idOrCode)->first()
            ?? throw AppError::notFound('SUPPLIER_NOT_FOUND', 'المورد غير موجود', 'Supplier not found');
        $this->validateSupplierIds($dto['cr'] ?? null, $dto['vat'] ?? null);
        $data = [];
        foreach (['nameAr', 'contact', 'terms', 'notes', 'leadDays', 'minOrder', 'active'] as $k) {
            if (array_key_exists($k, $dto)) {
                $data[$k] = $dto[$k];
            }
        }
        if (array_key_exists('nameEn', $dto)) {
            $data['nameEn'] = $dto['nameEn'] ?: (($dto['nameAr'] ?? null) ?: $s->name_ar);
        }
        if (array_key_exists('email', $dto)) {
            $data['email'] = $dto['email'] ?: null;
        }
        if (array_key_exists('iban', $dto)) {
            $data['iban'] = self::iban($dto['iban']);
        }
        if (isset($dto['cr'])) {
            $data['cr'] = self::digitsOnly($dto['cr']);
        }
        if (isset($dto['vat'])) {
            $data['vat'] = self::digitsOnly($dto['vat']);
        }
        if (array_key_exists('category', $dto)) {
            $data = array_merge($data, $this->categoryPair($dto['category']));
        }
        if (! empty($data['cr']) || ! empty($data['vat'])) {
            $dupe = Supplier::where('id', '!=', $s->id)->where(function ($w) use ($data) {
                if (! empty($data['cr'])) {
                    $w->orWhere('cr', $data['cr']);
                }
                if (! empty($data['vat'])) {
                    $w->orWhere('vat', $data['vat']);
                }
            })->first();
            if ($dupe) {
                throw AppError::rule('SUPPLIER_DUPLICATE', "السجل التجاري / الرقم الضريبي مسجل للمورد {$dupe->name_ar} ({$dupe->code})", "CR/VAT already registered for supplier {$dupe->code}");
            }
        }
        $d = Shape::diff($s, $data);
        if (! $d['changed']) {
            return ['ok' => true, 'changed' => 0, 'messageAr' => 'لا تغييرات'];
        }
        DB::transaction(function () use ($actor, $s, $dto, $data, $d) {
            $wasActive = (bool) $s->active;
            $s->update(Shape::snake($data));
            $this->audit->log($actor, ['action' => 'SUPPLIER.UPDATE', 'entityType' => 'Supplier', 'entityId' => $s->id, 'entityNumber' => $s->code, 'oldValue' => $d['oldValue'], 'newValue' => $d['newValue']]);
            if (array_key_exists('active', $dto) && (bool) $dto['active'] !== $wasActive) {
                $this->audit->status($actor, 'Supplier', $s->id, $s->code, $wasActive ? 'active' : 'inactive', $dto['active'] ? 'active' : 'inactive');
            }
        });

        return ['ok' => true, 'changed' => $d['changed'], 'messageAr' => "حُفظ المورد {$s->code}"];
    }

    // ───────────────────────────── customers ─────────────────────────────

    /** @param  array{city?:?string, terms?:?string, priceList?:?string, active?:?string}  $filters */
    public function listCustomers(Paging $page, array $filters): array
    {
        $query = Customer::query()->withCount(['orders', 'quotations']);
        $active = self::flag($filters['active'] ?? null);
        if ($active !== null) {
            $query->where('active', $active);
        }
        if (! empty($filters['city'])) {
            $query->where('city', $filters['city']);
        }
        if (! empty($filters['priceList'])) {
            $query->where('price_list', $filters['priceList']);
        }
        $terms = $filters['terms'] ?? null;
        if ($terms === 'cash') {
            $query->where('terms', self::CASH_TERMS);
        } elseif ($terms === 'credit') {
            $query->where('terms', '!=', self::CASH_TERMS); // like Prisma `not`: rows without terms are excluded
        } elseif ($terms) {
            $query->where('terms', $terms);
        }
        if ($page->q) {
            $q = $page->q;
            $query->where(function ($w) use ($q) {
                foreach (['code', 'name_ar', 'name_en', 'zone', 'contact'] as $column) {
                    $w->orWhere($column, 'like', "%{$q}%");
                }
            });
        }
        $sortable = ['code' => 'code', 'nameAr' => 'name_ar', 'city' => 'city', 'balance' => 'balance', 'creditLimit' => 'credit_limit', 'createdAt' => 'created_at'];
        if (isset($sortable[$page->sort ?? ''])) {
            $query->orderBy($sortable[$page->sort], $page->order);
        } else {
            $query->orderBy('code');
        }
        $query->orderBy('id');

        return $page->paginate($query, fn (Customer $c) => Shape::withCounts($c, ['orders', 'quotations']) + ['availableCredit' => self::availableCredit($c)]);
    }

    public function getCustomer(string $idOrCode): array
    {
        $relations = ['orders', 'quotations', 'returns', 'fos'];
        $c = Customer::where(fn ($w) => $w->where('id', $idOrCode)->orWhere('code', $idOrCode))->withCount($relations)->first()
            ?? throw AppError::notFound('CUSTOMER_NOT_FOUND', 'العميل غير موجود', 'Customer not found');
        $recentOrders = SalesOrder::with('warehouse:id,code')->where('customer_id', $c->id)->orderByDesc('created_at')->orderByDesc('id')->limit(5)->get()
            ->map(function (SalesOrder $o) {
                $row = $o->toArray();

                return ['id' => $o->id, 'number' => $o->number, 'status' => $o->status, 'dueDate' => $row['dueDate'] ?? null, 'date' => $row['date'] ?? null, 'warehouse' => ['code' => $o->warehouse?->code]];
            })->values()->all();

        return Shape::withCounts($c, $relations) + [
            'openSosCount' => SalesOrder::where('customer_id', $c->id)->whereNotIn('status', self::CLOSED_SO)->count(),
            'recentOrders' => $recentOrders,
            'availableCredit' => self::availableCredit($c),
        ];
    }

    /** New customer: code from the CUS sequence (CUS-1008…); credit terms require a positive limit. */
    public function createCustomer(AuthUser $actor, array $dto): array
    {
        $terms = ($dto['terms'] ?? null) ?: self::CASH_TERMS;
        $creditLimit = (float) ($dto['creditLimit'] ?? 0);
        $this->checkCredit($terms, $creditLimit);
        $c = DB::transaction(function () use ($actor, $dto, $terms, $creditLimit) {
            $code = $this->numbering->next('CUS');
            $r = Customer::create([
                'code' => $code, 'name_ar' => $dto['nameAr'], 'name_en' => ($dto['nameEn'] ?? null) ?: $dto['nameAr'], 'city' => ($dto['city'] ?? null) ?: null, 'zone' => $dto['zone'],
                'terms' => $terms, 'credit_limit' => $creditLimit, 'balance' => 0, 'contact' => $dto['contact'], 'price_list' => ($dto['priceList'] ?? null) ?: null,
                'address' => ($dto['address'] ?? null) ?: null, 'lat' => $dto['lat'] ?? null, 'lng' => $dto['lng'] ?? null,
            ]);
            $this->audit->log($actor, ['action' => 'CUSTOMER.CREATE', 'entityType' => 'Customer', 'entityId' => $r->id, 'entityNumber' => $code,
                'newValue' => ['terms' => $terms, 'creditLimit' => $creditLimit] + $dto]);

            return $r->refresh();
        });

        return $c->toArray() + ['messageAr' => "أُضيف العميل {$c->name_ar} — {$c->code}", 'messageEn' => "Customer {$c->code} added"];
    }

    public function updateCustomer(AuthUser $actor, string $idOrCode, array $dto): array
    {
        $c = Customer::where('id', $idOrCode)->orWhere('code', $idOrCode)->first()
            ?? throw AppError::notFound('CUSTOMER_NOT_FOUND', 'العميل غير موجود', 'Customer not found');
        $terms = $dto['terms'] ?? $c->terms;
        $creditLimit = (float) ($dto['creditLimit'] ?? $c->credit_limit);
        if (isset($dto['terms']) || isset($dto['creditLimit'])) {
            $this->checkCredit($terms, $creditLimit);
        }
        $balance = (float) $c->balance;
        if (isset($dto['creditLimit']) && $creditLimit > 0 && $balance > $creditLimit) {
            throw AppError::rule('CUSTOMER_LIMIT_BELOW_BALANCE', 'الحد الائتماني ('.self::num($creditLimit).') أقل من الرصيد الحالي ('.self::num($balance).')', 'Credit limit below current balance');
        }
        $data = [];
        foreach (['nameAr', 'city', 'zone', 'contact', 'priceList', 'address', 'lat', 'lng', 'active', 'terms', 'creditLimit'] as $k) {
            if (array_key_exists($k, $dto)) {
                $data[$k] = $dto[$k];
            }
        }
        if (array_key_exists('nameEn', $dto)) {
            $data['nameEn'] = $dto['nameEn'] ?: (($dto['nameAr'] ?? null) ?: $c->name_ar);
        }
        $d = Shape::diff($c, $data);
        if (! $d['changed']) {
            return ['ok' => true, 'changed' => 0, 'messageAr' => 'لا تغييرات'];
        }
        DB::transaction(function () use ($actor, $c, $dto, $data, $d) {
            $wasActive = (bool) $c->active;
            $c->update(Shape::snake($data));
            $this->audit->log($actor, ['action' => 'CUSTOMER.UPDATE', 'entityType' => 'Customer', 'entityId' => $c->id, 'entityNumber' => $c->code, 'oldValue' => $d['oldValue'], 'newValue' => $d['newValue']]);
            if (array_key_exists('active', $dto) && (bool) $dto['active'] !== $wasActive) {
                $this->audit->status($actor, 'Customer', $c->id, $c->code, $wasActive ? 'active' : 'inactive', $dto['active'] ? 'active' : 'inactive');
            }
        });

        return ['ok' => true, 'changed' => $d['changed'], 'messageAr' => "حُفظ العميل {$c->code}"];
    }

    // ───────────────────────────── helpers ─────────────────────────────

    /** 'true'|'1' → true, 'false'|'0' → false, anything else → null (no filter). */
    public static function flag(mixed $value): ?bool
    {
        return match (true) {
            $value === 'true' || $value === '1' || $value === 1 || $value === true => true,
            $value === 'false' || $value === '0' || $value === 0 || $value === false => false,
            default => null,
        };
    }

    private function validateSupplierIds(?string $cr, ?string $vat): void
    {
        if ($cr !== null && ! preg_match('/^\d{10}$/', self::digitsOnly($cr))) {
            throw AppError::validation('SUPPLIER_CR', 'السجل التجاري يجب أن يكون 10 أرقام', 'Commercial registration must be 10 digits');
        }
        if ($vat !== null && ! preg_match('/^\d{15}$/', self::digitsOnly($vat))) {
            throw AppError::validation('SUPPLIER_VAT', 'الرقم الضريبي يجب أن يكون 15 رقمًا', 'VAT number must be 15 digits');
        }
    }

    /** @return array{category:?string, categoryEn:?string} */
    private function categoryPair(?string $category): array
    {
        if (! $category) {
            return ['category' => null, 'categoryEn' => null];
        }
        $label = self::SUPPLIER_CATEGORIES[$category] ?? null;

        return $label ? ['category' => $label[0], 'categoryEn' => $label[1]] : ['category' => $category, 'categoryEn' => $category];
    }

    private function checkCredit(?string $terms, float $creditLimit): void
    {
        if (($terms ?: self::CASH_TERMS) !== self::CASH_TERMS && ! ($creditLimit > 0)) {
            throw AppError::rule('CUSTOMER_CREDIT_LIMIT', 'العميل الآجل يحتاج حدًا ائتمانيًا أكبر من صفر', 'Credit-terms customers need a credit limit greater than zero');
        }
    }

    private static function availableCredit(Customer $c): ?float
    {
        return (float) $c->credit_limit > 0 ? (float) $c->credit_limit - (float) $c->balance : null;
    }

    private static function digitsOnly(?string $s): string
    {
        return preg_replace('/\s/u', '', (string) $s);
    }

    private static function iban(?string $iban): ?string
    {
        $clean = mb_strtoupper(preg_replace('/\s/u', '', (string) $iban));

        return $clean === '' ? null : $clean;
    }

    private static function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }
}
