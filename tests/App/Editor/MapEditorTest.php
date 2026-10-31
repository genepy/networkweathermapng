<?php

declare(strict_types=1);

namespace Tests\App\Editor;

use App\Services\Editor\ConfigHistory;
use App\Services\Editor\MapEditor;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MapEditorTest extends TestCase
{
    private string $file;
    private MapEditor $editor;

    protected function setUp(): void
    {
        $dir = TESTS_OUTPUT . '/editor-' . $this->name();
        if (is_dir($dir)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
        } else {
            mkdir($dir, 0777, true);
        }
        $this->file = $dir . '/map.conf';
        copy(TESTS_ROOT . '/fixtures/maps/basic.conf', $this->file);
        $this->editor = new MapEditor($this->file);
    }

    public function testMovingSeveralNodesIsOneSave(): void
    {
        $this->editor->apply([
            ['op' => 'move', 'nodes' => ['core' => [410, 130], 'paris' => [160, 330]], 'vias' => ['paris-backup' => [[260, 430]]]],
        ]);

        $map = $this->editor->load();
        $this->assertEquals([410, 130], [$map->nodes['core']->x, $map->nodes['core']->y]);
        $this->assertEquals([160, 330], [$map->nodes['paris']->x, $map->nodes['paris']->y]);
        $this->assertEquals([[260, 430]], array_map(fn ($v) => [$v[0], $v[1]], $map->links['paris-backup']->vialist));
    }

    public function testSetPropertiesOnASelection(): void
    {
        $this->editor->apply([
            ['op' => 'set_link', 'names' => ['core-paris', 'core-lyon'], 'props' => ['width' => 'auto', 'bandwidth' => '10G', 'a_offset' => 'S', 'b_offset' => 'n']],
            ['op' => 'set_node', 'names' => ['paris', 'lyon'], 'props' => ['labelbgcolour' => '#ff8000', 'labelfontcolour' => 'contrast', 'zorder' => 700]],
        ]);

        $map = $this->editor->load();
        foreach (['core-paris', 'core-lyon'] as $name) {
            $this->assertSame('auto', $map->links[$name]->width);
            $this->assertSame('10G', $map->links[$name]->max_bandwidth_in_cfg);
            $this->assertSame('S', $map->links[$name]->a_offset);
            $this->assertSame('N', $map->links[$name]->b_offset);
        }
        $this->assertEquals([255, 128, 0], $map->nodes['paris']->labelbgcolour);
        $this->assertEquals([-3, -3, -3], $map->nodes['lyon']->labelfontcolour);
        $this->assertEquals(700, $map->nodes['lyon']->zorder);
    }

    public function testTheMapLinkWidthCanFollowTheBandwidth(): void
    {
        $this->editor->apply([['op' => 'set_link', 'names' => ['core-lyon'], 'props' => ['width' => 3]]]);
        $this->editor->apply([['op' => 'set_map', 'props' => ['link_width' => 'auto']]]);

        $map = $this->editor->load();
        $this->assertSame('auto', $map->links['DEFAULT']->width);
        $this->assertSame('auto', $map->links['core-paris']->width, 'inherited the old default');
        $this->assertEquals(3, $map->links['core-lyon']->width, 'its own width is kept');
        $this->assertStringContainsString("LINK DEFAULT\n\tWIDTH auto", str_replace("\r", '', (string) file_get_contents($this->file)));
        $this->assertSame('auto', $this->drawnModel()['map']['props']['link_width']);

        $this->editor->apply([['op' => 'set_map', 'props' => ['link_width' => '5']]]);
        $map = $this->editor->load();
        $this->assertEquals(5, $map->links['core-paris']->width);
    }

    public function testAddNodeAndLinkThenDeleteNodeRemovesItsLinks(): void
    {
        $result = $this->editor->apply([
            ['op' => 'add_node', 'x' => 600, 'y' => 450, 'name' => 'nice', 'label' => 'nice-sw1'],
            ['op' => 'add_link', 'a' => 'lyon', 'b' => 'nice', 'bandwidth' => '10G'],
        ]);
        $this->assertSame(['nice'], $result['created']['nodes']);
        $this->assertSame(['lyon-nice'], $result['created']['links']);

        $map = $this->editor->load();
        $this->assertSame('nice-sw1', $map->nodes['nice']->label);
        $this->assertSame('nice', $map->links['lyon-nice']->b->name);

        $this->editor->apply([['op' => 'delete', 'nodes' => ['nice']]]);
        $map = $this->editor->load();
        $this->assertArrayNotHasKey('nice', $map->nodes);
        $this->assertArrayNotHasKey('lyon-nice', $map->links);
    }

    public function testCloneCopiesLinksInsideTheSelection(): void
    {
        $result = $this->editor->apply([['op' => 'clone', 'nodes' => ['core', 'paris'], 'dx' => 30, 'dy' => 40]]);

        $this->assertSame(['core_copy', 'paris_copy'], $result['created']['nodes']);
        $this->assertSame(['core_copy-paris_copy'], $result['created']['links']);

        $map = $this->editor->load();
        $this->assertEquals([430, 160], [$map->nodes['core_copy']->x, $map->nodes['core_copy']->y]);
    }

    public function testRenameFollowsLinks(): void
    {
        $this->editor->apply([['op' => 'rename_node', 'name' => 'core', 'to' => 'core01']]);

        $map = $this->editor->load();
        $this->assertArrayHasKey('core01', $map->nodes);
        $this->assertSame('core01', $map->links['core-paris']->a->name);
    }

    public function testGroupAndLockAreKeptInTheFile(): void
    {
        $this->editor->apply([
            ['op' => 'hint', 'kind' => 'node', 'names' => ['paris', 'lyon'], 'key' => 'editor_group', 'value' => 'g1'],
            ['op' => 'hint', 'kind' => 'node', 'names' => ['core'], 'key' => 'editor_lock', 'value' => '1'],
        ]);

        $this->assertStringContainsString('SET editor_group g1', file_get_contents($this->file));

        $map = $this->editor->load();
        ob_start();
        $map->DrawMap('null');
        ob_end_clean();
        $model = MapEditor::model($map);
        $byName = array_column($model['nodes'], null, 'name');

        $this->assertSame('g1', $byName['paris']['group']);
        $this->assertTrue($byName['core']['locked']);
        $this->assertFalse($byName['lyon']['locked']);

        $this->editor->apply([['op' => 'hint', 'kind' => 'node', 'names' => ['paris'], 'key' => 'editor_group', 'value' => null]]);
        $this->assertSame('', $this->editor->load()->nodes['paris']->hints['editor_group'] ?? '');
    }

    public function testModelGivesAbsoluteViasAndBoxes(): void
    {
        $map = $this->editor->load();
        ob_start();
        $map->DrawMap('null');
        ob_end_clean();

        $model = MapEditor::model($map);
        $links = array_column($model['links'], null, 'name');
        $nodes = array_column($model['nodes'], null, 'name');

        $this->assertSame([[250, 420]], $links['paris-backup']['via']);
        $this->assertNotEmpty($links['paris-backup']['spine']);
        $box = $nodes['core']['box'];
        $this->assertTrue($box[0] < 400 && $box[2] > 400 && $box[1] < 120 && $box[3] > 120);
        $this->assertSame(800, $model['map']['width']);
    }

    public function testBadInputIsRefusedAndNothingIsWritten(): void
    {
        $before = file_get_contents($this->file);

        foreach ([
            [['op' => 'move', 'nodes' => ['nope' => [1, 2]]]],
            [['op' => 'set_link', 'names' => ['core-paris'], 'props' => ['bandwidth' => '10 G; rm']]],
            [['op' => 'set_node', 'names' => ['core'], 'props' => ['icon' => '../../etc/passwd.png']]],
            [['op' => 'set_link', 'names' => ['core-paris'], 'props' => ['a_offset' => 'UP']]],
            [['op' => 'hint', 'names' => ['core'], 'key' => 'testmode', 'value' => '1']],
            [['op' => 'whatever']],
        ] as $ops) {
            try {
                $this->editor->apply($ops);
                $this->fail('accepted ' . json_encode($ops));
            } catch (InvalidArgumentException) {
            }
        }

        $this->assertSame($before, file_get_contents($this->file));
    }

    public function testLabelsCannotInjectConfigLines(): void
    {
        $this->editor->apply([['op' => 'set_node', 'names' => ['core'], 'props' => ['label' => "x\nNODE evil\n\tPOSITION 1 1"]]]);

        $map = $this->editor->load();
        $this->assertArrayNotHasKey('evil', $map->nodes);
    }

    public function testUndoAndRedo(): void
    {
        $history = new ConfigHistory(dirname($this->file) . '/history', $this->file);
        $original = file_get_contents($this->file);

        $history->record();
        $this->editor->apply([['op' => 'move', 'nodes' => ['core' => [10, 10]]]]);
        $moved = file_get_contents($this->file);
        $this->assertNotSame($original, $moved);

        $this->assertTrue($history->undo());
        $this->assertSame($original, file_get_contents($this->file));
        $this->assertSame(['undo' => 0, 'redo' => 1], $history->counts());

        $this->assertTrue($history->redo());
        $this->assertSame($moved, file_get_contents($this->file));
        $this->assertFalse($history->redo());

        // a new change forgets what could be redone
        $history->undo();
        $history->record();
        $this->assertSame(['undo' => 1, 'redo' => 0], $history->counts());
    }

    public function testZonesAreAddedEditedMovedAndDeleted(): void
    {
        $r = $this->editor->apply([['op' => 'add_zone', 'x' => 10, 'y' => 20, 'width' => 300, 'height' => 150, 'label' => 'Paris']]);
        $this->assertSame(['zones' => ['zone']], $r['created']);

        $this->editor->apply([
            ['op' => 'set_zone', 'names' => ['zone'], 'props' => ['fillcolour' => '#102030', 'outlinecolour' => 'none', 'labelpos' => 'se', 'outlinestyle' => 'DASHED', 'opacity' => 150, 'name' => 'paris-dc']],
            ['op' => 'move', 'zones' => ['paris-dc' => [40, 50]]],
        ]);

        $map = $this->editor->load();
        $z = $map->zones['paris-dc'];
        $this->assertSame([40, 50, 300, 150], [$z->x, $z->y, $z->width, $z->height]);
        $this->assertSame([16, 32, 48], $z->fillcolour);
        $this->assertNull($z->outlinecolour);
        $this->assertSame('SE', $z->labelpos);
        $this->assertSame('dashed', $z->outlinestyle);
        $this->assertSame(100, $z->opacity);
        $this->assertSame('Paris', $z->label);

        $model = MapEditor::model($map);
        $this->assertSame('paris-dc', $model['zones'][0]['name']);
        $this->assertSame('#102030', $model['zones'][0]['fillcolour']);
        $this->assertSame('none', $model['zones'][0]['outlinecolour']);

        $this->editor->apply([['op' => 'delete_zones', 'names' => ['paris-dc']]]);
        $this->assertSame([], $this->editor->load()->zones);
    }

    public function testBadZoneValuesAreRefused(): void
    {
        $this->editor->apply([['op' => 'add_zone', 'x' => 0, 'y' => 0]]);

        foreach ([['labelpos' => 'middle'], ['fillcolour' => 'contrast'], ['colour' => '#fff']] as $props) {
            try {
                $this->editor->apply([['op' => 'set_zone', 'names' => ['zone'], 'props' => $props]]);
                $this->fail('accepted ' . json_encode($props));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $this->editor->apply([['op' => 'set_zone', 'names' => ['nope'], 'props' => ['label' => 'x']]]);
    }

    private function drawnModel(): array
    {
        $map = $this->editor->load();
        ob_start();
        $map->DrawMap('null');
        ob_end_clean();
        return MapEditor::model($map);
    }

    public function testLegendTimestampAndTitleAreInTheModelAndMove(): void
    {
        $furniture = array_column($this->drawnModel()['furniture'], null, 'kind');
        $this->assertSame('DEFAULT', $furniture['legend']['name']);
        $this->assertSame([680, 20], $furniture['legend']['pos']);
        $this->assertSame([10, 490], $furniture['timestamp']['pos']);
        $this->assertSame([10, 20], $furniture['title']['pos']);
        $this->assertSame(490, $furniture['timestamp']['box'][3]);

        $this->editor->apply([
            ['op' => 'move', 'furniture' => ['legend:DEFAULT' => [600, 40], 'timestamp:' => [20, 470], 'title:' => [30, 25]]],
        ]);

        $map = $this->editor->load();
        $this->assertEquals([600, 40], [$map->keyx['DEFAULT'], $map->keyy['DEFAULT']]);
        $this->assertEquals([20, 470], [$map->timex, $map->timey]);
        $this->assertEquals([30, 25], [$map->titlex, $map->titley]);

        // what the model says is where it was put
        $furniture = array_column($this->drawnModel()['furniture'], null, 'kind');
        $this->assertSame([600, 40], $furniture['legend']['pos']);
        $this->assertSame([20, 470], $furniture['timestamp']['pos']);
        $this->assertSame([30, 25], $furniture['title']['pos']);
    }

    public function testFurnitureStaysOnTheMapAndUnknownItemsAreRefused(): void
    {
        // TIMEPOS 0 0 would mean "top right corner", negative KEYPOS hides the legend
        $this->editor->apply([['op' => 'move', 'furniture' => ['timestamp:' => [-5, 0], 'legend:DEFAULT' => [-10, 5]]]]);
        $map = $this->editor->load();
        $this->assertEquals([1, 1], [$map->timex, $map->timey]);
        $this->assertEquals([0, 5], [$map->keyx['DEFAULT'], $map->keyy['DEFAULT']]);

        foreach (['legend:NOPE', 'timestamp:LATER', 'clock:'] as $key) {
            try {
                $this->editor->apply([['op' => 'move', 'furniture' => [$key => [1, 1]]]]);
                $this->fail('accepted ' . $key);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testZonesCanBeGroupedAndLocked(): void
    {
        $this->editor->apply([
            ['op' => 'add_zone', 'x' => 100, 'y' => 250, 'name' => 'site'],
            ['op' => 'hint', 'kind' => 'zone', 'names' => ['site'], 'key' => 'editor_group', 'value' => 'g1'],
            ['op' => 'hint', 'kind' => 'node', 'names' => ['paris'], 'key' => 'editor_group', 'value' => 'g1'],
            ['op' => 'hint', 'kind' => 'zone', 'names' => ['site'], 'key' => 'editor_lock', 'value' => '1'],
        ]);

        $model = $this->drawnModel();
        $this->assertSame('g1', $model['zones'][0]['group']);
        $this->assertTrue($model['zones'][0]['locked']);
        $this->assertSame('g1', array_column($model['nodes'], null, 'name')['paris']['group']);

        $this->editor->apply([['op' => 'hint', 'kind' => 'zone', 'names' => ['site'], 'key' => 'editor_lock', 'value' => null]]);
        $this->assertFalse($this->drawnModel()['zones'][0]['locked']);
    }

    public function testCustomLayersAreSavedInTheMap(): void
    {
        $this->editor->apply([
            ['op' => 'hint', 'kind' => 'node', 'names' => ['paris', 'lyon'], 'key' => 'editor_layer', 'value' => 'Core routers'],
            ['op' => 'hint', 'kind' => 'link', 'names' => ['paris-backup'], 'key' => 'editor_layer', 'value' => 'Core routers'],
        ]);
        $this->assertStringContainsString('SET editor_layer Core_routers', file_get_contents($this->file));

        $model = $this->drawnModel();
        $nodes = array_column($model['nodes'], null, 'name');
        $this->assertSame('Core_routers', $nodes['paris']['layer']);
        $this->assertSame('', $nodes['core']['layer']);
        $this->assertSame('Core_routers', array_column($model['links'], null, 'name')['paris-backup']['layer']);

        $this->editor->apply([['op' => 'hint', 'kind' => 'node', 'names' => ['paris'], 'key' => 'editor_layer', 'value' => null]]);
        $this->assertSame('', array_column($this->drawnModel()['nodes'], null, 'name')['paris']['layer']);
    }

    public function testANodeLabelMovesAwayFromItsIconAndBack(): void
    {
        $this->editor->apply([['op' => 'set_node', 'names' => ['backup'], 'props' => ['icon' => 'src/public/objects/net/router.png']]]);
        $before = array_column($this->drawnModel()['nodes'], null, 'name');
        $this->assertNotNull($before['backup']['label_box']);
        $this->assertNull($before['core']['label_box'], 'without an icon, the label is the node');

        $this->editor->apply([['op' => 'set_node', 'names' => ['backup'], 'props' => ['labelshift' => [10, -20]]]]);
        $this->assertStringContainsString('LABELOFFSET 10 -20', file_get_contents($this->file));

        $after = array_column($this->drawnModel()['nodes'], null, 'name');
        $this->assertSame([10, -20], $after['backup']['label_shift']);
        $b = $before['backup']['label_box'];
        $this->assertSame([$b[0] + 10, $b[1] - 20, $b[2] + 10, $b[3] - 20], $after['backup']['label_box']);

        $this->editor->apply([['op' => 'set_node', 'names' => ['backup'], 'props' => ['labelshift' => null]]]);
        $this->assertStringNotContainsString('LABELOFFSET', file_get_contents($this->file));
        $this->assertSame($b, array_column($this->drawnModel()['nodes'], null, 'name')['backup']['label_box']);
    }

    public function testAnIconIsResized(): void
    {
        $this->editor->apply([['op' => 'set_node', 'names' => ['backup'], 'props' => ['icon' => 'src/public/objects/net/router.png']]]);
        $natural = array_column($this->drawnModel()['nodes'], null, 'name')['backup'];
        $this->assertSame([0, 0], [$natural['iconscalew'], $natural['iconscaleh']]);
        $this->assertNotNull($natural['icon_box']);

        $this->editor->apply([['op' => 'set_node', 'names' => ['backup'], 'props' => ['iconscalew' => 80, 'iconscaleh' => 80]]]);
        $this->assertStringContainsString('ICON 80 80 src/public/objects/net/router.png', file_get_contents($this->file));
        $box = array_column($this->drawnModel()['nodes'], null, 'name')['backup']['icon_box'];
        $this->assertSame(80, max($box[2] - $box[0], $box[3] - $box[1]), 'the picture fits in 80 x 80');

        // one size given: the other follows; 0 is the picture's own size again
        $this->editor->apply([['op' => 'set_node', 'names' => ['backup'], 'props' => ['iconscalew' => 40, 'iconscaleh' => 0]]]);
        $this->assertStringContainsString('ICON 40 40 ', file_get_contents($this->file));
        $this->editor->apply([['op' => 'set_node', 'names' => ['backup'], 'props' => ['iconscalew' => 0, 'iconscaleh' => 0]]]);
        $this->assertStringContainsString("ICON src/public/objects/net/router.png", file_get_contents($this->file));
        $this->assertSame($natural['icon_box'], array_column($this->drawnModel()['nodes'], null, 'name')['backup']['icon_box']);

        $this->editor->apply([['op' => 'set_node', 'names' => ['backup'], 'props' => ['iconscalew' => 99999]]]);
        $this->assertEquals(1024, $this->editor->load()->nodes['backup']->iconscalew);
    }

    public function testAShapeIconGetsASize(): void
    {
        $this->editor->apply([['op' => 'set_node', 'names' => ['backup'], 'props' => ['icon' => 'round']]]);
        $this->assertStringContainsString('ICON 32 32 round', file_get_contents($this->file));
        $this->editor->apply([['op' => 'set_node', 'names' => ['backup'], 'props' => ['iconscalew' => 60, 'iconscaleh' => 20]]]);
        $box = array_column($this->drawnModel()['nodes'], null, 'name')['backup']['icon_box'];
        $this->assertSame([60, 20], [$box[2] - $box[0], $box[3] - $box[1]]);
    }

    public function testANodeLabelMovesWhenItsTemplateUsesACompassOffset(): void
    {
        file_put_contents($this->file, str_replace("LINK DEFAULT", "NODE DEFAULT\n\tLABELOFFSET S\n\nLINK DEFAULT", file_get_contents($this->file)));
        $this->editor->apply([['op' => 'set_node', 'names' => ['backup'], 'props' => ['icon' => 'src/public/objects/net/router.png']]]);
        $before = array_column($this->drawnModel()['nodes'], null, 'name')['backup'];

        $this->editor->apply([['op' => 'set_node', 'names' => ['backup'], 'props' => ['labelshift' => [10, -20]]]]);
        $this->assertStringContainsString('LABELOFFSET 10:-20', file_get_contents($this->file));
        $this->assertSame([10, -20], array_column($this->drawnModel()['nodes'], null, 'name')['backup']['label_shift']);

        $this->editor->apply([['op' => 'set_node', 'names' => ['backup'], 'props' => ['labelshift' => null]]]);
        $this->assertSame(1, substr_count(file_get_contents($this->file), 'LABELOFFSET'), 'only the template keeps one');
        $this->assertSame($before['label_box'], array_column($this->drawnModel()['nodes'], null, 'name')['backup']['label_box']);
    }

    public function testMapPropertiesAreSavedAndLinkDefaultsFollow(): void
    {
        $this->editor->apply([['op' => 'set_map', 'props' => [
            'key_text' => "Load\nper link", 'key_style' => 'horizontal', 'key_size' => 300,
            'key_bg_colour' => 'none', 'title_colour' => '#102030', 'stamp_text' => 'Updated %H:%M',
            'title_font' => 4, 'link_bandwidth' => '10G', 'link_width' => 8,
        ]]]);

        $map = $this->editor->load();
        $this->assertSame('Load per link', $map->keytext['DEFAULT']);
        $this->assertSame('horizontal', $map->keystyle['DEFAULT']);
        $this->assertEquals(300, $map->keysize['DEFAULT']);
        $this->assertEquals(-1, $map->colours['DEFAULT']['KEYBG']['red1']);
        $this->assertEquals([16, 32, 48], [$map->colours['DEFAULT']['TITLE']['red1'], $map->colours['DEFAULT']['TITLE']['green1'], $map->colours['DEFAULT']['TITLE']['blue1']]);
        $this->assertSame('Updated %H:%M', $map->stamptext);
        $this->assertEquals(4, $map->titlefont);

        // links that had the old default follow it; a link with its own value keeps it
        $this->assertSame('10G', $map->links['DEFAULT']->max_bandwidth_in_cfg);
        $this->assertSame('10G', $map->links['core-paris']->max_bandwidth_in_cfg);
        $this->assertEquals(8, $map->links['core-paris']->width);

        $props = MapEditor::model($map)['map']['props'];
        $this->assertSame('none', $props['key_bg_colour']);
        $this->assertSame('#102030', $props['title_colour']);
        $this->assertSame('10G', $props['link_bandwidth']);
    }

    public function testTheLegendCanBeHiddenAndShownAgain(): void
    {
        $this->editor->apply([['op' => 'set_map', 'props' => ['key_shown' => false]]]);
        $this->assertFalse(MapEditor::model($this->editor->load())['map']['props']['key_shown']);

        $this->editor->apply([['op' => 'set_map', 'props' => ['key_shown' => true]]]);
        $map = $this->editor->load();
        $this->assertEquals([680, 20], [$map->keyx['DEFAULT'], $map->keyy['DEFAULT']], 'the legend comes back where it was');
        $this->assertSame('Trafic', $map->keytext['DEFAULT']);
    }

    public function testAClassicHiddenLegendIsShownInTheCorner(): void
    {
        file_put_contents($this->file, str_replace('KEYPOS DEFAULT 680 20', 'KEYPOS DEFAULT -1 -1', (string) file_get_contents($this->file)));

        $this->editor->apply([['op' => 'set_map', 'props' => ['key_shown' => true]]]);
        $map = $this->editor->load();
        $this->assertEquals([10, 40], [$map->keyx['DEFAULT'], $map->keyy['DEFAULT']]);
    }

    public function testAnEmptyTemplateIsKeptOnSave(): void
    {
        $conf = (string) file_get_contents($this->file);
        $conf = str_replace("NODE core\n", "NODE bare\n\nLINK plain\n\nNODE core\n", $conf);
        $conf .= "\nNODE edge\n\tTEMPLATE bare\n\tPOSITION 300 450\n"
            . "\nLINK via-plain\n\tTEMPLATE plain\n\tNODES paris lyon\n";
        file_put_contents($this->file, $conf);

        $this->editor->apply([['op' => 'set_map', 'title' => 'Saved']]);
        $map = $this->editor->load();

        $this->assertArrayHasKey('plain', $map->links);
        $this->assertSame('plain', $map->links['via-plain']->template);
        $this->assertArrayHasKey('bare', $map->nodes);
        $this->assertSame('bare', $map->nodes['edge']->template);
    }

    public function testLinkDefaultsFollowThroughTemplates(): void
    {
        $conf = (string) file_get_contents($this->file);
        $conf = str_replace("NODE core\n", "LINK plain\n\tBANDWIDTH 2G\n\nLINK thick\n\tWIDTH 12\n\nNODE core\n", $conf);
        $conf .= "\nLINK via-plain\n\tTEMPLATE plain\n\tNODES paris lyon\n"
            . "\nLINK via-thick\n\tTEMPLATE thick\n\tNODES lyon backup\n"
            . "\nLINK own-width\n\tTEMPLATE thick\n\tWIDTH 6\n\tNODES paris backup\n";
        file_put_contents($this->file, $conf);

        $this->editor->apply([['op' => 'set_map', 'props' => ['link_width' => 9]]]);
        $map = $this->editor->load();

        $this->assertEquals(9, $map->links['plain']->width, 'a template without a width of its own follows');
        $this->assertEquals(9, $map->links['via-plain']->width, 'and so do its links');
        $this->assertEquals(12, $map->links['thick']->width, 'a template with its own width keeps it');
        $this->assertEquals(12, $map->links['via-thick']->width);
        $this->assertEquals(6, $map->links['own-width']->width, 'a width set on the link itself stays');
    }

    public function testBadMapPropertiesAreRefused(): void
    {
        $before = (string) file_get_contents($this->file);
        foreach ([['title_colour' => 'none'], ['key_style' => 'round'], ['title_font' => 99], ['link_bandwidth' => '1 Gb'], ['nope' => 1]] as $props) {
            try {
                $this->editor->apply([['op' => 'set_map', 'props' => $props]]);
                $this->fail(json_encode($props) . ' was accepted');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame($before, (string) file_get_contents($this->file));
    }
}
