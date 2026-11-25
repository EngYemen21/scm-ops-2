<?php

namespace App\Approvals;

use App\Approvals\Models\ApprovalApprover;
use App\Approvals\Models\ApprovalAttachment;
use App\Approvals\Models\ApprovalCategory;
use App\Approvals\Models\ApprovalCategoryApprover;
use App\Approvals\Models\ApprovalEvent;
use App\Approvals\Models\ApprovalItem;
use App\Approvals\Models\ApprovalRequest;
use App\Approvals\Services\ApprovalFlow;
use App\Approvals\Services\People;
use App\Integration\Odoo\Models\OdooRecord;
use App\Integration\Odoo\OdooMasterData;
use App\Integration\Odoo\Projection\OdooProjection;
use App\Integration\Odoo\Projection\Val;
use App\Integration\Services\ExternalRefs;
use App\Models\BaseModel;
use App\Services\Core\AuditService;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Odoo's Approvals app → the OPS approvals section. Odoo's categories and their approver lists, every request with
 * the decision of each approver, its product lines and the list of its attachments become OPS records
 * (`source_system = 'odoo'`, `external_ref` = the Odoo id), so the section holds the whole history and stays in step
 * while the company still approves in Odoo.
 *
 *   approval.category            → approval_categories            (archived in Odoo = inactive here)
 *   approval.category.approver   → approval_category_approvers
 *   approval.request             → approval_requests              (+ its timeline; its attachments from ir.attachment)
 *   approval.approver            → approval_request_approvers
 *   approval.product.line        → approval_request_items
 *
 * PEOPLE. An Odoo user is the OPS user whose e-mail or username is his Odoo login, or whose e-mail is the e-mail of his
 * Odoo contact — identity only, never a name. Whoever has no OPS user is kept by name with his login, and becomes that
 * user by himself the day an account is opened for him (App\Approvals\Services\People).
 *
 * WHAT ODOO WRITES THAT IS NOT A DECISION. In a category whose approvers decide in sequence, Odoo marks every later
 * line "refused" when one approver refuses. Such a line was written by the refuser, not by its own approver: here it
 * stays undecided (`waiting`), with Odoo's word kept in `source_status` — the timeline tells who refused.
 *
 * WHO OWNS THE RECORDS. While the area follows Odoo (OdooProjection::owns) every value written here is Odoo's and a
 * change in Odoo replaces it; comments and files added in OPS are OPS's and are never touched. Once the area is handed
 * over (the switch is off) a pass only adds what is missing and leaves every existing record as OPS has it.
 *
 * Nobody is notified by a projection: Odoo has told its own users, and a first load is history.
 */
class OdooApprovalsProjection extends OdooProjection
{
    private const REQUEST_STATUS = ['new' => 'draft', 'pending' => 'pending', 'approved' => 'approved', 'refused' => 'refused', 'cancel' => 'cancelled'];

    /** Odoo's "has_…" choices of a category → the field of an OPS request they rule. */
    private const FIELD_OF = [
        'has_amount' => 'amount', 'has_date' => 'date', 'has_period' => 'period', 'has_partner' => 'partner', 'has_reference' => 'reference',
        'has_quantity' => 'quantity', 'has_product' => 'items', 'has_location' => 'location', 'has_payment_method' => 'paymentMethod', 'requirer_document' => 'document',
    ];

    /** In a plan nothing is written: a parent that would be created has no id yet. */
    private const PLANNED = 'planned';

    /** A whole-mirror pass (plan / apply) reads the OPS rows once; the feed works record by record. */
    private bool $whole = false;

    /** Odoo still owns what came from it: changes in Odoo replace the values here. */
    private bool $follows = true;

    /** @var array<class-string, array<string, BaseModel>> whole pass: the OPS rows that came from Odoo, by Odoo id */
    private array $rows = [];

    /** @var array<class-string, array<string, BaseModel>> rows written by the record in hand — remembered when its transaction commits */
    private array $fresh = [];

    /** @var array<class-string, array<string, true>> a plan: the records it would create */
    private array $planned = [];

    /** @var array<class-string, array<string, true>> the records this pass created (Odoo ids) */
    private array $created = [];

    /** @var array<string, true> records this pass has already applied ("model:id") */
    private array $done = [];

    /** @var string[] records marked as done by the record in hand (taken back when it fails) */
    private array $marked = [];

    private int $depth = 0;

    /** @var array<int, array{0:?string, 1:string, 2:?string}> Odoo user id → [OPS user id, name, Odoo login] */
    private array $people = [];

    /** @var array{email: array<string, string[]>, username: array<string, string[]>}|null OPS users by what identifies them */
    private ?array $opsUsers = null;

