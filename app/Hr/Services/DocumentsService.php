<?php

namespace App\Hr\Services;

use App\Hr\Models\Document;
use App\Hr\Models\Employee;
use App\Hr\Models\HrAlert;
use App\Hr\Models\HrFile;
use App\Hr\Support\Hr;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The register of employee documents: residence permits, passports, licences, health certificates… each with its
 * expiry date, its scan, and the alerts 60 / 30 / 7 days before and after (AlertsService).
 *
 * WHO. The register is HR's work: reading needs `hr.manage` (or `hr.private`), writing `hr.manage`. A PRIVATE document
 * — every identity paper, and any other document a person marks so — belongs to the personal file: it is listed,
 * read, written and alerted about only with `hr.private` on top. A caller without it never learns such a row exists.
 *
 * Nothing is deleted: a paper that was entered by mistake is voided with a reason, one that was renewed is kept as
 * `replaced` and points at its renewal.
 */
class DocumentsService
{
    private const FIELDS = ['title' => 'title', 'number' => 'number', 'notes' => 'notes'];

    public function __construct(private readonly AuditService $audit, private readonly FilesService $files, private readonly AlertsService $alerts) {}

    public static function canSee(AuthUser $user, Document $d): bool
    {
        return $d->is_private ? Hr::seesPrivate($user) : Hr::seesDocuments($user);
    }

