<?php

declare(strict_types=1);

namespace Tests\App;

use App\Core\Config;
use App\Core\Database;
use App\Services\SettingsNav;
use PHPUnit\Framework\TestCase;

/**
 * The Settings space (UI redesign step 6): side navigation, counters, the
 * page an address belongs to, and the "tested … ago" of a data source row.
 */
final class SettingsNavTest extends TestCase
{
    public function testEveryPageOfTheNavigationHasARoute(): void
    {
        $routes = (string) file_get_contents(dirname(TESTS_ROOT) . '/src/app/Core/Application.php');
        $keys = [];
        foreach (SettingsNav::groups() as [$label, $items]) {
            $this->assertNotSame('', $label);
            foreach ($items as [$key, $text, $href, $icon]) {
                $keys[] = $key;
                $this->assertStringContainsString("get('" . $href . "'", $routes, $href . ' has no GET route');
                $this->assertNotSame('', $text);
                $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $icon);
            }
        }
        $this->assertSame($keys, array_unique($keys));
        $this->assertSame('data-sources', SettingsNav::pageFor(SettingsNav::HOME));
    }

    public function testIconsAreInTheSprite(): void
    {
        $sprite = (string) file_get_contents(dirname(TESTS_ROOT) . '/src/public/assets/icons/ui.svg');
        foreach (SettingsNav::groups() as [, $items]) {
            foreach ($items as [, , , $icon]) {
                $this->assertStringContainsString('id="' . $icon . '"', $sprite, $icon . ' is not in ui.svg');
            }
        }
    }

    public function testPageForAnAddress(): void
    {
        $this->assertSame('data-sources', SettingsNav::pageFor('/admin/data-sources'));
        $this->assertSame('data-sources', SettingsNav::pageFor('/admin/data-sources/edit/3'));
        $this->assertSame('users', SettingsNav::pageFor('/admin/users/create'));
        $this->assertSame('migration', SettingsNav::pageFor('/admin/migration'));
        $this->assertSame('rendering', SettingsNav::pageFor('/admin/settings'));
        $this->assertSame('check-db', SettingsNav::pageFor('/admin/check-db'));
        // a prefix is not enough: /admin/usersX is not Users
        $this->assertNull(SettingsNav::pageFor('/admin/usersX'));
        $this->assertNull(SettingsNav::pageFor('/admin'));
    }

    public function testTestedAgo(): void
    {
        $now = strtotime('2026-11-05 12:00:00');
        $this->assertSame('never tested', SettingsNav::testedAgo(null, $now));
        $this->assertSame('never tested', SettingsNav::testedAgo('', $now));
        $this->assertSame('tested just now', SettingsNav::testedAgo('2026-11-05 11:59:30', $now));
        $this->assertSame('tested 2 min ago', SettingsNav::testedAgo('2026-11-05 11:58:00', $now));
        $this->assertSame('tested 3 h ago', SettingsNav::testedAgo('2026-11-05 08:30:00', $now));
        $this->assertSame('tested on 2026-11-01', SettingsNav::testedAgo('2026-11-01 10:00:00', $now));
        // a clock set back does not give a negative time
        $this->assertSame('tested just now', SettingsNav::testedAgo('2026-11-05 12:05:00', $now));
    }

    public function testCounts(): void
    {
        $data = TESTS_OUTPUT . '/settings-nav';
        @unlink($data . '/test.db');
        @mkdir($data, 0775, true);
        $config = new Config();
        $config->set('APP_ROOT', dirname(TESTS_ROOT) . '/src');
        $config->set('DATA_ROOT', $data);
        $config->set('DB_TYPE', 'sqlite');
        $config->set('DB_PATH', $data . '/test.db');
        $db = new Database($config);

        $before = SettingsNav::counts($db);
        $db->insert('data_sources', ['name' => 'Lab', 'type' => 'zabbix', 'url' => 'http://zabbix']);
        $db->insert('map_groups', ['name' => 'Core']);
        $after = SettingsNav::counts($db);

        $this->assertSame(['data-sources', 'users', 'groups'], array_keys($after));
        $this->assertSame($before['data-sources'] + 1, $after['data-sources']);
        $this->assertSame($before['groups'] + 1, $after['groups']);
        $this->assertSame($before['users'], $after['users']);
    }
}
