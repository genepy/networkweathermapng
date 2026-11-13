<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * The geographic basemap of a map: tiles drawn by the browser under the
 * SVG map in the viewer and the editor (see docs/studies/geographic-basemap.md).
 *
 *   SET basemap <url>              MapLibre style (JSON) or raster tiles with {z}/{x}/{y}
 *   SET basemap_dark <url>         the same for the dark theme (optional)
 *   SET basemap_center <lat> <lon> the point at the centre of the canvas
 *   SET basemap_zoom <z>           web map zoom of the canvas at 100 %
 *   SET basemap_attribution <text> credit shown for raster tiles
 *
 * The browser may only fetch tiles from the site itself or from the
 * servers of BASEMAP_SERVERS, which the Content-Security-Policy allows.
 */
final class Basemap
{
    /** The hints of a map's basemap, as the editor sets them */
    public const HINTS = ['basemap', 'basemap_dark', 'basemap_center', 'basemap_zoom', 'basemap_attribution'];

    public const DEFAULT_SERVERS = 'https://tiles.openfreemap.org';

    /** Credit of raster tiles without SET basemap_attribution */
    public const RASTER_ATTRIBUTION = '© OpenStreetMap contributors';

    /**
     * URL prefixes the basemap may load from (BASEMAP_SERVERS, space or comma separated)
     *
     * @return string[]
     */
    public static function servers(?Config $config = null): array
    {
        $raw = $config !== null
            ? (string) $config->get('BASEMAP_SERVERS', self::DEFAULT_SERVERS)
            : (getenv('BASEMAP_SERVERS') !== false ? (string) getenv('BASEMAP_SERVERS') : self::DEFAULT_SERVERS);
        $out = [];
        foreach (preg_split('/[\s,]+/', trim($raw)) ?: [] as $s) {
            if (preg_match('#^https?://[^/\s]+#i', $s)) {
                $out[] = rtrim($s, '/');
            }
        }
        return array_values(array_unique($out));
    }

    /** Origins of the servers, for the Content-Security-Policy */
    public static function origins(?Config $config = null): array
    {
        $out = [];
        foreach (self::servers($config) as $s) {
            if (preg_match('#^(https?://[^/\s]+)#i', $s, $m)) {
                $out[] = strtolower($m[1]);
            }
        }
        return array_values(array_unique($out));
    }

    /** Whether the browser may load this URL: the site itself or one of the servers */
    public static function allowed(string $url, ?array $servers = null): bool
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\s"\'<>\\\\]/', $url)) {
            return false;
        }
        if ($url[0] === '/' && !str_starts_with($url, '//')) {
            return true;
        }
        foreach ($servers ?? self::servers() as $s) {
            if (strncasecmp($url, $s, strlen($s)) === 0) {
                $next = substr($url, strlen($s), 1);
                if ($next === '' || $next === '/' || $next === '?') {
                    return true;
                }
            }
        }
        return false;
    }

    /** "raster" for a tile template ({z}, {x}, {y}), "style" for a MapLibre style */
    public static function kind(string $url): string
    {
        return str_contains($url, '{z}') ? 'raster' : 'style';
    }

    /**
     * What the viewer needs to draw the basemap of a map, or null when it
     * has none (or one it may not load):
     * {url, dark, kind, center: [lat, lon], zoom, attribution}
     */
    public static function of(\WeatherMap $map, ?array $servers = null): ?array
    {
        $frame = \WeatherMapGeo::frame($map);
        if ($frame === null) {
            return null;
        }
        $url = trim((string) $map->hints['basemap']);
        if (!self::allowed($url, $servers)) {
            return null;
        }
        $dark = trim((string) ($map->hints['basemap_dark'] ?? ''));
        if ($dark !== '' && (!self::allowed($dark, $servers) || self::kind($dark) !== self::kind($url))) {
            $dark = '';
        }
        $credit = trim((string) ($map->hints['basemap_attribution'] ?? ''));
        return [
            'url' => $url,
            'dark' => $dark !== '' ? $dark : null,
            'kind' => self::kind($url),
            'center' => [$frame[0], $frame[1]],
            'zoom' => $frame[2],
            'attribution' => $credit !== '' ? $credit : (self::kind($url) === 'raster' ? self::RASTER_ATTRIBUTION : null),
            // how located nodes too close together merge in the viewer
            'clusters' => self::clusterMode($map),
        ];
    }

    /** The cluster modes of SET cluster_mode; the first is the default */
    public const CLUSTER_MODES = ['nearby', 'none', 'groups'];

    /**
     * SET cluster_mode: how located nodes too close to tell apart merge
     * into one badge. nearby: any of them (the default); none: never;
     * groups: only the nodes of a group the author made (SET cluster on
     * the nodes, or their site), the whole group at once.
     */
    public static function clusterMode(\WeatherMap $map): string
    {
        $mode = strtolower(trim((string) ($map->hints['cluster_mode'] ?? '')));
        return in_array($mode, self::CLUSTER_MODES, true) ? $mode : self::CLUSTER_MODES[0];
    }

    /** The cluster group of a node: SET cluster, or its site when it has none ('' for no group) */
    public static function clusterOf(\WeatherMapNode $node): string
    {
        $group = $node->hints['cluster'] ?? '';
        $group = is_scalar($group) ? trim(preg_replace('/\s+/u', ' ', (string) $group) ?? '') : '';
        return $group !== '' ? $group : MapService::siteOf($node);
    }
}
