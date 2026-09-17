<?php

namespace App\Services\Integrations;

use App\Models\Attachment;
use App\Models\PodAttachment;
use App\Models\ProofOfDelivery;
use App\Services\Core\AuditService;
use App\Services\Integrations\Adapters\AdapterFactory;
use App\Services\Integrations\Adapters\ErpAdapter;
use App\Services\Integrations\Adapters\GpsAdapter;
use App\Services\Integrations\Adapters\MapsAdapter;
use App\Services\Integrations\Adapters\MessagingAdapter;
use App\Services\Integrations\Adapters\ObjectStorageAdapter;
use App\Services\Integrations\Adapters\Pending;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Integration facade. Every answer is honest: a file is `uploaded` only when the storage provider accepted it,
 * otherwise the row is kept as `integration_pending` and no URL is invented; an integration is `connected` only when
 * its provider is configured (and, for storage, reachable).
 *
 * The adapters default to what the environment configures (AdapterFactory); tests and other services may pass their
 * own. Do not bind ErpAdapter in the container — the ERP and the B2B webhook share that contract.
 */
class IntegrationsService
{
    /** Decoded file size cap (5 MB). The controller also caps the base64 string length. */
    public const MAX_ATTACHMENT_BYTES = 5 * 1024 * 1024;

    private const STORAGE_PROBE_TIMEOUT_MS = 3000;

    private const NAMES = [
        'b2b' => ['ar' => 'منصة B2B', 'en' => 'B2B platform'],
        'storage' => ['ar' => 'تخزين الملفات', 'en' => 'Object storage'],
        'gps' => ['ar' => 'تتبع المركبات (GPS)', 'en' => 'GPS / telematics'],
        'maps' => ['ar' => 'الخرائط والمسارات', 'en' => 'Maps / routing'],
        'whatsapp' => ['ar' => 'واتساب', 'en' => 'WhatsApp'],
        'email' => ['ar' => 'البريد الإلكتروني', 'en' => 'E-mail (SMTP)'],
        'erp' => ['ar' => 'نظام ERP', 'en' => 'ERP'],
    ];

    public readonly ObjectStorageAdapter $storage;

    public readonly GpsAdapter $gps;

    public readonly MapsAdapter $maps;

    public readonly MessagingAdapter $messaging;

    public readonly ErpAdapter $erp;

    public readonly ErpAdapter $b2b;

    public function __construct(
        private readonly AuditService $audit,
        ?ObjectStorageAdapter $storage = null,
        ?GpsAdapter $gps = null,
        ?MapsAdapter $maps = null,
        ?MessagingAdapter $messaging = null,
        ?ErpAdapter $erp = null,
        ?ErpAdapter $b2b = null,
    ) {
        $this->storage = $storage ?? AdapterFactory::storage();
        $this->gps = $gps ?? AdapterFactory::gps();
        $this->maps = $maps ?? AdapterFactory::maps();
        $this->messaging = $messaging ?? AdapterFactory::messaging();
        $this->erp = $erp ?? AdapterFactory::erp();
        $this->b2b = $b2b ?? AdapterFactory::b2bWebhook();
    }

    /**
     * Status of every integration from its configuration; storage gets a cheap 3 s probe when configured. Never throws.
     *
     * @return list<array{key:string, nameAr:string, nameEn:string, status:string, detail:string, detailAr:string}>
     */
    public function status(): array
    {
        $row = fn (string $key, bool $configured, ?string $detailEn = null, ?string $detailAr = null, ?string $status = null) => [
            'key' => $key, 'nameAr' => self::NAMES[$key]['ar'], 'nameEn' => self::NAMES[$key]['en'],
            'status' => $status ?? ($configured ? 'connected' : Pending::STATUS),
            'detail' => $detailEn ?? ($configured ? 'configured' : Pending::DETAIL_EN),
            'detailAr' => $detailAr ?? ($configured ? 'مُهيّأ' : Pending::DETAIL_AR),
        ];

        if (! $this->storage->configured()) {
            $storageRow = $row('storage', false);
        } else {
            try {
                $probe = $this->storage->probe(self::STORAGE_PROBE_TIMEOUT_MS);
                $storageRow = $probe['ok'] ? $row('storage', true, $probe['detail'], 'الحاوية متاحة', 'connected') : $row('storage', true, $probe['detail'], "تعذّر الوصول: {$probe['detail']}", 'error');
            } catch (Throwable $e) {
                $storageRow = $row('storage', true, $e->getMessage(), "تعذّر الوصول: {$e->getMessage()}", 'error');
            }
        }

        return [
            $row('b2b', $this->b2b->configured()),
            $storageRow,
            $row('gps', $this->gps->configured()),
            $row('maps', $this->maps->configured()),
            $row('whatsapp', $this->messaging->whatsappConfigured()),
            $row('email', $this->messaging->emailConfigured()),
            $row('erp', $this->erp->configured()),
        ];
    }

