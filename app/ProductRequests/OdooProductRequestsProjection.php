<?php

namespace App\ProductRequests;

use App\Integration\Odoo\Models\OdooRecord;
use App\Integration\Odoo\OdooMasterData;
use App\Integration\Odoo\Projection\OdooProjection;
use App\Integration\Odoo\Projection\Val;
use App\Integration\Services\ExternalRefs;
use App\Models\Customer;
use App\Models\ProductCategory;
use App\ProductRequests\Models\ProductRequest;
use App\ProductRequests\Models\ProductRequestEvent;
use App\ProductRequests\Models\ProductRequestPicture;
use App\ProductRequests\Services\DuplicatesService;
use App\ProductRequests\Support\Flow;
use App\ProductRequests\Support\NameKey;
use App\ProductRequests\Support\People;
use App\ProductRequests\Support\Shape;
use App\Services\Core\AuditService;
use App\Support\AppError;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Odoo's new-product requests → the OPS section «طلبات الأصناف الجديدة». Both of Odoo's custom models become OPS
 * requests (`source_system = 'odoo'`, `source_model`, `external_ref` = the Odoo id), so the section holds the whole
 * history and stays in step while the company still asks for products in Odoo:
 *
 *   b2b.new.product       the staff flow — a request with three approvals in a row (supply chain manager, sales
 *                         manager, system administrator), then «معتمد» or «رفض»
 *   hub.product.request   what a customer asks for through the portal: a new product, or a product of the catalogue
 *
 * STATES are mapped faithfully (Flow::ODOO_STAFF / ODOO_PORTAL) and Odoo's own word is kept beside the OPS status.
 * Odoo holds ONE name ("Arabic English (pack)"): it is split the way the master data splits product names
 * (OdooMasterData::names). The pictures are the attachments Odoo lists for the record (`ir.attachment`): each is
 * listed on the OPS request and its content is read from the Odoo file archive — linked, never copied.
 *
 * THE PRODUCT A REQUEST BECAME. The portal model names it (`created_product_id`; `product_id` for a request about a
 * product of the catalogue). The staff model names nothing: its product is the Odoo product of exactly that name that
 * was created in the very transaction that approved the request — the same minute, by the same user — and only that
 * one; when no such product exists, or two could be it, nothing is linked and the request is counted for a person to
 * link (never a guess by a similar name). The link to OPS is by identity: the OPS product that Odoo product already
 * is (`int_external_refs`, system `odoo`, entity `product`). A request whose Odoo product is not an OPS product yet
 * remembers it (`source_product_ref`) and is linked by itself the day it becomes one.
 *
 * PEOPLE, CATEGORIES, CUSTOMERS are recognised by identity only: an Odoo user is the OPS user whose e-mail or username
 * is his Odoo login (or whose e-mail is the e-mail of his Odoo contact); a category and a customer are the OPS records
 * they are linked to. Whatever is not recognised is kept by name.
 *
 * WHO OWNS THE RECORDS. While the section follows Odoo (OdooProjection::owns) every value written here is Odoo's and a
 * change in Odoo replaces it; comments and pictures added in OPS, and a product a person linked here, are OPS's and
 * are never touched. Once the section is handed over (the switch is off) a pass only adds what is missing and leaves
 * every existing request as OPS has it. A request deleted in Odoo is not deleted here: it is history (what was still
 * open is closed, with the reason). plan() also runs where the section's tables do not exist yet — it never writes.
 *
 * Nobody is notified by a projection: Odoo has told its own users, and a first load is history.
 */
class OdooProductRequestsProjection extends OdooProjection
{
    public const KEY = 'preq';

    private const STAFF = ProductRequest::STAFF_MODEL;

    private const PORTAL = ProductRequest::PORTAL_MODEL;

    /** The prefix of the fallback number when Odoo's own reference cannot be the OPS number. */
    private const PREFIX = [self::STAFF => 'NPR', self::PORTAL => 'PRQ'];

    /** Odoo creates the product in the transaction that approves the request: this many seconds around its last write. */
    private const PRODUCT_WINDOW = 90;

    private const DELETED = 'حُذف الطلب من أودو';

    private const IMAGE_EXT = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    /** A whole-mirror pass (plan / apply) reads the OPS rows and the copy's lookups once; the feed works record by record. */
    private bool $whole = false;

    /** Odoo still owns what came from it: changes in Odoo replace the values here. */
    private bool $follows = true;

    /** The section's tables exist (a plan may run before the migration). */
    private bool $installed = true;

    /** @var array<string, ProductRequest>|null whole pass: the OPS requests that came from Odoo, by "model:id" */
    private ?array $rows = null;

    /** @var array<string, array<string, ProductRequestPicture>>|null whole pass: their Odoo pictures, by request id then attachment id */
    private ?array $pictureRows = null;

    /** @var array<string, array<int, array<int, array>>>|null whole pass: model → record id → attachment id → the file as Odoo lists it */
    private ?array $files = null;

    /** @var array<int, true>|null whole pass: the attachments whose content OPS keeps */
    private ?array $kept = null;

    /** @var array<int, array{0:?string, 1:?string, 2:?string}> Odoo user id → [OPS user id, name, Odoo login] */
    private array $people = [];

    /** @var array{email: array<string, string[]>, username: array<string, string[]>}|null OPS users by what identifies them */
    private ?array $opsUsers = null;

    /** @var array<string, array<int, ?string>> entity → Odoo id → the OPS id it is linked to (null: not an OPS record) */
    private array $ops = [];

    /** @var array<string, ?string> OPS product id → its code (null: no such product) */
    private array $skus = [];

    /** @var array<string, array<int, array{id:int, at:?int, uid:?int}>>|null Odoo's product templates by exact name */
    private ?array $templates = null;

