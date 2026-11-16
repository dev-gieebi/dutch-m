<?php

namespace App\Models;

use App\Support\Format;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Modèle de base : colonnes snake_case en base, sérialisation JSON en camelCase
 * (même format que l'ancienne API NestJS/TypeORM).
 */
abstract class BaseModel extends Model
{
    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** Attributs camelCase acceptés en entrée et convertis en snake_case. */
    public function fillCamel(array $data): static
    {
        foreach ($data as $key => $value) {
            $this->setAttribute(Str::snake($key), $value);
        }

        return $this;
    }

    public function toArray(): array
    {
        return self::camelKeys(parent::toArray());
    }

    /** Attributs seuls (sans relations), en camelCase. */
    public function attributesToArrayCamel(): array
    {
        return self::camelKeys($this->attributesToArray());
    }

    public static function camelKeys(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $k = is_string($key) && str_contains($key, '_') && strtolower($key) === $key ? Str::camel($key) : $key;
            $out[$k] = is_array($value) && ! array_is_list($value) ? self::camelKeys($value) : (is_array($value) ? array_map(fn ($v) => is_array($v) ? self::camelKeys($v) : $v, $value) : $value);
        }

        return $out;
    }

    protected function serializeDate(DateTimeInterface $date): string
    {
        return Format::iso(\Illuminate\Support\Carbon::instance($date));
    }
}
