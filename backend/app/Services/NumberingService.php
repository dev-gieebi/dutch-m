<?php

namespace App\Services;

use App\Support\Domain;
use Illuminate\Support\Facades\DB;

/** Séquences atomiques (SELECT … FOR UPDATE) : commandes, produits, arrivages. */
class NumberingService
{
    public function next(string $name, int $year = 0): int
    {
        $run = function () use ($name, $year) {
            DB::statement(
                'INSERT INTO sequences (name, year, value, created_at, updated_at) VALUES (?, ?, 0, NOW(3), NOW(3)) ON DUPLICATE KEY UPDATE id = id',
                [$name, $year],
            );
            $seq = DB::table('sequences')->where('name', $name)->where('year', $year)->lockForUpdate()->first();
            $value = $seq->value + 1;
            DB::table('sequences')->where('id', $seq->id)->update(['value' => $value]);

            return $value;
        };

        return DB::transactionLevel() > 0 ? $run() : DB::transaction($run);
    }

    public static function currentYear(): int
    {
        return (int) now(Domain::TIMEZONE)->format('Y');
    }

    /** AMO-2026-000123 */
    public function orderNumber(): string
    {
        $year = self::currentYear();

        return sprintf('AMO-%d-%06d', $year, $this->next('order', $year));
    }

    /** AMO-FA-0012 */
    public function productReference(?string $categoryCode): string
    {
        $code = strtoupper($categoryCode ?: 'GEN');

        return sprintf('AMO-%s-%04d', $code, $this->next("product-{$code}"));
    }

    /** ARR-2026-001 */
    public function arrivalReference(): string
    {
        $year = self::currentYear();

        return sprintf('ARR-%d-%03d', $year, $this->next('arrival', $year));
    }
}