    /** @param  array{employee?:?string, kind?:?string, status?:?string, due?:?string, department?:?string, order?:?string}  $f */
    public function list(AuthUser $user, Paging $page, array $f): array
    {
        $q = $this->visible($user)->with('employee:id,code,name_ar,name_en,job_title');
        $status = $f['status'] ?? 'active';
        if ($status !== 'all') {
            $q->whereIn('status', array_intersect(explode(',', (string) $status), Document::STATUSES) ?: ['active']);
        }
        if (! empty($f['employee'])) {
            $q->where('employee_id', $f['employee']);
        }
        if (! empty($f['kind'])) {
            $q->whereIn('kind', explode(',', (string) $f['kind']));
        }
        if (! empty($f['department'])) {
            $q->whereIn('employee_id', fn ($s) => $s->select('id')->from('hr_employees')->where('department_id', $f['department']));
        }
        $day = fn (int $n) => Carbon::today()->addDays($n)->toDateString();
        match ($f['due'] ?? '') {
            'expired' => $q->whereDate('expiry_date', '<', $day(0)),
            'd7' => $q->whereDate('expiry_date', '>=', $day(0))->whereDate('expiry_date', '<=', $day(7)),
            'd30' => $q->whereDate('expiry_date', '>', $day(7))->whereDate('expiry_date', '<=', $day(30)),
            'd60' => $q->whereDate('expiry_date', '>', $day(30))->whereDate('expiry_date', '<=', $day(60)),
            'due' => $q->whereDate('expiry_date', '<=', $day(60)),
            'valid' => $q->whereDate('expiry_date', '>', $day(60)),
            'none' => $q->whereNull('expiry_date'),
            default => null,
        };
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('number', 'like', $like)
                ->orWhereIn('employee_id', fn ($s) => $s->select('id')->from('hr_employees')->where(fn ($x) => $x->where('code', 'like', $like)->orWhere('name_ar', 'like', $like)->orWhere('name_en', 'like', $like))));
        }
        $order = ($f['order'] ?? null) === 'desc' ? 'desc' : 'asc';
        match ($page->sort) {
            'created' => $q->orderBy('created_at', $order),
            'kind' => $q->orderBy('kind', $order),
            default => $q->orderByRaw('expiry_date IS NULL')->orderBy('expiry_date', $order), // what expires first comes first; papers without a date last
        };
        $q->orderBy('id', $order);
        $total = (clone $q)->toBase()->getCountForPagination();
        $rows = $q->forPage($page->page, $page->pageSize)->get();
        $files = $this->files->shapedFor('document', $rows->pluck('id')->all());

        return $page->wrap($rows->map(fn (Document $d) => $this->shape($d, $files[$d->id] ?? []))->all(), $total);
    }

    public function get(AuthUser $user, string $id): array
    {
        $d = $this->find($user, $id)->load('employee:id,code,name_ar,name_en,job_title');

        return $this->shape($d, $this->files->shapedFor('document', [$d->id])[$d->id] ?? []);
    }

    /** The documents of one employee the person may see (the employee's card). */
    public function forEmployee(AuthUser $user, string $employeeId): array
    {
        $rows = $this->visible($user)->where('employee_id', $employeeId)->whereIn('status', ['active', 'replaced'])->orderByRaw("status = 'replaced'")->orderByRaw('expiry_date IS NULL')->orderBy('expiry_date')->orderBy('id')->get();
        $files = $this->files->shapedFor('document', $rows->pluck('id')->all());

        return $rows->map(fn (Document $d) => $this->shape($d, $files[$d->id] ?? []))->all();
    }

    public function create(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            $e = Employee::where('id', $dto['employee'])->orWhere('code', $dto['employee'])->first() ?? throw AppError::notFound('EMPLOYEE_NOT_FOUND', 'الموظف غير موجود', 'Employee not found');
            $kind = (string) $dto['kind'];
            $private = Document::alwaysPrivate($kind) || filter_var($dto['private'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $this->assertWrites($user, $private);
            $values = $this->values($dto);
            $this->assertDates($values['issue_date'] ?? null, $values['expiry_date'] ?? null);
            $d = Document::create(['employee_id' => $e->id, 'kind' => $kind, 'is_private' => $private, 'status' => 'active', 'created_by' => $user->username] + $values);
            if (! empty($dto['file'])) {
                $this->files->store($user, 'document', $d->id, $dto['file']);
            }
            $this->log($user, 'HR.DOCUMENT.CREATE', $d, $e, ['kind' => $kind, 'private' => $private]);
            $this->alerts->refreshEmployee($e->id);

            return $this->get($user, $d->id);
        });
    }

    public function update(AuthUser $user, string $id, array $dto): array
    {
        return DB::transaction(function () use ($user, $id, $dto) {
            $d = $this->lock($user, $id);
            $this->assertActive($d);
            $new = $this->values($dto);
            if (array_key_exists('kind', $dto) && $dto['kind'] !== null) {
                $new['kind'] = (string) $dto['kind'];
            }
            $kind = $new['kind'] ?? $d->kind;
            if (array_key_exists('private', $dto) || isset($new['kind'])) {
                $new['is_private'] = Document::alwaysPrivate($kind) || (array_key_exists('private', $dto) ? filter_var($dto['private'], FILTER_VALIDATE_BOOLEAN) : (bool) $d->is_private);
            }
            $this->assertWrites($user, (bool) $d->is_private || (bool) ($new['is_private'] ?? false));
            $changes = Hr::diff($d, $new);
            $this->assertDates(array_key_exists('issue_date', $changes) ? $changes['issue_date'] : Hr::day($d->issue_date), array_key_exists('expiry_date', $changes) ? $changes['expiry_date'] : Hr::day($d->expiry_date));
            if ($changes) {
                $d->update($changes);
                $this->log($user, 'HR.DOCUMENT.UPDATE', $d, null, ['fields' => array_keys($changes)]);
                $this->alerts->refreshEmployee($d->employee_id);
            }

            return $this->get($user, $d->id);
        });
    }

    /** The paper was renewed: a new document takes its place (new dates, number, scan); the old one stays as `replaced`. */
    public function renew(AuthUser $user, string $id, array $dto): array
    {
        return DB::transaction(function () use ($user, $id, $dto) {
            $old = $this->lock($user, $id);
            $this->assertActive($old);
            $this->assertWrites($user, (bool) $old->is_private);
            $values = $this->values($dto);
            if (empty($values['expiry_date'])) {
                throw AppError::validation('DOCUMENT_EXPIRY', 'تاريخ الانتهاء الجديد مطلوب للتجديد', 'The new expiry date is required to renew');
            }
            if ($old->expiry_date && $values['expiry_date'] <= Hr::day($old->expiry_date)) {
                throw AppError::validation('DOCUMENT_EXPIRY', 'تاريخ الانتهاء الجديد يجب أن يكون بعد تاريخ الوثيقة الحالية', 'The new expiry date must be later than the current one');
            }
            $this->assertDates($values['issue_date'] ?? null, $values['expiry_date']);
            $new = Document::create(['employee_id' => $old->employee_id, 'kind' => $old->kind, 'is_private' => $old->is_private, 'status' => 'active', 'created_by' => $user->username,
                'title' => $values['title'] ?? $old->title, 'number' => $values['number'] ?? $old->number, 'issue_date' => $values['issue_date'] ?? null, 'expiry_date' => $values['expiry_date'], 'notes' => $values['notes'] ?? null]);
            $old->update(['status' => 'replaced', 'replaced_by_id' => $new->id]);
            if (! empty($dto['file'])) {
                $this->files->store($user, 'document', $new->id, $dto['file']);
            }
            $this->log($user, 'HR.DOCUMENT.RENEW', $new, null, ['renews' => $old->id]);
            $this->audit->status($user, 'EmployeeDocument', $old->id, null, 'active', 'replaced');
            $this->alerts->refreshEmployee($old->employee_id);

            return $this->get($user, $new->id);
        });
    }

    public function void(AuthUser $user, string $id, string $reason): array
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw AppError::validation('REASON_REQUIRED', 'اكتب سبب الإلغاء (5 أحرف على الأقل)', 'A reason is required (at least 5 characters)');
        }

        return DB::transaction(function () use ($user, $id, $reason) {
            $d = $this->lock($user, $id);
            $this->assertActive($d);
            $this->assertWrites($user, (bool) $d->is_private);
            $d->update(['status' => 'void', 'voided_by' => $user->username, 'voided_at' => now(), 'void_reason' => mb_substr(trim($reason), 0, 500)]);
            $this->audit->status($user, 'EmployeeDocument', $d->id, null, 'active', 'void', mb_substr(trim($reason), 0, 500));
            $this->alerts->refreshEmployee($d->employee_id);

            return $this->get($user, $d->id);
        });
    }

    /** Adds a scan to the document. @param array{fileName:string, base64:string} $file */
    public function attach(AuthUser $user, string $id, array $file): array
    {
        return DB::transaction(function () use ($user, $id, $file) {
            $d = $this->lock($user, $id);
            $this->assertActive($d);
            $this->assertWrites($user, (bool) $d->is_private);
            $this->files->store($user, 'document', $d->id, $file);

            return $this->get($user, $d->id);
        });
    }

    public function removeFile(AuthUser $user, string $id, string $fileId): array
    {
        return DB::transaction(function () use ($user, $id, $fileId) {
            $d = $this->lock($user, $id);
            $this->assertWrites($user, (bool) $d->is_private);
            $file = HrFile::where('id', $fileId)->where('owner_type', 'document')->where('owner_id', $d->id)->first() ?? throw AppError::notFound('HR_FILE_NOT_FOUND', 'الملف غير موجود', 'File not found');
            $this->files->remove($user, $file);

            return $this->get($user, $d->id);
        });
    }

    // ───────────────────────────── internals ─────────────────────────────

    private function visible(AuthUser $user)
    {
        if (! Hr::seesDocuments($user)) {
            throw AppError::forbidden('HR_FORBIDDEN', 'سجل الوثائق يحتاج صلاحية hr.manage', 'The document register needs hr.manage');
        }

        return Document::query()->when(! Hr::seesPrivate($user), fn ($q) => $q->where('is_private', false));
    }

    /** A private document the caller may not see does not exist for them. */
    private function find(AuthUser $user, string $id): Document
    {
        return $this->visible($user)->whereKey($id)->first() ?? throw AppError::notFound('DOCUMENT_NOT_FOUND', 'الوثيقة غير موجودة', 'Document not found');
    }

    private function lock(AuthUser $user, string $id): Document
    {
        return $this->visible($user)->whereKey($id)->lockForUpdate()->first() ?? throw AppError::notFound('DOCUMENT_NOT_FOUND', 'الوثيقة غير موجودة', 'Document not found');
    }

    private function assertWrites(AuthUser $user, bool $private): void
    {
        if (! $user->can('hr.manage')) {
            throw AppError::forbidden('HR_FORBIDDEN', 'تعديل الوثائق يحتاج صلاحية hr.manage', 'Editing documents needs hr.manage');
        }
        if ($private) {
            Hr::needPrivate($user);
        }
    }

    private function assertActive(Document $d): void
    {
        if ($d->status !== 'active') {
            throw AppError::rule('DOCUMENT_STATE', $d->status === 'void' ? 'الوثيقة ملغاة — لا تُعدَّل' : 'الوثيقة جُدِّدت — عدِّل تجديدها', $d->status === 'void' ? 'The document is void' : 'The document was renewed — edit its renewal');
        }
    }

    private function assertDates(?string $issue, ?string $expiry): void
    {
        if ($issue !== null && $expiry !== null && $expiry < $issue) {
            throw AppError::validation('DOCUMENT_DATES', 'تاريخ الانتهاء قبل تاريخ الإصدار', 'The expiry date is before the issue date');
        }
        if ($issue !== null && $issue > Carbon::today()->toDateString()) {
            throw AppError::validation('DOCUMENT_DATES', 'تاريخ الإصدار في المستقبل', 'The issue date is in the future');
        }
    }

    private function values(array $dto): array
    {
        $v = [];
        foreach (self::FIELDS as $field => $column) {
            if (array_key_exists($field, $dto)) {
                $v[$column] = Hr::text($dto[$field], $column === 'notes' ? 2000 : ($column === 'number' ? 60 : 250));
            }
        }
        foreach (['issueDate' => 'issue_date', 'expiryDate' => 'expiry_date'] as $field => $column) {
            if (array_key_exists($field, $dto)) {
                $v[$column] = Hr::date($dto[$field]);
            }
        }

        return $v;
    }

    /** The trail says what happened to which document of whom — never the number written on the paper. */
    private function log(AuthUser $user, string $action, Document $d, ?Employee $e, array $value): void
    {
        $code = $e?->code ?? Employee::whereKey($d->employee_id)->value('code');
        $this->audit->log($user, ['action' => $action, 'entityType' => 'EmployeeDocument', 'entityId' => $d->id, 'entityNumber' => $code, 'newValue' => $value]);
    }

    private function shape(Document $d, array $files): array
    {
        $days = $d->status === 'active' ? Hr::daysTo($d->expiry_date) : null;
        $e = $d->relationLoaded('employee') ? $d->employee : null;

        return [
            'id' => $d->id, 'kind' => $d->kind, 'kindLabel' => ['ar' => Document::KINDS[$d->kind]['ar'] ?? $d->kind, 'en' => Document::KINDS[$d->kind]['en'] ?? $d->kind],
            'title' => $d->title, 'number' => $d->number, 'issueDate' => Hr::day($d->issue_date), 'expiryDate' => Hr::day($d->expiry_date),
            'days' => $days, 'stage' => $days === null ? null : (HrAlert::stageOf($days) ?? 'valid'), 'private' => (bool) $d->is_private, 'alwaysPrivate' => Document::alwaysPrivate($d->kind),
            'status' => $d->status, 'replacedById' => $d->replaced_by_id, 'notes' => $d->notes, 'voidReason' => $d->void_reason, 'voidedBy' => $d->voided_by, 'voidedAt' => Hr::at($d->voided_at),
            'files' => $files, 'createdBy' => $d->created_by, 'createdAt' => Hr::at($d->created_at),
        ] + ($e ? ['employee' => ['id' => $e->id, 'code' => $e->code, 'nameAr' => $e->name_ar, 'nameEn' => $e->name_en, 'jobTitle' => $e->job_title]] : ['employeeId' => $d->employee_id]);
    }
}
