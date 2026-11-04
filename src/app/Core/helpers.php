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

if (!function_exists('asset')) {
    /**
     * Address of a file of src/public/assets with a version taken from the
     * file, so a changed file is fetched again although /assets is cached
     * for 30 days (docker/nginx/default.conf). Escaped for HTML attributes.
     */
    function asset(string $path): string
    {
        static $versions = [];
        if (!isset($versions[$path])) {
            $root = defined('PUBLIC_ROOT') ? PUBLIC_ROOT : dirname(__DIR__, 2) . '/public';
            $file = $root . '/' . ltrim($path, '/');
            $versions[$path] = is_file($file) ? substr(md5(filemtime($file) . ':' . filesize($file)), 0, 10) : '';
        }
        $url = htmlspecialchars($path, ENT_QUOTES);
        return $versions[$path] === '' ? $url : $url . '?v=' . $versions[$path];
    }
}

if (!function_exists('icon')) {
    /**
     * An icon of the interface sprite (src/public/assets/icons/ui.svg, Lucide).
     * Decorative: the button or link around it carries the text or aria-label.
     */
    function icon(string $name, string $class = 'icon'): string
    {
        return '<svg class="' . htmlspecialchars($class, ENT_QUOTES) . '" aria-hidden="true" focusable="false">'
            . '<use href="' . asset('/assets/icons/ui.svg') . '#' . htmlspecialchars($name, ENT_QUOTES) . '"/></svg>';
    }
}
