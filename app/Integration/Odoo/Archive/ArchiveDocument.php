<?php

namespace App\Integration\Odoo\Archive;

use App\Integration\Odoo\Archive\Models\OdooDoc;
use App\Integration\Odoo\Models\OdooModel;
use App\Integration\Odoo\Projection\Val;
use App\Support\AppError;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One document of the Odoo archive as a person reads it (docs/odoo/WAVE2.md §3): its head and summary, its lines, the
 * documents related to it, its history (Odoo's messages and value changes ON THAT DOCUMENT — what people wrote to
 * each other elsewhere is never read here), its attachments, and every copied field under Odoo's own label.
 * Also the page of a partner and the page of a product: what Odoo holds about them, across kinds.
 *
 * Everything is found through the index: the lines, messages, attachments and follow-up documents of a document are
 * the rows that hang on it (`parent`), a partner's documents the rows that name it.
 */
#[Singleton]
class ArchiveDocument
{
    /** Fields whose target model is the same on every Odoo model — used where the dictionary is not in the copy. */
    private const COMMON = ['partner_id' => 'res.partner', 'product_id' => 'product.product', 'user_id' => 'res.users', 'company_id' => 'res.company',
        'currency_id' => 'res.currency', 'create_uid' => 'res.users', 'write_uid' => 'res.users', 'commercial_partner_id' => 'res.partner'];

    private static ?bool $files = null;

    public function __construct(
        private readonly ArchiveKinds $kinds,
        private readonly ArchiveCells $cells,
        private readonly ArchiveDictionary $dictionary,
        private readonly ArchiveReader $reader,
    ) {}

    // ───────────── a document ─────────────

    public function show(string $kind, int $odooId): array
    {
        $asked = $this->reader->kind($kind);
        [$def, $row, $record, $via] = $this->open($asked['model'], $odooId, fn () => AppError::notFound('ODOO_DOC_NOT_FOUND',
            "المستند {$asked['one'][0]} #{$odooId} غير موجود في أرشيف أودو", "{$asked['one'][1]} #{$odooId} is not in the Odoo archive"));
        $personal = $this->cells->mayPrivate() ? null : $this->kinds->personal($def);
        $history = $this->history($def, $row, $personal);

        return $this->head($def, $row, $record, $via, $personal) + [
            'summary' => $this->summary($def, $row, $record, $via, $personal),
            'sections' => $this->sections($def, $row),
            'related' => $this->related($def, $row, $record, $via),
            'history' => $history['items'],
            'historyTotal' => $history['total'],
            'attachments' => $this->attachments($def, $row),
            'fields' => $this->fields($def, $record, $personal),
        ];
    }

    /**
     * The row of the index, the record of the copy and the kind of one Odoo record — refused where it is people's
     * file (its kind, or what it hangs on) and the user may not read them.
     *
     * @return array{0:array, 1:OdooDoc, 2:array{data:array, display:?string}, 3:array}
     */
    private function open(string $model, int $odooId, callable $missing): array
    {
        $row = OdooDoc::query()->where('model', $model)->where('odoo_id', $odooId)->first() ?? throw $missing();
        $def = $this->kinds->get((string) $row->kind) ?? $this->kinds->primary($model);
        $this->reader->guard($def['private'] || $row->private);
        $record = $this->cells->records($model, [$odooId])[$odooId] ?? throw $missing();

        return [$def, $row, $record, $this->cells->via($def, [$odooId => $record])];
    }

    private function head(array $def, OdooDoc $row, array $record, array $via, ?string $personal): array
    {
        $item = $this->cells->item($def, $row, $record, $via, [], $personal);
        $parent = $row->parent_model === null ? null : ($this->links([[$row->parent_model, (int) $row->parent_id]])["{$row->parent_model}:{$row->parent_id}"] ?? null);

        return [
            'kind' => $def['key'], 'model' => $def['model'], 'odooId' => (int) $row->odoo_id, 'label' => $def['one'][0], 'labelEn' => $def['one'][1],
            'number' => $item['number'], 'title' => $item['title'], 'state' => $this->cells->state($def, $row->state),
            'partner' => $item['partner'], 'date' => $item['date'], 'amount' => $item['amount'], 'currency' => $item['currency'], 'user' => $item['user'],
            'active' => $item['active'], 'deleted' => $item['deleted'], 'private' => $def['private'] || (bool) $row->private,
            'parent' => $parent,
        ];
    }

