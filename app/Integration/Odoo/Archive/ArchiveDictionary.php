<?php

namespace App\Integration\Odoo\Archive;

use App\Integration\Odoo\Models\OdooModel;
use App\Integration\Odoo\Projection\Val;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Odoo's own words, read from the copy: the label of every field (`ir.model.fields`), the labels of the choices of a
 * selection (`ir.model.fields.selection`), the name of a record another record points at (a stage, a journal), and
 * the type Odoo gives each field of a model. With them a page of the archive reads like Odoo's, also without Odoo.
 * Where the dictionary is not in the copy the technical names are shown — nothing fails.
 */
#[Singleton]
class ArchiveDictionary
{
    /** @var array<string, array<string, array{id:int, label:?string, type:?string, relation:?string}>> */
    private array $fields = [];

    /** @var array<string, array<string, string>> "model.field" → value → label */
    private array $selections = [];

    /** @var array<string, array<int, ?string>> model → id → name */
    private array $names = [];

    private ?string $namesOf = null;

    /** @var array<string, array<string, string>> */
    private array $types = [];

    /** @var array<int, array{model:string, name:string, label:?string, type:?string}> */
    private array $byId = [];

    public function __construct(private readonly ArchiveKinds $kinds) {}

    /** The fields of a model as Odoo describes them. @return array<string, array{id:int, label:?string, type:?string, relation:?string}> */
    public function fields(string $model): array
    {
        if (isset($this->fields[$model])) {
            return $this->fields[$model];
        }
        $cached = Cache::get("odoo-archive:fields:{$model}");
        if (is_array($cached)) {
            return $this->fields[$model] = $cached;
        }
        $def = $this->kinds->primary('ir.model.fields');
        $out = [];
        if (isset($def['dims']['dim1'])) { // the kind that indexes Odoo's fields by their model
            $ids = DB::table('odoo_docs')->where('kind', $def['key'])->where('live', true)->where('dim1', $model)->pluck('odoo_id')->all();
            foreach (array_chunk($ids, 1000) as $chunk) {
                foreach (DB::table('odoo_records')->where('model', 'ir.model.fields')->whereIn('odoo_id', $chunk)->get(['odoo_id', 'data']) as $r) {
                    $d = json_decode((string) $r->data, true) ?: [];
                    if (is_string($d['name'] ?? null)) {
                        $out[$d['name']] = ['id' => (int) $r->odoo_id, 'label' => Pick::text($d['field_description'] ?? null, 190), 'type' => is_string($d['ttype'] ?? null) ? $d['ttype'] : null,
                            'relation' => is_string($d['relation'] ?? null) ? $d['relation'] : null];
                    }
                }
            }
        }
        if ($out) {
            Cache::put("odoo-archive:fields:{$model}", $out, 600); // an empty answer is asked again: the dictionary may still be on its way
        }

        return $this->fields[$model] = $out;
    }

    /** The choices of a selection field, in Odoo's order. @return array<string, string> value → label */
    public function selection(string $model, string $field): array
    {
        $key = "{$model}.{$field}";
        if (isset($this->selections[$key])) {
            return $this->selections[$key];
        }
        $cached = Cache::get("odoo-archive:selection:{$key}");
        if (is_array($cached)) {
            return $this->selections[$key] = $cached;
        }
        $id = $this->fields($model)[$field]['id'] ?? null;
        $out = [];
        if ($id) {
            foreach (DB::table('odoo_docs')->where('parent_model', 'ir.model.fields')->where('parent_id', $id)->where('model', 'ir.model.fields.selection')->where('live', true)
                ->orderBy('seq')->orderBy('odoo_id')->get(['number', 'title']) as $row) {
                $out[(string) $row->number] = $row->title !== '' ? (string) $row->title : (string) $row->number;
            }
        }
        if ($out) {
            Cache::put("odoo-archive:selection:{$key}", $out, 600); // Odoo's wording of its choices changes about never
        }

        return $this->selections[$key] = $out;
    }

