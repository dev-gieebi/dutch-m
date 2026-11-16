<?php

namespace App\Support;

/** Réponse paginée : { data: [...], meta: { page, limit, total } }. */
final class Paginated
{
    /** @param  array{page: int, limit: int}  $q */
    public static function make(array $data, int $total, array $q): array
    {
        return ['data' => array_values($data), 'meta' => ['page' => (int) $q['page'], 'limit' => (int) $q['limit'], 'total' => $total]];
    }

    /** @param  array{page: int, limit: int}  $q */
    public static function offset(array $q): int
    {
        return ($q['page'] - 1) * $q['limit'];
    }
}
