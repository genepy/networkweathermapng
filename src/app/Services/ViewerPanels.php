<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The panels of the viewer and the kiosk: which ones are shown and where.
 * The panels are the sites list, the total traffic, the link load legend
 * and the update time (with the most loaded link). Each one goes in the
 * side panel or in a corner of the map, or is hidden. The choice is one
 * hint of the map's .conf, the panels in their order:
 *
 *   SET viewer_panels sites:side total:top-right legend:side:hidden updated:bottom-left
 *
 * <id>:<position>, then :hidden for a panel that is not shown (it keeps its
 * position for when it is shown again). The order is the order in the side
 * panel, and in a corner that holds more than one panel. A panel the hint
 * leaves out takes its default place, after the others.
 *
 * Without the hint the viewer looks as it always did: the sites, the total
 * traffic and the legend in the side panel, the update time at the bottom
 * left, and the kiosk shows none of them.
 */
final class ViewerPanels
{
    public const HINT = 'viewer_panels';

    /** The panels, in their default order */
    public const IDS = ['sites', 'total', 'legend', 'updated'];

    /** Where a panel can go: the side panel or a corner of the map */
    public const POSITIONS = ['side', 'top-left', 'top-right', 'bottom-left', 'bottom-right'];

    /** Where each panel goes on a map without the hint */
    public const DEFAULTS = ['sites' => 'side', 'total' => 'side', 'legend' => 'side', 'updated' => 'bottom-left'];

    /**
     * The panels of a map, in their order.
     *
     * @return list<array{id: string, position: string, shown: bool}>
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
     * @return list<array{id: string, position: string, shown: bool}>|null
     */
    public static function forViewer(\WeatherMap $map): ?array
    {
        $panels = self::of($map);
        return self::isDefault($panels) ? null : $panels;
    }

    /**
     * The panels a hint value names, in its order, then the ones it leaves
     * out at their default place. Unknown panels and positions are skipped.
     *
     * @return list<array{id: string, position: string, shown: bool}>
     */
    public static function parse(string $value): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', strtolower(trim($value))) ?: [] as $token) {
            $parts = explode(':', $token);
            $id = $parts[0];
            if (!isset(self::DEFAULTS[$id]) || isset($out[$id])) {
                continue;
            }
            $position = $parts[1] ?? '';
            $shown = true;
            if ($position === 'hidden') {
                // <id>:hidden: not shown, at its default place when shown again
                $position = self::DEFAULTS[$id];
                $shown = false;
            } elseif (!in_array($position, self::POSITIONS, true)) {
                $position = self::DEFAULTS[$id];
            }
            if (($parts[2] ?? '') === 'hidden') {
                $shown = false;
            }
            $out[$id] = ['id' => $id, 'position' => $position, 'shown' => $shown];
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
     * @return list<array{id: string, position: string, shown: bool}>
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
            if (!isset(self::DEFAULTS[$id])) {
                throw new \InvalidArgumentException(__('Unknown viewer panel: :id', ['id' => $id]));
            }
            if (!in_array($position, self::POSITIONS, true)) {
                throw new \InvalidArgumentException(__('Invalid :key: :value', ['key' => self::HINT, 'value' => $position]));
            }
            $tokens[] = $id . ':' . $position . (array_key_exists('shown', $panel) && !$panel['shown'] ? ':hidden' : '');
        }
        return self::parse(implode(' ', $tokens));
    }

    /**
     * The hint value of the panels, '' when it is the default layout.
     *
     * @param list<array{id: string, position: string, shown: bool}> $panels
     */
    public static function format(array $panels): string
    {
        if (self::isDefault($panels)) {
            return '';
        }
        return implode(' ', array_map(
            static fn (array $p): string => $p['id'] . ':' . $p['position'] . ($p['shown'] ? '' : ':hidden'),
            $panels
        ));
    }

    /** Writes the panels in the map's hints; the default layout removes the hint */
    public static function set(\WeatherMap $map, mixed $value): void
    {
        $text = self::format(self::normalise($value));
        if ($text === '') {
            unset($map->hints[self::HINT]);
        } else {
            $map->add_hint(self::HINT, $text);
        }
    }

    /**
     * The usual layout: every panel shown at its default place, in the
     * default order.
     *
     * @param list<array{id: string, position: string, shown: bool}> $panels
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
