<?php

namespace App\Credit\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** A way the customer contracts to pay by (Odoo's `b2b.credit.payment.method`): bank transfer, cheque… Archived, never removed. */
class CreditPaymentMethod extends BaseModel
{
    use HasUlids;

    protected $table = 'credit_payment_methods';

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'active' => 'boolean', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function scopeOrdered(Builder $q): Builder
    {
        return $q->orderBy('sequence')->orderBy('code')->orderBy('id');
    }

    /** @return array{id:string, code:string, nameAr:string, nameEn:string} */
    public function brief(): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'nameAr' => $this->name_ar, 'nameEn' => $this->name_en];
    }
}
