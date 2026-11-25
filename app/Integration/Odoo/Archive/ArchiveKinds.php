<?php

namespace App\Integration\Odoo\Archive;

use App\Integration\Odoo\Models\OdooModel;
use App\Integration\Odoo\OdooSync;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Cache;

/**
 * The kinds of the Odoo archive as the rest of the code reads them: config/odoo_archive.php made complete (every
 * shorthand written out), plus a plain kind for every other model of the copy — a model nobody described still gets
 * a list with its name, date and state, under the section its name points at.
 *
 * A record belongs to ONE kind: the first kind of its model whose `when` it satisfies. A `view` kind has no rows of
 * its own: it is a named, filtered list of another kind ("customers" are the contacts with a customer rank).
 */
#[Singleton]
class ArchiveKinds
{
    /** The columns of the index a definition fills, in the order of the table. */
    private const INDEXED = ['number', 'title', 'partner', 'product', 'date', 'state', 'amount', 'residual', 'currency', 'user', 'dim1', 'dim2', 'seq'];

    /** The fields a plain kind is made of when the model has them. */
    private const NOTICED = ['name', 'code', 'state', 'partner_id', 'user_id', 'product_id', 'sequence', 'active', 'date', 'res_model', 'res_id'];

    /** @var ?array<string, array> */
    private ?array $explicit = null;

    /** @var array<string, array<int, array>> */
    private array $byModel = [];

    /** @var ?array<string, array{label:?string, has_active:bool, fields:array<string,string>}> */
    private ?array $registry = null;

    private float $registryAt = 0.0;

    /** @var array<string, array> */
    private array $plain = [];

    /** @var ?array<string, string> plain kind key → model */
    private ?array $plainKeys = null;

    // ───────────── looking a kind up ─────────────

    /** The kinds config/odoo_archive.php names, in its order. @return array<string, array> */
    public function explicit(): array
    {
        if ($this->explicit !== null) {
            return $this->explicit;
        }
        $config = (array) config('odoo_archive.kinds');
        $made = [];
        foreach ($config as $key => $raw) {
            if (! isset($raw['view'])) {
                $made[$key] = $this->normalise((string) $key, $raw, false);
            }
        }
        foreach ($config as $key => $raw) {
            $base = isset($raw['view']) ? ($made[$raw['view'][0]] ?? null) : null;
            if ($base) {
                $made[$key] = ['key' => (string) $key, 'section' => $raw['section'] ?? $base['section'], 'label' => $raw['label'], 'one' => $raw['one'] ?? $raw['label'],
                    'view' => ['kind' => $base['key'], 'where' => array_map('strval', array_diff_key($raw['view'], [0 => true]))]] + $base;
            }
        }
        $ordered = [];
        foreach (array_keys($config) as $key) {
            if (isset($made[$key])) {
                $ordered[$key] = $made[$key];
            }
        }

        return $this->explicit = $ordered;
    }

    /** A kind by its key: a named one, or the plain kind of a model (`res_country_state`). */
    public function get(string $key): ?array
    {
        if (isset($this->explicit()[$key])) {
            return $this->explicit()[$key];
        }
        $model = $this->plainKeys()[$key] ?? null;

        return $model ? $this->plainOf($model) : null;
    }

    /** The kinds that store rows of a model, in the order they are tried; the last one takes whatever is left. */
    public function forModel(string $model): array
    {
        if (isset($this->byModel[$model])) {
            return $this->byModel[$model];
        }
        $defs = array_values(array_filter($this->explicit(), fn ($d) => $d['view'] === null && $d['model'] === $model));
        if (! $defs || end($defs)['when'] !== null) {
            $defs[] = $this->plainOf($model);
        }

        return $this->byModel[$model] = $defs;
    }

    /** The kind of one record. */
    public function pick(string $model, array $data): array
    {
        $defs = $this->forModel($model);
        foreach ($defs as $def) {
            if ($def['when'] === null || self::matches($def['when'], $data)) {
                return $def;
            }
        }

        return end($defs);
    }

    /** The kind a link to "a record of this model" names (the page of a document answers for any kind of its model). */
    public function primary(string $model): array
    {
        return $this->forModel($model)[0];
    }

    /** People's files, or what people wrote to each other: reading needs odoo.private. */
    public function isPrivate(string $model): bool
    {
        return OdooSync::isRestricted($model) || in_array($model, (array) config('odoo_archive.private_models'), true);
    }

