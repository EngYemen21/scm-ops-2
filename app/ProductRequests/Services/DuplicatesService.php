<?php

namespace App\ProductRequests\Services;

use App\Models\Product;
use App\Models\ProductBarcode;
use App\ProductRequests\Models\ProductRequest;
use App\ProductRequests\Support\Flow;
use App\ProductRequests\Support\NameKey;
use App\Support\AppError;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Is the product that is being asked for already in the catalogue, or already asked for?
 *
 * Three questions, asked while the form is typed, again when the request is sent and once more when it is approved:
 *   the barcode   — a barcode is one product: the product that carries it, and any other running request that names it;
 *   the code      — a product code that is asked for must be free;
 *   the name      — products and running requests whose name is the same once spelling variants are folded (NameKey),
 *                   then the products whose name holds what was typed (for the person typing: "is it one of these?").
 *
 * A taken barcode or code BLOCKS: the product could not be created with it. The same name asks for a confirmation —
 * the same name in another pack is a different product, and only a person knows. A name in another pack, or a similar
 * one, is shown and blocks nothing. Requests that were rejected under the same name are shown too: the reason they
 * were rejected for is what the person asking should read first.
 */
class DuplicatesService
{
    /** How many similar names are shown. */
    public const SIMILAR = 8;

    /** A name shorter than this is not looked for. */
    private const MIN_NAME = 3;

    /**
     * @param  array{nameAr?:?string, nameEn?:?string, packText?:?string, barcode?:?string, sku?:?string}  $probe
     * @param  ?string  $exceptId  the request being edited: it is not its own duplicate
     * @return array{barcode:array, sku:array, names:array, blocked:bool, needsConfirmation:bool, clean:bool}
     */
    public function check(array $probe, ?string $exceptId = null): array
    {
        $barcode = self::barcode($probe['barcode'] ?? null);
        $sku = mb_strtoupper(trim((string) ($probe['sku'] ?? '')));
        $out = [
            'barcode' => ['value' => $barcode === '' ? null : $barcode, 'product' => null, 'requests' => []],
            'sku' => ['value' => $sku === '' ? null : $sku, 'product' => null],
            'names' => ['products' => [], 'requests' => [], 'rejected' => []],
        ];
        if ($barcode !== '') {
            $taken = ProductBarcode::where('barcode', $barcode)->with('product:id,sku,name_ar,name_en,active')->first();
            $out['barcode']['product'] = $taken?->product ? self::product($taken->product) : null;
            $out['barcode']['requests'] = $this->others($exceptId)->whereIn('status', Flow::RUNNING)->where('barcode', $barcode)->orderBy('created_at')->limit(5)->get()
                ->map(fn (ProductRequest $o) => self::request($o, 'exact'))->all();
        }
        if ($sku !== '' && ($p = Product::where('sku', $sku)->first(['id', 'sku', 'name_ar', 'name_en', 'active']))) {
            $out['sku']['product'] = self::product($p);
        }

        $ar = (string) ($probe['nameAr'] ?? '');
        $en = (string) ($probe['nameEn'] ?? '');
        $cores = self::keys([NameKey::core($ar), NameKey::core($en)]);
        if ($cores) {
            $pack = NameKey::pack($ar.' '.$en, $probe['packText'] ?? null);
            $out['names']['products'] = $this->products($cores, NameKey::tokens(implode(' ', $cores)), $pack);
            $sameName = fn () => $this->others($exceptId)->where(fn ($w) => $w->whereIn('name_key', $cores)->orWhereIn('name_en_key', $cores));
            $out['names']['requests'] = $sameName()->whereIn('status', Flow::RUNNING)->orderBy('created_at')->limit(10)->get()
                ->map(fn (ProductRequest $o) => self::request($o, self::samePack($pack, NameKey::pack($o->name_ar.' '.$o->name_en, $o->pack_text)) ? 'exact' : 'pack'))->all();
            $out['names']['rejected'] = $sameName()->where('status', Flow::REJECTED)->orderByDesc('decided_at')->orderByDesc('id')->limit(3)->get()
                ->map(fn (ProductRequest $o) => self::request($o, 'exact') + ['rejectReason' => $o->reject_reason, 'decidedAt' => $o->decided_at?->toIso8601String()])->all();
        }

        $exact = fn (array $rows) => array_values(array_filter($rows, fn (array $x) => $x['match'] === 'exact'));
        $blocked = $out['barcode']['product'] !== null || $out['sku']['product'] !== null || $out['barcode']['requests'] !== [];
        $confirm = $exact($out['names']['products']) !== [] || $exact($out['names']['requests']) !== [];
        $shown = $out['names']['products'] !== [] || $out['names']['requests'] !== [] || $out['names']['rejected'] !== [];

        return $out + ['blocked' => $blocked, 'needsConfirmation' => $confirm, 'clean' => ! $blocked && ! $shown];
    }

