<?php

declare(strict_types=1);

namespace Tests\App;

use App\Core\Config;
use App\Core\Database;
use App\Services\MapService;
use PHPUnit\Framework\TestCase;

/**
 * The map viewer's server side: SVG and state summary written with the
 * PNG, when they are drawn again, map fonts, favourites.
 */
final class ViewerTest extends TestCase
{
    private string $data;
    private Config $config;
    private Database $db;
    private MapService $maps;

    protected function setUp(): void
    {
        $this->data = TESTS_OUTPUT . '/viewer';
        self::wipe($this->data);
        mkdir($this->data, 0775, true);

        $this->config = new Config();
        $this->config->set('APP_ROOT', dirname(TESTS_ROOT) . '/src');
        $this->config->set('DATA_ROOT', $this->data);
        $this->config->set('DB_TYPE', 'sqlite');
        $this->config->set('DB_PATH', $this->data . '/test.db');
        $this->config->set('MAP_REFRESH_INTERVAL', '300');
        $this->db = new Database($this->config);
        $this->maps = new MapService($this->db, $this->config);
    }

    private function createMap(string $extra = ''): int
    {
        $font = dirname(TESTS_ROOT) . '/src/public/fonts/DejaVuSansMono.ttf';
        $id = $this->maps->createMap(['name' => 'Viewer test']);
        $this->maps->saveMapConfig($id, <<<CONF
WIDTH 400
HEIGHT 300
TITLE Viewer test
FONTDEFINE 20 {$font} 8
SCALE DEFAULT 0 50 0 255 0
SCALE DEFAULT 50 85 255 192 0
SCALE DEFAULT 85 100 255 0 0
LINK DEFAULT
	BANDWIDTH 100M
	BWFONT 20
NODE a
	POSITION 50 50
	INFOURL https://nms.example/node/a
NODE b
	POSITION 300 50
NODE c
	POSITION 300 250
LINK a-b
	NODES a b
	TARGET static:30M:87M
	INFOURL https://nms.example/port/{link:this:name}
	SET datasource {"sourceId":1,"hostId":"10","inKey":"i","outKey":"o"}
LINK b-c
	NODES b c
	TARGET static:10M:5M
{$extra}
CONF);
        return $id;
    }

    public function testOnlyWebLinksAreKept(): void
    {
        foreach (['https://noc.example/port/1', 'http://x/', '/graphs/1.png', 'port.php?id=1', '//cdn.example/a.png'] as $ok) {
            $this->assertSame($ok, MapService::safeUrl($ok));
        }
        foreach (['javascript:alert(1)', ' JavaScript:alert(1)', "java\tscript:alert(1)", 'data:text/html,<script>x</script>',
            'vbscript:x', 'file:///etc/passwd', '\\\\evil\\x', ''] as $bad) {
            $this->assertNull(MapService::safeUrl($bad), $bad);
        }
    }

    public function testFreshness(): void
    {
        $this->assertFalse(MapService::isFresh(null, 100, 200, 300), 'never drawn');
        $this->assertTrue(MapService::isFresh(150, 100, 200, 300));
        $this->assertFalse(MapService::isFresh(90, 100, 200, 300), 'older than the configuration');
        $this->assertFalse(MapService::isFresh(150, 100, 500, 300), 'older than the refresh interval');
        $this->assertTrue(MapService::isFresh(150, 100, 5000, 0), 'no interval');
    }

    public function testRenderWritesSvgAndSummary(): void
    {
        $id = $this->createMap();
        ob_start();
        $result = $this->maps->renderMap($id, true, true);
        ob_end_clean();
        $this->assertArrayNotHasKey('error', (array) $result);

        $map = (array) $this->maps->getMap($id);
        $paths = $this->maps->viewerPaths($map);
        $this->assertFileExists($paths['svg']);
        $svg = (string) file_get_contents($paths['svg']);
        $this->assertStringContainsString('class="wm-link" data-kind="link" data-name="a-b"', $svg);
        $this->assertStringContainsString('wm-bwlabel-in', $svg);
        $this->assertStringContainsString('/map/' . $id . '/font/20', $svg, 'fonts come from the public route');

        $status = $this->maps->mapStatus($map);
        $this->assertNotNull($status);
        $this->assertEqualsWithDelta(87.0, $status['max'], 0.01);
        $this->assertSame('a-b', $status['link']);
        $this->assertSame('crit', $status['level']);
        $this->assertSame('#ff0000', $status['colour']);
        $this->assertFalse($status['stale']);

        $items = $status['items'];
        $ab = array_values(array_filter($items, fn ($i) => $i['name'] === 'a-b'))[0];
        $this->assertSame('https://nms.example/port/a-b', $ab['url']);
        $this->assertStringContainsString('"sourceId":1', $ab['ds']);
        $this->assertEqualsWithDelta(30.0, $ab['inpct'], 0.01);
        $this->assertEqualsWithDelta(30000000.0, $ab['in'], 1);
        $nodeA = array_values(array_filter($items, fn ($i) => $i['kind'] === 'node'));
        $this->assertCount(1, $nodeA, 'only nodes with a URL are listed');
        $this->assertSame('https://nms.example/node/a', $nodeA[0]['url']);

        // The PNG render without data writes no summary
        $statusTime = filemtime($paths['status']);
        ob_start();
        $this->maps->renderMap($id, true, false);
        ob_end_clean();
        $this->assertSame($statusTime, filemtime($paths['status']));

        // Deleting the map takes them away
        $this->maps->deleteMap($id);
        $this->assertFileDoesNotExist($paths['svg']);
        $this->assertFileDoesNotExist($paths['status']);
    }

