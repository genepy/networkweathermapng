<?php

declare(strict_types=1);

namespace Tests\App;

use App\Core\Config;
use App\Core\Database;
use App\Services\MapService;
use App\Services\MapSettings;
use PHPUnit\Framework\TestCase;

/**
 * The Map settings panel (UI redesign step 5): the settings of one map read
 * and written together, maps whose drawn title differs from their name,
 * drag and drop order on the Maps page.
 */
final class MapSettingsTest extends TestCase
{
    private string $data;
    private Config $config;
    private Database $db;
    private MapService $maps;

    protected function setUp(): void
    {
        $this->data = TESTS_OUTPUT . '/mapsettings';
        self::wipe($this->data);
        mkdir($this->data, 0775, true);

        $this->config = new Config();
        $this->config->set('APP_ROOT', dirname(TESTS_ROOT) . '/src');
        $this->config->set('DATA_ROOT', $this->data);
        $this->config->set('DB_TYPE', 'sqlite');
        $this->config->set('DB_PATH', $this->data . '/test.db');
        $this->config->set('APP_TIMEZONE', 'Europe/Paris');
        $this->config->set('AUTH_ENABLED', 'true');
        $this->db = new Database($this->config);
        $this->maps = new MapService($this->db, $this->config);
    }

    public function testTitleConflicts(): void
    {
        $same = $this->maps->createMap(['name' => 'Core']);
        $other = $this->maps->createMap(['name' => 'Edge']);
        // an older map: its name was changed without its TITLE
        $this->db->update('maps', ['name' => 'Edge routers'], 'id = ?', [$other]);

        $all = $this->maps->getAllMaps();
        $this->assertSame([$other => 'Edge'], $this->maps->titleConflicts($all));
        $this->assertNull($this->maps->titleConflict($this->maps->getMap($same)));

        // keeping the drawn title: the name follows, the conflict is gone
        $this->maps->renameMap($other, 'Edge');
        $this->assertSame([], $this->maps->titleConflicts($this->maps->getAllMaps()));
    }

    public function testReorderKeepsThePositionsOfTheMapsMoved(): void
    {
        $a = $this->maps->createMap(['name' => 'A']);
        $b = $this->maps->createMap(['name' => 'B']);
        $c = $this->maps->createMap(['name' => 'C']);
        $d = $this->maps->createMap(['name' => 'D']);
        $order = fn () => array_map('intval', array_column($this->maps->getAllMaps(), 'id'));
        $this->assertSame([$a, $b, $c, $d], $order());

        // C before A, among A and C only: B and D stay where they are
        $this->assertSame(2, $this->maps->reorder([$c, $a]));
        $this->assertSame([$c, $b, $a, $d], $order());
        $this->assertSame(0, $this->maps->reorder([$c, $a]), 'same order: nothing written');

        // maps sharing one position get distinct ones
        $this->db->execute('UPDATE maps SET sort_order = 5');
        $this->maps->reorder([$d, $c, $b, $a]);
        $this->assertSame([$d, $c, $b, $a], $order());

        $this->assertSame(0, $this->maps->reorder([$a]));
        $this->expectException(\InvalidArgumentException::class);
        $this->maps->reorder([$a, 999]);
    }

    public function testDisplayAndRecording(): void
    {
        $id = $this->maps->createMap(['name' => 'Core']);
        $settings = new MapSettings($this->db, $this->config);

        $s = $settings->load($id);
        $this->assertSame('', $s['timezone']);
        $this->assertSame('Europe/Paris', $s['defaultZone']);
        $this->assertSame(0, $s['kioskZoom']);
        $this->assertNull($s['kioskDuration']);
        $this->assertFalse($s['recorded']);

        $settings->saveDisplay($id, 'America/New_York', 150, 60);
        $this->assertTrue($settings->saveRecording($id, true));
        $this->assertFalse($settings->saveRecording($id, true), 'unchanged');
        $s = $settings->load($id);
        $this->assertSame('America/New_York', $s['timezone']);
        $this->assertSame(150, $s['kioskZoom']);
        $this->assertSame(60, $s['kioskDuration']);
        $this->assertTrue($s['recorded']);

        // back to the defaults
        $settings->saveDisplay($id, '', 0, null);
        $s = $settings->load($id);
        $this->assertSame('', $s['timezone']);
        $this->assertSame(0, $s['kioskZoom']);
        $this->assertNull($s['kioskDuration']);

        $this->expectException(\InvalidArgumentException::class);
        $settings->saveDisplay($id, 'Mars/Olympus', 0, null);
    }

    public function testRecordingStaysOffWhileNotAllowed(): void
    {
        $id = $this->maps->createMap(['name' => 'Core']);
        $recording = new \App\Services\RecordingService($this->db, $this->config);
        $recording->setAllowed(false);
        $settings = new MapSettings($this->db, $this->config);

        $this->assertFalse($settings->saveRecording($id, true));
        $this->assertFalse($settings->load($id)['recorded']);
        $this->assertFalse($settings->load($id)['recordingAllowed']);
    }

    public function testAudience(): void
    {
        $group = (int) $this->db->insert('map_groups', ['name' => 'Paris', 'sort_order' => 2]);
        $id = $this->maps->createMap(['name' => 'Core', 'group_id' => $group]);
        $user = fn (string $name, string $role, int $active = 1) => (int) $this->db->insert('users', [
            'username' => $name, 'password_hash' => 'x', 'role' => $role, 'active' => $active,
        ]);
        $user('zoe', 'editor');
        $ann = $user('ann', 'viewer');
        $bob = $user('bob', 'viewer');
        $gone = $user('gone', 'viewer', 0);
        foreach ([$ann, $gone] as $u) {
            $this->db->insert('user_group_permissions', ['user_id' => $u, 'group_id' => $group]);
        }
        $this->db->insert('user_map_permissions', ['user_id' => $bob, 'map_id' => $id]);

        $audience = (new MapSettings($this->db, $this->config))->audience($this->maps->getMap($id));
        $this->assertFalse($audience['open']);
        $this->assertTrue($audience['active']);
        $this->assertFalse($audience['public']);
        $this->assertContains('zoe', $audience['staff']);
        $this->assertNotContains('ann', $audience['staff']);
        $this->assertSame(['ann'], $audience['group']);
        $this->assertSame(['bob'], $audience['direct']);

        $this->config->set('AUTH_ENABLED', 'false');
        $this->assertTrue((new MapSettings($this->db, $this->config))->audience($this->maps->getMap($id))['open']);
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
