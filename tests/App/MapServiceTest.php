<?php

declare(strict_types=1);

namespace Tests\App;

use App\Core\Config;
use App\Core\Database;
use App\Services\Basemap;
use App\Services\MapService;
use App\Services\Migration\MigrationStore;
use PHPUnit\Framework\TestCase;

final class MapServiceTest extends TestCase
{
    private string $data;
    private Database $db;
    private MapService $maps;

    protected function setUp(): void
    {
        $this->data = TESTS_OUTPUT . '/mapservice';
        self::wipe($this->data);
        mkdir($this->data, 0775, true);

        $config = new Config();
        $config->set('APP_ROOT', dirname(TESTS_ROOT) . '/src');
        $config->set('DATA_ROOT', $this->data);
        $config->set('DB_TYPE', 'sqlite');
        $config->set('DB_PATH', $this->data . '/test.db');
        $this->db = new Database($config);
        $this->maps = new MapService($this->db, $config);
    }

    public function testCreateCleansTheName(): void
    {
        $id = $this->maps->createMap(['name' => "Core\nNODE evil", 'width' => 'x']);
        $map = $this->maps->getMap($id);

        $this->assertSame('Core NODE evil', $map['name']);
        $conf = (string) $this->maps->getMapConfig($id);
        $this->assertStringContainsString("TITLE Core NODE evil\n", $conf);
        $this->assertStringNotContainsString("\nNODE evil", $conf);
        $this->assertStringContainsString('WIDTH 100', $conf);

        $this->assertSame('reseau_coeur_paris.conf', $this->maps->getMap($this->maps->createMap(['name' => 'Réseau cœur Paris']))['config_file']);
    }

    public function testNewMapOnAWebMap(): void
    {
        // F31: New map > On a web map starts with the starter basemap
        $conf = (string) $this->maps->getMapConfig($this->maps->createMap(['name' => 'On tiles', 'basemap' => true]));
        foreach (Basemap::STARTER as $hint => $value) {
            $this->assertStringContainsString("SET {$hint} {$value}\n", $conf);
        }
        $this->assertTrue(Basemap::starterAllowed());

        $plain = (string) $this->maps->getMapConfig($this->maps->createMap(['name' => 'Plain']));
        $this->assertStringNotContainsString('SET basemap', $plain);
    }

    /** F44: a new map draws its title inside the picture, and no timestamp (the viewer's status bar has the time) */
    public function testNewMapDrawsItsTitleAndNoTimestamp(): void
    {
        $conf = (string) $this->maps->getMapConfig($this->maps->createMap(['name' => 'Audit test']));
        $this->assertStringContainsString("TIMEPOS -1 -1\n", $conf);

        $svg = $this->svgOf($conf);
        $title = $svg->query('//s:g[@data-kind="title"]/s:text')->item(0);
        $this->assertNotNull($title, 'the title is drawn');
        $this->assertSame('Audit test', $title->textContent);
        $this->assertGreaterThan(0, (float) $title->getAttribute('y'), 'inside the picture');
        $this->assertSame(0, $svg->query('//s:g[@data-kind="timestamp"]')->length, 'no timestamp');

        // hidden (what the editor writes): no title
        $hidden = $this->svgOf((string) preg_replace('/^TITLEPOS .*$/m', 'TITLEPOS -1 -1', $conf));
        $this->assertSame(0, $hidden->query('//s:g[@data-kind="title"]/s:text')->length, 'a hidden title is not drawn');
    }

    private function svgOf(string $conf): \DOMXPath
    {
        $file = $this->data . '/svg-of.conf';
        file_put_contents($file, $conf);
        $cwd = (string) getcwd();
        chdir(dirname(TESTS_ROOT) . '/src/public');
        try {
            $map = new \WeatherMap();
            ob_start();
            $map->ReadConfig($file);
            $map->ReadData();
            ob_end_clean();
            $doc = new \DOMDocument();
            $this->assertTrue($doc->loadXML(\WeatherMapSvg::render($map)));
        } finally {
            chdir($cwd);
        }
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('s', 'http://www.w3.org/2000/svg');
        return $xpath;
    }

    public function testRename(): void
    {
        $id = $this->maps->createMap(['name' => 'Old name']);
        $this->assertTrue($this->maps->renameMap($id, 'New name', true));

        $map = $this->maps->getMap($id);
        $this->assertSame('New name', $map['name']);
        $this->assertSame('new_name.conf', $map['config_file']);
        $this->assertFileDoesNotExist($this->data . '/configs/old_name.conf');
        $this->assertMatchesRegularExpression('/^TITLE New name$/m', (string) $this->maps->getMapConfig($id));

        // same name again: the file stays
        $this->maps->renameMap($id, 'New name', true);
        $this->assertSame('new_name.conf', $this->maps->getMap($id)['config_file']);
    }

