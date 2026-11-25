<?php

namespace App\Tax\Services;

use App\Finance\Models\CustomerInvoice;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Tax\Models\Einvoice;
use App\Tax\Models\EinvoiceAttempt;
use App\Tax\Models\EinvoiceUnit;
use App\Tax\Support\Money;
use App\Tax\Support\Shape;
use App\Tax\Zatca\Certificate;
use App\Tax\Zatca\Codes;
use App\Tax\Zatca\InvoiceHash;
use App\Tax\Zatca\Qr;
use App\Tax\Zatca\Signer;
use App\Tax\Zatca\Ubl;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The register of e-invoices: one for every OPS customer invoice and credit note.
 *
 * THE CHAIN. A unit numbers its documents with a counter that never skips and never repeats, and every document
 * carries the hash of the one before it (the first carries the authority's fixed value). Both live on the unit's row;
 * a document is made inside one transaction that holds that row locked, so two invoices posted at the same moment get
 * two consecutive counters and the second one's "previous hash" is the first one's hash — whatever the order they
 * arrive in. A document that was rejected, withdrawn or replaced KEEPS its place: the corrected one is a new document
 * with the next counter (the authority tracks the hashes of rejected documents too).
 *
 * NOT ONBOARDED (the default). A unit without a production certificate makes the XML, the counter, the chain and the
 * QR code with its five basic fields, and nothing leaves the building: the documents are `not_sent`. Once the unit is
 * onboarded a document is stamped as it is made and queued for the authority; the ones made before can be stamped and
 * queued by a person — as the same documents, with the same counter and hash.
 */
class EinvoiceService
{
    public function __construct(
        private readonly EinvoiceDocuments $documents,
        private readonly TaxProfileService $profiles,
        private readonly AuditService $audit,
    ) {}

    // ───────────── the unit ─────────────

    /** The unit OPS documents are made by. One always exists: a plain, not-onboarded one is made on first use. */
    public function unit(): EinvoiceUnit
    {
        $unit = EinvoiceUnit::where('is_default', true)->where('status', '!=', 'retired')->orderBy('created_at')->first();
        if (! $unit) {
            // made once, by whoever comes first: the taxpayer row is the lock two first-comers queue on
            $unit = DB::transaction(function () {
                $this->profiles->profile(true);

                return EinvoiceUnit::where('is_default', true)->where('status', '!=', 'retired')->orderBy('created_at')->first()
                    ?? EinvoiceUnit::create(['name' => 'OPS-EGS-1', 'serial' => '1-OPS|2-1|3-'.strtoupper(Str::random(8)), 'environment' => 'sandbox', 'invoice_types' => '1100', 'status' => 'not_onboarded',
                        'is_default' => true, 'sending' => true, 'icv' => 0, 'created_by' => 'system']);
            });
        }

        return $unit;
    }

    // ───────────── making ─────────────

    /**
     * The e-invoice of an OPS invoice, made once (the entry point for the invoice service — see "wiring").
     * Returns null when OPS does not make one for it: before the start date, or the taxpayer profile is not ready.
     * Never throws into the caller's posting: an invoice is issued whether or not its e-invoice could be made.
     */
    public function issueFor(CustomerInvoice $invoice, ?AuthUser $user = null): ?Einvoice
    {
        try {
            $profile = $this->profiles->profile();
            if (! $profile->einvoice_auto || ! $profile->canIssue() || ($profile->einvoice_from && Carbon::parse($invoice->created_at)->lt($profile->einvoice_from->copy()->startOfDay()))) {
                return null;
            }

            return $this->make($invoice, $user);
        } catch (AppError) {
            return null; // it waits in "needs attention" with its reason
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Makes the e-invoice of an OPS customer invoice / credit note — or returns the one it already has.
     * `$replace`: a new document takes the place of the current one (which stays in the chain).
     */
    public function make(CustomerInvoice $invoice, ?AuthUser $user = null, bool $replace = false, ?string $reason = null): Einvoice
    {
        if ($invoice->status === 'cancelled') {
            throw AppError::rule('EINVOICE_SOURCE_CANCELLED', "الفاتورة {$invoice->number} ملغاة — لا تُصدر لها فاتورة إلكترونية", "Invoice {$invoice->number} is cancelled — no e-invoice is made for it");
        }
        $unitId = $this->unit()->id;

        return DB::transaction(function () use ($invoice, $user, $replace, $reason, $unitId) {
            // the lock of the chain: from here to the commit this request alone numbers documents of the unit
            $unit = EinvoiceUnit::whereKey($unitId)->lockForUpdate()->firstOrFail();
            $current = Einvoice::where('doc_type', 'CustomerInvoice')->where('doc_id', $invoice->id)->where('is_current', true)->first();
            if ($current && ! $replace) {
                return $current;
            }
            if ($current && $current->accepted()) {
                throw AppError::rule('EINVOICE_ACCEPTED', "الفاتورة الإلكترونية {$current->doc_number} قبلتها الهيئة — تُصحَّح بإشعار دائن، لا بإصدار جديد", "E-invoice {$current->doc_number} was accepted by the authority — it is corrected with a credit note, not re-made");
            }
            $built = $this->documents->fromInvoice($invoice, $unit);
            $icv = (int) $unit->icv + 1;
            $previous = $unit->last_hash ?: Codes::FIRST_PREVIOUS_HASH;
            $uuid = (string) Str::uuid();
            $xml = Ubl::render($built['document'] + ['icv' => $icv, 'previousHash' => $previous, 'uuid' => $uuid]);
            $hash = InvoiceHash::base64($xml);
            $qr = $built['document']['qr'];
            $signed = false;
            $errors = count(array_filter($built['issues'], fn (array $i) => $i['level'] === 'error'));
            if ($unit->onboarded()) {
                $stamp = Signer::stamp($xml, (string) $unit->private_key, Certificate::fromToken((string) $unit->production_token), $built['qrBasic'], $built['facts']['profile'] === 'simplified');
                [$xml, $qr, $signed] = [$stamp['xml'], $stamp['qr'], true];
            }
            $f = $built['facts'];
            $id = (string) Str::ulid();
            $was = $current?->status;
            if ($current) {
                // the document it replaces keeps its counter and its hash; it is only no longer the invoice's e-invoice
                $current->update(['is_current' => false, 'current_key' => null, 'superseded_by_id' => $id, 'status' => $was === 'rejected' ? 'rejected' : 'withdrawn', 'next_attempt_at' => null]);
            }
            $e = Einvoice::create([
                'id' => $id, 'current_key' => "{$f['docType']}:{$f['docId']}", 'unit_id' => $unit->id, 'icv' => $icv, 'uuid' => $uuid, 'doc_type' => $f['docType'], 'doc_id' => $f['docId'], 'doc_number' => $f['docNumber'], 'doc_kind' => $f['docKind'],
                'type_code' => Codes::TYPE[$f['docKind']], 'transaction_code' => Ubl::transactionCode($f['profile'], $built['document']['flags'] ?? []), 'profile' => $f['profile'], 'issue_at' => $f['issueAt'],
                'customer_id' => $f['customerId'], 'buyer_name' => $f['buyerName'], 'buyer_vat' => $f['buyerVat'], 'net' => Money::text($f['net']), 'vat' => Money::text($f['vat']), 'total' => Money::text($f['total']), 'currency' => 'SAR',
                'hash' => $hash, 'prev_hash' => $previous, 'qr' => $qr, 'xml' => $xml, 'signed' => $signed, 'is_current' => true, 'attempt_no' => $current ? $current->attempt_no + 1 : 1,
                'status' => $signed && $unit->sending && $errors === 0 ? 'queued' : 'not_sent', 'next_attempt_at' => $signed && $unit->sending && $errors === 0 ? now() : null,
                'issues' => $built['issues'] ?: null, 'created_by' => $user?->username ?? 'system',
            ]);
            $unit->update(['icv' => $icv, 'last_hash' => $hash, 'last_einvoice_id' => $e->id]);
            $this->audit->log($user, ['action' => $current ? 'EINVOICE.REMAKE' : 'EINVOICE.MAKE', 'entityType' => 'Einvoice', 'entityId' => $e->id, 'entityNumber' => $e->doc_number,
                'oldValue' => $current ? ['icv' => $current->icv, 'status' => $was] : null,
                'newValue' => ['icv' => $icv, 'uuid' => $uuid, 'hash' => $hash, 'profile' => $f['profile'], 'signed' => $signed, 'status' => $e->status, 'errors' => $errors, 'reason' => $reason]]);

            return $e;
        });
    }

    /** A person asks for the e-invoice of an OPS invoice (by id or number), whatever the start date says. */
    public function makeFor(AuthUser $user, string $invoiceIdOrNumber): array
    {
        $invoice = CustomerInvoice::where('id', $invoiceIdOrNumber)->orWhere('number', $invoiceIdOrNumber)->first()
            ?? throw AppError::notFound('CI_NOT_FOUND', "الفاتورة {$invoiceIdOrNumber} غير موجودة", 'Invoice not found');

        return $this->get($this->make($invoice, $user)->id);
    }

    /** The current document is replaced by a new one made from the invoice as it stands now. */
    public function remake(AuthUser $user, string $id, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw AppError::validation('REASON_REQUIRED', 'اكتب سبب إعادة الإصدار (5 أحرف على الأقل)', 'A reason is required', [['path' => 'reason', 'message' => 'هذا الحقل إلزامي']]);
        }
        $e = $this->find($id);
        if (! $e->is_current) {
            throw AppError::rule('EINVOICE_NOT_CURRENT', 'هذه ليست الفاتورة الإلكترونية السارية للمستند — أعد إصدار السارية', 'This is not the current e-invoice of its document');
        }
        $invoice = CustomerInvoice::find($e->doc_id) ?? throw AppError::notFound('CI_NOT_FOUND', "الفاتورة {$e->doc_number} غير موجودة", 'Invoice not found');

        return $this->get($this->make($invoice, $user, true, $reason)->id);
    }

    /**
     * Makes the e-invoices of the OPS invoices that have none yet, oldest first.
     *
     * @return array{made:int, blocked:int, reasons:array<string,int>}
     */
    public function makePending(?AuthUser $user = null, int $limit = 200): array
    {
        $out = ['made' => 0, 'blocked' => 0, 'reasons' => []];
        $profile = $this->profiles->profile();
        if (! $profile->einvoice_auto) {
            return $out;
        }
        foreach ($this->pendingQuery()->limit($limit)->get() as $invoice) {
            try {
                $this->make($invoice, $user);
                $out['made']++;
            } catch (AppError $e) {
                $out['blocked']++;
                $out['reasons'][$e->errorCode] = ($out['reasons'][$e->errorCode] ?? 0) + 1;
                if ($e->errorCode === 'EINVOICE_SELLER_PROFILE') {
                    break; // nothing can be made until the profile is completed
                }
            }
        }

        return $out;
    }

    /** OPS invoices and credit notes that should have an e-invoice and have none, with the reason each waits. */
    public function pending(Paging $page): array
    {
        $unit = $this->unit();
        $q = $this->pendingQuery();
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('fin_customer_invoices.number', 'like', $like)->orWhereHas('customer', fn ($c) => $c->where('name_ar', 'like', $like)->orWhere('name_en', 'like', $like)->orWhere('code', 'like', $like)));
        }

        return $page->paginate($q->with('customer:id,code,name_ar,name_en'), function (CustomerInvoice $i) use ($unit) {
            $why = null;
            try {
                $this->documents->fromInvoice($i, $unit);
            } catch (AppError $e) {
                $why = ['code' => $e->errorCode, 'ar' => $e->messageAr, 'en' => $e->messageEn];
            }

            return ['id' => $i->id, 'number' => $i->number, 'kind' => $i->kind, 'invoiceDate' => $i->invoice_date?->format('Y-m-d'), 'total' => (string) $i->total, 'vatPct' => Money::rate($i->vat_pct),
                'customer' => $i->customer ? ['id' => $i->customer->id, 'code' => $i->customer->code, 'nameAr' => $i->customer->name_ar, 'nameEn' => $i->customer->name_en] : null,
                'blocked' => $why, 'createdAt' => Shape::at($i->created_at)];
        });
    }

    private function pendingQuery()
    {
        $from = $this->profiles->profile()->einvoice_from;

        return CustomerInvoice::query()->where('fin_customer_invoices.status', '!=', 'cancelled')
            ->when($from, fn ($q) => $q->where('fin_customer_invoices.created_at', '>=', $from->copy()->startOfDay()))
            ->whereNotExists(fn ($s) => $s->from('tax_einvoices')->whereColumn('tax_einvoices.doc_id', 'fin_customer_invoices.id')->where('tax_einvoices.doc_type', 'CustomerInvoice'))
            ->orderBy('fin_customer_invoices.created_at')->orderBy('fin_customer_invoices.id');
    }

    // ───────────── reading ─────────────

    /** @param  array{status?:?string, profile?:?string, docKind?:?string, from?:?string, to?:?string, customer?:?string, unit?:?string, current?:?string, signed?:?string, issues?:?string}  $f */
    public function list(Paging $page, array $f): array
    {
        $q = Einvoice::query()->select(array_map(fn ($c) => "tax_einvoices.{$c}", self::listColumns()));
        if (! empty($f['status'])) {
            $q->whereIn('status', array_map('trim', explode(',', $f['status'])));
        }
        if (in_array($f['profile'] ?? null, ['standard', 'simplified'], true)) {
            $q->where('profile', $f['profile']);
        }
        if (! empty($f['docKind'])) {
            $q->whereIn('doc_kind', array_map('trim', explode(',', $f['docKind'])));
        }
        if (! empty($f['from'])) {
            $q->where('issue_at', '>=', Carbon::parse($f['from'], EinvoiceDocuments::TIMEZONE)->startOfDay()->utc());
        }
        if (! empty($f['to'])) {
            $q->where('issue_at', '<=', Carbon::parse($f['to'], EinvoiceDocuments::TIMEZONE)->endOfDay()->utc());
        }
        if (! empty($f['customer'])) {
            $q->where('customer_id', $f['customer']);
        }
        if (! empty($f['unit'])) {
            $q->where('unit_id', $f['unit']);
        }
        if (($f['current'] ?? '') !== 'all') {
            $q->where('is_current', ($f['current'] ?? '') === '' || $f['current'] === null ? true : filter_var($f['current'], FILTER_VALIDATE_BOOLEAN));
        }
        if (($f['signed'] ?? '') !== '' && ($f['signed'] ?? null) !== null) {
            $q->where('signed', filter_var($f['signed'], FILTER_VALIDATE_BOOLEAN));
        }
        if (($f['issues'] ?? null) === 'yes') {
            $q->whereNotNull('issues');
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('doc_number', 'like', $like)->orWhere('buyer_name', 'like', $like)->orWhere('uuid', 'like', $like)->orWhere('buyer_vat', 'like', $like)
                ->when(ctype_digit($page->q), fn ($x) => $x->orWhere('icv', (int) $page->q)));
        }
        $column = ['icv' => 'icv', 'issueAt' => 'issue_at', 'number' => 'doc_number', 'total' => 'total', 'status' => 'status', 'buyer' => 'buyer_name'][$page->sort ?? ''] ?? 'icv';
        $q->orderBy($column, $page->order)->orderBy('icv', $page->order)->orderBy('id', $page->order);

        return $page->paginate($q, fn (Einvoice $e) => Shape::einvoice($e));
    }

    public function get(string $id): array
    {
        $e = Einvoice::query()->select(array_map(fn ($c) => "tax_einvoices.{$c}", self::listColumns()))->selectRaw('cleared_xml is not null as has_cleared_xml')->with(['unit', 'log' => fn ($q) => $q->orderByDesc('at')->orderByDesc('id')->limit(50)])
            ->where(fn ($w) => $w->where('id', $id)->orWhere('uuid', $id))->first() ?? throw AppError::notFound('EINVOICE_NOT_FOUND', 'الفاتورة الإلكترونية غير موجودة', 'E-invoice not found');
        $source = $e->doc_type === 'CustomerInvoice' ? CustomerInvoice::whereKey($e->doc_id)->first(['id', 'number', 'status', 'kind']) : null;
        $siblings = Einvoice::where('doc_type', $e->doc_type)->where('doc_id', $e->doc_id)->where('id', '!=', $e->id)->orderBy('icv')->get(['id', 'icv', 'status', 'is_current', 'attempt_no', 'created_at']);
        $onboarded = (bool) $e->unit?->onboarded();
        $errors = count(array_filter((array) $e->issues, fn ($i) => ($i['level'] ?? null) === 'error'));
        $open = $e->is_current && ! $e->accepted() && $e->status !== 'withdrawn';

        return Shape::einvoice($e, true) + [
            'source' => $source ? ['id' => $source->id, 'number' => $source->number, 'status' => $source->status, 'kind' => $source->kind] : null,
            'otherDocuments' => $siblings->map(fn (Einvoice $s) => ['id' => $s->id, 'icv' => $s->icv, 'status' => $s->status, 'isCurrent' => $s->is_current, 'attemptNo' => $s->attempt_no, 'createdAt' => Shape::at($s->created_at)])->all(),
            'qrFields' => self::qrFields((string) $e->qr),
            'can' => [
                'remake' => $open && $source !== null && $source->status !== 'cancelled',
                'queue' => $open && $onboarded && $e->status === 'not_sent' && $errors === 0,
                'sendNow' => $open && $onboarded && $e->status === 'queued',
                'unqueue' => $open && $e->status === 'queued',
            ],
            'notOnboarded' => ! $onboarded,
        ];
    }

    /** The XML of a document: the one made here, or the one the authority stamped and returned. */
    public function xml(string $id, bool $cleared = false): array
    {
        $e = Einvoice::where('id', $id)->orWhere('uuid', $id)->first(['id', 'doc_number', 'icv', 'xml', 'cleared_xml']) ?? throw AppError::notFound('EINVOICE_NOT_FOUND', 'الفاتورة الإلكترونية غير موجودة', 'E-invoice not found');
        $xml = $cleared ? $e->cleared_xml : $e->xml;
        if ($xml === null) {
            throw AppError::notFound('EINVOICE_NO_CLEARED_XML', 'لا توجد نسخة مختومة من الهيئة لهذه الفاتورة', 'There is no copy stamped by the authority for this e-invoice');
        }

        return ['name' => preg_replace('/[^A-Za-z0-9_.-]+/', '-', "{$e->doc_number}-icv{$e->icv}".($cleared ? '-cleared' : '')).'.xml', 'xml' => $xml];
    }

    /**
     * The chain of a unit, proven from what is stored: counters 1..n without a gap, each document carrying the hash of
     * the one before it, and each stored XML still hashing to the hash that was recorded.
     */
    public function chain(?string $unitId = null, int $limit = 5000): array
    {
        $unit = $unitId ? (EinvoiceUnit::find($unitId) ?? throw AppError::notFound('EINVOICE_UNIT_NOT_FOUND', 'وحدة الفوترة غير موجودة', 'Unit not found')) : $this->unit();
        $breaks = [];
        $expected = 1;
        $previous = Codes::FIRST_PREVIOUS_HASH;
        $count = 0;
        $last = null;
        foreach (Einvoice::where('unit_id', $unit->id)->orderBy('icv')->limit($limit)->cursor() as $e) {
            $count++;
            if ($e->icv !== $expected) {
                $breaks[] = ['icv' => $e->icv, 'kind' => 'gap', 'ar' => "العداد قفز من {$expected} إلى {$e->icv}", 'en' => "The counter jumps from {$expected} to {$e->icv}"];
            }
            if ($e->prev_hash !== $previous) {
                $breaks[] = ['icv' => $e->icv, 'kind' => 'link', 'ar' => "الفاتورة {$e->icv} لا تحمل بصمة السابقة", 'en' => "Document {$e->icv} does not carry the hash of the one before it"];
            }
            if (InvoiceHash::base64((string) $e->xml) !== $e->hash) {
                $breaks[] = ['icv' => $e->icv, 'kind' => 'content', 'ar' => "محتوى الفاتورة {$e->icv} لا يطابق بصمتها المسجلة", 'en' => "The content of document {$e->icv} no longer matches its recorded hash"];
            }
            $expected = $e->icv + 1;
            $previous = $e->hash;
            $last = $e->icv;
        }
        $head = $count === 0 || ($unit->icv === $last && $unit->last_hash === $previous);
        if (! $head && $count < $limit) {
            $breaks[] = ['icv' => $unit->icv, 'kind' => 'head', 'ar' => 'عداد الوحدة لا يطابق آخر فاتورة في السجل', 'en' => 'The unit counter is not the last document of the register'];
        }

        return ['unit' => ['id' => $unit->id, 'name' => $unit->name, 'icv' => $unit->icv], 'count' => $count, 'first' => $count ? 1 : null, 'last' => $last, 'ok' => ! $breaks, 'breaks' => array_slice($breaks, 0, 50),
            'complete' => $count < $limit];
    }

    /** What the top of the screen says: the mode, the counts, what waits for a person. */
    public function summary(): array
    {
        $unit = $this->unit();
        $profile = $this->profiles->profile();
        $counts = Einvoice::where('is_current', true)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $pending = (clone $this->pendingQuery())->toBase()->getCountForPagination();
        $day = now()->subDay();

        return [
            'mode' => $unit->onboarded() ? ($unit->sending ? 'sending' : 'paused') : 'not_onboarded',
            'modeText' => $unit->onboarded()
                ? ($unit->sending ? ['ar' => 'الوحدة مربوطة بالهيئة: الفواتير تُختم وتُرسل', 'en' => 'The unit is onboarded: documents are stamped and sent']
                    : ['ar' => 'الوحدة مربوطة لكن الإرسال موقوف يدويًا', 'en' => 'The unit is onboarded but sending is paused'])
                : ['ar' => 'غير مربوط بهيئة الزكاة والضريبة والجمارك: تُنشأ الفاتورة الإلكترونية (XML) وسلسلة البصمات ورمز QR الأساسي، ولا يُرسل شيء إلى الهيئة',
                    'en' => 'Not onboarded with ZATCA: the e-invoice XML, the hash chain and the basic QR code are produced, and nothing is sent to the authority'],
            'unit' => Shape::unit($unit),
            'profileReady' => $profile->canIssue(), 'profileMissing' => TaxProfileService::missing($profile), 'auto' => (bool) $profile->einvoice_auto, 'from' => Shape::day($profile->einvoice_from),
            'counts' => collect(Einvoice::STATES)->mapWithKeys(fn (string $s) => [$s => (int) ($counts[$s] ?? 0)])->all(),
            'total' => (int) $counts->sum(),
            'pending' => $pending,
            'withIssues' => Einvoice::where('is_current', true)->whereNotNull('issues')->whereNotIn('status', Einvoice::ACCEPTED)->count(),
            // a simplified document is to be reported within 24 hours of its issue
            'overdue' => $unit->onboarded() ? Einvoice::where('is_current', true)->where('profile', 'simplified')->whereIn('status', ['queued', 'not_sent'])->where('issue_at', '<', $day)->count() : 0,
            'stuck' => Einvoice::where('status', 'queued')->where('attempts', '>=', 5)->count(),
            // accepted by the authority, then cancelled in OPS: the cancellation needs a credit note
            'cancelledAfterAcceptance' => (int) DB::table('tax_einvoices as e')->join('fin_customer_invoices as i', 'i.id', '=', 'e.doc_id')->where('e.doc_type', 'CustomerInvoice')->where('e.is_current', true)
                ->whereIn('e.status', Einvoice::ACCEPTED)->where('i.status', 'cancelled')->count(),
        ];
    }

    // ───────────── helpers ─────────────

    public function find(string $id, bool $lock = false): Einvoice
    {
        return Einvoice::query()->where(fn ($w) => $w->where('id', $id)->orWhere('uuid', $id))->when($lock, fn ($q) => $q->lockForUpdate())->first()
            ?? throw AppError::notFound('EINVOICE_NOT_FOUND', 'الفاتورة الإلكترونية غير موجودة', 'E-invoice not found');
    }

    /** The log of one call to the authority. */
    public function logAttempt(?Einvoice $e, EinvoiceUnit $unit, array $result, int $ms, ?AuthUser $by): EinvoiceAttempt
    {
        return EinvoiceAttempt::create(['einvoice_id' => $e?->id, 'unit_id' => $unit->id, 'api' => $result['api'], 'environment' => $unit->environment, 'http_status' => $result['http'] ?: null, 'outcome' => $result['outcome'],
            'messages' => $result['messages'] ?: null, 'error' => $result['error'], 'duration_ms' => $ms, 'at' => now(), 'by' => $by?->username ?? 'system']);
    }

    /** The fields of a QR text as a person reads them (the stamp fields only as "present, n bytes"). */
    public static function qrFields(string $qr): array
    {
        try {
            $f = Qr::decode($qr);
        } catch (Throwable) {
            return [];
        }
        $out = [];
        foreach ([1 => ['اسم البائع', 'Seller name'], 2 => ['الرقم الضريبي', 'VAT number'], 3 => ['التاريخ والوقت', 'Date and time'], 4 => ['الإجمالي مع الضريبة', 'Total with VAT'], 5 => ['الضريبة', 'VAT total'],
            6 => ['بصمة الفاتورة', 'Invoice hash'], 7 => ['التوقيع', 'Signature'], 8 => ['المفتاح العام', 'Public key'], 9 => ['ختم شهادة الوحدة', 'Certificate signature']] as $tag => [$ar, $en]) {
            if (isset($f[$tag])) {
                $out[] = ['tag' => $tag, 'ar' => $ar, 'en' => $en, 'value' => $tag <= 6 ? $f[$tag] : null, 'bytes' => strlen($f[$tag])];
            }
        }

        return $out;
    }

    /** Every column of the register but the two documents themselves. @return string[] */
    private static function listColumns(): array
    {
        return ['id', 'unit_id', 'icv', 'uuid', 'doc_type', 'doc_id', 'doc_number', 'doc_kind', 'type_code', 'transaction_code', 'profile', 'issue_at', 'customer_id', 'buyer_name', 'buyer_vat', 'net', 'vat', 'total',
            'currency', 'hash', 'prev_hash', 'qr', 'signed', 'is_current', 'superseded_by_id', 'attempt_no', 'status', 'via', 'warnings', 'messages', 'http_status', 'sent_at', 'accepted_at', 'attempts', 'next_attempt_at',
            'last_error', 'issues', 'created_by', 'created_at', 'updated_at'];
    }
}
