<?php

namespace App\Payroll\Models;

use App\Models\BaseModel;

/** Which account of the chart one kind of payroll amount is posted to (App\Payroll\Services\RunPosting::KEYS). */
class PayAccount extends BaseModel
{
    protected $table = 'pay_accounts';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    const CREATED_AT = null;

    protected function casts(): array
    {
        return ['updated_at' => 'datetime'];
    }
}
