<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Gumagawa ng numero na PREFIX-YYYYMMDD-0001 (A3).
 * Nagsisimula ulit sa 0001 bawat araw.
 *
 * DAPAT tawagin sa loob ng DB::transaction(), para naka-lock ang bilang
 * hanggang ma-save ang record. Kapag nag-rollback, babalik din ang bilang.
 *
 *   DocumentNumber::next('PR')   // PR-20261125-0001
 */
class DocumentNumber
{
    public static function next(string $prefix): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('DocumentNumber::next() ay dapat nasa loob ng DB::transaction().');
        }

        $date = now()->toDateString();          // Asia/Manila (APP_TIMEZONE)
        $now = now();

        // Siguraduhing may row para sa prefix at araw na ito (walang epekto kung mayroon na).
        DB::table('document_sequences')->insertOrIgnore([
            'prefix' => $prefix,
            'sequence_date' => $date,
            'last_number' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // I-lock ang row: maghihintay ang ibang sabay na request hanggang matapos ito.
        $row = DB::table('document_sequences')
            ->where('prefix', $prefix)
            ->where('sequence_date', $date)
            ->lockForUpdate()
            ->first();

        $number = $row->last_number + 1;

        DB::table('document_sequences')->where('id', $row->id)->update([
            'last_number' => $number,
            'updated_at' => $now,
        ]);

        return sprintf('%s-%s-%04d', $prefix, now()->format('Ymd'), $number);
    }
}
