<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The panels of the viewer and the kiosk: which ones are shown and where.
 * The panels are the sites list, the total traffic and the update time
 * (with the most loaded link). The legend is a card drawn on the map
 * (SET box_<id> legend); a hint that still names a legend panel parses, and
 * the entry is left out. Each one goes in the
 * side panel or in a corner of the map, or is hidden. The choice is one
 * hint of the map's .conf, the panels in their order:
 *
 *   SET viewer_panels sites:side total:top-right updated:bottom-left:hidden
 *
 * <id>:<position>, then :hidden for a panel that is not shown (it keeps its
 * position for when it is shown again). The update time (the status bar)
 * can also go on the map itself (F58): updated:map:<x>,<y>, the top left
 * corner of the bar in map pixels; it then moves and zooms with the map,
 * and the PNG and SVG pictures draw it there with the values of the render. The order is the order in the side
 * panel, and in a corner that holds more than one panel. A panel the hint
 * leaves out takes its default place, after the others.
 *
 * Without the hint the viewer looks as it always did: the sites and the
 * total traffic in the side panel, the update time at the bottom
 * left, and the kiosk shows none of them.
 *
 * The kiosk has a layout of its own, with the same syntax:
 *
 *   SET kiosk_panels total:side updated:bottom-right sites:side:hidden
 *
 * or "SET kiosk_panels same" for the viewer's layout. With either one the
 * kiosk shows a side panel for the panels set to "side". Without it the
 * kiosk keeps its old behaviour: the viewer's layout, corners only.
 */
final class ViewerPanels
{
    public const HINT = 'viewer_panels';

    /** The layout of the kiosk, when it differs from the viewer's corners */
    public const KIOSK_HINT = 'kiosk_panels';

    /** SET kiosk_panels same: the kiosk uses the viewer's layout, side panel included */
    public const SAME = 'same';

    /** The kiosk modes the editor offers: corners only (no hint), the viewer's layout, its own */
    public const KIOSK_MODES = ['corners', 'same', 'own'];

    /** The panels, in their default order */
    public const IDS = ['sites', 'total', 'updated'];

    /** The legend panel of older maps: read and left out (the legend is a card on the map) */
    public const OLD_LEGEND = 'legend';

    /**
     * Whether the viewer (or the kiosk, with KIOSK_HINT) showed the legend
     * panel that is gone: no hint, or a hint that does not hide it. The
     * kiosk without its hint showed no legend.
     */
    public static function showedLegend(\WeatherMap $map, string $key = self::HINT): bool
    {
        $hint = is_array($map->hints) ? ($map->hints[$key] ?? '') : '';
        $hint = is_scalar($hint) ? strtolower(trim((string) $hint)) : '';
        if ($key === self::KIOSK_HINT && ($hint === '' || $hint === self::SAME)) {
            return false;
        }
        if (!preg_match('/(?:^|[\s,])legend(?::([a-z-]+))?(?::(hidden))?(?=[\s,]|$)/', $hint, $m)) {
            return $key === self::HINT;
        }
        return ($m[1] ?? '') !== 'hidden' && ($m[2] ?? '') !== 'hidden';
    }

    /**
     * Rewrites the viewer and kiosk hints of an older map without a shown
     * legend panel (a card takes its place); true when one of them changed.
     * A hidden legend entry stays as it is, read and left out, so the map
     * still says that its viewer showed no legend; the editor drops it when
     * it writes the hint.
     */
    public static function dropLegend(\WeatherMap $map): bool
    {
        $changed = false;
        foreach ([self::HINT, self::KIOSK_HINT] as $key) {
            $hint = is_array($map->hints) ? ($map->hints[$key] ?? null) : null;
            if (!is_scalar($hint) || !preg_match('/(?:^|[\s,])legend(?=[:\s,]|$)/i', (string) $hint) || !self::showedLegend($map, $key)) {
                continue;
            }
            if ($key === self::HINT) {
                self::set($map, (string) $hint);
            } else {
                self::setKiosk($map, self::parse((string) $hint));
            }
            $changed = true;
        }
        return $changed;
    }

