<?php

namespace App\Services\Procurement;

use App\Models\Attachment;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Rfq;
use App\Models\RfqLine;
use App\Models\Supplier;
use App\Models\SupplierQuotation;
use App\Models\SupplierQuotationLine;
use App\Models\Warehouse;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\NumberingService;
use App\Services\Core\SettingsService;
use App\Services\Procurement\ProcurementHelpers as H;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Facades\DB;

/** RFQ lifecycle: create + invite suppliers by rule, record quotations, weighted comparison with recommendation, award → PO. */
class RfqService
{
    private const RFQ_OPEN = ['open', 'quoted', 'compared'];

    private const SUP_SEL = 'id,code,name_ar,name_en,category,category_en,score,otif,fill_rate,lead_days,is_new,terms';

    private const PRODUCT_SEL = 'id,sku,name_ar,name_en,purchase_price';

    public function __construct(
        private readonly NumberingService $numbering,
        private readonly AuditService $audit,
        private readonly NotifyService $notify,
        private readonly SettingsService $settings,
        private readonly PoService $po,
    ) {}

    /**
     * Creates an open RFQ and invites suppliers by rule. Joins the caller's transaction when there is one.
     *
     * @param  array{prNumber?:?string, lines:array<int, array{sku:string, qty:int|string}>, supplierCodes?:?array, invitedRule?:?string, closeDate:string,
     *               terms?:?string, deliveryWarehouseCode?:?string, notes?:?string}  $dto
     * @param  array{prId?:?string, source?:string}  $opts
     */
    public function create(AuthUser $user, array $dto, array $opts = []): Rfq
    {
        return DB::transaction(function () use ($user, $dto, $opts) {
            $rule = ($dto['invitedRule'] ?? null) ?: 'cat';
            $prNumber = ($dto['prNumber'] ?? null) ?: null;
            $prId = ($opts['prId'] ?? null) ?: null;
            if (! $prId && $prNumber) {
                $pr = PurchaseRequisition::where('id', $prNumber)->orWhere('number', $prNumber)->first()
                    ?? throw AppError::notFound('PR_NOT_FOUND', "طلب الشراء {$prNumber} غير موجود", "PR {$prNumber} not found");
                $prId = $pr->id;
            }
            if (H::isPastDate($dto['closeDate'])) {
                throw AppError::rule('RFQ_CLOSE_DATE_PAST', 'آخر موعد للعروض لا يمكن أن يكون في الماضي', 'RFQ close date cannot be in the past');
            }
            $wh = ! empty($dto['deliveryWarehouseCode']) ? H::findWarehouse($dto['deliveryWarehouseCode']) : null;
            $products = H::findProducts(array_map(fn ($l) => $l['sku'], $dto['lines']));
            $number = $this->numbering->next('RFQ');
            $rfq = Rfq::create(['number' => $number, 'pr_id' => $prId, 'close_date' => H::parseDate($dto['closeDate']), 'invited_rule' => $rule, 'terms' => ($dto['terms'] ?? null) ?: null,
                'delivery_warehouse_id' => $wh?->id, 'notes' => ($dto['notes'] ?? null) ?: null, 'status' => 'open']);
            foreach (array_values($dto['lines']) as $i => $l) {
                RfqLine::create(['rfq_id' => $rfq->id, 'line_no' => $i + 1, 'product_id' => $products[$l['sku']]->id, 'qty' => (int) $l['qty']]);
            }
            $distinct = [];
            foreach ($products as $p) {
                $distinct[$p->id] = $p;
            }
            $invited = $this->inviteSuppliers($rfq->id, array_values($distinct), $rule, $dto['supplierCodes'] ?? null);
            $codes = array_map(fn (Supplier $s) => $s->code, $invited);
            $this->audit->log($user, ['action' => 'RFQ.CREATE', 'entityType' => 'Rfq', 'entityId' => $rfq->id, 'entityNumber' => $number,
                'newValue' => ['lines' => count($dto['lines']), 'rule' => $rule, 'suppliers' => $codes, 'closeDate' => $dto['closeDate']] + ($prNumber ? ['pr' => $prNumber] : []) + ['source' => $opts['source'] ?? 'manual']]);
            $this->audit->status($user, 'Rfq', $rfq->id, $number, null, 'open');
            $count = count($invited);
            $this->notify->activity($user, 'Rfq', $rfq->id, $number, "أُرسل {$number} إلى {$count} موردين — الإقفال {$dto['closeDate']}", "{$number} sent to {$count} suppliers — closes {$dto['closeDate']}", ['proc']);
            $this->notify->event('RFQ_SENT', ['rfq' => $number, 'suppliers' => array_map(fn (Supplier $s) => ['code' => $s->code, 'email' => $s->email], $invited), 'closeDate' => $dto['closeDate']]);

            return $this->load($rfq->id);
        });
    }

