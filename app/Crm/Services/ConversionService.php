<?php

namespace App\Crm\Services;

use App\Crm\Models\CrmLead;
use App\Crm\Support\Owned;
use App\Crm\Support\People;
use App\Crm\Support\Shape;
use App\Crm\Support\Timeline;
use App\Integration\Odoo\Models\OdooRecord;
use App\Integration\Odoo\Projection\OdooProjection;
use App\Integration\Odoo\Projection\Val;
use App\Integration\Services\ExternalRefs;
use App\Models\Customer;
use App\Models\Quotation;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Master\PartnersService;
use App\Services\Sales\SalesService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Sql;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * From a won opportunity to an OPS customer, and from an opportunity to a quotation.
 *
 * NEVER A DUPLICATE CUSTOMER. Who the customer is, is decided by identity, in this order:
 *   1. a link that exists — the customer the lead already carries, or the OPS customer its Odoo partner is linked to;
 *   2. the VAT number — the OPS customer that carries the same number IS that company;
 *   3. otherwise a person says so: "it is this existing customer" (`customerId`) or "it is new" (`confirmNew`).
 * A similar name is only ever shown to that person as a candidate; it never links anything by itself. A new customer
 * is made by the customers' own service (`PartnersService::createCustomer`) and needs `customer.manage`.
 *
 * QUOTATIONS are documents of the sales module: one is made through `SalesService::createQuotation` (it needs
 * `sales.manage` and follows every sales rule — discount limit, validity), or an existing one of the same customer
 * is linked. The lead only remembers which quotations answer it.
 *
 * Both are OPS's own on every lead: a won opportunity that came from Odoo is converted here too.
 */
class ConversionService
{
    public function __construct(
        private readonly LeadsService $leads,
        private readonly PartnersService $partners,
        private readonly SalesService $sales,
        private readonly ExternalRefs $refs,
        private readonly AuditService $audit,
        private readonly NotifyService $notify,
    ) {}

    // ───────────── customer ─────────────

    /**
     * What a conversion would do, for the person about to do it: the customer identity already names, or the
     * candidates to choose from.
     */
    public function preview(string $idOrNumber, ?string $vatNo = null): array
    {
        $l = $this->leads->find($idOrNumber);
        $vat = LeadsService::digits($vatNo) ?? $l->vat_no; // the number the person is typing in the dialog, else the lead's own
        $identity = $this->identity($l, $vat);
        $refusal = $this->refusal($l);

        return [
            'lead' => ['id' => $l->id, 'number' => $l->number, 'name' => $l->name, 'status' => $l->status],
            'current' => Shape::customer($l->customer),
            // identity names the customer: nothing to decide
            'match' => $identity ? ['by' => $identity[1], 'customer' => Shape::customer($identity[0])] : null,
            // similar customers a person may recognise (never linked automatically)
            'candidates' => $identity ? [] : $this->candidates($l, $vat),
            'vatNo' => $vat,
            // what a new customer would start from
            'suggested' => [
                'nameAr' => $l->company_name ?: ($l->contact_name ?: $l->name), 'nameEn' => null, 'city' => $l->city, 'zone' => $l->city,
                'contact' => trim(implode(' · ', array_filter([$l->contact_name, $l->mobile ?: $l->phone]))) ?: null,
                'address' => trim(implode(' ', array_filter([$l->street, $l->street2]))) ?: null, 'vatNo' => $l->vat_no,
            ],
            'allowed' => $refusal === null, 'reasonAr' => $refusal?->messageAr, 'reasonEn' => $refusal?->messageEn, 'code' => $refusal?->errorCode,
        ];
    }

