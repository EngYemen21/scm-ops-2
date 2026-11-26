<?php

namespace App\Credit\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * A version of the terms of the deferred-credit agreement, in Arabic and English (Odoo's `b2b.credit.terms.version`).
 *
 * draft → published → retired. A draft is written and corrected; publishing freezes it (`content_hash` = the proof of
 * its text) and from then on nothing of it changes — a correction is a new version. The version in force is the
 * published one with the latest effective date that has come. A request freezes the version its customer accepted
 * (`credit_requests.terms_snapshot`), so retiring a version or publishing a new one never rewrites an agreement.
 */
class CreditTermsVersion extends BaseModel
{
    use HasUlids;

    protected $table = 'credit_terms_versions';

    public const STATUSES = ['draft', 'published', 'retired'];

    protected function casts(): array
    {
        return ['effective_date' => 'date', 'published_at' => 'datetime', 'retired_at' => 'datetime', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    /** The proof of a version's text: both languages, the jurisdiction and the version label, hashed. */
    public static function hashOf(string $version, ?string $ar, ?string $en, ?string $city): string
    {
        return hash('sha256', json_encode([$version, (string) $ar, (string) $en, (string) $city], JSON_UNESCAPED_UNICODE));
    }

    /** What a request keeps of the version its customer accepted. */
    public function snapshot(): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'version' => $this->version, 'effectiveDate' => $this->effective_date?->toDateString(),
            'termsAr' => $this->terms_ar, 'termsEn' => $this->terms_en, 'jurisdictionCity' => $this->jurisdiction_city,
            'hash' => $this->content_hash ?: self::hashOf((string) $this->version, $this->terms_ar, $this->terms_en, $this->jurisdiction_city),
        ];
    }
}
