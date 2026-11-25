<?php

namespace App\Portal\Services;

use App\Integration\Services\ExternalRefs;
use App\Models\Customer;
use App\Portal\Models\Application;
use App\Portal\Support\Access;
use App\Portal\Support\Owned;
use App\Portal\Support\Parties;
use App\Portal\Support\PortalLog;
use App\Portal\Support\Text;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Master\PartnersService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Sql;
use Illuminate\Support\Facades\DB;

/**
 * Approving an onboarding application: the company becomes an OPS customer.
 *
 * NEVER A DUPLICATE CUSTOMER. Who the company is in OPS is decided by identity, in this order:
 *   1. a link that exists — the customer the application already carries, or the OPS customer the partner Odoo made
 *      of it is linked to (`int_external_refs`, system `odoo`, entity `customer`);
 *   2. its numbers — the ONE OPS customer that carries the same VAT number or the same commercial registration IS that
 *      company (when the two numbers name two different customers nothing is assumed: a person says which);
 *   3. otherwise a person says so: "it is this existing customer" (`customerId`) or "it is new" (`confirmNew`).
 * A similar name or the same phone is only ever shown to that person as a candidate; it never links anything by
 * itself. A new customer is made by the customers' own service (`PartnersService::createCustomer`) — which needs
 * `customer.manage` besides `portal.manage` — and gets the application's VAT and registration numbers, the identity
 * the next application of the same company will be recognised by. Two approvals of the same company at the same
 * moment wait for each other, and the second one finds the customer the first one made.
 */
class OnboardingApproval
{
    public function __construct(
        private readonly PartnersService $partners,
        private readonly ExternalRefs $refs,
        private readonly AuditService $audit,
        private readonly NotifyService $notify,
    ) {}

    /**
     * What an approval would do, for the person about to do it: the customer identity already names, the two that the
     * numbers disagree on, or the candidates to choose from.
     */
    public function preview(Application $a): array
    {
        [$named, $by, $conflict] = $this->identity($a);

        return [
            'by' => $by, 'customer' => self::shape($named),
            'conflict' => array_map(fn (Customer $c) => self::shape($c), $conflict),
            // similar customers a person may recognise (never linked automatically)
            'candidates' => $named ? [] : $this->candidates($a),
            // what the reviewer has to say: nothing — identity names the customer; which of two; or existing / new
            'needs' => $named ? 'nothing' : ($conflict ? 'conflict' : 'choice'),
            // what a new customer would start from
            'suggested' => $this->suggested($a),
        ];
    }

