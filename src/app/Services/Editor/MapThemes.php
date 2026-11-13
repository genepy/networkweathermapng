<?php
/**
 * Ready-made looks for a map, applied in one click from Map properties.
 *
 * A theme only sets values the map format already has (colours, the legend
 * style, the colour scale, the DEFAULT node and link label colours, and for
 * some the fonts, as FONTDEFINE lines) plus SET link_glow, so a themed map
 * stays an ordinary .conf file that any Weathermap reads.
 *
 * Every theme is built on the same design base (BASE): the same legend
 * card, timestamp pill and link label pills, so maps keep one look whatever
 * their colours, and switching themes changes only the palette. Each
 * palette sets the same fields, so a theme applied after another replaces
 * all of it.
 *
 * A smooth scale is a list of stops [load %, colour]: each band blends from
 * one stop to the next (SCALE min max r g b r2 g2 b2), so a link takes the
 * colour of its exact load and the legends draw a gradient bar. The idea
 * of the gradient comes from the Nine IX traffic map (stats.nine-ix.net);
 * the colours are our own.
 */

declare(strict_types=1);

namespace App\Services\Editor;

final class MapThemes
{
    /** The shared design base: legend, timestamp and link label styles */
    private const BASE = [
        'key_style' => 'modern',
        'stamp_style' => 'pill',
        'link_bwstyle' => 'pill',
    ];

