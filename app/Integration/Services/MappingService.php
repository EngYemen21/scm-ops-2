<?php

namespace App\Integration\Services;

use App\Integration\Models\IntExternalRef;
use App\Integration\Models\IntMirror;
use App\Models\Customer;
use App\Models\Product;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Facades\DB;

/**
 * The data steward's mapping work (Control Tower → mappings): link another system's product / customer to the OPS
 * record. A link is made by a person, never guessed: name similarity is only shown as a suggestion. Linking closes
 * the matching "unmapped" exceptions and replays the events that were waiting for it.
 */
class MappingService
{
    public const ENTITIES = ['product', 'customer'];

    private const UNMAPPED_CODE = ['product' => 'PRODUCT_UNMAPPED', 'customer' => 'CUSTOMER_UNMAPPED'];

    public function __construct(
        private readonly ExternalRefs $refs,
        private readonly IntegrationExceptions $exceptions,
        private readonly InboxService $inbox,
        private readonly AuditService $audit,
    ) {}

    /** Mirrored records of $system/$entity with their link (and suggestions when unlinked). $state: all | mapped | unmapped */
    public function list(Paging $page, string $system, string $entity, string $state = 'all'): array
    {
        $this->entity($entity);
        $q = IntMirror::where('int_mirror.system', $system)->where('int_mirror.entity', $entity)
            ->leftJoin('int_external_refs as r', fn ($j) => $j->on('r.system', '=', 'int_mirror.system')->on('r.entity', '=', 'int_mirror.entity')->on('r.external_id', '=', 'int_mirror.external_id'))
            ->select('int_mirror.*', 'r.internal_id', 'r.internal_code', 'r.created_by as mapped_by', 'r.created_at as mapped_at');
        if ($state === 'mapped') {
            $q->whereNotNull('r.internal_id');
        } elseif ($state === 'unmapped') {
            $q->whereNull('r.internal_id');
        }
        if ($page->q) {
            $like = '%'.addcslashes($page->q, '%_\\').'%';
            $q->where(fn ($x) => $x->where('int_mirror.external_id', 'like', $like)->orWhere('int_mirror.label', 'like', $like));
        }
        $out = $page->paginate($q->orderByRaw('r.internal_id is not null')->orderBy('int_mirror.label'));
        $catalog = null;
        $out['items'] = array_map(function ($model) use ($entity, &$catalog) {
            $m = $model->toArray(); // camelCase keys, joined columns included
            $row =['externalId' => $m['externalId'], 'label' => $m['label'], 'data' => $m['data'], 'updatedAt' => $m['updatedAt'],
                'mapped' => $m['internalId'] !== null, 'internalId' => $m['internalId'], 'internalCode' => $m['internalCode'],
                'mappedBy' => $m['mappedBy'], 'mappedAt' => $m['mappedAt']];
            if (! $row['mapped']) {
                $catalog ??= $this->catalog($entity);
                $row['suggestions'] = self::suggest((string) $m['label'], $catalog);
            }

            return $row;
        }, $out['items']);

        return $out;
    }

    public function map(AuthUser $user, string $system, string $entity, string $externalId, string $internal): array
    {
        $this->entity($entity);
        if (! IntMirror::where('system', $system)->where('entity', $entity)->where('external_id', $externalId)->exists()) {
            throw AppError::notFound('EXTERNAL_RECORD_UNKNOWN', 'السجل غير معروف — لم يصل من النظام الآخر بعد', 'The other system has not sent this record yet');
        }
        [$id, $code] = $this->internal($entity, $internal);
        $ref = DB::transaction(function () use ($user, $system, $entity, $externalId, $id, $code) {
            $ref = $this->refs->link($system, $entity, $externalId, $id, $code, null, $user->username);
            $this->audit->log($user, ['action' => 'INTEGRATION.MAP', 'entityType' => 'int_external_ref', 'entityId' => $ref->id,
                'entityNumber' => "{$system}:{$entity}:{$externalId}", 'newValue' => ['internalId' => $id, 'internalCode' => $code]]);

            return $ref;
        });
        $code = self::UNMAPPED_CODE[$entity];
        $this->exceptions->autoResolve($code, $externalId, "mapped to {$ref->internal_code} by {$user->username}");
        $replayed = $this->inbox->replayBlocked($code);

        return ['externalId' => $externalId, 'internalId' => $id, 'internalCode' => $ref->internal_code, 'replayed' => $replayed,
            'messageAr' => "رُبط {$externalId} بـ {$ref->internal_code}".($replayed ? " — أُعيد تنفيذ {$replayed} حدث كان بانتظاره" : ''),
            'messageEn' => "{$externalId} linked to {$ref->internal_code}".($replayed ? "; {$replayed} waiting event(s) replayed" : '')];
    }