    /**
     * @param  array{customerId?:?string, confirmNew?:bool, vatNo?:?string, cr?:?string, nameAr?:?string, nameEn?:?string, city?:?string, zone?:?string, contact?:?string,
     *               address?:?string, terms?:?string, creditLimit?:float|int|string|null, priceList?:?string}  $dto
     */
    public function convert(AuthUser $user, string $idOrNumber, array $dto): array
    {
        // decided before the transaction: a refused permission is written to the audit trail and must stay there
        $probe = $this->leads->find($idOrNumber, []);
        $vat = LeadsService::digits($dto['vatNo'] ?? null) ?? $probe->vat_no;
        if (! $probe->customer_id && ! empty($dto['confirmNew']) && empty($dto['customerId']) && ! $this->identity($probe, $vat)) {
            People::need($user, 'customer.manage', 'إنشاء عميل جديد', 'creating a new customer');
        }

        $outcome = DB::transaction(function () use ($user, $idOrNumber, $dto, $vat) {
            $l = $this->leads->locked($idOrNumber);
            if ($l->customer_id) {
                return ['id' => $l->id, 'by' => 'already', 'customer' => Customer::find($l->customer_id)]; // done before: nothing to do, nothing to duplicate
            }
            if ($refusal = $this->refusal($l)) {
                throw $refusal;
            }
            $chosen = ! empty($dto['customerId'])
                ? (Customer::where('id', $dto['customerId'])->orWhere('code', $dto['customerId'])->first() ?? throw AppError::notFound('CUSTOMER_NOT_FOUND', 'العميل غير موجود', 'Customer not found'))
                : null;
            $identity = $this->identity($l, $vat);
            if ($identity) {
                [$customer, $by] = $identity;
                if ($chosen && $chosen->id !== $customer->id) {
                    $how = $by === 'vat' ? 'الرقم الضريبي' : 'ارتباط أودو';
                    throw AppError::rule('CRM_CUSTOMER_MISMATCH', "{$how} يدل على العميل {$customer->name_ar} ({$customer->code}) لا على {$chosen->code} — اربط الفرصة به", "Identity ({$by}) names customer {$customer->code}, not {$chosen->code}",
                        ['by' => $by, 'customer' => Shape::customer($customer)]);
                }
            } elseif ($chosen) {
                [$customer, $by] = [$chosen, 'person'];
            } elseif (! empty($dto['confirmNew'])) {
                [$customer, $by] = [$this->newCustomer($user, $l, $dto, $vat), 'new'];
            } else {
                throw AppError::rule('CRM_CUSTOMER_CONFIRM', 'لم يُعرف العميل برابط ولا برقم ضريبي — حدّد العميل الموجود الذي تخصه هذه الفرصة، أو أكّد أنه عميل جديد',
                    'No link and no VAT number name the customer — say which existing customer this is, or confirm that it is new', ['candidates' => $this->candidates($l, $vat)]);
            }
            if (! $customer->active && $by !== 'new') {
                throw AppError::rule('CUSTOMER_INACTIVE', "العميل {$customer->name_ar} ({$customer->code}) موقوف", "Customer {$customer->code} is inactive");
            }
            // the VAT number a person typed is kept on the lead — unless the lead is Odoo's: its values are written there, not here
            $l->update(['customer_id' => $customer->id, 'converted_at' => now(), 'converted_by' => $user->username, 'updated_by' => $user->username] + (Owned::bySource($l) ? [] : ['vat_no' => $l->vat_no ?: $vat]));
            Timeline::add($l, 'converted', $user, ['data' => ['by' => $by, 'customerId' => $customer->id, 'customerCode' => $customer->code, 'customerNameAr' => $customer->name_ar, 'customerNameEn' => $customer->name_en]]);
            $this->audit->log($user, ['action' => 'LEAD.CONVERT', 'entityType' => 'CrmLead', 'entityId' => $l->id, 'entityNumber' => $l->number, 'newValue' => ['customer' => $customer->code, 'by' => $by]]);
            $what = $by === 'new' ? 'أُنشئ العميل' : 'رُبطت بالعميل';
            $this->notify->activity($user, 'CrmLead', $l->id, $l->number, "الفرصة {$l->number}: {$what} {$customer->name_ar} ({$customer->code})", "Opportunity {$l->number}: ".($by === 'new' ? 'customer created' : 'linked to customer')." {$customer->code}");

            return ['id' => $l->id, 'by' => $by, 'customer' => $customer];
        });

        return $this->leads->get($outcome['id']) + ['conversion' => ['by' => $outcome['by'], 'customer' => Shape::customer($outcome['customer'])]];
    }