    /** @var array<string, array<int, array{id:int, at:?int, uid:?int}>> … and by the name with its spacing and letter case folded */
    private array $templatesFolded = [];

    /** @var array<int, int[]>|null template id → the ids of its variants (the link to OPS lives on the variant) */
    private ?array $variants = null;

    /** The Odoo file archive is another part of the system (the backup): it may not be installed where this runs. */
    private static ?bool $hasFileArchive = null;

    public function __construct(ExternalRefs $refs, private readonly AuditService $audit)
    {
        parent::__construct($refs);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function models(): array
    {
        return [self::STAFF, self::PORTAL];
    }

    public function plan(): array
    {
        return $this->wholePass(fn () => parent::plan());
    }

    public function apply(): array
    {
        return $this->wholePass(fn () => parent::apply());
    }

    /**
     * What the feed reports, then — with the time that is left — the requests whose Odoo product has become an OPS
     * product since they were applied (that happens in the master data, which is not this projection's feed).
     */
    public function consume(int $max = 500, float $seconds = 8.0): array
    {
        $this->whole = false;
        $started = microtime(true);
        $out = parent::consume($max, $seconds);
        if (microtime(true) - $started < $seconds && ($linked = $this->relink())) {
            $out['counts']['link.restoredLater'] = ($out['counts']['link.restoredLater'] ?? 0) + $linked;
        }

        return $out;
    }

    private function wholePass(callable $pass): array
    {
        $this->whole = true;
        try {
            return $pass();
        } finally {
            $this->whole = false;
            $this->rows = $this->pictureRows = $this->files = $this->kept = $this->templates = $this->variants = null;
            $this->templatesFolded = $this->skus = [];
        }
    }

    protected function begin(): void
    {
        $this->follows = self::owns($this->key());
        $this->installed = Schema::hasTable('product_requests');
        if (! $this->installed && ! $this->dry) {
            throw AppError::rule('PREQ_NOT_INSTALLED', 'جداول قسم طلبات الأصناف لم تُنشأ بعد — شغّل الترحيل أولًا (php artisan migrate)', 'The tables of the product-requests section do not exist yet — run the migration first');
        }
        $this->people = $this->ops = $this->skus = $this->templatesFolded = [];
        $this->opsUsers = $this->files = $this->kept = $this->templates = $this->variants = $this->rows = $this->pictureRows = null;
        if ($this->whole) {
            $this->rows = [];
            $this->pictureRows = [];
            if ($this->installed) {
                foreach (ProductRequest::where('source_system', self::SYSTEM)->get() as $row) {
                    $this->rows["{$row->source_model}:{$row->external_ref}"] = $row;
                }
                foreach (ProductRequestPicture::where('source_system', self::SYSTEM)->get() as $p) {
                    $this->pictureRows[(string) $p->request_id][(string) $p->external_ref] = $p;
                }
            }
            $this->skus = DB::table('products')->pluck('sku', 'id')->map(fn ($sku) => (string) $sku)->all();
        }
    }

    protected function project(OdooRecord $record): void
    {
        $work = fn () => match ($record->model) {
            self::STAFF => $this->staff($record),
            self::PORTAL => $this->portal($record),
            default => null,
        };
        $row = $this->dry ? $work() : DB::transaction($work);
        // committed: a whole pass keeps the new row with the ones it read at its start
        if ($row instanceof ProductRequest && $this->rows !== null) {
            $this->rows["{$record->model}:{$record->odoo_id}"] = $row;
        }
    }

    // ───────────── the two models ─────────────

    /** `b2b.new.product`: a request raised by the staff, approved in three steps. */
    private function staff(OdooRecord $r): ?ProductRequest
    {
        $d = (array) $r->data;
        $name = Val::text($d['product_name'] ?? null) ?? Val::text($r->display_name) ?? "طلب أودو #{$r->odoo_id}";
        $state = Val::text($d['state'] ?? null, 40) ?? 'draft';
        $status = Flow::ODOO_STAFF[$state] ?? null;
        if ($status === null) {
            $this->count('request.unknownState', "{$r->model} #{$r->odoo_id}: {$state}");
            $status = Flow::DRAFT;
        }
        $written = Val::at($d['write_date'] ?? $r->write_date);
        $created = Val::at($d['create_date'] ?? null) ?? $written;
        [$requesterId, $requesterName, $requesterLogin] = $this->person($d['create_uid'] ?? null);
        [$writerId, $writerName] = $this->person($d['write_uid'] ?? null);
        // Odoo names who approved each of its three steps, and nothing else about them
        $steps = ['supplyChainManager' => $d['supply_chain_manager_id'] ?? null, 'salesManager' => $d['sales_manager_id'] ?? null, 'systemAdmin' => $d['system_admin_id'] ?? null];
        $approvals = array_filter(array_map(fn ($user) => Val::id($user) ? $this->person($user)[1] : null, $steps));
        $first = Val::id($steps['supplyChainManager']) ? $this->person($steps['supplyChainManager']) : [null, null];
        // who settled it: the last approver Odoo names for an approved request, whoever wrote it last for a rejected one
        $last = Val::id($steps['systemAdmin']) ? $steps['systemAdmin'] : (Val::id($steps['salesManager']) ? $steps['salesManager'] : ($d['write_uid'] ?? null));
        $decider = match ($status) {
            Flow::APPROVED => $this->person($last),
            Flow::REJECTED => [$writerId, $writerName],
            default => [null, null],
        };
        $decided = in_array($status, [Flow::APPROVED, Flow::REJECTED], true);
        $categoryOdoo = Val::id($d['category_id'] ?? null);
        $product = $status === Flow::APPROVED ? $this->staffProduct($d, $name, $created, $written) : ['outcome' => null];

        return $this->request($r, $name, [
            'request_type' => 'new', 'status' => $status, 'status_at' => $written,
            'category_id' => $this->ops('category', $categoryOdoo, ProductCategory::class), 'category_name' => self::cut(Val::label($d['category_id'] ?? null), 250),
            'cost_price' => self::price($d['purchase_price'] ?? null), 'sale_price' => self::price($d['sale_price'] ?? null), 'currency' => 'SAR',
            'barcode' => DuplicatesService::barcode(Val::text($d['product_barcode'] ?? null, 64)) ?: null,
            'tracks_expiry' => Val::bool($d['use_expiration_date'] ?? false),
            'reason' => Val::plain($d['description'] ?? null, 4000),
            'requested_by_id' => $requesterId, 'requested_by_name' => $requesterName, 'requested_by_login' => $requesterLogin,
            'reviewer_id' => $status === Flow::DRAFT ? null : $first[0], 'reviewer_name' => $status === Flow::DRAFT ? null : $first[1],
            'decided_by_id' => $decider[0], 'decided_by_name' => $decider[1],
            // Odoo keeps no time of the sending: a request is sent when it is made, or soon after
            'submitted_at' => $status === Flow::DRAFT ? null : $created, 'decided_at' => $decided ? $written : null,
            'source_number' => Val::text($d['name'] ?? null, 60) ?? Val::text($r->display_name, 60), 'source_state' => $state,
            'source_meta' => self::meta([
                'approvals' => $approvals ?: null, 'tracking' => Val::text($d['tracking'] ?? null, 20), 'productType' => Val::text($d['product_type'] ?? null, 20),
                'writtenBy' => $writerName, 'odooProduct' => $product['odoo'] ?? null, 'productLookup' => in_array($product['outcome'], ['productNotFound', 'ambiguous'], true) ? $product['outcome'] : null,
            ]),
            'source_updated_at' => $written,
        ], $product, [
            'created' => $created, 'written' => $written, 'requester' => [$requesterId, $requesterName], 'actor' => $decided ? $decider : [$writerId, $writerName],
            'meta' => array_filter(['sourceState' => $state, 'approvals' => $status === Flow::APPROVED ? $approvals : null]),
        ]);
    }

    /** `hub.product.request`: what a customer asks for through the portal — a new product, or one the catalogue has. */
    private function portal(OdooRecord $r): ?ProductRequest
    {
        $d = (array) $r->data;
        $type = ($d['request_type'] ?? 'new') === 'existing' ? 'existing' : 'new';
        // a request about a product of the catalogue carries that product, not a typed name
        $name = Val::text($d['product_name'] ?? null) ?? Val::label($d['product_id'] ?? null) ?? Val::label($d['created_product_id'] ?? null)
            ?? Val::text($r->display_name) ?? "طلب عميل #{$r->odoo_id}";
        $state = Val::text($d['state'] ?? null, 40) ?? 'pending';
        $status = Flow::ODOO_PORTAL[$state] ?? null;
        if ($status === null) {
            $this->count('request.unknownState', "{$r->model} #{$r->odoo_id}: {$state}");
            $status = Flow::SUBMITTED;
        }
        $written = Val::at($d['write_date'] ?? $r->write_date);
        $created = Val::at($d['create_date'] ?? null) ?? $written;
        $asked = Val::at($d['request_date'] ?? null) ?? $created;
        $decidedAt = Val::at($d['approval_date'] ?? null);
        [$requesterId, $requesterName, $requesterLogin] = $this->person($d['requested_by_id'] ?? $d['create_uid'] ?? null);
        [$writerId, $writerName] = $this->person($d['write_uid'] ?? null);
        $decided = in_array($status, [Flow::APPROVED, Flow::REJECTED], true);
        $decider = $decided ? (Val::id($d['approved_by_id'] ?? null) ? $this->person($d['approved_by_id']) : [$writerId, $writerName]) : [null, null];
        $partner = Val::id($d['company_partner_id'] ?? null);
        $currency = Val::label($d['currency_id'] ?? null);
        $product = $this->portalProduct($d, $type, $status);

        return $this->request($r, $name, [
            'request_type' => $type, 'status' => $status,
            'status_at' => match (true) {
                $status === Flow::SUBMITTED => $asked,
                $decided => $decidedAt ?? $written,
                default => $written,
            },
            'category_id' => $this->ops('category', Val::id($d['category_id'] ?? null), ProductCategory::class),
            'category_name' => self::cut(Val::label($d['category_id'] ?? null) ?? Val::text($d['category_hint'] ?? null), 250),
            'uom_name' => self::cut(Val::label($d['uom_id'] ?? null) ?? Val::text($d['uom_hint'] ?? null), 120),
            'sale_price' => self::price($d['approved_price'] ?? null) ?? self::price($d['suggested_price'] ?? null),
            'currency' => $currency !== null && preg_match('/^[A-Za-z]{3}$/', $currency) ? strtoupper($currency) : 'SAR',
            'barcode' => DuplicatesService::barcode(Val::text($d['product_barcode'] ?? null, 64)) ?: null,
            'proposed_sku' => Val::text($d['product_code'] ?? null, 64),
            'reason' => Val::plain($d['customer_note'] ?? null, 4000),
            'customer_id' => $this->ops('customer', $partner, Customer::class), 'customer_name' => self::cut(Val::label($d['company_partner_id'] ?? null), 250),
            'requested_by_id' => $requesterId, 'requested_by_name' => $requesterName, 'requested_by_login' => $requesterLogin,
            'decided_by_id' => $decider[0], 'decided_by_name' => $decider[1],
            'submitted_at' => $asked, 'decided_at' => $decided ? ($decidedAt ?? $written) : null,
            'reject_reason' => $status === Flow::REJECTED ? Val::plain($d['rejection_reason'] ?? null, 2000) : null,
            'source_number' => Val::text($d['name'] ?? null, 60) ?? Val::text($r->display_name, 60), 'source_state' => $state,
            'source_meta' => self::meta([
                'company' => Val::label($d['company_id'] ?? null), 'branch' => Val::label($d['branch_id'] ?? null), 'note' => Val::plain($d['note'] ?? null, 2000),
                'suggestedPrice' => Shape::money(self::price($d['suggested_price'] ?? null)), 'approvedPrice' => Shape::money(self::price($d['approved_price'] ?? null)),
                'pricelist' => Val::label($d['pricelist_id'] ?? null), 'pricelistItemId' => Val::id($d['pricelist_item_id'] ?? null),
                'pictureFileName' => Val::text($d['product_image_filename'] ?? null), 'partnerId' => $partner, 'writtenBy' => $writerName,
                'odooProduct' => $product['odoo'] ?? null, 'productLookup' => ($product['outcome'] ?? null) === 'productNotFound' ? 'productNotFound' : null,
            ]),
            'source_updated_at' => $written,
        ], $product, [
            'created' => $created, 'written' => $written, 'requester' => [$requesterId, $requesterName], 'actor' => $decided ? $decider : [$writerId, $writerName],
            'meta' => ['sourceState' => $state],
        ]);
    }

    // ───────────── one request ─────────────

    /**
     * Creates the OPS request of an Odoo record or brings it in step, with its story and its pictures; counts what it
     * did (`request.created | updated | unchanged | kept | skippedDeleted`). In a plan it only counts.
     *
     * @param  array<string, mixed>  $values  the columns Odoo owns, as they should be now
     * @param  array{outcome:?string, id?:?string, sku?:?string, link?:?string, odoo?:?array, ref?:?string}  $product
     * @param  array{created:?Carbon, written:?Carbon, requester:array, actor:array, meta:array}  $story
     * @return ProductRequest|null the row, when one was written
     */
    private function request(OdooRecord $r, string $name, array $values, array $product, array $story): ?ProductRequest
    {
        $existing = $this->row($r->model, $r->odoo_id);
        $gone = $r->deleted_at !== null;
        if (! $existing && $gone) {
            $this->count('request.skippedDeleted');

            return null;
        }
        [$ar, $en] = OdooMasterData::names($name);
        if ($ar === '') {
            $ar = $en = $name; // a name that is nothing but a "[code]": kept as Odoo holds it
        }
        $values = [
            'name_ar' => $ar, 'name_en' => $en !== $ar ? $en : null,
            'name_key' => (NameKey::core($ar) ?: NameKey::of($ar)) ?: null, 'name_en_key' => $en !== $ar ? ((NameKey::core($en) ?: NameKey::of($en)) ?: null) : null,
            'pack_text' => preg_match('/\(([^()]*\d[^()]*)\)/u', $name, $m) ? self::cut(trim($m[1]), 120) : null,
        ] + $values;
        if ($gone) {
            // a request deleted in Odoo is not deleted here: it is history — and what was still open is closed
            if (in_array($values['status'], Flow::OPEN, true)) {
                $values = ['status' => Flow::CANCELLED, 'status_at' => Carbon::instance($r->deleted_at), 'cancel_reason' => self::DELETED] + $values;
                $story = ['actor' => [null, 'Odoo'], 'note' => self::DELETED, 'at' => Carbon::instance($r->deleted_at)] + $story;
            }
            $values['source_deleted_at'] = Carbon::instance($r->deleted_at);
        } else {
            $values['source_deleted_at'] = null;
        }
        // the product: what a person decided here (a link made by hand, a product created by an approval here) is OPS's
        if ($existing && in_array($existing->product_link, ['manual', 'created'], true)) {
            $this->count('link.keptAsDecidedHere');
        } else {
            $values += ['product_id' => $product['id'] ?? null, 'product_sku' => $product['sku'] ?? null, 'product_link' => $product['link'] ?? null, 'source_product_ref' => $product['ref'] ?? null];
            if ($product['outcome'] ?? null) {
                $this->count('link.'.$product['outcome'], in_array($product['outcome'], ['productNotFound', 'ambiguous'], true) ? ($values['source_number'] ?? "#{$r->odoo_id}").' — '.mb_substr($ar, 0, 60) : null);
            }
        }
        $statusAt = $story['at'] ?? $values['status_at'] ?? $story['written'];
        $note = $story['note'] ?? ($values['status'] === Flow::REJECTED ? ($values['reject_reason'] ?? null) : null);

        if (! $existing) {
            $this->count('request.created');
            if ($this->dry) {
                $this->pictures(null, $r, true);

                return null;
            }
            $row = new ProductRequest;
            $row->timestamps = false; // the dates are Odoo's, not the moment of the copy
            $row->forceFill($values + [
                'number' => $this->number($r, $values['source_number'] ?? null), 'source_system' => self::SYSTEM, 'source_model' => $r->model, 'external_ref' => (string) $r->odoo_id,
                'created_by' => 'svc.odoo', 'created_at' => $story['created'] ?? now(), 'updated_at' => $story['written'] ?? $story['created'] ?? now(),
            ])->save();
            // its story, as far as the record itself tells it: raised, sent, and where it stands now
            $this->event($row, 'created', $story['created'], $story['requester'], null, [], null, Flow::DRAFT);
            if ($row->status !== Flow::DRAFT) {
                $this->event($row, 'submitted', $values['submitted_at'] ?? $story['created'], $story['requester'], null, [], Flow::DRAFT, Flow::SUBMITTED);
            }
            if (! in_array($row->status, [Flow::DRAFT, Flow::SUBMITTED], true)) {
                $this->event($row, Flow::EVENT_OF[$row->status], $statusAt, $story['actor'], $note, $story['meta'], Flow::SUBMITTED, $row->status);
            }
            $this->audit->log($this->actor(), ['action' => 'PREQ.IMPORT', 'entityType' => 'ProductRequest', 'entityId' => $row->id, 'entityNumber' => $row->number,
                'newValue' => ['status' => $row->status, 'nameAr' => $ar, 'product' => $row->product_sku, 'origin' => "Odoo {$r->model} #{$r->odoo_id}"]]);
            $this->pictures($row, $r, true);

            return $row;
        }
        if (! $this->follows) {
            $this->count('request.kept'); // handed over: the request is OPS's now

            return null;
        }
        $old = [];
        foreach ($values as $column => $value) {
            if (! self::same($existing->getAttribute($column), $value)) {
                $old[$column] = $existing->getAttribute($column);
            }
        }
        $this->count($old ? 'request.updated' : 'request.unchanged');
        if ($old && ! $this->dry) {
            $existing->timestamps = false;
            $existing->forceFill(array_intersect_key($values, $old) + ['updated_at' => $story['written'] ?? now()])->save();
            if (array_key_exists('status', $old)) {
                $this->event($existing, Flow::EVENT_OF[$existing->status], $statusAt, $story['actor'], $note, $story['meta'], $old['status'], $existing->status);
                $this->audit->status($this->actor(), 'ProductRequest', $existing->id, $existing->number, $old['status'], $existing->status, 'من أودو');
            } elseif (array_key_exists('source_state', $old)) {
                // one of Odoo's three approvals: a step of its flow, not a change of status here
                $this->event($existing, 'source_step', $story['written'], $story['actor'], null, ['from' => $old['source_state'], 'to' => $existing->source_state]);
            }
            if (array_key_exists('product_id', $old) && $existing->product_id) {
                $this->event($existing, 'product_linked', $story['written'], [null, 'Odoo'], null, array_filter(['from' => $old['product_sku'] ?? null, 'to' => $existing->product_sku]));
            }
            $told = array_diff_key($old, array_flip(['status', 'status_at', 'source_meta', 'source_state', 'source_updated_at', 'name_key', 'name_en_key']));
            if ($told) {
                $this->audit->log($this->actor(), ['action' => 'PREQ.UPDATE', 'entityType' => 'ProductRequest', 'entityId' => $existing->id, 'entityNumber' => $existing->number,
                    'oldValue' => self::plain($told), 'newValue' => self::plain(array_intersect_key($values, $told)) + ['origin' => 'Odoo']]);
            }
        }
        $this->pictures($existing, $r, false);

        return null;
    }

    /** The OPS request an Odoo record became. */
    private function row(string $model, int $odooId): ?ProductRequest
    {
        if ($this->rows !== null) {
            return $this->rows["{$model}:{$odooId}"] ?? null;
        }

        return $this->installed ? ProductRequest::where('source_system', self::SYSTEM)->where('source_model', $model)->where('external_ref', (string) $odooId)->first() : null;
    }

    /**
     * The number of the OPS request: Odoo's own reference with its slashes as dashes (NPR/00042 → NPR-00042), which
     * people already know it by; a fallback when Odoo gives none, or one that is taken.
     */
    private function number(OdooRecord $r, ?string $sourceNumber): string
    {
        $own = strtoupper(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', (string) $sourceNumber), '-'));

        return $own === '' || strlen($own) > 40 || ProductRequest::where('number', $own)->exists() ? self::PREFIX[$r->model].'-OD-'.$r->odoo_id : $own;
    }

    /** One line of a request's story that happened in Odoo. @param  array{0:?string, 1:?string}  $by */
    private function event(ProductRequest $request, string $kind, ?DateTimeInterface $at, array $by, ?string $note = null, array $meta = [], ?string $from = null, ?string $to = null): void
    {
        ProductRequestEvent::create(['request_id' => $request->id, 'kind' => $kind, 'from_status' => $from, 'to_status' => $to, 'user_id' => $by[0] ?? null, 'user_name' => $by[1] ?? null,
            'note' => $note, 'meta' => $meta ?: null, 'source_system' => self::SYSTEM, 'at' => $at ?? now()]);
    }

    // ───────────── the product a request became ─────────────

    /**
     * The Odoo product an approved staff request created: the product of exactly that name that was made in the
     * transaction of the approval (the same minute as the request's last write) — or, when the request was written
     * again later, the one product of that name made while the request existed. Never a product that merely looks
     * alike, and never one of two that could both be it.
     */
    private function staffProduct(array $d, string $name, ?Carbon $created, ?Carbon $written): array
    {
        $this->loadTemplates();
        $candidates = $this->templates[$name] ?? $this->templatesFolded[self::fold($name)] ?? [];
        $at = $written?->getTimestamp();
        $from = $created?->getTimestamp();
        if (! $candidates || $at === null) {
            return ['outcome' => 'productNotFound'];
        }
        $pool = array_values(array_filter($candidates, fn (array $c) => $c['at'] !== null && abs($c['at'] - $at) <= self::PRODUCT_WINDOW));
        if (! $pool) {
            $pool = array_values(array_filter($candidates, fn (array $c) => $c['at'] !== null && $from !== null && $c['at'] >= $from - 60 && $c['at'] <= $at + self::PRODUCT_WINDOW));
        }
        if (! $pool) {
            return ['outcome' => 'productNotFound']; // products of that name exist, none made by this request
        }
        if (count($pool) > 1) {
            // made twice in Odoo: the one made by whoever approved, then the one closest to the approval
            $writer = Val::id($d['write_uid'] ?? null);
            $own = array_values(array_filter($pool, fn (array $c) => $writer !== null && $c['uid'] === $writer));
            $pool = $own ?: $pool;
            $nearest = min(array_map(fn (array $c) => abs($c['at'] - $at), $pool));
            $pool = array_values(array_filter($pool, fn (array $c) => abs($c['at'] - $at) === $nearest));
        }
        if (count($pool) > 1) {
            // still two: the one that is an OPS product, if exactly one is
            $inOps = array_values(array_filter($pool, fn (array $c) => $this->opsProductOf($this->variantsOf($c['id'])) !== null));
            if (count($inOps) !== 1) {
                return ['outcome' => 'ambiguous'];
            }
            $pool = $inOps;
        }
        $variants = $this->variantsOf($pool[0]['id']);
        $found = $this->opsProductOf($variants);
        $odoo = ['id' => $found['odooId'] ?? ($variants[0] ?? null), 'templateId' => $pool[0]['id']];

        return $found
            ? ['outcome' => 'restored', 'id' => $found['id'], 'sku' => $found['sku'], 'link' => 'restored', 'ref' => (string) $found['odooId'], 'odoo' => $odoo]
            : ['outcome' => 'productNotInOps', 'ref' => isset($variants[0]) ? (string) $variants[0] : null, 'odoo' => $odoo];
    }

    /** The product a portal request names: the one it was approved into, or — for a request about a catalogue product — that product. */
    private function portalProduct(array $d, string $type, string $status): array
    {
        if ($type === 'existing') {
            $ids = array_filter([Val::id($d['product_id'] ?? null)]);
            $link = 'existing';
        } elseif ($status === Flow::APPROVED) {
            $created = Val::id($d['created_product_id'] ?? null);
            $ids = $created ? [$created] : $this->variantsOf((int) Val::id($d['product_tmpl_id'] ?? null));
            $link = 'restored';
        } else {
            return ['outcome' => null];
        }
        if (! $ids) {
            return ['outcome' => 'productNotFound'];
        }
        $ids = array_values($ids);
        $found = $this->opsProductOf($ids);
        $odoo = ['id' => $found['odooId'] ?? $ids[0], 'name' => Val::label($d['created_product_id'] ?? null) ?? Val::label($d['product_id'] ?? null)];

        return $found
            ? ['outcome' => $link, 'id' => $found['id'], 'sku' => $found['sku'], 'link' => $link, 'ref' => (string) $found['odooId'], 'odoo' => array_filter($odoo)]
            : ['outcome' => 'productNotInOps', 'ref' => (string) $ids[0], 'odoo' => array_filter($odoo)];
    }

    /**
     * The OPS product one of these Odoo products (variants) already is.
     *
     * @param  int[]  $odooIds
     * @return array{id:string, sku:string, odooId:int}|null
     */
    private function opsProductOf(array $odooIds): ?array
    {
        foreach ($odooIds as $odooId) {
            $id = $this->linked('product', $odooId);
            if ($id === null) {
                continue;
            }
            if (! array_key_exists($id, $this->skus) && ! $this->whole) {
                $this->skus[$id] = DB::table('products')->where('id', $id)->value('sku');
            }
            if (($sku = $this->skus[$id] ?? null) !== null) {
                return ['id' => (string) $id, 'sku' => (string) $sku, 'odooId' => (int) $odooId];
            }
        }

        return null;
    }

    /** Odoo's product templates by name — a light read (three values of each) of the copy, once per pass. */
    private function loadTemplates(): void
    {
        if ($this->templates !== null) {
            return;
        }
        $this->templates = $this->templatesFolded = [];
        $rows = DB::table('odoo_records')->where('model', 'product.template')->orderBy('odoo_id')
            ->select(['odoo_id', 'data->name as name', 'data->create_date as created', 'data->create_uid as uid'])->cursor();
        foreach ($rows as $t) {
            $name = is_string($t->name) ? trim($t->name) : '';
            if ($name === '') {
                continue;
            }
            $uid = is_string($t->uid) ? json_decode($t->uid, true) : null;
            $at = is_string($t->created) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $t->created) ? strtotime($t->created.' UTC') : false;
            $entry = ['id' => (int) $t->odoo_id, 'at' => $at === false ? null : $at, 'uid' => Val::id($uid)];
            $this->templates[$name][] = $entry;
            $this->templatesFolded[self::fold($name)][] = $entry;
        }
    }

    /** @return int[] the variants of a template */
    private function variantsOf(int $templateId): array
    {
        if (! $templateId) {
            return [];
        }
        if ($this->variants === null) {
            $this->variants = [];
            foreach (DB::table('odoo_records')->where('model', 'product.product')->orderBy('odoo_id')->select(['odoo_id', 'data->product_tmpl_id as tpl'])->cursor() as $v) {
                $tpl = Val::id(is_string($v->tpl) ? json_decode($v->tpl, true) : $v->tpl);
                if ($tpl) {
                    $this->variants[$tpl][] = (int) $v->odoo_id;
                }
            }
        }

        return $this->variants[$templateId] ?? [];
    }

    /**
     * Requests whose Odoo product is known and was not an OPS product when they were applied: linked once it is one
     * (the master data links products on its own feed). @return int how many were linked
     */
    private function relink(int $max = 200): int
    {
        if (! Schema::hasTable('product_requests') || ! self::owns($this->key())) {
            return 0;
        }
        $waiting = ProductRequest::where('source_system', self::SYSTEM)->whereNull('product_id')->whereNotNull('source_product_ref')
            ->where(fn ($w) => $w->where('status', Flow::APPROVED)->orWhere('request_type', 'existing'))->orderBy('id')->limit($max)->get();
        if ($waiting->isEmpty()) {
            return 0;
        }
        $links = $this->refs->internalIds(self::SYSTEM, 'product', $waiting->pluck('source_product_ref')->map(fn ($ref) => (string) $ref)->all());
        $linked = 0;
        foreach ($waiting as $row) {
            $id = $links[(string) $row->source_product_ref] ?? null;
            $sku = $id ? DB::table('products')->where('id', $id)->value('sku') : null;
            if ($sku === null) {
                continue;
            }
            DB::transaction(function () use ($row, $id, $sku) {
                $row->timestamps = false;
                $row->forceFill(['product_id' => $id, 'product_sku' => $sku, 'product_link' => $row->request_type === 'existing' ? 'existing' : 'restored'])->save();
                $this->event($row, 'product_linked', now(), [null, 'Odoo'], null, ['to' => $sku]);
                $this->audit->log($this->actor(), ['action' => 'PREQ.LINK_PRODUCT', 'entityType' => 'ProductRequest', 'entityId' => $row->id, 'entityNumber' => $row->number, 'field' => 'product',
                    'newValue' => ['product' => $sku, 'origin' => "Odoo product #{$row->source_product_ref} is an OPS product now"]]);
            });
            $linked++;
        }

        return $linked;
    }

    // ───────────── pictures ─────────────

    /**
     * The pictures Odoo holds for a request (`ir.attachment` of the copy, which lists files without their content):
     * each is listed on the OPS request; its content is served from the Odoo file archive once it is kept there.
     * Pictures added in OPS are not looked at.
     */
    private function pictures(?ProductRequest $request, OdooRecord $r, bool $new): void
    {
        if ($r->deleted_at !== null) {
            return; // the record is gone from Odoo: the pictures it had are kept as they were
        }
        $files = $this->filesOf($r->model, $r->odoo_id);
        $known = [];
        if ($request && ! $new) {
            $known = $this->pictureRows !== null
                ? ($this->pictureRows[(string) $request->id] ?? [])
                : ProductRequestPicture::where('request_id', $request->id)->where('source_system', self::SYSTEM)->get()->keyBy(fn (ProductRequestPicture $p) => (string) $p->external_ref)->all();
        }
        foreach ($files as $fileId => $f) {
            $row = $known[(string) $fileId] ?? null;
            $values = ['file_name' => $f['name'], 'mime' => $f['mime'], 'size' => $f['size'], 'source_field' => $f['field']];
            if (! $row) {
                $this->count('picture.created');
                $this->count($this->contentKept((int) $fileId) ? 'picture.contentKept' : 'picture.contentNotArchivedYet');
                if (! $this->dry) {
                    $created = ProductRequestPicture::create($values + ['request_id' => $request->id, 'status' => ProductRequestPicture::ODOO, 'uploaded_by' => $f['by'], 'source_system' => self::SYSTEM,
                        'external_ref' => (string) $fileId, 'created_at' => $f['at'] ?? now()]);
                    if ($this->pictureRows !== null) {
                        $this->pictureRows[(string) $request->id][(string) $fileId] = $created;
                    }
                }

                continue;
            }
            $changed = array_filter($values, fn ($v, $k) => ! self::same($row->getAttribute($k), $v), ARRAY_FILTER_USE_BOTH)
                + ($row->removed_at !== null && $row->removed_by === 'Odoo' ? ['removed_at' => null, 'removed_by' => null, 'remove_reason' => null] : []);
            $this->count($changed ? 'picture.updated' : 'picture.unchanged');
            if ($changed && ! $this->dry) {
                $row->forceFill($changed)->save();
            }
        }
        foreach ($known as $ref => $row) {
            if (! isset($files[(int) $ref]) && $row->removed_at === null) {
                $this->count('picture.removed');
                if (! $this->dry) {
                    $row->forceFill(['removed_at' => now(), 'removed_by' => 'Odoo', 'remove_reason' => 'حُذفت الصورة من أودو'])->save();
                }
            }
        }
    }

    /**
     * The image files Odoo lists for one record: the picture of the record itself (`res_field = product_image`) and
     * the images attached to its conversation. @return array<int, array{name:string, mime:?string, size:int, at:?Carbon, by:?string, field:?string}>
     */
    private function filesOf(string $model, int $odooId): array
    {
        if ($this->whole) {
            if ($this->files === null) {
                $this->files = [];
                foreach (OdooRecord::where('model', 'ir.attachment')->whereIn('data->res_model', $this->models())->whereNull('deleted_at')->orderBy('odoo_id')->get(['odoo_id', 'data']) as $a) {
                    $d = (array) $a->data;
                    if (($id = Val::id($d['res_id'] ?? null)) && is_string($d['res_model'] ?? null) && ($file = self::picture($a->odoo_id, $d))) {
                        $this->files[$d['res_model']][$id][$a->odoo_id] = $file;
                    }
                }
            }

            return $this->files[$model][$odooId] ?? [];
        }
        $out = [];
        foreach (OdooRecord::where('model', 'ir.attachment')->where('data->res_model', $model)->where('data->res_id', (string) $odooId)->whereNull('deleted_at')->orderBy('odoo_id')->get(['odoo_id', 'data']) as $a) {
            if ($file = self::picture($a->odoo_id, (array) $a->data)) {
                $out[$a->odoo_id] = $file;
            }
        }

        return $out;
    }

    /** An attachment as a picture of a request, or null when it is not an image file (a link, a document). */
    private static function picture(int $id, array $d): ?array
    {
        $mime = Val::text($d['mimetype'] ?? null, 150);
        if (($d['type'] ?? 'binary') !== 'binary' || $mime === null || ! str_starts_with($mime, 'image/')) {
            return null;
        }
        $name = Val::text($d['name'] ?? null, 200) ?? "picture-{$id}";
        if (! preg_match('/\.[A-Za-z0-9]{2,5}$/', $name) && isset(self::IMAGE_EXT[$mime])) {
            $name .= '.'.self::IMAGE_EXT[$mime]; // Odoo names the file of a field after the field: "product_image"
        }

        return ['name' => $name, 'mime' => $mime, 'size' => Val::int($d['file_size'] ?? 0), 'at' => Val::at($d['create_date'] ?? null), 'by' => Val::label($d['create_uid'] ?? null),
            'field' => Val::text($d['res_field'] ?? null, 60)];
    }

    /** Does OPS keep the content of this attachment (table `odoo_files`, filled by `scm:odoo-files`)? */
    private function contentKept(int $attachmentId): bool
    {
        if (! (self::$hasFileArchive ??= Schema::hasTable('odoo_files'))) {
            return false;
        }
        if ($this->whole) {
            $this->kept ??= DB::table('odoo_files')->whereIn('res_model', $this->models())->where('status', 'stored')->pluck('attachment_id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();

            return isset($this->kept[$attachmentId]);
        }

        return DB::table('odoo_files')->where('attachment_id', $attachmentId)->where('status', 'stored')->exists();
    }

    // ───────────── people and links ─────────────

    /**
     * The person an Odoo user is here. @return array{0:?string, 1:?string, 2:?string} [OPS user id, name, Odoo login]
     */
    private function person(mixed $user): array
    {
        $id = Val::id($user);
        if (! $id) {
            return [null, null, null];
        }
        if (! isset($this->people[$id])) {
            $record = $this->mirrored('res.users', $id);
            $data = (array) ($record?->data ?? []);
            $login = self::identity($data['login'] ?? null);
            $partner = Val::id($data['partner_id'] ?? null);
            $email = $partner ? self::identity(((array) ($this->mirrored('res.partner', $partner)?->data ?? []))['email'] ?? null) : null;
            $ops = $this->opsUser(array_values(array_unique(array_filter([$login, $email]))), $login);
            $this->people[$id] = [$ops, self::cut(Val::label($user) ?? Val::text($record?->display_name) ?? "Odoo user #{$id}", 250), $login];
            $this->count($ops ? 'people.matched' : 'people.keptByName');
        }

        return $this->people[$id];
    }

    /** The one OPS user who carries one of these identities (e-mail, or username = the Odoo login); nobody when two do. */
    private function opsUser(array $identities, ?string $login): ?string
    {
        if (! $identities) {
            return null;
        }
        if ($this->opsUsers === null) {
            $this->opsUsers = ['email' => [], 'username' => []];
            foreach (DB::table('users')->where('username', 'not like', People::SERVICE_PREFIX.'%')->get(['id', 'username', 'email']) as $u) {
                if ($email = self::identity($u->email)) {
                    $this->opsUsers['email'][$email][] = (string) $u->id;
                }
                if ($username = self::identity($u->username)) {
                    $this->opsUsers['username'][$username][] = (string) $u->id;
                }
            }
        }
        $found = [];
        foreach ($identities as $identity) {
            foreach ($this->opsUsers['email'][$identity] ?? [] as $id) {
                $found[$id] = true;
            }
        }
        foreach ($login !== null ? ($this->opsUsers['username'][$login] ?? []) : [] as $id) {
            $found[$id] = true;
        }

        return count($found) === 1 ? (string) array_key_first($found) : null;
    }

    /**
     * The OPS record an Odoo record of `$entity` is linked to — when that OPS record is still there. Counted once per
     * Odoo record and pass (`<entity>.linked | keptByName`).
     *
     * @param  class-string<Model>  $class
     */
    private function ops(string $entity, ?int $odooId, string $class): ?string
    {
        if (! $odooId) {
            return null;
        }
        if (! array_key_exists($odooId, $this->ops[$entity] ?? [])) {
            $id = $this->linked($entity, $odooId);
            $id = $id !== null && $class::query()->whereKey($id)->exists() ? (string) $id : null;
            $this->ops[$entity][$odooId] = $id;
            $this->count($entity.($id ? '.linked' : '.keptByName'));
        }

        return $this->ops[$entity][$odooId];
    }

    // ───────────── helpers ─────────────

    /** What identifies a person across the two systems: a login or an e-mail, lower-case. */
    private static function identity(mixed $value): ?string
    {
        $s = is_string($value) ? mb_strtolower(trim($value)) : '';

        return $s === '' ? null : $s;
    }

    /** A product name with its spacing and letter case folded (Odoo's names carry double spaces). */
    private static function fold(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    /** A price Odoo holds: nothing when it is zero (a float of Odoo cannot say "not given"), four decimals otherwise. */
    private static function price(mixed $value): ?float
    {
        $n = round(Val::float($value), 4);

        return $n > 0 ? $n : null;
    }

    private static function cut(?string $s, int $max): ?string
    {
        return $s === null ? null : mb_substr($s, 0, $max);
    }

    /** Values worth keeping only: what is empty is left out, and nothing at all is null. */
    private static function meta(array $values): ?array
    {
        $kept = array_filter($values, fn ($v) => $v !== null && $v !== false && $v !== '' && $v !== []);

        return $kept ?: null;
    }

    /** Values as the audit trail prints them (a moment as text). */
    private static function plain(array $values): array
    {
        return array_map(fn ($v) => $v instanceof DateTimeInterface ? $v->format('Y-m-d H:i:s') : $v, $values);
    }

    /** Is what OPS holds the value Odoo has now? (moments to the second, amounts to their decimals, lists whatever their order of keys) */
    private static function same(mixed $current, mixed $new): bool
    {
        if ($current instanceof DateTimeInterface || $new instanceof DateTimeInterface) {
            if ($current === null || $new === null) {
                return false;
            }

            return Carbon::parse($current)->getTimestamp() === Carbon::parse($new)->getTimestamp();
        }
        if (is_array($current) || is_array($new)) {
            return json_encode(self::canon($current ?: null)) === json_encode(self::canon($new ?: null));
        }
        if (is_bool($new)) {
            return $current !== null && (bool) $current === $new;
        }
        if (is_int($new) || is_float($new)) {
            return $current !== null && is_numeric($current) && abs((float) $current - (float) $new) < 0.00005;
        }

        return (string) ($current ?? '') === (string) ($new ?? '');
    }

    private static function canon(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canon(...), $value);
    }
}
