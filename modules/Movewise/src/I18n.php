<?php

declare(strict_types=1);

namespace Modules\Movewise;

/**
 * English and Spanish on Movewise (specs/movewise/02). Spanish pages live under /es, with hreflang links both ways,
 * so each language has its own address for search engines. Strings are in resources/lang/{en,es}.php.
 */
final class I18n
{
    public const LOCALES = ['en', 'es'];

    private static string $locale = 'en';

    /** @var array<string, array<string, string>> */
    private static array $strings = [];

    public static function set(string $locale): void
    {
        self::$locale = in_array($locale, self::LOCALES, true) ? $locale : 'en';
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    /** @param  array<string, string|int>  $replace */
    public static function t(string $key, array $replace = []): string
    {
        $strings = self::$strings[self::$locale] ??= (array) require __DIR__.'/../resources/lang/'.self::$locale.'.php';
        $en = self::$strings['en'] ??= (array) require __DIR__.'/../resources/lang/en.php';
        $text = (string) ($strings[$key] ?? $en[$key] ?? $key);
        foreach ($replace as $k => $v) {
            $text = str_replace(':'.$k, (string) $v, $text);
        }

        return $text;
    }

    /** The same page in the current language: "/homes" → "/es/homes" in Spanish. */
    public static function url(string $path): string
    {
        return self::$locale === 'es' ? '/es'.($path === '/' ? '' : $path) : $path;
    }
}
