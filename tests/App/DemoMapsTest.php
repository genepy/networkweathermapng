<?php

declare(strict_types=1);

namespace Tests\App;

use App\Core\Config;
use App\Core\Database;
use App\Services\DemoMaps;
use App\Services\GroupService;
use App\Services\MapService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The demo maps of src/examples/maps: they draw from static values only,
 * with images and fonts shipped with the application, and install() adds
 * them once to their group.
 */
final class DemoMapsTest extends TestCase
{
    private const PUBLIC_DIR = __DIR__ . '/../../src/public';

    /** @return array<string, array{string}> */
    public static function demoProvider(): array
    {
        $demos = [];
        foreach (glob(DemoMaps::defaultDir() . '/*.conf') ?: [] as $file) {
            $demos[basename($file, '.conf')] = [$file];
        }
        return $demos;
    }

    public function testThereAreDemos(): void
    {
        $this->assertGreaterThanOrEqual(3, count(self::demoProvider()));
    }

    #[DataProvider('demoProvider')]
    public function testDemoDrawsFromStaticValues(string $file): void
    {
        $name = basename($file, '.conf');
        $cwd = (string) getcwd();
        chdir(self::PUBLIC_DIR);
        try {
            $map = new \WeatherMap();
            $map->context = 'cli';
            $map->ReadConfig($file);
            $map->ReadData();
            $output = TESTS_OUTPUT . '/demo-' . $name . '.png';
            $map->DrawMap($output);

            $this->assertNotEmpty(MapService::titleOf((string) file_get_contents($file)), 'a demo needs a TITLE: it is the map name');
            $this->assertFileExists($output);

            foreach ($map->fonts as $n => $font) {
                if ($font->type === 'truetype') {
                    $this->assertFileExists((string) $font->file, "font $n of $name");
                }
            }

            $links = 0;
            foreach ($map->links as $link) {
                if (in_array($link->name, ['DEFAULT', ':: DEFAULT ::'], true)) {
                    continue;
                }
                $links++;
                $this->assertNotEmpty($link->targets, "link {$link->name} of $name has no TARGET");
                foreach ($link->targets as $target) {
                    $this->assertStringStartsWith('static:', (string) $target[4], "link {$link->name} of $name");
                }
            }
            $this->assertGreaterThan(0, $links);

            foreach ($map->nodes as $node) {
                if ($node->iconfile === '' || $node->iconfile === null) {
                    continue;
                }
                $this->assertFileExists($node->iconfile, "icon of {$node->name} in $name");
                $this->assertFileExists(preg_replace('/\.png$/', '.svg', $node->iconfile), "vector twin of the icon of {$node->name} in $name");
            }
        } finally {
            chdir($cwd);
        }
    }

    public function testInstallAddsEachDemoOnce(): void
    {
        $data = TESTS_OUTPUT . '/demomaps';
        self::wipe($data);
        mkdir($data, 0775, true);

        $config = new Config();
        $config->set('APP_ROOT', dirname(TESTS_ROOT) . '/src');
        $config->set('DATA_ROOT', $data);
        $config->set('DB_TYPE', 'sqlite');
        $config->set('DB_PATH', $data . '/test.db');
        $db = new Database($config);
        $maps = new MapService($db, $config);
        $groups = new GroupService($db);
        $demos = new DemoMaps($maps, $groups);

        $first = $demos->install();
        $this->assertSame(array_keys($demos->available()), array_keys($first));
        $this->assertNotContains(false, $first);

        $group = array_values(array_filter($groups->getAllGroups(), static fn ($g) => $g['name'] === DemoMaps::GROUP));
        $this->assertCount(1, $group);
        foreach ($maps->getAllMaps() as $map) {
            $this->assertSame((int) $group[0]['id'], (int) $map['group_id']);
            $this->assertSame(
                (string) file_get_contents($demos->available()[$map['name']]),
                $maps->getMapConfig((int) $map['id'])
            );
        }

        $second = $demos->install();
        $this->assertNotContains(true, $second);
        $this->assertCount(count($first), $maps->getAllMaps());
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