    public function unmap(AuthUser $user, string $system, string $entity, string $externalId): array
    {
        $this->entity($entity);
        $ref = IntExternalRef::where('system', $system)->where('entity', $entity)->where('external_id', $externalId)->first()
            ?? throw AppError::notFound('MAPPING_NOT_FOUND', 'الربط غير موجود', 'Mapping not found');
        $ref->delete();
        $this->audit->log($user, ['action' => 'INTEGRATION.UNMAP', 'entityType' => 'int_external_ref', 'entityId' => $ref->id,
            'entityNumber' => "{$system}:{$entity}:{$externalId}", 'oldValue' => ['internalId' => $ref->internal_id, 'internalCode' => $ref->internal_code], 'newValue' => null]);

        return ['externalId' => $externalId, 'messageAr' => "أُلغي ربط {$externalId}", 'messageEn' => "{$externalId} unlinked"];
    }

    private function entity(string $entity): void
    {
        if (! in_array($entity, self::ENTITIES, true)) {
            throw AppError::validation('ENTITY_INVALID', 'نوع السجل غير مدعوم للربط', 'Entity cannot be mapped');
        }
    }

    /** @return array{0:string,1:string} [id, code] of the OPS record named by id or code/SKU */
    private function internal(string $entity, string $idOrCode): array
    {
        if ($entity === 'product') {
            $p = Product::where('id', $idOrCode)->orWhere('sku', $idOrCode)->first()
                ?? throw AppError::notFound('PRODUCT_NOT_FOUND', 'الصنف غير موجود في العمليات', 'OPS product not found');
            if (! $p->active) {
                throw AppError::rule('PRODUCT_INACTIVE', 'لا يمكن الربط بصنف غير نشط', 'Cannot map to an inactive product');
            }

            return [$p->id, $p->sku];
        }
        $c = Customer::where('id', $idOrCode)->orWhere('code', $idOrCode)->first()
            ?? throw AppError::notFound('CUSTOMER_NOT_FOUND', 'العميل غير موجود في العمليات', 'OPS customer not found');

        return [$c->id, $c->code];
    }

    /** @return list<array{id:string, code:string, name:string}> */
    private function catalog(string $entity): array
    {
        return $entity === 'product'
            ? Product::where('active', true)->get(['id', 'sku', 'name_ar'])->map(fn ($p) => ['id' => $p->id, 'code' => $p->sku, 'name' => $p->name_ar])->all()
            : Customer::where('active', true)->get(['id', 'code', 'name_ar'])->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name_ar])->all();
    }

    /** Up to 3 OPS records whose name resembles $label (a hint for the person mapping — never applied automatically). */
    private static function suggest(string $label, array $catalog): array
    {
        $norm = fn (string $s) => preg_replace('/\s+/u', ' ', str_replace(['أ', 'إ', 'آ', 'ة', 'ى'], ['ا', 'ا', 'ا', 'ه', 'ي'], mb_strtolower(trim($s))));
        $a = $norm($label);
        if ($a === '') {
            return [];
        }
        $scored = [];
        foreach ($catalog as $c) {
            similar_text($a, $norm($c['name']), $pct);
            if ($pct >= 45) {
                $scored[] = $c + ['score' => (int) round($pct)];
            }
        }
        usort($scored, fn ($x, $y) => $y['score'] <=> $x['score']);

        return array_slice($scored, 0, 3);
    }
}