    /** Fields by their id in Odoo's dictionary (a value change names its field that way). */
    public function fieldsById(array $ids): array
    {
        $missing = array_values(array_diff(array_unique(array_map('intval', $ids)), array_keys($this->byId)));
        foreach (array_chunk($missing, 1000) as $chunk) {
            foreach (DB::table('odoo_records')->where('model', 'ir.model.fields')->whereIn('odoo_id', $chunk)->get(['odoo_id', 'data']) as $r) {
                $d = json_decode((string) $r->data, true) ?: [];
                $this->byId[(int) $r->odoo_id] = ['model' => (string) ($d['model'] ?? ''), 'name' => (string) ($d['name'] ?? ''), 'label' => Pick::text($d['field_description'] ?? null, 190),
                    'type' => is_string($d['ttype'] ?? null) ? $d['ttype'] : null, 'relation' => is_string($d['relation'] ?? null) && $d['relation'] !== '' ? $d['relation'] : null];
            }
        }

        return array_intersect_key($this->byId, array_flip(array_map('intval', $ids)));
    }

    /**
     * What people call the records of a model, by id — the index knows. A thing people choose from (a stage, a
     * journal, a warehouse) goes by its name; a document people refer to (`$document`) by its number.
     *
     * @return array<int, ?string>
     */
    public function names(string $model, array $ids, bool $document = false): array
    {
        if ($this->namesOf !== ($stamp = ArchiveCounts::current())) {
            [$this->names, $this->namesOf] = [[], $stamp]; // the index changed: a name may have, too
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $slot = $model.($document ? '#' : '');
        $missing = array_values(array_diff($ids, array_keys($this->names[$slot] ?? [])));
        foreach (array_chunk($missing, 1000) as $chunk) {
            foreach (DB::table('odoo_docs')->where('model', $model)->whereIn('odoo_id', $chunk)->get(['odoo_id', 'number', 'title']) as $row) {
                [$first, $second] = $document ? [(string) $row->number, (string) $row->title] : [(string) $row->title, (string) $row->number];
                $this->names[$slot][(int) $row->odoo_id] = $first !== '' ? $first : ($second !== '' ? $second : null);
            }
            foreach ($chunk as $id) {
                $this->names[$slot][$id] ??= null;
            }
        }

        return array_intersect_key($this->names[$slot] ?? [], array_flip($ids));
    }

    /** The type Odoo gives each copied field of a model (`odoo_models.fields`). @return array<string, string> */
    public function types(string $model): array
    {
        return $this->types[$model] ??= array_map(fn ($t) => is_string($t) ? $t : (string) ($t['type'] ?? ''), (array) (OdooModel::where('model', $model)->first(['fields'])?->fields ?? []));
    }

    /** The model a many2one field points at, where the dictionary knows. */
    public function relation(string $model, string $field): ?string
    {
        return $this->fields($model)[$field]['relation'] ?? null;
    }

    /** The label of a field: Odoo's own, else its technical name. */
    public function label(string $model, string $field): string
    {
        return $this->fields($model)[$field]['label'] ?? $field;
    }

    /** The text of one side of a value change (`mail.tracking.value`), by the type of the field that changed. */
    public function trackedValue(array $tracking, string $side, ?string $type): mixed
    {
        $pick = fn (string $kind) => $tracking["{$side}_value_{$kind}"] ?? null;

        return match ($type) {
            'boolean' => $pick('integer') === null ? null : (bool) $pick('integer'),
            'integer' => $pick('integer'),
            'float', 'monetary' => $pick('float'),
            'date' => ($at = Pick::moment($pick('datetime'))) ? substr($at, 0, 10) : null,
            'datetime' => ($at = Pick::moment($pick('datetime'))) ? str_replace(' ', 'T', $at).'Z' : null,
            'text', 'html' => Val::plain($pick('text') ?? $pick('char'), 1000),
            default => Pick::text($pick('char') ?? $pick('text'), 500),
        };
    }
}