    /** The head of the page: the summary fields of the kind that have a value, each with a link where it names a document. */
    private function summary(array $def, OdooDoc $row, array $record, array $via, ?string $personal): array
    {
        $items = $targets = [];
        $context = ['id' => (int) $row->odoo_id, 'display' => $record['display'], 'via' => $via];
        foreach ($def['summary'] as $c) {
            $value = $this->cells->cell($c, $def, $row, $record, $via, $personal);
            if ($value === null || $value === '') {
                continue;
            }
            $item = ['key' => $c['key'], 'label' => $c['label'][0], 'labelEn' => $c['label'][1] ?? $c['label'][0], 'type' => $c['type'],
                'value' => $c['type'] === 'state' ? $this->cells->stateOfColumn($def, $c, (string) $value) : $value];
            $target = match (true) {
                $c['from'] === '#partner' => ['res.partner', (int) $row->partner_id],
                $c['from'] === '#user' && $row->user_id => ['res.users', (int) $row->user_id],
                $c['from'][0] === '#', $c['from'][0] === '@' => null,
                default => $this->target($def, $c['from'], Pick::raw($c['from'], $record['data'], $context)),
            };
            if ($target) {
                $targets[count($items)] = $target;
            }
            $items[] = $item;
        }
        $links = $this->links(array_values($targets));
        foreach ($targets as $i => [$model, $id]) {
            if (isset($links["{$model}:{$id}"])) {
                $items[$i]['link'] = ['kind' => $links["{$model}:{$id}"]['kind'], 'id' => $id];
            }
        }

        return $items;
    }

    /** @return ?array{0:string, 1:int} the record a many2one value names, where its model is known */
    private function target(array $def, string $spec, mixed $raw): ?array
    {
        if (! is_array($raw) || ! ($id = Val::id($raw)) || ! is_string($raw[1] ?? null)) {
            return null;
        }
        $path = explode('>', (string) preg_replace('/^[a-z]+:/', '', explode('|', $spec)[0]));
        [$model, $field] = isset($path[1]) ? [$def['via'][$path[0]] ?? null, $path[1]] : [$def['model'], $path[0]];
        if ($model === null) {
            return null;
        }
        $known = $model === $def['model'] ? ($def['parent'][$field] ?? $def['via'][$field] ?? $def['touch'][$field] ?? null) : null;
        $target = (is_string($known) ? $known : null) ?? $this->dictionary->relation($model, $field) ?? self::COMMON[$field] ?? null;

        return $target ? [$target, $id] : null;
    }

    /**
     * Which of these records the archive can show to this user. @param  array<int, array{0:string, 1:int}>  $pairs
     *
     * @return array<string, array{kind:string, id:int, label:string, labelEn:string, name:?string}> by "model:id"
     */
    private function links(array $pairs): array
    {
        $byModel = [];
        foreach ($pairs as [$model, $id]) {
            $byModel[$model][] = $id;
        }
        $may = $this->cells->mayPrivate();
        $out = [];
        foreach ($byModel as $model => $ids) {
            foreach (OdooDoc::query()->where('model', $model)->whereIn('odoo_id', array_unique($ids))->get(['odoo_id', 'kind', 'private', 'number', 'title']) as $r) {
                $def = $this->kinds->get((string) $r->kind);
                if ($def && ! $def['support'] && ($may || (! $def['private'] && ! $r->private))) {
                    $out["{$model}:{$r->odoo_id}"] = ['kind' => $def['key'], 'id' => (int) $r->odoo_id, 'label' => $def['one'][0], 'labelEn' => $def['one'][1],
                        'name' => $r->number !== '' ? (string) $r->number : ($r->title !== '' ? (string) $r->title : null)];
                }
            }
        }

        return $out;
    }

