<?php
/**
 * Ready-made looks for a map, applied in one click from Map properties.
 *
 * A theme only sets values the map format already has (colours, the legend
 * style, the colour scale, the DEFAULT node and link label colours) plus
 * SET link_glow, so a themed map stays an ordinary .conf file that any
 * Weathermap reads.
 */

declare(strict_types=1);

namespace App\Services\Editor;

final class MapThemes
{
    /**
     * Night: a dark canvas, links from cream to amber as the load grows,
     * dark labels with light text, link labels on pills along the link,
     * and a gold-edged legend card. The idea
     * comes from the Nine IX traffic map (stats.nine-ix.net); the colours
     * are our own. Every step of the scale reads on the dark background.
     */
    private const THEMES = [
        'night' => [
            'map' => [
                'bg_colour' => '#11141a',
                'title_colour' => '#f3e7c9',
                'time_colour' => '#b8ad95',
                'key_text_colour' => '#f3e7c9',
                'key_bg_colour' => '#1a1e26',
                'key_outline_colour' => '#c9a24a',
                'key_style' => 'modern',
                'stamp_style' => 'pill',
                'link_glow' => true,
                'link_bwstyle' => 'pill',
            ],
            'scale' => [
                ['bottom' => 0, 'top' => 0, 'colour' => '#5d6472'],
                ['bottom' => 0, 'top' => 1, 'colour' => '#7d8391'],
                ['bottom' => 1, 'top' => 10, 'colour' => '#efe7d2'],
                ['bottom' => 10, 'top' => 25, 'colour' => '#f2dba3'],
                ['bottom' => 25, 'top' => 40, 'colour' => '#f4c76d'],
                ['bottom' => 40, 'top' => 55, 'colour' => '#f5b246'],
                ['bottom' => 55, 'top' => 70, 'colour' => '#f2992c'],
                ['bottom' => 70, 'top' => 85, 'colour' => '#ec7a1f'],
                ['bottom' => 85, 'top' => 100, 'colour' => '#e5531c'],
            ],
            // colours of the DEFAULT link and node, as the engine names them
            'link' => [
                'outlinecolour' => 'none',
                'bwboxcolour' => '#1a1e26',
                'bwfontcolour' => '#f3e7c9',
                'bwoutlinecolour' => '#4a4f5c',
            ],
            'node' => [
                'labelbgcolour' => '#1a1e26',
                'labelfontcolour' => '#f3e7c9',
                'labeloutlinecolour' => '#c9a24a',
            ],
        ],
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::THEMES);
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
        foreach (self::THEMES as $name => $theme) {
            $found = [];
            array_walk_recursive($theme, static function ($v) use (&$found): void {
                if (is_string($v) && preg_match('/^#[0-9a-f]{6}$/i', $v)) {
                    $found[strtolower($v)] = true;
                }
            });
            $out[$name] = array_keys($found);
        }
        return $out;
    }

    /**
     * @return array{map: array<string, mixed>, scale: list<array<string, mixed>>, link: array<string, string>, node: array<string, string>}|null
     */
    public static function get(string $name): ?array
    {
        return self::THEMES[$name] ?? null;
    }
}
