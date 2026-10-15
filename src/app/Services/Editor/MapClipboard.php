<?php
/**
 * Copy and paste of nodes and links, also between maps.
 *
 * Copy writes each item with the engine's own WriteConfig(), together with
 * the DEFAULT blocks and the templates it uses, so the text says everything
 * needed to rebuild the items elsewhere. Paste reads that text in a scratch
 * map (strictly NODE and LINK blocks, nothing global) and adds the items to
 * the target map under free names, moved by an offset.
 */

declare(strict_types=1);

namespace App\Services\Editor;

use InvalidArgumentException;

class MapClipboard
{
    public const VERSION = 1;
    public const MAX_BYTES = 512 * 1024;
    public const MAX_ITEMS = 5000;

    /** Keywords a NODE block may hold */
    private const NODE_KEYWORDS = [
        'LABEL', 'MAXVALUE', 'LABELOFFSET', 'LABELFONT', 'LABELANGLE', 'ICON', 'NOTES', 'INFOURL',
        'OVERLIBCAPTION', 'ZORDER', 'OVERLIBWIDTH', 'OVERLIBHEIGHT', 'POSITION', 'TARGET', 'SET',
        'OVERLIBGRAPH', 'INOVERLIBGRAPH', 'OUTOVERLIBGRAPH', 'TEMPLATE', 'USESCALE', 'USEICONSCALE',
        'AICONOUTLINECOLOR', 'AICONFILLCOLOR', 'LABELFONTCOLOR', 'LABELFONTSHADOWCOLOR', 'LABELBGCOLOR',
        'LABELOUTLINECOLOR',
    ];

    /** Keywords a LINK block may hold */
    private const LINK_KEYWORDS = [
        'MAXVALUE', 'BANDWIDTH', 'WIDTH', 'ARROWSTYLE', 'VIASTYLE', 'INCOMMENT', 'OUTCOMMENT', 'BWFONT',
        'COMMENTFONT', 'COMMENTSTYLE', 'DUPLEX', 'BWSTYLE', 'LINKSTYLE', 'BWLABELPOS', 'COMMENTPOS',
        'USESCALE', 'SPLITPOS', 'OUTBWFORMAT', 'INBWFORMAT', 'NOTES', 'INNOTES', 'OUTNOTES', 'INFOURL',
        'ININFOURL', 'OUTINFOURL', 'OVERLIBCAPTION', 'INOVERLIBCAPTION', 'OUTOVERLIBCAPTION', 'ZORDER',
        'OVERLIBWIDTH', 'OVERLIBHEIGHT', 'NODES', 'TARGET', 'BWLABEL', 'SET', 'OVERLIBGRAPH',
        'INOVERLIBGRAPH', 'OUTOVERLIBGRAPH', 'TEMPLATE', 'VIA', 'COMMENTFONTCOLOR', 'BWBOXCOLOR',
        'BWFONTCOLOR', 'BWOUTLINECOLOR', 'OUTLINECOLOR',
    ];

    /**
     * The given nodes, and every link between two of them, as .conf text.
     *
     * @param string[] $nodeNames
     * @return array{version:int, text:string, nodes:string[], links:string[], icons:string[], templates:string[]}
     */
    public static function copy(\WeatherMap $map, array $nodeNames): array
    {
        $nodes = [];
        foreach ($nodeNames as $name) {
            $name = (string) $name;
            if ($name === 'DEFAULT' || !isset($map->nodes[$name]) || $map->nodes[$name]->x === null || str_starts_with($name, ':: ')) {
                continue;
            }
            $nodes[$name] = $map->nodes[$name];
        }
        if (!$nodes) {
            throw new InvalidArgumentException(__('Nothing to copy: select nodes'));
        }

        $links = [];
        foreach ($map->links as $name => $link) {
            if ($name === 'DEFAULT' || !isset($link->a) || !isset($link->b)) {
                continue;
            }
            if (isset($nodes[$link->a->name]) && isset($nodes[$link->b->name])) {
                $links[$name] = $link;
            }
        }

        // the templates used, parents before children
        $nodeTemplates = self::templateChain($map->nodes, array_map(static fn ($n) => (string) $n->template, $nodes));
        $linkTemplates = self::templateChain($map->links, array_map(static fn ($l) => (string) $l->template, $links));

        $text = "# NetworkWeathermapNG clipboard\n";
        ob_start();
        try {
            $text .= $map->nodes['DEFAULT']->WriteConfig();
            $text .= $map->links['DEFAULT']->WriteConfig();
            foreach ($nodeTemplates as $t) {
                $text .= $map->nodes[$t]->WriteConfig();
            }
            foreach ($linkTemplates as $t) {
                $text .= $map->links[$t]->WriteConfig();
            }

            foreach ($nodes as $node) {
                $copy = clone $node;
                if ($copy->relative_to !== '' && !isset($nodes[$copy->relative_to])) {
                    // its parent stays behind: keep the place it has on the map
                    $copy->relative_to = '';
                    $copy->polar = false;
                }
                $text .= $copy->WriteConfig();
            }

            foreach ($links as $link) {
                $copy = clone $link;
                $copy->vialist = [];
                foreach ($link->vialist as $via) {
                    if (isset($via[2]) && !isset($nodes[$via[2]]) && isset($map->nodes[$via[2]])) {
                        $via = [(int) round($map->nodes[$via[2]]->x + $via[0]), (int) round($map->nodes[$via[2]]->y + $via[1])];
                    }
                    $copy->vialist[] = $via;
                }
                $text .= $copy->WriteConfig();
            }
        } finally {
            ob_end_clean();
        }

        $icons = [];
        foreach ($nodes as $node) {
            $icon = (string) $node->iconfile;
            if ($icon !== '' && preg_match('/\.(png|jpe?g|gif)$/i', $icon)) {
                $icons[$icon] = true;
            }
        }

        return [
            'version' => self::VERSION,
            'text' => $text,
            'nodes' => array_map('strval', array_keys($nodes)),
            'links' => array_map('strval', array_keys($links)),
            'icons' => array_keys($icons),
            'templates' => array_values(array_unique(array_merge($nodeTemplates, $linkTemplates))),
        ];
    }

