<?php

namespace App\Services\Security;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Sanitizes rich text HTML authored in the admin panel (Filament RichEditor)
 * before it is rendered unescaped on the public site.
 */
class RichTextSanitizer
{
    private static ?HtmlSanitizer $sanitizer = null;

    public static function sanitize(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        return self::sanitizer()->sanitize($html);
    }

    private static function sanitizer(): HtmlSanitizer
    {
        return self::$sanitizer ??= new HtmlSanitizer(self::config());
    }

    /**
     * Allows only the W3C "safe" element and attribute set, which drops scripts,
     * iframes, event handlers and unsafe URL schemes. Inline styles remain blocked;
     * `class` is allowed so the editor output can use the site typography.
     */
    private static function config(): HtmlSanitizerConfig
    {
        return (new HtmlSanitizerConfig)
            ->allowSafeElements()
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->allowAttribute('class', allowedElements: '*')
            ->withMaxInputLength(500_000);
    }
}
