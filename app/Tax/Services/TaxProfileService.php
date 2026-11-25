<?php

namespace App\Tax\Services;

use App\Models\Customer;
use App\Models\Supplier;
use App\Services\Core\AuditService;
use App\Services\Core\SettingsService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Tax\Models\FiscalPosition;
use App\Tax\Models\TaxCode;
use App\Tax\Models\TaxParty;
use App\Tax\Models\TaxProfile;
use App\Tax\Support\Shape;
use App\Tax\Zatca\Codes;
use Illuminate\Support\Facades\DB;

/**
 * Who the taxpayer is (the seller of every e-invoice, the filer of the returns) and what tax needs to know about each
 * customer and supplier beyond the master record: the national address in its parts, another identification, the
 * fiscal position, the tax code of its documents, standard or simplified invoices.
 * The master records themselves (name, VAT number, commercial registration) are read, never written here.
 */
class TaxProfileService
{
    private const PROFILE_COLUMNS = [
        'legalNameAr' => 'legal_name_ar', 'legalNameEn' => 'legal_name_en', 'vatNo' => 'vat_no', 'idScheme' => 'id_scheme', 'idValue' => 'id_value', 'street' => 'street',
        'buildingNo' => 'building_no', 'additionalNo' => 'additional_no', 'district' => 'district', 'city' => 'city', 'postalCode' => 'postal_code', 'province' => 'province',
        'countryCode' => 'country_code', 'filingFrequency' => 'filing_frequency', 'einvoiceAuto' => 'einvoice_auto', 'einvoiceFrom' => 'einvoice_from',
    ];

    private const PARTY_COLUMNS = [
        'fiscalPositionId' => 'fiscal_position_id', 'defaultCodeId' => 'default_code_id', 'invoiceKind' => 'invoice_kind', 'street' => 'street', 'buildingNo' => 'building_no',
        'additionalNo' => 'additional_no', 'district' => 'district', 'city' => 'city', 'postalCode' => 'postal_code', 'countryCode' => 'country_code', 'idScheme' => 'id_scheme', 'idValue' => 'id_value',
    ];

    public function __construct(private readonly AuditService $audit, private readonly SettingsService $settings) {}

    /** The one row, made on first use from the company's name in the settings. */
    public function profile(bool $lock = false): TaxProfile
    {
        $p = TaxProfile::query()->whereKey(TaxProfile::ID)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        if (! $p) {
            $company = (array) ($this->settings->get('company') ?? []);
            DB::table('tax_profile')->insertOrIgnore(['id' => TaxProfile::ID, 'legal_name_ar' => (string) ($company['nameAr'] ?? ''), 'legal_name_en' => (string) ($company['nameEn'] ?? ''), 'id_scheme' => 'CRN',
                'country_code' => 'SA', 'filing_frequency' => 'quarterly', 'einvoice_auto' => true, 'einvoice_from' => now()->toDateString(), 'created_at' => now()]);
            $p = TaxProfile::query()->whereKey(TaxProfile::ID)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
        }

        return $p;
    }

    public function show(): array
    {
        $p = $this->profile();

        return Shape::profile($p) + ['missing' => self::missing($p)];
    }