    /**
     * Add the items of a copied text to $map.
     *
     * @param array{0:float,1:float}|null $at where the centre of the pasted nodes goes, or null
     * @return array{nodes:string[], links:string[]}
     */
    public static function paste(\WeatherMap $map, string $text, ?array $at, int $dx, int $dy): array
    {
        self::check($text);
        $source = self::read($text);

        $nodes = [];
        foreach ($source->nodes as $name => $node) {
            if ($name !== 'DEFAULT' && !str_starts_with((string) $name, ':: ') && $node->x !== null) {
                $nodes[$name] = $node;
            }
        }
        if (!$nodes) {
            throw new InvalidArgumentException(__('Nothing to paste'));
        }

        if ($at !== null) {
            $xs = array_map(static fn ($n) => (float) $n->x, $nodes);
            $ys = array_map(static fn ($n) => (float) $n->y, $nodes);
            $dx = (int) round($at[0] - (min($xs) + max($xs)) / 2);
            $dy = (int) round($at[1] - (min($ys) + max($ys)) / 2);
        }

        // templates the target does not have yet come with the items
        foreach ($source->nodes as $name => $tpl) {
            if ($name !== 'DEFAULT' && !str_starts_with((string) $name, ':: ') && $tpl->x === null && !isset($map->nodes[$name])) {
                $new = new \WeatherMapNode();
                $new->name = (string) $name;
                $new->template = isset($map->nodes[$tpl->template]) ? $tpl->template : 'DEFAULT';
                $new->Reset($map);
                $new->CopyFrom($tpl);
                $new->name = (string) $name;
                $new->x = null;
                $new->y = null;
                $new->relative_to = '';
                $new->defined_in = $map->configfile;
                $map->nodes[$name] = $new;
            }
        }
        foreach ($source->links as $name => $tpl) {
            if ($name !== 'DEFAULT' && !str_starts_with((string) $name, ':: ') && !isset($tpl->a) && !isset($map->links[$name])) {
                $new = new \WeatherMapLink();
                $new->name = (string) $name;
                $new->template = isset($map->links[$tpl->template]) ? $tpl->template : 'DEFAULT';
                $new->Reset($map);
                $new->CopyFrom($tpl);
                $new->name = (string) $name;
                $new->defined_in = $map->configfile;
                $map->links[$name] = $new;
            }
        }

        $groups = [];
        $group = static function (array $hints) use (&$groups): array {
            if (isset($hints['editor_group'])) {
                $old = (string) $hints['editor_group'];
                $groups[$old] ??= 'g' . base_convert((string) (int) (microtime(true) * 1000), 10, 36) . count($groups);
                $hints['editor_group'] = $groups[$old];
            }
            return $hints;
        };

        $renamed = [];
        foreach ($nodes as $name => $src) {
            $renamed[$name] = self::freeName($map->nodes, (string) $name);
            // reserve it now, so two pasted nodes do not take the same name
            $map->nodes[$renamed[$name]] = $src;
        }

        foreach ($nodes as $name => $src) {
            $newName = $renamed[$name];
            $node = new \WeatherMapNode();
            $node->name = $newName;
            $node->template = isset($map->nodes[$src->template]) && $map->nodes[$src->template]->x === null ? $src->template : 'DEFAULT';
            $node->Reset($map);
            $node->CopyFrom($src);
            $node->name = $newName;
            $node->x = (int) round((float) $src->x + $dx);
            $node->y = (int) round((float) $src->y + $dy);
            $node->polar = $src->polar;
            $node->relative_to = isset($renamed[$src->relative_to]) ? $renamed[$src->relative_to] : '';
            if ($node->relative_to === '') {
                $node->polar = false;
            }
            $node->relative_resolved = true;
            $node->hints = $group((array) $src->hints);
            $node->defined_in = $map->configfile;
            $map->nodes[$newName] = $node;
        }

        $links = [];
        foreach ($source->links as $name => $src) {
            if ($name === 'DEFAULT' || !isset($src->a) || !isset($src->b)) {
                continue;
            }
            if (!isset($renamed[$src->a->name]) || !isset($renamed[$src->b->name])) {
                continue; // one end was not copied
            }

            $newName = self::freeName($map->links, (string) $name);
            $link = new \WeatherMapLink();
            $link->name = $newName;
            $link->template = isset($map->links[$src->template]) && !isset($map->links[$src->template]->a) ? $src->template : 'DEFAULT';
            $link->Reset($map);
            $link->CopyFrom($src);
            $link->name = $newName;
            $link->a = $map->nodes[$renamed[$src->a->name]];
            $link->b = $map->nodes[$renamed[$src->b->name]];
            $link->vialist = [];
            foreach ($src->vialist as $via) {
                if (isset($via[2]) && isset($renamed[$via[2]])) {
                    $link->vialist[] = [$via[0], $via[1], $renamed[$via[2]]];
                } elseif (isset($via[2]) && isset($source->nodes[$via[2]])) {
                    $ref = $source->nodes[$via[2]];
                    $link->vialist[] = [(int) round($ref->x + $via[0] + $dx), (int) round($ref->y + $via[1] + $dy)];
                } else {
                    $link->vialist[] = [(int) round($via[0] + $dx), (int) round($via[1] + $dy)];
                }
            }
            $link->hints = $group((array) $src->hints);
            $link->defined_in = $map->configfile;
            $map->links[$newName] = $link;
            $links[] = $newName;
        }

        return ['nodes' => array_values($renamed), 'links' => $links];
    }

