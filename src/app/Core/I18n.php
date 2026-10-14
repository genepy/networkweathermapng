<?php
/**
 * Interface translations. Strings are written in English in the code and
 * looked up in app/Lang/<locale>/*.php (one file per part of the
 * application, each returning [English => translation]); a missing entry
 * shows the English. Files named *-js.php hold the texts of a browser
 * script and are only read through section().
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
        self::$catalog = [];
        if (self::$locale !== 'en') {
            foreach (glob(self::dir() . '/*.php') ?: [] as $file) {
                // *-js.php files only go to the browser scripts (see section())
                if (!str_ends_with($file, '-js.php')) {
                    self::$catalog += (array) require $file;
                }
            }
        }
    }

    /** The translations of one file of app/Lang/<locale>/, for the browser scripts */
    public static function section(string $name): array
    {
        $file = self::dir() . '/' . basename($name) . '.php';
        return self::locale() !== 'en' && is_file($file) ? (array) require $file : [];
    }

    private static function dir(): string
    {
        return dirname(__DIR__) . '/Lang/' . self::$locale;
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
