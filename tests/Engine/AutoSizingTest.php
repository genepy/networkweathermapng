<?php

declare(strict_types=1);

namespace Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * WIDTH auto (width from bandwidth) and BANDWIDTH auto (speed from the data source).
 */
final class AutoSizingTest extends TestCase
{
    public function testDefaultWidthTable(): void
    {
        $this->assertSame(2.0, wm_auto_link_width(100e6));
        $this->assertSame(2.0, wm_auto_link_width(1e9));
        $this->assertSame(4.0, wm_auto_link_width(10e9));
        $this->assertSame(5.0, wm_auto_link_width(25e9));
        $this->assertSame(6.0, wm_auto_link_width(40e9));
        $this->assertSame(9.0, wm_auto_link_width(100e9));
        $this->assertSame(12.0, wm_auto_link_width(800e9));
    }

    public function testMapCanSetItsOwnTable(): void
    {
        $this->assertSame(3.0, wm_auto_link_width(10e9, '1G:1, 10G:3, 100G:8'));
        $this->assertSame(1.0, wm_auto_link_width(1e6, '1G:1, 10G:3, 100G:8'));
    }

    public function testWidthAutoFollowsBandwidth(): void
    {
        $map = $this->load(TESTS_ROOT . '/fixtures/maps/link-widths.conf');

        $this->assertSame([2.0, 4.0, 6.0, 9.0], array_map(
            fn(string $l): float => (float) $map->links[$l]->EffectiveWidth($map),
            ['l1', 'l2', 'l3', 'l4']
        ));
    }

    public function testBandwidthAutoUsesTheInterfaceSpeed(): void
    {
        $map = $this->loadString(<<<'CONF'
NODE a
	POSITION 10 10
NODE b
	POSITION 100 10
LINK single
	NODES a b
	BANDWIDTH auto
	WIDTH auto
	TARGET speedtest:10G:2G
LINK lag
	NODES a b
	BANDWIDTH auto
	TARGET speedtest:10G:1G speedtest:10G:1G
LINK unknown
	NODES a b
	BANDWIDTH auto
	TARGET static:100M
LINK fixed
	NODES a b
	BANDWIDTH 1G
	TARGET speedtest:10G:500M
CONF);
        $this->registerSpeedPlugin($map);
        $map->ReadData();

        $this->assertEquals(10e9, $map->links['single']->max_bandwidth_in);
        $this->assertEqualsWithDelta(20.0, $map->links['single']->inpercent, 0.001);
        $this->assertEquals(4, $map->links['single']->EffectiveWidth($map));
        $this->assertEquals(20e9, $map->links['lag']->max_bandwidth_in, 'An aggregated link adds up its members');
        $this->assertEquals(1e9, $map->links['unknown']->max_bandwidth_in, 'Unknown speed falls back to 1G');
        $this->assertEquals(1e9, $map->links['fixed']->max_bandwidth_in, 'A bandwidth typed by hand is kept');
    }

    public function testAutoValuesAreWrittenBack(): void
    {
        $map = $this->loadString("NODE a\n\tPOSITION 1 1\nNODE b\n\tPOSITION 9 9\nLINK x\n\tNODES a b\n\tBANDWIDTH auto\n\tWIDTH auto\n");
        $file = TESTS_OUTPUT . '/auto-written.conf';
        $map->WriteConfig($file);
        $written = file_get_contents($file);

        $this->assertStringContainsString("\tBANDWIDTH auto\n", $written);
        $this->assertStringContainsString("\tWIDTH auto\n", $written);
    }

    public function testWidthLegendIsDrawnWhenAsked(): void
    {
        $config = "WIDTH 300\nHEIGHT 200\nSET link_width_table 1G:2 10G:6\n%sNODE a\n\tPOSITION 250 20\n";
        $pixels = [];
        foreach (['', "SET width_legend 10 -10\n"] as $hint) {
            $map = $this->loadString(sprintf($config, $hint));
            $png = TESTS_OUTPUT . '/width-legend-' . md5($hint) . '.png';
            $map->DrawMap($png);
            $image = imagecreatefrompng($png);
            // middle of the 10G bar: second row, bottom left corner
            $pixels[] = imagecolorat($image, 30, 200 - 10 - 12);
        }

        $this->assertNotSame($pixels[0], $pixels[1]);
        $this->assertSame(['1G', '10G'], array_column(wm_link_width_steps('10G:6 1G:2'), 2));
    }

    private function registerSpeedPlugin(\WeatherMap $map): void
    {
        $class = SpeedTestDataSource::class;
        $map->datasourceclasses[$class] = $class;
        $map->activedatasourceclasses[$class] = 1;
        $map->plugins['data'][$class] = new SpeedTestDataSource();
    }

    private function load(string $file): \WeatherMap
    {
        $map = new \WeatherMap();
        $map->context = 'cli';
        $map->ReadConfig($file);

        return $map;
    }

    private function loadString(string $config): \WeatherMap
    {
        $file = TESTS_OUTPUT . '/' . md5($config) . '.conf';
        file_put_contents($file, $config);

        return $this->load($file);
    }
}

/**
 * TARGET speedtest:<speed>:<rate> reports <rate> in both directions on an
 * interface of <speed>.
 */
class SpeedTestDataSource extends \WeatherMapDataSource
{
    public function Init(&$map)
    {
        return true;
    }

    public function Recognise($targetstring)
    {
        return preg_match('/^speedtest:/', $targetstring) === 1;
    }

    public function ReadData($targetstring, &$map, &$item)
    {
        [, , $rate] = explode(':', $targetstring);

        return [unformat_number($rate), unformat_number($rate), time()];
    }

    public function ReadSpeed($targetstring, &$map, &$item)
    {
        [, $speed] = explode(':', $targetstring);

        return unformat_number($speed);
    }
}
