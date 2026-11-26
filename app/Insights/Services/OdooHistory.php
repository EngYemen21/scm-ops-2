<?php

namespace App\Insights\Services;

use App\Insights\Support\Settings;
use App\Integration\Odoo\Projection\Val;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Odoo's customer invoices and credit notes, read from the local copy (never from Odoo) into two tables of the section,
 * so the brand dashboard and the operations insights have the years before OPS:
 *   ins_odoo_invoices    one row per `account.move` of type out_invoice / out_refund (number, partner, dates, totals,
 *                        what is still open);
 *   ins_odoo_sale_lines  one row per product line of those documents (`account.move.line`, display_type product):
 *                        product, quantity and amount before VAT — negative on a credit note.
 * The partner kept is the COMMERCIAL partner (the company, not the contact who signed); it becomes an OPS customer at
 * query time through `int_external_refs` (`odoo` / `customer`), the product through `odoo` / `product` — so a link made
 * later counts at once.
 *
 * The first pass walks the copy in slices of the table's id and keeps its place (`ins_settings` history.state): it can
 * be stopped and resumed at any time (`advance()` with a time budget; command `scm:insights-history`). Afterwards the
 * same call follows the copy's change feed (`odoo_changes`) from the point the first pass began. Amounts are Odoo's
 * own figures, rounded once to the halala when stored. These tables are a derived copy: a record deleted in Odoo is
 * removed from them; nothing of OPS's own is ever touched.
 */
class OdooHistory
{
    public const TYPES = ['out_invoice', 'out_refund'];

    private const CHUNK = 1000;

    /** @var array<int, array{t:string, s:string, d:?string, p:?string}>|null invoice header by Odoo id */
    private ?array $moves = null;

    /** The tables exist (a release whose migration has not run yet answers "not installed"). */
    public function installed(): bool
    {
        return Schema::hasTable('ins_odoo_sale_lines');
    }

    /** @return array{installed:bool, phase:?string, startedAt:?string, finishedAt:?string, invoices:int, lines:int, waiting:int} */
    public function status(): array
    {
        if (! $this->installed()) {
            return ['installed' => false, 'phase' => null, 'startedAt' => null, 'finishedAt' => null, 'invoices' => 0, 'lines' => 0, 'waiting' => 0];
        }
        $s = Settings::get(Settings::HISTORY);
        $waiting = $s && $s['phase'] === 'feed' ? DB::table('odoo_changes')->whereIn('model', ['account.move', 'account.move.line'])->where('id', '>', (int) $s['feed'])->count() : 0;

        return [
            'installed' => true, 'phase' => $s['phase'] ?? null, 'startedAt' => $s['startedAt'] ?? null, 'finishedAt' => $s['finishedAt'] ?? null,
            'invoices' => DB::table('ins_odoo_invoices')->count(), 'lines' => DB::table('ins_odoo_sale_lines')->count(), 'waiting' => $waiting,
        ];
    }

    /**
     * Does as much as `$seconds` allows (one slice at least) and says where it stands.
     *
     * @return array{phase:string, invoices:int, lines:int, removed:int, changes:int, done:bool}
     */
    public function advance(float $seconds = 20.0, bool $fresh = false): array
    {
        $until = microtime(true) + $seconds;
        $s = $fresh ? null : Settings::get(Settings::HISTORY);
        if (! $s) {
            // what the feed holds when the first pass begins is covered by the pass; anything later is followed afterwards
            $s = ['phase' => 'moves', 'cursor' => 0, 'feed' => (int) DB::table('odoo_changes')->max('id'), 'startedAt' => now()->toJSON(), 'finishedAt' => null];
            Settings::put(Settings::HISTORY, $s, 'system');
        }
        $out = ['invoices' => 0, 'lines' => 0, 'removed' => 0, 'changes' => 0];
        $this->moves = null;
        do {
            $moved = match ($s['phase']) {
                'moves' => $this->slice('account.move', $s, $out),
                'lines' => $this->slice('account.move.line', $s, $out),
                default => $this->follow($s, $out),
            };
            Settings::put(Settings::HISTORY, $s, 'system');
        } while ($moved && microtime(true) < $until);

        return ['phase' => $s['phase']] + $out + ['done' => $s['phase'] === 'feed' && ! $moved];
    }

    /** One slice of the first pass. @return bool there may be more to do */
    private function slice(string $model, array &$s, array &$out): bool
    {
        $rows = DB::table('odoo_records')->where('model', $model)->where('id', '>', (int) $s['cursor'])->orderBy('id')->limit(self::CHUNK)->get(['id', 'odoo_id', 'data', 'deleted_at']);
        if ($rows->isEmpty()) {
            if ($model === 'account.move') {
                $s = ['phase' => 'lines', 'cursor' => 0] + $s;
            } else {
                $s = ['phase' => 'feed', 'finishedAt' => now()->toJSON()] + $s;
                unset($s['cursor']);
            }

            return true;
        }
        DB::transaction(function () use ($model, $rows, &$out) {
            foreach ($rows as $r) {
                $this->apply($model, (int) $r->odoo_id, $r->deleted_at === null ? (json_decode((string) $r->data, true) ?: []) : null, $out);
            }
        });
        $s['cursor'] = (int) $rows->last()->id;

        return true;
    }

