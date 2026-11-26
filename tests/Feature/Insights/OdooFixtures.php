<?php

namespace Tests\Feature\Insights;

use App\Integration\Odoo\Models\OdooChange;
use App\Integration\Odoo\Models\OdooModel;
use App\Integration\Odoo\Models\OdooRecord;

/** Records of the local copy of Odoo, shaped like the ones the mirror writes (Odoo itself is never called). */
trait OdooFixtures
{
    protected function odooModel(string $model, string $status = 'active', string $full = 'done'): void
    {
        OdooModel::updateOrCreate(['model' => $model], ['status' => $status, 'full_state' => $full, 'has_active' => false]);
    }

    protected function rec(string $model, int $id, array $data, ?bool $active = null): void
    {
        $data = ($active === null ? [] : ['active' => $active]) + $data + ['write_date' => '2026-09-01 08:00:00'];
        OdooRecord::updateOrCreate(['model' => $model, 'odoo_id' => $id], ['display_name' => is_string($data['name'] ?? null) ? $data['name'] : null, 'write_date' => $data['write_date'], 'active' => $active,
            'data' => $data, 'hash' => sha1(json_encode($data)), 'first_seen_at' => now(), 'changed_at' => now(), 'deleted_at' => null]);
    }

    /** What changes in Odoo: the record of the copy is rewritten (or deleted) and the feed says so. */
    protected function odooWrites(string $model, int $id, array $values, bool $delete = false): void
    {
        $current = OdooRecord::where('model', $model)->where('odoo_id', $id)->first();
        if ($delete) {
            $current->update(['deleted_at' => now()]);
        } else {
            $this->rec($model, $id, $values + ['write_date' => '2026-09-10 11:00:00'] + (array) ($current?->data ?? []), $current?->active);
        }
        OdooChange::create(['model' => $model, 'odoo_id' => $id, 'kind' => $delete ? 'deleted' : ($current ? 'updated' : 'created'), 'seen_at' => now()]);
    }
}