    /** Where a panel can go: the side panel or a corner of the map */
    public const POSITIONS = ['side', 'top-left', 'top-right', 'bottom-left', 'bottom-right'];

    /** On the map, at a point of it: the update time only (the status bar, F58) */
    public const ON_MAP = 'map';

    /** The panel that can go on the map */
    public const STATUS = 'updated';

    /** Where the status bar goes on the map when it is put there without a place: bottom left, inside */
    public const STATUS_MARGIN = [10, 40];

    /**
     * The top left corner of the status bar on the map, or null when it is
     * in a screen corner, in the side panel or hidden (the pictures then
     * draw no update time).
     *
     * @return array{0: int, 1: int}|null
     */
    public static function statusAt(\WeatherMap $map): ?array
    {
        foreach (self::of($map) as $p) {
            if ($p['id'] === self::STATUS) {
                return $p['shown'] && $p['position'] === self::ON_MAP ? $p['at'] : null;
            }
        }
        return null;
    }

    /** Where a status bar put on the map without a place goes: bottom left, inside the map */
    public static function statusPlace(\WeatherMap $map): array
    {
        return [self::STATUS_MARGIN[0], max(0, (int) $map->height - self::STATUS_MARGIN[1])];
    }

    /**
     * Puts the status bar on the map at a point (null: where it is, or
     * bottom left), shown or hidden; the other panels keep their place.
     *
     * @param array{0: int|float, 1: int|float}|null $at
     */
    public static function placeStatus(\WeatherMap $map, ?array $at, bool $shown = true): void
    {
        $panels = self::of($map);
        foreach ($panels as $i => $p) {
            if ($p['id'] !== self::STATUS) {
                continue;
            }
            if ($at === null) {
                $at = $p['position'] === self::ON_MAP ? $p['at'] : self::statusPlace($map);
            }
            $panels[$i] = ['id' => $p['id'], 'position' => self::ON_MAP, 'shown' => $shown, 'at' => [max(0, (int) round($at[0])), max(0, (int) round($at[1]))]];
        }
        self::set($map, $panels);
    }

    /** Hides the status bar, keeping its place for when it is shown again */
    public static function hideStatus(\WeatherMap $map): void
    {
        $panels = self::of($map);
        foreach ($panels as $i => $p) {
            if ($p['id'] === self::STATUS) {
                $panels[$i]['shown'] = false;
            }
        }
        self::set($map, $panels);
    }

    /** Where each panel goes on a map without the hint */
    public const DEFAULTS = ['sites' => 'side', 'total' => 'side', 'updated' => 'bottom-left'];

    /**
     * The panels of a map, in their order.
     *
     * @return list<array{id: string, position: string, shown: bool, at?: array{0: int, 1: int}}>
     */
    public static function of(\WeatherMap $map): array
    {
        $hint = is_array($map->hints) ? ($map->hints[self::HINT] ?? '') : '';
        return self::parse(is_scalar($hint) ? (string) $hint : '');
    }

    /**
     * What the viewer and the kiosk use: the panels in their order, or null
     * on a map without the hint (the viewer keeps its usual layout).
     *
     * @return list<array{id: string, position: string, shown: bool, at?: array{0: int, 1: int}}>|null
     */
    public static function forViewer(\WeatherMap $map): ?array
    {
        $panels = self::of($map);
        return self::isDefault($panels) ? null : $panels;
    }

    /**
     * What the kiosk uses: its own panels, the viewer's (SET kiosk_panels
     * same), or null without the hint (the viewer's layout, corners only).
     *
     * @return list<array{id: string, position: string, shown: bool, at?: array{0: int, 1: int}}>|null
     */
    public static function forKiosk(\WeatherMap $map): ?array
    {
        return match (self::kioskMode($map)) {
            'own' => self::parse(self::kioskHint($map)),
            'same' => self::of($map),
            default => null,
        };
    }

