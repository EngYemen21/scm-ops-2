<?php

namespace App\Integration\Odoo\Archive;

use App\Integration\Odoo\Projection\Val;
use App\Support\AuthUser;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;

/**
 * From a row of the index (and, where a column needs it, the record behind it) to what the client shows: the item of
 * a list with its cells, the columns and choices a list is described by, the label and tone of a state.
 *
 * What a cell holds, by the type of its column: text → string · number, qty, money → number · date → `Y-m-d` ·
 * datetime → ISO 8601 UTC · state → the value (its label and tone are in the column's `options`) · partner, product →
 * `{ id, name }` · user → the name · bool → true / false. Null where Odoo has no value.
 *
 * A field of people's personal file is left out for a user without odoo.private, wherever it would appear.
 */
#[Singleton]
class ArchiveCells
{
    /** The typed columns of the index a column of a list may read directly. */
    private const ROW = ['#number', '#title', '#partner', '#date', '#state', '#amount', '#residual', '#user', '#dim1', '#dim2', '#active', '#currency', '@parent'];

    public function __construct(private readonly ArchiveKinds $kinds, private readonly ArchiveDictionary $dictionary, private readonly ArchiveCounts $counts) {}

    public function mayPrivate(): bool
    {
        return (bool) AuthUser::currentOrNull()?->can('odoo.private');
    }

    // ───────────── the records behind rows ─────────────