    /** @var array<int, array<int, array{name:string, mime:?string, size:int, at:?Carbon, by:?string}>>|null request Odoo id → its attachments in the copy */
    private ?array $files = null;

    /** @var array<int, array> values of Odoo's categories by id */
    private array $categoryData = [];

    public function __construct(ExternalRefs $refs, private readonly AuditService $audit)
    {
        parent::__construct($refs);
    }

    public function key(): string
    {
        return 'approvals';
    }

    public function models(): array
    {
        return ['approval.category', 'approval.category.approver', 'approval.request', 'approval.approver', 'approval.product.line'];
    }

    public function plan(): array
    {
        $this->whole = true;

        return parent::plan();
    }

    public function apply(): array
    {
        $this->whole = true;

        return parent::apply();
    }

    public function consume(int $max = 500, float $seconds = 8.0): array
    {
        $this->whole = false;

        return parent::consume($max, $seconds);
    }

    protected function begin(): void
    {
        $this->follows = self::owns($this->key());
        $this->rows = $this->fresh = $this->planned = $this->created = $this->done = $this->people = $this->categoryData = [];
        $this->marked = [];
        $this->depth = 0;
        $this->opsUsers = $this->files = null;
    }

    protected function project(OdooRecord $record): void
    {
        $key = "{$record->model}:{$record->odoo_id}";
        if (isset($this->done[$key])) {
            return; // its parent brought it along in this pass
        }
        $this->done[$key] = true;
        $this->marked[] = $key;
        $work = fn () => match ($record->model) {
            'approval.category' => $this->category($record),
            'approval.category.approver' => $this->categoryApprover($record),
            'approval.request' => $this->request($record),
            'approval.approver' => $this->approver($record),
            'approval.product.line' => $this->item($record),
            default => null,
        };
        $this->depth++;
        try {
            $this->dry ? $work() : DB::transaction($work);
        } catch (Throwable $e) {
            if ($this->depth === 1) {
                // nothing of this record (nor of what it brought along) was written: none of it counts as applied
                foreach ($this->marked as $k) {
                    unset($this->done[$k]);
                }
                $this->marked = $this->fresh = [];
            }
            throw $e;
        } finally {
            $this->depth--;
        }
        if ($this->depth === 0) {
            // committed: a whole pass keeps the new rows with the ones it read at its start
            foreach ($this->whole ? $this->fresh : [] as $class => $rows) {
                if (isset($this->rows[$class])) {
                    $this->rows[$class] = $rows + $this->rows[$class];
                }
            }
            $this->marked = $this->fresh = [];
        }
    }

    // ───────────── categories ─────────────

    private function category(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $existing = $this->row(ApprovalCategory::class, $r->odoo_id);
        if (! $existing && $r->deleted_at !== null) {
            $this->count('category.skippedDeleted');

            return;
        }
        [$ar, $en] = OdooMasterData::names((string) (Val::text($d['name'] ?? null) ?? Val::text($r->display_name) ?? "Odoo #{$r->odoo_id}"));
        $fields = [];
        foreach (self::FIELD_OF as $odoo => $field) {
            $fields[$field] = in_array($d[$odoo] ?? null, ApprovalFlow::LEVELS, true) ? $d[$odoo] : 'no';
        }
        $values = [
            'name_ar' => $ar, 'name_en' => $en, 'description' => Val::text($d['description'] ?? null, 500),
            'active' => $this->alive($r), // archived or deleted in Odoo: no new request of this kind, the old ones keep it
            'sort' => Val::int($d['sequence'] ?? 0),
            'approval_mode' => Val::bool($d['approver_sequence'] ?? false) ? 'sequence' : 'parallel',
            'min_approvals' => max(1, Val::int($d['approval_minimum'] ?? 1)),
            'fields' => ApprovalFlow::fields($fields),
            // what Odoo holds and OPS has no rule for: kept, shown, not acted on
            'source_meta' => self::meta([
                'company' => Val::label($d['company_id'] ?? null), 'sequenceCode' => Val::text($d['sequence_code'] ?? null), 'automatedSequence' => Val::bool($d['automated_sequence'] ?? false) ?: null,
                'managerApproval' => Val::text($d['manager_approval'] ?? null), 'approvalType' => Val::text($d['approval_type'] ?? null),
                'defaultForPurchaseTransfers' => Val::bool($d['is_default_for_purchase_transfers'] ?? false) ?: null, 'deleted' => $r->deleted_at !== null ?: null,
            ]),
        ];
        [$row, $outcome, $old] = $this->store('category', ApprovalCategory::class, $r->odoo_id, $existing, $values,
            // Odoo lets a requester who is one of the approvers approve his own request: its categories keep that
            ['code' => "OD-{$r->odoo_id}", 'allow_self_approval' => true, 'created_by' => 'svc.odoo'], Val::at($d['create_date'] ?? null), Val::at($d['write_date'] ?? $r->write_date));
        if ($this->dry || ! $row || ! in_array($outcome, ['created', 'updated'], true)) {
            return;
        }
        $this->audit->log($this->actor(), ['action' => 'APPROVAL_CATEGORY.'.($outcome === 'created' ? 'CREATE' : 'UPDATE'), 'entityType' => 'ApprovalCategory', 'entityId' => $row->id, 'entityNumber' => $row->code,
            'oldValue' => $outcome === 'updated' ? $old : null, 'newValue' => ($outcome === 'updated' ? array_intersect_key($values, $old) : ['nameAr' => $ar]) + ['origin' => "Odoo approval.category #{$r->odoo_id}"]]);
        if ($outcome === 'created' && ! $this->whole) {
            $this->children('approval.category.approver', 'category_id', $r->odoo_id);
        }
        if ($outcome === 'updated' && ! $this->whole && array_intersect_key($old, ['approval_mode' => 1, 'min_approvals' => 1])) {
            // Odoo reads the rule from the category whenever it looks at a request, and the feed says nothing about the
            // requests when only their category changed: the requests of Odoo follow it here (a whole pass brings each
            // of them in step by itself, so it is not done twice there)
            $followed = ApprovalRequest::where('source_system', self::SYSTEM)->where('category_id', $row->id)
                ->where(fn ($w) => $w->where('approval_mode', '!=', $values['approval_mode'])->orWhere('min_approvals', '!=', $values['min_approvals']))
                ->toBase()->update(['approval_mode' => $values['approval_mode'], 'min_approvals' => $values['min_approvals']]);
            for ($i = 0; $i < $followed; $i++) {
                $this->count('request.ruleFollowed');
            }
        }
    }

