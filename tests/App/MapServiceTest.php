<?php

declare(strict_types=1);

namespace Tests\App;

use App\Core\Config;
use App\Core\Database;
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
