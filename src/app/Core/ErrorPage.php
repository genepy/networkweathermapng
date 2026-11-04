<?php
/**
 * Error pages (403, 404, 419, 500...) drawn inside the application shell,
 * with a way back, instead of a bare title on a white page. API requests
 * and JSON callers get {"error": "..."} instead.
 */

declare(strict_types=1);

namespace App\Core;

final class ErrorPage
{
    /**
     * Sends the status and the page. $auth is null when the application
     * could not start (the shell then shows no sections).
     */
    public static function send(int $status, ?Auth $auth = null, ?string $title = null, ?string $message = null): void
    {
        [$defaultTitle, $defaultMessage] = self::texts($status);
        $title ??= $defaultTitle;
        $message ??= $defaultMessage;

        if (!headers_sent()) {
            http_response_code($status);
        }

        if (self::wantsJson()) {
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            echo json_encode(['success' => false, 'error' => $title], JSON_INVALID_UTF8_SUBSTITUTE);
            return;
        }

        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
        }
        echo self::html($status, $title, $message, $auth);
    }

    /** The page itself, in the shell */
    public static function html(int $status, string $title, string $message, ?Auth $auth = null): string
    {
        $user = null;
        try {
            $user = $auth?->user();
        } catch (\Throwable) {
            $auth = null;
        }
        $code = $status;
        $views = dirname(__DIR__) . '/Views';

        ob_start();
        include $views . '/errors/error.php';
        $content = (string) ob_get_clean();

        $title = $title . ' - NetworkWeathermapNG';
        ob_start();
        include $views . '/layouts/main.php';
        return (string) ob_get_clean();
    }

    /** @return array{string, string} default title and explanation of a status */
    public static function texts(int $status): array
    {
        return match ($status) {
            403 => [__('Access denied'), __('Your account cannot open this page. Ask an administrator if you need it.')],
            404 => [__('Page not found'), __('This page does not exist or has moved.')],
            419 => [__('Session expired'), __('The form was sent without a valid security token. Go back, reload the page and try again.')],
            default => [__('Something went wrong'), __('An unexpected error occurred. Please try again later.')],
        };
    }

    private static function wantsJson(): bool
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        return str_starts_with($uri, '/api/')
            || isset($_SERVER[Csrf::HEADER])
            || (str_contains($accept, 'application/json') && !str_contains($accept, 'text/html'));
    }
}
