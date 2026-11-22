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

    private function withHint(string $value, string $hint = 'viewer_panels'): \WeatherMap
    {
        $conf = (string) file_get_contents($this->file);
        file_put_contents($this->file, preg_replace('/^(SET nowarn_clipping 1)$/m', "$1\nSET " . $hint . ' ' . $value, $conf, 1));
        return $this->read();
    }

    /** @return list<array{id: string, position: string, shown: bool}> */
    private static function defaults(): array
    {
        return [
            ['id' => 'sites', 'position' => 'side', 'shown' => true],
            ['id' => 'total', 'position' => 'side', 'shown' => true],
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
        $map = $this->withHint('total:top-right updated:bottom-right:hidden sites:side');
        $this->assertSame([
            ['id' => 'total', 'position' => 'top-right', 'shown' => true],
            ['id' => 'updated', 'position' => 'bottom-right', 'shown' => false],
            ['id' => 'sites', 'position' => 'side', 'shown' => true],
        ], ViewerPanels::of($map), 'a panel left out comes last, at its default place');
        $this->assertSame(ViewerPanels::of($map), MapService::summarize($map)['panels']);
    }

    public function testUnknownPanelsAndPlacesAreSkipped(): void
    {
        $this->assertSame([
            ['id' => 'updated', 'position' => 'bottom-left', 'shown' => false],
            ['id' => 'sites', 'position' => 'side', 'shown' => true],
            ['id' => 'total', 'position' => 'side', 'shown' => true],
        ], ViewerPanels::parse('clock:top-left updated:hidden SITES:middle sites:top-left'), 'the first of two is kept');
        $this->assertSame(self::defaults(), ViewerPanels::parse(''));
    }

    public function testAnOlderLegendPanelIsReadAndLeftOut(): void
    {
        $map = $this->withHint('legend:top-left total:top-right');
        $this->assertSame(['total', 'sites', 'updated'], array_column(ViewerPanels::of($map), 'id'));
        $this->assertTrue(ViewerPanels::showedLegend($map));
        copy(TESTS_ROOT . '/fixtures/maps/basic.conf', $this->file);
        $this->assertFalse(ViewerPanels::showedLegend($this->withHint('legend:side:hidden')));
        copy(TESTS_ROOT . '/fixtures/maps/basic.conf', $this->file);

        // the editor drops it when it writes the hint
        $editor = new MapEditor($this->file);
        $editor->apply([['op' => 'set_map', 'props' => ['viewer_panels' => [
            ['id' => 'legend', 'position' => 'top-left', 'shown' => true],
            ['id' => 'total', 'position' => 'top-right', 'shown' => true],
        ]]]]);
        $this->assertStringContainsString('SET viewer_panels total:top-right sites:side updated:bottom-left', (string) file_get_contents($this->file));
    }

    public function testTheEditorWritesTheHintAndRemovesTheDefault(): void
    {
        $editor = new MapEditor($this->file);
        $panels = [
            ['id' => 'sites', 'position' => 'side', 'shown' => false],
            ['id' => 'updated', 'position' => 'top-right', 'shown' => true],
            ['id' => 'total', 'position' => 'bottom-right', 'shown' => true],
        ];
        $editor->apply([['op' => 'set_map', 'props' => ['viewer_panels' => $panels]]]);
        $this->assertStringContainsString(
            'SET viewer_panels sites:side:hidden updated:top-right total:bottom-right',
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
        $this->assertStringContainsString('viewer_panels: withStatusAt(viewer)', $editor);
        $this->assertStringContainsString('kiosk_panels: { mode: kiosk.mode', $editor, 'the Kiosk switch edits the kiosk layout');
        $this->assertStringContainsString('data.kiosk_panels', $js, 'the kiosk takes its own panels');
        $this->assertStringContainsString("'kiosk_panels' => \$summary['kiosk_panels']", $controller);

        $admin = (string) file_get_contents($root . '/src/app/Views/admin/kiosk.php');
        $this->assertStringContainsString('?open=panels', $admin, 'the kiosk page leads to the panels of each map');
    }

    public function testTheKioskKeepsTheViewerCornersWithoutItsHint(): void
    {
        $map = $this->withHint('total:top-right');
        $this->assertSame('corners', ViewerPanels::kioskMode($map));
        $this->assertNull(ViewerPanels::forKiosk($map), 'without SET kiosk_panels the kiosk does not change');
        $this->assertNull(MapService::summarize($map)['kiosk_panels']);
        $this->assertSame(['mode' => 'corners', 'panels' => ViewerPanels::of($map)], MapEditor::model($map)['map']['props']['kiosk_panels']);
    }

    public function testTheKioskHasItsOwnPanels(): void
    {
        $map = $this->withHint('total:side updated:bottom-right sites:side:hidden', 'kiosk_panels');
        $own = [
            ['id' => 'total', 'position' => 'side', 'shown' => true],
            ['id' => 'updated', 'position' => 'bottom-right', 'shown' => true],
            ['id' => 'sites', 'position' => 'side', 'shown' => false],
        ];
        $this->assertSame('own', ViewerPanels::kioskMode($map));
        $this->assertSame($own, ViewerPanels::forKiosk($map));
        $this->assertNull(ViewerPanels::forViewer($map), 'the viewer keeps its usual layout');
        $this->assertSame($own, MapService::summarize($map)['kiosk_panels']);
        $this->assertSame(['mode' => 'own', 'panels' => $own], MapEditor::model($map)['map']['props']['kiosk_panels']);
    }

    public function testTheKioskCanFollowTheViewer(): void
    {
        $this->withHint('same', 'kiosk_panels');
        $map = $this->withHint('total:top-left');
        $this->assertSame('same', ViewerPanels::kioskMode($map));
        $this->assertSame(ViewerPanels::of($map), ViewerPanels::forKiosk($map));

        // the usual layout too: the kiosk then shows the side panel
        copy(TESTS_ROOT . '/fixtures/maps/basic.conf', $this->file);
        $map = $this->withHint('same', 'kiosk_panels');
        $this->assertSame(self::defaults(), ViewerPanels::forKiosk($map));
    }

    public function testTheEditorWritesTheKioskPanels(): void
    {
        $editor = new MapEditor($this->file);
        $editor->apply([['op' => 'set_map', 'props' => ['kiosk_panels' => ['mode' => 'own', 'panels' => self::defaults()]]]]);
        $this->assertStringContainsString(
            'SET kiosk_panels sites:side total:side updated:bottom-left',
            (string) file_get_contents($this->file),
            'its own layout is written even when it is the usual one'
        );
        $this->assertSame(self::defaults(), ViewerPanels::forKiosk($this->read()));

        $editor->apply([['op' => 'set_map', 'props' => ['kiosk_panels' => ['mode' => 'same', 'panels' => []]]]]);
        $this->assertStringContainsString('SET kiosk_panels same', (string) file_get_contents($this->file));

        $editor->apply([['op' => 'set_map', 'props' => ['kiosk_panels' => ['mode' => 'corners']]]]);
        $this->assertStringNotContainsString('kiosk_panels', (string) file_get_contents($this->file), 'back to the viewer\'s corners');

        foreach ([['mode' => 'wall'], ['mode' => 'own', 'panels' => [['id' => 'clock', 'position' => 'side']]]] as $value) {
            try {
                $editor->apply([['op' => 'set_map', 'props' => ['kiosk_panels' => $value]]]);
                $this->fail('refused: ' . json_encode($value));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheStatusBarCanBePutOnTheMap(): void
    {
        $map = $this->withHint('sites:side, updated:map:20,400 total:top-right');
        $this->assertSame([20, 400], ViewerPanels::statusAt($map), 'the comma between x and y does not split the panel');
        $this->assertSame(['sites', 'updated', 'total'], array_column(ViewerPanels::of($map), 'id'));

        // hidden: it keeps its place, and the pictures draw no status bar
        $this->assertNull(ViewerPanels::statusAt($this->withParsed('updated:map:20,400:hidden')));
        $this->assertSame(['id' => 'updated', 'position' => 'map', 'shown' => false, 'at' => [20, 400]], ViewerPanels::parse('updated:map:20,400:hidden')[0]);

        // only the status bar goes on the map, and only with a place
        $this->assertSame('side', ViewerPanels::parse('sites:map:1,2')[0]['position']);
        $this->assertSame('bottom-left', ViewerPanels::parse('updated:map')[0]['position']);
        $this->assertSame('updated:map:20,400 sites:side total:side', ViewerPanels::format(ViewerPanels::parse('updated:map:20,400')));
    }

    private function withParsed(string $value): \WeatherMap
    {
        copy(TESTS_ROOT . '/fixtures/maps/basic.conf', $this->file);
        return $this->withHint($value);
    }

    public function testPlacingAndHidingTheStatusBar(): void
    {
        $map = $this->read();
        ViewerPanels::placeStatus($map, null);
        $this->assertSame([10, 460], ViewerPanels::statusAt($map), 'without a place: bottom left, inside the 800x500 map');

        ViewerPanels::placeStatus($map, [120.4, -5]);
        $this->assertSame([120, 0], ViewerPanels::statusAt($map));

        ViewerPanels::hideStatus($map);
        $this->assertNull(ViewerPanels::statusAt($map));
        $this->assertSame('sites:side total:side updated:map:120,0:hidden', $map->hints['viewer_panels']);

        ViewerPanels::placeStatus($map, null);
        $this->assertSame([120, 0], ViewerPanels::statusAt($map), 'shown again where it was');

        // the editor's panel list keeps a place, and gives one to a bar put on the map
        ViewerPanels::set($map, [['id' => 'updated', 'position' => 'map', 'shown' => true]]);
        $this->assertSame([10, 460], ViewerPanels::statusAt($map));
    }
}
