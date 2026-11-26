<?php

namespace App\Planning\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** What planning knows about a product or a customer beyond the master data: how critical it is. No row = normal. */
class Attribute extends BaseModel
{
    use HasUlids;

    protected $table = 'planning_attributes';

    public const ENTITIES = ['product', 'customer'];

    public const LEVELS = ['critical', 'high', 'normal', 'low'];
}
