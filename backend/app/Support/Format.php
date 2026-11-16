<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/** Formatage et utilitaires (équivalent de format.ts + utils.ts). */
final class Format
{
    public const GABON_PHONE_REGEX = '/^\+241\d{7,9}$/';

    /** 12500 → « 12 500 FCFA ». */
    public static function fcfa(int|float|null $amount): string
    {
        $n = (int) round((float) ($amount ?? 0));

        return ($n < 0 ? '-' : '').number_format(abs($n), 0, '', ' ').' FCFA';
    }

    /**
     * Normalise un numéro gabonais au format +241XXXXXXXX.
     * Accepte « 077 12 34 56 », « 241 77123456 », « 00241 ... », « +241 ... ».
     */
    public static function normalizeGabonPhone(?string $input): ?string
    {
        if ($input === null || $input === '') {
            return null;
        }
        $digits = preg_replace('/[\s().-]/', '', $input);
        if (str_starts_with($digits, '00241')) {
            $digits = '+241'.substr($digits, 5);
        } elseif (str_starts_with($digits, '241') && strlen($digits) >= 10) {
            $digits = '+'.$digits;
        } elseif (! str_starts_with($digits, '+')) {
            $digits = '+241'.$digits;
        }

        return preg_match(self::GABON_PHONE_REGEX, $digits) ? $digits : null;
    }

    /** +241 77 12 34 56 */
    public static function phone(?string $phone): string
    {
        if (! $phone) {
            return '';
        }
        if (! preg_match('/^\+241(\d+)$/', $phone, $m)) {
            return $phone;
        }

        return trim('+241 '.preg_replace('/(\d{2})(?=\d)/', '$1 ', $m[1]));
    }

    /** Date en français dans le fuseau de Libreville : « 12 mars 2026 ». */
    public static function date(CarbonInterface|string|null $value, bool $withTime = false): string
    {
        if (! $value) {
            return '';
        }
        $d = ($value instanceof CarbonInterface ? Carbon::instance($value) : Carbon::parse($value))->setTimezone(Domain::TIMEZONE)->locale('fr');

        return $d->translatedFormat($withTime ? 'd F Y \à H:i' : 'd F Y');
    }

    public static function slugify(string $input): string
    {
        $s = \Normalizer::normalize($input, \Normalizer::FORM_D);
        $s = preg_replace('/[\x{0300}-\x{036f}]/u', '', $s);
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = trim($s, '-');

        return substr($s, 0, 180);
    }

    public static function whatsAppLink(string $number, string $message): string
    {
        return 'https://wa.me/'.preg_replace('/[^\d]/', '', $number).'?text='.rawurlencode($message);
    }

    /** Jeton aléatoire base64url. */
    public static function randomToken(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function randomDigits(int $length): string
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= random_int(0, 9);
        }

        return $out;
    }

    public static function sha256(string $value): string
    {
        return hash('sha256', $value);
    }

    /** Indique si une période datée est active. */
    public static function isWithin(?CarbonInterface $start, ?CarbonInterface $end, ?CarbonInterface $now = null): bool
    {
        $now ??= now();

        return (! $start || $start->lte($now)) && (! $end || $end->gte($now));
    }

    /** Date ISO 8601 (UTC, millisecondes) comme le sérialisait l'API NestJS. */
    public static function iso(CarbonInterface|string|null $value): ?string
    {
        if (! $value) {
            return null;
        }
        $d = $value instanceof CarbonInterface ? $value : Carbon::parse($value);

        return $d->copy()->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