    /** The lines of the document: the rows of the named kinds that hang on it, in the document's order. */
    private function sections(array $def, OdooDoc $row): array
    {
        $may = $this->cells->mayPrivate();
        $limit = max(1, (int) config('odoo_archive.section_rows', 200));
        $out = [];
        foreach ($def['sections'] as $s) {
            $child = $this->kinds->get($s['kind']);
            if (! $child || ($child['private'] && ! $may)) {
                continue;
            }
            $base = $this->hanging($def, $row)->where('kind', $child['key']);
            if ($s['groups'] && $child['groups']) {
                $at = array_filter(array_map(fn ($g) => array_search($g, $child['groups'][1], true), $s['groups']), fn ($p) => $p !== false);
                if ($at) {
                    $base->whereBetween('seq', [1000000 * min($at), 1000000 * max($at) + 999999]);
                }
            }
            if ($child['hangs'] && ! $may) {
                $base->where('private', false);
            }
            $total = (clone $base)->count();
            if ($total === 0) {
                continue;
            }
            $columns = $child['columns'];
            if ($keys = $s['columns'] ?? $child['inline']) {
                $columns = array_values(array_filter(array_map(fn ($key) => is_array($key) ? $key : $this->kinds->columnOf($child, (string) $key), $keys)));
            }
            $rows = $base->orderBy('seq')->orderBy('odoo_id')->limit($limit)->get()->all();
            $out[] = ['key' => $s['key'], 'label' => $s['label'][0], 'labelEn' => $s['label'][1] ?? $s['label'][0], 'kind' => $child['key'],
                'columns' => $this->cells->columnsOut($child, $columns),
                'rows' => array_map(fn ($item) => ['odooId' => $item['odooId'], 'cells' => $item['cells']], $this->cells->items($child, $rows, $columns)),
                'total' => $total, 'more' => $total > count($rows), 'query' => ['parent' => "{$def['model']}:{$row->odoo_id}"]];
        }

        return $out;
    }

    /** The rows that hang on a document. What hangs on a document Odoo deleted went with it — it is shown all the same. */
    private function hanging(array $def, OdooDoc $row): Builder
    {
        $query = OdooDoc::query()->where('parent_model', $def['model'])->where('parent_id', (int) $row->odoo_id);

        return $row->live ? $query->where('live', true) : $query;
    }

    /**
     * Related documents: the ones this one points at, the ones that name it, what settled it — and everything that
     * hangs on it and is not one of its lines.
     */
    private function related(array $def, OdooDoc $row, array $record, array $via): array
    {
        $may = $this->cells->mayPrivate();
        $first = max(1, (int) config('odoo_archive.related_rows', 10));
        $context = ['id' => (int) $row->odoo_id, 'display' => $record['display'], 'via' => $via];
        $readable = fn (?array $kind) => $kind && ! $kind['support'] && ($may || ! $kind['private']);
        $groups = [];
        foreach ($def['related'] as $r) {
            if (isset($r['ids'])) {
                $raw = Pick::raw($r['ids'], $record['data'], $context);
                $ids = is_array($raw) && count($raw) === 2 && is_string($raw[1] ?? null) ? array_filter([Val::id($raw)]) : Val::ids($raw);
                if (! $ids) {
                    continue;
                }
                $rows = OdooDoc::query()->where('model', $r['model'])->whereIn('odoo_id', array_slice($ids, 0, 500))->orderByDesc('doc_date')->orderByDesc('odoo_id')->get();
                $rows = $may ? $rows : $rows->where('private', false);
                $byKind = $rows->groupBy('kind');
                foreach ($byKind as $key => $of) {
                    if ($readable($kind = $this->kinds->get((string) $key))) {
                        $groups[] = $this->group(count($byKind) > 1 ? $kind['label'] : $r['label'], $kind, $of->take($first)->all(), $of->count(), null);
                    }
                }
            } elseif (isset($r['kinds'])) {
                $dim = isset($r['dim1']) ? 'dim1' : 'dim2';
                $value = match ($r[$dim]) {
                    '#number' => (string) $row->number, '#id' => (string) $row->odoo_id, default => (string) $r[$dim],
                };
                if ($value === '') {
                    continue;
                }
                foreach ($r['kinds'] as $i => $key) {
                    if (! $readable($kind = $this->kinds->get((string) $key))) {
                        continue;
                    }
                    $base = OdooDoc::query()->where('kind', $kind['key'])->where('live', true)->where($dim, $value);
                    $base = $kind['hangs'] && ! $may ? $base->where('private', false) : $base;
                    if ($total = (clone $base)->count()) {
                        $groups[] = $this->group($i === 0 ? $r['label'] : $kind['label'], $kind, $base->orderByDesc('doc_date')->orderByDesc('odoo_id')->limit($first)->get()->all(), $total, [$dim => $value]);
                    }
                }
            } elseif (isset($r['reconciled'])) {
                $move = $r['reconciled'] === true ? (int) $row->odoo_id : Val::id($record['data'][$r['reconciled']] ?? null);
                array_push($groups, ...($move ? $this->reconciled($move, $r['label'], $first) : []));
            }
        }
        // what hangs on the document and is not one of its lines, its messages, its attachments — or a kind listed already
        $shown = array_merge(array_column($def['sections'], 'kind'), array_column($groups, 'kind'));
        foreach ($this->hanging($def, $row)->toBase()->selectRaw('kind, COUNT(*) AS n')->groupBy('kind')->pluck('n', 'kind') as $key => $n) {
            $kind = $this->kinds->get((string) $key);
            if (! $readable($kind) || in_array($key, $shown, true) || $kind['model'] === 'ir.attachment') {
                continue;
            }
            $base = $this->hanging($def, $row)->where('kind', $key);
            if ($kind['hangs'] && ! $may) {
                $base->where('private', false);
                $n = (clone $base)->count();
            }
            if ($n > 0) {
                $groups[] = $this->group($kind['label'], $kind, $base->orderByDesc('doc_date')->orderByDesc('odoo_id')->limit($first)->get()->all(), (int) $n, ['parent' => "{$def['model']}:{$row->odoo_id}"]);
            }
        }

        return $groups;
    }

