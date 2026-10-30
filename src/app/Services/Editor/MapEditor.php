<?php
/**
 * Batch edits on a Weathermap .conf file, for the SVG editor.
 *
 * The editor sends a list of operations; they are applied to one WeatherMap
 * object and written back in a single WriteConfig(), so a multi-selection
 * move or an alignment is one save and one undo step.
 */

declare(strict_types=1);

namespace App\Services\Editor;

use InvalidArgumentException;

class MapEditor
{
    /** Node fields the inspector may change, by request key */
    private const NODE_FIELDS = [
        'label' => 'label',
        'labelfont' => 'labelfont',
        'labeloffset' => 'labeloffset',
        'labelangle' => 'labelangle',
        'iconscalew' => 'iconscalew',
        'iconscaleh' => 'iconscaleh',
        'zorder' => 'zorder',
    ];

    private const NODE_COLOURS = [
        'labelbgcolour' => 'labelbgcolour',
        'labelfontcolour' => 'labelfontcolour',
        'labeloutlinecolour' => 'labeloutlinecolour',
        'labelfontshadowcolour' => 'labelfontshadowcolour',
    ];

    private const LINK_FIELDS = [
        'zorder' => 'zorder',
        'splitpos' => 'splitpos',
        'labelpos_in' => 'labeloffset_in',
        'labelpos_out' => 'labeloffset_out',
        'a_offset' => 'a_offset',
        'b_offset' => 'b_offset',
        'bwfont' => 'bwfont',
    ];

    private const LINK_CHOICES = [
        'viastyle' => ['curved', 'angled'],
        'linkstyle' => ['twoway', 'oneway'],
        'labelstyle' => ['percent', 'bits', 'unformatted', 'none'],
    ];

    /** Zone fields the inspector may change: request key => [property, type] */
    private const ZONE_FIELDS = [
        'label' => ['label', 'text'],
        'x' => ['x', 'int'],
        'y' => ['y', 'int'],
        'width' => ['width', 'size'],
        'height' => ['height', 'size'],
        'labelfont' => ['labelfont', 'int'],
        'opacity' => ['opacity', 'percent'],
        'outlinewidth' => ['outlinewidth', 'outline'],
        'fillcolour' => ['fillcolour', 'colour'],
        'outlinecolour' => ['outlinecolour', 'colour'],
        'labelfontcolour' => ['labelfontcolour', 'colour'],
    ];

    private const ZONE_CHOICES = [
        'labelpos' => \WeatherMapZone::LABEL_POSITIONS,
        'outlinestyle' => ['solid', 'dashed'],
    ];

    private const ANCHORS = ['C', 'N', 'S', 'E', 'W', 'NE', 'NW', 'SE', 'SW'];

    /** Hints the editor owns: grouping and locking */
    public const EDITOR_HINTS = ['editor_group', 'editor_lock'];

    /**
     * Map furniture the editor can move, by image map area name:
     * area name => [kind, name]. Legends are LEGEND:<scale>.
     */
    private const FURNITURE_AREAS = [
        'TIMESTAMP' => ['timestamp', ''],
        'MINTIMESTAMP' => ['timestamp', 'MIN'],
        'MAXTIMESTAMP' => ['timestamp', 'MAX'],
        'TITLE' => ['title', ''],
        'WIDTHLEGEND' => ['widthlegend', ''],
    ];

    private string $file;

    public function __construct(string $file)
    {
        $this->file = $file;
    }

    public function load(): \WeatherMap
    {
        $map = new \WeatherMap();
        $map->context = 'editor';

        if (file_exists($this->file)) {
            clearstatcache(true, $this->file);
            ob_start();
            $map->ReadConfig($this->file);
            ob_end_clean();
        }

        return $map;
    }

    /**
     * Apply operations and save. Returns what the editor needs to know
     * about the result (names given to new items).
     */
    public function apply(array $ops): array
    {
        $map = $this->load();
        $result = ['created' => []];

        foreach ($ops as $op) {
            if (!is_array($op) || !isset($op['op'])) {
                throw new InvalidArgumentException(__('Each operation needs an "op"'));
            }

            $created = match ($op['op']) {
                'move' => $this->move($map, $op),
                'set_node' => $this->setNodes($map, $op),
                'set_link' => $this->setLinks($map, $op),
                'rename_node' => $this->renameNode($map, (string) ($op['name'] ?? ''), (string) ($op['to'] ?? '')),
                'delete' => $this->delete($map, (array) ($op['nodes'] ?? []), (array) ($op['links'] ?? [])),
                'add_node' => $this->addNode($map, $op),
                'add_link' => $this->addLink($map, $op),
                'clone' => $this->cloneNodes($map, (array) ($op['nodes'] ?? []), (int) ($op['dx'] ?? 20), (int) ($op['dy'] ?? 20)),
                'hint' => $this->hint($map, $op),
                'set_map' => $this->setMap($map, $op),
                'paste' => $this->paste($map, $op),
                'add_zone' => $this->addZone($map, $op),
                'set_zone' => $this->setZones($map, $op),
                'delete_zones' => $this->deleteZones($map, (array) ($op['names'] ?? [])),
                default => throw new InvalidArgumentException(__('Unknown operation: :op', ['op' => $op['op']])),
            };

            if (is_array($created)) {
                $result['created'] = array_merge($result['created'], $created);
            }
        }

        $this->save($map);

        return $result;
    }