    /**
     * Supplier invitation rules: cat = suppliers whose category matches the products' preferred-supplier categories
     * (all active when none); top3 = best 3 by score; pref = preferred supplier(s) + 2 alternates; manual = given codes.
     * Requires an open transaction (called from create / invite).
     *
     * @param  Product[]  $products  with `suppliers.supplier` loaded
     * @param  string[]|null  $supplierCodes
     * @return Supplier[]
     */
    private function inviteSuppliers(string $rfqId, array $products, string $rule, ?array $supplierCodes = null): array
    {
        $active = Supplier::where('active', true)->orderByDesc('score')->orderBy('code')->get()->all();
        $byName = [];
        foreach ($active as $s) {
            $byName[$s->name_ar] = $s;
            $byName[$s->name_en] = $s;
        }
        $preferred = [];
        $seen = [];
        foreach ($products as $p) {
            $pref = H::preferredSupplierOf($p, $byName)['supplier'] ?? null;
            if ($pref && $pref->active !== false && ! isset($seen[$pref->id])) {
                $seen[$pref->id] = true;
                $preferred[] = $pref;
            }
        }
        $cats = array_values(array_unique(array_filter(array_map(fn (Supplier $s) => $s->category, $preferred))));
        if ($rule === 'manual') {
            if (! $supplierCodes) {
                throw AppError::validation('SUPPLIERS_REQUIRED', 'حدد الموردين المدعوين', 'Supplier codes are required for manual invitation');
            }
            $chosen = array_map(fn ($code) => H::findSupplier((string) $code), array_values($supplierCodes));
        } elseif ($rule === 'top3') {
            $chosen = array_slice($active, 0, 3);
        } elseif ($rule === 'pref') {
            $chosen = $preferred;
            $rest = array_filter($active, fn (Supplier $s) => ! isset($seen[$s->id]));
            $pool = array_merge(array_filter($rest, fn (Supplier $s) => in_array($s->category, $cats, true)), array_filter($rest, fn (Supplier $s) => ! in_array($s->category, $cats, true)));
            $cap = max(3, count($preferred) + 2);
            foreach ($pool as $s) {
                if (count($chosen) >= $cap) {
                    break;
                }
                $chosen[] = $s;
            }
        } else {
            $chosen = $cats ? array_values(array_filter($active, fn (Supplier $s) => in_array($s->category, $cats, true))) : $active;
        }
        if (! $chosen) {
            throw AppError::rule('NO_SUPPLIERS', 'لا يوجد موردون نشطون لدعوتهم', 'No active suppliers to invite');
        }
        $ids = array_values(array_unique(array_map(fn (Supplier $s) => $s->id, $chosen)));
        DB::table('rfq_suppliers')->insertOrIgnore(array_map(fn ($sid) => ['rfq_id' => $rfqId, 'supplier_id' => $sid], $ids));

        return $chosen;
    }