    /** The fields of a model that only a holder of odoo.private sees, as a pattern on the field name (null: none). */
    public function personal(array $def): ?string
    {
        $patterns = array_filter([OdooSync::personalPattern($def['model']), $def['personal'], config('odoo_archive.personal_everywhere')]);
        if (! $patterns) {
            return null;
        }

        // one pattern out of several: each is a complete /…/flags expression
        return '/'.implode('|', array_map(fn ($p) => '(?:'.preg_replace('/^\/(.*)\/[a-z]*$/s', '$1', (string) $p).')', $patterns)).'/i';
    }

    /** Changes when the way the records of a model are INDEXED changes — the build then indexes the model again. */
    public function hash(string $model): string
    {
        $parts = array_map(fn ($d) => [$d['key'], $d['when'], $d['ix'], $d['parent'], $d['via'], $d['groups'], $d['private'], $d['hangs'], $d['private_when'], $d['support']], $this->forModel($model));

        return sha1(json_encode([$parts, config('odoo_archive.chat_models'), 2]));
    }

    public function sectionLabel(string $section): array
    {
        return ((array) config('odoo_archive.sections'))[$section] ?? [$section, $section];
    }

    /** A set of choices: value => [ar, en, tone]. */
    public function choices(string|array|null $set): array
    {
        return is_array($set) ? $set : ($set === null ? [] : (array) (((array) config('odoo_archive.choices'))[$set] ?? []));
    }

    /** The tone of a state nobody described, guessed from its key. */
    public function tone(string $value): string
    {
        $key = mb_strtolower($value);
        foreach ((array) config('odoo_archive.tones') as $tone => $keys) {
            if (in_array($key, $keys, true)) {
                return (string) $tone;
            }
        }

        return 'neutral';
    }

    // ───────────── the models of the copy ─────────────

    /** What the copy says about its models: Odoo's name of each and the few fields a plain kind is made of. */
    public function registry(bool $fresh = false): array
    {
        if ($this->registry !== null && ! $fresh) {
            return $this->registry;
        }
        if ($fresh) {
            Cache::forget('odoo-archive:registry');
        }
        $this->registryAt = microtime(true);

        return $this->registry = Cache::remember('odoo-archive:registry', 300, function () {
            $noticed = array_flip(array_merge(self::NOTICED, array_keys((array) config('odoo_archive.plain_parents'))));
            $out = [];
            foreach (OdooModel::query()->orderBy('model')->get(['model', 'label', 'fields', 'has_active']) as $m) {
                $out[$m->model] = ['label' => $m->label, 'has_active' => (bool) $m->has_active, 'fields' => array_intersect_key((array) $m->fields, $noticed)];
            }

            return $out;
        });
    }

    /** @return array<string, string> the key of every plain kind → its model */
    public function plainKeys(): array
    {
        if ($this->plainKeys !== null) {
            return $this->plainKeys;
        }
        $keys = [];
        foreach (array_keys($this->registry()) as $model) {
            $defs = array_values(array_filter($this->explicit(), fn ($d) => $d['view'] === null && $d['model'] === $model));
            if (! $defs || end($defs)['when'] !== null) {
                $keys[$this->plainKey($model)] = $model;
            }
        }

        return $this->plainKeys = $keys;
    }

    private function plainKey(string $model): string
    {
        $key = (string) preg_replace('/[^a-z0-9_]+/', '_', mb_strtolower($model));
        $key = isset($this->explicit()[$key]) ? "odoo_{$key}" : $key;

        // a key is part of an address and of an index: 64 characters at most, still one per model
        return strlen($key) <= 64 ? $key : substr($key, 0, 55).'_'.substr(sha1($model), 0, 8);
    }