    /** A conversion is for a won opportunity. */
    private function refusal(CrmLead $l): ?AppError
    {
        return $l->status === 'won' ? null
            : AppError::rule('CRM_NOT_WON', "تتحول الفرصة إلى عميل بعد كسبها — الفرصة {$l->number} ".['open' => 'ما زالت مفتوحة', 'lost' => 'خاسرة'][$l->status], "An opportunity becomes a customer once it is won — {$l->number} is {$l->status}", ['status' => $l->status]);
    }

    /**
     * The customer identity names, and how: `link` (the OPS customer its Odoo partner is linked to) or `vat`.
     *
     * @return array{0:Customer, 1:string}|null
     */
    private function identity(CrmLead $l, ?string $vat): ?array
    {
        foreach ($l->partner_ref ? $this->partnerChain((string) $l->partner_ref) : [] as $ref) {
            if (($id = $this->refs->internalId(OdooProjection::SYSTEM, 'customer', $ref)) && ($c = Customer::find($id))) {
                return [$c, 'link'];
            }
        }
        // one customer carries the number: it is that company. Several (branches registered apart) is a person's choice.
        $same = $this->sameVat($vat ?? $l->vat_no);

        return count($same) === 1 ? [$same[0], 'vat'] : null;
    }

    /** The Odoo partner of the lead and — when it is a contact of a company — that company: the customer is the company. @return string[] */
    private function partnerChain(string $ref): array
    {
        $chain = [$ref];
        if (ctype_digit($ref)) {
            $data = OdooRecord::where('model', 'res.partner')->where('odoo_id', (int) $ref)->value('data');
            if ($parent = Val::id($data['parent_id'] ?? null)) {
                $chain[] = (string) $parent;
            }
        }

        return $chain;
    }

    /** OPS customers that carry this VAT number, whatever spaces or dashes it was typed with. @return Customer[] */
    private function sameVat(?string $vat): array
    {
        if (! $vat) {
            return [];
        }
        $ids = Customer::whereNotNull('vat_no')->toBase()->get(['id', 'vat_no'])->filter(fn ($c) => LeadsService::digits($c->vat_no) === $vat)->pluck('id')->all();

        return $ids ? Customer::whereIn('id', $ids)->orderBy('created_at')->orderBy('id')->get()->all() : [];
    }