    public function testLiveRenderIsCachedUntilTheConfigurationChanges(): void
    {
        $id = $this->createMap();
        $map = (array) $this->maps->getMap($id);
        $paths = $this->maps->viewerPaths($map);
        $config = $this->data . '/configs/' . $map['config_file'];

        $first = $this->maps->liveRender($id);
        $this->assertNotNull($first);
        $this->assertStringStartsWith('<svg', $first['svg']);
        $this->assertSame('crit', $first['summary']['level']);

        // pretend it was drawn a while ago (but after the configuration)
        touch($config, time() - 100);
        touch($paths['svg'], time() - 50);
        touch($paths['status'], time() - 50);
        clearstatcache();
        $this->maps->liveRender($id);
        clearstatcache();
        $this->assertSame(time() - 50, filemtime($paths['svg']), 'fresh enough: not drawn again');

        // an edit makes it stale
        touch($config, time() - 10);
        clearstatcache();
        $this->maps->liveRender($id);
        clearstatcache();
        $this->assertGreaterThanOrEqual(time() - 10, filemtime($paths['svg']));

        // and so does the refresh interval
        touch($config, time() - 1000);
        touch($paths['svg'], time() - 400);
        touch($paths['status'], time() - 400);
        clearstatcache();
        $this->assertTrue($this->maps->mapStatus($map)['stale']);
        $this->maps->liveRender($id);
        clearstatcache();
        $this->assertGreaterThan(time() - 400, filemtime($paths['svg']));
        $this->assertFalse($this->maps->mapStatus($map)['stale']);

        $this->assertNull($this->maps->liveRender(9999));
    }

    public function testLoadLevels(): void
    {
        $this->assertSame('none', MapService::loadLevel(null));
        $this->assertSame('ok', MapService::loadLevel(10.0));
        $this->assertSame('warn', MapService::loadLevel(55.0));
        $this->assertSame('crit', MapService::loadLevel(85.0));
    }

    public function testFontFile(): void
    {
        $id = $this->createMap();
        $this->assertSame(dirname(TESTS_ROOT) . '/src/public/fonts/DejaVuSansMono.ttf', $this->maps->fontFile($id, 20));
        $this->assertNull($this->maps->fontFile($id, 3), 'built-in GD font');
        $this->assertNull($this->maps->fontFile($id, 77), 'not defined');
        $this->assertNull($this->maps->fontFile(9999, 20), 'no such map');
    }

    public function testFavourites(): void
    {
        $one = $this->createMap();
        $two = $this->createMap();
        $three = $this->createMap();

        $this->assertSame([], $this->maps->favouriteMapIds(5));
        $this->maps->setFavourite(5, $three, true);
        $this->maps->setFavourite(5, $three, true);
        $this->maps->setFavourite(5, $one, true);
        $this->maps->setFavourite(6, $two, true);
        $ids = $this->maps->favouriteMapIds(5);
        sort($ids);
        $this->assertSame([$one, $three], $ids);
        $this->assertSame([$two], $this->maps->favouriteMapIds(6), 'per user');

        $this->maps->setFavourite(5, $one, false);
        $this->assertSame([$three], $this->maps->favouriteMapIds(5));

        $list = [['id' => $one], ['id' => $two], ['id' => $three]];
        $this->assertSame([$three, $one, $two], array_column(MapService::favouritesFirst($list, [$three]), 'id'));

        // global settings (map_id 0) are not touched, and a deleted map is forgotten
        $this->db->insert('settings', ['map_id' => 0, 'name' => 'favourite:5', 'value' => 'x']);
        $this->maps->deleteMap($three);
        $this->assertSame([], $this->maps->favouriteMapIds(5));
    }

    private static function wipe(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
