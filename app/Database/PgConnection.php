<?php

namespace App\Database;

use Closure;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;

/**
 * PostgreSQL connection that keeps two MySQL behaviours the application relies on. Registered in AppServiceProvider.
 *
 * 1. `like` is case-insensitive, as it is on MySQL with the utf8mb4 *_ci collations. Every search box
 *    (`->where('name_ar', 'like', …)`) relies on that; otherwise a search for "po-2026" would silently find nothing.
 * 2. Hand-written SQL (dashboard, reports) quotes identifiers the MySQL way — AS `onHand` — because PostgreSQL folds
 *    unquoted names to lower case. Backticks mean nothing else in PostgreSQL and values always travel as bindings,
 *    so they are turned into double quotes on the way out.
 */
class PgConnection extends PostgresConnection
{
    protected function run($query, $bindings, Closure $callback)
    {
        return parent::run(str_replace('`', '"', $query), $bindings, $callback);
    }

    protected function getDefaultQueryGrammar()
    {
        return new class($this) extends PostgresGrammar
        {
            protected function whereBasic(Builder $query, $where)
            {
                $operator = strtolower($where['operator']);
                if ($operator === 'like' || $operator === 'not like') {
                    $where['operator'] = str_replace('like', 'ilike', $operator);
                }

                return parent::whereBasic($query, $where);
            }
        };
    }
}
