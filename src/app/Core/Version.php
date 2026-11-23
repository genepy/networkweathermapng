<?php

declare(strict_types=1);

namespace App\Core;

/**
 * The version of the application shown in the interface (F61): the
 * VERSION file of the repository (copied into the Docker image), or
 * APP_VERSION, and the commit it was built from (APP_COMMIT, a build
 * argument of the image), e.g. "0.9.0 (3abc40a)". A checkout without
 * either shows "dev".
 */
final class Version
{
    private static ?string $cached = null;

    /** "0.9.0 (3abc40a)", "0.9.0" or "dev" */
    public static function label(): string
    {
        return self::$cached ??= self::describe(self::number(), self::commit());
    }

    public static function describe(string $number, string $commit): string
    {
        $number = $number !== '' ? $number : 'dev';
        return $commit !== '' ? $number . ' (' . $commit . ')' : $number;
    }

    /** The release number: APP_VERSION, or the VERSION file */
    public static function number(): string
    {
        $env = trim((string) Config::env('APP_VERSION'));
        if ($env !== '') {
            return self::clean($env);
        }
        // /app/VERSION in the image (src/ is /app), VERSION at the root of a checkout
        foreach ([dirname(__DIR__, 2) . '/VERSION', dirname(__DIR__, 3) . '/VERSION'] as $file) {
            if (is_file($file)) {
                return self::clean((string) file_get_contents($file));
            }
        }
        return '';
    }

    /** The short commit hash the image was built from, '' when unknown */
    public static function commit(): string
    {
        $commit = strtolower(trim((string) Config::env('APP_COMMIT')));
        return preg_match('/^[0-9a-f]{7,40}$/', $commit) ? substr($commit, 0, 7) : '';
    }

    private static function clean(string $value): string
    {
        $value = trim($value);
        return preg_match('/^v?([0-9A-Za-z.+-]{1,40})$/', $value, $m) ? $m[1] : '';
    }
}
