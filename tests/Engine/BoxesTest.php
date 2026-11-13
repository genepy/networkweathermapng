<?php

declare(strict_types=1);

namespace Tests\Engine;

use PHPUnit\Framework\TestCase;

/** Boxes on the map: SET box_<id> ... (WeatherMapBoxes) */
final class BoxesTest extends TestCase
{
    private function load(string $extra): \WeatherMap
    {
        $conf = (string) file_get_contents(TESTS_ROOT . '/fixtures/maps/basic.conf');
        // map hints go before the first NODE
        $conf = preg_replace('/^((?:NODE|LINK) )/m', $extra . "\n\n$1", $conf, 1);
        $map = new \WeatherMap();
        ob_start();
        $map->ReadConfig($conf);
        ob_end_clean();
        return $map;
    }

    private function svg(\WeatherMap $map): string
    {
        $cwd = getcwd();
        chdir(TESTS_ROOT . '/../src/public');
        try {
            return \WeatherMapSvg::render($map);
        } finally {
            chdir((string) $cwd);
        }
    }

    public function testBoxesAreReadFromHints(): void
    {
        $map = $this->load(implode("\n", [
            'SET box_2 legend -10 -10',
            'SET box_2_style list',
            'SET box_1 text 300 10',
            'SET box_1_title Points of presence',
            'SET box_1_text Amsterdam | 68\nParis \\\\ Lyon',
            'SET box_1_rows sites',
            'SET box_3 bogus 1 1',
            'SET box_4 total 5 5',
            'SET box_4_title -',
            'SET box_4_style nonsense',
        ]));

        $boxes = \WeatherMapBoxes::all($map);
        $this->assertSame(['1', '2', '4'], array_map('strval', array_keys($boxes)), 'an unknown type is left out, ids in order');
        $this->assertSame('Points of presence', $boxes['1']['title']);
        $this->assertSame("Amsterdam | 68\nParis \\ Lyon", $boxes['1']['text']);
        $this->assertSame('sites', $boxes['1']['rows']);
        $this->assertSame(['list', -10, -10], [$boxes['2']['style'], $boxes['2']['x'], $boxes['2']['y']]);
        $this->assertSame('Traffic', $boxes['2']['title'], 'the default title');
        $this->assertSame('', $boxes['4']['title'], '"-" is no title');
        $this->assertSame('graph', $boxes['4']['style'], 'an unknown style falls back to the default');
    }

    public function testEncodeAndDecodeKeepLinesAndBackslashes(): void
    {
        $text = "C:\\maps | 1\nsecond";
        $this->assertSame('C:\\\\maps | 1\nsecond', \WeatherMapBoxes::encode($text));
        $this->assertSame($text, \WeatherMapBoxes::decode(\WeatherMapBoxes::encode($text)));
    }

    public function testBoxesAreDrawnInTheSvgAndTheImageMap(): void
    {
        $map = $this->load(implode("\n", [
            'SET box_1 text 300 10',
            'SET box_1_title Points of presence',
            'SET box_1_text Amsterdam | 68',
            'SET box_2 total 10 10',
            'SET box_3 legend -10 -10',
            'SET box_4 speed 10 200',
            'SET box_5 updated 10 400',
        ]));
        $map->box_series = [[0, 1e9, 5e8], [300, 2e9, 1e9]];
        $svg = $this->svg($map);

        foreach (['1', '2', '3', '4', '5'] as $id) {
            $this->assertStringContainsString('data-kind="box" data-name="' . $id . '" data-id="BOX:' . $id . '"', $svg);
        }
        $this->assertStringContainsString('>Points of presence<', $svg);
        $this->assertStringContainsString('>Amsterdam<', $svg);
        $this->assertStringContainsString('>68<', $svg);
        $this->assertStringContainsString('>Total traffic<', $svg);
        $this->assertStringContainsString('>100%<', $svg, 'the legend bar ends at 100%');
        $this->assertStringContainsString('>10G<', $svg, 'the link speed rows');

        $areas = [];
        foreach ($map->imap->shapes as $area) {
            if (!$area instanceof \HTML_ImageMap_Area_Rectangle) {
                continue;
            }
            $areas[(string) $area->name] = [(int) $area->x1, (int) $area->y1, (int) $area->x2, (int) $area->y2];
        }
        $this->assertSame([300, 10], array_slice($areas['BOX:1'], 0, 2));
        $legend = $areas['BOX:3'];
        $this->assertSame([$map->width - 10, $map->height - 10], [$legend[2], $legend[3]], 'negative positions count from the far edges');
    }

    public function testTotalSumsTheLinksThatCount(): void
    {
        $map = $this->load('SET box_1 total 10 10');
        $names = array_values(array_filter(array_keys($map->links), fn ($n) => $n !== 'DEFAULT'));
        foreach ($names as $i => $name) {
            $map->links[$name]->bandwidth_in = 1000 * ($i + 1);
            $map->links[$name]->bandwidth_out = 10;
        }
        $map->links[$names[0]]->hints['traffic_total'] = '0';
        [$in, $out] = \WeatherMapBoxes::trafficTotal($map);
        $expected = 0;
        foreach (array_slice($names, 1, null, true) as $i => $name) {
            $expected += 1000 * ($i + 1);
        }
        $this->assertEquals($expected, $in);
        $this->assertEquals(10 * (count($names) - 1), $out);
        $this->assertSame('40.7 Gbps', \WeatherMapBoxes::rate(40.7e9, 1000));
    }

    public function testSiteRowsCountNodesOrTakeTheBusiestLink(): void
    {
        $map = $this->load('SET box_1 text 10 10');
        $nodes = array_values(array_filter(array_keys($map->nodes), fn ($n) => $n !== 'DEFAULT' && $map->nodes[$n]->x !== null));
        $this->assertGreaterThanOrEqual(3, count($nodes));
        $map->nodes[$nodes[0]]->hints['site'] = 'Paris';
        $map->nodes[$nodes[1]]->hints['site'] = 'Paris';
        $map->nodes[$nodes[2]]->hints['site'] = 'Lyon';

        $this->assertSame([['Paris', '2'], ['Lyon', '1']], \WeatherMapBoxes::siteRows($map, 'sites'));

        foreach ($map->links as $link) {
            $link->inpercent = 0;
            $link->outpercent = 0;
            if (isset($link->a, $link->b) && ($link->a->name === $nodes[2] || $link->b->name === $nodes[2])) {
                $link->inpercent = 75;
            }
        }
        $rows = \WeatherMapBoxes::siteRows($map, 'sites_load');
        $this->assertSame(['Lyon', '75%'], $rows[0]);
    }
}
