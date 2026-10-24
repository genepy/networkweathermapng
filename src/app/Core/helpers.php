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

if (!function_exists('breadcrumb')) {
    /**
     * The breadcrumb that starts every page header, so all pages look alike.
     * $crumbs: [label, url] pairs, already translated; the last one is the current page.
     */
    function breadcrumb(array $crumbs): string
    {
        $parts = [];
        $last = count($crumbs) - 1;
        foreach (array_values($crumbs) as $i => $crumb) {
            $label = htmlspecialchars((string) $crumb[0], ENT_QUOTES);
            $url = $crumb[1] ?? null;
            $parts[] = $i < $last && $url !== null
                ? '<a href="' . htmlspecialchars((string) $url, ENT_QUOTES) . '">' . $label . '</a>'
                : ($i === $last ? '<h1>' . $label . '</h1>' : $label);
        }
        return '<nav class="breadcrumb" aria-label="' . e__('Breadcrumb') . '">'
            . implode(' <span class="breadcrumb-sep" aria-hidden="true">&raquo;</span> ', $parts) . '</nav>';
    }
}
