<?php
/**
 * Interface translations. Strings are written in English in the code and
 * looked up in app/Lang/<locale>.php; a missing entry shows the English.
 *
 * The language comes from, in order: the "lang" cookie (the menu in the
 * header), APP_LOCALE, the browser's Accept-Language.
 */

declare(strict_types=1);

namespace App\Core;

class I18n
{
    public const LOCALES = ['en' => 'English', 'fr' => 'Français'];

    private static ?string $locale = null;
    private static array $catalog = [];

    public static function locale(): string
    {
        if (self::$locale === null) {
            self::setLocale(self::detect());
        }
        return self::$locale;
    }

    public static function setLocale(string $locale): void
    {
        self::$locale = isset(self::LOCALES[$locale]) ? $locale : 'en';
        $file = dirname(__DIR__) . '/Lang/' . self::$locale . '.php';
        self::$catalog = self::$locale !== 'en' && is_file($file) ? (array) require $file : [];
    }

    /** @param array<string, string|int|float> $params replaces :name in the text */
    public static function t(string $text, array $params = []): string
    {
        self::locale();
        $out = self::$catalog[$text] ?? $text;
        foreach ($params as $k => $v) {
            $out = str_replace(':' . $k, (string) $v, $out);
        }
        return $out;
    }

    /** Translations the browser scripts need, for the strings they list */
    public static function forScript(array $texts): array
    {
        $out = [];
        foreach ($texts as $t) {
            $out[$t] = self::t($t);
        }
        return $out;
    }

    private static function detect(): string
    {
        $cookie = $_COOKIE['lang'] ?? '';
        if (is_string($cookie) && isset(self::LOCALES[$cookie])) {
            return $cookie;
        }

        $env = getenv('APP_LOCALE') ?: ($_ENV['APP_LOCALE'] ?? '');
        if (is_string($env) && isset(self::LOCALES[$env])) {
            return $env;
        }

        foreach (explode(',', (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $part) {
            $code = strtolower(substr(trim($part), 0, 2));
            if (isset(self::LOCALES[$code])) {
                return $code;
            }
        }
        return 'en';
    }
}
