<?php

declare(strict_types=1);

namespace Tests\Engine;

use PHPUnit\Framework\TestCase;

final class ScalerTest extends TestCase
{
    private const MAP = <<<'CONF'
WIDTH 800
HEIGHT 500
TITLE t
TITLEPOS 10 20
KEYPOS DEFAULT 680 20 Trafic
TIMEPOS 10 490 stamp

NODE core
	POSITION 400 120
NODE paris
	POSITION 150 320
NODE label
	POSITION core 0 -40
NODE far
	POSITION core 100r90

LINK core-paris
	NODES core paris
	WIDTH 6
	VIA 250 420
	VIA core 20 30
CONF;

    public function testScaleContentDoublesEveryCoordinate(): void
    {
        $map = $this->scaled(1600, 1000, false, false);

        $this->assertSame([1600, 1000], [$map->width, $map->height]);
        $this->assertSame([800, 240], [$map->nodes['core']->x, $map->nodes['core']->y]);
        $this->assertSame([300, 640], [$map->nodes['paris']->x, $map->nodes['paris']->y]);
        $this->assertSame([0, -80], [$map->nodes['label']->original_x, $map->nodes['label']->original_y]);
        $this->assertSame(200, $map->nodes['far']->original_x, 'Polar distance scales, the angle does not');
        $this->assertEquals(90, $map->nodes['far']->original_y);
        $this->assertSame([500, 840], array_slice($map->links['core-paris']->vialist[0], 0, 2));
        $this->assertSame([40, 60, 'core'], $map->links['core-paris']->vialist[1]);
        $this->assertSame([1360, 40], [$map->keyx['DEFAULT'], $map->keyy['DEFAULT']]);
        $this->assertSame([20, 40], [$map->titlex, $map->titley]);
        $this->assertSame([20, 980], [$map->timex, $map->timey]);
        $this->assertEquals(6, $map->links['core-paris']->width, 'Sizes stay unless asked');
    }

    public function testKeepAspectCentresTheContent(): void
    {
        // Only wider: factor 1, content shifted by (1600 - 800) / 2
        $map = $this->scaled(1600, 500, true, false);

        $this->assertSame([800, 120], [$map->nodes['core']->x, $map->nodes['core']->y]);
        $this->assertSame([550, 320], [$map->nodes['paris']->x, $map->nodes['paris']->y]);
    }

    public function testScaleSizesAlsoScalesLinkWidth(): void
    {
        $map = $this->scaled(400, 250, false, true);

        $this->assertEquals(3, $map->links['core-paris']->width);
    }

    public function testScaledMapSurvivesWriteAndRead(): void
    {
        $map = $this->scaled(1200, 750, false, false);
        $file = TESTS_OUTPUT . '/scaled.conf';
        $map->WriteConfig($file);

        $reloaded = new \WeatherMap();
        $reloaded->context = 'cli';
        $reloaded->ReadConfig($file);

        $this->assertSame([1200, 750], [(int) $reloaded->width, (int) $reloaded->height]);
        $this->assertEquals([600, 180], [$reloaded->nodes['core']->x, $reloaded->nodes['core']->y]);
        // relative node follows its anchor with the scaled offset
        $this->assertEquals([600, 120], [$reloaded->nodes['label']->x, $reloaded->nodes['label']->y]);
    }

    private function scaled(int $w, int $h, bool $keepAspect, bool $scaleSizes): \WeatherMap
    {
        $file = TESTS_OUTPUT . '/scaler-source.conf';
        file_put_contents($file, self::MAP);
        $map = new \WeatherMap();
        $map->context = 'cli';
        $map->ReadConfig($file);
        \WeatherMapScaler::scale($map, $w, $h, $keepAspect, $scaleSizes);

        return $map;
    }
}