    /**
     * Customers a person may recognise as this lead: the same VAT number (when several carry it), the same name, or the
     * same phone in their contact line. At most eight, the surest first. Shown, never acted upon.
     *
     * @return array<int, array<string, mixed>>
     */
    private function candidates(CrmLead $l, ?string $vat = null): array
    {
        // the company is who the customer would be; a lead with no company named is a person's own business
        $names = array_values(array_unique(array_filter(array_map(fn ($n) => trim((string) $n), $l->company_name ? [$l->company_name] : [$l->contact_name, $l->name]), fn ($n) => mb_strlen($n) >= 3)));
        $phones = array_values(array_unique(array_filter(array_map(fn ($p) => substr((string) LeadsService::digits($p), -9), [$l->phone, $l->mobile]), fn ($p) => strlen($p) >= 7)));
        $byVat = collect($this->sameVat($vat ?? $l->vat_no));
        $found = collect();
        if ($names || $phones) {
            $op = Sql::like();
            $found = Customer::query()->where(function ($w) use ($names, $phones, $op) {
                foreach ($names as $n) {
                    $like = '%'.addcslashes($n, '\\%_').'%';
                    $w->orWhere('name_ar', $op, $like)->orWhere('name_en', $op, $like);
                }
                foreach ($phones as $p) {
                    $w->orWhere('contact', 'like', '%'.$p.'%');
                }
            })->orderBy('code')->limit(40)->get();
        }
        $key = fn (?string $s) => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $s)));
        $exact = array_map($key, $names);
        $vatIds = $byVat->pluck('id')->all();
        $rank = ['sameVat' => 0, 'sameName' => 1, 'samePhone' => 2, 'similarName' => 3];

        return $byVat->concat($found)->unique('id')->map(function (Customer $c) use ($key, $exact, $phones, $vatIds) {
            $why = in_array($c->id, $vatIds, true) ? ['sameVat'] : [];
            if (in_array($key($c->name_ar), $exact, true) || in_array($key($c->name_en), $exact, true)) {
                $why[] = 'sameName';
            }
            foreach ($phones as $p) {
                if (str_contains((string) LeadsService::digits($c->contact), $p)) {
                    $why[] = 'samePhone';
                    break;
                }
            }

            return Shape::customer($c) + ['contact' => $c->contact, 'zone' => $c->zone, 'why' => $why ?: ['similarName']];
        })->sortBy(fn (array $c) => $rank[$c['why'][0]])->take(8)->values()->all();
    }

    private function newCustomer(AuthUser $user, CrmLead $l, array $dto, ?string $vat): Customer
    {
        $name = trim((string) ($dto['nameAr'] ?? '')) ?: ($l->company_name ?: ($l->contact_name ?: $l->name));
        $city = trim((string) ($dto['city'] ?? '')) ?: $l->city;
        $created = $this->partners->createCustomer($user, [
            'nameAr' => $name, 'nameEn' => trim((string) ($dto['nameEn'] ?? '')) ?: null, 'city' => $city, 'zone' => trim((string) ($dto['zone'] ?? '')) ?: ($city ?: 'غير محدد'),
            'contact' => trim((string) ($dto['contact'] ?? '')) ?: (trim(implode(' · ', array_filter([$l->contact_name, $l->mobile ?: $l->phone]))) ?: '—'),
            'address' => trim((string) ($dto['address'] ?? '')) ?: (trim(implode(' ', array_filter([$l->street, $l->street2]))) ?: null),
            'terms' => ($dto['terms'] ?? null) ?: null, 'creditLimit' => (float) ($dto['creditLimit'] ?? 0), 'priceList' => ($dto['priceList'] ?? null) ?: null,
        ]);
        // the identity the next conversion will recognise this customer by (the customers' form does not store them)
        $identity = array_filter(['vat_no' => $vat, 'cr' => trim((string) ($dto['cr'] ?? '')) ?: null]);
        if ($identity) {
            Customer::whereKey($created['id'])->update($identity);
        }

        return Customer::findOrFail($created['id']);
    }

    // ───────────── quotations ─────────────

    /**
     * A quotation for the lead: `number` links an existing one of the lead's customer; otherwise `validUntil` + `lines`
     * make a new draft through the sales module.
     *
     * @param  array{number?:?string, validUntil?:?string, terms?:?string, delivery?:?string, notes?:?string, lines?:array<int, array>}  $dto
     */
    public function quote(AuthUser $user, string $idOrNumber, array $dto): array
    {
        $linking = ! empty($dto['number']);
        if (! $linking) {
            People::need($user, 'sales.manage', 'إنشاء عرض سعر', 'creating a quotation'); // before the transaction, see convert()
        }
        $id = DB::transaction(function () use ($user, $idOrNumber, $dto, $linking) {
            $l = $this->leads->locked($idOrNumber);
            $customer = $l->customer_id ? Customer::find($l->customer_id) : null;
            if (! $customer) {
                throw AppError::rule('CRM_NO_CUSTOMER', 'عرض السعر يصدر لعميل — اربط الفرصة بعميلها أو حوّلها إلى عميل بعد كسبها', 'A quotation is made for a customer — link the opportunity to its customer, or convert it once it is won');
            }
            if ($l->status === 'lost') {
                throw AppError::rule('CRM_LEAD_LOST', "الفرصة {$l->number} خاسرة — أعد فتحها قبل إصدار عرض سعر", "Opportunity {$l->number} is lost — reopen it before quoting");
            }
            if ($linking) {
                $q = Quotation::where('number', $dto['number'])->orWhere('id', $dto['number'])->first() ?? throw AppError::notFound('QT_NOT_FOUND', 'عرض السعر غير موجود', 'Quotation not found');
                if ($q->customer_id !== $customer->id) {
                    throw AppError::rule('CRM_QUOTATION_CUSTOMER', "عرض السعر {$q->number} لعميل آخر غير عميل الفرصة ({$customer->code})", "Quotation {$q->number} belongs to another customer than the opportunity's ({$customer->code})");
                }
                $held = DB::table('crm_lead_quotations')->where('quotation_id', $q->id)->value('lead_id');
                if ($held === $l->id) {
                    return $l->id; // linked already
                }
                if ($held) {
                    $other = CrmLead::whereKey($held)->value('number');
                    throw AppError::rule('CRM_QUOTATION_LINKED', "عرض السعر {$q->number} مرتبط بالفرصة {$other}", "Quotation {$q->number} is linked to opportunity {$other}", ['lead' => $other]);
                }
                $number = $q->number;
                $quotationId = $q->id;
            } else {
                $made = $this->sales->createQuotation($user, [
                    'customerCode' => $customer->code, 'validUntil' => $dto['validUntil'], 'terms' => $dto['terms'] ?? null, 'delivery' => $dto['delivery'] ?? null,
                    'notes' => trim((string) ($dto['notes'] ?? '')) ?: "الفرصة {$l->number} — {$l->name}", 'action' => 'draft', 'lines' => $dto['lines'],
                ]);
                $number = $made['number'];
                $quotationId = $made['id'];
            }
            DB::table('crm_lead_quotations')->insert(['id' => (string) Str::ulid(), 'lead_id' => $l->id, 'quotation_id' => $quotationId, 'created_by' => $user->username, 'created_at' => now()]);
            Timeline::add($l, 'quotation', $user, ['data' => ['number' => $number, 'action' => $linking ? 'linked' : 'created']]);
            $this->audit->log($user, ['action' => 'LEAD.QUOTATION', 'entityType' => 'CrmLead', 'entityId' => $l->id, 'entityNumber' => $l->number, 'newValue' => ['quotation' => $number, 'action' => $linking ? 'linked' : 'created']]);

            return $l->id;
        });

        return $this->leads->get($id);
    }

    /** Takes a quotation off the lead (a wrong link). The quotation itself is the sales module's and is not touched. */
    public function unquote(AuthUser $user, string $idOrNumber, string $quotation): array
    {
        $id = DB::transaction(function () use ($user, $idOrNumber, $quotation) {
            $l = $this->leads->locked($idOrNumber);
            $q = Quotation::where('number', $quotation)->orWhere('id', $quotation)->first() ?? throw AppError::notFound('QT_NOT_FOUND', 'عرض السعر غير موجود', 'Quotation not found');
            if (! DB::table('crm_lead_quotations')->where('lead_id', $l->id)->where('quotation_id', $q->id)->delete()) {
                throw AppError::rule('CRM_QUOTATION_NOT_LINKED', "عرض السعر {$q->number} غير مرتبط بهذه الفرصة", "Quotation {$q->number} is not linked to this opportunity");
            }
            Timeline::add($l, 'quotation', $user, ['data' => ['number' => $q->number, 'action' => 'unlinked']]);
            $this->audit->log($user, ['action' => 'LEAD.QUOTATION', 'entityType' => 'CrmLead', 'entityId' => $l->id, 'entityNumber' => $l->number, 'oldValue' => ['quotation' => $q->number], 'newValue' => ['action' => 'unlinked']]);

            return $l->id;
        });

        return $this->leads->get($id);
    }
}
