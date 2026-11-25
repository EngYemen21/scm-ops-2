<?php

namespace App\Tax\Services;

use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Tax\Models\Einvoice;
use App\Tax\Models\EinvoiceUnit;
use App\Tax\Models\FiscalPosition;
use App\Tax\Models\FiscalPositionLine;
use App\Tax\Models\TaxCode;
use App\Tax\Models\TaxGroup;
use App\Tax\Models\VatReturn;
use App\Tax\Models\VatReturnNote;
use App\Tax\Support\Boxes;
use App\Tax\Support\Money;
use App\Tax\Support\Owned;
use App\Tax\Support\Shape;
use App\Tax\Zatca\Codes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The dictionary of the section: tax codes, their groups and the fiscal positions — and the lookups every screen of
 * the section starts from. Nothing is deleted: a code, a group or a position that is no longer wanted is archived
 * (`active = false`) and stays on the documents and returns that carry it. Payloads are camelCase and already
 * validated; a key that is absent means "leave as it is".
 *
 * A TAX CODE says four things, and the service keeps them consistent:
 *   use + kind   → which lines of the VAT return it may feed (`Boxes::ALLOWED`), and its e-invoice category (S, Z, E, O)
 *   rate         → above zero for standard / import / reverse charge / withholding, zero for the others
 *   exemption    → a sales code without VAT names the reason the e-invoice must print (the authority's code list)
 *   default      → at most one active default per use and rate: the code OPS documents of that rate get by themselves
 */
class TaxCodesService
{
    private const CODE_COLUMNS = ['nameAr' => 'name_ar', 'nameEn' => 'name_en', 'label' => 'label', 'description' => 'description', 'scope' => 'scope', 'priceInclude' => 'price_include',
        'sequence' => 'sequence', 'groupId' => 'group_id', 'active' => 'active'];

    /** What stays OPS's own on a code the source system owns. */
    private const OWN_KEYS = ['code', 'isDefault'];

    public function __construct(private readonly AuditService $audit) {}

    /** Everything the forms and the filters of the section offer. */
    public function lookups(): array
    {
        $pairs = fn (array $labels) => collect($labels)->map(fn (array $l, string|int $k) => ['key' => $k, 'ar' => $l[0], 'en' => $l[1]])->values()->all();

        return [
            'follows' => Owned::following(),
            'kinds' => $pairs(Boxes::KINDS),
            'kindsByUse' => ['sale' => TaxCode::kinds('sale'), 'purchase' => TaxCode::kinds('purchase')],
            'allowedBoxes' => Boxes::ALLOWED,
            'boxes' => Boxes::catalogue(),
            'categories' => Codes::CATEGORY,
            'exemptions' => Codes::exemptions(),
            'sellerIdSchemes' => collect(Codes::SELLER_IDS)->map(fn ($ar, $k) => ['key' => (string) $k, 'ar' => $ar])->values()->all(),
            'buyerIdSchemes' => collect(Codes::BUYER_IDS)->map(fn ($ar, $k) => ['key' => (string) $k, 'ar' => $ar])->values()->all(),
            'environments' => collect(Codes::ENVIRONMENTS)->map(fn ($e, $k) => ['key' => $k, 'ar' => $e['ar'], 'en' => $e['en']])->values()->all(),
            'returnStates' => $pairs(VatReturn::LABELS),
            'einvoiceStates' => $pairs(Einvoice::LABELS),
            'unitStates' => $pairs(EinvoiceUnit::LABELS),
            'ledgerTreatments' => $pairs(VatReturnNote::TREATMENTS),
            'groups' => TaxGroup::orderBy('sequence')->orderBy('name_ar')->orderBy('id')->get()->map(fn (TaxGroup $g) => Shape::group($g))->all(),
            'codes' => TaxCode::with('group:id,name_ar,name_en')->orderBy('use')->orderBy('sequence')->orderBy('code')->get()->map(fn (TaxCode $c) => Shape::code($c))->all(),
            'positions' => FiscalPosition::orderBy('sequence')->orderBy('name_ar')->orderBy('id')->get(['id', 'name_ar', 'name_en', 'active', 'source_system', 'external_ref'])
                ->map(fn (FiscalPosition $p) => ['id' => $p->id, 'nameAr' => $p->name_ar, 'nameEn' => $p->name_en, 'active' => $p->active])->all(),
        ];
    }

    // ───────────── tax codes ─────────────

    /** @param  array{use?:?string, kind?:?string, active?:?string, origin?:?string, box?:?string, group?:?string}  $f */
    public function listCodes(Paging $page, array $f): array
    {
        $q = TaxCode::query()->with('group:id,name_ar,name_en');
        if (! empty($f['use'])) {
            $q->where('use', $f['use']);
        }
        if (! empty($f['kind'])) {
            $q->whereIn('kind', array_map('trim', explode(',', $f['kind'])));
        }
        if (($f['active'] ?? '') !== '' && $f['active'] !== null && $f['active'] !== 'all') {
            $q->where('active', filter_var($f['active'], FILTER_VALIDATE_BOOLEAN));
        }
        if (($f['origin'] ?? null) === 'odoo') {
            $q->where('source_system', 'odoo');
        } elseif (($f['origin'] ?? null) === 'ops') {
            $q->whereNull('source_system');
        }
        if (($f['box'] ?? '') === 'none') {
            $q->whereNull('return_box');
        } elseif (! empty($f['box'])) {
            $q->where('return_box', (int) $f['box']);
        }
        if (! empty($f['group'])) {
            $q->where('group_id', $f['group']);
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('code', 'like', $like)->orWhere('name_ar', 'like', $like)->orWhere('name_en', 'like', $like)->orWhere('label', 'like', $like)->orWhere('description', 'like', $like));
        }
        $column = ['code' => 'code', 'name' => 'name_ar', 'rate' => 'rate', 'box' => 'return_box', 'kind' => 'kind', 'updated' => 'updated_at'][$page->sort ?? ''] ?? null;
        if ($column) {
            $q->orderBy($column, $page->order)->orderBy('id', $page->order);
        } else {
            $q->orderBy('use')->orderBy('sequence')->orderBy('code')->orderBy('id');
        }

        return $page->paginate($q, fn (TaxCode $c) => Shape::code($c));
    }

    public function createCode(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            $values = $this->codeValues($dto, null);
            $code = self::shortCode((string) ($dto['code'] ?? ''));
            $this->codeFree($code, null);
            $c = TaxCode::create($values + ['code' => $code, 'is_default' => false, 'active' => true, 'created_by' => $user->username, 'updated_by' => $user->username]);
            if (! empty($dto['isDefault'])) {
                $this->makeDefault($user, $c);
            }
            $this->audit->log($user, ['action' => 'TAX.CODE.CREATE', 'entityType' => 'TaxCode', 'entityId' => $c->id, 'entityNumber' => $c->code, 'newValue' => $values + ['isDefault' => (bool) ($dto['isDefault'] ?? false)]]);

            return Shape::code($c->refresh()->load('group:id,name_ar,name_en'));
        });
    }

    public function updateCode(AuthUser $user, string $id, array $dto): array
    {
        return DB::transaction(function () use ($user, $id, $dto) {
            $c = TaxCode::where(fn ($w) => $w->where('id', $id)->orWhere('code', $id))->lockForUpdate()->first() ?? throw AppError::notFound('TAX_CODE_NOT_FOUND', 'الرمز الضريبي غير موجود', 'Tax code not found');
            $extra = (array) ($c->extra ?? []);
            if (Owned::bySource($c)) {
                // what Odoo holds is Odoo's; what is OPS's own on the code stays open
                $own = array_merge(self::OWN_KEYS, empty($extra['reason_from_source']) ? ['exemptionReasonCode', 'exemptionReasonAr', 'exemptionReasonEn'] : [], empty($extra['box_from_source']) ? ['returnBox'] : []);
                foreach (array_keys($dto) as $key) {
                    if (! in_array($key, $own, true)) {
                        Owned::refuse($c, 'code', $key);
                    }
                }
            }
            $before = $c->only(['code', 'name_ar', 'name_en', 'use', 'kind', 'rate', 'return_box', 'category_code', 'exemption_reason_code', 'is_default', 'active']);
            $values = $this->codeValues($dto, $c);
            if (array_key_exists('code', $dto)) {
                $values['code'] = self::shortCode((string) $dto['code']);
                $this->codeFree($values['code'], $c->id);
            }
            // a code documents were declared with keeps its meaning: its rate, its kind and its line stay as they were declared
            $meaning = array_intersect_key($values, array_flip(['use', 'kind', 'rate', 'return_box']));
            $changesMeaning = false;
            foreach ($meaning as $column => $value) {
                $changesMeaning = $changesMeaning || ($column === 'rate' ? ! Money::sameRate($c->rate, $value) : $c->getAttribute($column) !== $value);
            }
            if ($changesMeaning) {
                $declared = DB::table('tax_return_entries as e')->join('tax_returns as r', 'r.id', '=', 'e.return_id')->where('e.code_id', $c->id)->whereIn('r.status', VatReturn::FROZEN)->count();
                if ($declared > 0) {
                    throw AppError::rule('TAX_CODE_IN_USE', "هذا الرمز عليه {$declared} مستندًا في إقرارات تمت مراجعتها أو قُدّمت — لا يُغيَّر نوعه أو نسبته أو بنده؛ أنشئ رمزًا جديدًا",
                        "{$declared} documents of reviewed or filed returns carry this code — its kind, rate and line stay; create a new code instead", ['documents' => $declared]);
                }
            }
            $archiving = array_key_exists('active', $values) && ! $values['active'] && $c->active;
            if ($archiving && $c->is_default && ! array_key_exists('isDefault', $dto)) {
                $values['is_default'] = false; // an archived code is nobody's default
            }
            $changed = $this->write($user, $c, $values, 'TAX.CODE.UPDATE', 'TaxCode', $c->code);
            if (array_key_exists('isDefault', $dto) && $dto['isDefault'] !== null) {
                if ($dto['isDefault']) {
                    $changed += $this->makeDefault($user, $c->refresh()) ? 1 : 0;
                } elseif ($c->refresh()->is_default) {
                    $c->update(['is_default' => false, 'updated_by' => $user->username]);
                    $this->audit->log($user, ['action' => 'TAX.CODE.DEFAULT', 'entityType' => 'TaxCode', 'entityId' => $c->id, 'entityNumber' => $c->code, 'oldValue' => true, 'newValue' => false]);
                    $changed++;
                }
            }

            return Shape::code($c->refresh()->load('group:id,name_ar,name_en')) + ['changed' => $changed, 'was' => $changed ? $before : null];
        });
    }

    /**
     * The columns of a code from a payload, checked as a whole against what the code is (or will be).
     *
     * @return array<string, mixed> columns to write
     */
    private function codeValues(array $dto, ?TaxCode $current): array
    {
        $values = [];
        foreach (self::CODE_COLUMNS as $key => $column) {
            if (array_key_exists($key, $dto) && ! (in_array($key, ['priceInclude', 'sequence', 'active'], true) && $dto[$key] === null)) {
                $values[$column] = is_string($dto[$key]) ? (trim($dto[$key]) === '' ? null : trim($dto[$key])) : $dto[$key];
            }
        }
        if (! $current || array_key_exists('nameAr', $dto)) {
            if (($values['name_ar'] ?? null) === null) {
                throw AppError::validation('TAX_CODE_NAME', 'اسم الرمز الضريبي إلزامي', 'The tax code needs a name', [['path' => 'nameAr', 'message' => 'هذا الحقل إلزامي']]);
            }
        }
        if (array_key_exists('name_en', $values) && $values['name_en'] === null) {
            $values['name_en'] = $values['name_ar'] ?? $current?->name_ar;
        }
        if (! $current && ! isset($values['name_en'])) {
            $values['name_en'] = $values['name_ar'];
        }
        if (isset($values['group_id']) && ! TaxGroup::whereKey($values['group_id'])->exists()) {
            throw AppError::validation('TAX_GROUP_NOT_FOUND', 'مجموعة الضريبة غير موجودة', 'Tax group not found', [['path' => 'groupId', 'message' => 'غير موجودة']]);
        }

        $use = (string) ($dto['use'] ?? $current?->use);
        $kind = (string) ($dto['kind'] ?? $current?->kind);
        if (! in_array($use, TaxCode::USES, true) || ! in_array($kind, TaxCode::kinds($use), true)) {
            throw AppError::validation('TAX_CODE_KIND', 'نوع الرمز لا يوافق استخدامه (مبيعات / مشتريات)', 'The kind of the code does not fit its use (sales / purchases)', [['path' => 'kind', 'message' => 'نوع غير مسموح لهذا الاستخدام']]);
        }
        $rate = array_key_exists('rate', $dto) && $dto['rate'] !== null && $dto['rate'] !== '' ? Money::rate($dto['rate']) : Money::rate($current?->rate ?? '0');
        $taxed = in_array($kind, ['standard', 'import', 'reverse_charge', 'withholding'], true);
        if (bccomp($rate, '0', 4) < 0 || bccomp($rate, '100', 4) > 0 || ($taxed && bccomp($rate, '0', 4) === 0) || (! $taxed && bccomp($rate, '0', 4) !== 0)) {
            throw AppError::validation('TAX_CODE_RATE', $taxed ? 'هذا النوع يحتاج نسبة أكبر من صفر (ولا تزيد على 100)' : 'هذا النوع نسبته صفر', $taxed ? 'This kind needs a rate above zero (at most 100)' : 'This kind has a zero rate',
                [['path' => 'rate', 'message' => $taxed ? 'نسبة أكبر من صفر' : 'النسبة صفر']]);
        }
        // the line of the return: given, kept, or the natural one of the kind
        $box = array_key_exists('returnBox', $dto) ? ($dto['returnBox'] === null || $dto['returnBox'] === '' ? null : (int) $dto['returnBox'])
            : ($current && $current->use === $use && $current->kind === $kind ? $current->return_box : Boxes::natural($use, $kind));
        if (! Boxes::allowed($use, $kind, $box)) {
            // a code of the source system may wait for its line; one made here always has the line of its kind
            if (! ($box === null && $current && Owned::bySource($current))) {
                throw AppError::validation('TAX_CODE_BOX', 'بند الإقرار لا يوافق نوع الرمز', 'The line of the return does not fit the kind of the code', [['path' => 'returnBox', 'message' => 'بند غير مسموح لهذا النوع']]);
            }
        }
        $category = Codes::category($kind);
        $values += ['use' => $use, 'kind' => $kind, 'rate' => $rate, 'return_box' => $box, 'category_code' => $category];

        // the reason a document without VAT must give
        $reason = array_key_exists('exemptionReasonCode', $dto) ? (trim((string) $dto['exemptionReasonCode']) ?: null) : $current?->exemption_reason_code;
        $reasonAr = array_key_exists('exemptionReasonAr', $dto) ? (trim((string) $dto['exemptionReasonAr']) ?: null) : $current?->exemption_reason_ar;
        $reasonEn = array_key_exists('exemptionReasonEn', $dto) ? (trim((string) $dto['exemptionReasonEn']) ?: null) : $current?->exemption_reason_en;
        if (! in_array($category, ['Z', 'E', 'O'], true)) {
            [$reason, $reasonAr, $reasonEn] = [null, null, null];
        } else {
            if ($reason !== null && ! Codes::reasonFits($category, $reason)) {
                throw AppError::validation('TAX_CODE_REASON', 'سبب الإعفاء ليس من قائمة الهيئة لهذه الفئة', 'The exemption reason is not one of the authority\'s list for this category', [['path' => 'exemptionReasonCode', 'message' => 'سبب لا يوافق الفئة']]);
            }
            if ($reason !== null && $reason !== 'VATEX-SA-OOS') {
                // the texts of the list are the authority's; they are not typed
                [, $reasonEn, $reasonAr] = Codes::EXEMPTIONS[$reason];
            }
            $active = (bool) ($values['active'] ?? $current?->active ?? true);
            if ($use === 'sale' && $active && $reason === null && ! ($current && Owned::bySource($current))) {
                throw AppError::validation('TAX_CODE_REASON_REQUIRED', 'رمز مبيعات بلا ضريبة يحتاج سبب الإعفاء الذي تطبعه الفاتورة الإلكترونية', 'A sales code without VAT needs the exemption reason the e-invoice prints',
                    [['path' => 'exemptionReasonCode', 'message' => 'هذا الحقل إلزامي']]);
            }
            if ($reason === 'VATEX-SA-OOS' && $reasonAr === null && $reasonEn === null) {
                throw AppError::validation('TAX_CODE_REASON_TEXT', 'خارج نطاق الضريبة: اكتب السبب نصًّا', 'Out of scope: write the reason as text', [['path' => 'exemptionReasonAr', 'message' => 'هذا الحقل إلزامي']]);
            }
        }

        return $values + ['exemption_reason_code' => $reason, 'exemption_reason_ar' => $reasonAr, 'exemption_reason_en' => $reasonEn];
    }

    /** Makes a code THE default of its use and rate. @return bool whether anything changed */
    private function makeDefault(AuthUser $user, TaxCode $c): bool
    {
        if (! $c->active) {
            throw AppError::rule('TAX_CODE_ARCHIVED', 'رمز مؤرشف لا يكون الافتراضي', 'An archived code cannot be the default');
        }
        if ($c->kind === 'withholding') {
            throw AppError::rule('TAX_CODE_NOT_VAT', 'ضريبة الاستقطاع ليست رمزًا لضريبة القيمة المضافة على المستندات', 'A withholding tax is not a VAT code of documents');
        }
        if ($c->kind === 'reverse_charge') {
            throw AppError::rule('TAX_CODE_DEFAULT_KIND', 'رمز الاحتساب العكسي يُحدَّد للمستند أو للمورد، ولا يكون الافتراضي', 'A reverse-charge code is given to a document or a supplier; it cannot be the default');
        }
        if ($c->is_default) {
            return false;
        }
        $others = TaxCode::where('use', $c->use)->where('is_default', true)->where('id', '!=', $c->id)->lockForUpdate()->get()->filter(fn (TaxCode $o) => Money::sameRate($o->rate, $c->rate));
        foreach ($others as $o) {
            $o->update(['is_default' => false, 'updated_by' => $user->username]);
        }
        $c->update(['is_default' => true, 'updated_by' => $user->username]);
        $this->audit->log($user, ['action' => 'TAX.CODE.DEFAULT', 'entityType' => 'TaxCode', 'entityId' => $c->id, 'entityNumber' => $c->code,
            'oldValue' => ['default' => $others->pluck('code')->values()->all()], 'newValue' => ['default' => $c->code, 'use' => $c->use, 'rate' => Money::rate($c->rate)]]);

        return true;
    }

    private static function shortCode(string $code): string
    {
        $code = strtoupper(trim($code));
        if (! preg_match('/^[A-Z0-9][A-Z0-9._-]{0,29}$/', $code)) {
            throw AppError::validation('TAX_CODE_CODE', 'الرمز المختصر حروف لاتينية وأرقام (حتى 30) ويمكن أن يحوي . _ -', 'The short code is Latin letters and digits (up to 30), with . _ - allowed', [['path' => 'code', 'message' => 'صيغة غير صحيحة']]);
        }

        return $code;
    }

    private function codeFree(string $code, ?string $exceptId): void
    {
        if (TaxCode::where('code', $code)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists()) {
            throw AppError::conflict('TAX_CODE_EXISTS', "الرمز «{$code}» موجود مسبقًا", "The code \"{$code}\" already exists");
        }
    }

    // ───────────── groups ─────────────

    public function createGroup(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            $g = TaxGroup::create(['name_ar' => trim($dto['nameAr']), 'name_en' => trim((string) ($dto['nameEn'] ?? '')) ?: trim($dto['nameAr']),
                'sequence' => $dto['sequence'] ?? ((int) TaxGroup::max('sequence') + 10), 'active' => true, 'created_by' => $user->username]);
            $this->audit->log($user, ['action' => 'TAX.GROUP.CREATE', 'entityType' => 'TaxGroup', 'entityId' => $g->id, 'entityNumber' => $g->name_ar, 'newValue' => $dto]);

            return Shape::group($g->refresh());
        });
    }

    public function updateGroup(AuthUser $user, string $id, array $dto): array
    {
        return DB::transaction(function () use ($user, $id, $dto) {
            $g = TaxGroup::whereKey($id)->lockForUpdate()->first() ?? throw AppError::notFound('TAX_GROUP_NOT_FOUND', 'مجموعة الضريبة غير موجودة', 'Tax group not found');
            Owned::refuse($g, 'group');
            $values = $this->names($dto, 'TAX_GROUP_NAME', $g);
            foreach (['sequence', 'active'] as $key) {
                if (array_key_exists($key, $dto) && $dto[$key] !== null) {
                    $values[$key] = $dto[$key];
                }
            }
            if (($values['active'] ?? true) === false && $g->active && TaxCode::where('group_id', $g->id)->where('active', true)->exists()) {
                throw AppError::rule('TAX_GROUP_IN_USE', 'المجموعة عليها رموز ضريبية فعّالة — أرشف الرموز أو انقلها أولًا', 'Active tax codes belong to this group — archive or move them first');
            }
            $changed = $this->write($user, $g, $values, 'TAX.GROUP.UPDATE', 'TaxGroup', $g->name_ar);

            return Shape::group($g->refresh()) + ['changed' => $changed];
        });
    }

    // ───────────── fiscal positions ─────────────

    public function positions(): array
    {
        return FiscalPosition::with(['lines.from', 'lines.to'])->orderBy('sequence')->orderBy('name_ar')->orderBy('id')->get()->map(fn (FiscalPosition $p) => Shape::position($p)
            + ['partners' => (int) DB::table('tax_parties')->where('fiscal_position_id', $p->id)->count()])->all();
    }

    public function createPosition(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            $p = FiscalPosition::create($this->positionValues($dto, null) + ['active' => true, 'created_by' => $user->username]);
            $this->replaceLines($p, $dto['lines'] ?? []);
            $this->audit->log($user, ['action' => 'TAX.POSITION.CREATE', 'entityType' => 'FiscalPosition', 'entityId' => $p->id, 'entityNumber' => $p->name_ar, 'newValue' => $dto]);

            return Shape::position($p->refresh()->load(['lines.from', 'lines.to']));
        });
    }

    public function updatePosition(AuthUser $user, string $id, array $dto): array
    {
        return DB::transaction(function () use ($user, $id, $dto) {
            $p = FiscalPosition::whereKey($id)->lockForUpdate()->first() ?? throw AppError::notFound('TAX_POSITION_NOT_FOUND', 'الوضع الضريبي غير موجود', 'Fiscal position not found');
            Owned::refuse($p, 'position');
            $changed = $this->write($user, $p, $this->positionValues($dto, $p), 'TAX.POSITION.UPDATE', 'FiscalPosition', $p->name_ar);
            if (array_key_exists('lines', $dto) && is_array($dto['lines'])) {
                $before = $p->lines()->get(['from_code_id', 'to_code_id'])->map(fn ($l) => [$l->from_code_id, $l->to_code_id])->all();
                $after = $this->replaceLines($p, $dto['lines']);
                if ($before !== $after) {
                    $this->audit->log($user, ['action' => 'TAX.POSITION.LINES', 'entityType' => 'FiscalPosition', 'entityId' => $p->id, 'entityNumber' => $p->name_ar, 'oldValue' => $before, 'newValue' => $after]);
                    $changed++;
                }
            }

            return Shape::position($p->refresh()->load(['lines.from', 'lines.to'])) + ['changed' => $changed];
        });
    }

    private function positionValues(array $dto, ?FiscalPosition $current): array
    {
        $values = $this->names($dto, 'TAX_POSITION_NAME', $current);
        if (! $current && ! isset($values['name_ar'])) {
            throw AppError::validation('TAX_POSITION_NAME', 'اسم الوضع الضريبي إلزامي', 'The fiscal position needs a name', [['path' => 'nameAr', 'message' => 'هذا الحقل إلزامي']]);
        }
        if (! $current && ! isset($values['name_en'])) {
            $values['name_en'] = $values['name_ar'];
        }
        foreach (['sequence' => 'sequence', 'autoApply' => 'auto_apply', 'vatRequired' => 'vat_required', 'active' => 'active'] as $key => $column) {
            if (array_key_exists($key, $dto) && $dto[$key] !== null) {
                $values[$column] = $dto[$key];
            }
        }
        foreach (['countryCode' => 'country_code', 'countryGroup' => 'country_group', 'zipFrom' => 'zip_from', 'zipTo' => 'zip_to', 'foreignVat' => 'foreign_vat', 'note' => 'note'] as $key => $column) {
            if (array_key_exists($key, $dto)) {
                $values[$column] = trim((string) $dto[$key]) === '' ? null : trim((string) $dto[$key]);
            }
        }
        if (isset($values['country_code'])) {
            $values['country_code'] = strtoupper($values['country_code']);
        }
        if (! $current) {
            $values += ['sequence' => (int) FiscalPosition::max('sequence') + 10];
        }

        return $values;
    }

    /**
     * The replacements of a position, as a whole: "code `from` becomes code `to`", both of the same use.
     *
     * @return array<int, array{0:string, 1:?string}> the lines as stored
     */
    private function replaceLines(FiscalPosition $p, array $lines): array
    {
        $codes = TaxCode::whereIn('id', collect($lines)->flatMap(fn ($l) => [$l['fromCodeId'] ?? null, $l['toCodeId'] ?? null])->filter()->unique()->all())->get()->keyBy('id');
        $pairs = [];
        foreach (array_values($lines) as $i => $l) {
            $from = $codes->get($l['fromCodeId'] ?? '');
            $to = $codes->get($l['toCodeId'] ?? '');
            if (! $from || ! $to) {
                throw AppError::validation('TAX_POSITION_LINE', 'سطر الاستبدال يحتاج رمزين موجودين', 'A replacement needs two existing codes', [['path' => "lines.{$i}", 'message' => 'رمز غير موجود']]);
            }
            if ($from->use !== $to->use || $from->id === $to->id || $from->kind === 'withholding' || $to->kind === 'withholding') {
                throw AppError::validation('TAX_POSITION_LINE', 'الرمزان من الاستخدام نفسه (مبيعات أو مشتريات) ومختلفان', 'Both codes are of the same use (sales or purchases) and different', [['path' => "lines.{$i}", 'message' => 'رمزان لا يتوافقان']]);
            }
            if (isset($pairs[$from->id])) {
                throw AppError::validation('TAX_POSITION_LINE', "الرمز {$from->code} مكرر في الاستبدالات", "The code {$from->code} is replaced twice", [['path' => "lines.{$i}", 'message' => 'مكرر']]);
            }
            $pairs[$from->id] = $to->id;
        }
        $current = $p->lines()->get()->keyBy('from_code_id');
        foreach ($current as $fromId => $line) {
            if (! isset($pairs[$fromId])) {
                $line->delete(); // a line is part of the position's definition (the position itself is archived, never deleted)
            }
        }
        foreach ($pairs as $fromId => $toId) {
            if ($line = $current->get($fromId)) {
                if ($line->to_code_id !== $toId) {
                    $line->update(['to_code_id' => $toId]);
                }
            } else {
                FiscalPositionLine::create(['position_id' => $p->id, 'from_code_id' => $fromId, 'to_code_id' => $toId]);
            }
        }

        return $p->lines()->orderBy('created_at')->orderBy('id')->get(['from_code_id', 'to_code_id'])->map(fn ($l) => [$l->from_code_id, $l->to_code_id])->all();
    }

    // ───────────── shared ─────────────

    /** nameAr / nameEn of a payload as columns: the Arabic name is never emptied, an emptied English name follows it. */
    private function names(array $dto, string $code, ?Model $row): array
    {
        $values = [];
        if (array_key_exists('nameAr', $dto)) {
            $values['name_ar'] = trim((string) $dto['nameAr']);
            if ($values['name_ar'] === '') {
                throw AppError::validation($code, 'الاسم إلزامي', 'The name is required', [['path' => 'nameAr', 'message' => 'هذا الحقل إلزامي']]);
            }
        }
        if (array_key_exists('nameEn', $dto)) {
            $values['name_en'] = trim((string) $dto['nameEn']) ?: ($values['name_ar'] ?? $row?->getAttribute('name_ar'));
        }

        return $values;
    }

    /** Writes what really changed, audits it (old → new). @return int how many columns changed */
    private function write(AuthUser $user, Model $row, array $values, string $action, string $type, string $label): int
    {
        $old = $new = [];
        foreach ($values as $column => $value) {
            $current = $row->getAttribute($column);
            $same = is_bool($value) || is_bool($current) ? (bool) $current === (bool) $value
                : (in_array($column, ['rate', 'sequence', 'return_box'], true) && is_numeric($value) && is_numeric($current) ? bccomp(Money::rate($value), Money::rate($current), 4) === 0
                    : (string) ($current ?? '') === (string) ($value ?? ''));
            if (! $same) {
                $old[$column] = $current;
                $new[$column] = $value;
            }
        }
        if ($new) {
            $row->update($new + ($row instanceof TaxCode ? ['updated_by' => $user->username] : []));
            $this->audit->log($user, ['action' => $action, 'entityType' => $type, 'entityId' => $row->getKey(), 'entityNumber' => $label, 'oldValue' => $old, 'newValue' => $new]);
        }

        return count($new);
    }
}
