<?php
/**
 * NetworkWeathermapNG - what the Ctrl+K command palette searches (UI
 * redesign step 7): the maps a user can see, the nodes of those maps, the
 * kiosk groups and, for administrators, the Settings pages. The browser
 * (assets/js/palette.js) builds the commands from it and does the search.
 */

declare(strict_types=1);

namespace App\Services;

final class CommandPalette
{
    /**
     * The placed nodes of a map config, as the viewer finds them:
     * [['name' => ..., 'label' => ...], ...]. A label made of {tokens}
     * (filled with data at render time) gives way to the node name.
     * Template nodes (no POSITION) and DEFAULT are left out.
     *
     * @return list<array{name: string, label: string}>
     */
    public static function nodesOf(string $config): array
    {
        $nodes = [];
        $current = null;
        foreach (preg_split('/\R/', $config) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (preg_match('/^(NODE|LINK|ZONE)\s+(\S+)\s*$/i', $line, $m)) {
                $current = strtoupper($m[1]) === 'NODE' && $m[2] !== 'DEFAULT' ? $m[2] : null;
                if ($current !== null && !isset($nodes[$current])) {
                    $nodes[$current] = ['name' => $current, 'label' => '', 'placed' => false];
                }
                continue;
            }
            if ($current === null) {
                continue;
            }
            if (preg_match('/^LABEL\s+(.+)$/i', $line, $m)) {
                $nodes[$current]['label'] = trim($m[1]);
            } elseif (preg_match('/^POSITION\s+\S/i', $line)) {
                $nodes[$current]['placed'] = true;
            }
        }

        $out = [];
        foreach ($nodes as $node) {
            if (!$node['placed']) {
                continue;
            }
            $label = $node['label'];
            if ($label === '' || str_contains($label, '{')) {
                $label = $node['name'];
            }
            $out[] = ['name' => $node['name'], 'label' => $label];
        }
        return $out;
    }

    /**
     * The palette index sent to the browser.
     *
     * @param list<array<string, mixed>> $maps      maps the user can see (id, name, title_cache, group_id, group_name, active)
     * @param array<int, string|null>    $configs   map id => config text (null when unreadable)
     * @param array<int, array|null>     $statuses  map id => summary of the last render (MapService::mapStatus)
     * @param list<int>                  $favourites map ids
     * @param array{edit: bool, admin: bool, account: bool} $can
     */
    public static function index(array $maps, array $configs, array $statuses, array $favourites, array $can): array
    {
        $outMaps = [];
        $nodes = [];
        $groups = [];
        $fav = array_flip($favourites);
        foreach ($maps as $map) {
            $id = (int) $map['id'];
            $status = $statuses[$id] ?? null;
            $outMaps[] = [
                'id' => $id,
                'name' => (string) $map['name'],
                'group' => (string) ($map['group_name'] ?? ''),
                'active' => (bool) ($map['active'] ?? true),
                'favourite' => isset($fav[$id]),
                'pct' => is_array($status) && isset($status['max']) ? (float) $status['max'] : null,
                'level' => is_array($status) ? (string) ($status['level'] ?? '') : '',
            ];
            if ((int) ($map['group_id'] ?? 0) > 0 && !empty($map['active'])) {
                $groups[(int) $map['group_id']] = ['id' => (int) $map['group_id'], 'name' => (string) ($map['group_name'] ?? '')];
            }
            foreach (self::nodesOf((string) ($configs[$id] ?? '')) as $node) {
                $nodes[] = ['map' => $id] + $node;
            }
        }

        $settings = [];
        if ($can['admin']) {
            foreach (SettingsNav::groups() as [$group, $items]) {
                foreach ($items as [, $label, $href, $icon]) {
                    $settings[] = ['label' => $label, 'group' => $group, 'href' => $href, 'icon' => $icon];
                }
            }
        }

        return [
            'maps' => $outMaps,
            'nodes' => $nodes,
            'groups' => array_values($groups),
            'settings' => $settings,
            'can' => $can,
        ];
    }
}