    public function save(\WeatherMap $map): void
    {
        ob_start();
        $ok = $map->WriteConfig($this->file);
        ob_end_clean();

        if ($ok === false) {
            throw new \RuntimeException('Could not write ' . basename($this->file));
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->file, true);
        }
        clearstatcache(true, $this->file);
    }

    // ------------------------------------------------------------------
    // Operations

    /**
     * {op: move, nodes: {name: [x, y]}, vias: {link: [[x, y], ...]}, zones: {name: [x, y]},
     *  furniture: {"legend:DEFAULT" | "timestamp:" | "timestamp:MIN" | "title:" | "widthlegend:": [x, y]}}
     * Positions are absolute map pixels; for furniture, the position its
     * keyword takes (KEYPOS, TIMEPOS, TITLEPOS, SET width_legend), as in model().
     */
    private function move(\WeatherMap $map, array $op): void
    {
        foreach ((array) ($op['nodes'] ?? []) as $name => $pos) {
            $node = $this->node($map, (string) $name);
            [$x, $y] = $this->point($pos);

            if ($node->relative_to !== '' && isset($map->nodes[$node->relative_to])) {
                // Keep the node attached to its parent, with a new offset
                $parent = $map->nodes[$node->relative_to];
                $node->polar = false;
                $node->original_x = $x - $parent->x;
                $node->original_y = $y - $parent->y;
            }

            $node->x = $x;
            $node->y = $y;
        }

        foreach ((array) ($op['vias'] ?? []) as $name => $vias) {
            $this->setVias($this->link($map, (string) $name), (array) $vias);
        }

        foreach ((array) ($op['zones'] ?? []) as $name => $pos) {
            $zone = $this->zone($map, (string) $name);
            [$zone->x, $zone->y] = $this->point($pos);
        }

        foreach ((array) ($op['furniture'] ?? []) as $key => $pos) {
            $this->moveFurniture($map, (string) $key, $this->point($pos));
        }
    }

    /** Place the legend of a scale, a timestamp, the title or the width legend */
    private function moveFurniture(\WeatherMap $map, string $key, array $pos): void
    {
        [$kind, $name] = array_pad(explode(':', $key, 2), 2, '');
        // negative positions mean "hidden" or "from the far edge" for these keywords
        [$x, $y] = [max(0, $pos[0]), max(0, $pos[1])];

        switch ($kind) {
            case 'legend':
                if (!isset($map->keyx[$name])) {
                    throw new InvalidArgumentException(__('Unknown legend: :name', ['name' => $name]));
                }
                $map->keyx[$name] = $x;
                $map->keyy[$name] = $y;
                return;

            case 'timestamp':
                // TIMEPOS 0 0 means "top right corner"
                $prefix = ['' => '', 'MIN' => 'min', 'MAX' => 'max'][$name] ?? null;
                if ($prefix === null) {
                    throw new InvalidArgumentException(__('Unknown timestamp: :name', ['name' => $name]));
                }
                $map->{$prefix . 'timex'} = max(1, $x);
                $map->{$prefix . 'timey'} = max(1, $y);
                return;

            case 'title':
                $map->titlex = $x;
                $map->titley = $y;
                return;

            case 'widthlegend':
                $map->add_hint('width_legend', $x . ' ' . $y);
                return;
        }

        throw new InvalidArgumentException(__('Unknown map item: :name', ['name' => $key]));
    }

    /** {op: add_zone, x, y, width?, height?, label?, name?} */
    private function addZone(\WeatherMap $map, array $op): array
    {
        [$x, $y] = $this->point([$op['x'] ?? 0, $op['y'] ?? 0]);
        $name = $this->freeName($map->zones, $this->name((string) ($op['name'] ?? 'zone')));

        $zone = new \WeatherMapZone($name);
        $zone->x = $x;
        $zone->y = $y;
        $zone->width = max(1, (int) ($op['width'] ?? $zone->width));
        $zone->height = max(1, (int) ($op['height'] ?? $zone->height));
        $zone->label = isset($op['label']) ? $this->text($op['label']) : $name;
        $zone->defined_in = $map->configfile;

        $map->zones[$name] = $zone;

        return ['zones' => [$name]];
    }

    /** {op: set_zone, names: [...], props: {label, x, y, width, height, fillcolour, ...}} or props.name to rename one */
    private function setZones(\WeatherMap $map, array $op): void
    {
        $props = (array) ($op['props'] ?? []);
        $names = array_map('strval', (array) ($op['names'] ?? []));

        foreach ($names as $name) {
            $zone = $this->zone($map, $name);

            foreach ($props as $key => $value) {
                if (isset(self::ZONE_FIELDS[$key])) {
                    [$field, $type] = self::ZONE_FIELDS[$key];
                    $zone->$field = match ($type) {
                        'text' => $this->text($value),
                        'size' => max(1, (int) $value),
                        'percent' => max(0, min(100, (int) $value)),
                        // same bounds as OUTLINEWIDTH in the .conf
                        'outline' => max(0, min(20, (int) $value)),
                        'colour' => $this->zoneColour($value),
                        default => (int) $value,
                    };
                } elseif (isset(self::ZONE_CHOICES[$key])) {
                    $value = $key === 'labelpos' ? strtoupper((string) $value) : strtolower((string) $value);
                    if (!in_array($value, self::ZONE_CHOICES[$key], true)) {
                        throw new InvalidArgumentException(__('Invalid value for :key: :value', ['key' => $key, 'value' => $value]));
                    }
                    $zone->$key = $value;
                } elseif ($key !== 'name') {
                    throw new InvalidArgumentException(__('Unknown zone property: :key', ['key' => $key]));
                }
            }
        }

        if (isset($props['name']) && count($names) === 1) {
            $this->renameZone($map, $names[0], (string) $props['name']);
        }
    }

    private function renameZone(\WeatherMap $map, string $name, string $to): void
    {
        $to = $this->name($to);
        if ($to === $name) {
            return;
        }
        if (isset($map->zones[$to])) {
            throw new InvalidArgumentException(__('A zone named :name already exists', ['name' => $to]));
        }

        // keep the order of the zones, which is their drawing order
        $zones = [];
        foreach ($map->zones as $key => $zone) {
            if ($key === $name) {
                $zone->name = $to;
                $key = $to;
            }
            $zones[$key] = $zone;
        }
        $map->zones = $zones;
    }

    private function deleteZones(\WeatherMap $map, array $names): void
    {
        foreach ($names as $name) {
            unset($map->zones[(string) $name]);
        }
    }

    private function setNodes(\WeatherMap $map, array $op): void
    {
        $props = (array) ($op['props'] ?? []);

        foreach ((array) ($op['names'] ?? []) as $name) {
            $node = $this->node($map, (string) $name);

            foreach ($props as $key => $value) {
                if (isset(self::NODE_FIELDS[$key])) {
                    $field = self::NODE_FIELDS[$key];
                    $node->$field = in_array($key, ['label', 'labeloffset'], true)
                        ? $this->text($value)
                        : (int) $value;
                } elseif (isset(self::NODE_COLOURS[$key])) {
                    $field = self::NODE_COLOURS[$key];
                    $node->$field = $this->colour($value);
                } elseif ($key === 'icon') {
                    $node->iconfile = $this->iconFile((string) $value);
                } elseif ($key === 'infourl') {
                    $node->infourl[IN] = $this->text($value);
                    $node->infourl[OUT] = $node->infourl[IN];
                } elseif ($key === 'target') {
                    $node->targets = $this->targets((string) $value);
                } elseif ($key === 'position') {
                    [$node->x, $node->y] = $this->point($value);
                } elseif ($key === 'labelshift') {
                    // where the label sits from its default place, in pixels; null puts it back
                    $template = $map->nodes[$node->template] ?? $map->nodes['DEFAULT'] ?? null;
                    $inherited = $template === null ? '' : (string) $template->labeloffset;
                    if ($value === null) {
                        $node->labeloffsetx = $template === null ? 0 : $template->labeloffsetx;
                        $node->labeloffsety = $template === null ? 0 : $template->labeloffsety;
                        $node->labeloffset = $inherited;
                    } elseif ($inherited === '') {
                        [$node->labeloffsetx, $node->labeloffsety] = $this->point($value);
                        $node->labeloffset = '';
                    } else {
                        // a compass offset from the template wins over LABELOFFSET x y,
                        // so override it with a pixel one (LABELOFFSET dx:dy)
                        [$dx, $dy] = $this->point($value);
                        $node->labeloffset = $dx . ':' . $dy;
                        $node->labeloffsetx = $template->labeloffsetx;
                        $node->labeloffsety = $template->labeloffsety;
                    }
                } else {
                    throw new InvalidArgumentException(__('Unknown node property: :key', ['key' => $key]));
                }
            }
        }
    }

    private function setLinks(\WeatherMap $map, array $op): void
    {
        $props = (array) ($op['props'] ?? []);

        foreach ((array) ($op['names'] ?? []) as $name) {
            $link = $this->link($map, (string) $name);

            foreach ($props as $key => $value) {
                if (isset(self::LINK_FIELDS[$key])) {
                    $field = self::LINK_FIELDS[$key];
                    if ($key === 'a_offset' || $key === 'b_offset') {
                        $link->$field = $this->anchor((string) $value);
                    } else {
                        $link->$field = (int) $value;
                    }
                } elseif (isset(self::LINK_CHOICES[$key])) {
                    if (!in_array($value, self::LINK_CHOICES[$key], true)) {
                        throw new InvalidArgumentException(__('Invalid :key: :value', ['key' => $key, 'value' => $value]));
                    }
                    $field = $key === 'labelstyle' ? 'labelstyle' : $key;
                    $link->$field = $value;
                } elseif ($key === 'width') {
                    $link->width = strtolower((string) $value) === 'auto' ? 'auto' : max(1, (int) $value);
                } elseif ($key === 'bandwidth' || $key === 'bandwidth_in' || $key === 'bandwidth_out') {
                    $bw = $this->bandwidth((string) $value);
                    if ($key !== 'bandwidth_out') {
                        $link->max_bandwidth_in_cfg = $bw;
                    }
                    if ($key !== 'bandwidth_in') {
                        $link->max_bandwidth_out_cfg = $bw;
                    }
                } elseif ($key === 'target') {
                    $link->targets = $this->targets((string) $value);
                } elseif ($key === 'datasource') {
                    $hint = $this->datasourceHint($value);
                    if ($hint === null) {
                        unset($link->hints['datasource']);
                    } else {
                        $link->hints['datasource'] = $hint;
                    }
                } elseif ($key === 'comment_in' || $key === 'comment_out') {
                    $link->comments[$key === 'comment_in' ? IN : OUT] = $this->text($value);
                } elseif ($key === 'infourl') {
                    $link->infourl[IN] = $this->text($value);
                    $link->infourl[OUT] = $link->infourl[IN];
                } elseif ($key === 'via') {
                    $this->setVias($link, (array) $value);
                } else {
                    throw new InvalidArgumentException(__('Unknown link property: :key', ['key' => $key]));
                }
            }
        }
    }

    private function renameNode(\WeatherMap $map, string $name, string $to): void
    {
        $node = $this->node($map, $name);
        $to = $this->name($to);

        if ($to === $name) {
            return;
        }
        if (isset($map->nodes[$to])) {
            throw new InvalidArgumentException(__('A node called :name already exists', ['name' => $to]));
        }

        $node->name = $to;
        $map->nodes[$to] = $node;
        unset($map->nodes[$name]);

        foreach ($map->nodes as $other) {
            if ($other->relative_to === $name) {
                $other->relative_to = $to;
            }
        }

        foreach ($map->links as $link) {
            if (isset($link->a) && $link->a->name === $to) {
                $link->a = $node;
            }
            if (isset($link->b) && $link->b->name === $to) {
                $link->b = $node;
            }
            foreach ($link->vialist as $i => $via) {
                if (isset($via[2]) && $via[2] === $name) {
                    $link->vialist[$i][2] = $to;
                }
            }
        }
    }

    private function delete(\WeatherMap $map, array $nodes, array $links): void
    {
        foreach ($nodes as $name) {
            $name = (string) $name;
            if (!isset($map->nodes[$name]) || $this->isDefault($name)) {
                continue;
            }

            foreach ($map->links as $linkName => $link) {
                if (isset($link->a) && ($link->a->name === $name || $link->b->name === $name)) {
                    unset($map->links[$linkName]);
                }
            }

            // Nodes placed relative to this one keep their place on the map
            foreach ($map->nodes as $other) {
                if ($other->relative_to === $name) {
                    $other->relative_to = '';
                }
            }

            unset($map->nodes[$name]);
        }

        foreach ($links as $name) {
            $name = (string) $name;
            if (isset($map->links[$name]) && !$this->isDefault($name)) {
                unset($map->links[$name]);
            }
        }
    }

    /** {op: add_node, x, y, label?, name?, template?, icon?} */
    private function addNode(\WeatherMap $map, array $op): array
    {
        [$x, $y] = $this->point([$op['x'] ?? 0, $op['y'] ?? 0]);
        $template = (string) ($op['template'] ?? 'DEFAULT');
        if (!isset($map->nodes[$template])) {
            throw new InvalidArgumentException(__('Unknown template: :template', ['template' => $template]));
        }

        $name = $this->freeName($map->nodes, $this->name((string) ($op['name'] ?? 'node')));

        $node = new \WeatherMapNode();
        $node->name = $name;
        $node->template = $template;
        $node->Reset($map);
        if ($template !== 'DEFAULT') {
            $node->CopyFrom($map->nodes[$template]);
            $node->name = $name;
            $node->template = $template;
        }
        $node->x = $x;
        $node->y = $y;
        $node->defined_in = $map->configfile;

        if (isset($op['label'])) {
            $node->label = $this->text($op['label']);
        } elseif ($node->label === '' || $node->label === $map->nodes[':: DEFAULT ::']->label) {
            $node->label = $name;
        }

        if (isset($op['icon'])) {
            $node->iconfile = $this->iconFile((string) $op['icon']);
        }

        $map->nodes[$name] = $node;

        return ['nodes' => [$name]];
    }

    /** {op: add_link, a, b, name?, template?, width?, bandwidth?, target?} */
    private function addLink(\WeatherMap $map, array $op): array
    {
        $a = $this->node($map, (string) ($op['a'] ?? ''));
        $b = $this->node($map, (string) ($op['b'] ?? ''));
        if ($a === $b) {
            throw new InvalidArgumentException(__('A link needs two different nodes'));
        }

        $template = (string) ($op['template'] ?? 'DEFAULT');
        if (!isset($map->links[$template])) {
            throw new InvalidArgumentException(__('Unknown template: :template', ['template' => $template]));
        }

        $name = $this->freeName($map->links, $this->name((string) ($op['name'] ?? ($a->name . '-' . $b->name))));

        $link = new \WeatherMapLink();
        $link->Reset($map);
        if ($template !== 'DEFAULT') {
            $link->CopyFrom($map->links[$template]);
        }
        $link->template = $template;
        $link->name = $name;
        $link->a = $a;
        $link->b = $b;
        $link->defined_in = $map->configfile;
        $map->links[$name] = $link;

        $props = array_intersect_key($op, array_flip(['width', 'bandwidth', 'target', 'datasource']));
        if ($props) {
            $this->setLinks($map, ['names' => [$name], 'props' => $props]);
        }

        return ['links' => [$name]];
    }

    /**
     * Copy nodes, and the links between them, shifted by (dx, dy).
     */
    private function cloneNodes(\WeatherMap $map, array $names, int $dx, int $dy): array
    {
        $renamed = [];

        foreach ($names as $name) {
            $source = $this->node($map, (string) $name);
            $newName = $this->freeName($map->nodes, $source->name . '_copy');

            $node = new \WeatherMapNode();
            $node->Reset($map);
            $node->CopyFrom($source);
            $node->template = $source->template;
            $node->name = $newName;
            $node->x = $source->x + $dx;
            $node->y = $source->y + $dy;
            $node->relative_to = '';
            $node->defined_in = $map->configfile;
            $map->nodes[$newName] = $node;
            $renamed[$source->name] = $newName;
        }

        $links = [];
        foreach ($map->links as $link) {
            if (!isset($link->a) || !isset($renamed[$link->a->name]) || !isset($renamed[$link->b->name])) {
                continue;
            }

            $newName = $this->freeName($map->links, $renamed[$link->a->name] . '-' . $renamed[$link->b->name]);
            $copy = new \WeatherMapLink();
            $copy->Reset($map);
            $copy->CopyFrom($link);
            $copy->template = $link->template;
            $copy->name = $newName;
            $copy->a = $map->nodes[$renamed[$link->a->name]];
            $copy->b = $map->nodes[$renamed[$link->b->name]];
            $copy->vialist = [];
            foreach ($link->vialist as $via) {
                $copy->vialist[] = isset($via[2]) ? $via : [$via[0] + $dx, $via[1] + $dy];
            }
            $copy->defined_in = $map->configfile;
            $map->links[$newName] = $copy;
            $links[] = $newName;
        }

        return ['nodes' => array_values($renamed), 'links' => $links];
    }

    /**
     * {op: paste, text, at?: [x, y], dx?, dy?}: the text of MapClipboard::copy().
     * With `at`, the centre of the pasted nodes lands there; otherwise they
     * move by (dx, dy).
     */
    private function paste(\WeatherMap $map, array $op): array
    {
        if (!isset($op['text']) || !is_string($op['text'])) {
            throw new InvalidArgumentException(__('Nothing to paste'));
        }
        $at = isset($op['at']) ? $this->point($op['at']) : null;

        return MapClipboard::paste($map, $op['text'], $at, (int) ($op['dx'] ?? 0), (int) ($op['dy'] ?? 0));
    }

    /** {op: hint, kind: node|link|zone, names, key, value} - value null removes it */
    private function hint(\WeatherMap $map, array $op): void
    {
        $key = (string) ($op['key'] ?? '');
        if (!in_array($key, self::EDITOR_HINTS, true)) {
            throw new InvalidArgumentException(__('Unknown editor setting: :key', ['key' => $key]));
        }

        $value = $op['value'] ?? null;
        $value = ($value === null || $value === '' || $value === false) ? null : $this->name((string) $value);

        foreach ((array) ($op['names'] ?? []) as $name) {
            $item = match ($op['kind'] ?? 'node') {
                'link' => $this->link($map, (string) $name),
                'zone' => $this->zone($map, (string) $name),
                default => $this->node($map, (string) $name),
            };
            if ($value === null) {
                unset($item->hints[$key]);
            } else {
                $item->hints[$key] = $value;
            }
        }
    }

    /** {op: set_map, width?, height?, resize_mode?: extend|scale, keep_aspect?, scale_sizes?, title?} */
    private function setMap(\WeatherMap $map, array $op): void
    {
        if (isset($op['title'])) {
            $map->title = $this->text($op['title']);
        }

        if (isset($op['background'])) {
            $bg = trim((string) $op['background']);
            if ($bg !== '' && (str_contains($bg, '..') || !preg_match('/^[\w\/.\-]+\.(png|jpe?g|gif)$/i', $bg))) {
                throw new InvalidArgumentException(__('Invalid background: :value', ['value' => $bg]));
            }
            $map->background = $bg;
        }

        if (isset($op['link_width'])) {
            $this->setDefaultLinkWidth($map, (string) $op['link_width']);
        }

        if (isset($op['width']) || isset($op['height'])) {
            $w = max(1, (int) ($op['width'] ?? $map->width));
            $h = max(1, (int) ($op['height'] ?? $map->height));

            if (($op['resize_mode'] ?? 'extend') === 'scale') {
                \WeatherMapScaler::scale($map, $w, $h, !empty($op['keep_aspect']), !empty($op['scale_sizes']));
            } else {
                $map->width = $w;
                $map->height = $h;
            }
        }
    }

    /**
     * The width of the links of the map: pixels, or "auto" to follow each
     * link's bandwidth. It is the WIDTH of LINK DEFAULT; the links (and
     * templates) that inherited the old value follow, the others keep
     * their own width.
     */
    private function setDefaultLinkWidth(\WeatherMap $map, string $value): void
    {
        $value = strtolower(trim($value));
        if ($value !== 'auto') {
            if (!preg_match('/^\d{1,3}$/', $value) || (int) $value < 1) {
                throw new InvalidArgumentException(__('Invalid link width: :value', ['value' => $value]));
            }
            $value = (int) $value;
        }

        if (!isset($map->links['DEFAULT'])) {
            return;
        }
        $old = (string) $map->links['DEFAULT']->width;
        $map->links['DEFAULT']->width = $value;

        // templates come before the links that use them
        $followed = ['DEFAULT' => true];
        foreach ($map->links as $name => $link) {
            if ($name === 'DEFAULT' || $name === ':: DEFAULT ::') {
                continue;
            }
            if (isset($followed[$link->template]) && (string) $link->width === $old) {
                $link->width = $value;
                $followed[$name] = true;
            }
        }
    }

    // ------------------------------------------------------------------
    // Model for the editor

    /**
     * Geometry and settings of every item, measured by the engine.
     * Call after DrawMap() so boxes and curve points exist.
     */
    public static function model(\WeatherMap $map): array
    {
        $nodes = [];
        $templates = ['nodes' => [], 'links' => []];

        foreach ($map->nodes as $name => $node) {
            if ($name === ':: DEFAULT ::') {
                continue;
            }
            if ($node->x === null) {
                $templates['nodes'][] = $name;
                continue;
            }

            $box = null;
            foreach ($node->boundingboxes as $b) {
                $box = $box === null ? $b : [min($box[0], $b[0]), min($box[1], $b[1]), max($box[2], $b[2]), max($box[3], $b[3])];
            }
            $box ??= [$node->x - 5, $node->y - 5, $node->x + 5, $node->y + 5];

            // with an icon, the label is the second box and can move on its own
            $labelBox = count($node->boundingboxes) >= 2 && (string) $node->label !== ''
                ? array_map(static fn ($v) => (int) round((float) $v), end($node->boundingboxes))
                : null;

            $nodes[] = [
                'name' => $name,
                'id' => 'N' . $node->id,
                'x' => (int) round((float) $node->x),
                'y' => (int) round((float) $node->y),
                'box' => array_map(static fn ($v) => (int) round((float) $v), $box),
                'w' => (int) round((float) $node->width),
                'h' => (int) round((float) $node->height),
                'label' => (string) $node->label,
                'label_box' => $labelBox,
                'label_shift' => array_map(static fn ($v) => (int) round((float) $v), (array) $node->labelshift),
                'icon' => (string) $node->iconfile,
                'template' => (string) $node->template,
                'relative_to' => (string) $node->relative_to,
                'zorder' => (int) $node->zorder,
                'labelfont' => (int) $node->labelfont,
                'labelbgcolour' => self::colourOut($node->labelbgcolour),
                'labelfontcolour' => self::colourOut($node->labelfontcolour),
                'labeloutlinecolour' => self::colourOut($node->labeloutlinecolour),
                'target' => self::targetsOut($node->targets),
                'infourl' => (string) ($node->infourl[IN] ?? ''),
                'group' => (string) ($node->hints['editor_group'] ?? ''),
                'locked' => !empty($node->hints['editor_lock']),
            ];
        }

        $links = [];
        foreach ($map->links as $name => $link) {
            if (!isset($link->a) || !isset($link->b)) {
                if ($name !== ':: DEFAULT ::') {
                    $templates['links'][] = $name;
                }
                continue;
            }

            $vias = [];
            foreach ($link->vialist as $via) {
                $vias[] = isset($via[2]) && isset($map->nodes[$via[2]])
                    ? [(int) round((float) $map->nodes[$via[2]]->x + (float) $via[0]), (int) round((float) $map->nodes[$via[2]]->y + (float) $via[1])]
                    : [(int) round((float) $via[0]), (int) round((float) $via[1])];
            }

            $spine = [];
            foreach ((array) $link->curvepoints as $i => $p) {
                // a light copy of the drawn curve, for hit testing and labels
                if ($i % 4 === 0 || $i === count($link->curvepoints) - 1) {
                    $spine[] = [round((float) $p[0], 1), round((float) $p[1], 1)];
                }
            }

            $links[] = [
                'name' => $name,
                'id' => 'L' . $link->id,
                'a' => $link->a->name,
                'b' => $link->b->name,
                'a_offset' => (string) $link->a_offset,
                'b_offset' => (string) $link->b_offset,
                'via' => $vias,
                'spine' => $spine,
                'viastyle' => (string) $link->viastyle,
                'linkstyle' => (string) $link->linkstyle,
                'width' => (string) $link->width,
                'drawn_width' => method_exists($link, 'EffectiveWidth') ? $link->EffectiveWidth($map) : (int) $link->width,
                'bandwidth_in' => (string) $link->max_bandwidth_in_cfg,
                'bandwidth_out' => (string) $link->max_bandwidth_out_cfg,
                'splitpos' => (int) $link->splitpos,
                'labelpos_in' => (int) $link->labeloffset_in,
                'labelpos_out' => (int) $link->labeloffset_out,
                'target' => self::targetsOut($link->targets),
                'datasource' => (string) ($link->hints['datasource'] ?? ''),
                'comment_in' => (string) ($link->comments[IN] ?? ''),
                'comment_out' => (string) ($link->comments[OUT] ?? ''),
                'zorder' => (int) $link->zorder,
                'group' => (string) ($link->hints['editor_group'] ?? ''),
                'locked' => !empty($link->hints['editor_lock']),
            ];
        }

        $zones = [];
        foreach ($map->zones as $name => $zone) {
            $zones[] = [
                'name' => (string) $name,
                'x' => (int) $zone->x,
                'y' => (int) $zone->y,
                'width' => (int) $zone->width,
                'height' => (int) $zone->height,
                'label' => (string) $zone->label,
                'labelfont' => (int) $zone->labelfont,
                'labelpos' => (string) $zone->labelpos,
                'fillcolour' => self::zoneColourOut($zone->fillcolour),
                'outlinecolour' => self::zoneColourOut($zone->outlinecolour),
                'labelfontcolour' => self::zoneColourOut($zone->labelfontcolour),
                'opacity' => (int) $zone->opacity,
                'outlinewidth' => (int) $zone->outlinewidth,
                'outlinestyle' => (string) $zone->outlinestyle,
                'group' => (string) ($zone->hints['editor_group'] ?? ''),
                'locked' => !empty($zone->hints['editor_lock']),
            ];
        }

        return [
            'map' => [
                'width' => (int) $map->width,
                'height' => (int) $map->height,
                'title' => (string) $map->title,
                'background' => (string) $map->background,
                'link_width' => isset($map->links['DEFAULT']) ? (string) $map->links['DEFAULT']->width : '',
            ],
            'nodes' => $nodes,
            'links' => $links,
            'zones' => $zones,
            'furniture' => self::furnitureOut($map),
            'templates' => $templates,
            'fonts' => array_values(array_unique(array_merge([1, 2, 3, 4, 5], array_keys($map->fonts)))),
        ];
    }

    /**
     * The legends, timestamps, title and width legend as drawn: their box
     * (from the image map) and the position their keyword holds.
     */
    private static function furnitureOut(\WeatherMap $map): array
    {
        $found = [];
        foreach ((array) ($map->imap->shapes ?? []) as $area) {
            if (!$area instanceof \HTML_ImageMap_Area_Rectangle) {
                continue;
            }
            $name = (string) $area->name;
            if (str_starts_with($name, 'LEGEND:')) {
                [$kind, $item] = ['legend', substr($name, 7)];
            } elseif (isset(self::FURNITURE_AREAS[$name])) {
                [$kind, $item] = self::FURNITURE_AREAS[$name];
            } else {
                continue;
            }

            $box = [(int) $area->x1, (int) $area->y1, (int) $area->x2, (int) $area->y2];
            $pos = match ($kind) {
                'legend' => [(int) ($map->keyx[$item] ?? $box[0]), (int) ($map->keyy[$item] ?? $box[1])],
                // text is placed by the left end of its baseline
                'timestamp', 'title' => [$box[0], $box[3]],
                default => [$box[0], $box[1]],
            };

            // the last drawing wins, if an area was recorded twice
            $found[$kind . ':' . $item] = ['kind' => $kind, 'name' => $item, 'box' => $box, 'pos' => $pos];
        }

        return array_values($found);
    }

    // ------------------------------------------------------------------
    // Helpers

    private function node(\WeatherMap $map, string $name): \WeatherMapNode
    {
        if (!isset($map->nodes[$name]) || $this->isDefault($name) || $map->nodes[$name]->x === null) {
            throw new InvalidArgumentException(__('Unknown node: :name', ['name' => $name]));
        }
        return $map->nodes[$name];
    }

    private function link(\WeatherMap $map, string $name): \WeatherMapLink
    {
        if (!isset($map->links[$name]) || $this->isDefault($name) || !isset($map->links[$name]->a)) {
            throw new InvalidArgumentException(__('Unknown link: :name', ['name' => $name]));
        }
        return $map->links[$name];
    }

    private function zone(\WeatherMap $map, string $name): \WeatherMapZone
    {
        if (!isset($map->zones[$name])) {
            throw new InvalidArgumentException(__('Unknown zone: :name', ['name' => $name]));
        }
        return $map->zones[$name];
    }

    /** A zone colour: [r, g, b], or null for none */
    private function zoneColour(mixed $value): ?array
    {
        $value = strtolower(trim((string) $value));
        if ($value === 'contrast') {
            throw new InvalidArgumentException(__('Invalid colour: :value', ['value' => $value]));
        }
        $c = $this->colour($value);
        return $c[0] < 0 ? null : $c;
    }

    private static function zoneColourOut(?array $c): string
    {
        return $c === null ? 'none' : sprintf('#%02x%02x%02x', $c[0], $c[1], $c[2]);
    }

    private function isDefault(string $name): bool
    {
        return $name === 'DEFAULT' || $name === ':: DEFAULT ::';
    }

    private function setVias(\WeatherMapLink $link, array $vias): void
    {
        $link->vialist = [];
        foreach ($vias as $via) {
            $link->vialist[] = $this->point($via);
        }
    }

    private function point(mixed $p): array
    {
        if (!is_array($p) || count($p) < 2 || !is_numeric($p[0] ?? null) || !is_numeric($p[1] ?? null)) {
            throw new InvalidArgumentException(__('A position is [x, y]'));
        }
        return [(int) round((float) $p[0]), (int) round((float) $p[1])];
    }

    /**
     * The interface picker's choice, kept on the link as in the classic
     * editor: {"sourceId":1,"hostId":"10084","interfaceId":"2",...}
     */
    private function datasourceHint(mixed $value): ?string
    {
        if (is_string($value) && trim($value) !== '' && $value[0] === '{') {
            $value = json_decode($value, true);
        }
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        if (is_numeric($value)) {
            return (string) (int) $value;
        }
        if (!is_array($value) || !isset($value['sourceId'])) {
            throw new InvalidArgumentException(__('Invalid data source'));
        }

        $clean = ['sourceId' => (int) $value['sourceId']];
        foreach (['hostId', 'interfaceId', 'inKey', 'outKey'] as $k) {
            if (isset($value[$k]) && $value[$k] !== '') {
                $clean[$k] = str_replace(["\r", "\n"], '', (string) $value[$k]);
            }
        }

        return json_encode($clean, JSON_UNESCAPED_SLASHES);
    }

    private function anchor(string $value): string
    {
        $value = strtoupper(trim($value));
        if ($value === '') {
            return 'C';
        }
        // compass points, or "x:y" pixel offsets, or a percentage like "N90"
        if (in_array($value, self::ANCHORS, true) || preg_match('/^-?\d+:-?\d+$/', $value) || preg_match('/^(N|S|E|W|NE|NW|SE|SW)\d{1,3}$/', $value)) {
            return $value;
        }
        throw new InvalidArgumentException(__('Invalid anchor: :value', ['value' => $value]));
    }

    private function bandwidth(string $value): string
    {
        $value = trim($value);
        if (strtolower($value) === 'auto') {
            return 'auto';
        }
        if (!preg_match('/^\d+(\.\d+)?[KMGT]?$/i', $value)) {
            throw new InvalidArgumentException(__('Invalid bandwidth: :value', ['value' => $value]));
        }
        return $value;
    }

    private function colour(mixed $value): array
    {
        $value = strtolower(trim((string) $value));
        if ($value === '' || $value === 'none') {
            return [-1, -1, -1];
        }
        if ($value === 'contrast') {
            return [-3, -3, -3];
        }
        if (preg_match('/^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/', $value, $m)) {
            return [hexdec($m[1]), hexdec($m[2]), hexdec($m[3])];
        }
        throw new InvalidArgumentException(__('Invalid colour: :value', ['value' => $value]));
    }

    private static function colourOut(mixed $c): string
    {
        if (!is_array($c) || count($c) < 3) {
            return '';
        }
        if ($c[0] == -1) {
            return 'none';
        }
        if ($c[0] == -3) {
            return 'contrast';
        }
        if ($c[0] < 0) {
            return '';
        }
        return sprintf('#%02x%02x%02x', $c[0], $c[1], $c[2]);
    }

    private function targets(string $value): array
    {
        $out = [];
        foreach (preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) as $target) {
            $t = [$target, 'traffic_in', 'traffic_out', 0, $target];
            if (preg_match('/(.*\.rrd):([\-a-zA-Z0-9_]+):([\-a-zA-Z0-9_]+)$/i', $target, $m)) {
                $t[0] = trim($m[1]);
                $t[1] = trim($m[2]);
                $t[2] = trim($m[3]);
            }
            $out[] = $t;
        }
        return $out;
    }

    private static function targetsOut(array $targets): string
    {
        return trim(implode(' ', array_map(static fn ($t) => (string) ($t[4] ?? $t[0] ?? ''), $targets)));
    }

    private function iconFile(string $value): string
    {
        $value = trim($value);
        if ($value === '' || $value === 'none') {
            return '';
        }
        if (in_array($value, ['box', 'rbox', 'round', 'inpie', 'outpie', 'nink'], true)) {
            return $value;
        }
        if (str_contains($value, '..') || !preg_match('/^[\w\/.\-{}:]+\.(png|jpe?g|gif)$/i', $value)) {
            throw new InvalidArgumentException(__('Invalid icon: :value', ['value' => $value]));
        }
        return $value;
    }

    private function text(mixed $value): string
    {
        // a .conf value is one line
        return trim(str_replace(["\r", "\n"], ' ', (string) $value));
    }

    private function name(string $value): string
    {
        $value = preg_replace('/[^\w.\-:]/', '_', trim($value));
        if ($value === '' || $value === null) {
            throw new InvalidArgumentException(__('A name is required'));
        }
        return $value;
    }

    private function freeName(array $existing, string $base): string
    {
        $name = $base;
        $i = 2;
        while (isset($existing[$name])) {
            $name = $base . '_' . $i++;
        }
        return $name;
    }
}
