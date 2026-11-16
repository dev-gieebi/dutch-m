<?php

namespace App\Services;

use App\Models\Setting;
use App\Support\DefaultSettings;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Paramètres de la boutique (table settings), fusionnés avec les valeurs par défaut. */
class SettingsService
{
    private ?array $cache = null;

    public function __construct(private readonly AuditService $audit) {}

    private static function isPlainObject(mixed $v): bool
    {
        return is_array($v) && ($v === [] || ! array_is_list($v));
    }

    private function stored(): array
    {
        return $this->cache ??= Setting::query()->pluck('value', 'key')->all();
    }

    public function get(string $key): mixed
    {
        $def = DefaultSettings::all()[$key] ?? null;
        $stored = $this->stored();
        if (! array_key_exists($key, $stored)) {
            return $def;
        }
        $value = $stored[$key];
        if (self::isPlainObject($def) && self::isPlainObject($value)) {
            return array_replace($def, $value);
        }

        return $value;
    }

    public function getAll(): array
    {
        $out = [];
        foreach (array_keys(DefaultSettings::all()) as $k) {
            $out[$k] = $this->get($k);
        }

        return $out;
    }

    public function getPublic(): array
    {
        $all = $this->getAll();
        $out = [];
        foreach (DefaultSettings::PUBLIC_KEYS as $k) {
            $out[$k] = $all[$k];
        }
        $out['supportOnline'] = DefaultSettings::isSupportOpen($all['supportHours']['slots'] ?? []);

        return $out;
    }

    public function update(array $patch, int $adminUserId, $meta): array
    {
        $defaults = DefaultSettings::all();
        foreach ($patch as $key => $value) {
            if (! array_key_exists($key, $defaults)) {
                throw new HttpException(400, "Paramètre inconnu : {$key}");
            }
            $def = $defaults[$key];
            if (self::isPlainObject($def) && ! self::isPlainObject($value)) {
                throw new HttpException(400, "Format invalide pour {$key}");
            }
            $this->validate($key, $value);
            $before = $this->get($key);
            $merged = self::isPlainObject($def) ? array_replace((array) $before, $value) : $value;
            Setting::updateOrCreate(['key' => $key], ['value' => $merged]);
            $this->cache = null;
            $this->audit->log($adminUserId, 'settings.update', 'setting', $key, $before, $merged, $meta);
        }

        return $this->getAll();
    }

    private function validate(string $key, mixed $value): void
    {
        $isNum = fn ($v) => is_int($v) || is_float($v);
        if ($key === 'payments' && is_array($value)) {
            $pct = $value['depositPercent'] ?? null;
            if (array_key_exists('depositPercent', $value) && (! $isNum($pct) || $pct < 0 || $pct > 100)) {
                throw new HttpException(400, "Le pourcentage d'acompte doit être compris entre 0 et 100");
            }
            $exp = $value['expiryMinutes'] ?? null;
            if (array_key_exists('expiryMinutes', $value) && (! $isNum($exp) || $exp < 5 || $exp > 1440)) {
                throw new HttpException(400, "Le délai d'expiration doit être compris entre 5 et 1440 minutes");
            }
        }
        if ($key === 'freeShippingThreshold' && $value !== null && (! $isNum($value) || $value < 0)) {
            throw new HttpException(400, 'Seuil de livraison gratuite invalide');
        }
        if ($key === 'preorder' && is_array($value) && array_key_exists('defaultLeadTimeDays', $value)) {
            $d = $value['defaultLeadTimeDays'];
            if (! $isNum($d) || $d < 1 || $d > 365) {
                throw new HttpException(400, 'Délai invalide');
            }
        }
    }
}