    /** @param  array{invitedRule?:?string, supplierCodes?:?array}  $dto */
    public function invite(AuthUser $user, string $id, array $dto): Rfq
    {
        $rfq = $this->find($id);
        if (! in_array($rfq->status, self::RFQ_OPEN, true)) {
            throw AppError::rule('RFQ_CLOSED', "{$rfq->number} ليس مفتوحًا (الحالة: {$rfq->status})", "RFQ {$rfq->number} is not open");
        }
        $rule = ($dto['invitedRule'] ?? null) ?: 'manual';

        return DB::transaction(function () use ($user, $rfq, $rule, $dto) {
            $products = Product::with('suppliers.supplier')->whereIn('id', $rfq->lines->pluck('product_id')->all())->get()->all();
            $chosen = $this->inviteSuppliers($rfq->id, $products, $rule, $dto['supplierCodes'] ?? null);
            $this->audit->log($user, ['action' => 'RFQ.INVITE', 'entityType' => 'Rfq', 'entityId' => $rfq->id, 'entityNumber' => $rfq->number, 'newValue' => ['rule' => $rule, 'suppliers' => array_map(fn (Supplier $s) => $s->code, $chosen)]]);
            $this->notify->event('RFQ_SENT', ['rfq' => $rfq->number, 'suppliers' => array_map(fn (Supplier $s) => ['code' => $s->code, 'email' => $s->email], $chosen), 'closeDate' => $rfq->close_date?->toJSON()]);

            return $this->load($rfq->id);
        });
    }

    /**
     * Records a supplier quotation (attachment pdf/jpg/png, price > 0, validUntil not past); linked to an RFQ it joins the comparison.
     *
     * @param  array<string, mixed>  $dto  validated SupplierQuotation payload (camelCase)
     */
    public function recordQuotation(AuthUser $user, array $dto): SupplierQuotation
    {
        if (H::isPastDate($dto['validUntil'])) {
            throw AppError::rule('QUOTE_EXPIRED', 'تاريخ الصلاحية منتهٍ', 'Quotation validity date is in the past');
        }
        foreach ($dto['lines'] as $l) {
            if ((float) $l['price'] <= 0) {
                throw AppError::rule('QUOTE_PRICE', 'السعر يجب أن يكون أكبر من صفر', 'Price must be greater than zero');
            }
        }
        $parts = explode('.', (string) $dto['attachmentName']);
        $ext = str_replace('JPEG', 'JPG', strtoupper((string) end($parts)));
        if (! in_array($ext, ['PDF', 'JPG', 'PNG'], true)) {
            throw AppError::validation('QUOTE_ATTACHMENT', 'المرفق يجب أن يكون PDF أو JPG أو PNG', 'Attachment must be PDF, JPG or PNG');
        }

        return DB::transaction(function () use ($user, $dto, $ext) {
            $supplier = H::findSupplier($dto['supplierCode']);
            $rfqNumber = ($dto['rfqNumber'] ?? null) ?: null;
            $rfq = $rfqNumber ? Rfq::where('id', $rfqNumber)->orWhere('number', $rfqNumber)->first() : null;
            if ($rfqNumber && ! $rfq) {
                throw AppError::notFound('RFQ_NOT_FOUND', "طلب العروض {$rfqNumber} غير موجود", "RFQ {$rfqNumber} not found");
            }
            if ($rfq && ! in_array($rfq->status, self::RFQ_OPEN, true)) {
                throw AppError::rule('RFQ_CLOSED', "{$rfq->number} لم يعد يقبل عروضًا (الحالة: {$rfq->status})", "RFQ {$rfq->number} no longer accepts quotations");
            }
            $products = H::findProducts(array_map(fn ($l) => $l['sku'], $dto['lines']));
            $number = $this->numbering->next('SQ');
            $sq = SupplierQuotation::create([
                'number' => $number, 'supplier_id' => $supplier->id, 'rfq_id' => $rfq?->id, 'supplier_ref' => $dto['supplierRef'], 'date' => ! empty($dto['date']) ? H::parseDate($dto['date']) : now(),
                'valid_until' => H::parseDate($dto['validUntil']), 'payment_terms' => ($dto['paymentTerms'] ?? null) ?: null, 'delivery_terms' => ($dto['deliveryTerms'] ?? null) ?: null,
                'min_order' => (int) ($dto['minOrder'] ?? 0), 'lead_days' => (int) $dto['leadDays'], 'notes' => ($dto['notes'] ?? null) ?: null, 'attachment_name' => $dto['attachmentName'], 'attachment_type' => $ext, 'status' => 'received',
            ]);
            foreach ($dto['lines'] as $l) {
                SupplierQuotationLine::create(['quotation_id' => $sq->id, 'product_id' => $products[$l['sku']]->id, 'qty' => isset($l['qty']) ? (int) $l['qty'] : null, 'price' => H::round2((float) $l['price']),
                    'vat_pct' => isset($l['vatPct']) ? (float) $l['vatPct'] : 15, 'lead_days' => isset($l['leadDays']) ? (int) $l['leadDays'] : null]);
            }
            Attachment::create(['entity_type' => 'SupplierQuotation', 'entity_id' => $sq->id, 'file_name' => $dto['attachmentName'], 'mime' => $ext === 'PDF' ? 'application/pdf' : ($ext === 'PNG' ? 'image/png' : 'image/jpeg'), 'status' => 'integration_pending']);
            if ($rfq) {
                DB::table('rfq_suppliers')->insertOrIgnore([['rfq_id' => $rfq->id, 'supplier_id' => $supplier->id]]);
                $this->recompute($rfq->id);
                $count = SupplierQuotation::where('rfq_id', $rfq->id)->count();
                $next = $count >= 2 ? 'compared' : 'quoted';
                if ($rfq->status !== $next && $rfq->status !== 'compared') {
                    $from = $rfq->status;
                    $rfq->update(['status' => $next]);
                    $this->audit->status($user, 'Rfq', $rfq->id, $rfq->number, $from, $next);
                }
            }
            $this->audit->log($user, ['action' => 'SQ.CREATE', 'entityType' => 'SupplierQuotation', 'entityId' => $sq->id, 'entityNumber' => $number,
                'newValue' => ['supplier' => $supplier->code] + ($rfq ? ['rfq' => $rfq->number] : []) + ['lines' => count($dto['lines']), 'attachment' => $ext, 'validUntil' => $dto['validUntil']]]);
            $fresh = $this->loadQuotation($sq->id);
            $priced = $fresh->lines->map(fn ($l) => "{$l->product->name_ar} @ ".PoService::num($l->price).' ر.س')->implode('، ');
            $this->notify->activity($user, 'SupplierQuotation', $sq->id, $number, "سُجل عرض سعر {$number} من {$supplier->name_ar} — {$priced} · مرفق {$ext}".($rfq ? ' — أُضيف لمقارنة '.$rfq->number : ''),
                "Quotation {$number} from {$supplier->name_en} recorded".($rfq ? ' — added to '.$rfq->number : ''), ['proc']);

            return $fresh;
        });
    }

