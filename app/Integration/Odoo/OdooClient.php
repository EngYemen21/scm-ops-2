<?php

namespace App\Integration\Odoo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Read-only connector to Odoo's external API.
 *
 * The guarantee: every call to an Odoo model goes through call(), and call() refuses any method that is not in
 * READ_METHODS before a single byte is sent. This class has no way to create, write, unlink or run an action, so OPS
 * cannot change anything in Odoo whatever the Odoo user is allowed to do. (The stronger guarantee is on the Odoo side:
 * a dedicated user whose access rights are read-only.)
 *
 * Two transports, chosen once and remembered: `/json/2` (Odoo 19 and later, bearer key) and `/jsonrpc` (earlier
 * versions, execute_kw with the user's id). Archived records are always included (`active_test` off): an archived
 * product is still a fact OPS must know.
 */
class OdooClient
{
    public const READ_METHODS = ['search', 'search_read', 'search_count', 'read', 'fields_get'];

    /** Arguments /jsonrpc expects by position, per method. */
    private const POSITIONAL = ['search' => ['domain'], 'search_read' => ['domain'], 'search_count' => ['domain'], 'read' => ['ids'], 'fields_get' => []];

    /** HTTP calls made through this instance (a run reports it). */
    public int $calls = 0;

    private array $config;

    private ?string $transport = null;

    private ?int $uid = null;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (array) config('odoo');
    }

    public function configured(): bool
    {
        return ! empty($this->config['url']) && ! empty($this->config['key']);
    }

    public function enabled(): bool
    {
        return ! empty($this->config['enabled']) && $this->configured();
    }

    /** What may be shown to a person about the connection — never the key. */
    public function describe(): array
    {
        return [
            'configured' => $this->configured(), 'enabled' => $this->enabled(),
            'host' => $this->configured() ? parse_url($this->config['url'], PHP_URL_HOST) : null,
            'db' => $this->config['db'] ?? null, 'login' => $this->config['login'] ?? null,
        ];
    }

    // ───────────── the one door to Odoo models ─────────────

    public function call(string $model, string $method, array $params = [], ?int $timeout = null): mixed
    {
        if (! in_array($method, self::READ_METHODS, true)) {
            throw new \LogicException("Odoo connector is read-only: method `{$method}` is refused.");
        }
        if (! preg_match('/^[a-z0-9_.]+$/', $model)) {
            throw new \LogicException("Invalid Odoo model name `{$model}`.");
        }
        $this->assertUsable();
        if ($method !== 'fields_get') {
            $params['context'] = ($params['context'] ?? []) + array_filter(['active_test' => false, 'lang' => $this->config['lang'] ?? null], fn ($v) => $v !== null);
        }

        return $this->transport() === 'json2' ? $this->json2($model, $method, $params, $timeout) : $this->jsonRpc($model, $method, $params, $timeout);
    }

    /** @return array<int, array<string, mixed>> */
    public function searchRead(string $model, array $domain, array $fields, int $limit, string $order = 'id asc', int $offset = 0, ?int $timeout = null): array
    {
        return (array) $this->call($model, 'search_read', ['domain' => $domain, 'fields' => $fields, 'limit' => $limit, 'offset' => $offset, 'order' => $order], $timeout);
    }

    /** @return int[] */
    public function search(string $model, array $domain, int $limit, string $order = 'id asc'): array
    {
        return array_map('intval', (array) $this->call($model, 'search', ['domain' => $domain, 'limit' => $limit, 'order' => $order]));
    }

    public function searchCount(string $model, array $domain = []): int
    {
        return (int) $this->call($model, 'search_count', ['domain' => $domain]);
    }

    /** @return array<string, array{type:string, string?:string, store?:bool, relation?:string}> */
    public function fieldsGet(string $model): array
    {
        return (array) $this->call($model, 'fields_get', ['attributes' => ['type', 'string', 'store', 'relation']]);
    }

    /** @return array{version:?string, transport:string} — needs no key */
    public function version(): array
    {
        $this->assertUsable();
        if ($this->transport() === 'jsonrpc') {
            $info = (array) $this->rpc('common', 'version', []);

            return ['version' => $info['server_version'] ?? null, 'transport' => 'jsonrpc'];
        }
        $r = $this->send('get', '/web/version', null, []);

        return ['version' => $r->json('version'), 'transport' => 'json2'];
    }

    /** Signs in and reads the user behind the key: proves the connection end to end. */
    public function whoAmI(): array
    {
        $login = $this->config['login'] ?? null;
        $rows = $login ? $this->searchRead('res.users', [['login', '=', $login]], ['name', 'login', 'company_id', 'tz'], 1) : [];

        return ['name' => $rows[0]['name'] ?? null, 'company' => $rows[0]['company_id'][1] ?? null, 'tz' => $rows[0]['tz'] ?? null];
    }

    // ───────────── transports ─────────────

    private function assertUsable(): void
    {
        if (! $this->configured()) {
            throw new OdooError('auth', 'Odoo connection is not configured (ODOO_URL / ODOO_API_KEY).');
        }
        $host = parse_url($this->config['url'], PHP_URL_HOST);
        $local = in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with((string) $host, '.test') || str_ends_with((string) $host, '.localhost');
        if (parse_url($this->config['url'], PHP_URL_SCHEME) !== 'https' && ! $local) {
            throw new OdooError('auth', 'ODOO_URL must start with https:// — the key would travel unencrypted.');
        }
    }

    private function cacheKey(string $what): string
    {
        return "odoo:{$what}:".sha1(($this->config['url'] ?? '').'|'.($this->config['db'] ?? '').'|'.($this->config['login'] ?? '').'|'.sha1((string) ($this->config['key'] ?? '')));
    }

    private function transport(): string
    {
        if ($this->transport) {
            return $this->transport;
        }
        $wanted = $this->config['api'] ?? 'auto';
        if (in_array($wanted, ['json2', 'jsonrpc'], true)) {
            return $this->transport = $wanted;
        }

        // Odoo 19+ serves /json/2; earlier versions answer 404 (or 405). Probed without the key.
        return $this->transport = Cache::remember($this->cacheKey('transport'), now()->addDay(), function () {
            $r = $this->send('post', '/json/2/res.company/search_count', ['domain' => []], [], check: false);

            return in_array($r->status(), [404, 405], true) ? 'jsonrpc' : 'json2';
        });
    }

    private function json2(string $model, string $method, array $params, ?int $timeout): mixed
    {
        $headers = ['Authorization' => 'bearer '.$this->config['key']] + (empty($this->config['db']) ? [] : ['X-Odoo-Database' => $this->config['db']]);
        $r = $this->send('post', "/json/2/{$model}/{$method}", $params, $headers, $timeout, check: false);
        if (! $r->successful()) {
            throw $this->classify((string) ($r->json('message') ?? "Odoo HTTP {$r->status()}"), (string) $r->json('name'), $r->status());
        }

        return $r->json();
    }

    private function jsonRpc(string $model, string $method, array $params, ?int $timeout): mixed
    {
        if (empty($this->config['db']) || empty($this->config['login'])) {
            throw new OdooError('auth', 'This Odoo version needs ODOO_DB and ODOO_LOGIN.');
        }
        $args = [];
        foreach (self::POSITIONAL[$method] as $name) {
            $args[] = $params[$name] ?? [];
            unset($params[$name]);
        }
        try {
            return $this->rpc('object', 'execute_kw', [$this->config['db'], $this->uid(), $this->config['key'], $model, $method, $args, (object) $params], $timeout);
        } catch (OdooError $e) {
            if ($e->kind !== 'auth') {
                throw $e;
            }
            // the remembered user id may belong to a key that was replaced: sign in again once
            Cache::forget($this->cacheKey('uid'));
            $this->uid = null;

            return $this->rpc('object', 'execute_kw', [$this->config['db'], $this->uid(), $this->config['key'], $model, $method, $args, (object) $params], $timeout);
        }
    }

    private function uid(): int
    {
        return $this->uid ??= (int) Cache::remember($this->cacheKey('uid'), now()->addHours(12), function () {
            $uid = $this->rpc('common', 'authenticate', [$this->config['db'], $this->config['login'], $this->config['key'], (object) []]);
            if (! $uid) {
                throw new OdooError('auth', 'Odoo refused the login or the key.', 401);
            }

            return (int) $uid;
        });
    }

    private function rpc(string $service, string $method, array $args, ?int $timeout = null): mixed
    {
        $r = $this->send('post', '/jsonrpc', ['jsonrpc' => '2.0', 'method' => 'call', 'id' => random_int(1, PHP_INT_MAX), 'params' => ['service' => $service, 'method' => $method, 'args' => $args]], [], $timeout);
        $body = $r->json();
        if (! is_array($body)) {
            throw new OdooError('transport', 'Odoo answered something that is not JSON.', $r->status());
        }
        if (isset($body['error'])) {
            $data = $body['error']['data'] ?? [];
            throw $this->classify((string) ($data['message'] ?? $body['error']['message'] ?? 'Odoo error'), (string) ($data['name'] ?? ''));
        }

        return $body['result'] ?? null;
    }

    private function send(string $verb, string $path, ?array $json, array $headers, ?int $timeout = null, bool $check = true): Response
    {
        $this->calls++;
        try {
            $http = Http::timeout($timeout ?? (int) ($this->config['timeout_seconds'] ?? 25))->connectTimeout(10)->acceptJson()->withHeaders($headers)->withoutRedirecting();
            $r = $verb === 'get' ? $http->get($this->config['url'].$path) : $http->asJson()->post($this->config['url'].$path, $json);
        } catch (ConnectionException $e) {
            throw new OdooError('transport', 'Could not reach Odoo: '.$this->safe($e->getMessage()));
        }
        if ($check && ! $r->successful()) {
            throw new OdooError('transport', "Odoo HTTP {$r->status()}", $r->status());
        }

        return $r;
    }

    private function classify(string $message, string $name, ?int $status = null): OdooError
    {
        $message = $this->safe($message);
        $kind = match (true) {
            str_contains($name, 'AccessDenied') || $status === 401 => 'auth',
            str_contains($name, 'AccessError') || $status === 403 => 'access',
            (bool) preg_match("/doesn'?t exist|does not exist|Invalid field|unknown field/i", $message) || $status === 404 => 'missing',
            $status !== null && $status >= 500 && $name === '' => 'transport',
            default => 'odoo',
        };

        return new OdooError($kind, mb_substr($message, 0, 500), $status);
    }

    /** An error text never carries the key, whatever a library put in it. */
    private function safe(string $text): string
    {
        $key = (string) ($this->config['key'] ?? '');

        return $key !== '' ? str_replace($key, '***', $text) : $text;
    }
}
