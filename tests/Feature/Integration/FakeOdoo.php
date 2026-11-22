<?php

namespace Tests\Feature\Integration;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * An Odoo that lives in memory, behind the same HTTP endpoints the real one serves (`/jsonrpc`, and `/json/2` when
 * `$json2` is on). The tests change it the way people change Odoo — create, write, archive, unlink — and then let the
 * mirror find out by reading. Anything that is not a read is recorded in `$writes`: it must stay empty.
 */
class FakeOdoo
{
    public const URL = 'https://odoo.test';

    public const KEY = 'test-odoo-key-0123456789';

    /** @var array<string, array{label:string, transient:bool, fields:array<string,array>, rows:array<int,array>, denied:bool, broken:string[], down:bool}> */
    public array $models = [];

    /** Calls that tried to change something (must stay empty). @var array<int, string> */
    public array $writes = [];

    /** Every call that reached the server: "model.method". @var string[] */
    public array $log = [];

    public bool $json2 = false;

    private Carbon $clock;

    private int $seq = 0;

    public function __construct(?string $start = null)
    {
        $this->clock = Carbon::parse($start ?? now()->utc()->subHours(3)->format('Y-m-d H:i:s'), 'UTC');
    }

    public static function config(array $over = []): array
    {
        return $over + [
            'enabled' => true, 'url' => self::URL, 'db' => 'testdb', 'login' => 'mirror@test', 'key' => self::KEY, 'api' => 'auto', 'lang' => null,
            'timeout_seconds' => 5, 'page_size' => 3, 'max_page_size' => 3, 'overlap_seconds' => 180, 'deep_overlap_seconds' => 3600, 'deep_every_minutes' => 15,
            'deletion_check_minutes' => 30, 'deletion_check_minutes_other' => 360, 'deletion_page' => 4, 'run_seconds' => 20, 'rediscover_hours' => 24,
            'keep_changes_days' => 30, 'backoff_minutes' => [1, 2, 5, 15, 60],
            'priority' => ['sale.order', 'res.partner'],
        ] + (array) config('odoo');
    }

    public function install(): static
    {
        Http::fake(fn (Request $request) => $this->handle($request));

        return $this;
    }

    // ───────────── what people do in Odoo ─────────────

    public function model(string $name, array $fields, string $label = '', bool $transient = false, bool $logAccess = true): static
    {
        $defs = ['id' => ['type' => 'integer', 'string' => 'ID', 'store' => true], 'display_name' => ['type' => 'char', 'string' => 'Display Name', 'store' => false]];
        if ($logAccess) {
            $defs['write_date'] = ['type' => 'datetime', 'string' => 'Last Updated on', 'store' => true];
        }
        foreach ($fields as $field => $def) {
            $defs[$field] = (is_string($def) ? ['type' => $def] : $def) + ['string' => $field, 'store' => true];
        }
        $this->models[$name] = ['label' => $label ?: $name, 'transient' => $transient, 'fields' => $defs, 'rows' => [], 'denied' => false, 'broken' => [], 'down' => false];

        return $this;
    }

    public function tick(int $seconds = 1): static
    {
        $this->clock->addSeconds($seconds);

        return $this;
    }

    public function now(): string
    {
        return $this->clock->format('Y-m-d H:i:s');
    }

    /** @return int the new record's id */
    public function create(string $model, array $values, ?string $at = null): int
    {
        $id = isset($values['id']) ? (int) $values['id'] : ($this->models[$model]['rows'] ? max(array_keys($this->models[$model]['rows'])) + 1 : 1);
        $this->tick();
        $row = ['id' => $id] + $values;
        if (isset($this->models[$model]['fields']['write_date'])) {
            $row['write_date'] = $at ?? $this->now();
        }
        if (isset($this->models[$model]['fields']['active']) && ! array_key_exists('active', $row)) {
            $row['active'] = true;
        }
        $this->models[$model]['rows'][$id] = $row;

        return $id;
    }

    public function write(string $model, int $id, array $values, ?string $at = null): void
    {
        $this->tick();
        $this->models[$model]['rows'][$id] = array_merge($this->models[$model]['rows'][$id], $values, ['write_date' => $at ?? $this->now()]);
    }

    public function unlink(string $model, int $id): void
    {
        unset($this->models[$model]['rows'][$id]);
    }

    // ───────────── the HTTP side ─────────────

