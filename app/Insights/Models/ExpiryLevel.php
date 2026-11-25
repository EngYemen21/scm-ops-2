<?php

namespace App\Insights\Models;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An expiry alert level (Odoo `expiry.notification.level`): a lot that is this many days or fewer from its expiry
 * date is announced once to the recipients, in the application and / or by e-mail. Archived, never deleted.
 */
class ExpiryLevel extends BaseModel
{
    use HasUlids;

    protected $table = 'ins_expiry_levels';

    /** in-app = Odoo's "chatter" */
    public const CHANNELS = ['inapp', 'email', 'both'];

    protected function casts(): array
    {
        return ['days_before_expiry' => 'integer', 'sequence' => 'integer', 'color' => 'integer', 'active' => 'boolean', 'recipients_kept' => 'array'];
    }

    public function recipients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'ins_expiry_level_users', 'level_id', 'user_id');
    }

    public function log(): HasMany
    {
        return $this->hasMany(ExpiryLog::class, 'level_id', 'id');
    }
}