    /**
     * The check a request must pass before it is sent or approved. A taken barcode or code refuses it (409); the same
     * name refuses it until the person says it is another product (`$confirmed`).
     *
     * @return array what was found (for the history line of whoever confirmed)
     */
    public function gate(ProductRequest $r, bool $confirmed, ?string $sku = null): array
    {
        $found = $this->check(['nameAr' => $r->name_ar, 'nameEn' => $r->name_en, 'packText' => $r->pack_text, 'barcode' => $r->barcode, 'sku' => $sku ?? $r->proposed_sku], $r->id);
        if ($p = $found['barcode']['product']) {
            throw new AppError('CONFLICT', 'PREQ_BARCODE_EXISTS', "الباركود {$found['barcode']['value']} مسجَّل للصنف {$p['sku']} — {$p['nameAr']}",
                "Barcode {$found['barcode']['value']} already belongs to product {$p['sku']}", ['product' => $p]);
        }
        if ($p = $found['sku']['product']) {
            throw new AppError('CONFLICT', 'PREQ_SKU_EXISTS', "رمز الصنف {$p['sku']} مستخدم للصنف «{$p['nameAr']}»", "Product code {$p['sku']} is already used", ['product' => $p]);
        }
        if ($o = $found['barcode']['requests'][0] ?? null) {
            throw new AppError('CONFLICT', 'PREQ_DUPLICATE_REQUEST', "طلب آخر مفتوح بالباركود نفسه: {$o['number']} — {$o['nameAr']}",
                "Another open request carries the same barcode: {$o['number']}", ['request' => $o]);
        }
        if ($r->request_type !== 'new' || $confirmed) {
            return $found;
        }
        $products = array_values(array_filter($found['names']['products'], fn (array $x) => $x['match'] === 'exact'));
        $requests = array_values(array_filter($found['names']['requests'], fn (array $x) => $x['match'] === 'exact'));
        if ($products) {
            $p = $products[0];
            throw new AppError('CONFLICT', 'PREQ_DUPLICATE_NAME', "يوجد صنف بالاسم نفسه: {$p['sku']} — {$p['nameAr']}. أكّد أنه صنف آخر للمتابعة",
                "A product of the same name exists: {$p['sku']}. Confirm that this is another product to go on", ['products' => $products, 'requests' => $requests]);
        }
        if ($requests) {
            $o = $requests[0];
            throw new AppError('CONFLICT', 'PREQ_DUPLICATE_NAME', "يوجد طلب مفتوح بالاسم نفسه: {$o['number']}. أكّد أنه صنف آخر للمتابعة",
                "An open request of the same name exists: {$o['number']}. Confirm that this is another product to go on", ['products' => [], 'requests' => $requests]);
        }

        return $found;
    }

    /** A barcode as it is stored: no spaces inside or around it. */
    public static function barcode(mixed $value): string
    {
        return is_string($value) || is_int($value) ? (string) preg_replace('/\s+/u', '', (string) $value) : '';
    }

    /**
     * The products that carry the name: the same name first (`exact`; `pack` when both state a pack and the packs
     * differ), then the names that hold what was typed (`similar`), the closest first.
     *
     * The catalogue is read whole and compared here: the names of products are stored as people typed them, and the
     * folding of spelling variants is not something a database index can do on a table this module does not own.
     *
     * @param  string[]  $keys  the names that were typed (Arabic, English), as keys without their pack
     * @param  string[]  $typed  their words
     * @return array<int, array{id:string, sku:string, nameAr:string, nameEn:?string, active:bool, match:string, score:float}>
     */
    private function products(array $keys, array $typed, string $pack): array
    {
        $same = [];
        $similar = [];
        $wide = count($typed) >= 2 || ($typed && mb_strlen($typed[0]) >= 4); // one short word names half the catalogue
        foreach (DB::table('products')->select(['id', 'sku', 'name_ar', 'name_en', 'active'])->orderBy('sku')->cursor() as $p) {
            $coreAr = NameKey::core($p->name_ar);
            $coreEn = $p->name_en === $p->name_ar ? $coreAr : NameKey::core($p->name_en);
            if (in_array($coreAr, $keys, true) || in_array($coreEn, $keys, true)) {
                $same[] = self::product($p) + ['match' => self::samePack($pack, NameKey::pack($p->name_ar.' '.$p->name_en)) ? 'exact' : 'pack', 'score' => 1.0];

                continue;
            }
            if (! $wide) {
                continue;
            }
            $score = NameKey::coverage($typed, NameKey::tokens($coreAr.' '.$coreEn));
            if ($score >= 0.75) {
                $similar[] = self::product($p) + ['match' => 'similar', 'score' => round($score, 2)];
            }
        }
        usort($same, fn (array $a, array $b) => [$a['match'] === 'exact' ? 0 : 1, $a['sku']] <=> [$b['match'] === 'exact' ? 0 : 1, $b['sku']]);
        usort($similar, fn (array $a, array $b) => [$b['score'], mb_strlen($a['nameAr'])] <=> [$a['score'], mb_strlen($b['nameAr'])]);

        return array_merge(array_slice($same, 0, 20), array_slice($similar, 0, self::SIMILAR));
    }

    /** Two packs are the same pack unless both are stated and differ. */
    private static function samePack(string $a, string $b): bool
    {
        return $a === '' || $b === '' || $a === $b;
    }

    /** @return Builder<ProductRequest> */
    private function others(?string $exceptId)
    {
        return ProductRequest::query()->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId));
    }

    /** @param  string[]  $keys  @return string[] the keys worth looking for, each once */
    private static function keys(array $keys): array
    {
        return array_values(array_unique(array_filter($keys, fn (string $k) => mb_strlen($k) >= self::MIN_NAME)));
    }

    private static function product(object $p): array
    {
        return ['id' => (string) $p->id, 'sku' => (string) $p->sku, 'nameAr' => (string) $p->name_ar, 'nameEn' => $p->name_en === null ? null : (string) $p->name_en, 'active' => (bool) $p->active];
    }

    private static function request(ProductRequest $o, string $match): array
    {
        return ['id' => $o->id, 'number' => $o->number, 'nameAr' => $o->name_ar, 'nameEn' => $o->name_en, 'packText' => $o->pack_text, 'status' => $o->status, 'match' => $match];
    }
}
