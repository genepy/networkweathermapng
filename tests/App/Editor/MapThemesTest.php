<?php

declare(strict_types=1);

namespace Tests\App\Editor;

use App\Services\Editor\MapThemes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MapThemesTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function themes(): array
    {
        $out = [];
        foreach (MapThemes::names() as $name) {
            $out[$name] = [$name];
        }
        return $out;
    }

    public function testThereIsAChoiceOfThemes(): void
    {
        $this->assertGreaterThanOrEqual(4, count(MapThemes::names()));
        $this->assertContains('night', MapThemes::names());
    }

    /** One design base: every theme sets the same fields and the same styles */
    public function testThemesShareOneDesignBase(): void
    {
        $first = null;
        foreach (MapThemes::names() as $name) {
            $theme = MapThemes::get($name);
            $this->assertNotNull($theme);
            $shape = [array_keys($theme['map']), array_keys($theme['link']), array_keys($theme['node'])];
            $first ??= $shape;
            $this->assertSame($first, $shape, "$name sets the same fields as the others");
            $this->assertSame('modern', $theme['map']['key_style'], $name);
            $this->assertSame('pill', $theme['map']['stamp_style'], $name);
            $this->assertSame('pill', $theme['map']['link_bwstyle'], $name);
        }
        $this->assertNull(MapThemes::get('nope'));
    }

    #[DataProvider('themes')]
    public function testTextsReadOnTheirBackground(string $name): void
    {
        $map = MapThemes::get($name)['map'];
        foreach (['title_colour' => 'bg_colour', 'time_colour' => 'bg_colour', 'key_text_colour' => 'key_bg_colour'] as $fg => $bg) {
            $this->assertGreaterThanOrEqual(4.5, self::contrast($map[$fg], $map[$bg]), "$name: $fg on $bg");
        }
        $link = MapThemes::get($name)['link'];
        $this->assertGreaterThanOrEqual(4.5, self::contrast($link['bwfontcolour'], $link['bwboxcolour']), "$name: link labels");
    }

    /** The scale covers 0 to 100 % without a gap; a smooth one chains its colours */
    #[DataProvider('themes')]
    public function testScalesCoverEveryLoad(string $name): void
    {
        $scale = MapThemes::get($name)['scale'];
        $this->assertSame([0, 0], [$scale[0]['bottom'], $scale[0]['top']], 'an idle step first');
        $at = 0;
        foreach (array_slice($scale, 1) as $i => $step) {
            $this->assertEquals($at, $step['bottom'], "$name step $i starts where the one before ends");
            $this->assertGreaterThan($step['bottom'], $step['top']);
            $next = $scale[$i + 2] ?? null;
            if ($step['colour2'] !== '' && $next !== null) {
                $this->assertSame($next['colour'], $step['colour2'], "$name step $i fades into the next");
            }
            $at = $step['top'];
        }
        $this->assertEquals(100, $at);

        $preview = MapThemes::previews()[$name];
        $this->assertSame(MapThemes::get($name)['map']['bg_colour'], $preview['bg']);
        $this->assertSame(0, $preview['scale'][0][0]);
        $this->assertSame(100, $preview['scale'][count($preview['scale']) - 1][0]);
    }

    private static function contrast(string $a, string $b): float
    {
        $l = static function (string $hex): float {
            $sum = 0.0;
            foreach ([0.2126, 0.7152, 0.0722] as $i => $w) {
                $c = hexdec(substr($hex, 1 + 2 * $i, 2)) / 255;
                $sum += $w * ($c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4);
            }
            return $sum;
        };
        [$x, $y] = [$l($a), $l($b)];
        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }
}