    public function testNameFollowsAnEditedTitle(): void
    {
        $id = $this->maps->createMap(['name' => 'Core']);
        $before = (string) $this->maps->getMapConfig($id);

        // a save that leaves the TITLE alone keeps the name, even where they differ
        $this->db->update('maps', ['name' => 'Listed name', 'title_cache' => 'Listed name'], 'id = ?', [$id]);
        $this->assertNull($this->maps->followTitle($id, $before, $before . "\nNODE a\n"));
        $this->assertSame('Listed name', $this->maps->getMap($id)['name']);

        // a new title renames the map, the file name stays
        $after = MapService::withTitle($before, "Core\tParis");
        $this->assertSame('Core Paris', $this->maps->followTitle($id, $before, $after));
        $map = $this->maps->getMap($id);
        $this->assertSame('Core Paris', $map['name']);
        $this->assertSame('Core Paris', $map['title_cache']);
        $this->assertSame('core.conf', $map['config_file']);

        // an empty or removed title never empties the name
        $this->assertNull($this->maps->followTitle($id, $after, "WIDTH 100\n"));
        $this->assertNull($this->maps->followTitle($id, $after, "TITLE \nWIDTH 100\n"));
        $this->assertSame('Core Paris', $this->maps->getMap($id)['name']);

        $this->assertSame('A', MapService::titleOf("# x\n  title A\nTITLE B\n"));
        $this->assertNull(MapService::titleOf("WIDTH 10\n"));
    }

    public function testDuplicate(): void
    {
        $id = $this->maps->createMap(['name' => 'Source', 'group_id' => 1]);
        $this->maps->saveMapConfig($id, MapService::withTitle((string) $this->maps->getMapConfig($id), 'Source') . "\nNODE a\n\tPOSITION 1 1\n");

        $copy = $this->maps->duplicateMap($id, 'Copy of source', null, true);
        $map = $this->maps->getMap((int) $copy);

        $this->assertSame('Copy of source', $map['name']);
        $this->assertSame(1, (int) $map['active']);
        $conf = (string) $this->maps->getMapConfig((int) $copy);
        $this->assertStringContainsString('TITLE Copy of source', $conf);
        $this->assertStringContainsString('NODE a', $conf);
    }

    public function testDeleteRemovesEverything(): void
    {
        $id = $this->maps->createMap(['name' => 'Gone']);
        $keep = $this->maps->createMap(['name' => 'Gone too']);
        $base = pathinfo($this->maps->getMap($id)['config_file'], PATHINFO_FILENAME);
        $this->maps->saveMapConfig($id, (string) $this->maps->getMapConfig($id)); // makes the .bak

        $out = $this->data . '/output';
        foreach (['.png', '.thumb.png', '.html', '.fast.png', '.fast.thumb.png'] as $ext) {
            touch($out . '/' . $base . $ext);
        }
        touch($out . '/' . pathinfo($this->maps->getMap($keep)['config_file'], PATHINFO_FILENAME) . '.png');
        @mkdir($out . '/graphs', 0775, true);
        touch($out . '/graphs/map_' . $id . '_link_3_min_1440.png');
        touch($out . '/graphs/map_' . $id . '1_link_3_min_1440.png');
        mkdir($this->data . '/history/' . $id . '/undo', 0775, true);
        touch($this->data . '/history/' . $id . '/undo/1.conf');
        $this->db->insert('user_map_permissions', ['user_id' => 1, 'map_id' => $id]);
        $this->db->insert('settings', ['map_id' => $id, 'name' => 'x', 'value' => 'y']);

        $store = new MigrationStore($this->data . '/migration');
        $mig = $store->create('Gone', 'gone.conf', "NODE a\n");
        $store->update($mig, ['mapId' => $id, 'applied' => 'now']);

        $this->assertTrue($this->maps->deleteMap($id));

        $this->assertNull($this->maps->getMap($id));
        $this->assertSame([], glob($this->data . '/configs/' . $base . '.conf*'));
        $this->assertSame([], glob($out . '/' . $base . '.*'));
        $this->assertFileExists($out . '/graphs/map_' . $id . '1_link_3_min_1440.png');
        $this->assertFileDoesNotExist($out . '/graphs/map_' . $id . '_link_3_min_1440.png');
        $this->assertDirectoryDoesNotExist($this->data . '/history/' . $id);
        $this->assertNull($this->db->queryOne('SELECT * FROM user_map_permissions WHERE map_id = ?', [$id]));
        $this->assertNull($this->db->queryOne('SELECT * FROM settings WHERE map_id = ?', [$id]));
        $this->assertNull($store->get($mig)['mapId']);
        $this->assertNotNull($this->maps->getMap($keep));
    }

    public function testExportZip(): void
    {
        $id = $this->maps->createMap(['name' => 'Export']);
        $this->maps->saveMapConfig($id, (string) $this->maps->getMapConfig($id) . "\nNODE r\n\tICON objects/Router.png\n\tPOSITION 10 10\n");

        $zipFile = $this->data . '/export.zip';
        $this->assertSame(1, $this->maps->exportZip([$id], $zipFile));

        $zip = new \ZipArchive();
        $zip->open($zipFile);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $this->assertEqualsCanonicalizing(['configs/export.conf', 'images/objects/Router.png'], $names);
    }

    public function testWithTitle(): void
    {
        $this->assertSame("TITLE B\nWIDTH 1\n", MapService::withTitle("TITLE A\nWIDTH 1\n", 'B'));
        $this->assertSame("TITLE B\nWIDTH 1\n", MapService::withTitle("WIDTH 1\n", 'B'));
    }

    private static function wipe(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
