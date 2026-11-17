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

    /** The older keywords are the same map items, drawn as the same cards, and stay in the .conf */
    public function testOlderKeywordsAreReadAsMapItems(): void
    {
        $map = new \WeatherMap();
        ob_start();
        $map->ReadConfig(implode("\n", [
            'WIDTH 600', 'HEIGHT 400',
            'KEYPOS DEFAULT 20 30 Load', 'KEYSTYLE DEFAULT modern 250',
            'KEYPOS other 300 30 Other', 'KEYSTYLE other tags',
            'SCALE other 0 50 0 255 0 low', 'SCALE other 50 100 255 0 0 high',
            'TIMEPOS 100 380 At %H:%M',
            'SET width_legend 10 -10', 'SET width_legend_title Speeds', 'SET link_width_table 1G:2 10G:6',
            'SET key_hidezero_DEFAULT 1',
        ]) . "\n");
        ob_end_clean();

        $legend = \WeatherMapBoxes::fromLegend($map, 'DEFAULT');
        $this->assertSame(['legend', 20, 30, 'Load', 'bar', 250, 'LEGEND:DEFAULT'], [$legend['type'], $legend['x'], $legend['y'], $legend['title'], $legend['style'], $legend['width'], $legend['area']]);
        $other = \WeatherMapBoxes::fromLegend($map, 'other');
        $this->assertSame(['list', true], [$other['style'], $other['tags']], 'the other styles give the list');
        $this->assertSame(['low', 'high'], array_column(\WeatherMapBoxes::scaleBands($map, 'other', true), 2), 'KEYSTYLE tags: the tags of the scale');
        $this->assertNotContains('0%', array_column(\WeatherMapBoxes::scaleBands($map, 'DEFAULT'), 2), 'key_hidezero leaves out the 0 to 0 band');

        $speed = \WeatherMapBoxes::fromWidthLegend($map);
        $this->assertSame(['speed', 10, -10, 'Speeds', 'WIDTHLEGEND'], [$speed['type'], $speed['x'], $speed['y'], $speed['title'], $speed['area']]);

        $svg = $this->svg($map);
        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($svg));
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('s', 'http://www.w3.org/2000/svg');
        foreach (['legend' => 'DEFAULT', 'legend ' => 'other', 'widthlegend' => 'widthlegend', 'timestamp' => ''] as $kind => $name) {
            $this->assertSame(1, $xpath->query('//s:g[@data-kind="' . trim($kind) . '"][@data-name="' . $name . '"]/s:polygon')->length, "$kind $name is a card");
        }
        $this->assertSame(['Other', 'low', 'high'], array_map(fn ($n) => $n->textContent, iterator_to_array($xpath->query('//s:g[@data-kind="legend"][@data-name="other"]/s:text'))));
        $this->assertStringStartsWith('At ', $xpath->query('//s:g[@data-kind="timestamp"]/s:text')->item(0)->textContent);

        $areas = [];
        foreach ($map->imap->shapes as $area) {
            if ($area instanceof \HTML_ImageMap_Area_Rectangle) {
                $areas[(string) $area->name] = [(int) $area->x1, (int) $area->y1, (int) $area->x2, (int) $area->y2];
            }
        }
        $this->assertSame([20, 30], array_slice($areas['LEGEND:DEFAULT'], 0, 2), 'the card at KEYPOS');
        $this->assertSame([100, 380], \WeatherMapBoxes::timestampAnchor($areas['TIMESTAMP']), 'the text of the timestamp card starts at TIMEPOS');
        $this->assertSame(390, $areas['WIDTHLEGEND'][3], 'negative positions count from the far edge');
    }

    public function testCardsHaveASmallNoteLineAndStayOnTheMap(): void
    {
        $map = $this->load(implode("\n", [
            'SET box_1 legend 790 10',
            'SET box_1_note Data: Observium\nDemo',
        ]));
        $this->assertSame("Data: Observium\nDemo", \WeatherMapBoxes::all($map)['1']['note']);
        $svg = $this->svg($map);
        $this->assertStringContainsString('>Data: Observium<', $svg);
        $this->assertStringContainsString('>Demo<', $svg);

        $areas = [];
        foreach ($map->imap->shapes as $area) {
            if ($area instanceof \HTML_ImageMap_Area_Rectangle) {
                $areas[(string) $area->name] = [(int) $area->x1, (int) $area->y1, (int) $area->x2, (int) $area->y2];
            }
        }
        $this->assertSame((int) $map->width, $areas['BOX:1'][2], 'a card placed past the edge is drawn inside the map');
    }
}
