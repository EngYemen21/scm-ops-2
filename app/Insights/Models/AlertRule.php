<?php

namespace App\Insights\Models;

use App\Models\BaseModel;

/** The configurable threshold of one alert type (App\Insights\Support\AlertRules says what each number means). */
class AlertRule extends BaseModel
{
    protected $table = 'ins_alert_rules';

    protected $primaryKey = 'type';

    public $incrementing = false;

    protected $keyType = 'string';

    const CREATED_AT = null;

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'threshold' => 'decimal:2', 'window_size' => 'integer'];
    }
}