    private function categoryApprover(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $gone = $r->deleted_at !== null;
        $categoryId = $gone ? null : $this->parent(ApprovalCategory::class, 'approval.category', Val::id($d['category_id'] ?? null));
        $existing = $this->row(ApprovalCategoryApprover::class, $r->odoo_id);
        if ($gone) {
            // the list of who approves is a setting, not a record of something that happened: a line Odoo dropped is dropped
            $this->drop('categoryApprover', $existing);

            return;
        }
        if ($categoryId === null) {
            $this->count('categoryApprover.skippedNoCategory', "approval.category.approver #{$r->odoo_id}");

            return;
        }
        if ($this->leftToOps($existing, ApprovalCategory::class, Val::id($d['category_id'] ?? null))) {
            $this->count('categoryApprover.kept');

            return;
        }
        [$userId, $name, $login] = $this->person($d['user_id'] ?? null);
        $this->store('categoryApprover', ApprovalCategoryApprover::class, $r->odoo_id, $existing, [
            'category_id' => $categoryId, 'user_id' => $userId, 'user_name' => $name, 'user_login' => $login,
            'sequence' => Val::int($d['sequence'] ?? 10), 'required' => Val::bool($d['required'] ?? false),
        ], [], Val::at($d['create_date'] ?? null));
    }

    // ───────────── requests ─────────────

