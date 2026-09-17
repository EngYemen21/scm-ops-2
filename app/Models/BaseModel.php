<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Base for every domain model.
 *
 * - Database columns are snake_case (Laravel convention); the HTTP API speaks camelCase (the contract shared with
 *   the reference system and the Vue client). toArray() converts the top-level keys; related models convert
 *   themselves, and JSON-cast columns keep their own keys untouched.
 * - Datetimes carry milliseconds so "latest first" ordering is stable for rows written in the same second.
 * - Mass assignment is open ($guarded = []) because models are only written by domain services, which receive
 *   input already validated by a FormRequest. Never pass raw request input to create()/update().
 */
abstract class BaseModel extends Model
{
    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.v';

    public function toArray(): array
    {
        $out = [];
        foreach (parent::toArray() as $key => $value) {
            $out[Str::camel($key)] = $value;
        }

        return $out;
    }

    /** Fill from a camelCase payload (already validated). Unknown keys are the caller's responsibility. */
    public function fillCamel(array $data): static
    {
        $snake = [];
        foreach ($data as $key => $value) {
            $snake[Str::snake($key)] = $value;
        }

        return $this->fill($snake);
    }
}