    /**
     * Pure comparison: 50% price, 30% lead days, 20% supplier score (lower weighted = better). Reasons in Arabic like the prototype notes.
     *
     * @return array{rows: array<int, array<string, mixed>>, rec: ?string}
     */
    public function compare(Rfq $rfq, float $minScore): array
    {
        $need = (int) $rfq->lines->sum('qty');
        $rows = [];
        foreach ($rfq->quotations as $q) {
            $price = 0.0;
            $complete = true;
            $maxLineLead = 0;
            foreach ($rfq->lines as $l) {
                $ql = $q->lines->first(fn ($x) => $x->product_id === $l->product_id);
                if (! $ql) {
                    $complete = false;

                    continue;
                }
                $price += (float) $ql->price * $l->qty;
                $maxLineLead = max($maxLineLead, (int) ($ql->lead_days ?: 0));
            }
            $s = $q->supplier;
            $rows[] = [
                'quotationId' => $q->id, 'number' => $q->number, 'supplierRef' => $q->supplier_ref, 'status' => $q->status,
                'supplier' => ['code' => $s->code, 'nameAr' => $s->name_ar, 'nameEn' => $s->name_en, 'score' => $s->score, 'otif' => $s->otif, 'isNew' => (bool) $s->is_new],
                'price' => H::round2($price), 'unitPrice' => $need ? H::round2($price / $need) : 0, 'lead' => (int) ($q->lead_days ?: $maxLineLead ?: 0), 'pay' => $q->payment_terms, 'deliveryTerms' => $q->delivery_terms,
                'min' => (int) ($q->min_order ?: 0), 'score' => $s->score, 'validUntil' => $q->valid_until?->toJSON(), 'complete' => $complete, 'expired' => $q->valid_until !== null && H::isPastDate($q->valid_until),
                'moqExceedsNeed' => (int) ($q->min_order ?: 0) > $need, 'weighted' => null, 'rec' => false, 'reasons' => [], 'reasonsEn' => [],
            ];
        }
        $eligible = array_keys(array_filter($rows, fn ($r) => $r['complete'] && ! $r['expired'] && $r['price'] > 0));
        $minPrice = $eligible ? min(array_map(fn ($i) => $rows[$i]['price'], $eligible)) : null;
        $minLead = $eligible ? min(array_map(fn ($i) => $rows[$i]['lead'], $eligible)) : null;
        $maxScore = $eligible ? max(array_map(fn ($i) => $rows[$i]['score'], $eligible)) : null;
        $minText = PoService::num($minScore);
        foreach ($rows as $i => &$r) {
            $scoreText = PoService::num($r['score']);
            if (in_array($i, $eligible, true)) {
                $r['weighted'] = H::round2(0.5 * ($r['price'] / $minPrice) + 0.3 * (($r['lead'] + 1) / ($minLead + 1)) + 0.2 * ($maxScore / max($r['score'], 1)));
            }
            if (! $r['complete']) {
                $r['reasons'][] = 'العرض لا يغطي كل بنود الطلب';
                $r['reasonsEn'][] = 'Quote does not cover all lines';
            }
            if ($r['expired']) {
                $r['reasons'][] = 'العرض منتهي الصلاحية';
                $r['reasonsEn'][] = 'Quotation expired';
            }
            if ($eligible && $r['complete'] && ! $r['expired']) {
                if ($r['price'] == $minPrice) {
                    $r['reasons'][] = 'السعر الأدنى';
                    $r['reasonsEn'][] = 'Lowest price';
                } else {
                    $pct = (int) round((($r['price'] - $minPrice) / $minPrice) * 100);
                    $r['reasons'][] = "أعلى من السعر الأدنى بـ {$pct}%";
                    $r['reasonsEn'][] = "{$pct}% above lowest price";
                }
                if ($r['lead'] === $minLead) {
                    $r['reasons'][] = 'أسرع توريد';
                    $r['reasonsEn'][] = 'Fastest lead';
                } else {
                    $r['reasons'][] = "مهلة توريد {$r['lead']} يومًا (الأسرع {$minLead})";
                    $r['reasonsEn'][] = "Lead {$r['lead']} days (fastest {$minLead})";
                }
                if ($r['score'] == $maxScore) {
                    $r['reasons'][] = "أعلى تقييم مورد ({$scoreText})";
                    $r['reasonsEn'][] = "Highest supplier score ({$scoreText})";
                }
            }
            $otif = $r['supplier']['otif'];
            $otifText = (string) (int) round((float) $otif);
            if ($r['score'] < $minScore) {
                $r['reasons'][] = "تقييم المورد {$scoreText} دون الحد الأدنى ({$minText})".($otif ? " — OTIF {$otifText}% وتأخر سابق" : '');
                $r['reasonsEn'][] = "Supplier score {$scoreText} below minimum {$minText}".($otif ? " — OTIF {$otifText}%" : '');
            } elseif ($otif && $otif < 85) {
                $r['reasons'][] = "OTIF {$otifText}% منخفض";
                $r['reasonsEn'][] = "Weak OTIF {$otifText}%";
            }
            if ($r['supplier']['isNew']) {
                $r['reasons'][] = 'مورد جديد قيد التقييم';
                $r['reasonsEn'][] = 'New supplier under evaluation';
            }
            if ($r['moqExceedsNeed']) {
                $r['reasons'][] = "حد أدنى {$r['min']} يفوق الحاجة ({$need}) ويرفع الراكد";
                $r['reasonsEn'][] = "MOQ {$r['min']} exceeds need ({$need})";
            }
            if ($r['pay'] && preg_match('/مقدم|prepaid|adv/iu', $r['pay'])) {
                $r['reasons'][] = 'دفع مقدم — أثر على السيولة';
                $r['reasonsEn'][] = 'Prepayment — cash-flow impact';
            }
        }
        unset($r);
        $rec = null;
        foreach ($eligible as $i) {
            if ($rec === null || $rows[$i]['weighted'] < $rows[$rec]['weighted']) {
                $rec = $i;
            }
        }
        if ($rec !== null) {
            $rows[$rec]['rec'] = true;
            array_unshift($rows[$rec]['reasons'], 'توصية النظام — أفضل موازنة بين السعر (50%) والمهلة (30%) وتقييم المورد (20%)');
            array_unshift($rows[$rec]['reasonsEn'], 'System recommendation — best weighted price/lead/score');
        }

        return ['rows' => $rows, 'rec' => $rec !== null ? $rows[$rec]['quotationId'] : null];
    }

