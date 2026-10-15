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
}
