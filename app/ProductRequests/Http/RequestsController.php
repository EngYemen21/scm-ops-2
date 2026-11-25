<?php

namespace App\ProductRequests\Http;

use App\Http\Controllers\Controller;
use App\ProductRequests\Services\Catalogue;
use App\ProductRequests\Services\PicturesService;
use App\ProductRequests\Services\RequestsService;
use App\ProductRequests\Support\Flow;
use App\Services\Integrations\FileStore;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * New-product requests: the lists and the board, one request, raising and editing it, the moves of its author and of
 * the reviewers, its comments and pictures. Thin: validate (keys are camelCase, as the client sends them), call the
 * service, return. Action buttons post no body.
 */
class RequestsController extends Controller
{
    public function __construct(private readonly RequestsService $requests, private readonly PicturesService $pictures, private readonly Catalogue $catalogue) {}

    public function summary(): array
    {
        return $this->requests->summary(AuthUser::current());
    }

    public function lookups(): array
    {
        return $this->requests->lookups(AuthUser::current());
    }

    public function index(Request $request): array
    {
        return $this->requests->list(AuthUser::current(), Paging::from($request, 25), $request->validate(self::filters()));
    }

    public function board(Request $request): array
    {
        $f = $request->validate(self::filters() + ['per' => 'nullable|integer|min:1|max:50']);

        return $this->requests->board(AuthUser::current(), $f, Paging::from($request)->q, (int) ($f['per'] ?? 12));
    }

    /**
     * While the form is typed (the values in the query; `except=<id or number>` when the form edits a request), or
     * for a request as it stands (`request=<id or number>`, with what the reviewer is typing on top of it).
     */
    public function duplicates(Request $request): array
    {
        $f = $request->validate([
            'request' => 'nullable|string|max:60', 'except' => 'nullable|string|max:60', 'nameAr' => 'nullable|string|max:250', 'nameEn' => 'nullable|string|max:250',
            'packText' => 'nullable|string|max:120', 'barcode' => 'nullable|string|max:64', 'sku' => 'nullable|string|max:64',
        ]);

        return $this->requests->duplicates(AuthUser::current(), array_intersect_key($f, array_flip(['nameAr', 'nameEn', 'packText', 'barcode', 'sku'])), $f['request'] ?? null, $f['except'] ?? null);
    }

    /** Weight, a carton and a storage class read from a name: a proposal for the person filling the form. */
    public function estimate(Request $request): array
    {
        $f = $request->validate(['nameAr' => 'required|string|min:2|max:250', 'nameEn' => 'nullable|string|max:250', 'packText' => 'nullable|string|max:120', 'category' => 'nullable|string|max:190']);

        return $this->catalogue->estimate($f['nameAr'], $f['nameEn'] ?? null, $f['packText'] ?? null, $f['category'] ?? null);
    }

    /** The next free product code of the series, for the reviewer about to approve. */
    public function nextSku(): array
    {
        return ['sku' => $this->catalogue->nextSku()];
    }

    public function show(string $id): array
    {
        return $this->requests->get(AuthUser::current(), $id);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(self::fields(true) + ['submit' => 'nullable|boolean', 'confirmDuplicate' => 'nullable|boolean']);

        return response()->json($this->requests->create(AuthUser::current(), $data), 201);
    }

    public function update(Request $request, string $id): array
    {
        return $this->requests->update(AuthUser::current(), $id, $request->validate(self::fields(false)));
    }

    public function submit(Request $request, string $id): array
    {
        $data = $request->validate(['confirmDuplicate' => 'nullable|boolean']);

        return $this->requests->submit(AuthUser::current(), $id, ! empty($data['confirmDuplicate']));
    }

    public function startReview(string $id): array
    {
        return $this->requests->startReview(AuthUser::current(), $id);
    }