    private function request(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $existing = $this->row(ApprovalRequest::class, $r->odoo_id);
        $gone = $r->deleted_at !== null;
        if (! $existing && $gone) {
            $this->count('request.skippedDeleted');

            return;
        }
        $categoryOdooId = Val::id($d['category_id'] ?? null);
        $categoryId = $this->parent(ApprovalCategory::class, 'approval.category', $categoryOdooId) ?? $existing?->category_id
            ?? throw new RuntimeException("its category (approval.category #{$categoryOdooId}) is not in OPS");
        $rule = $this->categoryData[$categoryOdooId] ??= (array) ($this->mirrored('approval.category', $categoryOdooId)?->data ?? []);
        // a request deleted in Odoo is not deleted here: it is history, closed
        $status = $gone ? 'cancelled' : (self::REQUEST_STATUS[$d['request_status'] ?? 'new'] ?? 'draft');
        [$requesterId, $requesterName, $requesterLogin] = $this->person($d['request_owner_id'] ?? null);
        [$creatorId, $creatorName] = $this->person($d['create_uid'] ?? $d['request_owner_id'] ?? null);
        [$writerId, $writerName] = $this->person($d['write_uid'] ?? null);
        $written = Val::at($d['write_date'] ?? $r->write_date);
        $amount = round(Val::float($d['amount'] ?? 0), 2);
        $quantity = round(Val::float($d['quantity'] ?? 0), 3);
        $po = Val::id($d['purchase_order_id'] ?? null);
        $values = [
            'title' => Val::text($d['name'] ?? null) ?? Val::text($r->display_name) ?? "طلب أودو #{$r->odoo_id}",
            'category_id' => $categoryId, 'status' => $status,
            'requester_id' => $requesterId, 'requester_name' => $requesterName, 'requester_login' => $requesterLogin, 'created_by_id' => $creatorId, 'created_by' => $creatorName,
            'amount' => $amount != 0.0 ? $amount : null, 'quantity' => $quantity != 0.0 ? $quantity : null,
            'request_date' => Val::at($d['date'] ?? null)?->toDateString(), 'period_start' => Val::at($d['date_start'] ?? null), 'period_end' => Val::at($d['date_end'] ?? null),
            'location' => Val::text($d['location'] ?? null), 'reference' => Val::text($d['reference'] ?? null, 120),
            ...$this->partner($d),
            'reason' => Val::plain($d['reason'] ?? null, 8000),
            // Odoo reads the rule from the category whenever it looks; the request carries it here
            'approval_mode' => Val::bool($rule['approver_sequence'] ?? false) ? 'sequence' : 'parallel', 'min_approvals' => max(1, Val::int($rule['approval_minimum'] ?? 1)),
            'submitted_at' => Val::at($d['date_confirmed'] ?? null),
            // Odoo keeps no date of the decision: the request is written for the last time when its status settles
            'decided_at' => in_array($status, ['approved', 'refused'], true) ? $written : null,
            'cancelled_at' => $status === 'cancelled' ? $written : null,
            'cancelled_by' => $status === 'cancelled' ? ($gone ? 'Odoo' : $writerName) : null,
            'cancel_reason' => $gone ? 'حُذف الطلب من أودو' : null,
            'source_meta' => self::meta([
                'company' => Val::label($d['company_id'] ?? null), 'status' => Val::text($d['request_status'] ?? null),
                'purchaseOrder' => $po ? ['id' => $po, 'name' => Val::label($d['purchase_order_id'])] : null,
                'partnerId' => Val::id($d['partner_id'] ?? null), 'partnerBankId' => Val::id($d['partner_bank_id'] ?? null),
                'mainAttachmentId' => Val::id($d['message_main_attachment_id'] ?? null),
                'writtenAt' => Val::text($d['write_date'] ?? $r->write_date), 'writtenBy' => $writerName, 'deleted' => $gone ?: null,
            ]),
        ];
        [$row, $outcome, $old] = $this->store('request', ApprovalRequest::class, $r->odoo_id, $existing, $values,
            ['number' => 'APR-OD-'.str_pad((string) $r->odoo_id, 5, '0', STR_PAD_LEFT), 'currency' => 'SAR'], Val::at($d['create_date'] ?? null) ?? $written, $written);
        if ($outcome !== 'kept') {
            $this->attachments($row, $r->odoo_id, $outcome === 'created');
        }
        if ($this->dry || ! $row) {
            return;
        }
        $writer = [$writerId, $writerName];
        if ($outcome === 'created') {
            $this->event($row, 'created', $row->created_at, [$requesterId, $requesterName]);
            if ($values['submitted_at'] && $status !== 'draft') {
                $this->event($row, 'submitted', $values['submitted_at'], [$requesterId, $requesterName]);
            }
            if ($status === 'cancelled') {
                $this->event($row, 'cancelled', $written, $gone ? [null, 'Odoo'] : $writer, $values['cancel_reason']);
            }
            $this->audit->log($this->actor(), ['action' => 'APPROVAL.IMPORT', 'entityType' => 'ApprovalRequest', 'entityId' => $row->id, 'entityNumber' => $row->number,
                'newValue' => ['status' => $status, 'origin' => "Odoo approval.request #{$r->odoo_id}"]]);
            if (! $this->whole) {
                $this->children('approval.approver', 'request_id', $r->odoo_id);
                $this->children('approval.product.line', 'approval_request_id', $r->odoo_id);
            }
        } elseif ($outcome === 'updated') {
            if (array_key_exists('status', $old)) {
                $kind = match (true) {
                    $status === 'pending' && $old['status'] === 'draft' => 'submitted',
                    $status === 'cancelled' => 'cancelled',
                    $status === 'draft' => 'reopened',
                    default => null, // approved / refused: the approvers' own lines tell it
                };
                if ($kind) {
                    $this->event($row, $kind, $kind === 'submitted' ? ($values['submitted_at'] ?? $written) : $written, $gone ? [null, 'Odoo'] : $writer, $values['cancel_reason'], ['from' => $old['status']]);
                }
                $this->audit->status($this->actor(), 'ApprovalRequest', $row->id, $row->number, $old['status'], $status, 'من أودو');
            }
            $told = array_diff_key($old, ['source_meta' => 1, 'status' => 1]);
            if ($told) {
                $this->audit->log($this->actor(), ['action' => 'APPROVAL.UPDATE', 'entityType' => 'ApprovalRequest', 'entityId' => $row->id, 'entityNumber' => $row->number,
                    'oldValue' => $told, 'newValue' => array_intersect_key($values, $told) + ['origin' => 'Odoo']]);
            }
        }
    }

