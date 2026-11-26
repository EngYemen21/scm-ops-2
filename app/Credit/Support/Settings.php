<?php

namespace App\Credit\Support;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The policies of the credit section that are the business's to set (table `credit_settings`, one row per group;
 * a group that was never saved has the defaults below). Kept in the section's own table: the shared settings page is
 * not this section's to extend.
 *
 *   calendar   how target hours are counted (BusinessClock): working days and hours in the business time zone, holidays
 *   documents  which of the nine papers a stage that "requires documents" asks for
 *   reminders  how often a late stage is reminded again, and how many times at most
 *   signature  how long an agreement sent for signature waits before it expires
 */
#[Singleton]
class Settings
{
    public const DEFAULTS = [
        // Sunday … Thursday, 08:00 – 17:00: the working week of the company (Saudi Arabia)
        'calendar' => ['mode' => 'working', 'weekdays' => [0, 1, 2, 3, 4], 'dayStart' => '08:00', 'dayEnd' => '17:00', 'holidays' => []],
        'documents' => ['required' => Slots::DEFAULT_REQUIRED],
        'reminders' => ['repeatHours' => 24, 'max' => 3],
        'signature' => ['expiryDays' => 14],
    ];

    /** @var array<string, array>|null */
    private ?array $rows = null;

    /** One group, the saved values over the defaults. */
    public function get(string $group): array
    {
        $saved = $this->rows()[$group] ?? [];

        return array_merge(self::DEFAULTS[$group] ?? [], is_array($saved) ? $saved : []);
    }

    /** @return array<string, array> every group */
    public function all(): array
    {
        $out = [];
        foreach (array_keys(self::DEFAULTS) as $group) {
            $out[$group] = $this->get($group);
        }

        return $out;
    }

    public function put(string $group, array $value, ?string $by): void
    {
        $row = ['value' => json_encode($value, JSON_UNESCAPED_UNICODE), 'updated_by' => $by, 'updated_at' => now()];
        if (DB::table('credit_settings')->where('key', $group)->exists()) {
            DB::table('credit_settings')->where('key', $group)->update($row);
        } else {
            DB::table('credit_settings')->insert(['key' => $group] + $row);
        }
        $this->rows = null;
    }

    public function clock(): BusinessClock
    {
        return BusinessClock::from($this->get('calendar'));
    }

    /** @return string[] the papers a stage that requires documents asks for */
    public function requiredSlots(): array
    {
        return array_values(array_intersect(Slots::keys(), (array) ($this->get('documents')['required'] ?? [])));
    }

    public function flush(): void
    {
        $this->rows = null;
    }

    /** @return array<string, array> */
    private function rows(): array
    {
        if ($this->rows === null) {
            $this->rows = [];
            if (Schema::hasTable('credit_settings')) {
                foreach (DB::table('credit_settings')->get(['key', 'value']) as $row) {
                    $this->rows[$row->key] = json_decode((string) $row->value, true) ?: [];
                }
            }
        }

        return $this->rows;
    }
}