    /** @return array<int, array{data:array, display:?string}> the mirrored records of a model, by Odoo id */
    public function records(string $model, array $ids): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 1000) as $chunk) {
            foreach (DB::table('odoo_records')->where('model', $model)->whereIn('odoo_id', $chunk)->get(['odoo_id', 'display_name', 'data']) as $r) {
                $out[(int) $r->odoo_id] = ['data' => json_decode((string) $r->data, true) ?: [], 'display' => $r->display_name];
            }
        }

        return $out;
    }

    /** The records a kind reads values of (`via`), for a set of its records. @return array<string, array<int, array>> */
    public function via(array $def, array $records): array
    {
        $via = [];
        foreach ($def['via'] as $field => $target) {
            $ids = array_filter(array_map(fn ($r) => Val::id($r['data'][$field] ?? null), $records));
            foreach ($this->records($target, $ids) as $id => $far) {
                $via[$field][$id] = $far['data'];
            }
        }

        return $via;
    }

    private function needsRecords(array $columns): bool
    {
        foreach ($columns as $c) {
            if (! in_array($c['from'], self::ROW, true)) {
                return true;
            }
        }

        return false;
    }

    // ───────────── items ─────────────

    /** The items of a list: rows of ONE kind, with the cells of the given columns. @param  iterable<object>  $rows */
    public function items(array $def, iterable $rows, array $columns): array
    {
        $rows = is_array($rows) ? array_values($rows) : iterator_to_array($rows, false);
        if (! $rows) {
            return [];
        }
        $records = $via = $parents = [];
        if ($this->needsRecords($columns)) {
            $records = $this->records($def['model'], array_map(fn ($r) => (int) $r->odoo_id, $rows));
            $via = $this->via($def, $records);
        }
        if (in_array('@parent', array_column($columns, 'from'), true)) {
            foreach ($rows as $r) {
                if ($r->parent_model !== null) {
                    $parents[$r->parent_model][] = (int) $r->parent_id;
                }
            }
            foreach ($parents as $model => $ids) {
                $parents[$model] = $this->dictionary->names($model, $ids, document: true);
            }
        }
        $personal = $this->mayPrivate() ? null : $this->kinds->personal($def);

        return array_map(fn ($row) => $this->item($def, $row, $records[(int) $row->odoo_id] ?? null, $via, $columns, $personal, $parents), $rows);
    }

    /** @param  ?array{data:array, display:?string}  $record */
    public function item(array $def, object $row, ?array $record, array $via, array $columns, ?string $personal, array $parents = []): array
    {
        $cells = [];
        foreach ($columns as $c) {
            $cells[$c['key']] = $this->cell($c, $def, $row, $record, $via, $personal, $parents);
        }

        return [
            'odooId' => (int) $row->odoo_id,
            'kind' => (string) $row->kind,
            'number' => $row->number !== '' ? (string) $row->number : null,
            'title' => $row->title !== '' ? (string) $row->title : null,
            'partner' => $row->partner_id ? ['id' => (int) $row->partner_id, 'name' => $row->partner_name] : null,
            'date' => $this->date($def['dateType'], (string) $row->doc_date),
            'state' => $row->state,
            'amount' => $def['ix']['amount'] ? (float) $row->amount : null,
            'currency' => $row->currency,
            'user' => $row->user_name,
            'active' => (bool) $row->active,
            'deleted' => ! $row->live,
            'parent' => $row->parent_model === null ? null : ['model' => (string) $row->parent_model, 'kind' => $this->kinds->primary((string) $row->parent_model)['key'], 'id' => (int) $row->parent_id],
            'cells' => (object) $cells,
        ];
    }

    public function cell(array $column, array $def, object $row, ?array $record, array $via, ?string $personal, array $parents = []): mixed
    {
        $from = $column['from'];
        switch ($from) {
            case '#number': return $row->number !== '' ? (string) $row->number : null;
            case '#title': return $row->title !== '' ? (string) $row->title : null;
            case '#partner': return $row->partner_id ? ['id' => (int) $row->partner_id, 'name' => $row->partner_name] : null;
            case '#date': return $this->date($column['type'] === 'date' ? 'date' : 'datetime', (string) $row->doc_date);
            case '#state': return $row->state;
            case '#amount': return (float) $row->amount;
            case '#residual': return $row->residual === null ? null : (float) $row->residual;
            case '#user': return $row->user_name;
            case '#dim1': return $row->dim1;
            case '#dim2': return $row->dim2;
            case '#active': return (bool) $row->active;
            case '#currency': return $row->currency;
            case '@parent':
                return $row->parent_model === null ? null : ($parents[$row->parent_model][(int) $row->parent_id] ?? "{$row->parent_model} #{$row->parent_id}");
        }
        if ($record === null || ($personal !== null && $this->isPersonal($from, $personal))) {
            return null;
        }

        return $this->present($column['type'], Pick::raw($from, $record['data'], ['id' => (int) $row->odoo_id, 'display' => $record['display'], 'via' => $via]));
    }

    /** Does a VALUE read a field of the personal file? */
    public function isPersonal(string $spec, string $personal): bool
    {
        foreach (explode('|', $spec) as $alternative) {
            $field = explode('>', (string) preg_replace('/^[a-z]+:/', '', $alternative))[0];
            if ($field !== '' && $field[0] !== '@' && preg_match($personal, $field)) {
                return true;
            }
        }

        return false;
    }

    /** A value of Odoo as the cell of a column of the given type. */
    public function present(string $type, mixed $raw): mixed
    {
        if (! Pick::filled($raw) && $type !== 'bool') {
            return null;
        }

        return match ($type) {
            'number', 'qty', 'money' => Pick::number($raw),
            'date' => ($at = Pick::moment($raw)) ? substr($at, 0, 10) : null,
            'datetime' => ($at = Pick::moment($raw)) ? str_replace(' ', 'T', $at).'Z' : null,
            'state' => is_array($raw) ? (($id = Val::id($raw)) ? (string) $id : null) : (is_bool($raw) ? ($raw ? '1' : '0') : (string) $raw),
            'partner', 'product' => ($id = Val::id($raw)) && is_array($raw) ? ['id' => $id, 'name' => Val::label($raw)] : null,
            'user' => is_array($raw) ? Val::label($raw) : Pick::text($raw, 190),
            'bool' => is_bool($raw) ? $raw : ($raw === null ? null : (bool) $raw),
            default => is_string($raw) && preg_match('/<\/?[a-zA-Z][^>]*>/', $raw) ? Val::plain($raw) : (is_array($raw) && ! isset($raw[1]) ? null : Pick::text(is_array($raw) ? Val::label($raw) : $raw, 4000)),
        };
    }

    /** The date of a row as the client reads it: a day, or a moment in UTC. */
    public function date(string $dateType, string $stored): string
    {
        return $dateType === 'date' ? substr($stored, 0, 10) : str_replace(' ', 'T', substr($stored, 0, 19)).'Z';
    }

    // ───────────── what a list is described by ─────────────

    /** Columns as the catalog (and a section of a page) carries them: key, the two labels, type, and choices of a state. */
    public function columnsOut(array $def, array $columns): array
    {
        return array_map(function (array $c) use ($def) {
            $out = ['key' => $c['key'], 'label' => $c['label'][0], 'labelEn' => $c['label'][1] ?? $c['label'][0], 'type' => $c['type']];
            if ($c['type'] === 'state') {
                $out['options'] = $this->columnOptions($def, $c);
            }

            return $out;
        }, array_values($columns));
    }

    /** @return array<int, array{value:string, label:string, labelEn:string, tone:string}> */
    public function columnOptions(array $def, array $column): array
    {
        if ($column['from'] === '#state') {
            return $this->stateOptions($def); // the same choices as the state filter, with how many documents are in each
        }
        if ($column['choices']) {
            return $this->options($column['choices']);
        }

        return match (true) {
            $column['from'] === '#dim1', $column['from'] === '#dim2' => $this->dimOptions($def, substr($column['from'], 1)) ?? [],
            default => array_map(fn ($value, $label) => ['value' => (string) $value, 'label' => $label, 'labelEn' => $label, 'tone' => $this->kinds->tone((string) $value)],
                array_keys($choices = $this->dictionary->selection($def['model'], $this->field($column['from']))), array_values($choices)),
        };
    }

    /** value => [ar, en, tone] → the choices of a filter or a state column. */
    public function options(array $choices, array $counts = []): array
    {
        $out = [];
        foreach ($choices as $value => $c) {
            $out[] = ['value' => (string) $value, 'label' => (string) $c[0], 'labelEn' => (string) ($c[1] ?? $c[0]), 'tone' => (string) ($c[2] ?? 'neutral')]
                + (isset($counts[$value]) ? ['count' => (int) $counts[$value]] : []);
        }

        return $out;
    }

    /** A state of a document with its label and tone: as the definition names it, else as Odoo does. */
    public function state(array $def, ?string $value, ?array $choices = null, ?string $field = null): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        $choices ??= $def['states'];
        if (isset($choices[$value])) {
            return ['value' => $value, 'label' => (string) $choices[$value][0], 'labelEn' => (string) ($choices[$value][1] ?? $choices[$value][0]), 'tone' => (string) ($choices[$value][2] ?? 'neutral')];
        }
        if ($field === null && $def['stateModel'] && ctype_digit($value)) {
            $name = $this->dictionary->names($def['stateModel'], [(int) $value])[(int) $value] ?? null;

            return ['value' => $value, 'label' => $name ?? "#{$value}", 'labelEn' => $name ?? "#{$value}", 'tone' => 'neutral'];
        }
        $label = $this->dictionary->selection($def['model'], $field ?? $this->field((string) $def['ix']['state']))[$value] ?? $value;

        return ['value' => $value, 'label' => $label, 'labelEn' => $label, 'tone' => $this->kinds->tone($value)];
    }

    /** The value of a state-typed column with its label and tone, wherever the column reads it from. */
    public function stateOfColumn(array $def, array $column, string $value): array
    {
        if ($column['choices']) {
            return $this->state($def, $value, $column['choices'], '-');
        }
        if ($column['from'] === '#state') {
            return $this->state($def, $value);
        }
        if ($column['from'] === '#dim1' || $column['from'] === '#dim2') {
            $d = $def['dims'][substr($column['from'], 1)] ?? null;
            if ($d && $d['model'] && ctype_digit($value)) {
                $name = $this->dictionary->names($d['model'], [(int) $value])[(int) $value] ?? "#{$value}";

                return ['value' => $value, 'label' => $name, 'labelEn' => $name, 'tone' => 'neutral'];
            }

            return $this->state($def, $value, (array) ($d['choices'] ?? []), '-');
        }

        return $this->state($def, $value, [], $this->field($column['from']));
    }

    /** The choices of the state of a kind: the ones its definition names, else the ones its documents are in. */
    public function stateOptions(array $def): array
    {
        $present = $this->counts->kind($def['view']['kind'] ?? $def['key'])['states'];
        if ($def['states']) {
            return $this->options($def['states'], $def['view'] ? [] : $present);
        }
        $out = [];
        foreach (array_keys($present) as $value) {
            $out[] = $this->state($def, (string) $value) + ($def['view'] ? [] : ['count' => (int) $present[$value]]);
        }
        usort($out, fn ($a, $b) => strcmp($a['label'], $b['label']));

        return $out;
    }

    /**
     * The choices of a dimension people filter by — or null when it is free text (too many values to choose from).
     *
     * @return ?array<int, array{value:string, label:string, labelEn:string, tone:string}>
     */
    public function dimOptions(array $def, string $dim): ?array
    {
        $d = $def['dims'][$dim] ?? null;
        if (! $d) {
            return null;
        }
        if ($d['choices']) {
            return $this->options($d['choices']);
        }
        $present = $this->counts->dim($def['view']['kind'] ?? $def['key'], $dim, $d['model'] ? 80 : 25);
        if ($present === null) {
            return null;
        }
        $names = $d['model'] ? $this->dictionary->names($d['model'], array_keys($present)) : [];
        $out = [];
        foreach ($present as $value => $n) {
            $label = $d['model'] ? ($names[(int) $value] ?? "#{$value}") : (string) $value;
            $out[] = ['value' => (string) $value, 'label' => $label, 'labelEn' => $label, 'tone' => 'neutral', 'count' => (int) $n];
        }
        usort($out, fn ($a, $b) => strcmp($a['label'], $b['label']));

        return $out;
    }

    /** The field a VALUE reads first (`id:stage_id` → stage_id, `a|b` → a, `m2o>x` → m2o). */
    public function field(string $spec): string
    {
        return explode('>', (string) preg_replace('/^[a-z]+:/', '', explode('|', $spec)[0]))[0];
    }
}