    /** The OPS customer or supplier an Odoo partner is (the links of the master data), otherwise the partner by name. */
    private function partner(array $d): array
    {
        $id = Val::id($d['partner_id'] ?? null);
        if (! $id) {
            return ['partner_type' => null, 'partner_id' => null, 'partner_name' => null];
        }
        $supplier = $this->linked('supplier', $id);
        $customer = $this->linked('customer', $id);
        // a partner that is both: a request about a purchase order speaks to the supplier, any other to the customer
        $type = $supplier && $customer ? (Val::id($d['purchase_order_id'] ?? null) ? 'supplier' : 'customer') : ($supplier ? 'supplier' : ($customer ? 'customer' : 'other'));

        return ['partner_type' => $type, 'partner_id' => $type === 'supplier' ? $supplier : ($type === 'customer' ? $customer : null),
            'partner_name' => Val::label($d['partner_id']) ?? "Odoo partner #{$id}"];
    }

    private function approver(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $gone = $r->deleted_at !== null;
        $requestId = $gone ? null : $this->parent(ApprovalRequest::class, 'approval.request', Val::id($d['request_id'] ?? null));
        // looked up after the parent: a request the feed had not brought yet has just brought its lines with it
        $existing = $this->row(ApprovalApprover::class, $r->odoo_id);
        if ($gone) {
            if (! $existing) {
                $this->count('approver.skippedDeleted');

                return;
            }
            // taken off the request in Odoo: the line stays here, out of the decision
            $this->store('approver', ApprovalApprover::class, $r->odoo_id, $existing, ['status' => 'removed', 'source_status' => 'deleted']);

            return;
        }
        if ($requestId === null) {
            $this->count('approver.skippedNoRequest', "approval.approver #{$r->odoo_id}");

            return;
        }
        if ($this->leftToOps($existing, ApprovalRequest::class, Val::id($d['request_id'] ?? null))) {
            $this->count('approver.kept');

            return;
        }
        [$userId, $name, $login] = $this->person($d['user_id'] ?? null);
        $said = Val::text($d['status'] ?? null, 12) ?? 'new';
        $own = Val::id($d['write_uid'] ?? null) === Val::id($d['user_id'] ?? null);
        $status = match ($said) {
            'approved', 'pending', 'waiting', 'new' => $said,
            'refused' => $own ? 'refused' : 'waiting', // written by the one who refused before him, not by himself
            'cancel' => 'cancelled',
            default => 'new',
        };
        $written = Val::at($d['write_date'] ?? $r->write_date);
        [$row, $outcome, $old] = $this->store('approver', ApprovalApprover::class, $r->odoo_id, $existing, [
            'request_id' => $requestId, 'user_id' => $userId, 'user_name' => $name, 'user_login' => $login,
            'sequence' => Val::int($d['sequence'] ?? 10), 'required' => Val::bool($d['required'] ?? false), 'status' => $status, 'source_status' => $said,
            // Odoo keeps no date of a decision: the line is written for the last time when its approver decides
            'decided_at' => in_array($status, ['approved', 'refused'], true) ? $written : null,
        ], ['origin' => 'odoo'], Val::at($d['create_date'] ?? null) ?? $written, $written);
        if ($this->dry || ! $row || ! in_array($outcome, ['created', 'updated'], true)) {
            return;
        }
        $decided = fn (?string $s) => in_array($s, ['approved', 'refused'], true);
        $was = $outcome === 'updated' ? ($old['status'] ?? $status) : null;
        $request = null;
        $told = null;
        if ($decided($status) && $was !== $status) {
            $told = $status;
        } elseif ($outcome === 'updated' && $decided($was) && ! $decided($status)) {
            $told = 'withdrawn';
        }
        if ($told) {
            $request = ApprovalRequest::find($requestId);
            $this->event($request, $told, $written, [$userId, $name], null, $told === 'withdrawn' ? ['was' => $was] : []);
            if ($outcome === 'updated') {
                // a decision taken in Odoo while OPS was watching: it belongs in the audit trail like one taken here
                $this->audit->log($this->actor(), ['action' => 'APPROVAL.'.['approved' => 'APPROVE', 'refused' => 'REFUSE', 'withdrawn' => 'WITHDRAW'][$told], 'entityType' => 'ApprovalRequest',
                    'entityId' => $request->id, 'entityNumber' => $request->number, 'oldValue' => ['decision' => $was], 'newValue' => ['approver' => $name, 'origin' => 'Odoo']]);
            }
        }
        // a refusal Odoo wrote on this line in somebody else's name. The one who refused normally has a line of his own
        // that still says so, and that line tells it. When he has none — or took his own refusal back, which leaves
        // these lines (and the request) refused in Odoo — it is told once, under the name of who wrote it
        if ($said === 'refused' && ! $own && ($outcome === 'created' || array_key_exists('source_status', $old))
            && ! ApprovalEvent::where('request_id', $requestId)->where('kind', 'refused')->where('source_system', self::SYSTEM)->exists()) {
            $writer = Val::id($d['write_uid'] ?? null);
            $ownRefusal = OdooRecord::where('model', 'approval.approver')->whereJsonContains('data->request_id', Val::id($d['request_id'] ?? null))->whereNull('deleted_at')->get(['data'])
                ->contains(fn (OdooRecord $line) => Val::id($line->data['user_id'] ?? null) === $writer && Val::id($line->data['write_uid'] ?? null) === $writer && ($line->data['status'] ?? null) === 'refused');
            if (! $ownRefusal) {
                [$writerId, $writerName] = $this->person($d['write_uid'] ?? null);
                $this->event($request ?? ApprovalRequest::find($requestId), 'refused', $written, [$writerId, $writerName], null, ['recordedOn' => $name]);
            }
        }
    }