    /** The plain kind of a model: its name, its date, its state when it has one, and what it obviously hangs on. */
    private function plainOf(string $model): array
    {
        if (isset($this->plain[$model])) {
            return $this->plain[$model];
        }
        if (! isset($this->registry()[$model]) && microtime(true) - $this->registryAt > 60) {
            $this->registry(true); // a model registered after this process read the list
            $this->plainKeys = null;
        }
        $info = $this->registry()[$model] ?? ['label' => null, 'has_active' => false, 'fields' => []];
        $fields = $info['fields'];
        $is = fn (string $field, string ...$types) => in_array($fields[$field] ?? null, $types, true);
        $named = ((array) config('odoo_archive.plain'))[$model] ?? null;
        $english = $info['label'] ?: $model;
        $raw = ['model' => $model, 'section' => $named[0] ?? $this->prefixSection($model), 'label' => [$named[1] ?? $english, $english],
            'title' => $is('name', 'char', 'text') ? 'name|@display' : '@display'];
        if ($is('code', 'char')) {
            $raw['number'] = 'code';
        }
        foreach (['partner' => 'partner_id', 'user' => 'user_id', 'product' => 'product_id'] as $column => $field) {
            if ($is($field, 'many2one')) {
                $raw[$column] = $field;
            }
        }
        if ($is('state', 'selection')) {
            $raw += ['state' => 'state', 'stateDictionary' => true];
        }
        if ($is('date', 'date', 'datetime')) {
            $raw += ['date' => 'date', 'dateType' => $fields['date']];
        }
        if ($is('sequence', 'integer')) {
            $raw['seq'] = 'sequence';
        }
        foreach ((array) config('odoo_archive.plain_parents') as $field => $targets) {
            $target = $targets[$model] ?? $targets['*'] ?? null;
            if ($target && $target !== $model && $is($field, 'many2one')) {
                $raw['parent'][$field] = $target;
            }
        }
        if ($is('res_model', 'char') && isset($fields['res_id'])) {
            $raw['parent']['@ref'] = ['res_model', 'res_id'];
            $raw['hangs'] = true;
        }
        $def = $this->normalise($this->plainKey($model), $raw, true);
        if ($info['has_active']) {
            $def['columns'][] = ['key' => 'active', 'type' => 'bool', 'label' => $def['labels']['active'], 'from' => '#active', 'choices' => null];
        }

        return $this->plain[$model] = $def;
    }

    private function prefixSection(string $model): string
    {
        foreach ((array) config('odoo_archive.plain_prefix') as $prefix => $section) {
            if (str_starts_with($model, (string) $prefix)) {
                return (string) $section;
            }
        }

        return 'other';
    }

    // ───────────── writing a definition out ─────────────

    private function normalise(string $key, array $raw, bool $plain): array
    {
        $ix = [];
        foreach (self::INDEXED as $column) {
            $spec = $raw[$column] ?? null;
            $ix[$column] = is_array($spec) ? ($spec[0] ?? null) : $spec;
        }
        $ix['search'] = array_values((array) ($raw['search'] ?? []));
        $dims = [];
        foreach (['dim1', 'dim2'] as $dim) {
            $spec = $raw[$dim] ?? null;
            if (is_array($spec) && isset($spec[1])) {
                $dims[$dim] = ['label' => $spec[1], 'choices' => isset($spec['choices']) ? $this->choices($spec['choices']) : null, 'model' => $spec['model'] ?? null, 'text' => ! empty($spec['text'])];
            }
        }
        $def = [
            'key' => $key, 'model' => (string) $raw['model'], 'section' => (string) ($raw['section'] ?? 'other'), 'label' => $raw['label'], 'one' => $raw['one'] ?? $raw['label'],
            'plain' => $plain, 'line' => ! empty($raw['line']), 'support' => ! empty($raw['support']), 'hangs' => ! empty($raw['hangs']), 'view' => null,
            'private' => ! empty($raw['private']) || $this->isPrivate((string) $raw['model']), 'personal' => $raw['personal'] ?? null,
            'when' => $raw['when'] ?? null, 'ix' => $ix, 'parent' => (array) ($raw['parent'] ?? []), 'via' => (array) ($raw['via'] ?? []),
            'touch' => (array) ($raw['touch'] ?? []), 'touchChildren' => $raw['touchChildren'] ?? null, 'groups' => $raw['groups'] ?? null, 'private_when' => $raw['private_when'] ?? null,
            'dateType' => ($raw['dateType'] ?? 'datetime') === 'date' ? 'date' : 'datetime',
            'states' => $this->choices($raw['states'] ?? null), 'stateModel' => $raw['stateModel'] ?? null, 'stateDictionary' => ! empty($raw['stateDictionary']),
            'dims' => $dims, 'labels' => ((array) ($raw['labels'] ?? [])) + (array) config('odoo_archive.labels'),
            'related' => array_values((array) ($raw['related'] ?? [])),
        ];
        $def['global'] = (bool) ($raw['global'] ?? (! $def['line'] && ! $def['support'] && ! $plain));
        $def['columns'] = $this->columns($raw['columns'] ?? $this->defaultColumns($def), $def);
        $def['more'] = $this->columns((array) ($raw['more'] ?? []), $def);
        $def['inline'] = isset($raw['inline']) ? array_values((array) $raw['inline']) : null;
        // a kind nobody wrote a summary for shows the columns of its list at the head of its page
        $def['summary'] = isset($raw['summary']) ? $this->columns((array) $raw['summary'], $def) : $def['columns'];
        $def['sections'] = array_map(fn ($s) => ['kind' => (string) $s[0], 'label' => $s[1], 'key' => (string) ($s['key'] ?? $s[0]), 'columns' => $s['columns'] ?? null, 'groups' => $s['groups'] ?? null],
            array_values((array) ($raw['sections'] ?? [])));

        return $def;
    }

