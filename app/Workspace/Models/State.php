<?php

namespace App\Workspace\Models;

use App\Models\BaseModel;

/** What the section remembers between runs (`ws_state`): one value per key. */
class State extends BaseModel
{
    protected $table = 'ws_state';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    public const CREATED_AT = null;

    public static function read(string $key): ?string
    {
        return self::query()->whereKey($key)->value('value');
    }

    public static function write(string $key, ?string $value): void
    {
        self::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
