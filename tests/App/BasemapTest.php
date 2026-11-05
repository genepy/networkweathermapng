<?php

declare(strict_types=1);

namespace Tests\App;

use App\Core\Application;
use App\Core\Config;
use App\Services\Basemap;
use App\Services\Editor\MapEditor;
use App\Services\MapService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The geographic basemap: the projection, nodes placed by SET geo, the
 * allowed tile servers and the editor operations
 * (docs/studies/geographic-basemap.md).
 */
final class BasemapTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = TESTS_OUTPUT . '/basemap-' . $this->name();
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0777, true);
        }
    }

    private function mapFile(string $body): string
    {
        $file = $this->dir . '/map.conf';
        file_put_contents($file, "WIDTH 800\nHEIGHT 600\n"
            . "SET basemap https://tiles.openfreemap.org/styles/positron\n"
            . "SET basemap_center 46.6 2.4\nSET basemap_zoom 5\n\n" . $body);
        return $file;
    }

    private function read(string $file): \WeatherMap
    {
        $map = new \WeatherMap();
        $map->context = 'editor';
        ob_start();
        $map->ReadConfig($file);
        ob_end_clean();
        return $map;
    }

    public function testProjectionRoundTrips(): void
    {
        $frame = [46.6, 2.4, 5.0];
        // the centre of the frame is the centre of the canvas
        $this->assertEqualsWithDelta([400, 300], \WeatherMapGeo::project($frame, 800, 600, 46.6, 2.4), 1e-9);
        [$x, $y] = \WeatherMapGeo::project($frame, 800, 600, 48.8566, 2.3522);
        $this->assertLessThan(300, $y, 'Paris is north of the centre');
        $this->assertEqualsWithDelta([48.8566, 2.3522], \WeatherMapGeo::unproject($frame, 800, 600, $x, $y), 1e-9);
        // one zoom level more doubles the distances
        [$x2, $y2] = \WeatherMapGeo::project([46.6, 2.4, 6.0], 800, 600, 48.8566, 2.3522);
        $this->assertEqualsWithDelta(2 * ($y - 300), $y2 - 300, 1e-6);
        // 512-pixel world tiles: at zoom 0 the whole world is 512 pixels wide
        [$xw] = \WeatherMapGeo::project([0, 0, 0], 512, 512, 0, 180);
        $this->assertEqualsWithDelta(512, $xw, 1e-9);
    }

    public function testLatLonParsing(): void
    {
        $this->assertSame([48.85, 2.35], \WeatherMapGeo::latLon('48.85 2.35'));
        $this->assertSame([48.85, -2.35], \WeatherMapGeo::latLon('48.85, -2.35'));
        $this->assertNull(\WeatherMapGeo::latLon('91 0'));
        $this->assertNull(\WeatherMapGeo::latLon('paris'));
        $this->assertSame('48.8566 -2.3', \WeatherMapGeo::format(48.85660001, -2.3));
    }

    public function testNodesWithAPointArePlacedOnIt(): void
    {
        $map = $this->read($this->mapFile("NODE paris\n\tPOSITION 10 10\n\tSET geo 48.8566 2.3522\n\n"
            . "NODE child\n\tPOSITION paris 20 0\n\nNODE free\n\tPOSITION 100 100\n"));

        $frame = \WeatherMapGeo::frame($map);
        [$x, $y] = \WeatherMapGeo::project($frame, 800, 600, 48.8566, 2.3522);
        $this->assertSame([(int) round($x), (int) round($y)], [$map->nodes['paris']->x, $map->nodes['paris']->y]);
        // a node placed relative to it follows it
        $this->assertSame([$map->nodes['paris']->x + 20, $map->nodes['paris']->y], [$map->nodes['child']->x, $map->nodes['child']->y]);
        // a node without a point keeps its position
        $this->assertEquals([100, 100], [$map->nodes['free']->x, $map->nodes['free']->y]);
    }

    public function testWithoutAFrameNodesKeepTheirPosition(): void
    {
        $file = $this->dir . '/map.conf';
        file_put_contents($file, "WIDTH 800\nHEIGHT 600\nSET basemap_center 46.6 2.4\nSET basemap_zoom 5\n\n"
            . "NODE paris\n\tPOSITION 10 10\n\tSET geo 48.8566 2.3522\n");
        $map = $this->read($file);
        $this->assertNull(\WeatherMapGeo::frame($map), 'no basemap address, no frame');
        $this->assertEquals([10, 10], [$map->nodes['paris']->x, $map->nodes['paris']->y]);
    }

    public function testAllowedServers(): void
    {
        $servers = ['https://tiles.openfreemap.org', 'https://tiles.example.net/osm'];
        $this->assertTrue(Basemap::allowed('https://tiles.openfreemap.org/styles/dark', $servers));
        $this->assertTrue(Basemap::allowed('https://tiles.example.net/osm/{z}/{x}/{y}.png', $servers));
        $this->assertTrue(Basemap::allowed('/tiles/style.json', $servers), 'the site itself');
        $this->assertFalse(Basemap::allowed('//evil.example/style.json', $servers));
        $this->assertFalse(Basemap::allowed('https://tiles.openfreemap.org.evil.example/x', $servers));
        $this->assertFalse(Basemap::allowed('https://tiles.example.net/osmosis/x', $servers));
        $this->assertFalse(Basemap::allowed('javascript:alert(1)', $servers));
        $this->assertFalse(Basemap::allowed('https://tiles.openfreemap.org/a b', $servers));

        $config = new Config();
        $config->set('BASEMAP_SERVERS', 'https://a.example/tiles/, https://b.example  ftp://c.example');
        $this->assertSame(['https://a.example/tiles', 'https://b.example'], Basemap::servers($config));
        $this->assertSame(['https://a.example', 'https://b.example'], Basemap::origins($config));
    }

    public function testTheDefaultPolicyAllowsTheTileServers(): void
    {
        $csp = Application::defaultCsp(['https://tiles.openfreemap.org']);
        $this->assertStringContainsString("img-src 'self' data: blob: https://tiles.openfreemap.org;", $csp);
        $this->assertStringContainsString("connect-src 'self' https://tiles.openfreemap.org;", $csp);
        $this->assertSame(Application::DEFAULT_CSP, Application::defaultCsp([]));
    }

    public function testTheSummaryGivesTheBasemapToTheViewer(): void
    {
        $map = $this->read($this->mapFile("SET basemap_dark https://tiles.openfreemap.org/styles/dark\n"));
        $b = Basemap::of($map, ['https://tiles.openfreemap.org']);
        $this->assertSame([
            'url' => 'https://tiles.openfreemap.org/styles/positron',
            'dark' => 'https://tiles.openfreemap.org/styles/dark',
            'kind' => 'style',
            'center' => [46.6, 2.4],
            'zoom' => 5.0,
            'attribution' => null,
        ], $b);
        $this->assertNull(Basemap::of($map, ['https://other.example']), 'a server that is not allowed');

        $map->hints['basemap'] = '/tiles/{z}/{x}/{y}.png';
        $b = Basemap::of($map, []);
        $this->assertSame('raster', $b['kind']);
        $this->assertNull($b['dark'], 'a dark style cannot pair with raster tiles');
        $this->assertSame(Basemap::RASTER_ATTRIBUTION, $b['attribution']);

        $this->assertArrayHasKey('basemap', MapService::summarize($map));
    }

    public function testTheSummaryGivesThePointOfLocatedNodes(): void
    {
        $body = "NODE paris\n\tPOSITION 100 100\n\tSET geo 48.8566 2.3522\n\tSET site Paris\n\n"
            . "NODE title\n\tPOSITION 50 50\n\tLABEL Title\n\tINFOURL https://example.net/\n\n";
        $items = MapService::summarize($this->read($this->mapFile($body)))['items'];
        $nodes = [];
        foreach ($items as $item) {
            if ($item['kind'] === 'node') {
                $nodes[$item['name']] = $item;
            }
        }
        $this->assertSame([48.8566, 2.3522], $nodes['paris']['geo'], 'the viewer merges close sites by their point');
        $this->assertSame('Paris', $nodes['paris']['site']);
        $this->assertArrayNotHasKey('geo', $nodes['title'], 'a node without a point stays on the canvas');

        // without a basemap, no point: nothing to merge or show in a city inset
        $plain = $this->dir . '/plain.conf';
        file_put_contents($plain, "WIDTH 800\nHEIGHT 600\n\n" . $body);
        foreach (MapService::summarize($this->read($plain))['items'] as $item) {
            $this->assertArrayNotHasKey('geo', $item);
        }
    }

    public function testEditorSetsTheBasemapAndLocatesNodes(): void
    {
        $file = $this->dir . '/map.conf';
        copy(TESTS_ROOT . '/fixtures/maps/basic.conf', $file);
        $editor = new MapEditor($file);

        $editor->apply([['op' => 'set_map', 'props' => [
            'basemap' => 'https://tiles.openfreemap.org/styles/positron',
            'basemap_center' => '46.6, 2.4',
            'basemap_zoom' => '5.25',
        ]]]);
        $map = $editor->load();
        $this->assertSame('46.6 2.4', $map->hints['basemap_center']);
        $this->assertSame('5.25', $map->hints['basemap_zoom']);

        // locate a node: it moves onto its point
        $editor->apply([['op' => 'set_node', 'names' => ['paris'], 'props' => ['geo' => '48.8566 2.3522']]]);
        $map = $editor->load();
        $frame = \WeatherMapGeo::frame($map);
        [$x, $y] = \WeatherMapGeo::project($frame, 800, 500, 48.8566, 2.3522);
        $this->assertSame([(int) round($x), (int) round($y)], [$map->nodes['paris']->x, $map->nodes['paris']->y]);
        $this->assertStringContainsString("POSITION " . (int) round($x) . ' ' . (int) round($y), (string) file_get_contents($file));

        // moving it moves its point
        $editor->apply([['op' => 'move', 'nodes' => ['paris' => [300, 200]]]]);
        $map = $editor->load();
        $this->assertSame([300, 200], [$map->nodes['paris']->x, $map->nodes['paris']->y]);
        $p = \WeatherMapGeo::nodePoint($map->nodes['paris']);
        $this->assertEqualsWithDelta(\WeatherMapGeo::unproject($frame, 800, 500, 300, 200), $p, 1e-5);

        // a new zoom keeps it on its point, so it moves on the canvas
        $editor->apply([['op' => 'set_map', 'props' => ['basemap_zoom' => '6.25']]]);
        $map = $editor->load();
        $this->assertSame([(int) round(400 + (300 - 400) * 2), (int) round(250 + (200 - 250) * 2)], [$map->nodes['paris']->x, $map->nodes['paris']->y]);

        // a node added on the basemap is located where it is put; a note is not
        $node = $editor->apply([['op' => 'add_node', 'x' => 120, 'y' => 80]])['created']['nodes'][0];
        $note = $editor->apply([['op' => 'add_node', 'x' => 50, 'y' => 50, 'note' => true]])['created']['nodes'][0];
        $map = $editor->load();
        $this->assertNotNull(\WeatherMapGeo::nodePoint($map->nodes[$node]));
        $this->assertSame([120, 80], [$map->nodes[$node]->x, $map->nodes[$node]->y]);
        $this->assertNull(\WeatherMapGeo::nodePoint($map->nodes[$note]));

        // a clone gets the point of where it lands, not the one of its source
        $result = $editor->apply([['op' => 'clone', 'nodes' => ['paris'], 'dx' => 40, 'dy' => 0]]);
        $map = $editor->load();
        $copy = $map->nodes[$result['created']['nodes'][0] ?? 'paris_copy'];
        $this->assertSame($map->nodes['paris']->x + 40, $copy->x);

        // removing the location leaves the node where it is
        $editor->apply([['op' => 'set_node', 'names' => ['paris'], 'props' => ['geo' => '']]]);
        $map = $editor->load();
        $this->assertNull(\WeatherMapGeo::nodePoint($map->nodes['paris']));

        // no basemap address: the frame is gone, nodes stay where they are
        $before = [$map->nodes['paris']->x, $map->nodes['lyon']->x];
        $editor->apply([['op' => 'set_map', 'props' => ['basemap' => '']]]);
        $map = $editor->load();
        $this->assertArrayNotHasKey('basemap', $map->hints);
        $this->assertSame($before, [$map->nodes['paris']->x, $map->nodes['lyon']->x]);
    }

    public function testEditorRefusesWhatTheBrowserMayNotLoad(): void
    {
        $file = $this->dir . '/map.conf';
        copy(TESTS_ROOT . '/fixtures/maps/basic.conf', $file);
        $editor = new MapEditor($file);

        foreach ([
            ['basemap' => 'https://tiles.example.com/style.json'],
            ['basemap_center' => '100 2'],
            ['basemap_zoom' => '30'],
        ] as $props) {
            try {
                $editor->apply([['op' => 'set_map', 'props' => $props]]);
                $this->fail('accepted ' . json_encode($props));
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $editor->apply([['op' => 'set_node', 'names' => ['paris'], 'props' => ['geo' => 'somewhere']]]);
    }
}
