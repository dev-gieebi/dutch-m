<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** Liste stockée « a,b,c » dans une colonne TEXT (équivalent TypeORM « simple-array »). */
class SimpleArray implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        return $value === null || $value === '' ? [] : explode(',', $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return implode(',', array_values((array) $value));
    }
}