    /** @param  array<int, object>  $rows  rows of one kind */
    private function group(array $label, array $kind, array $rows, int $total, ?array $query, array $amounts = []): array
    {
        $items = array_map(function ($row) use ($kind, $amounts) {
            $item = $this->cells->item($kind, $row, null, [], [], null);

            return ['odooId' => $item['odooId'], 'kind' => $kind['key'], 'number' => $item['number'], 'title' => $item['title'], 'date' => $item['date'], 'state' => $item['state'],
                'amount' => $amounts[$item['odooId']] ?? $item['amount'], 'currency' => $item['currency'], 'partner' => $item['partner']];
        }, array_values($rows));

        return ['label' => $label[0], 'labelEn' => $label[1] ?? $label[0], 'kind' => $kind['key'], 'total' => $total, 'more' => $total > count($items), 'items' => $items, 'query' => $query];
    }

    /**
     * What settled a journal entry (an invoice, a bill, the entry of a payment): the entries on the other side of
     * Odoo's reconciliations of its lines, each with the amount that was matched. A payment's entry is shown as the
     * payment. Reads the partial reconciliations the way their kind indexes them (the debit line is the parent, the
     * credit line is dim1).
     */
    private function reconciled(int $move, array $label, int $first): array
    {
        $lines = OdooDoc::query()->where('parent_model', 'account.move')->where('parent_id', $move)->where('model', 'account.move.line')->limit(2000)->pluck('odoo_id')->map(fn ($id) => (int) $id)->all();
        if (! $lines) {
            return [];
        }
        $partial = $this->kinds->primary('account.partial.reconcile')['key'];
        $ids = array_merge(
            OdooDoc::query()->where('parent_model', 'account.move.line')->whereIn('parent_id', $lines)->where('kind', $partial)->pluck('odoo_id')->all(),
            OdooDoc::query()->where('kind', $partial)->where('live', true)->whereIn('dim1', array_map('strval', $lines))->pluck('odoo_id')->all(),
        );
        $own = array_flip($lines);
        $matched = [];
        foreach ($this->cells->records('account.partial.reconcile', array_slice(array_unique($ids), 0, 1000)) as $r) {
            [$debit, $credit] = [Val::id($r['data']['debit_move_id'] ?? null), Val::id($r['data']['credit_move_id'] ?? null)];
            $other = isset($own[$debit]) ? $credit : $debit;
            if ($other && ! isset($own[$other])) {
                $matched[$other] = ($matched[$other] ?? 0.0) + Val::float($r['data']['amount'] ?? 0);
            }
        }
        if (! $matched) {
            return [];
        }
        $amounts = [];
        foreach (OdooDoc::query()->where('model', 'account.move.line')->whereIn('odoo_id', array_keys($matched))->get(['odoo_id', 'parent_id']) as $line) {
            if ($line->parent_id) {
                $amounts[(int) $line->parent_id] = round(($amounts[(int) $line->parent_id] ?? 0.0) + $matched[(int) $line->odoo_id], 2);
            }
        }
        $may = $this->cells->mayPrivate();
        $moves = OdooDoc::query()->where('model', 'account.move')->whereIn('odoo_id', array_keys($amounts))->orderByDesc('doc_date')->orderByDesc('odoo_id')->get();
        // the entry of a payment is shown as the payment people know
        $payments = $moves->where('parent_model', 'account.payment');
        $rows = $moves->reject(fn ($m) => $m->parent_model === 'account.payment');
        if ($payments->isNotEmpty()) {
            $byPayment = $payments->mapWithKeys(fn ($m) => [(int) $m->parent_id => $amounts[(int) $m->odoo_id]])->all();
            foreach (OdooDoc::query()->where('model', 'account.payment')->whereIn('odoo_id', array_keys($byPayment))->orderByDesc('doc_date')->orderByDesc('odoo_id')->get() as $p) {
                $amounts["p{$p->odoo_id}"] = $byPayment[(int) $p->odoo_id];
                $rows->push($p);
            }
        }
        $groups = [];
        $byKind = $rows->groupBy('kind');
        foreach ($byKind as $key => $of) {
            $kind = $this->kinds->get((string) $key);
            if (! $kind || ($kind['private'] && ! $may)) {
                continue;
            }
            $of = $of->values();
            $by = $of->mapWithKeys(fn ($r) => [(int) $r->odoo_id => $amounts[$r->model === 'account.payment' ? "p{$r->odoo_id}" : (int) $r->odoo_id] ?? null])->all();
            $groups[] = $this->group(count($byKind) > 1 ? [$label[0].' — '.$kind['label'][0], ($label[1] ?? $label[0]).' — '.$kind['label'][1]] : $label, $kind, $of->take($first)->all(), $of->count(), null, $by)
                + ['reconciled' => true];
        }

        return $groups;
    }

