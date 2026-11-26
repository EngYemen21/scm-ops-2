<?php

namespace App\Commissions\Services;

use App\Commissions\Models\CommissionAssignment;
use App\Commissions\Support\Doc;
use App\Commissions\Support\People;
use Illuminate\Support\Facades\DB;

/**
 * Whose sale an invoice is. OPS documents carry no salesperson, so it is read from what they do carry, in this order —
 * and the line of the statement says which one answered (`salesperson_via`):
 *
 *   rep       the representative the sales platform names on the order (`sales_orders.commercial.salesRep`): the OPS
 *             user a person assigned that name to, or the user whose login / e-mail it is — never a guess by a similar name;
 *   customer  the salesperson a person assigned the invoice's customer to;
 *   creator   the user who entered the order in OPS (a service account — an order that arrived from another system —
 *             is nobody's sale).
 *
 * An invoice nobody answers for earns no salesperson a commission (a manager plan on "every invoice" still counts it);
 * `GET /commissions/unassigned` lists those, so that a person assigns their customers.
 */
class Salespeople
{
    /** @var array<string, array<string, string>>|null kind → subject → user id */
    private ?array $assigned = null;

    /** @var array<string, true>|null users who may earn (active, not a service account) */
    private ?array $users = null;

    /** @var array<string, ?string>|null lower-case login / e-mail → the one user it names (null when two answer) */
    private ?array $identity = null;

    /** @var array<string, array{creator:?string, rep:mixed}> sales order id → what it says */
    private array $orders = [];

    /**
     * Forgets what was read: a run starts from what the tables hold NOW (the same service object may serve several
     * requests of a long-lived worker — an assignment made between two runs must count in the second).
     */
    public function fresh(): void
    {
        $this->assigned = $this->users = $this->identity = null;
        $this->orders = [];
    }

    /**
     * Reads the orders of these documents once.
     *
     * @param  array<string, Doc>  $docs
     */
    public function prime(array $docs): void
    {
        $ids = [];
        foreach ($docs as $d) {
            if ($d->soId && ! isset($this->orders[$d->soId])) {
                $ids[$d->soId] = true;
            }
        }
        foreach (array_chunk(array_keys($ids), 1000) as $chunk) {
            foreach (DB::table('sales_orders')->whereIn('id', $chunk)->get(['id', 'created_by_id', 'commercial']) as $o) {
                $commercial = is_string($o->commercial) ? json_decode($o->commercial, true) : null;
                $this->orders[(string) $o->id] = ['creator' => $o->created_by_id ? (string) $o->created_by_id : null, 'rep' => is_array($commercial) ? ($commercial['salesRep'] ?? null) : null];
            }
        }
    }

    /** @return array{0: ?string, 1: ?string} [the OPS user whose sale it is, how that is known: rep | customer | creator] */
    public function of(Doc $doc): array
    {
        $this->load();
        $order = $doc->soId ? ($this->orders[$doc->soId] ?? null) : null;
        foreach (self::repKeys($order['rep'] ?? null) as $key) {
            $id = $this->assigned['rep'][$key] ?? $this->identity[$key] ?? null;
            if ($id !== null && isset($this->users[$id])) {
                return [$id, 'rep'];
            }
        }
        $id = $this->assigned['customer'][$doc->customerId] ?? null;
        if ($id !== null && isset($this->users[$id])) {
            return [$id, 'customer'];
        }
        $id = $order['creator'] ?? null;
        if ($id !== null && isset($this->users[$id])) {
            return [$id, 'creator'];
        }

        return [null, null];
    }

    /** The representative the sales platform names on the document's order, as written (for the "unassigned" list). */
    public function repName(Doc $doc): ?string
    {
        $rep = $doc->soId ? ($this->orders[$doc->soId]['rep'] ?? null) : null;
        if (is_array($rep)) {
            $rep = $rep['name'] ?? $rep['nameAr'] ?? $rep['email'] ?? $rep['id'] ?? null;
        }

        return is_scalar($rep) && trim((string) $rep) !== '' ? mb_substr(trim((string) $rep), 0, 190) : null;
    }

    /** A representative's name as it is compared: trimmed, one space between words, lower case. */
    public static function repKey(?string $name): ?string
    {
        $key = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $name)));

        return $key === '' ? null : mb_substr($key, 0, 190);
    }

    /** What an order's `salesRep` offers to be recognised by: the text itself, or the identities of a structured value. @return string[] */
    private static function repKeys(mixed $rep): array
    {
        $values = is_array($rep) ? array_intersect_key($rep, array_flip(['email', 'username', 'login', 'code', 'id', 'name', 'nameAr', 'nameEn'])) : [$rep];

        return array_values(array_unique(array_filter(array_map(fn ($v) => is_scalar($v) ? self::repKey((string) $v) : null, $values))));
    }

    private function load(): void
    {
        if ($this->assigned !== null) {
            return;
        }
        $this->assigned = ['customer' => [], 'rep' => []];
        foreach (CommissionAssignment::get(['kind', 'subject', 'user_id']) as $a) {
            $this->assigned[$a->kind][$a->kind === 'rep' ? (string) self::repKey($a->subject) : (string) $a->subject] = (string) $a->user_id;
        }
        $this->users = $this->identity = [];
        foreach (DB::table('users')->where('active', true)->where('username', 'not like', People::SERVICE_PREFIX.'%')->get(['id', 'username', 'email']) as $u) {
            $this->users[(string) $u->id] = true;
            foreach (array_filter([$u->username, $u->email]) as $name) {
                $key = (string) self::repKey((string) $name);
                // a login that is also somebody else's e-mail names nobody
                $this->identity[$key] = array_key_exists($key, $this->identity) && $this->identity[$key] !== (string) $u->id ? null : (string) $u->id;
            }
        }
    }
}
