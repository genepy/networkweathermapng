<?php

declare(strict_types=1);

namespace Tests\App;

use App\Controllers\MapManagementController;
use App\Core\Config;
use App\Core\Database;
use App\Services\DemoMaps;
use App\Services\GroupService;
use App\Services\MapService;
use PHPUnit\Framework\TestCase;

/**
 * F55: an administrator adds the demo maps from the Maps page (empty state
 * and ⋯ menu) or Settings › Import maps: POST /editor/maps/demos adds the
 * missing ones, says which, and goes back to the page it came from.
 */
final class DemoMapsActionTest extends TestCase
{
    private string $data;
    private Config $config;
    private Database $db;
    /** @var array<mixed> */
    private array $session = [];

    protected function setUp(): void
    {
        $this->data = TESTS_OUTPUT . '/demo-action';
        self::wipe($this->data);
        mkdir($this->data, 0775, true);

        $this->config = new Config();
        $this->config->set('APP_ROOT', dirname(TESTS_ROOT) . '/src');
        $this->config->set('DATA_ROOT', $this->data);
        $this->config->set('DB_TYPE', 'sqlite');
        $this->config->set('DB_PATH', $this->data . '/test.db');
        $this->config->set('AUTH_ENABLED', 'false');
        $this->db = new Database($this->config);
        $this->session = $_SESSION ?? [];
        $_SESSION = ['logged_in' => true, 'user_id' => 1, 'username' => 'admin', 'role' => 'admin'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->session;
        $_GET = [];
        $_POST = [];
    }

    public function testTheActionAddsTheMissingDemosAndSaysWhich(): void
    {
        $demos = $this->demos();
        $all = array_keys($demos->available());
        $this->assertSame($all, $demos->missing());

        [$flash, $to] = $this->post();
        $this->assertSame('/', $to);
        $this->assertArrayHasKey('success', $flash);
        foreach ($all as $name) {
            $this->assertStringContainsString($name, $flash['success']);
        }
        $this->assertSame([], $demos->missing(), 'nothing left to add: the menu item and the button are hidden');
        $this->assertCount(count($all), (new MapService($this->db, $this->config))->getAllMaps());

        [$flash] = $this->post();
        $this->assertArrayHasKey('info', $flash, 'a second click adds nothing');
        $this->assertCount(count($all), (new MapService($this->db, $this->config))->getAllMaps());
    }

    public function testADeletedDemoComesBackAloneAndTheActionReturnsToItsPage(): void
    {
        $this->post();
        $maps = new MapService($this->db, $this->config);
        $gone = $maps->getAllMaps()[0];
        $maps->deleteMap((int) $gone['id']);
        $this->assertSame([$gone['name']], $this->demos()->missing());

        $_GET['return'] = '/admin/migration';
        [$flash, $to] = $this->post();
        $this->assertSame('/admin/migration', $to);
        $this->assertSame(__('Demo maps added: :names', ['names' => $gone['name']]), $flash['success']);
    }

    public function testTheRouteIsForAdministrators(): void
    {
        $app = (string) file_get_contents(dirname(TESTS_ROOT) . '/src/app/Core/Application.php');
        $this->assertStringContainsString("post('/editor/maps/demos', 'MapManagementController@installDemos')", $app);

        $source = (string) file_get_contents(dirname(TESTS_ROOT) . '/src/app/Controllers/MapManagementController.php');
        $body = substr($source, (int) strpos($source, 'function installDemos'));
        $this->assertMatchesRegularExpression('/^[^}]*requireAdmin\(\)/', $body);
    }

    private function demos(): DemoMaps
    {
        return new DemoMaps(new MapService($this->db, $this->config), new GroupService($this->db));
    }

    /** @return array{array<string, string>, string} the flash messages and where the action redirects */
    private function post(): array
    {
        $controller = new class (['config' => $this->config, 'database' => $this->db]) extends MapManagementController {
            /** @var array<string, string> */
            public array $flashes = [];

            protected function flash(string $type, string $message): void
            {
                $this->flashes[$type] = $message;
            }

            protected function redirect(string $url): void
            {
                throw new \RuntimeException('redirect:' . $url);
            }
        };
        try {
            $controller->installDemos([]);
            $this->fail('the action redirects');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('redirect:', $e->getMessage());
            return [$controller->flashes, substr($e->getMessage(), strlen('redirect:'))];
        }
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