    public function update(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            $p = $this->profile(true);
            $values = [];
            foreach (self::PROFILE_COLUMNS as $key => $column) {
                if (array_key_exists($key, $dto)) {
                    $values[$column] = is_string($dto[$key]) ? (trim($dto[$key]) === '' ? null : trim($dto[$key])) : $dto[$key];
                }
            }
            $bad = fn (string $path, string $ar, string $en) => throw AppError::validation('TAX_PROFILE_INVALID', $ar, $en, [['path' => $path, 'message' => $ar]]);
            if (array_key_exists('vat_no', $values) && $values['vat_no'] !== null && ! Codes::vatNumber($values['vat_no'])) {
                $bad('vatNo', 'الرقم الضريبي 15 رقمًا يبدأ وينتهي بالرقم 3', 'The VAT number has 15 digits, the first and the last being 3');
            }
            if (isset($values['id_scheme']) && ! isset(Codes::SELLER_IDS[$values['id_scheme']])) {
                $bad('idScheme', 'نوع المعرّف ليس من قائمة الهيئة للبائع', 'Not an identification scheme of a seller');
            }
            if (isset($values['id_value']) && ! preg_match('/^[A-Za-z0-9]{1,40}$/', $values['id_value'])) {
                $bad('idValue', 'رقم المعرّف حروف وأرقام فقط', 'The identification holds letters and digits only');
            }
            if (isset($values['building_no']) && ! preg_match('/^\d{4}$/', $values['building_no'])) {
                $bad('buildingNo', 'رقم المبنى أربعة أرقام', 'The building number has four digits');
            }
            if (isset($values['additional_no']) && ! preg_match('/^\d{4}$/', $values['additional_no'])) {
                $bad('additionalNo', 'الرقم الإضافي أربعة أرقام', 'The additional number has four digits');
            }
            if (isset($values['postal_code']) && ! preg_match('/^\d{5}$/', $values['postal_code'])) {
                $bad('postalCode', 'الرمز البريدي خمسة أرقام', 'The postal code has five digits');
            }
            if (isset($values['country_code']) && ! preg_match('/^[A-Za-z]{2}$/', $values['country_code'])) {
                $bad('countryCode', 'رمز الدولة حرفان', 'The country is a two-letter code');
            }
            if (isset($values['filing_frequency']) && ! in_array($values['filing_frequency'], TaxProfile::FREQUENCIES, true)) {
                $bad('filingFrequency', 'دورية الإقرار شهرية أو ربع سنوية', 'The filing frequency is monthly or quarterly');
            }
            foreach (['legal_name_ar' => 'legalNameAr', 'country_code' => 'countryCode', 'filing_frequency' => 'filingFrequency', 'id_scheme' => 'idScheme', 'einvoice_auto' => 'einvoiceAuto'] as $column => $key) {
                if (array_key_exists($column, $values) && $values[$column] === null) {
                    unset($values[$column]); // these are never emptied
                }
            }
            if (isset($values['country_code'])) {
                $values['country_code'] = strtoupper($values['country_code']);
            }
            $old = $new = [];
            foreach ($values as $column => $value) {
                $current = $p->getAttribute($column);
                $currentText = $current instanceof \DateTimeInterface ? $current->format('Y-m-d') : (is_bool($current) ? (int) $current : (string) ($current ?? ''));
                $valueText = is_bool($value) ? (int) $value : (string) ($value ?? '');
                if ((string) $currentText !== (string) $valueText) {
                    $old[$column] = $current instanceof \DateTimeInterface ? $current->format('Y-m-d') : $current;
                    $new[$column] = $value;
                }
            }
            if ($new) {
                $p->update($new + ['updated_by' => $user->username, 'updated_at' => now()]);
                $this->audit->log($user, ['action' => 'TAX.PROFILE.UPDATE', 'entityType' => 'TaxProfile', 'entityId' => TaxProfile::ID, 'entityNumber' => $p->vat_no ?: 'company', 'oldValue' => $old, 'newValue' => $new]);
            }

            return Shape::profile($p->refresh()) + ['missing' => self::missing($p), 'changed' => count($new)];
        });
    }

    /** What the seller's data lacks for a valid e-invoice (rules BR-KSA-08, -09, -37, -39, -40, -66). @return array<int, array{field:string, ar:string, en:string}> */
    public static function missing(TaxProfile $p): array
    {
        $out = [];
        $need = function (bool $ok, string $field, string $ar, string $en) use (&$out) {
            if (! $ok) {
                $out[] = ['field' => $field, 'ar' => $ar, 'en' => $en];
            }
        };
        $need(trim((string) $p->legal_name_ar) !== '', 'legalNameAr', 'الاسم النظامي للمنشأة', 'Legal name');
        $need(Codes::vatNumber($p->vat_no), 'vatNo', 'الرقم الضريبي (15 رقمًا يبدأ وينتهي بـ 3)', 'VAT number (15 digits, first and last 3)');
        $need(trim((string) $p->id_value) !== '', 'idValue', 'السجل التجاري أو معرّف آخر', 'Commercial registration or another identification');
        $need(trim((string) $p->street) !== '', 'street', 'اسم الشارع', 'Street name');
        $need(preg_match('/^\d{4}$/', (string) $p->building_no) === 1, 'buildingNo', 'رقم المبنى (4 أرقام)', 'Building number (4 digits)');
        $need(trim((string) $p->district) !== '', 'district', 'الحي', 'District');
        $need(trim((string) $p->city) !== '', 'city', 'المدينة', 'City');
        $need(preg_match('/^\d{5}$/', (string) $p->postal_code) === 1, 'postalCode', 'الرمز البريدي (5 أرقام)', 'Postal code (5 digits)');

        return $out;
    }

    // ───────────── customers and suppliers ─────────────

    /** @param  array{type?:?string, state?:?string, position?:?string}  $f  state: complete | incomplete | noVat */
    public function parties(Paging $page, array $f): array
    {
        $type = ($f['type'] ?? 'customer') === 'supplier' ? 'supplier' : 'customer';
        $vatColumn = $type === 'customer' ? 'vat_no' : 'vat';
        $table = $type === 'customer' ? 'customers' : 'suppliers';
        $q = ($type === 'customer' ? Customer::query() : Supplier::query())->leftJoin('tax_parties as tp', fn ($j) => $j->on('tp.partner_id', '=', "{$table}.id")->where('tp.partner_type', $type))
            ->select(["{$table}.*", 'tp.id as tp_id']);
        if (($f['state'] ?? null) === 'incomplete') {
            $q->where(fn ($w) => $w->whereNull('tp.id')->orWhereNull('tp.street')->orWhereNull('tp.city')->orWhereNull('tp.building_no')->orWhereNull('tp.postal_code')->orWhereNull('tp.district'));
        } elseif (($f['state'] ?? null) === 'complete') {
            $q->whereNotNull('tp.street')->whereNotNull('tp.city')->whereNotNull('tp.building_no')->whereNotNull('tp.postal_code')->whereNotNull('tp.district');
        } elseif (($f['state'] ?? null) === 'noVat') {
            $q->where(fn ($w) => $w->whereNull("{$table}.{$vatColumn}")->orWhere("{$table}.{$vatColumn}", ''));
        }
        if (! empty($f['position'])) {
            $q->where('tp.fiscal_position_id', $f['position']);
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where("{$table}.code", 'like', $like)->orWhere("{$table}.name_ar", 'like', $like)->orWhere("{$table}.name_en", 'like', $like)->orWhere("{$table}.{$vatColumn}", 'like', $like));
        }
        $column = ['code' => "{$table}.code", 'name' => "{$table}.name_ar", 'vat' => "{$table}.{$vatColumn}"][$page->sort ?? ''] ?? "{$table}.code";
        $q->orderBy($column, $page->sort ? $page->order : 'asc')->orderBy("{$table}.id");
        $total = (clone $q)->toBase()->getCountForPagination();
        $rows = $q->forPage($page->page, $page->pageSize)->get();
        $details = TaxParty::where('partner_type', $type)->whereIn('partner_id', $rows->pluck('id'))->get()->keyBy('partner_id');

        return $page->wrap($rows->map(fn ($partner) => Shape::party($type, $partner, $details->get($partner->id)))->values()->all(), $total);
    }

    public function party(string $type, string $idOrCode): array
    {
        $partner = $this->partner($type, $idOrCode);

        return Shape::party($type, $partner, TaxParty::where('partner_type', $type)->where('partner_id', $partner->id)->first());
    }

    public function saveParty(AuthUser $user, string $type, string $idOrCode, array $dto): array
    {
        return DB::transaction(function () use ($user, $type, $idOrCode, $dto) {
            $partner = $this->partner($type, $idOrCode);
            $row = TaxParty::where('partner_type', $type)->where('partner_id', $partner->id)->lockForUpdate()->first();
            $values = [];
            foreach (self::PARTY_COLUMNS as $key => $column) {
                if (array_key_exists($key, $dto)) {
                    $values[$column] = is_string($dto[$key]) ? (trim($dto[$key]) === '' ? null : trim($dto[$key])) : $dto[$key];
                }
            }
            $bad = fn (string $path, string $ar, string $en) => throw AppError::validation('TAX_PARTY_INVALID', $ar, $en, [['path' => $path, 'message' => $ar]]);
            if (isset($values['fiscal_position_id']) && ! FiscalPosition::whereKey($values['fiscal_position_id'])->exists()) {
                $bad('fiscalPositionId', 'الوضع الضريبي غير موجود', 'Fiscal position not found');
            }
            if (isset($values['default_code_id'])) {
                $code = TaxCode::find($values['default_code_id']);
                if (! $code || $code->use !== ($type === 'customer' ? 'sale' : 'purchase') || $code->kind === 'withholding' || ! $code->active) {
                    $bad('defaultCodeId', $type === 'customer' ? 'اختر رمز مبيعات فعّالًا' : 'اختر رمز مشتريات فعّالًا', $type === 'customer' ? 'Pick an active sales code' : 'Pick an active purchases code');
                }
            }
            if (isset($values['invoice_kind']) && ! in_array($values['invoice_kind'], TaxParty::INVOICE_KINDS, true)) {
                $bad('invoiceKind', 'نوع الفاتورة: تلقائي أو ضريبية أو مبسطة', 'Invoice kind: auto, standard or simplified');
            }
            $country = strtoupper((string) ($values['country_code'] ?? $row?->country_code ?? 'SA'));
            if (isset($values['country_code'])) {
                if (! preg_match('/^[A-Z]{2}$/', $country)) {
                    $bad('countryCode', 'رمز الدولة حرفان', 'The country is a two-letter code');
                }
                $values['country_code'] = $country;
            }
            if ($country === 'SA') {
                if (isset($values['building_no']) && ! preg_match('/^\d{4}$/', $values['building_no'])) {
                    $bad('buildingNo', 'رقم المبنى أربعة أرقام', 'The building number has four digits');
                }
                if (isset($values['postal_code']) && ! preg_match('/^\d{5}$/', $values['postal_code'])) {
                    $bad('postalCode', 'الرمز البريدي خمسة أرقام', 'The postal code has five digits');
                }
            }
            $scheme = array_key_exists('id_scheme', $values) ? $values['id_scheme'] : $row?->id_scheme;
            $value = array_key_exists('id_value', $values) ? $values['id_value'] : $row?->id_value;
            if ($scheme !== null && ! isset(Codes::BUYER_IDS[$scheme])) {
                $bad('idScheme', 'نوع المعرّف ليس من قائمة الهيئة', 'Not an identification scheme of the authority\'s list');
            }
            if (($scheme === null) !== ($value === null)) {
                $bad('idValue', 'المعرّف يحتاج نوعه ورقمه معًا', 'An identification needs both its scheme and its number');
            }
            if ($value !== null && ! preg_match('/^[A-Za-z0-9]{1,40}$/', $value)) {
                $bad('idValue', 'رقم المعرّف حروف وأرقام فقط', 'The identification holds letters and digits only');
            }
            if (array_key_exists('invoice_kind', $values) && $values['invoice_kind'] === null) {
                $values['invoice_kind'] = 'auto';
            }
            $old = $new = [];
            foreach ($values as $column => $v) {
                $current = $row?->getAttribute($column);
                if ((string) ($current ?? '') !== (string) ($v ?? '')) {
                    $old[$column] = $current;
                    $new[$column] = $v;
                }
            }
            if ($new) {
                if ($row) {
                    $row->update($new + ['updated_by' => $user->username]);
                } else {
                    $row = TaxParty::create($new + ['partner_type' => $type, 'partner_id' => $partner->id, 'invoice_kind' => $new['invoice_kind'] ?? 'auto', 'country_code' => $new['country_code'] ?? 'SA', 'updated_by' => $user->username]);
                }
                $this->audit->log($user, ['action' => 'TAX.PARTY.UPDATE', 'entityType' => $type === 'customer' ? 'Customer' : 'Supplier', 'entityId' => $partner->id, 'entityNumber' => $partner->code, 'oldValue' => $old, 'newValue' => $new]);
            }

            return Shape::party($type, $partner, $row?->refresh()) + ['changed' => count($new)];
        });
    }

    private function partner(string $type, string $idOrCode): Customer|Supplier
    {
        if (! in_array($type, TaxParty::TYPES, true)) {
            throw AppError::validation('TAX_PARTY_TYPE', 'نوع الطرف: عميل أو مورد', 'The party is a customer or a supplier');
        }
        $query = $type === 'customer' ? Customer::query() : Supplier::query();

        return $query->where(fn ($w) => $w->where('id', $idOrCode)->orWhere('code', $idOrCode))->first()
            ?? throw AppError::notFound('TAX_PARTY_NOT_FOUND', $type === 'customer' ? 'العميل غير موجود' : 'المورد غير موجود', $type === 'customer' ? 'Customer not found' : 'Supplier not found');
    }
}