    /**
     * @param  array{customerId?:?string, confirmNew?:bool, zone?:?string, contact?:?string, address?:?string, terms?:?string, creditLimit?:float|int|string|null, priceList?:?string, note?:?string}  $dto
     * @return array{id:string, by:string, customer:array}
     */
    public function approve(AuthUser $user, string $id, array $dto): array
    {
        // decided before the transaction: a refused permission is written to the audit trail and must stay there
        $probe = Application::where(fn ($w) => $w->where('id', $id)->orWhere('number', $id))->first()
            ?? throw AppError::notFound('PORTAL_APPLICATION_NOT_FOUND', 'طلب فتح الحساب غير موجود', 'Application not found');
        if (! empty($dto['confirmNew']) && empty($dto['customerId']) && ! $this->identity($probe)[0]) {
            Access::need($user, 'customer.manage', 'إنشاء عميل جديد من طلب فتح حساب', 'creating a new customer from an application');
        }

        return DB::transaction(function () use ($user, $id, $dto) {
            $a = ApplicationsService::locked($id);
            Owned::refuse($a, 'application');
            $from = ApplicationsService::move($a, 'approved');
            $missing = array_values(array_filter(ApplicationsService::checks($a, []), fn (array $c) => $c['blocking'] && ! $c['ok']));
            if ($missing) {
                throw AppError::rule('PORTAL_APPLICATION_INCOMPLETE', 'الطلب غير مكتمل: '.implode('، ', array_column($missing, 'ar')), 'The application is incomplete: '.implode(', ', array_column($missing, 'en')),
                    array_map(fn (array $c) => ['path' => $c['key'], 'message' => $c['ar']], $missing));
            }
            $chosen = ! empty($dto['customerId']) ? Parties::customer($dto['customerId']) : null;
            [$named, $by, $conflict] = $this->identity($a);
            if ($named) {
                if ($chosen && $chosen->id !== $named->id) {
                    $how = ['link' => 'الارتباط القائم', 'vat' => 'الرقم الضريبي', 'cr' => 'السجل التجاري'][$by];
                    throw AppError::rule('PORTAL_CUSTOMER_MISMATCH', "{$how} يدل على العميل {$named->name_ar} ({$named->code}) لا على {$chosen->code} — يُربط الطلب به", "Identity ({$by}) names customer {$named->code}, not {$chosen->code}",
                        ['by' => $by, 'customer' => self::shape($named)]);
                }
                $customer = $named;
            } elseif ($chosen) {
                [$customer, $by] = [$chosen, 'person'];
            } elseif ($conflict) {
                throw AppError::rule('PORTAL_IDENTITY_CONFLICT', 'الرقم الضريبي والسجل التجاري يدلان على عميلين مختلفين — حدّد أيهما صاحب هذا الطلب', 'The VAT number and the commercial registration name two different customers — say which one this application belongs to',
                    ['candidates' => array_map(fn (Customer $c) => self::shape($c), $conflict)]);
            } elseif (! empty($dto['confirmNew'])) {
                // one at a time, then looked for once more: the same company approved a moment ago is found, not made twice
                DB::table('number_sequences')->where('key', 'CUS')->lockForUpdate()->first();
                $twin = $this->justMade($a);
                [$customer, $by] = $twin ? [$twin, $twin->vat_no && $twin->vat_no === $a->vat_no ? 'vat' : 'cr'] : [$this->newCustomer($user, $a, $dto), 'new'];
            } else {
                throw AppError::rule('PORTAL_CUSTOMER_CONFIRM', 'لم تُعرف المنشأة برابط ولا برقم ضريبي أو سجل تجاري — حدّد العميل الموجود الذي يخصه الطلب، أو أكّد أنه عميل جديد',
                    'No link and no number name the company — say which existing customer this is, or confirm that it is new', ['candidates' => $this->candidates($a)]);
            }
            if (! $customer->active && $by !== 'new') {
                throw AppError::rule('CUSTOMER_INACTIVE', "العميل {$customer->name_ar} ({$customer->code}) موقوف — يُفعَّل أولًا من صفحة العملاء", "Customer {$customer->code} is inactive — activate it first");
            }
            $a->update(['status' => 'approved', 'customer_id' => $customer->id, 'customer_by' => $by, 'decided_at' => now(), 'decided_by' => $user->username, 'approved_at' => now(),
                'rejection_reason' => null, 'updated_by' => $user->username]);
            $facts = ['by' => $by, 'customerId' => $customer->id, 'customerCode' => $customer->code, 'customerNameAr' => $customer->name_ar, 'customerNameEn' => $customer->name_en];
            PortalLog::add('application', $a->id, 'approved', $user, ['note' => $dto['note'] ?? null, 'data' => $facts]);
            $this->audit->status($user, 'PortalApplication', $a->id, $a->number, $from, 'approved', Text::clean($dto['note'] ?? null, 500));
            $this->audit->log($user, ['action' => 'PORTAL.APPLICATION.APPROVE', 'entityType' => 'PortalApplication', 'entityId' => $a->id, 'entityNumber' => $a->number, 'newValue' => ['customer' => $customer->code, 'by' => $by]]);
            $what = $by === 'new' ? 'وأُنشئ العميل' : 'ورُبط بالعميل';
            $this->notify->activity($user, 'PortalApplication', $a->id, $a->number, "اعتُمد طلب فتح الحساب {$a->number} {$what} {$customer->name_ar} ({$customer->code})",
                "Application {$a->number} approved — ".($by === 'new' ? 'customer created' : 'linked to customer')." {$customer->code}");

            return ['id' => $a->id, 'by' => $by, 'customer' => self::shape($customer)];
        });
    }

    /**
     * The customer identity names, and how: `link`, `vat` or `cr` — or the two customers the numbers disagree on.
     *
     * @return array{0:?Customer, 1:?string, 2:Customer[]}
     */
    private function identity(Application $a): array
    {
        if ($a->customer_id && ($c = Customer::find($a->customer_id))) {
            return [$c, 'link', []];
        }
        if ($a->customer_ref && ($id = $this->refs->internalId(Owned::SYSTEM, 'customer', (string) $a->customer_ref)) && ($c = Customer::find($id))) {
            return [$c, 'link', []];
        }
        // one customer carries the number: it is that company. Several (branches registered apart) is a person's choice.
        $byVat = $this->sameNumber('vat_no', $a->vat_no);
        $byCr = $this->sameNumber('cr', $a->cr);
        $vat = count($byVat) === 1 ? $byVat[0] : null;
        $cr = count($byCr) === 1 ? $byCr[0] : null;
        if ($vat && $cr && $vat->id !== $cr->id) {
            return [null, null, [$vat, $cr]];
        }

        return $vat ? [$vat, 'vat', []] : ($cr ? [$cr, 'cr', []] : [null, null, []]);
    }

    /** OPS customers that carry this number, whatever spaces or dashes it was typed with. @return Customer[] */
    private function sameNumber(string $column, ?string $digits): array
    {
        if (! $digits) {
            return [];
        }
        $ids = Customer::whereNotNull($column)->toBase()->get(['id', $column])->filter(fn ($c) => Text::digits($c->{$column}) === $digits)->pluck('id')->all();

        return $ids ? Customer::whereIn('id', $ids)->orderBy('created_at')->orderBy('id')->get()->all() : [];
    }

