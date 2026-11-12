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

use App\Services\Basemap;

use InvalidArgumentException;

class MapEditor
{
    /** Node fields the inspector may change, by request key */
    private const NODE_FIELDS = [
        'label' => 'label',
        'labelfont' => 'labelfont',
        'labeloffset' => 'labeloffset',
        'labelangle' => 'labelangle',
        'zorder' => 'zorder',
    ];

    /** Icons the engine draws itself, at the ICON size */
    private const ICON_SHAPES = ['box', 'rbox', 'round', 'inpie', 'outpie', 'nink'];

    /** Size of a new shape icon, and the largest icon, in pixels */
    private const SHAPE_SIZE = 32;
    private const ICON_MAX = 1024;

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
        'commentcurve' => ['0', '1'],
        'bwstyle' => ['classic', 'angled', 'pill'],
        'arrowstyle' => ['classic', 'compact', 'flat'],
    ];

    /** How far a bandwidth label may be moved across its link, in pixels */
    private const LABEL_SHIFT_MAX = 60;

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

    private const NOTE_ALIGNS = ['left', 'centre', 'right'];

    /**
     * Label position presets of the inspector, as compass offsets of the
     * icon: above, top, centre, bottom, below (SET editor_label_position,
     * the PHP Weathermap 2.0 hint)
     */
    public const LABEL_PRESETS = ['N', 'N60', 'C', 'S60', 'S'];

    private const ANCHORS = ['C', 'N', 'S', 'E', 'W', 'NE', 'NW', 'SE', 'SW'];

    /** Hints the editor owns: grouping and locking */
    public const EDITOR_HINTS = ['editor_group', 'editor_lock', 'editor_layer'];

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
                'show_furniture' => $this->showFurniture($map, $op),
                'hide_furniture' => $this->hideFurniture($map, (array) ($op['keys'] ?? [])),
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
                'define_font' => $this->defineFont($map, $op),
                'set_scale' => $this->setScale($map, (array) ($op['steps'] ?? [])),
                'apply_theme' => $this->applyTheme($map, (string) ($op['theme'] ?? '')),
                default => throw new InvalidArgumentException(__('Unknown operation: :op', ['op' => $op['op']])),
            };

            if (is_array($created)) {
                $result['created'] = array_merge($result['created'], $created);
            }
        }

        // nodes with a point on the basemap go there, also after the frame changed
        \WeatherMapGeo::placeNodes($map);

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
            // a node with a point on the basemap: the point moves with it
            \WeatherMapGeo::follow($map, $node);
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

    /**
     * {op: show_furniture, keys: [...], at?: [x, y]} - put legends,
     * timestamps or the width legend back on the map: at `at` (the position
     * their keyword takes, as for move) or where they were before.
     */
    private function showFurniture(\WeatherMap $map, array $op): void
    {
        $at = isset($op['at']) ? $this->point($op['at']) : null;
        foreach ((array) ($op['keys'] ?? []) as $key) {
            $this->setFurnitureShown($map, (string) $key, true);
            if ($at !== null) {
                $this->moveFurniture($map, (string) $key, $at);
            }
        }
    }

    /**
     * {op: hide_furniture, keys: [...]} - the Delete of the editor for
     * legends, timestamps and the width legend: they stay in the .conf,
     * hidden (a negative position, or no SET width_legend).
     */
    private function hideFurniture(\WeatherMap $map, array $keys): void
    {
        foreach ($keys as $key) {
            $this->setFurnitureShown($map, (string) $key, false);
        }
    }

    /**
     * Show or hide a piece of furniture by its key ("legend:DEFAULT",
     * "timestamp:", "timestamp:MIN", "timestamp:MAX", "widthlegend:").
     * Legends and timestamps keep their place while hidden as (-x - 1,
     * -y - 1), so showing them puts them back where they were.
     */
    private function setFurnitureShown(\WeatherMap $map, string $key, bool $shown): void
    {
        [$kind, $name] = array_pad(explode(':', $key, 2), 2, '');

        switch ($kind) {
            case 'legend':
                if (!isset($map->keyx[$name]) && !isset($map->colours[$name])) {
                    throw new InvalidArgumentException(__('Unknown legend: :name', ['name' => $name]));
                }
                [$map->keyx[$name], $map->keyy[$name]] = self::legendPosition($shown, $map->keyx[$name] ?? -1, $map->keyy[$name] ?? -1);
                $map->keytext[$name] ??= 'Traffic Load';
                $map->keystyle[$name] ??= 'classic';
                $map->keysize[$name] ??= 400;
                return;

            case 'timestamp':
                $prefix = ['' => '', 'MIN' => 'min', 'MAX' => 'max'][$name] ?? null;
                if ($prefix === null) {
                    throw new InvalidArgumentException(__('Unknown timestamp: :name', ['name' => $name]));
                }
                [$x, $y] = [(int) $map->{$prefix . 'timex'}, (int) $map->{$prefix . 'timey'}];
                $hidden = $x < 0 || $y < 0;
                if ($shown && $hidden) {
                    [$x, $y] = [$x < 0 ? -$x - 1 : $x, $y < 0 ? -$y - 1 : $y];
                    if ($x > $map->width || $y > $map->height) {
                        // never placed (the -10000 of MINTIMEPOS and MAXTIMEPOS): bottom left
                        [$x, $y] = $prefix === '' ? [0, 0] : [10, max(1, $map->height - ($prefix === 'min' ? 24 : 8))];
                    }
                } elseif (!$shown && !$hidden) {
                    [$x, $y] = [-$x - 1, -$y - 1];
                }
                $map->{$prefix . 'timex'} = $x;
                $map->{$prefix . 'timey'} = $y;
                return;

            case 'widthlegend':
                if (!$shown) {
                    unset($map->hints['width_legend']);
                } elseif (!self::widthLegendShown($map)) {
                    // bottom left corner (negative counts from the far edge)
                    $map->add_hint('width_legend', '10 -10');
                }
                return;

            case 'title':
                throw new InvalidArgumentException(__('The title cannot be hidden: it shows the map name.'));
        }

        throw new InvalidArgumentException(__('Unknown map item: :name', ['name' => $key]));
    }

    /** SET width_legend holds a position, so the engine draws the legend */
    private static function widthLegendShown(\WeatherMap $map): bool
    {
        return (bool) preg_match('/^-?\d+[\s,]+-?\d+$/', trim((string) $map->get_hint('width_legend')));
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

    /**
     * Put a node's label at a preset place (null: back to the template's).
     * The preset is kept as SET editor_label_position, so PHP Weathermap 2.0
     * reads it too; a pixel offset from a drag is dropped.
     */
    private function setLabelPreset(\WeatherMap $map, \WeatherMapNode $node, mixed $value): void
    {
        $template = $map->nodes[$node->template] ?? $map->nodes['DEFAULT'] ?? null;
        $inherited = $template === null ? '' : (string) $template->labeloffset;
        $node->labeloffsetx = $template === null ? 0 : $template->labeloffsetx;
        $node->labeloffsety = $template === null ? 0 : $template->labeloffsety;
        $node->labeloffset = $inherited;

        if ($value === null || $value === '') {
            unset($node->hints['editor_label_position']);
            return;
        }
        $preset = strtoupper((string) $value);
        if (!in_array($preset, self::LABEL_PRESETS, true)) {
            throw new InvalidArgumentException(__('Unknown label position: :value', ['value' => (string) $value]));
        }
        $node->hints['editor_label_position'] = $preset;
        if ($inherited !== '') {
            // a compass LABELOFFSET from the template wins over the hint
            $node->labeloffset = $preset;
        }
    }

    /**
     * A box with its label, or a round badge (SET node_style badge). The
     * hint is written only where the node differs from its template.
     */
    private function setNodeStyle(\WeatherMap $map, \WeatherMapNode $node, string $style): void
    {
        if (!in_array($style, ['box', 'badge'], true)) {
            throw new InvalidArgumentException(__('Invalid :key: :value', ['key' => 'style', 'value' => $style]));
        }
        $template = $map->nodes[$node->template] ?? $map->nodes['DEFAULT'] ?? null;
        $inherited = $template !== null && $template->is_badge() ? 'badge' : 'box';
        if ($style === $inherited) {
            unset($node->hints['node_style']);
        } else {
            $node->hints['node_style'] = $style;
        }
    }

    /**
     * A hint written only where an item differs from its template (NODE or
     * LINK DEFAULT, or the template it names); $empty is the value meaning
     * "not set".
     *
     * @param \WeatherMapNode|\WeatherMapLink $item
     * @param \WeatherMapNode|\WeatherMapLink|null $template
     */
    private function setHint(object $item, ?object $template, string $key, string $value, string $empty): void
    {
        $inherited = $template !== null && isset($template->hints[$key]) ? trim((string) $template->hints[$key]) : $empty;
        if ($value === $inherited) {
            unset($item->hints[$key]);
        } else {
            // a template sets it: write the empty value to override it
            $item->hints[$key] = $value;
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
                } elseif ($key === 'iconscalew' || $key === 'iconscaleh') {
                    // 0 draws a picture at its own size
                    $node->$key = max(0, min(self::ICON_MAX, (int) $value));
                } elseif ($key === 'infourl') {
                    $node->infourl[IN] = $this->text($value);
                    $node->infourl[OUT] = $node->infourl[IN];
                } elseif ($key === 'hover_url') {
                    $node->overliburl[IN] = $this->urls((string) $value);
                    $node->overliburl[OUT] = $node->overliburl[IN];
                } elseif ($key === 'note_text') {
                    $this->setNoteText($node, (string) $value);
                } elseif ($key === 'note_align') {
                    if (!in_array($value, self::NOTE_ALIGNS, true)) {
                        throw new InvalidArgumentException(__('Unknown alignment: :value', ['value' => (string) $value]));
                    }
                    if ($value === 'left') {
                        unset($node->hints['editor_note_align']);
                    } else {
                        $node->hints['editor_note_align'] = $value;
                    }
                } elseif ($key === 'target') {
                    $node->targets = $this->targets((string) $value);
                } elseif ($key === 'position') {
                    [$node->x, $node->y] = $this->point($value);
                } elseif ($key === 'label_position') {
                    $this->setLabelPreset($map, $node, $value);
                } elseif ($key === 'style') {
                    $this->setNodeStyle($map, $node, (string) $value);
                } elseif ($key === 'geo') {
                    // SET geo <lat> <lon>: the node sits on that point of the basemap; '' or null frees it
                    $this->setGeo($map, $node, $value);
                } elseif ($key === 'site') {
                    // SET site: the group of the node in the viewer's site list
                    $site = trim((string) preg_replace('/\s+/u', ' ', $this->text($value)));
                    $this->setHint($node, $map->nodes[$node->template] ?? $map->nodes['DEFAULT'] ?? null, 'site', $site, '');
                } elseif ($key === 'labelshift') {
                    // where the label sits from its default place, in pixels; null puts it back
                    $template = $map->nodes[$node->template] ?? $map->nodes['DEFAULT'] ?? null;
                    $inherited = $template === null ? '' : (string) $template->labeloffset;
                    // the shift sent already counts the preset: it replaces it
                    unset($node->hints['editor_label_position']);
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
            $this->fixIconSize($node);
        }
    }

    /** Several lines of text, kept on the one LABEL line of the .conf with \n */
    private function setNoteText(\WeatherMapNode $node, string $text): void
    {
        $lines = preg_split('/\R/u', $text) ?: [''];
        $lines = array_map(static fn (string $l) => rtrim((string) preg_replace('/[\x00-\x1f\x7f]/u', ' ', $l)), $lines);
        // no empty lines at the ends: the box would grow for nothing
        while (count($lines) > 1 && end($lines) === '') {
            array_pop($lines);
        }
        while (count($lines) > 1 && $lines[0] === '') {
            array_shift($lines);
        }
        $node->label = \WeatherMapNode::note_encode(implode("\n", $lines));
        $node->hints['editor_note_escaped'] = '1';
    }

    /**
     * The engine scales an icon only when it has both sizes, and draws a
     * shape (box, circle...) only with a size: give the missing one.
     */
    private function fixIconSize(\WeatherMapNode $node): void
    {
        $w = (int) $node->iconscalew;
        $h = (int) $node->iconscaleh;
        if ($w > 0 && $h <= 0) {
            $node->iconscaleh = $w;
        } elseif ($h > 0 && $w <= 0) {
            $node->iconscalew = $h;
        } elseif ($w <= 0 && in_array($node->iconfile, self::ICON_SHAPES, true)) {
            $node->iconscalew = self::SHAPE_SIZE;
            $node->iconscaleh = self::SHAPE_SIZE;
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
                    $field = $key === 'bwstyle' ? 'labelboxstyle' : $key;
                    $link->$field = $value;
                    if ($key === 'labelstyle') {
                        self::labelFormats($link, $value);
                    }
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
                } elseif ($key === 'hover_url') {
                    $link->overliburl[IN] = $this->urls((string) $value);
                    $link->overliburl[OUT] = $link->overliburl[IN];
                } elseif ($key === 'via') {
                    $this->setVias($link, (array) $value);
                } elseif ($key === 'labelshift_in' || $key === 'labelshift_out') {
                    // SET bwlabel_offset_in|out: the label moved across the link
                    if (!is_numeric($value)) {
                        throw new InvalidArgumentException(__('Invalid :key: :value', ['key' => $key, 'value' => (string) $value]));
                    }
                    $shift = (int) round(max(-self::LABEL_SHIFT_MAX, min(self::LABEL_SHIFT_MAX, (float) $value)));
                    $template = $map->links[$link->template] ?? $map->links['DEFAULT'] ?? null;
                    $this->setHint($link, $template, 'bwlabel_offset_' . substr($key, 11), (string) $shift, '0');
                } elseif ($key === 'traffic_total') {
                    // SET traffic_total 0 leaves the link out of the viewer's total traffic
                    if (!in_array((string) $value, ['0', '1'], true)) {
                        throw new InvalidArgumentException(__('Invalid :key: :value', ['key' => $key, 'value' => (string) $value]));
                    }
                    $template = $map->links[$link->template] ?? $map->links['DEFAULT'] ?? null;
                    $this->setHint($link, $template, 'traffic_total', (string) $value, '1');
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

        $note = !empty($op['note']);
        $name = $this->freeName($map->nodes, $this->name((string) ($op['name'] ?? ($note ? 'note' : 'node'))));

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

        if ($note) {
            // a map note: free text, no icon (SET editor_note 1, as PHP Weathermap 2.0 writes it)
            $node->iconfile = '';
            $node->hints['editor_note'] = '1';
            $this->setNoteText($node, isset($op['text']) ? (string) $op['text'] : __('Note'));
        }

        // on a basemap, a new node is located where it is put (a note stays on the canvas)
        $frame = \WeatherMapGeo::frame($map);
        if ($frame !== null && !$note) {
            [$lat, $lon] = \WeatherMapGeo::unproject($frame, $map->width, $map->height, $x, $y);
            $node->hints['geo'] = \WeatherMapGeo::format($lat, $lon);
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
            \WeatherMapGeo::follow($map, $node);
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

    /**
     * {op: define_font, file, size, n?} - a TrueType font for the map: one
     * shipped in public/fonts, or a file the map already uses. Without n,
     * it takes the next free number. Returns {fonts: [n]}.
     */
    private function defineFont(\WeatherMap $map, array $op): array
    {
        $file = trim((string) ($op['file'] ?? ''));
        $used = [];
        foreach ($map->fonts as $font) {
            if ($font->type === 'truetype') {
                $used[] = (string) $font->file;
            }
        }
        if (!in_array($file, $used, true) && !self::isShippedFont($file)) {
            throw new InvalidArgumentException(__('Unknown font: :file', ['file' => $file]));
        }
        $size = (int) ($op['size'] ?? 10);
        if ($size < 4 || $size > 96) {
            throw new InvalidArgumentException(__('A font size is between 4 and 96'));
        }
        $n = isset($op['n']) ? (int) $op['n'] : max(10, max(array_merge([0], array_keys($map->fonts))) + 1);
        if ($n < 6 || $n > 999) {
            throw new InvalidArgumentException(__('Invalid font number: :n', ['n' => $n]));
        }

        $font = new \WMFont();
        $font->type = 'truetype';
        $font->file = $file;
        $font->size = $size;
        $map->fonts[$n] = $font;

        return ['fonts' => [$n]];
    }

    /** The web root, where icons and shipped fonts are, relative to the map files */
    private static function publicDir(): string
    {
        return dirname(__DIR__, 3) . '/public';
    }

    /** fonts/<name>.ttf shipped with the application */
    private static function isShippedFont(string $file): bool
    {
        return (bool) preg_match('#^fonts/[A-Za-z0-9._-]+\.ttf$#', $file) && is_file(self::publicDir() . '/' . $file);
    }

    /**
     * The TrueType fonts shipped in public/fonts, for the editor:
     * [{file: fonts/<name>.ttf, label}], sorted by label.
     */
    public static function shippedFonts(): array
    {
        $out = [];
        foreach (glob(self::publicDir() . '/fonts/*.ttf') ?: [] as $path) {
            $file = 'fonts/' . basename($path);
            $out[] = ['file' => $file, 'label' => self::fontLabel($file)];
        }
        usort($out, static fn ($a, $b) => strcmp($a['label'], $b['label']));
        return $out;
    }

    /** "fonts/FiraSans-SemiBold.ttf" => "Fira Sans SemiBold" */
    public static function fontLabel(string $file): string
    {
        $name = preg_replace('/\.ttf$/i', '', basename($file));
        $name = str_replace(['-', '_'], ' ', (string) $name);
        $name = (string) preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $name);
        $name = str_replace(['Deja Vu', 'IBMPlex', ' Regular'], ['DejaVu', 'IBM Plex', ''], $name);
        return trim($name);
    }

    /** {op: set_map, width?, height?, resize_mode?: extend|scale, keep_aspect?, scale_sizes?, title?, background?, props?: {...}} */
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

        if (isset($op['props'])) {
            $this->setMapProps($map, (array) $op['props']);
        }
    }

    /** Colours of the map furniture, by request key => colour name in the engine */
    private const MAP_COLOURS = [
        'bg_colour' => 'BG', 'title_colour' => 'TITLE', 'time_colour' => 'TIME',
        'key_text_colour' => 'KEYTEXT', 'key_bg_colour' => 'KEYBG', 'key_outline_colour' => 'KEYOUTLINE',
    ];

    private const SCALE_MAX_STEPS = 32;

    /**
     * {op: set_scale, steps: [{bottom, top, colour, colour2?, tag?}]} - the
     * DEFAULT colour scale of the links, in % of the bandwidth. Replaces every
     * step; the map colours kept in the same scale (BG, TITLE...) stay.
     */
    private function setScale(\WeatherMap $map, array $steps): void
    {
        if ($steps === [] || count($steps) > self::SCALE_MAX_STEPS) {
            throw new InvalidArgumentException(__('A colour scale has 1 to :n steps', ['n' => self::SCALE_MAX_STEPS]));
        }

        $out = [];
        foreach ($steps as $step) {
            $step = (array) $step;
            $bottom = $step['bottom'] ?? null;
            $top = $step['top'] ?? null;
            if (!is_numeric($bottom) || !is_numeric($top) || $bottom < 0 || $top > 100 || $bottom > $top) {
                throw new InvalidArgumentException(__('Invalid scale step: :from to :to', ['from' => (string) $bottom, 'to' => (string) $top]));
            }
            $bottom = self::plainNumber(round((float) $bottom, 2));
            $top = self::plainNumber(round((float) $top, 2));
            $key = $bottom . '_' . $top;
            if (isset($out[$key])) {
                throw new InvalidArgumentException(__('Two scale steps go from :from to :to', ['from' => (string) $bottom, 'to' => (string) $top]));
            }

            $c = $this->colour($step['colour'] ?? '');
            if ($c[0] === -3) {
                throw new InvalidArgumentException(__('Invalid colour: :value', ['value' => (string) $step['colour']]));
            }
            $entry = [
                'key' => $key, 'bottom' => $bottom, 'top' => $top, 'special' => 0,
                'red1' => $c[0], 'green1' => $c[1], 'blue1' => $c[2],
                'tag' => $this->text($step['tag'] ?? ''),
            ];
            if (($step['colour2'] ?? '') !== '' && $c[0] >= 0) {
                $c2 = $this->colour($step['colour2']);
                if ($c2[0] < 0) {
                    throw new InvalidArgumentException(__('Invalid colour: :value', ['value' => (string) $step['colour2']]));
                }
                [$entry['red2'], $entry['green2'], $entry['blue2']] = $c2;
            }
            $out[$key] = $entry;
        }
        uasort($out, static fn (array $a, array $b) => [$a['bottom'], $a['top']] <=> [$b['bottom'], $b['top']]);

        $special = array_filter((array) ($map->colours['DEFAULT'] ?? []), static fn ($c) => !empty($c['special']));
        $map->colours['DEFAULT'] = $out + $special;
        $map->numscales['DEFAULT'] = count($out);
        // an idle (0 to 0) step colours links without traffic; the legend does not list it
        if (isset($out['0_0'])) {
            $map->add_hint('key_hidezero_DEFAULT', 1);
        }
    }

    /** 10.0 as 10, 2.5 as it is */
    private static function plainNumber(mixed $v): int|float
    {
        $f = (float) $v;
        return $f == floor($f) && abs($f) < PHP_INT_MAX ? (int) $f : $f;
    }

    /** @return list<array{bottom: float|int, top: float|int, colour: string, colour2: string, tag: string}> */
    private static function scaleOut(\WeatherMap $map): array
    {
        $out = [];
        foreach ((array) ($map->colours['DEFAULT'] ?? []) as $c) {
            if (!empty($c['special'])) {
                continue;
            }
            $out[] = [
                'bottom' => self::plainNumber($c['bottom']),
                'top' => self::plainNumber($c['top']),
                'colour' => self::colourOut([$c['red1'], $c['green1'], $c['blue1']]),
                'colour2' => isset($c['red2']) ? self::colourOut([$c['red2'], $c['green2'], $c['blue2']]) : '',
                'tag' => (string) ($c['tag'] ?? ''),
            ];
        }
        usort($out, static fn (array $a, array $b) => [$a['bottom'], $a['top']] <=> [$b['bottom'], $b['top']]);
        return $out;
    }

    private const KEY_STYLES = ['classic', 'horizontal', 'vertical', 'inverted', 'tags', 'modern'];

    /** SET basemap...: the geographic basemap; an empty value removes the hint */
    private function setBasemapHint(\WeatherMap $map, string $key, mixed $value): void
    {
        $text = trim($this->text($value ?? ''));
        if ($text === '') {
            unset($map->hints[$key]);
            return;
        }
        if ($key === 'basemap' || $key === 'basemap_dark') {
            if (!Basemap::allowed($text)) {
                throw new InvalidArgumentException(__('The basemap must come from this site or from a tile server of BASEMAP_SERVERS: :value', ['value' => $text]));
            }
        } elseif ($key === 'basemap_center') {
            $p = \WeatherMapGeo::latLon($text);
            if ($p === null) {
                throw new InvalidArgumentException(__('Invalid latitude and longitude: :value', ['value' => $text]));
            }
            $text = \WeatherMapGeo::format($p[0], $p[1]);
        } elseif ($key === 'basemap_zoom') {
            if (!is_numeric($text) || (float) $text < 0 || (float) $text > 22) {
                throw new InvalidArgumentException(__('Invalid :key: :value', ['key' => $key, 'value' => $text]));
            }
            $text = rtrim(rtrim(sprintf('%.3F', (float) $text), '0'), '.');
        }
        $map->hints[$key] = $text;
    }

    /** SET geo on a node: where it sits on the basemap; empty frees it where it is */
    private function setGeo(\WeatherMap $map, \WeatherMapNode $node, mixed $value): void
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            unset($node->hints['geo']);
            return;
        }
        $p = \WeatherMapGeo::latLon($text);
        if ($p === null) {
            throw new InvalidArgumentException(__('Invalid latitude and longitude: :value', ['value' => $text]));
        }
        $node->hints['geo'] = \WeatherMapGeo::format($p[0], $p[1]);
    }

    /**
     * The Map properties dialog: texts, fonts and colours of the title,
     * legend and timestamp, and the defaults of the links. A new link
     * default also applies to the links that had the old one.
     */
    private function setMapProps(\WeatherMap $map, array $props): void
    {
        $fonts = array_merge([1, 2, 3, 4, 5], array_keys($map->fonts));

        foreach ($props as $key => $value) {
            if (isset(self::MAP_COLOURS[$key])) {
                $c = $this->colour($value);
                if ($c[0] === -3 || ($c[0] === -1 && !in_array($key, ['key_bg_colour', 'key_outline_colour'], true))) {
                    throw new InvalidArgumentException(__('Invalid colour: :value', ['value' => $value]));
                }
                $ref = &$map->colours['DEFAULT'][self::MAP_COLOURS[$key]];
                $ref['red1'] = $c[0];
                $ref['green1'] = $c[1];
                $ref['blue1'] = $c[2];
                $ref['bottom'] = -2;
                $ref['top'] = -1;
                $ref['special'] = 1;
                unset($ref);
            } elseif (in_array($key, Basemap::HINTS, true)) {
                $this->setBasemapHint($map, $key, $value);
            } elseif ($key === 'title_font' || $key === 'time_font' || $key === 'key_font') {
                if (!in_array((int) $value, $fonts, true)) {
                    throw new InvalidArgumentException(__('Invalid font: :value', ['value' => $value]));
                }
                $field = ['title_font' => 'titlefont', 'time_font' => 'timefont', 'key_font' => 'keyfont'][$key];
                $map->$field = (int) $value;
            } elseif ($key === 'key_text') {
                $map->keytext['DEFAULT'] = $this->text($value);
            } elseif ($key === 'key_style') {
                if (!in_array($value, self::KEY_STYLES, true)) {
                    throw new InvalidArgumentException(__('Invalid :key: :value', ['key' => $key, 'value' => $value]));
                }
                $map->keystyle['DEFAULT'] = $value;
            } elseif ($key === 'key_size') {
                $map->keysize['DEFAULT'] = max(50, min(2000, (int) $value));
            } elseif ($key === 'key_shown') {
                [$map->keyx['DEFAULT'], $map->keyy['DEFAULT']] = self::legendPosition(
                    (bool) $value,
                    $map->keyx['DEFAULT'] ?? -1,
                    $map->keyy['DEFAULT'] ?? -1
                );
                $map->keytext['DEFAULT'] ??= 'Traffic Load';
            } elseif ($key === 'stamp_shown') {
                $this->setFurnitureShown($map, 'timestamp:', (bool) $value);
            } elseif ($key === 'width_legend_shown') {
                $this->setFurnitureShown($map, 'widthlegend:', (bool) $value);
            } elseif ($key === 'width_legend_title') {
                // drawn as "Link speed" when not set
                $title = $this->text($value);
                if ($title === '' || $title === 'Link speed') {
                    unset($map->hints['width_legend_title']);
                } else {
                    $map->add_hint('width_legend_title', $title);
                }
            } elseif ($key === 'stamp_text') {
                $map->stamptext = $this->text($value);
            } elseif ($key === 'stamp_style') {
                if (!in_array($value, ['plain', 'pill'], true)) {
                    throw new InvalidArgumentException(__('Invalid :key: :value', ['key' => $key, 'value' => $value]));
                }
                if ($value === 'pill') {
                    $map->add_hint('timestamp_style', 'pill');
                } else {
                    unset($map->hints['timestamp_style']);
                }
            } elseif ($key === 'traffic_pulse') {
                // the PHP Weathermap 2.0 hint: links at 80 % or more pulse in the viewer
                if ($value) {
                    $map->add_hint('traffic_pulse', '1');
                } else {
                    unset($map->hints['traffic_pulse']);
                }
            } elseif ($key === 'link_glow') {
                // a soft glow in the colour of each link, in the SVG only
                if ($value) {
                    $map->add_hint('link_glow', '1');
                } else {
                    unset($map->hints['link_glow']);
                }
            } elseif ($key === 'link_flow') {
                // dots moving along the links in the viewer, in the SVG only
                if ($value) {
                    $map->add_hint('link_flow', '1');
                } else {
                    unset($map->hints['link_flow']);
                }
            } elseif ($key === 'background_sizing') {
                // the PHP Weathermap 2.0 hint: natural size (image), fit or stretch
                if (!in_array($value, \WeatherMap::BACKGROUND_SIZINGS, true)) {
                    throw new InvalidArgumentException(__('Invalid :key: :value', ['key' => $key, 'value' => $value]));
                }
                if ($value === 'image') {
                    unset($map->hints['background_sizing']);
                } else {
                    $map->add_hint('background_sizing', $value);
                }
            } elseif (in_array($key, ['link_bandwidth', 'link_width', 'link_labelstyle', 'link_bwfont', 'link_bwstyle', 'link_arrowstyle'], true)) {
                $this->setLinkDefault($map, $key, $value, $fonts);
            } else {
                throw new InvalidArgumentException(__('Unknown map property: :key', ['key' => $key]));
            }
        }
    }

    /**
     * {op: apply_theme, theme} - a ready-made look (MapThemes): the map and
     * legend colours, the colour scale, and the label colours of the
     * DEFAULT node and link, which the nodes and links that had the old
     * default value follow. One batch, so one undo step.
     */
    private function applyTheme(\WeatherMap $map, string $name): void
    {
        $theme = MapThemes::get($name);
        if ($theme === null) {
            throw new InvalidArgumentException(__('Unknown theme: :name', ['name' => $name]));
        }

        $this->setMapProps($map, $theme['map']);
        $this->setScale($map, $theme['scale']);
        foreach (['links' => $theme['link'], 'nodes' => $theme['node']] as $kind => $fields) {
            if (!isset($map->{$kind}['DEFAULT'])) {
                continue;
            }
            foreach ($fields as $field => $value) {
                $this->setTemplateDefault($map, $kind, $field, $this->colour($value));
            }
        }
    }

    /**
     * Give DEFAULT a new value for $field, and to every node or link
     * ($kind) that had the old one from it.
     */
    private function setTemplateDefault(\WeatherMap $map, string $kind, string $field, mixed $new): void
    {
        $items = $map->$kind;
        $old = $items['DEFAULT']->$field;
        $followers = [];
        foreach ($items as $name => $item) {
            if ($name === 'DEFAULT' || str_starts_with((string) $name, ':: DEFAULT')) {
                continue;
            }
            // the template chain up to DEFAULT has the old value all along
            $ok = $item->$field == $old;
            $seen = [];
            $cur = $item;
            while ($ok && ($template = (string) $cur->template) !== 'DEFAULT') {
                $parent = $items[$template] ?? null;
                if ($parent === null || isset($seen[$template]) || $parent->$field != $old) {
                    $ok = false;
                    break;
                }
                $seen[$template] = true;
                $cur = $parent;
            }
            if ($ok) {
                $followers[] = $item;
            }
        }
        foreach ($followers as $item) {
            $item->$field = $new;
        }
        $items['DEFAULT']->$field = $new;
    }

    /**
     * The engine hides a legend placed at a negative position. A hidden
     * legend keeps its place as (-x - 1, -y - 1), so showing it again puts
     * it back where it was; the classic -1 -1 comes back at 10, 40.
     *
     * @return array{int, int}
     */
    private static function legendPosition(bool $shown, mixed $x, mixed $y): array
    {
        $x = is_numeric($x) ? (int) $x : -1;
        $y = is_numeric($y) ? (int) $y : -1;
        $hidden = $x < 0 || $y < 0;

        if ($shown) {
            if (!$hidden) {
                return [$x, $y];
            }
            if ($x === -1 && $y === -1) {
                return [10, 40];
            }
            return [$x < 0 ? -$x - 1 : $x, $y < 0 ? -$y - 1 : $y];
        }

        return $hidden ? [$x, $y] : [-$x - 1, -$y - 1];
    }

    private function setLinkDefault(\WeatherMap $map, string $key, mixed $value, array $fonts): void
    {
        $default = $map->links['DEFAULT'] ?? null;
        if (!$default) {
            throw new InvalidArgumentException(__('This map has no DEFAULT link'));
        }

        $fields = match ($key) {
            'link_bandwidth' => ['max_bandwidth_in_cfg', 'max_bandwidth_out_cfg'],
            'link_width' => ['width'],
            'link_labelstyle' => ['labelstyle'],
            'link_bwfont' => ['bwfont'],
            'link_bwstyle' => ['labelboxstyle'],
            'link_arrowstyle' => ['arrowstyle'],
        };
        $new = match ($key) {
            'link_bandwidth' => $this->bandwidth((string) $value),
            'link_width' => strtolower((string) $value) === 'auto' ? 'auto' : max(1, (int) $value),
            'link_labelstyle' => in_array($value, self::LINK_CHOICES['labelstyle'], true) ? $value
                : throw new InvalidArgumentException(__('Invalid :key: :value', ['key' => $key, 'value' => $value])),
            'link_bwfont' => in_array((int) $value, $fonts, true) ? (int) $value
                : throw new InvalidArgumentException(__('Invalid font: :value', ['value' => $value])),
            'link_bwstyle' => in_array($value, self::LINK_CHOICES['bwstyle'], true) ? $value
                : throw new InvalidArgumentException(__('Invalid :key: :value', ['key' => $key, 'value' => $value])),
            'link_arrowstyle' => in_array($value, self::LINK_CHOICES['arrowstyle'], true) ? $value
                : throw new InvalidArgumentException(__('Invalid :key: :value', ['key' => $key, 'value' => $value])),
        };

        foreach ($fields as $field) {
            // find all the followers first: changing one would hide its children
            $followers = array_filter(
                $map->links,
                static fn ($link, $name) => $name !== 'DEFAULT' && $name !== ':: DEFAULT ::' && self::inheritsFromDefault($map, $link, $field),
                ARRAY_FILTER_USE_BOTH
            );
            foreach ($followers as $link) {
                $link->$field = $new;
                if ($field === 'labelstyle') {
                    self::labelFormats($link, (string) $new);
                }
            }
            $default->$field = $new;
            if ($field === 'labelstyle') {
                self::labelFormats($default, (string) $new);
            }
        }
    }

    /**
     * Whether a link (or a link template) has $field from DEFAULT: each
     * template between them has the same value as DEFAULT, so none of them
     * sets its own.
     */
    private static function inheritsFromDefault(\WeatherMap $map, object $link, string $field): bool
    {
        $value = (string) $link->$field;
        $seen = [];
        while (($template = (string) $link->template) !== 'DEFAULT') {
            $parent = $map->links[$template] ?? null;
            if ($parent === null || isset($seen[$template]) || (string) $parent->$field !== $value) {
                return false;
            }
            $seen[$template] = true;
            $link = $parent;
        }
        return $value === (string) $map->links['DEFAULT']->$field;
    }

    /** The label texts BWLABEL $style stands for, as the config reader sets them */
    private static function labelFormats(object $link, string $style): void
    {
        [$in, $out] = match ($style) {
            'percent' => [FMT_PERC_IN, FMT_PERC_OUT],
            'bits' => [FMT_BITS_IN, FMT_BITS_OUT],
            'unformatted' => [FMT_UNFORM_IN, FMT_UNFORM_OUT],
            default => ['', ''],
        };
        $link->bwlabelformats[IN] = $in;
        $link->bwlabelformats[OUT] = $out;
    }

    /** BWSTYLE of a link: classic, angled or pill */
    private static function bwStyle(object $link): string
    {
        $v = strtolower((string) $link->labelboxstyle);
        return in_array($v, self::LINK_CHOICES['bwstyle'], true) ? $v : 'classic';
    }

    /** SET background_sizing as the editor shows it: image when unset or unknown */
    private static function backgroundSizing(\WeatherMap $map): string
    {
        $v = strtolower(trim((string) $map->get_hint('background_sizing')));
        return in_array($v, \WeatherMap::BACKGROUND_SIZINGS, true) ? $v : 'image';
    }

    /** What the Map properties dialog shows */
    private static function mapProps(\WeatherMap $map): array
    {
        $colour = static function (string $name) use ($map): string {
            $c = $map->colours['DEFAULT'][$name] ?? null;
            if (!$c || $c['red1'] < 0) {
                return 'none';
            }
            return sprintf('#%02x%02x%02x', $c['red1'], $c['green1'], $c['blue1']);
        };
        $default = $map->links['DEFAULT'] ?? null;

        return [
            'title_font' => (int) $map->titlefont,
            'title_colour' => $colour('TITLE'),
            'bg_colour' => $colour('BG'),
            'key_shown' => ($map->keyx['DEFAULT'] ?? -1) >= 0 && ($map->keyy['DEFAULT'] ?? -1) >= 0,
            'key_text' => (string) ($map->keytext['DEFAULT'] ?? ''),
            'key_style' => (string) ($map->keystyle['DEFAULT'] ?? 'classic'),
            'key_size' => (int) ($map->keysize['DEFAULT'] ?? 400),
            'key_font' => (int) $map->keyfont,
            'key_text_colour' => $colour('KEYTEXT'),
            'key_bg_colour' => $colour('KEYBG'),
            'key_outline_colour' => $colour('KEYOUTLINE'),
            'stamp_shown' => (int) $map->timex >= 0 && (int) $map->timey >= 0,
            'stamp_text' => (string) $map->stamptext,
            'width_legend_shown' => self::widthLegendShown($map),
            'width_legend_title' => (string) ($map->get_hint('width_legend_title') ?: 'Link speed'),
            'stamp_style' => $map->get_hint('timestamp_style') === 'pill' ? 'pill' : 'plain',
            'traffic_pulse' => (string) $map->get_hint('traffic_pulse') === '1',
            'link_glow' => (string) $map->get_hint('link_glow') === '1',
            'link_flow' => (string) $map->get_hint('link_flow') === '1',
            'background_sizing' => self::backgroundSizing($map),
            'time_font' => (int) $map->timefont,
            'time_colour' => $colour('TIME'),
            'link_bandwidth' => $default ? (string) $default->max_bandwidth_in_cfg : '',
            'link_width' => $default ? (string) $default->width : '',
            'link_labelstyle' => $default ? (string) $default->labelstyle : 'percent',
            'link_bwfont' => $default ? (int) $default->bwfont : 2,
            'link_bwstyle' => $default ? self::bwStyle($default) : 'classic',
            'link_arrowstyle' => $default ? (string) $default->arrowstyle : 'classic',
            'basemap' => (string) ($map->hints['basemap'] ?? ''),
            'basemap_dark' => (string) ($map->hints['basemap_dark'] ?? ''),
            'basemap_center' => (string) ($map->hints['basemap_center'] ?? ''),
            'basemap_zoom' => (string) ($map->hints['basemap_zoom'] ?? ''),
            'basemap_attribution' => (string) ($map->hints['basemap_attribution'] ?? ''),
        ];
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

            $hasIcon = !in_array((string) $node->iconfile, ['', 'none'], true);
            $isNote = (string) ($node->hints['editor_note'] ?? '') === '1';
            $noteText = (string) ($node->hints['editor_note_escaped'] ?? '') === '1'
                ? \WeatherMapNode::note_decode((string) $node->label)
                : (string) $node->label;

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
                // the preset the label sits at, '' when none or moved by hand
                'label_position' => self::labelPresetOf($node),
                'style' => $node->is_badge() ? 'badge' : 'box',
                'site' => \App\Services\MapService::siteOf($node),
                // its point on the basemap, [lat, lon], or null
                'geo' => \WeatherMapGeo::nodePoint($node),
                'icon' => (string) $node->iconfile,
                'iconscalew' => (int) $node->iconscalew,
                'iconscaleh' => (int) $node->iconscaleh,
                // the icon is the first box (an icon that failed to load has none)
                'icon_box' => $hasIcon && (count($node->boundingboxes) >= 2 || ((string) $node->label === '' && $node->boundingboxes !== []))
                    ? array_map(static fn ($v) => (int) round((float) $v), reset($node->boundingboxes))
                    : null,
                'template' => (string) $node->template,
                'relative_to' => (string) $node->relative_to,
                'zorder' => (int) $node->zorder,
                'labelfont' => (int) $node->labelfont,
                'labelbgcolour' => self::colourOut($node->labelbgcolour),
                'labelfontcolour' => self::colourOut($node->labelfontcolour),
                'labeloutlinecolour' => self::colourOut($node->labeloutlinecolour),
                'target' => self::targetsOut($node->targets),
                'infourl' => (string) ($node->infourl[IN] ?? ''),
                'hover_url' => implode(' ', (array) ($node->overliburl[IN] ?? [])),
                'group' => (string) ($node->hints['editor_group'] ?? ''),
                'locked' => !empty($node->hints['editor_lock']),
                'layer' => (string) ($node->hints['editor_layer'] ?? ''),
                'note' => $isNote,
                'note_text' => $isNote ? $noteText : '',
                'note_align' => $isNote ? $node->note_align() : '',
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
                'infourl' => (string) ($link->infourl[IN] ?? ''),
                'hover_url' => implode(' ', (array) ($link->overliburl[IN] ?? [])),
                'commentcurve' => (string) $link->commentcurve === '1' ? '1' : '0',
                'bwstyle' => self::bwStyle($link),
                'labelstyle' => (string) $link->labelstyle,
                'arrowstyle' => (string) $link->arrowstyle,
                'labelshift_in' => (int) round((float) ($link->hints['bwlabel_offset_in'] ?? 0)),
                'labelshift_out' => (int) round((float) ($link->hints['bwlabel_offset_out'] ?? 0)),
                'traffic_total' => \App\Services\MapService::countsInTotal($link) ? '1' : '0',
                'zorder' => (int) $link->zorder,
                'group' => (string) ($link->hints['editor_group'] ?? ''),
                'locked' => !empty($link->hints['editor_lock']),
                'layer' => (string) ($link->hints['editor_layer'] ?? ''),
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
                'layer' => (string) ($zone->hints['editor_layer'] ?? ''),
            ];
        }

        return [
            'map' => [
                'width' => (int) $map->width,
                'height' => (int) $map->height,
                'title' => (string) $map->title,
                'background' => (string) $map->background,
                'props' => self::mapProps($map),
                'scale' => self::scaleOut($map),
                // the basemap the editor draws under the canvas (as the viewer does), or null
                'basemap' => Basemap::of($map),
                'basemap_servers' => Basemap::servers(),
            ],
            'nodes' => $nodes,
            'links' => $links,
            'zones' => $zones,
            'furniture' => self::furnitureOut($map),
            'templates' => $templates,
            'fonts' => array_values(array_unique(array_merge([1, 2, 3, 4, 5], array_keys($map->fonts)))),
            // what each defined font is: family (from the file name) and size
            // (the built-in GD fonts have no file and are left out)
            'font_info' => array_map(static fn ($f) => $f->type === 'truetype'
                ? ['label' => self::fontLabel((string) $f->file), 'size' => (int) $f->size, 'file' => (string) $f->file]
                : ['label' => basename((string) $f->file), 'size' => 0, 'file' => (string) $f->file],
                array_filter($map->fonts, static fn ($f) => (string) $f->file !== '')),
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

    /**
     * The pictures shown on hover (OVERLIBGRAPH): addresses separated by
     * spaces, each one a web address or a local path.
     *
     * @return list<string>
     */
    private function urls(string $value): array
    {
        $urls = preg_split('/\s+/', $this->text($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($urls as $url) {
            if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) && !preg_match('#^https?://#i', $url)) {
                throw new InvalidArgumentException(__('Invalid address: :value', ['value' => $url]));
            }
        }
        return array_values($urls);
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

    /** The label preset a node uses, '' when none or when the label was moved by hand */
    private static function labelPresetOf(\WeatherMapNode $node): string
    {
        $preset = \WeatherMapNode::label_preset((string) ($node->hints['editor_label_position'] ?? ''));
        if ((string) $node->labeloffset !== '') {
            $preset = \WeatherMapNode::label_preset((string) $node->labeloffset);
        }
        if ((float) $node->labeloffsetx != 0 || (float) $node->labeloffsety != 0) {
            return '';
        }

        return in_array($preset, self::LABEL_PRESETS, true) ? $preset : '';
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
        if (in_array($value, self::ICON_SHAPES, true)) {
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