    private function item(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $gone = $r->deleted_at !== null;
        $requestId = $gone ? null : $this->parent(ApprovalRequest::class, 'approval.request', Val::id($d['approval_request_id'] ?? null));
        $existing = $this->row(ApprovalItem::class, $r->odoo_id);
        if ($gone) {
            $this->drop('item', $existing); // what a request asks for is its content: a line Odoo dropped is not asked for any more

            return;
        }
        if ($requestId === null) {
            $this->count('item.skippedNoRequest', "approval.product.line #{$r->odoo_id}");

            return;
        }
        if ($this->leftToOps($existing, ApprovalRequest::class, Val::id($d['approval_request_id'] ?? null))) {
            $this->count('item.kept');

            return;
        }
        $product = Val::id($d['product_id'] ?? null);
        $this->store('item', ApprovalItem::class, $r->odoo_id, $existing, [
            'request_id' => $requestId, 'product_id' => $this->linked('product', $product),
            'description' => Val::text($d['description'] ?? null, 500) ?? Val::label($d['product_id'] ?? null) ?? "بند #{$r->odoo_id}",
            'quantity' => round(Val::float($d['quantity'] ?? 0), 3), 'uom' => Val::label($d['product_uom_id'] ?? null), 'warehouse' => Val::label($d['warehouse_id'] ?? null),
            'source_meta' => self::meta([
                'product' => $product ? ['id' => $product, 'name' => Val::label($d['product_id'])] : null, 'uomId' => Val::id($d['product_uom_id'] ?? null),
                'warehouseId' => Val::id($d['warehouse_id'] ?? null), 'purchaseOrderLineId' => Val::id($d['purchase_order_line_id'] ?? null),
            ]),
        ], $existing || $this->dry ? [] : ['line_no' => ((int) ApprovalItem::where('request_id', $requestId)->max('line_no')) + 1]);
    }

    /**
     * The files Odoo holds for a request (`ir.attachment` of the copy, which lists them without their content): each is
     * listed on the OPS request; its content is served from the Odoo file archive once it is kept there. Files added in
     * OPS are not looked at.
     */
    private function attachments(?ApprovalRequest $request, int $odooId, bool $new): void
    {
        $files = $this->files()[$odooId] ?? [];
        $known = $request && ! $new ? ApprovalAttachment::where('request_id', $request->id)->where('source_system', self::SYSTEM)->get()->keyBy('external_ref') : collect();
        foreach ($files as $fileId => $f) {
            $row = $known->get((string) $fileId);
            $values = ['file_name' => $f['name'], 'mime' => $f['mime'], 'size' => $f['size']];
            if (! $row) {
                $this->count('attachment.created');
                if (! $this->dry) {
                    ApprovalAttachment::create($values + ['request_id' => $request->id, 'status' => ApprovalAttachment::ODOO, 'uploaded_by' => $f['by'], 'source_system' => self::SYSTEM,
                        'external_ref' => (string) $fileId, 'created_at' => $f['at'] ?? now()]);
                }

                continue;
            }
            $changed = array_filter($values, fn ($v, $k) => ! self::same($row->getAttribute($k), $v), ARRAY_FILTER_USE_BOTH) + ($row->removed_at !== null ? ['removed_at' => null, 'removed_by' => null, 'remove_reason' => null] : []);
            $this->count($changed ? 'attachment.updated' : 'attachment.unchanged');
            if ($changed && ! $this->dry) {
                $row->forceFill($changed)->save();
            }
        }
        foreach ($known as $ref => $row) {
            if (! isset($files[(int) $ref]) && $row->removed_at === null) {
                $this->count('attachment.removed');
                if (! $this->dry) {
                    $row->forceFill(['removed_at' => now(), 'removed_by' => 'Odoo', 'remove_reason' => 'حُذف المرفق من أودو'])->save();
                }
            }
        }
    }

