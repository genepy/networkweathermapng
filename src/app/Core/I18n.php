<?php
/**
 * Interface translations. Strings are written in English in the code and
 * looked up in app/Lang/<locale>/*.php (one file per part of the
 * application, each returning [English => translation]); a missing entry
 * shows the English. Files named *-js.php hold the texts of a browser
 * script and are only read through section().
 *
 * Languages are discovered from the folders of app/Lang/: each folder
 * named after a locale code (fr, de, pt_BR...) holds a _meta.php file
 * returning ['name' => 'Native language name']. English is built in, as
 * the source strings are English. See TRANSLATING.md.
 *
 * The language comes from, in order: the "lang" cookie (the menu in the
 * header), APP_LOCALE, the browser's Accept-Language, else English.
 */

declare(strict_types=1);

namespace App\Core;

class I18n
{
    /** Built-in language of the source strings */
    public const DEFAULT_LOCALE = 'en';

    /** Valid names for a folder of app/Lang/: "fr", "ast", "pt_BR"... */
    public const CODE_PATTERN = '/^[a-z]{2,3}(_[A-Z]{2})?$/';

    private static ?string $locale = null;
    private static array $catalog = [];

    /** @var array<string, string>|null discovered languages, cached per request */
    private static ?array $locales = null;

    /** Folder holding the catalogues; overridable for tests */
    private static ?string $langDir = null;

    /**
     * Available languages, code => display name, English first.
     *
     * @return array<string, string>
     */
    public static function locales(): array
    {
        if (self::$locales !== null) {
            return self::$locales;
        }

        $found = [];
        foreach (glob(self::langDir() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $code = basename($dir);
            if ($code === self::DEFAULT_LOCALE || !preg_match(self::CODE_PATTERN, $code)) {
                continue;
            }
            $found[$code] = self::displayName($dir, $code);
        }
        ksort($found);

        return self::$locales = [self::DEFAULT_LOCALE => 'English'] + $found;
    }

    /** Use another catalogue folder (null restores the default) and forget the cached state */
    public static function setLangDir(?string $dir): void
    {
        self::$langDir = $dir;
        self::$locales = null;
        self::$locale = null;
        self::$catalog = [];
    }

    public static function locale(): string
    {
        if (self::$locale === null) {
            self::setLocale(self::detect());
        }
        return self::$locale;
    }

    public static function setLocale(string $locale): void
    {
        self::$locale = isset(self::locales()[$locale]) ? $locale : self::DEFAULT_LOCALE;
        self::$catalog = [];
        if (self::$locale !== self::DEFAULT_LOCALE) {
            foreach (glob(self::dir() . '/*.php') ?: [] as $file) {
                $name = basename($file);
                // _meta.php describes the language; *-js.php files only go
                // to the browser scripts (see section())
                if ($name[0] !== '_' && !str_ends_with($name, '-js.php')) {
                    self::$catalog += (array) require $file;
                }
            }
        }
    }

    /** The translations of one file of app/Lang/<locale>/, for the browser scripts */
    public static function section(string $name): array
    {
        $file = self::dir() . '/' . basename($name) . '.php';
        return self::locale() !== self::DEFAULT_LOCALE && is_file($file) ? (array) require $file : [];
    }

    private static function langDir(): string
    {
        return self::$langDir ?? dirname(__DIR__) . '/Lang';
    }

    private static function dir(): string
    {
        return self::langDir() . '/' . self::$locale;
    }

    private static function displayName(string $dir, string $code): string
    {
        $meta = $dir . '/_meta.php';
        if (is_file($meta)) {
            $data = require $meta;
            if (is_array($data) && is_string($data['name'] ?? null) && trim($data['name']) !== '') {
                return trim($data['name']);
            }
        }
        return $code;
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
        $locales = self::locales();

        $cookie = $_COOKIE['lang'] ?? '';
        if (is_string($cookie) && isset($locales[$cookie])) {
            return $cookie;
        }

        $env = getenv('APP_LOCALE') ?: ($_ENV['APP_LOCALE'] ?? '');
        if (is_string($env) && isset($locales[$env])) {
            return $env;
        }

        foreach (explode(',', (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $part) {
            // "pt-BR;q=0.8" matches pt_BR first, then pt
            $tag = explode(';', trim($part))[0];
            $bits = explode('-', str_replace('_', '-', $tag));
            $lang = strtolower($bits[0]);
            $full = isset($bits[1]) ? $lang . '_' . strtoupper($bits[1]) : $lang;
            foreach ([$full, $lang] as $code) {
                if ($code !== '' && isset($locales[$code])) {
                    return $code;
                }
            }
        }
        return self::DEFAULT_LOCALE;
    }
}