    private function minScore(): float
    {
        return (float) ($this->settings->get('procurement.minSupplierScore') ?? 65);
    }

    /** Stores the recommendation flag + weighted score on every quotation of the RFQ. */
    private function recompute(string $rfqId): void
    {
        $cmp = $this->compare($this->load($rfqId), $this->minScore());
        foreach ($cmp['rows'] as $r) {
            SupplierQuotation::where('id', $r['quotationId'])->update(['recommended' => $r['rec'], 'score' => $r['weighted']]);
        }
    }

    public function comparison(string $id): array
    {
        $rfq = $this->find($id);
        $cmp = $this->compare($rfq, $this->minScore());

        return [
            'rfq' => ['id' => $rfq->id, 'number' => $rfq->number, 'status' => $rfq->status, 'closeDate' => $rfq->close_date?->toJSON(), 'terms' => $rfq->terms, 'awardedQuotationId' => $rfq->awarded_quotation_id,
                'warehouse' => $rfq->warehouse?->toArray(), 'pr' => $rfq->pr?->toArray()],
            'need' => $rfq->lines->map->toArray()->all(),
            'invited' => $rfq->suppliers->map(fn ($s) => $s->supplier?->toArray())->filter()->values()->all(),
            'quotes' => $cmp['rows'], 'rec' => $cmp['rec'], 'weights' => ['price' => 0.5, 'lead' => 0.3, 'score' => 0.2],
        ];
    }