    /** The change feed after the first pass. @return bool there may be more to do */
    private function follow(array &$s, array &$out): bool
    {
        $changes = DB::table('odoo_changes')->whereIn('model', ['account.move', 'account.move.line'])->where('id', '>', (int) $s['feed'])->orderBy('id')->limit(self::CHUNK)->get(['id', 'model', 'odoo_id']);
        if ($changes->isEmpty()) {
            return false;
        }
        DB::transaction(function () use ($changes, &$out) {
            // the headers first: a line looks its invoice up
            foreach ($changes->sortBy(fn ($c) => [$c->model === 'account.move' ? 0 : 1, $c->id]) as $c) {
                $r = DB::table('odoo_records')->where('model', $c->model)->where('odoo_id', $c->odoo_id)->first(['data', 'deleted_at']);
                $this->apply($c->model, (int) $c->odoo_id, $r && $r->deleted_at === null ? (json_decode((string) $r->data, true) ?: []) : null, $out);
                $out['changes']++;
            }
        });
        $s['feed'] = (int) $changes->last()->id;

        return true;
    }

    /** One record of the copy as it is now (`$d` null = gone from Odoo). */
    private function apply(string $model, int $odooId, ?array $d, array &$out): void
    {
        if ($model === 'account.move') {
            $this->invoice($odooId, $d, $out);
        } else {
            $this->line($odooId, $d, $out);
        }
    }

    private function invoice(int $odooId, ?array $d, array &$out): void
    {
        $type = $d['move_type'] ?? null;
        if ($d === null || ! in_array($type, self::TYPES, true)) {
            $gone = DB::table('ins_odoo_invoices')->where('odoo_id', $odooId)->delete();
            if ($gone) {
                $out['removed'] += $gone + DB::table('ins_odoo_sale_lines')->where('move_id', $odooId)->delete();
                unset($this->moves[$odooId]);
            }

            return;
        }
        $partner = Val::id($d['commercial_partner_id'] ?? null) ?? Val::id($d['partner_id'] ?? null);
        $row = [
            'odoo_id' => $odooId, 'number' => mb_substr((string) (Val::text($d['name'] ?? null) ?? "#{$odooId}"), 0, 64), 'move_type' => $type,
            'state' => mb_substr((string) ($d['state'] ?? ''), 0, 12), 'payment_state' => mb_substr((string) (is_string($d['payment_state'] ?? null) ? $d['payment_state'] : ''), 0, 16),
            'partner_ref' => $partner ? (string) $partner : null, 'partner_name' => mb_substr((string) (Val::label($d['commercial_partner_id'] ?? null) ?? Val::label($d['partner_id'] ?? null) ?? ''), 0, 190) ?: null,
            'invoice_date' => Val::date($d['invoice_date'] ?? null) ?? Val::date($d['date'] ?? null), 'due_date' => Val::date($d['invoice_date_due'] ?? null),
            'amount_untaxed' => round(Val::float($d['amount_untaxed'] ?? 0), 2), 'amount_total' => round(Val::float($d['amount_total'] ?? 0), 2),
            'residual' => round(Val::float($d['amount_residual'] ?? 0), 2), 'currency' => mb_substr((string) (Val::label($d['currency_id'] ?? null) ?? ''), 0, 8) ?: null, 'updated_at' => now(),
        ];
        DB::table('ins_odoo_invoices')->upsert([$row], ['odoo_id'], array_keys(array_diff_key($row, ['odoo_id' => 1])));
        // what the lines carry of their invoice follows it (a credit note posted, an invoice cancelled, a date corrected)
        DB::table('ins_odoo_sale_lines')->where('move_id', $odooId)->update(['move_type' => $type, 'state' => $row['state'], 'invoice_date' => $row['invoice_date'], 'partner_ref' => $row['partner_ref']]);
        if ($this->moves !== null) {
            $this->moves[$odooId] = ['t' => $type, 's' => $row['state'], 'd' => $row['invoice_date'], 'p' => $row['partner_ref']];
        }
        $out['invoices']++;
    }

    private function line(int $odooId, ?array $d, array &$out): void
    {
        $move = $d ? $this->move(Val::id($d['move_id'] ?? null)) : null;
        $display = $d['display_type'] ?? false;
        $product = $d ? Val::id($d['product_id'] ?? null) : null;
        // a product line of a customer invoice / credit note: not a tax, a term, a section, a note or a cost-of-sales line
        if (! $move || ! $product || ! ($display === 'product' || $display === false || $display === null)) {
            $out['removed'] += DB::table('ins_odoo_sale_lines')->where('odoo_id', $odooId)->delete();

            return;
        }
        $sign = $move['t'] === 'out_refund' ? -1 : 1;
        $row = [
            'odoo_id' => $odooId, 'move_id' => Val::id($d['move_id']), 'move_type' => $move['t'], 'state' => $move['s'], 'invoice_date' => $move['d'], 'partner_ref' => $move['p'],
            'product_ref' => (string) $product, 'product_name' => mb_substr((string) (Val::label($d['product_id'] ?? null) ?? ''), 0, 190) ?: null,
            'qty' => round($sign * Val::float($d['quantity'] ?? 0), 3), 'amount' => round($sign * Val::float($d['price_subtotal'] ?? 0), 2),
        ];
        DB::table('ins_odoo_sale_lines')->upsert([$row], ['odoo_id'], array_keys(array_diff_key($row, ['odoo_id' => 1])));
        $out['lines']++;
    }

    /** @return array{t:string, s:string, d:?string, p:?string}|null */
    private function move(?int $id): ?array
    {
        if (! $id) {
            return null;
        }
        if ($this->moves === null) {
            $this->moves = [];
            foreach (DB::table('ins_odoo_invoices')->select(['odoo_id', 'move_type', 'state', 'invoice_date', 'partner_ref'])->lazyById(5000, 'odoo_id') as $m) {
                $this->moves[(int) $m->odoo_id] = ['t' => $m->move_type, 's' => $m->state, 'd' => $m->invoice_date, 'p' => $m->partner_ref];
            }
        }

        return $this->moves[$id] ?? null;
    }
}