    /**
     * Only NODE and LINK blocks, with the keywords such blocks take.
     */
    public static function check(string $text): void
    {
        if (strlen($text) > self::MAX_BYTES) {
            throw new InvalidArgumentException(__('The pasted content is too large'));
        }

        $type = null;
        $items = 0;
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $i => $line) {
            $trim = trim($line);
            if ($trim === '' || $trim[0] === '#') {
                continue;
            }
            $word = strtoupper((string) preg_split('/\s+/', $trim)[0]);

            if (preg_match('/^(NODE|LINK)\s+\S+$/i', $trim)) {
                $type = $word;
                if (++$items > self::MAX_ITEMS) {
                    throw new InvalidArgumentException(__('The pasted content is too large'));
                }
                continue;
            }

            $allowed = $type === 'NODE' ? self::NODE_KEYWORDS : ($type === 'LINK' ? self::LINK_KEYWORDS : []);
            if (!in_array($word, $allowed, true)) {
                throw new InvalidArgumentException(__('Line :line cannot be pasted: only NODE and LINK blocks are accepted (:text)', [
                    'line' => $i + 1,
                    'text' => mb_substr($trim, 0, 60),
                ]));
            }
        }

        if ($items === 0) {
            throw new InvalidArgumentException(__('Nothing to paste'));
        }
    }

    private static function read(string $text): \WeatherMap
    {
        $map = new \WeatherMap();
        $map->context = 'editor';

        $previous = $GLOBALS['weathermap_warnings'] ?? null;
        $GLOBALS['weathermap_warnings'] = [];
        ob_start();
        try {
            // a text with line breaks is read as a config fragment, not a file name
            $map->ReadConfig(str_replace("\r", '', $text) . "\n");
        } finally {
            ob_end_clean();
            $GLOBALS['weathermap_warnings'] = $previous;
        }

        return $map;
    }

    /** @param array<string, object> $items @param string[] $used */
    private static function templateChain(array $items, array $used): array
    {
        $out = [];
        $visit = static function (string $name) use (&$visit, &$out, $items): void {
            if ($name === '' || $name === 'DEFAULT' || str_starts_with($name, ':: ') || in_array($name, $out, true) || !isset($items[$name])) {
                return;
            }
            $visit((string) $items[$name]->template);
            $out[] = $name;
        };
        foreach (array_unique($used) as $name) {
            $visit($name);
        }
        return $out;
    }

    private static function freeName(array $existing, string $base): string
    {
        $name = $base;
        $i = 2;
        while (isset($existing[$name])) {
            $name = $base . '_' . $i++;
        }
        return $name;
    }
}