    public function sendBack(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'required|string|min:1|max:1000']);

        return $this->requests->sendBack(AuthUser::current(), $id, $data['reason']);
    }

    public function approve(Request $request, string $id): array
    {
        // the reviewer's last corrections travel with the approval: only the keys of the form are taken from `changes`
        $changes = [];
        foreach (self::fields(false) as $key => $rule) {
            $changes["changes.{$key}"] = $rule;
        }
        $data = $request->validate(['sku' => 'nullable|string|max:32', 'confirmDuplicate' => 'nullable|boolean', 'note' => 'nullable|string|max:2000'] + $changes);

        return $this->requests->approve(AuthUser::current(), $id, $data);
    }

    public function reject(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'required|string|min:1|max:1000', 'existingProduct' => 'nullable|string|max:80']);

        return $this->requests->reject(AuthUser::current(), $id, $data['reason'], $data['existingProduct'] ?? null);
    }

    public function cancel(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'nullable|string|max:1000']);

        return $this->requests->cancel(AuthUser::current(), $id, $data['reason'] ?? null);
    }

    public function comment(Request $request, string $id): array
    {
        $data = $request->validate(['text' => 'required|string|min:1|max:4000']);

        return $this->requests->comment(AuthUser::current(), $id, trim($data['text']));
    }

    public function linkProduct(Request $request, string $id): array
    {
        $data = $request->validate(['product' => 'required|string|min:1|max:80']);

        return $this->requests->linkProduct(AuthUser::current(), $id, $data['product']);
    }

    public function addPicture(Request $request, string $id): JsonResponse
    {
        // base64 of a 4 MB picture is ~5.4 MB of text (+ an optional data-URL prefix)
        $maxBase64 = (int) ceil(PicturesService::MAX_BYTES / 3) * 4 + 128;
        $data = $request->validate(['fileName' => 'nullable|string|max:200', 'base64' => "required|string|min:1|max:{$maxBase64}"]);

        return response()->json($this->pictures->add(AuthUser::current(), $id, $data), 201);
    }

    public function removePicture(Request $request, string $id, string $picture): array
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        return $this->pictures->remove(AuthUser::current(), $id, $picture, $data['reason'] ?? null);
    }

    /** The bytes of one picture, shown in place. */
    public function picture(string $id, string $picture): Response
    {
        $file = $this->pictures->open(AuthUser::current(), $id, $picture);
        $inline = in_array($file['mime'], FileStore::INLINE_MIMES, true);
        $headers = [
            'Content-Type' => $inline ? $file['mime'] : 'application/octet-stream',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment')."; filename*=UTF-8''".rawurlencode($file['fileName']),
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=3600',
        ];
        if (isset($file['disk'])) {
            // a file of the Odoo archive is streamed from its disk, never loaded whole
            return response()->stream(function () use ($file) {
                $stream = Storage::disk($file['disk'])->readStream($file['path']);
                if (is_resource($stream)) {
                    fpassthru($stream);
                    fclose($stream);
                }
            }, 200, $headers);
        }

        return response($file['bytes'], 200, $headers + ['Content-Length' => (string) $file['size']]);
    }

    /** The filters of the list and of the board. */
    private static function filters(): array
    {
        return [
            'scope' => ['nullable', Rule::in(RequestsService::SCOPES)],
            'status' => ['nullable', 'string', 'max:120', function (string $attribute, mixed $value, \Closure $fail) {
                foreach (explode(',', (string) $value) as $s) {
                    if (! in_array($s, Flow::STATUSES, true) && ! isset(Flow::GROUPS[$s])) {
                        $fail('حالة غير معروفة');
                    }
                }
            }],
            'category' => 'nullable|string|max:60', 'supplier' => 'nullable|string|max:60', 'customer' => 'nullable|string|max:60', 'requester' => 'nullable|string|max:200',
            'origin' => ['nullable', Rule::in(RequestsService::ORIGINS)], 'type' => ['nullable', Rule::in(Flow::REQUEST_TYPES)], 'storage' => ['nullable', Rule::in(Flow::STORAGE)],
            'hasProduct' => 'nullable|in:0,1,true,false', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d',
            'sort' => ['nullable', Rule::in(array_keys(RequestsService::SORTS))], 'order' => 'nullable|in:asc,desc',
        ];
    }

    /** The fields of a request, the same when it is raised, edited by its author or corrected by a reviewer. */
    private static function fields(bool $creating): array
    {
        return [
            // `filled`: absent in an edit means "leave it", present means a real name (an empty one is refused)
            'nameAr' => [$creating ? 'required' : 'filled', 'string', 'min:2', 'max:250'],
            'nameEn' => 'nullable|string|max:250', 'brand' => 'nullable|string|max:120',
            'category' => 'nullable|string|max:60', 'categoryName' => 'nullable|string|max:190',
            'supplier' => 'nullable|string|max:60', 'supplierName' => 'nullable|string|max:190',
            'uom' => 'nullable|string|max:60', 'uomName' => 'nullable|string|max:120', 'packUom' => 'nullable|string|max:60',
            'packText' => 'nullable|string|max:120', 'packQty' => 'nullable|integer|min:1|max:100000',
            'costPrice' => 'nullable|numeric|min:0|max:9999999999', 'salePrice' => 'nullable|numeric|min:0|max:9999999999',
            'barcode' => 'nullable|string|max:64', 'proposedSku' => 'nullable|string|max:32',
            'storageClass' => ['nullable', Rule::in(Flow::STORAGE)], 'shelfLifeDays' => 'nullable|integer|min:1|max:36500', 'tracksExpiry' => 'nullable|boolean',
            'weightKg' => 'nullable|numeric|gt:0|max:100000', 'lengthCm' => 'nullable|numeric|gt:0|max:10000', 'widthCm' => 'nullable|numeric|gt:0|max:10000', 'heightCm' => 'nullable|numeric|gt:0|max:10000',
            'reason' => 'nullable|string|max:4000', 'customer' => 'nullable|string|max:60', 'customerName' => 'nullable|string|max:190',
        ];
    }
}
