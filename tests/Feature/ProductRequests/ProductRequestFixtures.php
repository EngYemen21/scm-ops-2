<?php

namespace Tests\Feature\ProductRequests;

use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Models\Uom;
use App\Models\User;
use App\ProductRequests\Models\ProductRequest;

/**
 * Fixtures of the new-product request tests. The section's tables are shared by every story of the run, so each story
 * works on requests of its own, told apart by a tag in their names.
 *
 * The demo roles: `sales` and `proc` raise requests (preq.create), `inv` raises and reviews, `gm` reviews only,
 * `admin` holds everything, `wm` cannot open the section.
 */
trait ProductRequestFixtures
{
    private static int $preqSeq = 0;

    /** A short unique tag for names, codes and barcodes (two calls in one millisecond still differ). */
    protected static function tag(): string
    {
        return self::uid().(++self::$preqSeq);
    }

    /** The section follows Odoo (requests that came from Odoo are Odoo's) or is handed over to OPS. */
    protected function followOdoo(bool $on): void
    {
        config(['odoo.enabled' => $on, 'odoo.project.preq' => $on]);
    }

    protected function userId(string $username): string
    {
        return (string) User::where('username', $username)->value('id');
    }

    /** The weight and the carton the catalogue needs before a product can be created. */
    protected static function physical(): array
    {
        return ['weightKg' => 6.5, 'lengthCm' => 30, 'widthCm' => 20, 'heightCm' => 15];
    }

    /** POST /api/product-requests as a user who may raise requests; a draft unless `submit` is sent. */
    protected function raise(array $more = [], string $as = 'sales'): array
    {
        return $this->expectOk($this->postAs($as, '/api/product-requests', $more + ['nameAr' => 'صنف اختبار '.self::tag()]));
    }

    /** A request that waits for review, with everything the catalogue needs. */
    protected function waiting(array $more = [], string $as = 'sales'): array
    {
        return $this->raise($more + self::physical() + ['submit' => true], $as);
    }

    protected function show(string $idOrNumber, string $as = 'sales'): array
    {
        return $this->expectOk($this->getAs($as, "/api/product-requests/{$idOrNumber}"));
    }

    protected function row(string $id): ProductRequest
    {
        return ProductRequest::findOrFail($id);
    }

    /** A product of the catalogue, made the way the products page makes it. */
    protected function catalogueProduct(array $more = []): array
    {
        $tag = self::tag();

        // a code outside the numbered series (P01234): the series of the catalogue is not disturbed by test products
        return $this->expectOk($this->postAs('admin', '/api/products', $more + ['sku' => "QA-{$tag}", 'nameAr' => "منتج قائم {$tag}", 'nameEn' => "Existing product {$tag}",
            'weightKg' => 2.5, 'lengthCm' => 10, 'widthCm' => 20, 'heightCm' => 30]));
    }

    protected function category(): ProductCategory
    {
        return ProductCategory::where('active', true)->orderBy('code')->firstOrFail();
    }

    protected function supplier(): Supplier
    {
        return Supplier::where('active', true)->orderBy('code')->firstOrFail();
    }

    protected function uom(string $code = 'ctn'): Uom
    {
        return Uom::where('code', $code)->firstOrFail();
    }

    /** The kinds of the lines of a request's history, the oldest first. @return string[] */
    protected static function story(array $request): array
    {
        return array_reverse(array_column($request['events'], 'kind'));
    }
}
