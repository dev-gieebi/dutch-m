<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/** Nettoie le HTML riche saisi dans l'administration. */
final class Html
{
    private static ?HtmlSanitizer $sanitizer = null;

    public static function sanitize(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }
        if (! self::$sanitizer) {
            $config = (new HtmlSanitizerConfig)
                ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
                ->forceAttribute('a', 'rel', 'noopener noreferrer');
            foreach (['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'blockquote', 'span'] as $tag) {
                $config = $config->allowElement($tag);
            }
            $config = $config->allowElement('a', ['href', 'target', 'rel']);
            self::$sanitizer = new HtmlSanitizer($config);
        }

        return self::$sanitizer->sanitize($html);
    }
}