    /**
     * Award: creates a PO (pending approval) from the awarded quotation and marks the RFQ awarded.
     *
     * @param  array{quotation:string, warehouseCode?:?string, dueDate?:?string, paymentTerms?:?string, notes?:?string, overrideSupplierScore?:?bool, overrideReason?:?string}  $dto
     * @return array{rfq: Rfq, po: PurchaseOrder}
     */
    public function award(AuthUser $user, string $id, array $dto): array
    {
        // Resolved before the transaction so that the row lock is its first statement (MySQL snapshot rule): two concurrent
        // awards are serialised and the second one sees the RFQ already awarded.
        $rfqId = Rfq::where('id', $id)->orWhere('number', $id)->value('id')
            ?? throw AppError::notFound('RFQ_NOT_FOUND', "طلب العروض {$id} غير موجود", "RFQ {$id} not found");

        return DB::transaction(function () use ($user, $rfqId, $dto) {
            Rfq::where('id', $rfqId)->lockForUpdate()->first(['id']);
            $rfq = $this->load($rfqId);
            if (! in_array($rfq->status, self::RFQ_OPEN, true)) {
                throw AppError::rule('RFQ_NOT_AWARDABLE', "{$rfq->number} ".($rfq->status === 'awarded' ? 'رُسّي مسبقًا' : 'ليس مفتوحًا')." (الحالة: {$rfq->status})", "RFQ {$rfq->number} cannot be awarded (status {$rfq->status})");
            }
            $q = $rfq->quotations->first(fn ($x) => $x->id === $dto['quotation'] || $x->number === $dto['quotation'])
                ?? throw AppError::notFound('QUOTE_NOT_FOUND', "العرض {$dto['quotation']} غير مرتبط بـ {$rfq->number}", "Quotation {$dto['quotation']} is not part of {$rfq->number}");
            if ($q->valid_until && H::isPastDate($q->valid_until)) {
                throw AppError::rule('QUOTE_EXPIRED', "العرض {$q->number} منتهي الصلاحية", "Quotation {$q->number} has expired");
            }
            $whId = ! empty($dto['warehouseCode']) ? H::findWarehouse($dto['warehouseCode'])->id : ($rfq->delivery_warehouse_id ?: $rfq->pr?->warehouse_id);
            if (! $whId) {
                throw AppError::validation('WAREHOUSE_REQUIRED', 'حدد مستودع التوريد لأمر الشراء', 'Delivery warehouse is required');
            }
            $wh = Warehouse::findOrFail($whId);
            $lines = [];
            foreach ($rfq->lines as $l) {
                $ql = $q->lines->first(fn ($x) => $x->product_id === $l->product_id)
                    ?? throw AppError::rule('QUOTE_INCOMPLETE', "العرض {$q->number} لا يتضمن سعرًا لـ {$l->product->name_ar}", "Quotation {$q->number} has no price for {$l->product->sku}");
                $lines[] = ['sku' => $l->product->sku, 'qty' => $l->qty, 'price' => (float) $ql->price];
            }
            $dueDate = ($dto['dueDate'] ?? null) ?: H::isoDatePlus((int) ($q->lead_days ?: 0));
            $po = $this->po->create($user, [
                'supplierCode' => $q->supplier->code, 'warehouseCode' => $wh->code, 'dueDate' => $dueDate, 'paymentTerms' => ($dto['paymentTerms'] ?? null) ?: ($q->payment_terms ?: null),
                'reference' => "{$rfq->number} / {$q->number}", 'notes' => $dto['notes'] ?? null, 'lines' => $lines,
                'overrideSupplierScore' => $dto['overrideSupplierScore'] ?? null, 'overrideReason' => $dto['overrideReason'] ?? null,
            ], ['rfqId' => $rfq->id, 'source' => 'rfq']);
            SupplierQuotation::where('rfq_id', $rfq->id)->where('id', '!=', $q->id)->update(['status' => 'lost']);
            SupplierQuotation::where('id', $q->id)->update(['status' => 'awarded']);
            $from = $rfq->status;
            Rfq::where('id', $rfq->id)->update(['status' => 'awarded', 'awarded_quotation_id' => $q->id, 'awarded_at' => now()]);
            if ($rfq->pr_id) {
                PurchaseRequisition::where('id', $rfq->pr_id)->update(['po_id' => $po->id]);
            }
            $this->audit->log($user, ['action' => 'RFQ.AWARD', 'entityType' => 'Rfq', 'entityId' => $rfq->id, 'entityNumber' => $rfq->number, 'newValue' => ['quotation' => $q->number, 'supplier' => $q->supplier->code, 'po' => $po->number, 'total' => (float) $po->total]]);
            $this->audit->status($user, 'Rfq', $rfq->id, $rfq->number, $from, 'awarded', "{$q->number} → {$po->number}");
            $this->notify->activity($user, 'Rfq', $rfq->id, $rfq->number, "رُسّي {$rfq->number} على {$q->supplier->name_ar} — أُنشئ {$po->number} بانتظار الاعتماد", "{$rfq->number} awarded to {$q->supplier->name_en} — {$po->number} created", ['proc']);

            return ['rfq' => $this->load($rfq->id), 'po' => $po];
        });
    }