    /**
     * The history of the document: Odoo's messages ON IT (newest first) with the value changes each one recorded.
     * A message that is not attached to this document — a direct chat, a channel — has no row that hangs on it and
     * can not appear.
     *
     * @return array{items:array, total:int}
     */
    private function history(array $def, OdooDoc $row, ?string $personal): array
    {
        if (in_array($def['model'], (array) config('odoo_archive.chat_models'), true)) {
            return ['items' => [], 'total' => 0];
        }
        $base = OdooDoc::query()->where('parent_model', $def['model'])->where('parent_id', (int) $row->odoo_id)->where('kind', $this->kinds->primary('mail.message')['key']);
        $total = (clone $base)->count();
        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }
        $ids = $base->orderByDesc('doc_date')->orderByDesc('odoo_id')->limit(max(1, (int) config('odoo_archive.history_rows', 200)))->pluck('odoo_id')->map(fn ($id) => (int) $id)->all();
        $messages = $this->cells->records('mail.message', $ids);
        $may = $this->cells->mayPrivate();
        $changes = [];
        $trackingRows = OdooDoc::query()->where('parent_model', 'mail.message')->whereIn('parent_id', $ids)->where('model', 'mail.tracking.value')->orderBy('odoo_id')->get(['odoo_id', 'parent_id']);
        $tracking = $this->cells->records('mail.tracking.value', $trackingRows->pluck('odoo_id')->all());
        $meta = $this->dictionary->fieldsById(array_filter(array_map(fn ($t) => Val::id($t['data']['field_id'] ?? null), $tracking)));
        foreach ($trackingRows as $t) {
            $d = $tracking[(int) $t->odoo_id]['data'] ?? null;
            if ($d === null) {
                continue;
            }
            $field = $meta[Val::id($d['field_id'] ?? null) ?? 0] ?? null;
            if ($field && (($personal !== null && preg_match($personal, $field['name'])) || (! $may && $field['relation'] && $this->kinds->isPrivate($field['relation'])))) {
                continue; // a change of the personal file, or of a link to people's files, is part of people's files
            }
            // without the dictionary: the name Odoo printed beside the field ("Status (Sales Order)"), and the text values
            $label = $field['label'] ?? (($printed = Val::label($d['field_id'] ?? null)) ? trim((string) preg_replace('/\s*\([^()]*\)\s*$/u', '', $printed)) : null) ?? (is_array($d['field_info'] ?? null) ? ($d['field_info']['desc'] ?? null) : null);
            $changes[(int) $t->parent_id][] = ['field' => $label ?? '—', 'name' => $field['name'] ?? null,
                'old' => $this->dictionary->trackedValue($d, 'old', $field['type'] ?? null), 'new' => $this->dictionary->trackedValue($d, 'new', $field['type'] ?? null)];
        }
        $items = [];
        foreach ($ids as $id) {
            $m = $messages[$id]['data'] ?? null;
            if ($m === null) {
                continue;
            }
            $text = Val::plain($m['body'] ?? null);
            $subject = Pick::text($m['subject'] ?? null, 250);
            if ($text === null && $subject === null && empty($changes[$id])) {
                continue;
            }
            $type = (string) ($m['message_type'] ?? '');
            $items[] = [
                'id' => $id,
                'at' => ($at = Pick::moment($m['date'] ?? $m['create_date'] ?? null)) ? str_replace(' ', 'T', $at).'Z' : null,
                'author' => Val::label($m['author_id'] ?? null) ?? Pick::text($m['email_from'] ?? null, 190),
                'type' => in_array($type, ['email', 'email_outgoing'], true) ? 'email' : ($type === 'comment' ? 'note' : 'system'),
                'subject' => $subject,
                'text' => $text,
                'changes' => $changes[$id] ?? [],
            ];
        }