    /**
     * Generic attachment on any entity → `attachments` row (uploaded | integration_pending).
     *
     * @param  array{fileName:string, mime:string, base64:string}  $file  base64 may carry a `data:<mime>;base64,` prefix
     */
    public function attach(?AuthUser $user, string $entityType, string $entityId, array $file): Attachment
    {
        $bytes = $this->decode($file['base64']);
        $name = self::safeName($file['fileName']);
        $key = mb_strtolower($entityType)."/{$entityId}/".self::stamp()."-{$name}";
        $put = $this->store($key, $bytes, $file['mime']);
        $uploaded = $put['status'] === 'uploaded';
        $row = Attachment::create([
            'entity_type' => $entityType, 'entity_id' => $entityId, 'file_name' => $name, 'mime' => $file['mime'], 'size' => strlen($bytes),
            'status' => $uploaded ? 'uploaded' : Pending::STATUS,
            'storage_key' => $uploaded ? ($put['key'] ?? $key) : null,
            'url' => $uploaded ? ($put['url'] ?? null) : null,
        ]);
        $this->audit->log($user, ['action' => 'ATTACHMENT.ADD', 'entityType' => $entityType, 'entityId' => $entityId, 'newValue' => ['fileName' => $name, 'size' => strlen($bytes), 'status' => $row->status]]);

        return $row->refresh();
    }

    /** @return Collection<int, Attachment> */
    public function attachments(string $entityType, string $entityId): Collection
    {
        return Attachment::where('entity_type', $entityType)->where('entity_id', $entityId)->orderByDesc('created_at')->orderByDesc('id')->get();
    }

    /**
     * Proof-of-delivery signature / photo → `pod_attachments` row (uploaded | integration_pending).
     *
     * @param  array{fileName:string, mime:string, base64:string}  $file
     */
    public function podAttach(string $podId, string $kind, array $file, ?AuthUser $user = null): PodAttachment
    {
        $pod = $this->pod($podId);
        $bytes = $this->decode($file['base64']);
        $name = self::safeName($file['fileName']);
        $key = "pod/{$pod->number}/{$kind}-".self::stamp()."-{$name}";
        $put = $this->store($key, $bytes, $file['mime']);
        $uploaded = $put['status'] === 'uploaded';
        $row = PodAttachment::create([
            'pod_id' => $pod->id, 'kind' => $kind, 'file_name' => $name, 'mime' => $file['mime'], 'size' => strlen($bytes),
            'status' => $uploaded ? 'uploaded' : Pending::STATUS,
            'storage_key' => $uploaded ? ($put['key'] ?? $key) : null,
            'url' => $uploaded ? ($put['url'] ?? null) : null,
        ]);
        $this->audit->log($user, ['action' => 'POD.ATTACHMENT', 'entityType' => 'ProofOfDelivery', 'entityId' => $pod->id, 'entityNumber' => $pod->number, 'newValue' => ['kind' => $kind, 'fileName' => $name, 'size' => strlen($bytes), 'status' => $row->status]]);

        return $row->refresh();
    }

    /** @return Collection<int, PodAttachment> */
    public function podAttachments(string $podId): Collection
    {
        return PodAttachment::where('pod_id', $this->pod($podId)->id)->orderByDesc('created_at')->orderByDesc('id')->get();
    }

    private function pod(string $idOrNumber): ProofOfDelivery
    {
        return ProofOfDelivery::where('id', $idOrNumber)->orWhere('number', $idOrNumber)->first()
            ?? throw AppError::notFound('POD_NOT_FOUND', 'إثبات التسليم غير موجود', 'Proof of delivery not found');
    }

    /** Decodes and size-checks a base64 file. Throws VALIDATION for empty / oversized payloads. */
    private function decode(string $base64): string
    {
        $clean = preg_replace('/\s+/', '', (string) preg_replace('/^data:[^;,]*;base64,/i', '', $base64));
        $bytes = (string) base64_decode((string) $clean, false);
        if ($bytes === '') {
            throw AppError::validation('ATTACHMENT_EMPTY', 'الملف فارغ أو ترميز base64 غير صالح', 'File is empty or base64 is invalid');
        }
        if (strlen($bytes) > self::MAX_ATTACHMENT_BYTES) {
            throw AppError::validation('ATTACHMENT_TOO_LARGE', 'حجم الملف يتجاوز 5 ميجابايت', 'File exceeds 5 MB', ['size' => strlen($bytes), 'max' => self::MAX_ATTACHMENT_BYTES]);
        }

        return $bytes;
    }

    /** Sends bytes to the storage adapter. Returns the honest outcome; throws BUSINESS_RULE only on a real remote failure. */
    private function store(string $key, string $bytes, string $mime): array
    {
        try {
            return $this->storage->putObject($key, $bytes, $mime);
        } catch (Throwable $e) {
            Log::warning("storage upload failed for {$key}: {$e->getMessage()}");
            throw AppError::rule('STORAGE_UPLOAD_FAILED', 'فشل رفع الملف إلى التخزين — لم يُحفظ المرفق', 'Storage upload failed — attachment not saved', ['detail' => $e->getMessage()]);
        }
    }

    private static function stamp(): string
    {
        return (int) (microtime(true) * 1000).'-'.bin2hex(random_bytes(4));
    }

    private static function safeName(string $name): string
    {
        $name = (string) preg_replace('/[\\\\\/]+/u', '_', $name);
        $name = (string) preg_replace('/[^\w.\-\x{0600}-\x{06FF} ]+/u', '_', $name);
        $name = mb_substr((string) preg_replace('/\s+/u', '_', $name), 0, 120);

        return $name !== '' ? $name : 'file';
    }
}