    private function handle(Request $request)
    {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $body = json_decode($request->body(), true) ?? [];
        if (str_starts_with($path, '/json/2/')) {
            if (! $this->json2) {
                return Http::response('Not found', 404);
            }
            [$model, $method] = array_slice(explode('/', $path), 3);
            // the transport probe comes without a key: 401 (not 404) tells the connector /json/2 exists
            if (($request->header('Authorization')[0] ?? '') !== 'bearer '.self::KEY || ($request->header('X-Odoo-Database')[0] ?? '') !== 'testdb') {
                return Http::response(['name' => 'odoo.exceptions.AccessDenied', 'message' => 'Access Denied'], 401);
            }
            $out = $this->execute($model, $method, $body);

            return $out['error'] ?? null ? Http::response(['name' => $out['error']['name'], 'message' => $out['error']['message']], $out['error']['status']) : Http::response(json_encode($out['result']), 200, ['Content-Type' => 'application/json']);
        }
        if ($path === '/web/version') {
            return Http::response(['version' => '19.0', 'version_info' => [19, 0, 0, 'final', 0]], 200);
        }
        if ($path !== '/jsonrpc') {
            return Http::response('Not found', 404);
        }
        $p = $body['params'] ?? [];
        $args = $p['args'] ?? [];
        $reply = fn ($result) => Http::response(['jsonrpc' => '2.0', 'id' => $body['id'] ?? null, 'result' => $result], 200);
        $fail = fn (string $name, string $message) => Http::response(['jsonrpc' => '2.0', 'id' => $body['id'] ?? null,
            'error' => ['code' => 200, 'message' => 'Odoo Server Error', 'data' => ['name' => $name, 'message' => $message]]], 200);
        if (($p['service'] ?? null) === 'common') {
            return match ($p['method'] ?? null) {
                'version' => $reply(['server_version' => '17.0', 'server_version_info' => [17, 0, 0, 'final', 0, 'e']]),
                'authenticate' => $reply(($args[2] ?? null) === self::KEY && ($args[1] ?? null) === 'mirror@test' ? 7 : false),
                default => $fail('builtins.AttributeError', 'unknown common method'),
            };
        }
        [, $uid, $key, $model, $method, $positional, $kwargs] = $args + [null, null, null, null, null, [], []];
        if ($uid !== 7 || $key !== self::KEY) {
            return $fail('odoo.exceptions.AccessDenied', 'Access Denied');
        }
        $names = ['search' => ['domain'], 'search_read' => ['domain'], 'search_count' => ['domain'], 'read' => ['ids'], 'fields_get' => []][$method] ?? [];
        $params = (array) $kwargs;
        foreach ($names as $i => $name) {
            $params[$name] = $positional[$i] ?? [];
        }
        $out = $this->execute((string) $model, (string) $method, $params);
        if ($out['error'] ?? null) {
            return $out['error']['status'] >= 500 && $out['error']['name'] === '' ? Http::response('Bad gateway', 502) : $fail($out['error']['name'], $out['error']['message']);
        }

        return $reply($out['result']);
    }

    /** @return array{result?:mixed, error?:array{name:string, message:string, status:int}} */
    private function execute(string $model, string $method, array $params): array
    {
        $this->log[] = "{$model}.{$method}";
        $err = fn (string $name, string $message, int $status) => ['error' => ['name' => $name, 'message' => $message, 'status' => $status]];
        if (! in_array($method, ['search', 'search_read', 'search_count', 'read', 'fields_get'], true)) {
            $this->writes[] = "{$model}.{$method}";

            return $err('odoo.exceptions.AccessError', 'this fake refuses writes', 403);
        }
        if ($model === 'ir.model') {
            $rows = [];
            $i = 0;
            foreach ($this->models as $name => $m) {
                $rows[] = ['id' => ++$i, 'model' => $name, 'name' => $m['label'], 'transient' => $m['transient']];
            }
            $rows = array_values(array_filter($rows, fn ($r) => $this->matches($r, $params['domain'] ?? [])));

            return ['result' => $method === 'search_count' ? count($rows) : $rows];
        }
        if ($model === 'res.users' && ! isset($this->models[$model])) {
            return ['result' => $method === 'search_count' ? 1 : [['id' => 7, 'name' => 'Mirror Bot', 'login' => 'mirror@test', 'company_id' => [1, 'Test Co'], 'tz' => 'Asia/Riyadh']]];
        }
        $m = $this->models[$model] ?? null;
        if (! $m) {
            return $err('builtins.KeyError', "Object {$model} doesn't exist", 404);
        }
        if ($m['down']) {
            return $err('', 'bad gateway', 502);
        }
        if ($m['denied']) {
            return $err('odoo.exceptions.AccessError', "You are not allowed to access '{$m['label']}' ({$model}) records.", 403);
        }
        if ($method === 'fields_get') {
            return ['result' => $m['fields']];
        }
        $rows = array_values(array_filter($m['rows'], fn ($r) => $this->matches($r, $params['domain'] ?? [])));
        if ($method === 'search_count') {
            return ['result' => count($rows)];
        }
        foreach (array_reverse($this->order((string) ($params['order'] ?? 'id asc'))) as [$field, $dir]) {
            usort($rows, fn ($a, $b) => ($dir === 'desc' ? -1 : 1) * (($a[$field] ?? null) <=> ($b[$field] ?? null)));
        }
        $rows = array_slice($rows, (int) ($params['offset'] ?? 0), isset($params['limit']) && $params['limit'] ? (int) $params['limit'] : null);
        if ($method === 'search') {
            return ['result' => array_column($rows, 'id')];
        }
        $fields = (array) ($params['fields'] ?? array_keys($m['fields']));
        if (array_intersect($fields, $m['broken'])) {
            return $err('builtins.ValueError', 'Compute method failed to assign '.implode(', ', array_intersect($fields, $m['broken'])), 500);
        }
        foreach ($fields as $f) {
            if ($f !== 'id' && ! isset($m['fields'][$f])) {
                return $err('builtins.ValueError', "Invalid field '{$f}' on model '{$model}'", 400);
            }
        }

        return ['result' => array_map(function ($r) use ($fields) {
            $out = ['id' => $r['id']];
            foreach ($fields as $f) {
                $out[$f] = $f === 'display_name' ? (string) ($r['name'] ?? "#{$r['id']}") : ($r[$f] ?? false);
            }

            return $out;
        }, $rows)];
    }

    /** The simple AND domains the mirror sends. */
    private function matches(array $row, array $domain): bool
    {
        foreach ($domain as $term) {
            [$field, $op, $value] = $term;
            $have = $row[$field] ?? null;
            $ok = match ($op) {
                '=' => $have == $value, '>' => $have > $value, '>=' => $have >= $value, '<' => $have < $value, '<=' => $have <= $value,
                'in' => in_array($have, (array) $value), default => false,
            };
            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, array{0:string, 1:string}> */
    private function order(string $order): array
    {
        return array_map(function ($part) {
            $bits = preg_split('/\s+/', trim($part));

            return [$bits[0], strtolower($bits[1] ?? 'asc')];
        }, explode(',', $order));
    }
}
