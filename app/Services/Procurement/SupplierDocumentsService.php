<?php

namespace App\Services\Procurement;

use App\Models\Supplier;
use App\Models\SupplierDocument;
use App\Services\Core\AuditService;
use App\Services\Core\SettingsService;
use App\Services\Procurement\ProcurementHelpers as H;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Support\Facades\DB;

/**
 * Supplier qualification documents (CR, VAT certificate, licences, insurance…) with expiry dates.
 * An expired *mandatory* document blocks new purchase orders for the supplier (PoService) unless a po.approve holder
 * records an exception. The file itself is not stored yet (Integration Pending) — the record is the control.
 */
class SupplierDocumentsService
{
    public function __construct(private readonly AuditService $audit, private readonly SettingsService $settings) {}

    /** @return array{supplier: array, documents: array<int, array>, expired: int, expiringSoon: int, warnDays: int} */
    public function list(string $supplierCode): array
    {
        $s = H::findSupplier($supplierCode, false);
        $warn = (int) ($this->settings->get('procurement.supplierDocWarnDays') ?? 30);
        $docs = SupplierDocument::where('supplier_id', $s->id)->orderByRaw('expires_at is null')->orderBy('expires_at')->get()->map(fn ($d) => $this->row($d, $warn));

        return [
            'supplier' => ['code' => $s->code, 'nameAr' => $s->name_ar, 'nameEn' => $s->name_en, 'status' => $s->status, 'active' => (bool) $s->active],
            'documents' => $docs->all(),
            'expired' => $docs->where('state', 'expired')->count(),
            'expiringSoon' => $docs->where('state', 'expiring')->count(),
            'warnDays' => $warn,
        ];
    }

    /** @param  array{type:string, number?:?string, issuedAt?:?string, expiresAt?:?string, mandatory?:bool, notes?:?string, fileName?:?string}  $dto */
    public function add(AuthUser $user, string $supplierCode, array $dto): array
    {
        $s = H::findSupplier($supplierCode, false);
        $type = (string) $dto['type'];
        if (! in_array($type, SupplierDocument::TYPES, true)) {
            throw AppError::validation('DOC_TYPE', 'نوع المستند غير معروف', 'Unknown document type');
        }
        $issued = H::parseDate($dto['issuedAt'] ?? null);
        $expires = H::parseDate($dto['expiresAt'] ?? null);
        if ($issued && $expires && $expires->lt($issued)) {
            throw AppError::validation('DOC_DATES', 'تاريخ الانتهاء قبل تاريخ الإصدار', 'Expiry is before the issue date');
        }
        // CR and VAT certificates are mandatory by default: a supplier without a valid one must not get new POs
        $mandatory = array_key_exists('mandatory', $dto) ? (bool) $dto['mandatory'] : in_array($type, ['cr', 'vat'], true);

        return DB::transaction(function () use ($user, $s, $type, $dto, $issued, $expires, $mandatory) {
            $d = SupplierDocument::create([
                'supplier_id' => $s->id, 'type' => $type, 'number' => ($dto['number'] ?? null) ?: null, 'issued_at' => $issued, 'expires_at' => $expires,
                'mandatory' => $mandatory, 'notes' => ($dto['notes'] ?? null) ?: null, 'file_name' => ($dto['fileName'] ?? null) ?: null, 'created_by' => $user->username,
            ]);
            $this->audit->log($user, ['action' => 'SUPPLIER.DOCUMENT_ADD', 'entityType' => 'Supplier', 'entityId' => $s->id, 'entityNumber' => $s->code,
                'newValue' => ['type' => $type, 'number' => $d->number, 'expiresAt' => $expires?->toDateString(), 'mandatory' => $mandatory]]);

            return $this->row($d->refresh(), (int) ($this->settings->get('procurement.supplierDocWarnDays') ?? 30));
        });
    }

    public function remove(AuthUser $user, string $supplierCode, string $documentId): array
    {
        $s = H::findSupplier($supplierCode, false);
        $d = SupplierDocument::where('supplier_id', $s->id)->where('id', $documentId)->first()
            ?? throw AppError::notFound('DOC_NOT_FOUND', 'المستند غير موجود', 'Document not found');
        $d->delete();
        $this->audit->log($user, ['action' => 'SUPPLIER.DOCUMENT_REMOVE', 'entityType' => 'Supplier', 'entityId' => $s->id, 'entityNumber' => $s->code,
            'oldValue' => ['type' => $d->type, 'number' => $d->number, 'expiresAt' => $d->expires_at?->toDateString()]]);

        return ['ok' => true, 'messageAr' => 'حُذف المستند', 'messageEn' => 'Document removed'];
    }

    /** Expired mandatory documents of a supplier (the PO gate). */
    public static function expiredMandatory(Supplier $s)
    {
        return $s->documents()->where('mandatory', true)->whereNotNull('expires_at')->where('expires_at', '<', H::dayStart())->get();
    }

    private function row(SupplierDocument $d, int $warnDays): array
    {
        $state = 'valid';
        if ($d->expires_at) {
            if ($d->expires_at->lt(H::dayStart())) {
                $state = 'expired';
            } elseif ($d->expires_at->lt(H::dayStart()->addDays($warnDays))) {
                $state = 'expiring';
            }
        }

        return $d->toArray() + ['state' => $state, 'daysLeft' => $d->expires_at ? (int) H::dayStart()->diffInDays($d->expires_at, false) : null];
    }
}