    /** @return string[] */
    private function defaultColumns(array $def): array
    {
        $ix = $def['ix'];

        return array_values(array_filter([$ix['number'] ? 'number' : null, 'title', $ix['partner'] ? 'partner' : null, $ix['user'] ? 'user' : null, $ix['amount'] ? 'amount' : null, 'date', $ix['state'] ? 'state' : null]));
    }

    /** @param  array<int, string|array>  $specs */
    private function columns(array $specs, array $def): array
    {
        return array_values(array_map(fn ($spec) => is_string($spec) ? $this->typed($spec, $def) : $this->column($spec, $def), $specs));
    }

    /** One of the typed columns of the index, by its name. */
    public function typed(string $name, array $def): array
    {
        $label = $def['labels'][$name] ?? [$name, $name];

        return match ($name) {
            'number', 'title' => ['key' => $name, 'type' => 'text', 'label' => $label, 'from' => "#{$name}", 'choices' => null],
            'partner' => ['key' => 'partner', 'type' => 'partner', 'label' => $label, 'from' => '#partner', 'choices' => null],
            // the name of the product is on the record, in the many2one the definition reads the product from
            'product' => ['key' => 'product', 'type' => 'product', 'label' => $label, 'choices' => null,
                'from' => (string) (collect(explode('|', (string) $def['ix']['product']))->first(fn ($a) => ! str_contains($a, ':') && ! str_starts_with($a, '@')) ?? 'product_id')],
            'date' => ['key' => 'date', 'type' => $def['dateType'], 'label' => $label, 'from' => '#date', 'choices' => null],
            'state' => ['key' => 'state', 'type' => 'state', 'label' => $label, 'from' => '#state', 'choices' => $def['states']],
            'amount' => ['key' => 'amount', 'type' => 'money', 'label' => $label, 'from' => '#amount', 'choices' => null],
            'user' => ['key' => 'user', 'type' => 'user', 'label' => $label, 'from' => '#user', 'choices' => null],
            default => ['key' => $name, 'type' => 'text', 'label' => $label, 'from' => $name, 'choices' => null],
        };
    }

    private function column(array $spec, array $def): array
    {
        $from = (string) ($spec['from'] ?? $spec['key']);
        $choices = isset($spec['choices']) ? $this->choices($spec['choices']) : null;
        if ($choices === null && $spec['type'] === 'state') {
            $choices = match ($from) {
                '#state' => $def['states'],
                '#dim1', '#dim2' => $def['dims'][substr($from, 1)]['choices'] ?? null,
                default => null,
            };
        }

        return ['key' => (string) $spec['key'], 'type' => (string) $spec['type'], 'label' => $spec['label'], 'from' => $from, 'choices' => $choices];
    }

    /** The column of a kind by its key: one of its list, one it keeps for the lines of a document, or a typed one. */
    public function columnOf(array $def, string $key): ?array
    {
        foreach ([$def['columns'], $def['more']] as $columns) {
            foreach ($columns as $column) {
                if ($column['key'] === $key) {
                    return $column;
                }
            }
        }

        return in_array($key, ['number', 'title', 'partner', 'product', 'date', 'state', 'amount', 'user'], true) ? $this->typed($key, $def) : null;
    }

    private static function matches(array $when, array $data): bool
    {
        foreach ($when as $field => $values) {
            $value = $data[$field] ?? null;
            $value = is_array($value) ? ($value[0] ?? null) : $value;
            if (! Pick::filled($value) ? ! in_array(null, (array) $values, true) : ! in_array($value, array_filter((array) $values, fn ($v) => $v !== null), true)) {
                return false;
            }
        }

        return true;
    }
}
