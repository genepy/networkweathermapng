<?php

declare(strict_types=1);

use App\Core\I18n;

if (!function_exists('__')) {
    /** Translated text (see App\Core\I18n) */
    function __(string $text, array $params = []): string
    {
        return I18n::t($text, $params);
    }
}

if (!function_exists('e__')) {
    /** Translated text, escaped for HTML */
    function e__(string $text, array $params = []): string
    {
        return htmlspecialchars(I18n::t($text, $params), ENT_QUOTES);
    }
}
