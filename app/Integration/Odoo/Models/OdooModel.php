<?php

namespace App\Integration\Odoo\Models;

use App\Models\BaseModel;

/** One Odoo model in the mirror: what is copied from it, how far the copy got, when it was last looked at. */
class OdooModel extends BaseModel
{
    protected $table = 'odoo_models';

    /** Below this priority a model is synchronised on every run; the others take turns. */
    public const CORE_BELOW = 1000;

    protected function casts(): array
    {
        return [
            'fields' => 'array', 'skipped_fields' => 'array', 'has_write_date' => 'boolean', 'has_active' => 'boolean',
            'priority' => 'integer', 'page_size' => 'integer', 'remote_count' => 'integer', 'mirrored_count' => 'integer',
            'full_cursor_id' => 'integer', 'deletion_cursor_id' => 'integer', 'consecutive_errors' => 'integer',
            'window_cursor_id' => 'integer', 'window_deep' => 'boolean',
            'full_started_at' => 'datetime', 'full_finished_at' => 'datetime', 'last_synced_at' => 'datetime', 'last_deep_at' => 'datetime',
            'last_changed_at' => 'datetime', 'last_deletion_check_at' => 'datetime', 'next_attempt_at' => 'datetime',
        ];
    }

    public function core(): bool
    {
        return $this->priority < self::CORE_BELOW;
    }
}
