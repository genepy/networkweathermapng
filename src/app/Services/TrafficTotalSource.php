<?php
/**
 * What the total traffic of a map counts (F79).
 *
 * By default the total is the sum of the links drawn on the map
 * (MapService::trafficTotal()). A map hint can take it from Observium
 * instead, for the Total tab and for each link group tab:
 *
 *   SET traffic_total_source links                        the links (default)
 *   SET traffic_total_source observium:group:<id or name> an Observium port group
 *   SET traffic_total_source observium:ports:<id>,<id>    a list of Observium port ids
 *   SET traffic_group_source_<group> ...                  the same, for one group tab
 *
 * <group> in the hint name is the group's name in lower case with every
 * run of other characters than letters and digits as one "_" (a SET name
 * has no spaces): traffic_group "IX peering" is traffic_group_source_ix_peering.
 */

declare(strict_types=1);

namespace App\Services;

final class TrafficTotalSource
{
    public const HINT = 'traffic_total_source';
    public const GROUP_HINT = 'traffic_group_source_';

    public const LINKS = 'links';
    public const OBSERVIUM = 'observium';

    /** At most this many ports in an explicit list */
    public const MAX_PORTS = 500;

    /**
     * A hint value as a source: null for the links of the map (no value,
     * or "links"), else ['kind' => 'observium', 'group' => string] or
     * ['kind' => 'observium', 'ports' => list<int>]. Throws on a value it
     * cannot read.
     *
     * @return array{kind: string, group?: string, ports?: list<int>}|null
     */
    public static function parse(mixed $value): ?array
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        if ($value === '' || strcasecmp($value, self::LINKS) === 0) {
            return null;
        }
        if (preg_match('/^observium:group:(.+)$/i', $value, $m) && trim($m[1]) !== '') {
            return ['kind' => self::OBSERVIUM, 'group' => trim($m[1])];
        }
        if (preg_match('/^observium:ports:([\d\s,]+)$/i', $value, $m)) {
            $ports = array_values(array_unique(array_map('intval', array_filter(preg_split('/[\s,]+/', $m[1]) ?: [], 'ctype_digit'))));
            $ports = array_values(array_filter($ports, static fn (int $p) => $p > 0));
            if ($ports !== [] && count($ports) <= self::MAX_PORTS) {
                return ['kind' => self::OBSERVIUM, 'ports' => $ports];
            }
        }
        throw new \InvalidArgumentException(__('Invalid source of the total traffic: :value', ['value' => $value]));
    }

    /** A source written back as a hint value, '' for the links of the map */
    public static function format(?array $source): string
    {
        if ($source === null || ($source['kind'] ?? '') !== self::OBSERVIUM) {
            return '';
        }
        if (isset($source['group'])) {
            return 'observium:group:' . $source['group'];
        }
        return 'observium:ports:' . implode(',', array_map('intval', (array) ($source['ports'] ?? [])));
    }

    /** The name of the hint of a group tab ('' is the Total tab) */
    public static function hintName(string $group): string
    {
        if ($group === '') {
            return self::HINT;
        }
        return self::GROUP_HINT . self::slug($group);
    }

    /** A group name as it is written in a hint name */
    public static function slug(string $group): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($group)), '_');
    }

    /**
     * The sources of a drawn map, by tab: '' for the Total tab, else the
     * group name as the links give it (SET traffic_group). Groups whose
     * hint names no group of the map are keyed by their slug. Values that
     * cannot be read are left out (the editor refuses them).
     *
     * @return array<string, array{kind: string, group?: string, ports?: list<int>}>
     */
    public static function forMap(\WeatherMap $wmap): array
    {
        $groups = [];
        foreach ($wmap->links as $link) {
            if ($link->name !== 'DEFAULT') {
                $name = TotalTrafficSeries::groupOf($link);
                if ($name !== '') {
                    $groups[self::slug($name)] = $name;
                }
            }
        }
        return self::fromHints((array) $wmap->hints, $groups);
    }

    /**
     * The same from the text of a configuration (the viewer page decides
     * without drawing the map): the map-level SET lines only, before the
     * first NODE or LINK.
     *
     * @return array<string, array{kind: string, group?: string, ports?: list<int>}>
     */
    public static function fromConfig(string $config): array
    {
        $hints = [];
        $groups = [];
        $global = true;
        foreach (preg_split('/\R/', $config) ?: [] as $line) {
            if (preg_match('/^\s*(NODE|LINK)\s/i', $line)) {
                $global = false;
            }
            if (!preg_match('/^\s*SET\s+(\S+)\s+(.+?)\s*$/i', $line, $m)) {
                continue;
            }
            $key = strtolower($m[1]);
            if ($global && ($key === self::HINT || str_starts_with($key, self::GROUP_HINT))) {
                $hints[$key] = $m[2];
            }
            if (!$global && $key === 'traffic_group') {
                $name = TotalTrafficSeries::group(trim($m[2], '"'));
                if ($name !== '') {
                    $groups[self::slug($name)] = $name;
                }
            }
        }
        return self::fromHints($hints, $groups);
    }

    /**
     * @param array<string, mixed> $hints
     * @param array<string, string> $groups group names by slug
     * @return array<string, array{kind: string, group?: string, ports?: list<int>}>
     */
    private static function fromHints(array $hints, array $groups): array
    {
        $out = [];
        foreach ($hints as $key => $value) {
            $key = strtolower((string) $key);
            if ($key === self::HINT) {
                $tab = '';
            } elseif (str_starts_with($key, self::GROUP_HINT)) {
                $slug = substr($key, strlen(self::GROUP_HINT));
                if ($slug === '') {
                    continue;
                }
                $tab = $groups[$slug] ?? $slug;
            } else {
                continue;
            }
            try {
                $source = self::parse($value);
            } catch (\InvalidArgumentException) {
                continue;
            }
            if ($source !== null) {
                $out[$tab] = $source;
            }
        }
        return $out;
    }

    /**
     * Writes the source of a tab on a map (the editor): no hint for the
     * links of the map. Throws on a value it cannot read.
     */
    public static function set(\WeatherMap $wmap, string $group, mixed $value): void
    {
        $name = self::hintName($group);
        $text = self::format(self::parse($value));
        if ($text === '') {
            unset($wmap->hints[$name]);
        } else {
            $wmap->add_hint($name, $text);
        }
    }

    /** A short description of a source, for the status bar and the editor */
    public static function label(array $source): string
    {
        if (isset($source['group'])) {
            return __('Observium port group :group', ['group' => (string) $source['group']]);
        }
        return __('Observium ports :ports', ['ports' => implode(', ', array_map('intval', (array) ($source['ports'] ?? [])))]);
    }
}