        return ['items' => $items, 'total' => $total];
    }

    /** The files attached to the document; `url` where OPS keeps the content (table odoo_files, status stored). */
    private function attachments(array $def, OdooDoc $row): array
    {
        $kind = $this->kinds->primary('ir.attachment')['key'];
        $ids = $this->hanging($def, $row)->where('kind', $kind)->orderByDesc('doc_date')->orderByDesc('odoo_id')->limit(300)->pluck('odoo_id')->map(fn ($id) => (int) $id)->all();
        if (! $ids) {
            return [];
        }
        self::$files ??= Schema::hasTable('odoo_files');
        $stored = self::$files ? array_flip(DB::table('odoo_files')->whereIn('attachment_id', $ids)->where('status', 'stored')->pluck('attachment_id')->map(fn ($id) => (int) $id)->all()) : [];
        $records = $this->cells->records('ir.attachment', $ids);
        $out = [];
        foreach ($ids as $id) {
            $d = $records[$id]['data'] ?? null;
            if ($d !== null) {
                $out[] = ['id' => $id, 'name' => Pick::text($d['name'] ?? null, 255) ?? "attachment-{$id}", 'mimetype' => Pick::text($d['mimetype'] ?? null, 150), 'size' => (int) Val::float($d['file_size'] ?? 0),
                    'archived' => isset($stored[$id]), 'url' => isset($stored[$id]) ? "/api/odoo-backup/files/{$id}" : null,
                    'at' => ($at = Pick::moment($d['create_date'] ?? null)) ? str_replace(' ', 'T', $at).'Z' : null,
                    'link' => ($d['type'] ?? null) === 'url' ? Pick::text($d['url'] ?? null, 1000) : null];
            }
        }

        return $out;
    }

    /** Every copied field of the record under Odoo's own label — the "all fields" of the page. */
    private function fields(array $def, array $record, ?string $personal): array
    {
        $model = $def['model'];
        $types = $this->dictionary->types($model);
        $described = $this->dictionary->fields($model);
        $names = array_unique(array_merge(array_keys($types), array_keys($record['data'])));
        sort($names);
        $may = $this->cells->mayPrivate();
        $out = [];
        foreach ($names as $name) {
            $name = (string) $name;
            if ($personal !== null && preg_match($personal, $name)) {
                continue;
            }
            if (! $may && isset($described[$name]['relation']) && $this->kinds->isPrivate($described[$name]['relation'])) {
                continue; // a link to people's files names one of them (an employee's contract, a partner's bank account)
            }
            // the record as it is in Odoo: Odoo's type of the field and the value as it was copied (null: empty in Odoo)
            $type = $types[$name] ?? $described[$name]['type'] ?? null;
            $raw = $record['data'][$name] ?? null;
            $field = ['name' => $name, 'label' => $described[$name]['label'] ?? $name, 'type' => $type, 'value' => $raw];
            if ($type === 'selection' && is_scalar($raw) && ($choice = $this->dictionary->selection($model, $name)[(string) $raw] ?? null) !== null) {
                $field['valueLabel'] = $choice; // what Odoo calls that choice
            }
            $out[] = $field;
        }

        return $out;
    }

    // ───────────── a partner ─────────────

    /** The partner as Odoo knows it: profile, what it owes and is owed (open posted invoices and bills), its documents per kind. */
    public function partner(int $odooId): array
    {
        $model = (string) config('odoo_archive.partner_page.model', 'res.partner');
        [$def, $row, $record, $via] = $this->open($model, $odooId, fn () => AppError::notFound('ODOO_PARTNER_NOT_FOUND', "الشريك #{$odooId} غير موجود في أرشيف أودو", "Partner #{$odooId} is not in the Odoo archive"));
        $personal = $this->cells->mayPrivate() ? null : $this->kinds->personal($def);
        $family = $this->reader->family($odooId);
        $scope = fn () => OdooDoc::query()->whereIn('partner_id', $family)->where('live', true);
        $open = function (array $kinds, int $sign) use ($scope) {
            $r = $scope()->whereIn('kind', $kinds)->where('state', (string) config('odoo_archive.partner_page.open_state', 'posted'))->toBase()
                ->selectRaw('SUM(residual) AS due, SUM(CASE WHEN residual <> 0 THEN 1 ELSE 0 END) AS n, MIN(currency) AS currency')->first();

            return ['amount' => round($sign * (float) ($r->due ?? 0), 2) + 0.0, 'currency' => $r->currency, 'documents' => (int) ($r->n ?? 0)];
        };

        return $this->head($def, $row, $record, $via, $personal) + [
            'name' => $row->title !== '' ? $row->title : ($row->number !== '' ? $row->number : "#{$odooId}"),
            'isCustomer' => $row->dim1 === '1', 'isSupplier' => $row->dim2 === '1',
            'contacts' => count($family) - 1,
            'summary' => $this->summary($def, $row, $record, $via, $personal),
            // Odoo's own residuals, signed as Odoo signs them: what customers still owe, what is still owed to vendors
            'receivable' => $open((array) config('odoo_archive.partner_page.receivable'), 1),
            'payable' => $open((array) config('odoo_archive.partner_page.payable'), -1),
            'groups' => $this->groups($scope, ['partner' => $odooId], [], true),
        ];
    }

    // ───────────── a product ─────────────

    /** The product as Odoo knows it: its card, Odoo's stock of it per warehouse, its latest sales, purchases and stock moves. */
    public function product(int $odooId): array
    {
        $model = (string) config('odoo_archive.product_page.model', 'product.product');
        [$def, $row, $record, $via] = $this->open($model, $odooId, fn () => AppError::notFound('ODOO_PRODUCT_NOT_FOUND', "المنتج #{$odooId} غير موجود في أرشيف أودو", "Product #{$odooId} is not in the Odoo archive"));
        $personal = $this->cells->mayPrivate() ? null : $this->kinds->personal($def);
        $scope = fn () => OdooDoc::query()->where('product_id', $odooId)->where('live', true);
        $quants = (string) config('odoo_archive.product_page.quants', 'stock_quant');

        return $this->head($def, $row, $record, $via, $personal) + [
            'name' => $row->title !== '' ? $row->title : "#{$odooId}",
            'code' => $row->number !== '' ? $row->number : null,
            'summary' => $this->summary($def, $row, $record, $via, $personal),
            'stock' => $this->stock($scope()->where('kind', $quants)),
            'groups' => $this->groups($scope, ['product' => $odooId], array_merge([$def['key'], $quants], array_column($this->kinds->forModel('product.template'), 'key')), false,
                (array) config('odoo_archive.product_page.first')),
        ];
    }

    /** Odoo's stock of one product: its quants in the internal locations of each warehouse (the rest is Odoo's bookkeeping). */
    private function stock(Builder $quants): array
    {
        $ids = $quants->limit(5000)->pluck('odoo_id')->all();
        $records = $this->cells->records('stock.quant', $ids);
        $locations = $this->cells->records('stock.location', array_filter(array_map(fn ($q) => Val::id($q['data']['location_id'] ?? null), $records)));
        $warehouses = [];
        foreach ($records as $q) {
            $location = $locations[Val::id($q['data']['location_id'] ?? null) ?? 0]['data'] ?? null;
            $warehouse = Val::id($location['warehouse_id'] ?? null);
            $qty = Val::float($q['data']['quantity'] ?? 0);
            if (! $location || ($location['usage'] ?? null) !== 'internal' || ! $warehouse || $qty == 0.0) {
                continue;
            }
            $warehouses[$warehouse] ??= ['warehouseId' => $warehouse, 'warehouse' => Val::label($location['warehouse_id']) ?? "#{$warehouse}", 'qty' => 0.0, 'reserved' => 0.0, 'lots' => 0, 'nearestExpiry' => null];
            $w = &$warehouses[$warehouse];
            $w['qty'] += $qty;
            $w['reserved'] += Val::float($q['data']['reserved_quantity'] ?? 0);
            $w['lots'] += Val::id($q['data']['lot_id'] ?? null) ? 1 : 0;
            $expiry = Val::date($q['data']['expiration_date'] ?? null);
            if ($expiry && $qty > 0 && ($w['nearestExpiry'] === null || $expiry < $w['nearestExpiry'])) {
                $w['nearestExpiry'] = $expiry;
            }
            unset($w);
        }
        $rows = array_map(fn ($w) => ['qty' => round($w['qty'], 3), 'reserved' => round($w['reserved'], 3), 'available' => round($w['qty'] - $w['reserved'], 3)] + $w, array_values($warehouses));
        usort($rows, fn ($a, $b) => $b['qty'] <=> $a['qty']);

        return [
            'asOf' => OdooModel::where('model', 'stock.quant')->first()?->last_synced_at?->toJSON(),
            'qty' => round(array_sum(array_column($rows, 'qty')), 3), 'reserved' => round(array_sum(array_column($rows, 'reserved')), 3),
            'available' => round(array_sum(array_column($rows, 'available')), 3),
            'warehouses' => $rows,
        ];
    }

    /**
     * What a partner or a product appears in, kind by kind, newest first — the kinds a person may read, in the order
     * of the catalog. @param  callable():Builder  $scope  @param  string[]  $skip  @param  string[]  $first
     */
    private function groups(callable $scope, array $query, array $skip, bool $sums, array $first = [], int $rows = 5): array
    {
        $may = $this->cells->mayPrivate();
        $found = $scope()->toBase()->selectRaw('kind, COUNT(*) AS n'.($sums ? ', SUM(amount) AS total' : ''))->groupBy('kind')->get()->keyBy('kind');
        $order = array_values(array_unique(array_merge($first, array_keys($this->kinds->explicit()), $found->keys()->all())));
        $groups = [];
        foreach ($order as $key) {
            $kind = isset($found[$key]) && ! in_array($key, $skip, true) ? $this->kinds->get((string) $key) : null;
            if (! $kind || $kind['support'] || ($kind['private'] && ! $may)) {
                continue;
            }
            $base = $scope()->where('kind', $key);
            $total = (int) $found[$key]->n;
            if ($kind['hangs'] && ! $may) {
                $base->where('private', false);
                $total = (clone $base)->count();
            }
            if ($total === 0) {
                continue;
            }
            $money = $sums && $kind['ix']['amount'] && in_array('#amount', array_column($kind['columns'], 'from'), true);
            $groups[] = ['kind' => $key, 'label' => $kind['label'][0], 'labelEn' => $kind['label'][1], 'total' => $total, 'amount' => $money ? round((float) $found[$key]->total, 2) : null,
                'items' => $this->cells->items($kind, $base->orderByDesc('doc_date')->orderByDesc('odoo_id')->limit($rows)->get()->all(), $kind['columns']), 'query' => $query];
        }

        return $groups;
    }
}
