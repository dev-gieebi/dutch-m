<?php

namespace App\Support;

/**
 * Moteur de gabarits compatible avec le sous-ensemble Handlebars utilisé par les notifications :
 * {{variable}} (échappé), {{{variable}}} (brut), {{#if variable}}…{{else}}…{{/if}} (imbriquable).
 */
final class Template
{
    /** @throws \InvalidArgumentException si les blocs {{#if}} / {{/if}} ne sont pas équilibrés */
    public static function validate(string $template): void
    {
        $open = preg_match_all('/\{\{#if\s+[\w.]+\s*\}\}/', $template);
        $close = preg_match_all('/\{\{\/if\}\}/', $template);
        if ($open !== $close) {
            throw new \InvalidArgumentException('bloc {{#if}} non fermé');
        }
        if (preg_match('/\{\{(?![{#\/]|else\}\})[^}]*[^\w.\s}][^}]*\}\}/', $template, $m)) {
            throw new \InvalidArgumentException("expression non prise en charge : {$m[0]}");
        }
    }

    public static function render(string $template, array $data, bool $escape = true): string
    {
        // Blocs conditionnels, du plus interne au plus externe.
        $pattern = '/\{\{#if\s+([\w.]+)\s*\}\}((?:(?!\{\{#if\b).)*?)\{\{\/if\}\}/s';
        while (preg_match($pattern, $template)) {
            $template = preg_replace_callback($pattern, function ($m) use ($data) {
                [$then, $else] = array_pad(explode('{{else}}', $m[2], 2), 2, '');

                return self::truthy(self::lookup($data, $m[1])) ? $then : $else;
            }, $template);
        }
        $template = preg_replace_callback('/\{\{\{\s*([\w.]+)\s*\}\}\}/', fn ($m) => self::str(self::lookup($data, $m[1])), $template);

        return preg_replace_callback('/\{\{\s*([\w.]+)\s*\}\}/', function ($m) use ($data, $escape) {
            $v = self::str(self::lookup($data, $m[1]));

            return $escape ? htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $v;
        }, $template);
    }

    private static function lookup(array $data, string $path): mixed
    {
        $v = $data;
        foreach (explode('.', $path) as $k) {
            if (! is_array($v) || ! array_key_exists($k, $v)) {
                return null;
            }
            $v = $v[$k];
        }

        return $v;
    }

    private static function truthy(mixed $v): bool
    {
        return ! ($v === null || $v === false || $v === '' || $v === 0 || $v === []);
    }

    private static function str(mixed $v): string
    {
        return is_scalar($v) ? (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v) : '';
    }
}