    public function cancel(AuthUser $user, string $id, ?string $reason = null): Rfq
    {
        $rfq = $this->find($id);
        if (! in_array($rfq->status, self::RFQ_OPEN, true)) {
            throw AppError::rule('RFQ_NOT_OPEN', "{$rfq->number} لا يمكن إلغاؤه (الحالة: {$rfq->status})", "RFQ {$rfq->number} cannot be cancelled");
        }

        return DB::transaction(function () use ($user, $rfq, $reason) {
            Rfq::where('id', $rfq->id)->update(['status' => 'cancelled']);
            $this->audit->status($user, 'Rfq', $rfq->id, $rfq->number, $rfq->status, 'cancelled', $reason);

            return $this->load($rfq->id);
        });
    }

    public function get(string $id): array
    {
        $rfq = $this->find($id);
        $pos = PurchaseOrder::where('rfq_id', $rfq->id)->get(['id', 'number', 'status', 'total']);

        return $rfq->toArray() + ['pos' => $pos->map->toArray()->all()];
    }

    /** @param  array{status?:?string, pr?:?string}  $filters */
    public function list(Paging $page, array $filters): array
    {
        $query = Rfq::query()->with(['lines.product:'.self::PRODUCT_SEL, 'warehouse:id,code,name_ar,name_en', 'pr:id,number'])->withCount(['suppliers', 'quotations']);
        if (! empty($filters['status'])) {
            $query->whereIn('status', H::csv($filters['status']));
        }
        if (! empty($filters['pr'])) {
            $query->whereHas('pr', fn ($p) => $p->where('number', $filters['pr']));
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $query->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('notes', 'like', $like)
                ->orWhereHas('lines.product', fn ($p) => $p->where(fn ($x) => $x->where('sku', 'like', $like)->orWhere('name_ar', 'like', $like))));
        }
        $query->orderBy('created_at', $page->order)->orderBy('id', $page->order);

