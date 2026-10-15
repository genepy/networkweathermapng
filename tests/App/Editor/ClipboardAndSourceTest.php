<?php

declare(strict_types=1);

namespace Tests\App\Editor;

use App\Services\Editor\ConfigSource;
use App\Services\Editor\MapClipboard;
use App\Services\Editor\MapEditor;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ClipboardAndSourceTest extends TestCase
{
    private string $dir;
    private string $file;
    private MapEditor $editor;

    protected function setUp(): void
    {
        $this->dir = TESTS_OUTPUT . '/editor-clip-' . $this->name();
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0777, true);
        }
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        $this->file = $this->dir . '/map.conf';
        copy(TESTS_ROOT . '/fixtures/maps/basic.conf', $this->file);
        $this->editor = new MapEditor($this->file);
    }

    public function testCopyGivesTheNodesAndTheLinksBetweenThem(): void
    {
        $clip = MapClipboard::copy($this->editor->load(), ['paris', 'backup']);

        $this->assertSame(['paris', 'backup'], $clip['nodes']);
        $this->assertSame(['paris-backup'], $clip['links']);
        $this->assertStringContainsString('NODE paris', $clip['text']);
        $this->assertStringContainsString('VIA 250 420', $clip['text']);
        $this->assertStringNotContainsString('LINK core-paris', $clip['text']);
        $this->assertStringNotContainsString('TITLE', $clip['text']);
    }

    public function testPasteInTheSameMapRenamesAndMovesEverything(): void
    {
        $clip = MapClipboard::copy($this->editor->load(), ['paris', 'backup']);

        $result = $this->editor->apply([['op' => 'paste', 'text' => $clip['text'], 'dx' => 20, 'dy' => 30]]);
        $this->assertSame(['paris_2', 'backup_2'], $result['created']['nodes']);
        $this->assertSame(['paris-backup_2'], $result['created']['links']);

        $map = $this->editor->load();
        $this->assertEquals([170, 350], [$map->nodes['paris_2']->x, $map->nodes['paris_2']->y]);
        $this->assertSame('paris-sw1', $map->nodes['paris_2']->label);
        $link = $map->links['paris-backup_2'];
        $this->assertSame('paris_2', $link->a->name);
        $this->assertSame('backup_2', $link->b->name);
        $this->assertEquals([[270, 450]], array_map(fn ($v) => [$v[0], $v[1]], $link->vialist));
        $this->assertSame('curved', $link->viastyle);
        // the originals are untouched
        $this->assertEquals([150, 320], [$map->nodes['paris']->x, $map->nodes['paris']->y]);

        // a second paste takes the next free names
        $result = $this->editor->apply([['op' => 'paste', 'text' => $clip['text'], 'dx' => 40, 'dy' => 40]]);
        $this->assertSame(['paris_3', 'backup_3'], $result['created']['nodes']);
    }

    public function testPasteInAnotherMapAtAPointKeepsTheShapeAndDropsOutsideLinks(): void
    {
        $clip = MapClipboard::copy($this->editor->load(), ['core', 'paris', 'lyon']);
        $this->assertSame(['core-paris', 'core-lyon'], $clip['links']);

        $other = $this->dir . '/other.conf';
        file_put_contents($other, "WIDTH 1000\nHEIGHT 800\n\nLINK DEFAULT\n\tWIDTH 2\n\nNODE core\n\tPOSITION 10 10\n");
        $target = new MapEditor($other);

        // the centre of the three nodes (400, 220) goes to (500, 500)
        $result = $target->apply([['op' => 'paste', 'text' => $clip['text'], 'at' => [500, 500]]]);
        $this->assertSame(['core_2', 'paris', 'lyon'], $result['created']['nodes']);

        $map = $target->load();
        $this->assertEquals([500, 400], [$map->nodes['core_2']->x, $map->nodes['core_2']->y]);
        $this->assertEquals([250, 600], [$map->nodes['paris']->x, $map->nodes['paris']->y]);
        $this->assertEquals([10, 10], [$map->nodes['core']->x, $map->nodes['core']->y]);
        $this->assertSame('core_2', $map->links['core-paris']->a->name);
        // the source map's DEFAULT link width (6) is kept on the pasted links
        $this->assertEquals(6, $map->links['core-paris']->width);
        $this->assertEquals(2, $map->links['DEFAULT']->width);
    }

    public function testTemplatesComeAlongWhenTheTargetLacksThem(): void
    {
        $src = $this->dir . '/src.conf';
        file_put_contents($src, "WIDTH 500\nHEIGHT 500\n\nNODE router\n\tLABELBGCOLOR 255 0 0\n\nNODE r1\n\tTEMPLATE router\n\tPOSITION 100 100\n\nNODE r2\n\tPOSITION r1 50 0\n");
        $clip = MapClipboard::copy((new MapEditor($src))->load(), ['r1', 'r2']);
        $this->assertSame(['router'], $clip['templates']);

        $other = $this->dir . '/other.conf';
        file_put_contents($other, "WIDTH 500\nHEIGHT 500\n");
        $target = new MapEditor($other);
        $target->apply([['op' => 'paste', 'text' => $clip['text'], 'dx' => 10, 'dy' => 10]]);

        $map = $target->load();
        $this->assertArrayHasKey('router', $map->nodes);
        $this->assertNull($map->nodes['router']->x);
        $this->assertSame('router', $map->nodes['r1']->template);
        $this->assertEquals([255, 0, 0], $map->nodes['r1']->labelbgcolour);
        // r2 stays attached to r1
        $this->assertSame('r1', $map->nodes['r2']->relative_to);
        $this->assertEquals([160, 110], [$map->nodes['r2']->x, $map->nodes['r2']->y]);
    }

    public function testPasteDropsLinksWhoseEndsAreMissing(): void
    {
        $text = "NODE a\n\tPOSITION 10 10\nLINK a-b\n\tNODES a b\nLINK a-a2\n\tNODES a a\n";
        $result = $this->editor->apply([['op' => 'paste', 'text' => $text]]);
        $this->assertSame(['a'], $result['created']['nodes']);
        $this->assertArrayNotHasKey('a-b', $this->editor->load()->links);
    }

    public function testPasteRefusesAnythingButNodeAndLinkBlocks(): void
    {
        $before = file_get_contents($this->file);

        foreach ([
            "INCLUDE /etc/passwd\nNODE x\n\tPOSITION 1 1\n",
            "NODE x\n\tPOSITION 1 1\n\tINCLUDE /etc/passwd\n",
            "WIDTH 100\nNODE x\n\tPOSITION 1 1\n",
            "NODE x\n\tPOSITION 1 1\n\tIMAGEOUTPUTFILE /tmp/x.png\n",
            "NODE x\n\tPOSITION 1 1\n\tSCALE DEFAULT 0 100 255 0 0\n",
            "NODE x\n\tPOSITION 1 1\n\tFONTDEFINE 9 /etc/x.ttf 10\n",
            "LINK l\n\tKEYPOS DEFAULT 1 1\n",
            "# nothing\n",
            str_repeat('#', MapClipboard::MAX_BYTES + 1),
        ] as $text) {
            try {
                $this->editor->apply([['op' => 'paste', 'text' => $text]]);
                $this->fail('accepted ' . substr($text, 0, 40));
            } catch (InvalidArgumentException) {
            }
        }

        $this->assertSame($before, file_get_contents($this->file));
    }

    public function testValidateGivesWarningsWithTheirLines(): void
    {
        $source = new ConfigSource();
        $text = "WIDTH 300\nHEIGHT 200\nNODE a\n\tPOSITION 10 10\nLINK l\n\tNODES a nowhere\nBOGUS keyword\n";

        $result = $source->validate($text, null);
        $this->assertSame([], $result['errors']);
        $lines = array_column($result['warnings'], 'line');
        $this->assertContains(6, $lines, json_encode($result['warnings']));
        $this->assertContains(7, $lines, json_encode($result['warnings']));
        $this->assertSame(1, $result['nodes']);
    }

    public function testIncludeAndOutputPathsAreRefused(): void
    {
        $source = new ConfigSource();
        $current = "WIDTH 300\nIMAGEOUTPUTFILE out/a.png\n";

        $result = $source->validate("WIDTH 300\nIMAGEOUTPUTFILE out/a.png\nINCLUDE /etc/passwd\n", $current);
        $this->assertSame(3, $result['errors'][0]['line']);

        $this->expectException(InvalidArgumentException::class);
        $source->check("WIDTH 300\nIMAGEOUTPUTFILE /var/www/x.php\n", $current);
    }

    public function testOutputPathsMayStayAsTheyAre(): void
    {
        $source = new ConfigSource();
        $current = "WIDTH 300\nIMAGEOUTPUTFILE out/a.png\n";
        $source->check("WIDTH 400\nIMAGEOUTPUTFILE   out/a.png\n", $current);

        $this->expectException(InvalidArgumentException::class);
        $source->check("WIDTH 400\n", $current);
    }
}
