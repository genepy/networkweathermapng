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

    public function testLinkAndNodeDataOfTheClassicEditor(): void
    {
        $this->editor->apply([
            ['op' => 'set_link', 'names' => ['core-paris'], 'props' => [
                'bandwidth' => '1G', 'infourl' => 'https://noc.example/paris',
                'hover_url' => "https://graphs.example/a.png\n  /graphs/b.png",
            ]],
            ['op' => 'set_link', 'names' => ['core-paris'], 'props' => ['bandwidth_out' => '100M']],
            ['op' => 'set_node', 'names' => ['paris'], 'props' => ['hover_url' => 'https://graphs.example/node.png']],
        ]);

        $map = $this->editor->load();
        $link = $map->links['core-paris'];
        $this->assertSame('1G', $link->max_bandwidth_in_cfg);
        $this->assertSame('100M', $link->max_bandwidth_out_cfg);
        $this->assertSame('https://noc.example/paris', $link->infourl[IN]);
        $this->assertSame(['https://graphs.example/a.png', '/graphs/b.png'], $link->overliburl[IN]);
        $this->assertSame($link->overliburl[IN], $link->overliburl[OUT]);
        $this->assertSame(['https://graphs.example/node.png'], $map->nodes['paris']->overliburl[IN]);
        $this->assertStringContainsString('OVERLIBGRAPH https://graphs.example/a.png /graphs/b.png', (string) file_get_contents($this->file));

        $model = $this->drawnModel();
        $links = array_column($model['links'], null, 'name');
        $this->assertSame('100M', $links['core-paris']['bandwidth_out']);
        $this->assertSame('https://noc.example/paris', $links['core-paris']['infourl']);
        $this->assertSame('https://graphs.example/a.png /graphs/b.png', $links['core-paris']['hover_url']);
        $nodes = array_column($model['nodes'], null, 'name');
        $this->assertSame('https://graphs.example/node.png', $nodes['paris']['hover_url']);

        // cleared, the link takes its template's empty value again
        $this->editor->apply([['op' => 'set_link', 'names' => ['core-paris'], 'props' => ['hover_url' => '']]]);
        $this->assertSame([], $this->editor->load()->links['core-paris']->overliburl[IN]);

        try {
            $this->editor->apply([['op' => 'set_node', 'names' => ['paris'], 'props' => ['hover_url' => 'javascript:alert(1)']]]);
            $this->fail('accepted a javascript: address');
        } catch (InvalidArgumentException) {
            $this->assertSame(['https://graphs.example/node.png'], $this->editor->load()->nodes['paris']->overliburl[IN]);
        }
    }

    public function testCommentsCanFollowTheCurve(): void
    {
        $this->editor->apply([['op' => 'set_link', 'names' => ['paris-backup'], 'props' => ['commentcurve' => '1']]]);

        $map = $this->editor->load();
        $this->assertSame('1', (string) $map->links['paris-backup']->commentcurve);
        $this->assertSame('0', (string) $map->links['core-paris']->commentcurve);
        $this->assertMatchesRegularExpression('/^LINK paris-backup\s*$(?:\n(?!LINK|NODE).*)*\n\s*COMMENTCURVE 1$/m', str_replace("\r", '', (string) file_get_contents($this->file)));

        $links = array_column($this->drawnModel()['links'], 'commentcurve', 'name');
        $this->assertSame('1', $links['paris-backup']);
        $this->assertSame('0', $links['core-paris']);

        try {
            $this->editor->apply([['op' => 'set_link', 'names' => ['paris-backup'], 'props' => ['commentcurve' => 'yes']]]);
            $this->fail('accepted commentcurve yes');
        } catch (InvalidArgumentException) {
        }
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

    public function testMapNotesAreAddedAndEdited(): void
    {
        $r = $this->editor->apply([['op' => 'add_node', 'note' => true, 'x' => 100, 'y' => 80, 'text' => 'Hello']]);
        $this->assertSame(['nodes' => ['note']], $r['created']);

        $this->editor->apply([['op' => 'set_node', 'names' => ['note'], 'props' => [
            'note_text' => "\r\nMaintenance: Sunday\r\nNOC: +33 1 23 45 67 89\nC:\\path\n\n",
            'note_align' => 'centre',
        ]]]);

        $conf = (string) file_get_contents($this->file);
        $this->assertStringContainsString('LABEL Maintenance: Sunday\nNOC: +33 1 23 45 67 89\nC:\\\\path', $conf);
        $this->assertStringContainsString('SET editor_note 1', $conf);
        $this->assertStringContainsString('SET editor_note_escaped 1', $conf);
        $this->assertStringContainsString('SET editor_note_align centre', $conf);

        $model = array_column($this->drawnModel()['nodes'], null, 'name');
        $this->assertTrue($model['note']['note']);
        $this->assertSame("Maintenance: Sunday\nNOC: +33 1 23 45 67 89\nC:\\path", $model['note']['note_text']);
        $this->assertSame('centre', $model['note']['note_align']);
        $this->assertSame('', $model['note']['icon']);
        $this->assertFalse($model['core']['note']);

        // left is the default: the hint goes away
        $this->editor->apply([['op' => 'set_node', 'names' => ['note'], 'props' => ['note_align' => 'left']]]);
        $this->assertStringNotContainsString('editor_note_align', (string) file_get_contents($this->file));

        $this->expectException(InvalidArgumentException::class);
        $this->editor->apply([['op' => 'set_node', 'names' => ['note'], 'props' => ['note_align' => 'justify']]]);
    }

    public function testTheColourScaleIsReplacedAndKeepsTheMapColours(): void
    {
        $this->editor->apply([['op' => 'set_map', 'props' => ['bg_colour' => '#102030']]]);
        $this->editor->apply([['op' => 'set_scale', 'steps' => [
            ['bottom' => 50, 'top' => 100, 'colour' => '#d55e00', 'tag' => 'busy'],
            ['bottom' => 0, 'top' => 0, 'colour' => '#9aa0a6'],
            ['bottom' => 0, 'top' => 50, 'colour' => '#0072b2', 'colour2' => '#56b4e9'],
            ['bottom' => 2.5, 'top' => 3, 'colour' => 'none'],
        ]]]);

        $conf = (string) file_get_contents($this->file);
        $this->assertMatchesRegularExpression('/^SCALE DEFAULT 0\s+0\s+154 160 166/m', $conf);
        $this->assertMatchesRegularExpression('/^SCALE DEFAULT 0\s+50\s+0 114 178\s+86 180 233/m', $conf);
        $this->assertMatchesRegularExpression('/^SCALE DEFAULT 50\s+100\s+213  94   0\s+busy$/m', $conf);
        $this->assertMatchesRegularExpression('/^SCALE DEFAULT 2.5\s+3\s+none/m', $conf);
        $this->assertSame(4, preg_match_all('/^SCALE DEFAULT /m', $conf));
        $this->assertStringContainsString('SET key_hidezero_DEFAULT 1', $conf);

        $map = $this->editor->load();
        $model = MapEditor::model($map);
        $this->assertSame([
            ['bottom' => 0, 'top' => 0, 'colour' => '#9aa0a6', 'colour2' => '', 'tag' => ''],
            ['bottom' => 0, 'top' => 50, 'colour' => '#0072b2', 'colour2' => '#56b4e9', 'tag' => ''],
            ['bottom' => 2.5, 'top' => 3, 'colour' => 'none', 'colour2' => '', 'tag' => ''],
            ['bottom' => 50, 'top' => 100, 'colour' => '#d55e00', 'colour2' => '', 'tag' => 'busy'],
        ], $model['map']['scale']);
        $this->assertSame('#102030', $model['map']['props']['bg_colour'], 'the map colours stay');

        // a link at 75 % takes the colour of its step
        [$colour, $key, $tag] = $map->NewColourFromPercent(75, 'DEFAULT');
        $this->assertSame([213, 94, 0], [$colour->r, $colour->g, $colour->b]);
        $this->assertSame(['50_100', 'busy'], [$key, $tag]);
    }

    public function testSaturatedLinksPulseIsAMapSwitch(): void
    {
        $this->editor->apply([['op' => 'set_map', 'props' => ['traffic_pulse' => true]]]);
        $this->assertStringContainsString('SET traffic_pulse 1', (string) file_get_contents($this->file));
        $this->assertTrue(MapEditor::model($this->editor->load())['map']['props']['traffic_pulse']);

        $this->editor->apply([['op' => 'set_map', 'props' => ['traffic_pulse' => false]]]);
        $this->assertStringNotContainsString('traffic_pulse', (string) file_get_contents($this->file));
        $this->assertFalse(MapEditor::model($this->editor->load())['map']['props']['traffic_pulse']);
    }

    public function testLinkGlowIsAMapSwitch(): void
    {
        $this->editor->apply([['op' => 'set_map', 'props' => ['link_glow' => true]]]);
        $this->assertStringContainsString('SET link_glow 1', (string) file_get_contents($this->file));
        $this->assertTrue(MapEditor::model($this->editor->load())['map']['props']['link_glow']);

        $this->editor->apply([['op' => 'set_map', 'props' => ['link_glow' => false]]]);
        $this->assertStringNotContainsString('link_glow', (string) file_get_contents($this->file));
    }

    public function testLinkFlowIsAMapSwitch(): void
    {
        $this->editor->apply([['op' => 'set_map', 'props' => ['link_flow' => true]]]);
        $this->assertStringContainsString('SET link_flow 1', (string) file_get_contents($this->file));
        $this->assertTrue(MapEditor::model($this->editor->load())['map']['props']['link_flow']);

        $this->editor->apply([['op' => 'set_map', 'props' => ['link_flow' => false]]]);
        $this->assertStringNotContainsString('link_flow', (string) file_get_contents($this->file));
    }

    public function testPillLinkLabelsPerLinkAndByDefault(): void
    {
        $this->editor->apply([['op' => 'set_link', 'names' => ['core-paris'], 'props' => ['bwstyle' => 'pill']]]);
        $model = MapEditor::model($this->editor->load());
        $links = array_column($model['links'], null, 'name');
        $this->assertSame('pill', $links['core-paris']['bwstyle']);
        $this->assertSame('classic', $links['core-lyon']['bwstyle']);

        $this->editor->apply([['op' => 'set_map', 'props' => ['link_bwstyle' => 'angled']]]);
        $map = $this->editor->load();
        $this->assertSame('angled', $map->links['core-lyon']->labelboxstyle, 'a link with the default follows');
        $this->assertSame('pill', $map->links['core-paris']->labelboxstyle, 'its own style stays');
        $this->assertSame('angled', MapEditor::model($map)['map']['props']['link_bwstyle']);

        $this->expectException(InvalidArgumentException::class);
        $this->editor->apply([['op' => 'set_link', 'names' => ['core-paris'], 'props' => ['bwstyle' => 'round']]]);
    }

    public function testBadgeNodeStyleFollowsItsTemplate(): void
    {
        $this->editor->apply([['op' => 'set_node', 'names' => ['paris'], 'props' => ['style' => 'badge']]]);
        $this->assertStringContainsString('SET node_style badge', (string) file_get_contents($this->file));
        $this->assertTrue($this->editor->load()->nodes['paris']->is_badge());

        // a DEFAULT badge (written by hand) passes on to every node
        file_put_contents($this->file, str_replace("NODE core\n", "NODE DEFAULT\n\tSET node_style badge\n\nNODE core\n", (string) file_get_contents($this->file)));
        $this->editor->apply([['op' => 'set_node', 'names' => ['paris'], 'props' => ['style' => 'badge']]]);
        $map = $this->editor->load();
        $this->assertTrue($map->nodes['lyon']->is_badge(), 'nodes follow DEFAULT');
        $this->assertSame(1, substr_count((string) file_get_contents($this->file), 'SET node_style badge'), 'written once, on DEFAULT');

        $this->editor->apply([['op' => 'set_node', 'names' => ['lyon'], 'props' => ['style' => 'box']]]);
        $model = MapEditor::model($this->editor->load());
        $nodes = array_column($model['nodes'], null, 'name');
        $this->assertSame('box', $nodes['lyon']['style']);
        $this->assertSame('badge', $nodes['core']['style']);
        $this->assertStringContainsString('SET node_style box', (string) file_get_contents($this->file));

        $this->expectException(InvalidArgumentException::class);
        $this->editor->apply([['op' => 'set_node', 'names' => ['lyon'], 'props' => ['style' => 'hexagon']]]);
    }

    /** SET site groups nodes in the viewer's side panel; SET traffic_total 0 leaves a link out of the total */
    public function testSiteAndTrafficTotal(): void
    {
        $this->editor->apply([['op' => 'set_node', 'names' => ['paris', 'lyon'], 'props' => ['site' => "  Paris\n  north "]]]);
        $conf = (string) file_get_contents($this->file);
        $this->assertSame(2, substr_count($conf, 'SET site Paris north'), 'one line, spaces folded');
        $nodes = array_column(MapEditor::model($this->editor->load())['nodes'], null, 'name');
        $this->assertSame('Paris north', $nodes['paris']['site']);
        $this->assertSame('', $nodes['core']['site']);

        $this->editor->apply([['op' => 'set_node', 'names' => ['lyon'], 'props' => ['site' => '']]]);
        $this->assertSame(1, substr_count((string) file_get_contents($this->file), 'SET site'), 'an empty site removes the hint');

        $links = array_column(MapEditor::model($this->editor->load())['links'], null, 'name');
        $this->assertSame('1', $links['core-paris']['traffic_total'], 'every link counts by default');
        $this->editor->apply([['op' => 'set_link', 'names' => ['core-paris'], 'props' => ['traffic_total' => '0']]]);
        $this->assertStringContainsString('SET traffic_total 0', (string) file_get_contents($this->file));
        $links = array_column(MapEditor::model($this->editor->load())['links'], null, 'name');
        $this->assertSame('0', $links['core-paris']['traffic_total']);
        $this->editor->apply([['op' => 'set_link', 'names' => ['core-paris'], 'props' => ['traffic_total' => '1']]]);
        $this->assertStringNotContainsString('traffic_total', (string) file_get_contents($this->file), 'back to the default: no hint');

        $this->expectException(InvalidArgumentException::class);
        $this->editor->apply([['op' => 'set_link', 'names' => ['core-paris'], 'props' => ['traffic_total' => 'maybe']]]);
    }

    /** The Night theme: map colours, scale and the DEFAULT labels, in one batch; own values stay */
    public function testNightThemeInOneStep(): void
    {
        $this->editor->apply([['op' => 'set_node', 'names' => ['paris'], 'props' => ['labelbgcolour' => '#ff8000']]]);
        $this->editor->apply([['op' => 'apply_theme', 'theme' => 'night']]);

        $map = $this->editor->load();
        $props = MapEditor::model($map)['map']['props'];
        $this->assertSame('#11141a', $props['bg_colour']);
        $this->assertSame('modern', $props['key_style']);
        $this->assertSame('pill', $props['stamp_style']);
        $this->assertTrue($props['link_glow']);
        $this->assertSame('pill', $props['link_bwstyle']);
        [$colour] = $map->NewColourFromPercent(90, 'DEFAULT');
        $this->assertSame([229, 83, 28], [$colour->r, $colour->g, $colour->b]);

        $this->assertEquals([26, 30, 38], $map->nodes['lyon']->labelbgcolour, 'a node with the default follows');
        $this->assertEquals([255, 128, 0], $map->nodes['paris']->labelbgcolour, 'its own colour stays');
        $this->assertEquals([243, 231, 201], $map->nodes['lyon']->labelfontcolour);
        $this->assertEquals([-1, -1, -1], $map->links['core-paris']->outlinecolour);
        $this->assertEquals([26, 30, 38], $map->links['core-lyon']->bwboxcolour);

        $conf = (string) file_get_contents($this->file);
        $this->assertStringContainsString('LABELBGCOLOR 26 30 38', $conf);
        $this->assertSame(1, substr_count($conf, 'LABELBGCOLOR 26 30 38'), 'written once, on the DEFAULT node');

        try {
            $this->editor->apply([['op' => 'apply_theme', 'theme' => 'nope']]);
            $this->fail('an unknown theme is refused');
        } catch (InvalidArgumentException) {
            $this->assertSame($conf, (string) file_get_contents($this->file));
        }
    }

    public function testBackgroundSizingIsAMapSetting(): void
    {
        $this->assertSame('image', MapEditor::model($this->editor->load())['map']['props']['background_sizing']);

        foreach (['fit', 'stretch'] as $sizing) {
            $this->editor->apply([['op' => 'set_map', 'props' => ['background_sizing' => $sizing]]]);
            $this->assertStringContainsString('SET background_sizing ' . $sizing, (string) file_get_contents($this->file));
            $this->assertSame($sizing, MapEditor::model($this->editor->load())['map']['props']['background_sizing']);
        }

        // natural size is the default: the hint goes away
        $this->editor->apply([['op' => 'set_map', 'props' => ['background_sizing' => 'image']]]);
        $this->assertStringNotContainsString('background_sizing', (string) file_get_contents($this->file));

        $before = (string) file_get_contents($this->file);
        try {
            $this->editor->apply([['op' => 'set_map', 'props' => ['background_sizing' => 'cover']]]);
            $this->fail('an unknown sizing is refused');
        } catch (InvalidArgumentException) {
            $this->assertSame($before, (string) file_get_contents($this->file));
        }
    }

    public function testBadColourScalesAreRefused(): void
    {
        $before = (string) file_get_contents($this->file);
        foreach ([
            [],
            [['bottom' => 50, 'top' => 10, 'colour' => '#ffffff']],
            [['bottom' => 0, 'top' => 150, 'colour' => '#ffffff']],
            [['bottom' => 'a', 'top' => 10, 'colour' => '#ffffff']],
            [['bottom' => 0, 'top' => 10, 'colour' => 'contrast']],
            [['bottom' => 0, 'top' => 10, 'colour' => 'red']],
            [['bottom' => 0, 'top' => 10, 'colour' => '#ffffff'], ['bottom' => 0, 'top' => 10, 'colour' => '#000000']],
            array_fill(0, 33, ['bottom' => 0, 'top' => 1, 'colour' => '#ffffff']),
        ] as $steps) {
            try {
                $this->editor->apply([['op' => 'set_scale', 'steps' => $steps]]);
                $this->fail('accepted ' . json_encode(array_slice($steps, 0, 2)));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame($before, (string) file_get_contents($this->file));
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

    public function testModernLegendAndPillTimestamp(): void
    {
        $this->editor->apply([['op' => 'set_map', 'props' => ['key_shown' => true, 'key_style' => 'modern', 'key_size' => 260, 'stamp_style' => 'pill']]]);
        $conf = file_get_contents($this->file);
        $this->assertStringContainsString('KEYSTYLE  DEFAULT modern 260', $conf);
        $this->assertStringContainsString('SET timestamp_style pill', $conf);

        $model = $this->drawnModel();
        $this->assertSame('modern', $model['map']['props']['key_style']);
        $this->assertSame('pill', $model['map']['props']['stamp_style']);
        $legend = array_column($model['furniture'], null, 'kind')['legend'];
        $this->assertGreaterThanOrEqual(260, $legend['box'][2] - $legend['box'][0], 'the card holds the bar');

        $this->editor->apply([['op' => 'set_map', 'props' => ['stamp_style' => 'plain']]]);
        $this->assertStringNotContainsString('timestamp_style', file_get_contents($this->file));
    }

    public function testAShippedFontIsAddedToTheMap(): void
    {
        $cwd = getcwd();
        chdir(dirname(TESTS_ROOT) . '/src/public'); // the editor runs from the web root
        try {
            $result = $this->editor->apply([['op' => 'define_font', 'file' => 'fonts/FiraSans-Regular.ttf', 'size' => 11]]);
            $n = $result['created']['fonts'][0];
            $this->assertGreaterThanOrEqual(10, $n);
            $this->assertStringContainsString("FONTDEFINE $n fonts/FiraSans-Regular.ttf 11", file_get_contents($this->file));

            $info = $this->drawnModel()['font_info'][$n];
            $this->assertSame(['label' => 'Fira Sans', 'size' => 11, 'file' => 'fonts/FiraSans-Regular.ttf'], $info);

            $this->editor->apply([['op' => 'define_font', 'n' => $n, 'file' => 'fonts/FiraSans-Regular.ttf', 'size' => 14]]);
            $this->assertStringContainsString("FONTDEFINE $n fonts/FiraSans-Regular.ttf 14", file_get_contents($this->file));
        } finally {
            chdir($cwd);
        }
    }

    public function testOnlyShippedOrUsedFontsCanBeAdded(): void
    {
        foreach (['/etc/passwd', 'fonts/../../app.key.ttf', 'fonts/Missing.ttf'] as $file) {
            try {
                $this->editor->apply([['op' => 'define_font', 'file' => $file, 'size' => 10]]);
                $this->fail("$file was accepted");
            } catch (InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $this->editor->apply([['op' => 'define_font', 'file' => 'fonts/FiraSans-Regular.ttf', 'size' => 500]]);
    }

    public function testFontLabels(): void
    {
        $this->assertSame('IBM Plex Mono Semi Bold', MapEditor::fontLabel('fonts/IBMPlexMono-SemiBold.ttf'));
        $this->assertSame('DejaVu Sans Mono', MapEditor::fontLabel('/usr/share/fonts/DejaVuSansMono.ttf'));
        $this->assertContains('fonts/BarlowSemiCondensed-Regular.ttf', array_column(MapEditor::shippedFonts(), 'file'));
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

    public function testANodeLabelGoesToAPresetPlace(): void
    {
        $this->editor->apply([['op' => 'set_node', 'names' => ['backup'], 'props' => ['icon' => 'src/public/objects/net/router.png']]]);
        $before = array_column($this->drawnModel()['nodes'], null, 'name')['backup'];
        $this->assertSame('', $before['label_position']);
        $icon = $before['icon_box'];
        $label = $before['label_box'];

        // below: the label starts where the icon ends
        $this->editor->apply([['op' => 'set_node', 'names' => ['backup'], 'props' => ['label_position' => 's']]]);
        $this->assertStringContainsString('SET editor_label_position S', file_get_contents($this->file));
        $below = array_column($this->drawnModel()['nodes'], null, 'name')['backup'];
        $this->assertSame('S', $below['label_position']);
        $this->assertGreaterThan($label[1], $below['label_box'][1]);
        $this->assertEqualsWithDelta($icon[3], $below['label_box'][1], 2);

        // top of the icon: inside it, above the centre
        $this->editor->apply([['op' => 'set_node', 'names' => ['backup'], 'props' => ['label_position' => 'N60']]]);
        $top = array_column($this->drawnModel()['nodes'], null, 'name')['backup'];
        $this->assertSame('N60', $top['label_position']);
        $this->assertLessThan($label[1], $top['label_box'][1]);
        $this->assertGreaterThan($icon[1] - 8, $top['label_box'][1]);

        // a drag replaces the preset, a reset removes both
        $this->editor->apply([['op' => 'set_node', 'names' => ['backup'], 'props' => ['labelshift' => [$top['label_shift'][0] + 5, $top['label_shift'][1]]]]]);
        $this->assertStringNotContainsString('editor_label_position', file_get_contents($this->file));
        $moved = array_column($this->drawnModel()['nodes'], null, 'name')['backup'];
        $this->assertSame('', $moved['label_position']);
        $this->assertSame([$top['label_box'][0] + 5, $top['label_box'][1]], array_slice($moved['label_box'], 0, 2));

        $this->editor->apply([['op' => 'set_node', 'names' => ['backup'], 'props' => ['label_position' => 'C']]]);
        $this->editor->apply([['op' => 'set_node', 'names' => ['backup'], 'props' => ['labelshift' => null]]]);
        $this->assertStringNotContainsString('editor_label_position', file_get_contents($this->file));
        $this->assertStringNotContainsString('LABELOFFSET', file_get_contents($this->file));
        $this->assertSame($label, array_column($this->drawnModel()['nodes'], null, 'name')['backup']['label_box']);

        $this->expectException(\InvalidArgumentException::class);
        $this->editor->apply([['op' => 'set_node', 'names' => ['backup'], 'props' => ['label_position' => 'NE; DROP']]]);
    }

    public function testALabelPresetWinsOverTheTemplateCompassOffset(): void
    {
        file_put_contents($this->file, str_replace("LINK DEFAULT", "NODE DEFAULT\n\tLABELOFFSET S\n\nLINK DEFAULT", file_get_contents($this->file)));
        $this->editor->apply([['op' => 'set_node', 'names' => ['backup'], 'props' => ['icon' => 'src/public/objects/net/router.png']]]);
        $this->assertSame('S', array_column($this->drawnModel()['nodes'], null, 'name')['backup']['label_position']);

        $this->editor->apply([['op' => 'set_node', 'names' => ['backup'], 'props' => ['label_position' => 'N']]]);
        $conf = file_get_contents($this->file);
        $this->assertStringContainsString('SET editor_label_position N', $conf);
        $this->assertStringContainsString('LABELOFFSET N', $conf);
        $node = array_column($this->drawnModel()['nodes'], null, 'name')['backup'];
        $this->assertSame('N', $node['label_position']);
        $this->assertLessThan($node['icon_box'][1] + 2, $node['label_box'][3]);
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