        return $page->paginate($query, function (Rfq $rfq) {
            $rfq->warehouse?->makeHidden('id');
            $rfq->pr?->makeHidden('id');
            $row = $rfq->toArray();
            $row['_count'] = ['suppliers' => (int) $rfq->suppliers_count, 'quotations' => (int) $rfq->quotations_count];
            unset($row['suppliersCount'], $row['quotationsCount']);

            return $row;
        });
    }

    /** @param  array{supplier?:?string, rfq?:?string, status?:?string}  $filters */
    public function quotations(Paging $page, array $filters): array
    {
        $query = SupplierQuotation::query()->with($this->quotationIncludes());
        if (! empty($filters['supplier'])) {
            $query->whereHas('supplier', fn ($s) => $s->where('code', $filters['supplier']));
        }
        if (! empty($filters['rfq'])) {
            $query->whereHas('rfq', fn ($r) => $r->where('number', $filters['rfq']));
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $query->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('supplier_ref', 'like', $like)->orWhereHas('supplier', fn ($s) => $s->where('name_ar', 'like', $like)));
        }
        $query->orderBy('created_at', $page->order)->orderBy('id', $page->order);

        return $page->paginate($query);
    }

    public function quotation(string $id): array
    {
        $sq = SupplierQuotation::where('id', $id)->orWhere('number', $id)->first()
            ?? throw AppError::notFound('QUOTE_NOT_FOUND', "عرض السعر {$id} غير موجود", "Quotation {$id} not found");
        $sq = $this->loadQuotation($sq->id);
        $attachments = Attachment::where('entity_type', 'SupplierQuotation')->where('entity_id', $sq->id)->get();

        return $sq->toArray() + ['attachments' => $attachments->map->toArray()->all()];
    }

    /** The RFQ with the projection every RFQ endpoint returns. */
    public function load(string $rfqId): Rfq
    {
        return Rfq::with([
            'pr:id,number,status,warehouse_id', 'warehouse:id,code,name_ar,name_en',
            'lines' => fn ($q) => $q->orderBy('line_no'), 'lines.product:'.self::PRODUCT_SEL,
            'suppliers.supplier:'.self::SUP_SEL,
            'quotations' => fn ($q) => $q->orderBy('created_at')->orderBy('id'), 'quotations.supplier:'.self::SUP_SEL, 'quotations.lines.product:'.self::PRODUCT_SEL,
        ])->findOrFail($rfqId);
    }

    private function quotationIncludes(): array
    {
        return ['supplier:'.self::SUP_SEL, 'rfq:id,number,status', 'lines.product:'.self::PRODUCT_SEL];
    }

    private function loadQuotation(string $id): SupplierQuotation
    {
        return SupplierQuotation::with($this->quotationIncludes())->findOrFail($id);
    }

    private function find(string $id): Rfq
    {
        $rfq = Rfq::where('id', $id)->orWhere('number', $id)->first(['id'])
            ?? throw AppError::notFound('RFQ_NOT_FOUND', "طلب العروض {$id} غير موجود", "RFQ {$id} not found");

        return $this->load($rfq->id);
    }
}