    /** The attachments of approval requests in the copy, read once per pass (one pass over the list of attachments). */
    private function files(): array
    {
        if ($this->files === null) {
            $this->files = [];
            foreach (OdooRecord::where('model', 'ir.attachment')->where('data->res_model', 'approval.request')->whereNull('deleted_at')->orderBy('odoo_id')->get(['odoo_id', 'data']) as $a) {
                $d = (array) $a->data;
                $requestId = Val::id($d['res_id'] ?? null);
                // a file that fills a field of the record, or a link, is not an attachment people added
                if (! $requestId || ! empty($d['res_field']) || ($d['type'] ?? 'binary') !== 'binary') {
                    continue;
                }
                $this->files[$requestId][$a->odoo_id] = ['name' => Val::text($d['name'] ?? null) ?? "attachment-{$a->odoo_id}", 'mime' => Val::text($d['mimetype'] ?? null, 150),
                    'size' => Val::int($d['file_size'] ?? 0), 'at' => Val::at($d['create_date'] ?? null), 'by' => Val::label($d['create_uid'] ?? null)];
            }
        }

        return $this->files;
    }

    // ───────────── people ─────────────

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
            $login = People::key($data['login'] ?? null);
            $partner = Val::id($data['partner_id'] ?? null);
            $email = $partner ? People::key(((array) ($this->mirrored('res.partner', $partner)?->data ?? []))['email'] ?? null) : null;
            $ops = $this->opsUser(array_values(array_unique(array_filter([$login, $email]))), $login);
            $this->people[$id] = [$ops, Val::label($user) ?? Val::text($record?->display_name) ?? "Odoo user #{$id}", $login];
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
            foreach (DB::table('users')->where('username', 'not like', 'svc.%')->get(['id', 'username', 'email']) as $u) {
                if ($email = People::key($u->email)) {
                    $this->opsUsers['email'][$email][] = (string) $u->id;
                }
                if ($username = People::key($u->username)) {
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

    // ───────────── the mechanics ─────────────

    /** The OPS row an Odoo record became. */
    private function row(string $class, int|string|null $odooId): ?BaseModel
    {
        if ($odooId === null || $odooId === '' || $odooId === 0) {
            return null;
        }
        if (isset($this->fresh[$class][(string) $odooId])) {
            return $this->fresh[$class][(string) $odooId];
        }
        if (! $this->whole) {
            return $class::where('source_system', self::SYSTEM)->where('external_ref', (string) $odooId)->first();
        }
        $this->rows[$class] ??= $class::where('source_system', self::SYSTEM)->get()->keyBy('external_ref')->all();

        return $this->rows[$class][(string) $odooId] ?? null;
    }

    /**
     * The OPS id of the record a child points at. The feed may bring a child before its parent: the parent is then
     * applied first, from the copy. In a plan a parent that would be created answers PLANNED.
     */
    private function parent(string $class, string $model, ?int $odooId): ?string
    {
        if (! $odooId) {
            return null;
        }
        if ($row = $this->row($class, $odooId)) {
            return (string) $row->id;
        }
        if ($this->dry) {
            return isset($this->planned[$class][(string) $odooId]) ? self::PLANNED : null;
        }
        if ($this->whole) {
            return null; // parents come first in a whole pass: one that is not there could not be applied
        }
        $record = $this->mirrored($model, $odooId);
        if (! $record || $record->deleted_at !== null) {
            return null;
        }
        $this->project($record);

        return ($row = $this->row($class, $odooId)) ? (string) $row->id : null;
    }

    /** The records of the copy that point at this parent, applied now (the feed: a new parent brings its children along). */
    private function children(string $model, string $field, int $parentOdooId): void
    {
        foreach (OdooRecord::where('model', $model)->whereJsonContains("data->{$field}", $parentOdooId)->orderBy('odoo_id')->get() as $child) {
            $this->project($child);
        }
    }

    /**
     * Creates the OPS row of an Odoo record or brings it in step; counts what it did (`<entity>.created | updated |
     * unchanged | kept`). In a plan it only counts.
     *
     * @param  class-string<BaseModel>  $class
     * @return array{0:?BaseModel, 1:string, 2:array<string, mixed>} the row, the outcome, and the old values of what changed
     */
    private function store(string $entity, string $class, int $odooId, ?BaseModel $existing, array $values, array $onCreate = [], ?Carbon $createdAt = null, ?Carbon $updatedAt = null): array
    {
        if (! $existing) {
            $this->count("{$entity}.created");
            $this->created[$class][(string) $odooId] = true;
            if ($this->dry) {
                $this->planned[$class][(string) $odooId] = true;

                return [null, 'created', []];
            }
            $row = new $class;
            $dated = $row->usesTimestamps();
            $row->timestamps = false; // the dates are Odoo's, not the moment of the copy
            $row->forceFill($values + $onCreate + ['source_system' => self::SYSTEM, 'external_ref' => (string) $odooId]
                + ($dated ? ['created_at' => $createdAt ?? now()] : [])
                + ($dated && $row->getUpdatedAtColumn() ? ['updated_at' => $updatedAt ?? $createdAt ?? now()] : []))->save();
            $this->fresh[$class][(string) $odooId] = $row;

            return [$row, 'created', []];
        }
        if (! $this->follows) {
            $this->count("{$entity}.kept"); // handed over: the record is OPS's now

            return [$existing, 'kept', []];
        }
        $old = [];
        foreach ($values as $column => $value) {
            if (! self::same($existing->getAttribute($column), $value)) {
                $old[$column] = $existing->getAttribute($column);
            }
        }
        if (! $old) {
            $this->count("{$entity}.unchanged");

            return [$existing, 'unchanged', []];
        }
        $this->count("{$entity}.updated");
        if (! $this->dry) {
            $stamp = $updatedAt && array_key_exists($existing->getUpdatedAtColumn() ?? '-', $existing->getAttributes());
            $existing->timestamps = false;
            $existing->forceFill(array_intersect_key($values, $old) + ($stamp ? ['updated_at' => $updatedAt] : []))->save();
        }

        return [$existing, 'updated', $old];
    }

    /**
     * After the handover a pass adds whole records OPS does not have — never a line under a request (or a category)
     * OPS already holds: that record, with what is on it, is OPS's now, and a line missing from it was taken off here.
     */
    private function leftToOps(?BaseModel $existing, string $parentClass, ?int $parentOdooId): bool
    {
        return ! $this->follows && ! $existing && ! isset($this->created[$parentClass][(string) $parentOdooId]);
    }

    /** A row Odoo no longer has, of a kind that is a setting or content — not a record of something that happened. */
    private function drop(string $entity, ?BaseModel $existing): void
    {
        if (! $existing) {
            $this->count("{$entity}.skippedDeleted");

            return;
        }
        if (! $this->follows) {
            $this->count("{$entity}.kept");

            return;
        }
        $this->count("{$entity}.removed");
        if (! $this->dry) {
            $existing->delete();
            unset($this->rows[$existing::class][(string) $existing->external_ref]);
        }
    }

    /** One line of a request's timeline that happened in Odoo. @param  array{0:?string, 1:?string}  $by */
    private function event(ApprovalRequest $request, string $kind, ?Carbon $at, array $by, ?string $note = null, array $meta = []): void
    {
        ApprovalEvent::create(['request_id' => $request->id, 'kind' => $kind, 'user_id' => $by[0] ?? null, 'user_name' => $by[1] ?? null, 'note' => $note, 'meta' => $meta ?: null,
            'source_system' => self::SYSTEM, 'at' => $at ?? now()]);
    }

    /** Values worth keeping only: what is empty is left out, and nothing at all is null. */
    private static function meta(array $values): ?array
    {
        $kept = array_filter($values, fn ($v) => $v !== null && $v !== false && $v !== '' && $v !== []);

        return $kept ?: null;
    }

    /** Is what OPS holds the value Odoo has now? (dates to the second, amounts to their decimals, lists whatever their order of keys) */
    private static function same(mixed $current, mixed $new): bool
    {
        if ($current instanceof DateTimeInterface || $new instanceof DateTimeInterface) {
            if ($current === null || $new === null) {
                return false;
            }

            // a day (`Y-m-d`) against a date column, otherwise two moments
            return is_string($new) ? Carbon::parse($current)->toDateString() === $new : Carbon::parse($current)->getTimestamp() === Carbon::parse($new)->getTimestamp();
        }
        if (is_array($current) || is_array($new)) {
            return json_encode(self::canon($current ?: null)) === json_encode(self::canon($new ?: null));
        }
        if (is_bool($new)) {
            return $current !== null && (bool) $current === $new;
        }
        if (is_int($new) || is_float($new)) {
            return $current !== null && is_numeric($current) && abs((float) $current - (float) $new) < 0.0005;
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