    /** How the kiosk lays out its panels: corners (no hint), same or own */
    public static function kioskMode(\WeatherMap $map): string
    {
        $hint = strtolower(trim(self::kioskHint($map)));
        if ($hint === '') {
            return 'corners';
        }
        return $hint === self::SAME ? 'same' : 'own';
    }

    /**
     * The kiosk layout for the editor: its mode, and its own panels (the
     * viewer's while it has none, as a start).
     *
     * @return array{mode: string, panels: list<array{id: string, position: string, shown: bool, at?: array{0: int, 1: int}}>}
     */
    public static function kioskModel(\WeatherMap $map): array
    {
        $mode = self::kioskMode($map);
        return ['mode' => $mode, 'panels' => $mode === 'own' ? self::parse(self::kioskHint($map)) : self::of($map)];
    }

    /**
     * Writes the kiosk layout: 'corners' (or '') removes the hint, 'same'
     * follows the viewer, a list of panels (or a hint value) is its own.
     * As the editor sends it: {mode, panels}.
     */
    public static function setKiosk(\WeatherMap $map, mixed $value): void
    {
        if (is_array($value) && array_key_exists('mode', $value)) {
            $mode = (string) $value['mode'];
            if (!in_array($mode, self::KIOSK_MODES, true)) {
                throw new \InvalidArgumentException(__('Invalid :key: :value', ['key' => self::KIOSK_HINT, 'value' => $mode]));
            }
            $value = $mode === 'own' ? ($value['panels'] ?? []) : $mode;
        }
        if ($value === null || $value === '' || $value === 'corners') {
            unset($map->hints[self::KIOSK_HINT]);
            return;
        }
        if (is_string($value) && strtolower(trim($value)) === self::SAME) {
            $map->add_hint(self::KIOSK_HINT, self::SAME);
            return;
        }
        // its own layout is always written, the usual one included: it has a side panel
        $map->add_hint(self::KIOSK_HINT, self::tokens(self::normalise(self::withStatusPlace($map, $value))));
    }

    private static function kioskHint(\WeatherMap $map): string
    {
        $hint = is_array($map->hints) ? ($map->hints[self::KIOSK_HINT] ?? '') : '';
        return is_scalar($hint) ? (string) $hint : '';
    }

    /**
     * The panels a hint value names, in its order, then the ones it leaves
     * out at their default place. Unknown panels and positions are skipped.
     *
     * @return list<array{id: string, position: string, shown: bool, at?: array{0: int, 1: int}}>
     */
    public static function parse(string $value): array
    {
        $out = [];
        foreach (preg_split('/\s+|,(?!\d)/', strtolower(trim($value))) ?: [] as $token) {
            $parts = explode(':', $token);
            $id = $parts[0];
            if (!isset(self::DEFAULTS[$id]) || isset($out[$id])) {
                continue;
            }
            $position = $parts[1] ?? '';
            $shown = true;
            $at = null;
            if ($position === self::ON_MAP) {
                // updated:map:<x>,<y>[:hidden]
                if ($id !== self::STATUS || !preg_match('/^(\d+),(\d+)$/', $parts[2] ?? '', $xy)) {
                    $position = self::DEFAULTS[$id];
                } else {
                    $at = [(int) $xy[1], (int) $xy[2]];
                }
                array_splice($parts, 2, 1);
            }
            if ($position === 'hidden') {
                // <id>:hidden: not shown, at its default place when shown again
                $position = self::DEFAULTS[$id];
                $shown = false;
            } elseif ($at === null && !in_array($position, self::POSITIONS, true)) {
                $position = self::DEFAULTS[$id];
            }
            if (($parts[2] ?? '') === 'hidden') {
                $shown = false;
            }
            $out[$id] = ['id' => $id, 'position' => $position, 'shown' => $shown] + ($at === null ? [] : ['at' => $at]);
        }
        foreach (self::DEFAULTS as $id => $position) {
            $out[$id] ??= ['id' => $id, 'position' => $position, 'shown' => true];
        }
        return array_values($out);
    }

