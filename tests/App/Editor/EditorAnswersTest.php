<?php

declare(strict_types=1);

namespace Tests\App\Editor;

use App\Controllers\SvgEditorController;
use App\Core\Config;
use App\Core\Database;
use App\Services\DemoMaps;
use App\Services\GroupService;
use App\Services\MapService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every answer of the editor endpoints is JSON and nothing else, on each
 * demo map (issue #114: "The server sent an unreadable answer" on the
 * ISP backbone and Datacenter fabric demos).
 *
 * The requests run from the repository root, not from the web root, as
 * PHP-FPM does: the engine must still find the fonts of the maps, and its
 * warnings must not land in the answer.
 */
final class EditorAnswersTest extends TestCase
{
    private string $data;
    private Config $config;
    private Database $db;
    private string|false $errorLog = false;
    private string $logFile;

    protected function setUp(): void
    {
        $this->data = TESTS_OUTPUT . '/editor-answers-' . $this->name();
        self::wipe($this->data);
        mkdir($this->data, 0775, true);

        $this->config = new Config();
        $this->config->set('APP_ROOT', dirname(TESTS_ROOT) . '/src');
        $this->config->set('DATA_ROOT', $this->data);
        $this->config->set('DB_TYPE', 'sqlite');
        $this->config->set('DB_PATH', $this->data . '/test.db');
        $this->config->set('AUTH_ENABLED', 'false');
        $this->db = new Database($this->config);

        // what the controller logs goes to a file, not into the test output
        $this->logFile = $this->data . '/php-error.log';
        $this->errorLog = ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        if ($this->errorLog !== false) {
            ini_set('error_log', $this->errorLog);
        }
    }

    /** @return array<string, array{string}> */
    public static function demoProvider(): array
    {
        $demos = [];
        foreach (glob(DemoMaps::defaultDir() . '/*.conf') ?: [] as $file) {
            $name = (string) MapService::titleOf((string) file_get_contents($file));
            $demos[$name] = [$name];
        }
        return $demos;
    }

    #[DataProvider('demoProvider')]
    public function testEveryEditorActionAnswersJson(string $demo): void
    {
        $id = $this->install($demo);

        $answer = $this->call('renderMap', $id);
        $nodes = array_column($answer['model']['nodes'], 'name');
        $links = array_column($answer['model']['links'], 'name');
        $first = $answer['model']['nodes'][0];
        $this->assertGreaterThan(3, count($nodes));
        $this->assertGreaterThan(1, count($links));

        // the TrueType fonts of the map load, so the SVG asks for them
        $this->assertStringContainsString('/editor/font/' . $id . '/', $answer['svg'], 'the fonts of the map load');

        $steps = [
            ['op' => 'move', 'nodes' => [$nodes[0] => [(int) $first['x'] + 10, (int) $first['y'] + 10]]],
            ['op' => 'set_node', 'names' => [$nodes[1]], 'props' => ['label' => 'Edited']],
            ['op' => 'set_link', 'names' => [$links[0]], 'props' => ['width' => 6]],
            ['op' => 'add_node', 'x' => 60, 'y' => 60],
            ['op' => 'add_link', 'a' => $nodes[0], 'b' => $nodes[2]],
            ['op' => 'delete', 'nodes' => [$nodes[3]], 'links' => [$links[1]]],
        ];
        foreach ($steps as $op) {
            $answer = $this->call('ops', $id, ['ops' => [$op]]);
            $this->assertArrayHasKey('svg', $answer, $op['op']);
        }

        $this->assertArrayHasKey('svg', $this->call('undo', $id));
        $this->assertArrayHasKey('svg', $this->call('undo', $id));
        $this->assertArrayHasKey('svg', $this->call('redo', $id));
        $this->assertArrayHasKey('text', $this->call('copy', $id, ['nodes' => [$nodes[0], $nodes[2]]]));
    }

    public function testTheFontOfAMapIsServedFromAnyDirectory(): void
    {
        $id = $this->install('ISP backbone');
        $wmap = $this->engineMap($id);
        $n = array_key_first(array_filter($wmap->fonts, static fn ($f) => $f->type === 'truetype'));
        $this->assertNotNull($n);

        $out = $this->request('font', ['id' => $id, 'n' => $n]);
        $this->assertStringStartsWith("\x00\x01\x00\x00", $out, 'a TrueType file, with no text before it');
    }

    public function testAnAnswerThatCannotBeEncodedIsAClearError(): void
    {
        ob_start();
        $controller = new class (['config' => $this->config, 'database' => $this->db]) extends SvgEditorController {
            public function answer(mixed $data): void
            {
                echo 'stray text';
                $this->json($data);
            }
        };

        $controller->answer(['value' => NAN]);
        $out = (string) ob_get_clean();

        $answer = json_decode($out, true);
        $this->assertIsArray($answer, $out);
        $this->assertSame(__('The server could not write its answer. The details are in the server log.'), $answer['error']);
        $log = (string) @file_get_contents($this->logFile);
        $this->assertStringContainsString('could not be encoded', $log);
        $this->assertStringContainsString('stray text', $log, 'the dropped output is logged');
    }

    public function testRelativeFontPathsAreMadeFull(): void
    {
        $cwd = (string) getcwd();
        chdir(dirname(TESTS_ROOT) . '/src/public');
        try {
            $this->assertSame(realpath('fonts/FiraSans-Regular.ttf'), wm_ttf_path('fonts/FiraSans-Regular.ttf'));
        } finally {
            chdir($cwd);
        }
        $this->assertSame('fonts/none.ttf', wm_ttf_path('fonts/none.ttf'));
        $this->assertSame('', wm_ttf_path(''));
    }

    // ------------------------------------------------------------------

    private function install(string $demo): int
    {
        $maps = new MapService($this->db, $this->config);
        (new DemoMaps($maps, new GroupService($this->db)))->install();
        foreach ($maps->getAllMaps() as $map) {
            if ($map['name'] === $demo) {
                return (int) $map['id'];
            }
        }
        $this->fail("demo $demo not installed");
    }

    private function engineMap(int $id): \WeatherMap
    {
        $maps = new MapService($this->db, $this->config);
        $file = $this->config->getConfigsPath() . '/' . $maps->getMap($id)['config_file'];
        return $maps->engine(static fn () => (new \App\Services\Editor\MapEditor($file))->load());
    }

    /**
     * One request to the editor: its answer must be JSON and nothing else.
     *
     * @return array<string, mixed>
     */
    private function call(string $action, int $id, ?array $body = null): array
    {
        $out = $this->request($action, ['id' => $id], $body);

        $answer = json_decode($out, true);
        $this->assertIsArray($answer, "$action: the answer is not JSON: " . substr($out, 0, 300));
        $this->assertArrayNotHasKey('error', $answer, "$action: " . ($answer['error'] ?? ''));
        return $answer;
    }

    /** The output of one action of the editor */
    private function request(string $action, array $params, ?array $body = null): string
    {
        $context = ['config' => $this->config, 'database' => $this->db];
        $raw = $body === null ? '' : (string) json_encode($body);
        // what the request prints, from the start: the controller buffers
        // its own output from its constructor
        $level = ob_get_level();
        ob_start();
        $controller = new class ($context, $raw) extends SvgEditorController {
            public function __construct(array $context, private string $raw)
            {
                parent::__construct($context);
            }

            protected function rawBody(): string
            {
                return $this->raw;
            }
        };

        $controller->$action($params);
        $out = '';
        while (ob_get_level() > $level) {
            $out = (string) ob_get_clean() . $out;
        }
        return $out;
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
