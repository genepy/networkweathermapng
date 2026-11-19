<?php

declare(strict_types=1);

namespace Tests\App;

use App\Services\Editor\MapEditor;
use App\Services\MapService;
use App\Services\ViewerPanels;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Viewer panels (feedback F25): SET viewer_panels in the .conf, what the
 * viewer gets about them, the editor property and the viewer's layout.
 */
final class ViewerPanelsTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $dir = TESTS_OUTPUT . '/panels-' . $this->name();
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $this->file = $dir . '/map.conf';
        copy(TESTS_ROOT . '/fixtures/maps/basic.conf', $this->file);
    }

    private function read(): \WeatherMap
    {
        $map = new \WeatherMap();
        $map->context = 'editor';
        ob_start();
        $map->ReadConfig($this->file);
        ob_end_clean();
        return $map;
    }

    private function withHint(string $value): \WeatherMap
    {
        $conf = (string) file_get_contents($this->file);
        file_put_contents($this->file, preg_replace('/^(SET nowarn_clipping 1)$/m', "$1\nSET viewer_panels " . $value, $conf, 1));
        return $this->read();
    }

    /** @return list<array{id: string, position: string, shown: bool}> */
    private static function defaults(): array
    {
        return [
            ['id' => 'sites', 'position' => 'side', 'shown' => true],
            ['id' => 'total', 'position' => 'side', 'shown' => true],
            ['id' => 'legend', 'position' => 'side', 'shown' => true],
            ['id' => 'updated', 'position' => 'bottom-left', 'shown' => true],
        ];
    }

    public function testAMapWithoutTheHintKeepsTheUsualLayout(): void
    {
        $map = $this->read();
        $this->assertSame(self::defaults(), ViewerPanels::of($map));
        $this->assertNull(ViewerPanels::forViewer($map));
        $this->assertNull(MapService::summarize($map)['panels']);
    }

    public function testThePanelsAreReadInTheirOrder(): void
    {
        $map = $this->withHint('total:top-right legend:bottom-right:hidden sites:side');
        $this->assertSame([
            ['id' => 'total', 'position' => 'top-right', 'shown' => true],
            ['id' => 'legend', 'position' => 'bottom-right', 'shown' => false],
            ['id' => 'sites', 'position' => 'side', 'shown' => true],
            ['id' => 'updated', 'position' => 'bottom-left', 'shown' => true],
        ], ViewerPanels::of($map), 'a panel left out comes last, at its default place');
        $this->assertSame(ViewerPanels::of($map), MapService::summarize($map)['panels']);
    }

    public function testUnknownPanelsAndPlacesAreSkipped(): void
    {
        $this->assertSame([
            ['id' => 'legend', 'position' => 'side', 'shown' => false],
            ['id' => 'sites', 'position' => 'side', 'shown' => true],
            ['id' => 'total', 'position' => 'side', 'shown' => true],
            ['id' => 'updated', 'position' => 'bottom-left', 'shown' => true],
        ], ViewerPanels::parse('clock:top-left legend:hidden SITES:middle sites:top-left'), 'the first of two is kept');
        $this->assertSame(self::defaults(), ViewerPanels::parse(''));
    }

    public function testTheEditorWritesTheHintAndRemovesTheDefault(): void
    {
        $editor = new MapEditor($this->file);
        $panels = [
            ['id' => 'legend', 'position' => 'top-left', 'shown' => true],
            ['id' => 'sites', 'position' => 'side', 'shown' => false],
            ['id' => 'updated', 'position' => 'top-right', 'shown' => true],
            ['id' => 'total', 'position' => 'bottom-right', 'shown' => true],
        ];
        $editor->apply([['op' => 'set_map', 'props' => ['viewer_panels' => $panels]]]);
        $this->assertStringContainsString(
            'SET viewer_panels legend:top-left sites:side:hidden updated:top-right total:bottom-right',
            (string) file_get_contents($this->file)
        );
        $map = $editor->load();
        $this->assertSame($panels, ViewerPanels::of($map));
        $this->assertSame($panels, MapEditor::model($map)['map']['props']['viewer_panels']);

        $editor->apply([['op' => 'set_map', 'props' => ['viewer_panels' => self::defaults()]]]);
        $this->assertStringNotContainsString('viewer_panels', (string) file_get_contents($this->file), 'the usual layout writes nothing');
    }

    public function testBadPanelsAreRefused(): void
    {
        $editor = new MapEditor($this->file);
        foreach ([
            [['id' => 'clock', 'position' => 'side']],
            [['id' => 'sites', 'position' => 'middle']],
            'not a list',
        ] as $value) {
            try {
                $editor->apply([['op' => 'set_map', 'props' => ['viewer_panels' => is_string($value) ? 42 : $value]]]);
                $this->fail('refused: ' . json_encode($value));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheViewerAndTheEditorUseThePanels(): void
    {
        $root = dirname(TESTS_ROOT);
        $js = (string) file_get_contents($root . '/src/public/assets/js/viewer.js');
        $this->assertStringContainsString('setPanels(data.panels', $js, 'the panels come with the live map');

        $controller = (string) file_get_contents($root . '/src/app/Controllers/MapController.php');
        $this->assertStringContainsString("'panels' => \$summary['panels']", $controller);

        $view = (string) file_get_contents($root . '/src/app/Views/maps/view.php');
        $this->assertStringNotContainsString('<?php if (!$isKiosk): ?>' . "\n" . '        <aside class="wmv-panel', $view, 'the kiosk has the panels too');

        $editor = (string) file_get_contents($root . '/src/public/assets/js/editor-mapprops.js');
        $this->assertStringContainsString("['panels', t('Viewer panels')]", $editor);
        $this->assertStringContainsString('viewer_panels: panels', $editor);
    }
}