    /**
     * The panels as the editor sends them ([{id, position, shown}], in
     * their order) or as a hint value, checked and completed.
     *
     * @return list<array{id: string, position: string, shown: bool, at?: array{0: int, 1: int}}>
     */
    public static function normalise(mixed $value): array
    {
        if (is_string($value)) {
            return self::parse($value);
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException(__('Invalid :key: :value', ['key' => self::HINT, 'value' => get_debug_type($value)]));
        }
        $tokens = [];
        foreach ($value as $panel) {
            $id = is_array($panel) ? (string) ($panel['id'] ?? '') : '';
            $position = is_array($panel) ? (string) ($panel['position'] ?? '') : '';
            if ($id === self::OLD_LEGEND) {
                continue;
            }
            if (!isset(self::DEFAULTS[$id])) {
                throw new \InvalidArgumentException(__('Unknown viewer panel: :id', ['id' => $id]));
            }
            if ($position === self::ON_MAP && $id === self::STATUS) {
                $at = $panel['at'] ?? null;
                $position .= is_array($at) && isset($at[0], $at[1]) && is_numeric($at[0]) && is_numeric($at[1])
                    ? ':' . max(0, (int) round((float) $at[0])) . ',' . max(0, (int) round((float) $at[1]))
                    // on the map without a place: top left here, set() puts it bottom left (statusPlace())
                    : ':' . self::STATUS_MARGIN[0] . ',' . self::STATUS_MARGIN[0];
            } elseif (!in_array($position, self::POSITIONS, true)) {
                throw new \InvalidArgumentException(__('Invalid :key: :value', ['key' => self::HINT, 'value' => $position]));
            }
            $tokens[] = $id . ':' . $position . (array_key_exists('shown', $panel) && !$panel['shown'] ? ':hidden' : '');
        }
        return self::parse(implode(' ', $tokens));
    }

    /**
     * The hint value of the panels, '' when it is the default layout.
     *
     * @param list<array{id: string, position: string, shown: bool, at?: array{0: int, 1: int}}> $panels
     */
    public static function format(array $panels): string
    {
        return self::isDefault($panels) ? '' : self::tokens($panels);
    }

    /**
     * The hint value of the panels, every one of them written.
     *
     * @param list<array{id: string, position: string, shown: bool, at?: array{0: int, 1: int}}> $panels
     */
    private static function tokens(array $panels): string
    {
        return implode(' ', array_map(
            static fn (array $p): string => $p['id'] . ':' . $p['position']
                . (isset($p['at']) ? ':' . $p['at'][0] . ',' . $p['at'][1] : '') . ($p['shown'] ? '' : ':hidden'),
            $panels
        ));
    }

    /** Writes the panels in the map's hints; the default layout removes the hint */
    public static function set(\WeatherMap $map, mixed $value): void
    {
        $value = self::withStatusPlace($map, $value);
        $text = self::format(self::normalise($value));
        if ($text === '') {
            unset($map->hints[self::HINT]);
        } else {
            $map->add_hint(self::HINT, $text);
        }
    }

    /** A status bar the editor puts on the map without a place goes bottom left (statusPlace()) */
    private static function withStatusPlace(\WeatherMap $map, mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $i => $p) {
            if (is_array($p) && ($p['id'] ?? '') === self::STATUS && ($p['position'] ?? '') === self::ON_MAP && !is_array($p['at'] ?? null)) {
                $value[$i]['at'] = self::statusPlace($map);
            }
        }
        return $value;
    }

    /**
     * The usual layout: every panel shown at its default place, in the
     * default order.
     *
     * @param list<array{id: string, position: string, shown: bool, at?: array{0: int, 1: int}}> $panels
     */
    public static function isDefault(array $panels): bool
    {
        $expected = array_map(
            static fn (string $id): array => ['id' => $id, 'position' => self::DEFAULTS[$id], 'shown' => true],
            self::IDS
        );
        return $panels === $expected;
    }
}
