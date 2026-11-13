<?php

declare(strict_types=1);

namespace Tests\App;

use App\Services\Editor\MapEditor;
use App\Services\FocusAreas;
use App\Services\MapService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Focus areas (feedback F14): SET focus_* in the .conf, what the viewer
 * gets about them, the editor operations and the viewer's menu.
 */
final class FocusAreasTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $dir = TESTS_OUTPUT . '/focus-' . $this->name();
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

    private function withHints(string $hints): \WeatherMap
    {
        $conf = (string) file_get_contents($this->file);
        file_put_contents($this->file, preg_replace('/^(SET nowarn_clipping 1)$/m', "$1\n" . $hints, $conf, 1));
        return $this->read();
    }

    public function testAreasAreReadFromTheConf(): void
    {
        $map = $this->withHints("SET focus_2 700 400 500 200\nSET focus_1 100 50 450 200\nSET focus_1_title West\n"
            . "SET focus_1_style dim\nSET focus_3 1 2 3\nSET focus_4 10 10 10 90\nSET focus_default 2");
        $areas = FocusAreas::all($map);

        $this->assertSame(['1', '2'], array_map('strval', array_keys($areas)), 'in the order of their ids, bad rectangles left out');
        $this->assertSame(['id' => '1', 'title' => 'West', 'style' => 'dim', 'box' => [100, 50, 450, 200], 'default' => false], $areas['1']);
        $this->assertSame([500, 200, 700, 400], $areas['2']['box'], 'corners in any order');
        $this->assertSame(['', 'both', true], [$areas['2']['title'], $areas['2']['style'], $areas['2']['default']]);
        $this->assertSame('2', FocusAreas::defaultId($map));
    }

    public function testAnUnknownDefaultIsNoDefault(): void
    {
        $map = $this->withHints("SET focus_1 0 0 100 100\nSET focus_default 7");
        $this->assertNull(FocusAreas::defaultId($map));
        $focus = FocusAreas::forViewer($map);
        $this->assertNotNull($focus);
        $this->assertArrayHasKey('default', $focus);
        $this->assertNull($focus['default']);
    }

    public function testTheViewerGetsTheNodesAndLinksOfEachArea(): void
    {
        // basic.conf: core 400 120, paris 150 320, lyon 650 320, backup 400 380
        $map = $this->withHints("SET focus_1 100 250 450 400\nSET focus_1_title South-west\nSET focus_default 1");
        ob_start();
        $map->DrawMap('null');
        ob_end_clean();

        $focus = MapService::summarize($map)['focus'];
        $this->assertSame('1', $focus['default']);
        $area = $focus['areas'][0];
        $this->assertSame('South-west', $area['title']);
        $this->assertEqualsCanonicalizing(['paris', 'backup'], $area['nodes']);
        $ids = [];
        foreach ($map->links as $link) {
            if (isset($link->a, $link->b) && $link->name !== 'DEFAULT') {
                $ids[$link->name] = 'L' . $link->id;
            }
        }
        $this->assertContains($ids['core-paris'], $area['links'], 'a link with one end inside');
        $this->assertContains($ids['paris-backup'], $area['links']);
        $this->assertNotContains($ids['core-lyon'], $area['links'], 'both ends outside');
        $this->assertArrayNotHasKey('default', $area);
    }

    public function testAMapWithoutAreasHasNoFocus(): void
    {
        $this->assertNull(FocusAreas::forViewer($this->read()));
        $this->assertNull(MapService::summarize($this->read())['focus']);
    }

    public function testTheEditorAddsChangesAndDeletesAreas(): void
    {
        $editor = new MapEditor($this->file);
        $result = $editor->apply([['op' => 'add_focus', 'box' => [450, 300, 100, 50], 'title' => 'West', 'default' => true]]);
        $this->assertSame(['focus' => ['1']], $result['created']);
        $editor->apply([['op' => 'add_focus', 'box' => [500, 200, 790, 480]]]);

        $conf = (string) file_get_contents($this->file);
        $this->assertStringContainsString('SET focus_1 100 50 450 300', $conf);
        $this->assertStringContainsString('SET focus_1_title West', $conf);
        $this->assertStringContainsString('SET focus_default 1', $conf);
        $this->assertStringNotContainsString('focus_1_style', $conf, 'both is the default style');

        $editor->apply([['op' => 'set_focus', 'name' => '2', 'props' => ['style' => 'zoom', 'title' => 'East', 'default' => true]]]);
        $editor->apply([['op' => 'set_focus', 'name' => '1', 'props' => ['box' => [0, 0, 300, 300], 'title' => '']]]);
        $map = $editor->load();
        $this->assertSame('2', FocusAreas::defaultId($map), 'one default: the last one asked for');
        $this->assertSame('zoom', FocusAreas::all($map)['2']['style']);
        $this->assertSame([0, 0, 300, 300], FocusAreas::all($map)['1']['box']);
        $this->assertSame('', FocusAreas::all($map)['1']['title']);

        $model = MapEditor::model($map);
        $this->assertSame(['1', '2'], array_column($model['focus'], 'id'));
        $this->assertSame([false, true], array_column($model['focus'], 'default'));

        $editor->apply([['op' => 'set_focus', 'name' => '1', 'props' => ['default' => false]]]);
        $this->assertSame('2', FocusAreas::defaultId($editor->load()), 'unticking another area keeps the default');

        $editor->apply([['op' => 'delete_focus', 'name' => '2']]);
        $conf = (string) file_get_contents($this->file);
        $this->assertStringNotContainsString('focus_2', $conf, 'the area and its settings');
        $this->assertStringNotContainsString('focus_default', $conf, 'it was the default');
        $this->assertStringContainsString('SET focus_1 0 0 300 300', $conf);
    }

    public function testTheLinesOfAnAreaStayTogether(): void
    {
        $editor = new MapEditor($this->file);
        $editor->apply([
            ['op' => 'add_focus', 'box' => [0, 0, 100, 100]],
            ['op' => 'add_focus', 'box' => [100, 100, 200, 200]],
        ]);
        $editor->apply([['op' => 'set_focus', 'name' => '1', 'props' => ['title' => 'First']]]);
        $this->assertMatchesRegularExpression('/SET focus_1 0 0 100 100\s+SET focus_1_title First\s+SET focus_2 100 100 200 200/', (string) file_get_contents($this->file));
    }

    public function testBadAreasAreRefused(): void
    {
        $editor = new MapEditor($this->file);
        $editor->apply([['op' => 'add_focus', 'box' => [0, 0, 100, 100]]]);
        foreach ([
            ['op' => 'add_focus', 'box' => [0, 0, 5, 100]],
            ['op' => 'add_focus', 'box' => [0, 0, 100]],
            ['op' => 'add_focus', 'box' => ['a', 0, 100, 100]],
            ['op' => 'set_focus', 'name' => '9', 'props' => ['title' => 'x']],
            ['op' => 'set_focus', 'name' => 'default', 'props' => ['title' => 'x']],
            ['op' => 'set_focus', 'name' => '1', 'props' => ['style' => 'blink']],
            ['op' => 'set_focus', 'name' => '1', 'props' => ['colour' => 'red']],
            ['op' => 'delete_focus', 'name' => '9'],
        ] as $op) {
            try {
                $editor->apply([$op]);
                $this->fail('refused: ' . json_encode($op));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheViewerHasTheMenuAndAppliesTheAreas(): void
    {
        $root = dirname(TESTS_ROOT);
        $view = (string) file_get_contents($root . '/src/app/Views/maps/view.php');
        $this->assertStringContainsString('wmv-areas', $view);
        $this->assertStringContainsString("icon('focus')", $view);
        $this->assertStringContainsString('id="focus"', (string) file_get_contents($root . '/src/public/assets/icons/ui.svg'));

        $js = (string) file_get_contents($root . '/src/public/assets/js/viewer.js');
        $this->assertStringContainsString('setAreas(data.focus', $js, 'the areas come with the live map');
        $this->assertStringContainsString("'a=' +", $js, 'the address keeps the area');

        $editor = (string) file_get_contents($root . '/src/app/Views/editor/svg.php');
        $this->assertStringContainsString('editor-focus.js', $editor);
        $this->assertLessThan(strpos($editor, 'editor-mapprops.js'), strpos($editor, 'editor-focus.js'));
    }
}
