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
use App\Services\FocusAreas;
use App\Services\ViewerPanels;

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
        'commentfont' => 'commentfont',
    ];

    private const LINK_CHOICES = [
        'viastyle' => ['curved', 'angled'],
        'linkstyle' => ['twoway', 'oneway'],
        'labelstyle' => ['percent', 'bits', 'unformatted', 'none'],
        'commentcurve' => ['0', '1'],
        'bwstyle' => ['classic', 'angled', 'pill'],
        'arrowstyle' => ['classic', 'compact', 'flat'],
    ];

    /** The traffic bar settings: editor key => hint (per link; the map-wide one is link_<key>) */
    private const BAR_HINTS = [
        'look' => 'link_look',
        'bar_separator' => 'link_bar_separator',
        'bar_outline' => 'link_bar_outline',
    ];

    /** What each traffic bar hint is without a value */
    private const BAR_DEFAULTS = [
        'link_look' => 'classic',
        'link_bar_separator' => '#ffffff',
        'link_bar_outline' => 'none',
    ];

    /** Largest custom arrow head factor (ARROWSTYLE <length> <width>) */
    private const ARROW_FACTOR_MAX = 20;

    /** How far a bandwidth label may be moved across its link, in pixels */
    private const LABEL_SHIFT_MAX = 60;

    /** Zone fields the inspector may change: request key => [property, type] */
    private const ZONE_FIELDS = [
        'label' => ['label', 'text'],
        'x' => ['x', 'int'],
        'y' => ['y', 'int'],
        'width' => ['width', 'size'],
        'height' => ['height', 'size'],
        'labelfont' => ['labelfont', 'font'],
        'opacity' => ['opacity', 'percent'],
        'outlinewidth' => ['outlinewidth', 'outline'],
        'fillcolour' => ['fillcolour', 'colour'],
        'outlinecolour' => ['outlinecolour', 'colour'],
        'labelfontcolour' => ['labelfontcolour', 'colour'],
        'radius' => ['radius', 'radius'],
    ];

    private const ZONE_CHOICES = [
        'labelpos' => \WeatherMapZone::LABEL_POSITIONS,
        'outlinestyle' => ['solid', 'dashed'],
        'shape' => \WeatherMapZone::SHAPES,
    ];

    private const NOTE_ALIGNS = ['left', 'centre', 'right'];

    /**
     * The fonts GD draws itself, 1 to 5 (the engine draws them even when the
     * map has a FONTDEFINE with their number): the TrueType size and weight
     * that stand in for each when a map font replaces them, n => [size, bold]
     */
    private const BUILTIN_FONTS = [1 => [7, false], 2 => [8, false], 3 => [8, true], 4 => [10, false], 5 => [10, true]];

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
    /** Where a title shown again (or of a new map) goes: TITLEPOS, the left end of its baseline */
    public const TITLE_PLACE = [10, 28];

    /** Where the legend card of a new map, or of an older map that had none, goes */
    public const LEGEND_PLACE = [10, 40];

    private const FURNITURE_AREAS = [
        'TIMESTAMP' => ['timestamp', ''],
        'MINTIMESTAMP' => ['timestamp', 'MIN'],
        'MAXTIMESTAMP' => ['timestamp', 'MAX'],
        'TITLE' => ['title', ''],
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
     * Turns the older map items of the map into cards, as the editor opens
     * it (one history step, "Converted legacy map items"); true when the
     * .conf changed. See convertLegacyItems().
     */
    public function convertLegacy(): bool
    {
        if (!is_file($this->file)) {
            return false;
        }
        $map = $this->load();
        if (!self::convertLegacyItems($map)) {
            return false;
        }
        $this->save($map);
        return true;
    }

    /**
     * The older keywords of a map as map item cards (SET box_<id>):
     *
     * - each legend placed by KEYPOS becomes a legend card at the same place,
     *   with its text, its style (KEYSTYLE horizontal and modern: the bar of
     *   their length, tags: the list with the step tags, the others: the
     *   list) and its scale; a hidden one (negative position) is dropped;
     * - SET width_legend becomes a link speed card with its title;
     * - the legend panel of the viewer is gone (F56): a map whose viewer
     *   showed it, and that has no card at all, gets a legend card, and the
     *   viewer and kiosk hints no longer name a shown legend panel.
     *
     * The engine still draws the older keywords of maps never opened in the
     * editor. True when the map changed.
     */
    public static function convertLegacyItems(\WeatherMap $map): bool
    {
        if (!is_array($map->hints)) {
            $map->hints = [];
        }
        $changed = false;
        $taken = array_map('strval', array_keys(\WeatherMapBoxes::all($map)));
        $hadCards = $taken !== [];
        $nextId = static function () use ($map, &$taken): string {
            $id = 1;
            while (in_array((string) $id, $taken, true) || $map->get_hint('box_' . $id) !== null) {
                $id++;
            }
            $taken[] = (string) $id;
            return (string) $id;
        };
        $defaults = $map->inherit_fieldlist;

        // KEYPOS / KEYSTYLE: one legend card per shown legend
        foreach (array_unique(array_merge(array_keys((array) $map->keyx), array_keys((array) $map->keystyle))) as $scale) {
            $scale = (string) $scale;
            $x = $map->keyx[$scale] ?? -1;
            $y = $map->keyy[$scale] ?? -1;
            $x = is_numeric($x) ? (int) $x : -1;
            $y = is_numeric($y) ? (int) $y : -1;
            $style = strtolower((string) ($map->keystyle[$scale] ?? 'classic'));
            $text = trim((string) ($map->keytext[$scale] ?? ''));
            $size = (int) ($map->keysize[$scale] ?? 400);
            $isDefault = $x === -1 && $y === -1
                && ($text === '' || $text === $defaults['keytext']['DEFAULT'])
                && $style === $defaults['keystyle']['DEFAULT']
                && $size === (int) $defaults['keysize']['DEFAULT'];
            if (!$isDefault) {
                $changed = true;
            }
            if ($x >= 0 && $y >= 0 && isset($map->colours[$scale])) {
                $id = $nextId();
                $bar = in_array($style, ['horizontal', 'modern'], true);
                $map->add_hint('box_' . $id, 'legend ' . $x . ' ' . $y);
                $map->add_hint('box_' . $id . '_title', $text === '' ? '-' : $text);
                if (!$bar) {
                    $map->add_hint('box_' . $id . '_style', $style === 'tags' ? 'tags' : 'list');
                } else {
                    $map->add_hint('box_' . $id . '_width', (string) max(80, min(2000, $size)));
                }
                if ($scale !== 'DEFAULT') {
                    $map->add_hint('box_' . $id . '_scale', $scale);
                }
            }
        }
        foreach (['keyx', 'keyy', 'keytext', 'keystyle', 'keysize'] as $field) {
            $map->$field = [];
        }

        // SET width_legend: a link speed card
        $where = $map->get_hint('width_legend');
        $title = trim((string) $map->get_hint('width_legend_title'));
        if ($where !== null || $map->get_hint('width_legend_title') !== null) {
            $changed = true;
            if (preg_match('/^(-?\d+)[\s,]+(-?\d+)$/', trim((string) $where), $m)) {
                $id = $nextId();
                $map->add_hint('box_' . $id, 'speed ' . (int) $m[1] . ' ' . (int) $m[2]);
                if ($title !== '' && $title !== 'Link speed') {
                    $map->add_hint('box_' . $id . '_title', $title);
                }
            }
            unset($map->hints['width_legend'], $map->hints['width_legend_title']);
        }

        // the legend panel of the viewer (F56): a card takes its place on a
        // map from before the cards (none at all: a map that has cards and
        // no legend had it removed in the editor)
        $hasLegend = in_array('legend', array_column(\WeatherMapBoxes::all($map), 'type'), true);
        if (!$hadCards && !$hasLegend && ViewerPanels::showedLegend($map) && \WeatherMapBoxes::scaleBands($map) !== []) {
            $map->add_hint('box_' . $nextId(), 'legend ' . self::LEGEND_PLACE[0] . ' ' . self::LEGEND_PLACE[1]);
            $changed = true;
        }
        if (ViewerPanels::dropLegend($map)) {
            $changed = true;
        }

        return $changed;
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
                'resize_canvas' => $this->resizeCanvas($map, $op),
                'paste' => $this->paste($map, $op),
                'add_zone' => $this->addZone($map, $op),
                'set_zone' => $this->setZones($map, $op),
                'delete_zones' => $this->deleteZones($map, (array) ($op['names'] ?? [])),
                'add_box' => $this->addBox($map, $op),
                'set_box' => $this->setBox($map, (string) ($op['name'] ?? ''), (array) ($op['props'] ?? [])),
                'text_style' => $this->textStyle($map, $op),
                'add_focus' => $this->addFocus($map, $op),
                'set_focus' => $this->setFocus($map, (string) ($op['name'] ?? ''), (array) ($op['props'] ?? [])),
                'delete_focus' => $this->deleteFocus($map, (string) ($op['name'] ?? '')),
                'define_font' => $this->defineFont($map, $op),
                'set_map_font' => $this->setMapFont($map, (string) ($op['family'] ?? '')),
                'set_scale' => $this->setScale($map, (array) ($op['steps'] ?? [])),
                'apply_theme' => $this->applyTheme($map, (string) ($op['theme'] ?? '')),
                default => throw new InvalidArgumentException(__('Unknown operation: :op', ['op' => $op['op']])),
            };

            if (is_array($created)) {
                $result['created'] = array_merge_recursive($result['created'], $created);
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
     *  furniture: {"box:<id>" | "timestamp:" | "timestamp:MIN" | "title:": [x, y]}}
     * Positions are absolute map pixels; for furniture, the position its
     * keyword takes (SET box_<id>, TIMEPOS, TITLEPOS), as in model().
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

    /** Place a map item card, a timestamp or the title */
    private function moveFurniture(\WeatherMap $map, string $key, array $pos): void
    {
        [$kind, $name] = array_pad(explode(':', $key, 2), 2, '');
        // negative positions mean "hidden" or "from the far edge" for these keywords
        [$x, $y] = [max(0, $pos[0]), max(0, $pos[1])];

        switch ($kind) {
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

            case 'box':
                $box = $this->box($map, $name);
                $map->add_hint('box_' . $name, $box['type'] . ' ' . $x . ' ' . $y);
                return;
        }

        throw new InvalidArgumentException(__('Unknown map item: :name', ['name' => $key]));
    }

    /**
     * {op: show_furniture, keys: [...], at?: [x, y]} - put timestamps or
     * the title back on the map: at `at` (the position
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
     * map items: timestamps and the title stay in the .conf, hidden (a
     * negative position); a card is removed.
     */
    private function hideFurniture(\WeatherMap $map, array $keys): void
    {
        foreach ($keys as $key) {
            $this->setFurnitureShown($map, (string) $key, false);
        }
    }

    /**
     * Show or hide a piece of furniture by its key ("box:<id>", "title:",
     * "timestamp:", "timestamp:MIN", "timestamp:MAX"). The title and
     * timestamps keep their place while hidden as (-x - 1,
     * -y - 1), so showing them puts them back where they were.
     */
    private function setFurnitureShown(\WeatherMap $map, string $key, bool $shown): void
    {
        [$kind, $name] = array_pad(explode(':', $key, 2), 2, '');

        switch ($kind) {
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

            case 'box':
                // a box is deleted (SET box_<id>_hidden 1 hides one and keeps it: set_box hidden);
                // showing it draws a hidden one again
                $this->box($map, $name);
                if ($shown) {
                    unset($map->hints['box_' . $name . '_hidden']);
                    return;
                }
                foreach (array_keys($map->hints) as $hint) {
                    if ($hint === 'box_' . $name || str_starts_with((string) $hint, 'box_' . $name . '_')) {
                        unset($map->hints[$hint]);
                    }
                }
                return;

            case 'title':
                // hidden as (-x - 1, -y - 1), so it comes back where it was;
                // TITLEPOS -1 -1 (never placed) comes back top left
                [$x, $y] = [(int) $map->titlex, (int) $map->titley];
                $hidden = $x < 0 || $y < 0;
                if ($shown && $hidden) {
                    [$x, $y] = $x === -1 && $y === -1 ? self::TITLE_PLACE : [$x < 0 ? -$x - 1 : $x, $y < 0 ? -$y - 1 : $y];
                } elseif (!$shown && !$hidden) {
                    [$x, $y] = [-$x - 1, -$y - 1];
                }
                [$map->titlex, $map->titley] = [$x, $y];
                return;
        }

        throw new InvalidArgumentException(__('Unknown map item: :name', ['name' => $key]));
    }

    /**
     * {op: add_box, type, at: [x, y], title?, style?, text?, rows?, width?, note?} - a box
     * on the map (WeatherMapBoxes): total, legend, speed, updated or text.
     * The title is written as given, so a box keeps the language of the
     * editor that added it.
     */
    private function addBox(\WeatherMap $map, array $op): array
    {
        $type = (string) ($op['type'] ?? '');
        if (!in_array($type, \WeatherMapBoxes::TYPES, true)) {
            throw new InvalidArgumentException(__('Unknown box type: :type', ['type' => $type]));
        }
        [$x, $y] = $this->point($op['at'] ?? [10, 10]);
        $taken = \WeatherMapBoxes::all($map);
        $id = 1;
        while (isset($taken[(string) $id]) || $map->get_hint('box_' . $id) !== null) {
            $id++;
        }
        $map->add_hint('box_' . $id, $type . ' ' . max(0, $x) . ' ' . max(0, $y));
        $this->setBox($map, (string) $id, array_intersect_key($op, array_flip(['title', 'style', 'text', 'rows', 'width', 'note'])));

        return ['furniture' => ['box:' . $id]];
    }

    /**
     * {op: text_style, node?: <note>, box?: <id>, style: plain|card, at?: [x, y]}
     * - the two styles of a Text element (F54): "plain" is a map note
     * (a node with SET editor_note 1), "card" a text box (SET box_<id> text).
     * Switching keeps the text: a note becomes a card with its lines; a card
     * becomes a note with its title, its lines and its small note line (rows
     * taken from the sites stay with cards). at is where the new item goes:
     * the top left corner of a card, the middle of a note (the editor sends
     * the place of the drawn item); without it, the place of the old one.
     * Returns the new item, {furniture: [box:<id>]} or {nodes: [<name>]}.
     */
    private function textStyle(\WeatherMap $map, array $op): array
    {
        $style = (string) ($op['style'] ?? '');
        if (!in_array($style, ['plain', 'card'], true)) {
            throw new InvalidArgumentException(__('Unknown text style: :style', ['style' => $style]));
        }
        $at = isset($op['at']) ? $this->point($op['at']) : null;

        if (isset($op['node'])) {
            $name = (string) $op['node'];
            $node = $map->nodes[$name] ?? null;
            if ($node === null || (string) ($node->hints['editor_note'] ?? '') !== '1') {
                throw new InvalidArgumentException(__('Unknown note: :name', ['name' => $name]));
            }
            if ($style === 'plain') {
                return ['nodes' => [$name]];
            }
            $text = (string) ($node->hints['editor_note_escaped'] ?? '') === '1'
                ? \WeatherMapNode::note_decode((string) $node->label)
                : (string) $node->label;
            [$x, $y] = $at ?? [(int) round((float) $node->x), (int) round((float) $node->y)];
            $this->delete($map, [$name], []);
            return $this->addBox($map, ['type' => 'text', 'at' => [$x, $y], 'title' => '', 'text' => $text]);
        }

        $id = (string) ($op['box'] ?? '');
        $box = $this->box($map, $id);
        if ($box['type'] !== 'text') {
            throw new InvalidArgumentException(__('Only a text box can become plain text'));
        }
        if ($style === 'card') {
            return ['furniture' => ['box:' . $id]];
        }
        $lines = array_merge(
            $box['title'] === '' ? [] : [$box['title']],
            $box['text'] === '' ? [] : explode("\n", $box['text']),
            $box['note'] === '' ? [] : explode("\n", $box['note'])
        );
        [$x, $y] = $at ?? [max(0, $box['x']), max(0, $box['y'])];
        $this->setFurnitureShown($map, 'box:' . $id, false);
        return $this->addNode($map, ['note' => true, 'x' => $x, 'y' => $y, 'text' => $lines ? implode("\n", $lines) : __('Text')]);
    }

    /**
     * {op: set_box, name, props: {title, style, text, rows, width, note, hidden, x, y}}
     * - an empty value takes the default; hidden 1 keeps the card and its
     * settings in the .conf without drawing it (F57).
     */
    private function setBox(\WeatherMap $map, string $id, array $props): void
    {
        $box = $this->box($map, $id);
        foreach ($props as $key => $value) {
            $hint = 'box_' . $id . '_' . $key;
            switch ($key) {
                case 'title':
                    // an empty title is kept as "-" (no title), unset takes the default
                    $value = $value === null ? '' : $this->text($value);
                    if ($value === '' && $props[$key] !== null) {
                        $value = '-';
                    }
                    break;
                case 'note':
                case 'text':
                    $value = \WeatherMapBoxes::encode(str_replace("\r", '', (string) $value));
                    $value = trim(str_replace('\\n', '', $value)) === '' ? '' : $value;
                    break;
                case 'style':
                    $value = strtolower($this->text($value));
                    $allowed = ['legend' => ['bar', 'list', 'tags'], 'total' => ['graph', 'plain']][$box['type']] ?? [];
                    if ($value !== '' && !in_array($value, $allowed, true)) {
                        throw new InvalidArgumentException(__('Unknown box style: :style', ['style' => $value]));
                    }
                    break;
                case 'rows':
                    $value = strtolower($this->text($value));
                    if ($value !== '' && !in_array($value, \WeatherMapBoxes::ROW_SOURCES, true)) {
                        throw new InvalidArgumentException(__('Unknown box rows: :rows', ['rows' => $value]));
                    }
                    break;
                case 'hidden':
                    $value = $value === true || $value === 1 || $value === '1' ? '1' : '';
                    break;
                case 'width':
                    $value = $value === '' || $value === null ? '' : (string) max(0, min(2000, (int) $value));
                    $value = $value === '0' ? '' : $value;
                    break;
                case 'x':
                case 'y':
                    continue 2;
                default:
                    throw new InvalidArgumentException(__('Unknown box property: :key', ['key' => (string) $key]));
            }
            if ($value === '') {
                unset($map->hints[$hint]);
            } else {
                $map->add_hint($hint, $value);
            }
        }
        if (isset($props['x']) || isset($props['y'])) {
            $map->add_hint('box_' . $id, $box['type'] . ' ' . (int) ($props['x'] ?? $box['x']) . ' ' . (int) ($props['y'] ?? $box['y']));
        }
        self::groupBoxHints($map);
    }

    /** Keep the SET box_* lines together in the .conf, box by box */
    private static function groupBoxHints(\WeatherMap $map): void
    {
        self::groupHints($map, 'box');
    }

    /** Keep the SET <prefix>_* lines together at the end of the .conf hints, item by item */
    private static function groupHints(\WeatherMap $map, string $prefix): void
    {
        $boxes = [];
        $others = [];
        foreach ($map->hints as $key => $value) {
            if (preg_match('/^' . $prefix . '_([A-Za-z0-9]+)(?:_(\w+))?$/', (string) $key, $m)) {
                $boxes[(string) $key] = [$m[1], isset($m[2]) ? $m[2] : '', $value];
            } else {
                $others[$key] = $value;
            }
        }
        uasort($boxes, static fn (array $a, array $b) => [strnatcmp($a[0], $b[0]), $a[1]] <=> [0, $b[1]]);
        foreach ($boxes as $key => $b) {
            $others[$key] = $b[2];
        }
        $map->hints = $others;
    }

    /** @return array<string, mixed> */
    private function box(\WeatherMap $map, string $id): array
    {
        $box = \WeatherMapBoxes::parse($map, $id, (string) $map->get_hint('box_' . $id));
        if ($box === null) {
            throw new InvalidArgumentException(__('Unknown box: :name', ['name' => $id]));
        }
        return $box;
    }

    /**
     * {op: add_focus, box: [x1, y1, x2, y2], title?, style?, default?} - a
     * focus area (SET focus_<id>, see FocusAreas). Returns {focus: [id]}.
     */
    private function addFocus(\WeatherMap $map, array $op): array
    {
        $id = 1;
        while ($map->get_hint('focus_' . $id) !== null) {
            $id++;
        }
        $box = $this->focusBox($op['box'] ?? null);
        $map->add_hint('focus_' . $id, implode(' ', $box));
        $this->setFocus($map, (string) $id, array_intersect_key($op, array_flip(['title', 'style', 'default'])));

        return ['focus' => [(string) $id]];
    }

    /** {op: set_focus, name, props: {title, style, box, default}} - an empty title or style takes the default */
    private function setFocus(\WeatherMap $map, string $id, array $props): void
    {
        if (!preg_match('/^[A-Za-z0-9]+$/', $id) || $id === 'default' || FocusAreas::box((string) ($map->get_hint('focus_' . $id) ?? '')) === null) {
            throw new InvalidArgumentException(__('Unknown focus area: :name', ['name' => $id]));
        }
        foreach ($props as $key => $value) {
            switch ($key) {
                case 'title':
                    $value = $this->text($value ?? '');
                    break;
                case 'style':
                    $value = strtolower($this->text($value ?? ''));
                    if ($value !== '' && !in_array($value, FocusAreas::STYLES, true)) {
                        throw new InvalidArgumentException(__('Unknown focus area style: :style', ['style' => $value]));
                    }
                    // both is the default: not written
                    $value = $value === FocusAreas::STYLES[0] ? '' : $value;
                    break;
                case 'box':
                    $map->add_hint('focus_' . $id, implode(' ', $this->focusBox($value)));
                    continue 2;
                case 'default':
                    if ($value) {
                        $map->add_hint('focus_default', $id);
                    } elseif (FocusAreas::defaultId($map) === $id) {
                        unset($map->hints['focus_default']);
                    }
                    continue 2;
                default:
                    throw new InvalidArgumentException(__('Unknown focus area property: :key', ['key' => (string) $key]));
            }
            $hint = 'focus_' . $id . '_' . $key;
            if ($value === '') {
                unset($map->hints[$hint]);
            } else {
                $map->add_hint($hint, $value);
            }
        }
        self::groupHints($map, 'focus');
    }

    /** {op: delete_focus, name} - the area and its settings; the map opens on the whole map if it was the default */
    private function deleteFocus(\WeatherMap $map, string $id): void
    {
        if (!preg_match('/^[A-Za-z0-9]+$/', $id) || $id === 'default' || $map->get_hint('focus_' . $id) === null) {
            throw new InvalidArgumentException(__('Unknown focus area: :name', ['name' => $id]));
        }
        if (trim((string) ($map->get_hint('focus_default') ?? '')) === $id) {
            unset($map->hints['focus_default']);
        }
        foreach (array_keys($map->hints) as $key) {
            if ($key === 'focus_' . $id || str_starts_with((string) $key, 'focus_' . $id . '_')) {
                unset($map->hints[$key]);
            }
        }
    }

    /**
     * [x1, y1, x2, y2] in map pixels, ordered, at least 10 px each way.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function focusBox(mixed $value): array
    {
        $v = is_array($value) ? array_values($value) : [];
        if (count($v) !== 4 || array_filter($v, static fn ($n) => !is_numeric($n)) !== []) {
            throw new InvalidArgumentException(__('A focus area needs a rectangle: x1 y1 x2 y2'));
        }
        $v = array_map(static fn ($n) => (int) round((float) $n), $v);
        $box = [min($v[0], $v[2]), min($v[1], $v[3]), max($v[0], $v[2]), max($v[1], $v[3])];
        if ($box[2] - $box[0] < 10 || $box[3] - $box[1] < 10) {
            throw new InvalidArgumentException(__('A focus area must be at least 10 pixels wide and high'));
        }
        return $box;
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
                        'radius' => max(0, min(\WeatherMapZone::RADIUS_MAX, (int) $value)),
                        'colour' => $this->zoneColour($value),
                        'font' => $this->fontNumber($map, $value),
                        default => (int) $value,
                    };
                } elseif (isset(self::ZONE_CHOICES[$key])) {
                    $value = $key === 'labelpos' ? strtoupper((string) $value) : strtolower((string) $value);
                    if (!in_array($value, self::ZONE_CHOICES[$key], true)) {
                        throw new InvalidArgumentException(__('Invalid value for :key: :value', ['key' => $key, 'value' => $value]));
                    }
                    $zone->$key = $value;
                    // a placement puts the label back on its spot, unless moved in the same change
                    if ($key === 'labelpos' && !array_key_exists('labeloffset', $props)) {
                        $zone->labeloffset = [0, 0];
                    }
                } elseif ($key === 'labeloffset') {
                    // [dx, dy] from the label's placement, null puts it back
                    $offset = $value === null ? [0, 0] : $this->point($value);
                    $zone->labeloffset = [\WeatherMapZone::clampOffset($offset[0]), \WeatherMapZone::clampOffset($offset[1])];
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
     * A box with its label, a round badge (SET node_style badge) or the
     * icon alone (SET node_style icon). The hint is written only where the
     * node differs from its template.
     */
    private function setNodeStyle(\WeatherMap $map, \WeatherMapNode $node, string $style): void
    {
        if (!in_array($style, ['box', 'badge', 'icon'], true)) {
            throw new InvalidArgumentException(__('Invalid :key: :value', ['key' => 'style', 'value' => $style]));
        }
        $template = $map->nodes[$node->template] ?? $map->nodes['DEFAULT'] ?? null;
        $inherited = $template !== null ? $template->look() : 'box';
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
    /** A traffic bar hint as it is written: classic|bar, or a colour as #rrggbb or none */
    private static function barValue(string $hint, mixed $value): string
    {
        $value = strtolower(trim((string) $value));
        if ($hint === 'link_look') {
            if (!in_array($value, ['classic', 'bar'], true)) {
                throw new InvalidArgumentException(__('Invalid :key: :value', ['key' => $hint, 'value' => $value]));
            }
            return $value;
        }
        $colour = \WeatherMapLink::parse_colour_hint($value);
        if ($colour === false) {
            throw new InvalidArgumentException(__('Invalid colour: :value', ['value' => $value]));
        }
        return $colour === null ? 'none' : sprintf('#%02x%02x%02x', $colour[0], $colour[1], $colour[2]);
    }

    /** What a traffic bar hint of a link is: its own, its template's, the map's, or the default */
    private static function barHintOf(\WeatherMap $map, object $item, string $hint): string
    {
        $value = trim((string) ($item->hints[$hint] ?? ''));
        if ($value === '') {
            $value = trim((string) ($map->hints[$hint] ?? ''));
        }
        if ($value === '') {
            return self::BAR_DEFAULTS[$hint];
        }
        try {
            return self::barValue($hint, $value);
        } catch (InvalidArgumentException) {
            return self::BAR_DEFAULTS[$hint];
        }
    }

    /** A traffic bar hint of one link: written only when it differs from what the link would take */
    private function setBarHint(\WeatherMap $map, \WeatherMapLink $link, string $hint, mixed $value): void
    {
        $value = self::barValue($hint, $value);
        $template = $map->links[$link->template] ?? $map->links['DEFAULT'] ?? null;
        $inherited = $template !== null && $template !== $link
            ? self::barHintOf($map, $template, $hint)
            : self::barHintOf($map, new \stdClass(), $hint);
        if ($value === $inherited) {
            unset($link->hints[$hint]);
        } else {
            $link->hints[$hint] = $value;
        }
    }

    /**
     * A traffic bar hint of the whole map (SET link_look bar...): the map
     * hint, the default value removing it. The DEFAULT link stops setting
     * it, and the links that took it from there follow the map.
     */
    private function setMapBarHint(\WeatherMap $map, string $hint, mixed $value): void
    {
        $value = self::barValue($hint, $value);
        $default = $map->links['DEFAULT'] ?? null;
        $old = $default !== null && isset($default->hints[$hint]) ? (string) $default->hints[$hint] : null;
        if ($old !== null) {
            foreach ($map->links as $name => $link) {
                if ($name !== 'DEFAULT' && isset($link->hints[$hint]) && (string) $link->hints[$hint] === $old
                    && (string) $link->template === 'DEFAULT') {
                    unset($link->hints[$hint]);
                }
            }
            unset($default->hints[$hint]);
        }
        if ($value === self::BAR_DEFAULTS[$hint]) {
            unset($map->hints[$hint]);
        } else {
            $map->add_hint($hint, $value);
        }
    }

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
                    $node->$field = match ($key) {
                        'label', 'labeloffset' => $this->text($value),
                        'labelfont' => $this->fontNumber($map, $value),
                        default => (int) $value,
                    };
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
                } elseif ($key === 'cluster') {
                    // SET cluster: the group the node merges with when the map merges only groups
                    $group = trim((string) preg_replace('/\s+/u', ' ', $this->text($value)));
                    $this->setHint($node, $map->nodes[$node->template] ?? $map->nodes['DEFAULT'] ?? null, 'cluster', $group, '');
                } elseif ($key === 'badge_size') {
                    // SET badge_size: the diameter of a round badge in pixels, 0 or '' for its own size
                    $size = (int) $value;
                    if ($size !== 0 && ($size < self::BADGE_SIZE_MIN || $size > self::BADGE_SIZE_MAX)) {
                        throw new InvalidArgumentException(__('The badge size must be between :min and :max pixels', ['min' => self::BADGE_SIZE_MIN, 'max' => self::BADGE_SIZE_MAX]));
                    }
                    $this->setHint($node, $map->nodes[$node->template] ?? $map->nodes['DEFAULT'] ?? null, 'badge_size', $size === 0 ? '' : (string) $size, '');
                } elseif ($key === 'icon_spin') {
                    // SET icon_spin 1: the viewer turns the icon with the traffic
                    $this->setHint($node, $map->nodes[$node->template] ?? $map->nodes['DEFAULT'] ?? null, 'icon_spin', $value ? '1' : '0', '0');
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
        $names = (array) ($op['names'] ?? []);
        $customText = ($props['labelstyle'] ?? null) === '--' ? $this->customLabelTexts($map, $names) : null;

        foreach ($names as $name) {
            $link = $this->link($map, (string) $name);

            foreach ($props as $key => $value) {
                if (isset(self::LINK_FIELDS[$key])) {
                    $field = self::LINK_FIELDS[$key];
                    if ($key === 'a_offset' || $key === 'b_offset') {
                        $link->$field = $this->anchor((string) $value);
                    } elseif ($key === 'bwfont' || $key === 'commentfont') {
                        $link->$field = $this->fontNumber($map, $value);
                    } else {
                        $link->$field = (int) $value;
                    }
                } elseif ($key === 'arrowstyle') {
                    $link->arrowstyle = self::arrowStyle($value);
                } elseif ($key === 'labelstyle' && $value === '--') {
                    // custom text: links that have one keep it, the others take it
                    if ((string) $link->labelstyle !== '--' && $customText !== null) {
                        $link->labelstyle = '--';
                        $link->bwlabelformats[IN] = $customText[0];
                        $link->bwlabelformats[OUT] = $customText[1];
                    }
                } elseif ($key === 'label_in' || $key === 'label_out') {
                    // INBWFORMAT / OUTBWFORMAT: a label text of one's own
                    if ((string) $link->labelstyle !== '--') {
                        $link->labelstyle = '--';
                    }
                    $link->bwlabelformats[$key === 'label_in' ? IN : OUT] = $this->text($value);
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
                } elseif ($key === 'look' || $key === 'bar_separator' || $key === 'bar_outline') {
                    // SET link_look, link_bar_separator, link_bar_outline: the traffic bar look
                    $this->setBarHint($map, $link, self::BAR_HINTS[$key], $value);
                } elseif ($key === 'traffic_group') {
                    // SET traffic_group <name>: its tab in the total traffic graph of the viewer
                    $template = $map->links[$link->template] ?? $map->links['DEFAULT'] ?? null;
                    $this->setHint($link, $template, 'traffic_group', \App\Services\TotalTrafficSeries::group($value), '');
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
            $this->setNoteText($node, isset($op['text']) ? (string) $op['text'] : __('Text'));
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

    /**
     * The shipped font families, from the file names (<Family>-<Weight>.ttf):
     * family => {label, regular: file, bold: file or null}. A family needs a
     * regular file; its bold is the first file whose weight says bold.
     *
     * @return array<string, array{label: string, regular: string, bold: ?string}>
     */
    public static function fontFamilies(): array
    {
        $out = [];
        foreach (self::shippedFonts() as $font) {
            $base = (string) preg_replace('/\.ttf$/i', '', basename($font['file']));
            [$family, $weight] = array_pad(explode('-', $base, 2), 2, 'Regular');
            $out[$family] ??= ['label' => self::fontLabel($family . '.ttf'), 'regular' => '', 'bold' => null];
            if ($weight === 'Regular') {
                $out[$family]['regular'] = $font['file'];
            } elseif (self::isBold($weight)) {
                $out[$family]['bold'] ??= $font['file'];
            }
        }
        $out = array_filter($out, static fn (array $f): bool => $f['regular'] !== '');
        uasort($out, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));
        return $out;
    }

    /** A font file or weight name that is drawn bold (semi-bold and heavier) */
    private static function isBold(string $name): bool
    {
        return (bool) preg_match('/bold|black|heavy/i', basename($name));
    }

    /**
     * A font of the map from what the editor sends: its number, or
     * "<file>@<size>" for a TrueType file (shipped, or one the map already
     * uses) at a size. The map font with that file and size is used, or a
     * new one is defined, so every object can take any shipped font.
     */
    private function fontNumber(\WeatherMap $map, mixed $value): int
    {
        if (is_string($value) && preg_match('/^(\S+)@(\d+)$/', trim($value), $m)) {
            foreach ($map->fonts as $n => $font) {
                if ((int) $n > 5 && $font->type === 'truetype' && (string) $font->file === $m[1] && (int) $font->size === (int) $m[2]) {
                    return (int) $n;
                }
            }
            return $this->defineFont($map, ['file' => $m[1], 'size' => (int) $m[2]])['fonts'][0];
        }
        if (!is_numeric($value) || !in_array((int) $value, array_merge(array_keys(self::BUILTIN_FONTS), array_keys($map->fonts)), true)) {
            throw new InvalidArgumentException(__('Invalid font: :value', ['value' => (string) (is_scalar($value) ? $value : '')]));
        }
        return (int) $value;
    }

    /**
     * {op: set_map_font, family} - one shipped font family for every text of
     * the map (title, legend, timestamp, node, zone and note labels, link
     * labels and comments), at the same size, bold where it was bold and the
     * family has a bold file. The TrueType fonts of the map are redefined in
     * place; a text in one of GD's built-in fonts (1 to 5, which the engine
     * always draws itself) gets a TrueType font of a matching size. The fonts
     * picked for single items are replaced too; picking another font for one
     * afterwards gives it its own font again.
     */
    private function setMapFont(\WeatherMap $map, string $family): void
    {
        $files = self::fontFamilies()[$family] ?? null;
        if ($files === null) {
            throw new InvalidArgumentException(__('Unknown font: :file', ['file' => $family]));
        }
        $fileFor = static fn (bool $bold): string => $bold && $files['bold'] !== null ? $files['bold'] : $files['regular'];

        // the TrueType fonts first, so the built-in ones can reuse them
        foreach ($map->fonts as $n => $font) {
            if ($font->type === 'truetype' && (int) $n > 5) {
                $font->file = $fileFor(self::isBold((string) $font->file));
            }
        }

        $new = [];
        $swap = function (mixed $n) use ($map, $fileFor, &$new): int {
            $n = (int) $n;
            $font = $map->fonts[$n] ?? null;
            if ($n > 5 && $font !== null && $font->type === 'truetype') {
                return $n;
            }
            if (!isset($new[$n])) {
                [$size, $bold] = self::BUILTIN_FONTS[$n] ?? [10, false];
                $new[$n] = $this->fontNumber($map, $fileFor($bold) . '@' . $size);
            }
            return $new[$n];
        };

        foreach (['titlefont', 'timefont', 'keyfont'] as $field) {
            $map->$field = $swap($map->$field);
        }
        // ':: DEFAULT ::' holds the engine's own defaults, that DEFAULT is written against
        foreach ($map->nodes as $name => $node) {
            if (!str_starts_with((string) $name, ':: ')) {
                $node->labelfont = $swap($node->labelfont);
            }
        }
        foreach ($map->links as $name => $link) {
            if (str_starts_with((string) $name, ':: ')) {
                continue;
            }
            $link->bwfont = $swap($link->bwfont);
            $link->commentfont = $swap($link->commentfont);
        }
        foreach ($map->zones as $zone) {
            $zone->labelfont = $swap($zone->labelfont);
        }
        ksort($map->fonts);
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

    /** The smallest and largest canvas the editor makes, in pixels */
    private const CANVAS_MIN = 50;
    private const CANVAS_MAX = 20000;

    /**
     * {op: resize_canvas, left, top, right, bottom} - grow (or shrink, when
     * negative) the canvas by that many pixels on each side. Everything
     * moves by (left, top), so nothing moves against the sides that stay:
     * nodes, vias, zones, legends, title, timestamps, boxes, the width
     * legend and the focus areas. On a basemap the shown area grows
     * instead: the centre moves so the located nodes keep their point.
     */
    private function resizeCanvas(\WeatherMap $map, array $op): void
    {
        $side = static fn (string $k): int => (int) round((float) ($op[$k] ?? 0));
        [$left, $top, $right, $bottom] = [$side('left'), $side('top'), $side('right'), $side('bottom')];
        $w = (int) $map->width + $left + $right;
        $h = (int) $map->height + $top + $bottom;
        if ($w < self::CANVAS_MIN || $h < self::CANVAS_MIN || $w > self::CANVAS_MAX || $h > self::CANVAS_MAX) {
            throw new InvalidArgumentException(__('The map size must be between :min and :max pixels', ['min' => self::CANVAS_MIN, 'max' => self::CANVAS_MAX]));
        }

        $frame = \WeatherMapGeo::frame($map);
        if ($frame !== null) {
            // the point under the centre of the new canvas, in the old one
            [$lat, $lon] = \WeatherMapGeo::unproject($frame, (int) $map->width, (int) $map->height, $w / 2 - $left, $h / 2 - $top);
            $map->add_hint('basemap_center', \WeatherMapGeo::format($lat, $lon));
        }

        \WeatherMapScaler::shift($map, $left, $top, $w, $h);

        $hints = is_array($map->hints) ? $map->hints : [];
        foreach ($hints as $key => $value) {
            $key = (string) $key;
            $value = trim((string) $value);
            if (preg_match('/^box_[A-Za-z0-9]+$/', $key) && preg_match('/^(\w+)\s+(-?\d+)[\s,]+(-?\d+)$/', $value, $m)) {
                $map->add_hint($key, $m[1] . ' ' . ((int) $m[2] + $left) . ' ' . ((int) $m[3] + $top));
            } elseif ($key === 'width_legend' && preg_match('/^(-?\d+)[\s,]+(-?\d+)$/', $value, $m)) {
                $map->add_hint($key, ((int) $m[1] + $left) . ' ' . ((int) $m[2] + $top));
            } elseif (preg_match('/^focus_[A-Za-z0-9]+$/', $key) && $key !== 'focus_default'
                && preg_match('/^(-?\d+)[\s,]+(-?\d+)[\s,]+(-?\d+)[\s,]+(-?\d+)$/', $value, $m)) {
                $map->add_hint($key, implode(' ', [(int) $m[1] + $left, (int) $m[2] + $top, (int) $m[3] + $left, (int) $m[4] + $top]));
            }
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

    /** The badge diameters SET badge_size takes, in pixels (as the engine) */
    public const BADGE_SIZE_MIN = \WeatherMapNode::BADGE_SIZE_MIN;
    public const BADGE_SIZE_MAX = \WeatherMapNode::BADGE_SIZE_MAX;

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
            } elseif ($key === 'cluster_mode') {
                // SET cluster_mode nearby|none|groups; nearby is the default and writes nothing
                $mode = (string) $value;
                if (!in_array($mode, Basemap::CLUSTER_MODES, true)) {
                    throw new InvalidArgumentException(__('Invalid :key: :value', ['key' => $key, 'value' => $mode]));
                }
                if ($mode === Basemap::CLUSTER_MODES[0]) {
                    unset($map->hints['cluster_mode']);
                } else {
                    $map->hints['cluster_mode'] = $mode;
                }
            } elseif ($key === 'title_font' || $key === 'time_font' || $key === 'key_font') {
                $field = ['title_font' => 'titlefont', 'time_font' => 'timefont', 'key_font' => 'keyfont'][$key];
                $map->$field = $this->fontNumber($map, $value);
            } elseif ($key === 'stamp_shown') {
                $this->setFurnitureShown($map, 'timestamp:', (bool) $value);
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
            } elseif ($key === ViewerPanels::HINT) {
                // which panels the viewer and the kiosk show, and where
                ViewerPanels::set($map, $value);
            } elseif ($key === ViewerPanels::KIOSK_HINT) {
                // the kiosk's layout: the viewer's corners, the viewer's layout or its own
                ViewerPanels::setKiosk($map, $value);
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
            } elseif (isset(self::BAR_DEFAULTS[$key])) {
                // the traffic bar look of the whole map: a map hint
                $this->setMapBarHint($map, $key, $value);
            } elseif (in_array($key, ['link_bandwidth', 'link_width', 'link_labelstyle', 'link_bwfont', 'link_bwstyle', 'link_arrowstyle'], true)) {
                $this->setLinkDefault($map, $key, $value);
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
        if ($theme['font'] !== null) {
            $this->setMapFont($map, $theme['font']);
        }
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

    private function setLinkDefault(\WeatherMap $map, string $key, mixed $value): void
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
            // custom text stays as it is: there is no field for the default's texts
            'link_labelstyle' => in_array($value, self::LINK_CHOICES['labelstyle'], true) || ($value === '--' && (string) $default->labelstyle === '--') ? $value
                : throw new InvalidArgumentException(__('Invalid :key: :value', ['key' => $key, 'value' => $value])),
            'link_bwfont' => $this->fontNumber($map, $value),
            'link_bwstyle' => in_array($value, self::LINK_CHOICES['bwstyle'], true) ? $value
                : throw new InvalidArgumentException(__('Invalid :key: :value', ['key' => $key, 'value' => $value])),
            'link_arrowstyle' => self::arrowStyle($value, $key),
        };

        if ($key === 'link_labelstyle' && $new === '--') {
            return;
        }

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
        $value = self::defaultableValue($link, $field);
        $seen = [];
        while (($template = (string) $link->template) !== 'DEFAULT') {
            $parent = $map->links[$template] ?? null;
            if ($parent === null || isset($seen[$template]) || self::defaultableValue($parent, $field) !== $value) {
                return false;
            }
            $seen[$template] = true;
            $link = $parent;
        }
        return $value === self::defaultableValue($map->links['DEFAULT'], $field);
    }

    /** A link's $field as inheritsFromDefault compares it: custom label text counts with its texts */
    private static function defaultableValue(object $link, string $field): string
    {
        $value = (string) $link->$field;
        if ($field === 'labelstyle' && $value === '--') {
            $value .= "\n" . ($link->bwlabelformats[IN] ?? '') . "\n" . ($link->bwlabelformats[OUT] ?? '');
        }
        return $value;
    }

    /**
     * The custom label texts (INBWFORMAT, OUTBWFORMAT) that BWLABEL "--"
     * gives the links: those of the first of them that has some. A link
     * cannot get custom text when none of them has any: the label texts
     * fields set it.
     *
     * @param array<mixed> $names
     * @return array{0: string, 1: string}
     */
    private function customLabelTexts(\WeatherMap $map, array $names): array
    {
        foreach ($names as $name) {
            $link = $this->link($map, (string) $name);
            if ((string) $link->labelstyle === '--') {
                return [(string) $link->bwlabelformats[IN], (string) $link->bwlabelformats[OUT]];
            }
        }
        throw new InvalidArgumentException(__('Invalid :key: :value', ['key' => 'labelstyle', 'value' => '--']));
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

    /**
     * ARROWSTYLE as the editor may set it: one of the named styles, or a
     * custom head size "<length> <width>" (each 1 to ARROW_FACTOR_MAX), as
     * a map file may have it.
     */
    private static function arrowStyle(mixed $value, string $key = 'arrowstyle'): string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        if (in_array($value, self::LINK_CHOICES['arrowstyle'], true)) {
            return $value;
        }
        if (preg_match('/^(\d{1,3})\s+(\d{1,3})$/', $value, $m)) {
            $length = (int) $m[1];
            $width = (int) $m[2];
            if ($length >= 1 && $width >= 1 && $length <= self::ARROW_FACTOR_MAX && $width <= self::ARROW_FACTOR_MAX) {
                return $length . ' ' . $width;
            }
        }
        throw new InvalidArgumentException(__('Invalid :key: :value', ['key' => $key, 'value' => $value]));
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
            'key_font' => (int) $map->keyfont,
            'key_text_colour' => $colour('KEYTEXT'),
            'key_bg_colour' => $colour('KEYBG'),
            'key_outline_colour' => $colour('KEYOUTLINE'),
            'stamp_shown' => (int) $map->timex >= 0 && (int) $map->timey >= 0,
            'stamp_text' => (string) $map->stamptext,
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
            // the traffic bar look of the whole map (SET link_look bar...)
            'link_look' => self::barHintOf($map, $default ?? new \stdClass(), 'link_look'),
            'link_bar_separator' => self::barHintOf($map, $default ?? new \stdClass(), 'link_bar_separator'),
            'link_bar_outline' => self::barHintOf($map, $default ?? new \stdClass(), 'link_bar_outline'),
            'basemap' => (string) ($map->hints['basemap'] ?? ''),
            'basemap_dark' => (string) ($map->hints['basemap_dark'] ?? ''),
            'basemap_center' => (string) ($map->hints['basemap_center'] ?? ''),
            'basemap_zoom' => (string) ($map->hints['basemap_zoom'] ?? ''),
            'basemap_attribution' => (string) ($map->hints['basemap_attribution'] ?? ''),
            'cluster_mode' => Basemap::clusterMode($map),
            'viewer_panels' => ViewerPanels::of($map),
            'kiosk_panels' => ViewerPanels::kioskModel($map),
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
                'style' => $node->look(),
                'site' => \App\Services\MapService::siteOf($node),
                // its point on the basemap, [lat, lon], or null
                'geo' => \WeatherMapGeo::nodePoint($node),
                // SET cluster: its own cluster group ('' when it follows its site)
                'cluster' => is_scalar($node->hints['cluster'] ?? null) ? trim((string) $node->hints['cluster']) : '',
                // SET badge_size: the diameter of its badge, 0 for its own size
                'badge_size' => $node->badgeSize(),
                // SET icon_spin 1: its icon turns with the traffic in the viewer
                'icon_spin' => \App\Services\MapService::spinsWithTraffic($node),
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
                // where the engine put the labels, in % of the spine (on the
                // visible part of each arrow with the default BWLABELPOS)
                'labelpos_drawn' => is_array($link->drawn_labelpos ?? null)
                    ? ['in' => round((float) $link->drawn_labelpos[IN], 1), 'out' => round((float) $link->drawn_labelpos[OUT], 1)]
                    : null,
                'target' => self::targetsOut($link->targets),
                'datasource' => (string) ($link->hints['datasource'] ?? ''),
                'comment_in' => (string) ($link->comments[IN] ?? ''),
                'comment_out' => (string) ($link->comments[OUT] ?? ''),
                'infourl' => (string) ($link->infourl[IN] ?? ''),
                'hover_url' => implode(' ', (array) ($link->overliburl[IN] ?? [])),
                'commentcurve' => (string) $link->commentcurve === '1' ? '1' : '0',
                'bwfont' => (int) $link->bwfont,
                'commentfont' => (int) $link->commentfont,
                'bwstyle' => self::bwStyle($link),
                'labelstyle' => (string) $link->labelstyle,
                'label_in' => (string) ($link->bwlabelformats[IN] ?? ''),
                'label_out' => (string) ($link->bwlabelformats[OUT] ?? ''),
                'arrowstyle' => (string) $link->arrowstyle,
                'labelshift_in' => (int) round((float) ($link->hints['bwlabel_offset_in'] ?? 0)),
                'labelshift_out' => (int) round((float) ($link->hints['bwlabel_offset_out'] ?? 0)),
                'traffic_total' => \App\Services\MapService::countsInTotal($link) ? '1' : '0',
                'traffic_group' => \App\Services\TotalTrafficSeries::groupOf($link),
                'look' => self::barHintOf($map, $link, 'link_look'),
                'bar_separator' => self::barHintOf($map, $link, 'link_bar_separator'),
                'bar_outline' => self::barHintOf($map, $link, 'link_bar_outline'),
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
                'shape' => (string) $zone->shape,
                'radius' => (int) $zone->radius,
                'label_offset' => array_map('intval', (array) $zone->labeloffset),
                'label_box' => self::zoneLabelBox($map, $zone),
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
                // TITLEPOS -1 -1 (or another negative position): not drawn
                'title_shown' => $map->titlex >= 0 && $map->titley >= 0,
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
            // the cards kept in the .conf but not drawn (SET box_<id>_hidden 1): the Map items list shows them again
            'hidden_boxes' => array_values(array_map(static fn (array $b) => [
                'kind' => 'box', 'name' => $b['id'], 'props' => ['type' => $b['type'], 'title' => $b['title']],
            ], array_filter(\WeatherMapBoxes::all($map), static fn (array $b) => $b['hidden']))),
            // the focus areas of the viewer and the kiosk (SET focus_*)
            'focus' => array_values(FocusAreas::all($map)),
            'templates' => $templates,
            'fonts' => array_values(array_unique(array_merge([1, 2, 3, 4, 5], array_keys($map->fonts)))),
            // what each defined font is: family (from the file name) and size
            // (the built-in GD fonts have no file and are left out)
            'font_info' => array_map(static fn ($f) => $f->type === 'truetype'
                ? ['label' => self::fontLabel((string) $f->file), 'size' => (int) $f->size, 'file' => (string) $f->file]
                : ['label' => basename((string) $f->file), 'size' => 0, 'file' => (string) $f->file],
                // 1 to 5 are drawn by GD whatever FONTDEFINE says
                array_filter($map->fonts, static fn ($f, $n) => (int) $n > 5 && (string) $f->file !== '', ARRAY_FILTER_USE_BOTH)),
        ];
    }

    /**
     * The cards, timestamps and title as drawn: their box
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
            if (str_starts_with($name, 'BOX:')) {
                [$kind, $item] = ['box', substr($name, 4)];
            } elseif (isset(self::FURNITURE_AREAS[$name])) {
                [$kind, $item] = self::FURNITURE_AREAS[$name];
            } else {
                continue;
            }

            $box = [(int) $area->x1, (int) $area->y1, (int) $area->x2, (int) $area->y2];
            $pos = match ($kind) {
                // text is placed by the left end of its baseline (a timestamp in its card)
                'timestamp' => self::timestampPosition($map, $item, $box),
                'title' => [$box[0], $box[3]],
                default => [$box[0], $box[1]],
            };

            // the last drawing wins, if an area was recorded twice
            $found[$kind . ':' . $item] = ['kind' => $kind, 'name' => $item, 'box' => $box, 'pos' => $pos];
            if ($kind === 'box') {
                // what the box panel edits
                $b = \WeatherMapBoxes::parse($map, $item, (string) $map->get_hint('box_' . $item));
                if ($b !== null) {
                    $found[$kind . ':' . $item]['props'] = [
                        'type' => $b['type'],
                        'title' => $b['title'],
                        'style' => $b['tags'] ? 'tags' : $b['style'],
                        'text' => $b['text'],
                        'rows' => $b['rows'],
                        'width' => $b['width'],
                        'note' => $b['note'],
                    ];
                }
            }
        }

        return array_values($found);
    }

    /**
     * Where TIMEPOS (MINTIMEPOS, MAXTIMEPOS) puts a timestamp: its keyword
     * when set, else the start of the text in the card (the top right corner)
     */
    private static function timestampPosition(\WeatherMap $map, string $which, array $box): array
    {
        $prefix = ['' => '', 'MIN' => 'min', 'MAX' => 'max'][$which] ?? '';
        [$x, $y] = [(int) $map->{$prefix . 'timex'}, (int) $map->{$prefix . 'timey'}];
        return $x > 0 && $y > 0 ? [$x, $y] : \WeatherMapBoxes::timestampAnchor($box);
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

    /** Where the label of a zone is drawn, [x1, y1, x2, y2], or null without one */
    private static function zoneLabelBox(\WeatherMap $map, \WeatherMapZone $zone): ?array
    {
        if ($zone->label === '' || $zone->labelfontcolour === null) {
            return null;
        }
        [$tw, $th] = $map->myimagestringsize($zone->labelfont, $zone->label);
        [$tx, $ty] = $zone->labelPosition($map);

        return [(int) $tx, (int) round($ty - $th), (int) round($tx + $tw), (int) $ty];
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
