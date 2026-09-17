<?php

namespace App\Services\Core;

use Illuminate\Support\Facades\DB;

/**
 * Document numbering (PO-2026-00465 …). Sequences live in number_sequences and are advanced under a row lock,
 * so two concurrent requests can never receive the same number. Joins the caller's transaction when there is one
 * (a rolled-back document then also gives its number back).
 */
class NumberingService
{
    public function next(string $key): string
    {
        return DB::transaction(function () use ($key) {
            $row = DB::table('number_sequences')->where('key', $key)->lockForUpdate()->first();
            if (! $row) {
                $prefix = $key.'-'.date('Y').'-';
                DB::table('number_sequences')->insert(['key' => $key, 'prefix' => $prefix, 'next' => 2, 'width' => 5]);

                return $prefix.str_pad('1', 5, '0', STR_PAD_LEFT);
            }
            DB::table('number_sequences')->where('key', $key)->update(['next' => $row->next + 1]);

            return $row->prefix.str_pad((string) $row->next, (int) $row->width, '0', STR_PAD_LEFT);
        });
    }
}