    /**
     * The palettes. 'idle' colours the links without traffic (a 0 to 0
     * step); 'smooth' lists the stops of a gradient, 'steps' the bands of a
     * stepped scale [from %, to %, colour]. 'font' is a shipped font family
     * given to every text of the map (MapEditor::fontFamilies()).
     */
    private const PALETTES = [
        // dark canvas, links from cream to amber to red, gold-edged cards
        'night' => [
            'bg' => '#11141a', 'text' => '#f3e7c9', 'muted' => '#b8ad95',
            'card' => '#1a1e26', 'edge' => '#c9a24a', 'line' => '#4a4f5c', 'glow' => true,
            'idle' => '#5d6472', 'font' => 'Inter',
            'smooth' => [[0, '#efe7d2'], [30, '#f4c76d'], [60, '#f2992c'], [100, '#e5531c']],
        ],
        // warm light canvas, white cards, green to amber to red
        'day' => [
            'bg' => '#f6f4ef', 'text' => '#1f2430', 'muted' => '#5b6170',
            'card' => '#ffffff', 'edge' => '#c9c3b5', 'line' => '#c9c3b5', 'glow' => false,
            'idle' => '#a3a8b3',
            'smooth' => [[0, '#3a9e78'], [40, '#d9a21b'], [70, '#e07b2e'], [100, '#c63a2b']],
        ],
        // deep blue canvas, links from cyan to violet to pink
        'ocean' => [
            'bg' => '#0b1d2e', 'text' => '#e3f2fb', 'muted' => '#93b4c8',
            'card' => '#10283d', 'edge' => '#3fa7d6', 'line' => '#2c4a63', 'glow' => true,
            'idle' => '#4b6275',
            'smooth' => [[0, '#5ad1e8'], [40, '#7c8cf8'], [70, '#c86bfa'], [100, '#ff5d8f']],
        ],
        // white canvas, black text, steps told apart by lightness as well as
        // hue (colours after Okabe and Ito), for colour-blind readers
        'contrast' => [
            'bg' => '#ffffff', 'text' => '#000000', 'muted' => '#3a3a3a',
            'card' => '#ffffff', 'edge' => '#000000', 'line' => '#000000', 'glow' => false,
            'idle' => '#9aa0a6',
            'steps' => [
                [0, 10, '#56b4e9'], [10, 25, '#0072b2'], [25, 40, '#009e73'], [40, 55, '#f0e442'],
                [55, 70, '#e69f00'], [70, 85, '#d55e00'], [85, 100, '#8e1b3f'],
            ],
        ],
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::PALETTES);
    }

    /**
     * The colours of each theme, without repeats, in the order they appear
     * (swatches of the colour picker).
     *
     * @return array<string, list<string>>
     */
    public static function colours(): array
    {
        $out = [];
        foreach (self::PALETTES as $name => $palette) {
            $found = [];
            array_walk_recursive($palette, static function ($v) use (&$found): void {
                if (is_string($v) && preg_match('/^#[0-9a-f]{6}$/i', $v)) {
                    $found[strtolower($v)] = true;
                }
            });
            $out[$name] = array_keys($found);
        }
        return $out;
    }

    /**
     * What the editor shows of each theme on its card: the canvas, text and
     * card colours, and the scale as CSS gradient stops.
     *
     * @return array<string, array{bg: string, text: string, card: string, edge: string, smooth: bool, scale: list<array{0: float|int, 1: string}>}>
     */
    public static function previews(): array
    {
        $out = [];
        foreach (self::PALETTES as $name => $p) {
            $stops = [];
            foreach (self::scale($p) as $step) {
                if ($step['top'] > $step['bottom']) {
                    $stops[] = [$step['bottom'], $step['colour']];
                    $stops[] = [$step['top'], $step['colour2'] !== '' ? $step['colour2'] : $step['colour']];
                }
            }
            $out[$name] = [
                'bg' => $p['bg'], 'text' => $p['text'], 'card' => $p['card'], 'edge' => $p['edge'],
                'smooth' => isset($p['smooth']), 'scale' => $stops,
            ];
        }
        return $out;
    }

    /**
     * @return array{map: array<string, mixed>, scale: list<array{bottom: int|float, top: int|float, colour: string, colour2: string}>, link: array<string, string>, node: array<string, string>, font: ?string}|null
     */
    public static function get(string $name): ?array
    {
        $p = self::PALETTES[$name] ?? null;
        if ($p === null) {
            return null;
        }

        return [
            'map' => self::BASE + [
                'bg_colour' => $p['bg'],
                'title_colour' => $p['text'],
                'time_colour' => $p['muted'],
                'key_text_colour' => $p['text'],
                'key_bg_colour' => $p['card'],
                'key_outline_colour' => $p['edge'],
                'link_glow' => $p['glow'],
            ],
            'scale' => self::scale($p),
            // colours of the DEFAULT link and node, as the engine names them
            'link' => [
                'outlinecolour' => 'none',
                'bwboxcolour' => $p['card'],
                'bwfontcolour' => $p['text'],
                'bwoutlinecolour' => $p['line'],
            ],
            'node' => [
                'labelbgcolour' => $p['card'],
                'labelfontcolour' => $p['text'],
                'labeloutlinecolour' => $p['edge'],
            ],
            'font' => $p['font'] ?? null,
        ];
    }

    /**
     * The scale steps of a palette, as the set_scale op takes them: the
     * idle step, then one band per pair of stops (smooth) or the bands.
     *
     * @param array<string, mixed> $p
     * @return list<array{bottom: int|float, top: int|float, colour: string, colour2: string}>
     */
    private static function scale(array $p): array
    {
        $steps = [['bottom' => 0, 'top' => 0, 'colour' => $p['idle'], 'colour2' => '']];
        if (isset($p['smooth'])) {
            $stops = $p['smooth'];
            for ($i = 0; $i + 1 < count($stops); $i++) {
                $steps[] = ['bottom' => $stops[$i][0], 'top' => $stops[$i + 1][0], 'colour' => $stops[$i][1], 'colour2' => $stops[$i + 1][1]];
            }
            return $steps;
        }
        foreach ($p['steps'] as [$bottom, $top, $colour]) {
            $steps[] = ['bottom' => $bottom, 'top' => $top, 'colour' => $colour, 'colour2' => ''];
        }
        return $steps;
    }
}