    /**
     * The customer another approval made of the same company a moment ago: read with a lock, so what was committed
     * since this transaction started is seen.
     */
    private function justMade(Application $a): ?Customer
    {
        $numbers = array_filter(['vat_no' => $a->vat_no, 'cr' => $a->cr]);
        if (! $numbers) {
            return null;
        }

        return Customer::query()->where(function ($w) use ($numbers) {
            foreach ($numbers as $column => $value) {
                $w->orWhere($column, $value);
            }
        })->orderBy('created_at')->orderBy('id')->lockForUpdate()->first();
    }

    /**
     * Customers a person may recognise as this company: the same number (when several carry it), the same name, or
     * the same phone in their contact line. At most eight, the surest first. Shown, never acted upon.
     *
     * @return array<int, array<string, mixed>>
     */
    private function candidates(Application $a): array
    {
        $names = array_values(array_unique(array_filter(array_map(fn ($n) => trim((string) $n), [$a->company_name_ar, $a->company_name_en]), fn ($n) => mb_strlen($n) >= 3)));
        $phone = substr((string) Text::digits($a->mobile), -9);
        $byNumber = collect($this->sameNumber('vat_no', $a->vat_no))->concat($this->sameNumber('cr', $a->cr));
        $found = collect();
        if ($names || strlen($phone) >= 7) {
            $op = Sql::like();
            $found = Customer::query()->where(function ($w) use ($names, $phone, $op) {
                foreach ($names as $n) {
                    $like = Text::like($n);
                    $w->orWhere('name_ar', $op, $like)->orWhere('name_en', $op, $like);
                }
                if (strlen($phone) >= 7) {
                    $w->orWhere('contact', 'like', '%'.$phone.'%');
                }
            })->orderBy('code')->limit(40)->get();
        }
        $exact = array_map(fn (string $n) => Text::nameKey($n), $names);
        $numberIds = $byNumber->pluck('id')->all();
        $rank = ['sameNumber' => 0, 'sameName' => 1, 'samePhone' => 2, 'similarName' => 3];

        return $byNumber->concat($found)->unique('id')->map(function (Customer $c) use ($exact, $phone, $numberIds) {
            $why = in_array($c->id, $numberIds, true) ? ['sameNumber'] : [];
            if (in_array(Text::nameKey($c->name_ar), $exact, true) || in_array(Text::nameKey($c->name_en), $exact, true)) {
                $why[] = 'sameName';
            }
            if (strlen($phone) >= 7 && str_contains((string) Text::digits($c->contact), $phone)) {
                $why[] = 'samePhone';
            }

            return self::shape($c) + ['contact' => $c->contact, 'zone' => $c->zone, 'why' => $why ?: ['similarName']];
        })->sortBy(fn (array $c) => $rank[$c['why'][0]])->take(8)->values()->all();
    }

    /** @return array<string, ?string> what the form of a new customer starts from */
    private function suggested(Application $a): array
    {
        return [
            'nameAr' => $a->company_name_ar, 'nameEn' => $a->company_name_en, 'city' => $a->city, 'zone' => $a->district ?: $a->city,
            'contact' => trim(implode(' · ', array_filter([$a->manager_name ?: $a->owner_name, $a->mobile]))) ?: null,
            'address' => trim(implode('، ', array_filter([trim(implode(' ', array_filter([$a->building_no, $a->street]))), $a->district, $a->city, $a->postal_code]))) ?: null,
            'vatNo' => $a->vat_no, 'cr' => $a->cr,
        ];
    }

    private function newCustomer(AuthUser $user, Application $a, array $dto): Customer
    {
        $s = $this->suggested($a);
        $created = $this->partners->createCustomer($user, [
            'nameAr' => $s['nameAr'], 'nameEn' => $s['nameEn'], 'city' => $s['city'], 'zone' => Text::clean($dto['zone'] ?? null, 190) ?? ($s['zone'] ?: 'غير محدد'),
            'contact' => Text::clean($dto['contact'] ?? null, 250) ?? ($s['contact'] ?: '—'), 'address' => Text::clean($dto['address'] ?? null, 500) ?? $s['address'],
            'terms' => ($dto['terms'] ?? null) ?: null, 'creditLimit' => (float) ($dto['creditLimit'] ?? 0), 'priceList' => ($dto['priceList'] ?? null) ?: null,
        ]);
        // the identity the next application of this company will be recognised by (the customers' form does not store them)
        $identity = array_filter(['vat_no' => $a->vat_no, 'cr' => $a->cr]);
        if ($identity) {
            Customer::whereKey($created['id'])->update($identity);
        }

        return Customer::findOrFail($created['id']);
    }

    /** @return array<string, mixed>|null */
    public static function shape(?Customer $c): ?array
    {
        return $c ? ['id' => $c->id, 'code' => $c->code, 'nameAr' => $c->name_ar, 'nameEn' => $c->name_en, 'city' => $c->city, 'vatNo' => $c->vat_no, 'cr' => $c->cr, 'active' => (bool) $c->active] : null;
    }
}
