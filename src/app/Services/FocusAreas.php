<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Focus areas of a map: parts of a broad map that can be put forward in
 * the viewer and the kiosk, the rest dimmed, or the view zoomed onto them,
 * or both. They are saved in the map's .conf:
 *
 *   SET focus_<id> <x1> <y1> <x2> <y2>   the rectangle, in map pixels
 *   SET focus_<id>_title <text>          its name in the viewer's menu
 *   SET focus_<id>_style <style>         dim, zoom or both (the default)
 *   SET focus_default <id>               the area the viewer opens on and the kiosk shows
 *
 * The PNG and SVG pictures do not change: the viewer applies the focus.
 */
final class FocusAreas
{
    /** How an area stands out: the rest dims, the view zooms onto it, or both */
    public const STYLES = ['both', 'dim', 'zoom'];

    /** Margin kept around an area when the viewer zooms onto it, in map pixels */
    public const ZOOM_MARGIN = 20;

    /**
     * The areas of a map, in the order of their ids:
     * id => {id, title, style, box: [x1, y1, x2, y2], default}.
     *
     * @return array<string, array{id: string, title: string, style: string, box: array{0: int, 1: int, 2: int, 3: int}, default: bool}>
     */
    public static function all(\WeatherMap $map): array
    {
        if (!is_array($map->hints)) {
            return [];
        }
        $default = self::defaultId($map);
        $areas = [];
        foreach ($map->hints as $key => $value) {
            if (!preg_match('/^focus_([A-Za-z0-9]+)$/', (string) $key, $m) || $m[1] === 'default') {
                continue;
            }
            $area = self::parse($map, $m[1], is_scalar($value) ? (string) $value : '');
            if ($area !== null) {
                $area['default'] = $m[1] === $default;
                $areas[$m[1]] = $area;
            }
        }
        uksort($areas, 'strnatcmp');
        return $areas;
    }

    /**
     * One area from its hints, null when SET focus_<id> is not a rectangle.
     *
     * @return array{id: string, title: string, style: string, box: array{0: int, 1: int, 2: int, 3: int}, default: bool}|null
     */
    public static function parse(\WeatherMap $map, string $id, string $value): ?array
    {
        $box = self::box($value);
        if ($box === null) {
            return null;
        }
        $title = trim((string) ($map->get_hint('focus_' . $id . '_title') ?? ''));
        $style = strtolower(trim((string) ($map->get_hint('focus_' . $id . '_style') ?? '')));

        return [
            'id' => $id,
            // empty: the viewer and the editor call it "Area <id>" in their language
            'title' => $title,
            'style' => in_array($style, self::STYLES, true) ? $style : self::STYLES[0],
            'box' => $box,
            'default' => false,
        ];
    }

    /**
     * "x1 y1 x2 y2" as a rectangle with x1 < x2 and y1 < y2, null when it
     * is not four numbers or has no area.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}|null
     */
    public static function box(string $value): ?array
    {
        if (!preg_match('/^(-?\d+)[\s,]+(-?\d+)[\s,]+(-?\d+)[\s,]+(-?\d+)$/', trim($value), $m)) {
            return null;
        }
        $x1 = min((int) $m[1], (int) $m[3]);
        $x2 = max((int) $m[1], (int) $m[3]);
        $y1 = min((int) $m[2], (int) $m[4]);
        $y2 = max((int) $m[2], (int) $m[4]);
        if ($x2 - $x1 < 1 || $y2 - $y1 < 1) {
            return null;
        }
        return [$x1, $y1, $x2, $y2];
    }

    /** SET focus_default: the id of the area the map opens on, null when none (or unknown) */
    public static function defaultId(\WeatherMap $map): ?string
    {
        $id = trim((string) ($map->get_hint('focus_default') ?? ''));
        if ($id === '' || $id === 'default' || !preg_match('/^[A-Za-z0-9]+$/', $id)) {
            return null;
        }
        return self::box((string) ($map->get_hint('focus_' . $id) ?? '')) !== null ? $id : null;
    }

    /**
     * What the viewer needs: each area with the nodes inside it (by name)
     * and the links with an end inside it (by summary item id, L<id>), and
     * the area to open on. Null when the map has no area.
     *
     * @return array{areas: list<array<string, mixed>>, default: string|null}|null
     */
    public static function forViewer(\WeatherMap $map): ?array
    {
        $areas = self::all($map);
        if ($areas === []) {
            return null;
        }
        $out = [];
        foreach ($areas as $area) {
            $inside = [];
            foreach ($map->nodes as $node) {
                if ($node->x === null || $node->name === 'DEFAULT' || $node->name === ':: DEFAULT ::') {
                    continue;
                }
                if (self::contains($area['box'], (float) $node->x, (float) $node->y)) {
                    $inside[(string) $node->name] = true;
                }
            }
            $links = [];
            foreach ($map->links as $link) {
                if (!isset($link->a, $link->b) || $link->name === 'DEFAULT') {
                    continue;
                }
                if (isset($inside[(string) $link->a->name]) || isset($inside[(string) $link->b->name])) {
                    $links[] = 'L' . $link->id;
                }
            }
            unset($area['default']);
            $out[] = $area + ['nodes' => array_map('strval', array_keys($inside)), 'links' => $links];
        }
        return ['areas' => $out, 'default' => self::defaultId($map)];
    }

    /** @param array{0: int, 1: int, 2: int, 3: int} $box */
    public static function contains(array $box, float $x, float $y): bool
    {
        return $x >= $box[0] && $x <= $box[2] && $y >= $box[1] && $y <= $box[3];
    }
}
