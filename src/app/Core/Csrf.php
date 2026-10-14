<?php
/**
 * NetworkWeathermapNG - CSRF protection
 *
 * One token per session. Forms send it as the "_token" field, scripts as the
 * "X-CSRF-Token" header (public/assets/js/csrf.js adds both automatically).
 */

declare(strict_types=1);

namespace App\Core;

class Csrf
{
    public const FIELD = '_token';
    public const HEADER = 'HTTP_X_CSRF_TOKEN';

    public static function token(): string
    {
        if (empty($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf_token'];
    }

    /**
     * Token sent with the current request, from the form field or the header.
     */
    public static function fromRequest(): ?string
    {
        $token = $_POST[self::FIELD] ?? $_SERVER[self::HEADER] ?? null;

        return is_string($token) ? $token : null;
    }

    public static function isValid(?string $token): bool
    {
        $expected = $_SESSION['_csrf_token'] ?? null;

        return is_string($expected) && $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }

    public static function metaTag(): string
    {
        return '<meta name="csrf-token" content="' . htmlspecialchars(self::token(), ENT_QUOTES) . '">';
    }

    public static function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="' . htmlspecialchars(self::token(), ENT_QUOTES) . '">';
    }
}
