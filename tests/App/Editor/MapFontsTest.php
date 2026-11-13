<?php

declare(strict_types=1);

namespace Tests\App\Editor;

use App\Services\Editor\MapEditor;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Fonts in the editor: any shipped font for any text of the map, one font
 * for the whole map, and Inter as the font of the Night theme.
 */
final class MapFontsTest extends TestCase
{
    private string $file;
    private MapEditor $editor;
    private string|false $cwd;

    protected function setUp(): void
    {
        $dir = TESTS_OUTPUT . '/fonts-' . $this->name();
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $this->file = $dir . '/map.conf';
        copy(TESTS_ROOT . '/fixtures/maps/basic.conf', $this->file);
        file_put_contents($this->file, "\nZONE area\n\tPOSITION 20 20\n\tSIZE 100 60\n\tLABEL Area\n", FILE_APPEND);
        $this->editor = new MapEditor($this->file);
        // the editor runs from the web root, where fonts/... are
        $this->cwd = getcwd();
        chdir(dirname(TESTS_ROOT) . '/src/public');
    }

    protected function tearDown(): void
    {
        if ($this->cwd !== false) {
            chdir($this->cwd);
        }
    }

    public function testInterIsShippedAsAFamily(): void
    {
        $families = MapEditor::fontFamilies();
        $this->assertSame(
            ['label' => 'Inter', 'regular' => 'fonts/Inter-Regular.ttf', 'bold' => 'fonts/Inter-SemiBold.ttf'],
            $families['Inter']
        );
        $this->assertSame('DejaVu Sans Mono', $families['DejaVuSansMono']['label']);
        $this->assertSame('fonts/DejaVuSansMono-Bold.ttf', $families['DejaVuSansMono']['bold']);
        $this->assertSame('Barlow Semi Condensed', $families['BarlowSemiCondensed']['label']);
    }

    public function testAnyShippedFontAtAnySizeForEveryKindOfText(): void
    {
        $this->editor->apply([
            ['op' => 'set_node', 'names' => ['core'], 'props' => ['labelfont' => 'fonts/Inter-SemiBold.ttf@11']],
            ['op' => 'set_node', 'names' => ['paris'], 'props' => ['labelfont' => 'fonts/Inter-SemiBold.ttf@11']],
            ['op' => 'set_link', 'names' => ['core-paris'], 'props' => ['bwfont' => 'fonts/Inter-Regular.ttf@9', 'commentfont' => 'fonts/FiraSans-Regular.ttf@8']],
            ['op' => 'set_zone', 'names' => ['area'], 'props' => ['labelfont' => 'fonts/BarlowSemiCondensed-Regular.ttf@12']],
            ['op' => 'set_map', 'props' => ['title_font' => 'fonts/Inter-SemiBold.ttf@16', 'key_font' => 'fonts/Inter-Regular.ttf@9', 'link_bwfont' => 'fonts/Inter-Regular.ttf@9']],
        ]);

        $map = $this->editor->load();
        $node = $map->nodes['core']->labelfont;
        $this->assertSame($node, $map->nodes['paris']->labelfont, 'the same file and size share one font');
        $this->assertSame('fonts/Inter-SemiBold.ttf', $map->fonts[$node]->file);
        $this->assertSame(11, (int) $map->fonts[$node]->size);
        $this->assertSame('fonts/FiraSans-Regular.ttf', $map->fonts[$map->links['core-paris']->commentfont]->file);
        $this->assertSame('fonts/BarlowSemiCondensed-Regular.ttf', $map->fonts[$map->zones['area']->labelfont]->file);
        $this->assertSame(16, (int) $map->fonts[$map->titlefont]->size);
        $this->assertSame($map->links['core-paris']->bwfont, $map->keyfont);
        $this->assertSame($map->keyfont, $map->links['core-lyon']->bwfont, 'the link default font reaches the links');
        $this->assertCount(5, array_filter($map->fonts, static fn ($f) => $f->type === 'truetype'), 'one FONTDEFINE per file and size');

        $model = MapEditor::model($map);
        $links = array_column($model['links'], null, 'name');
        $this->assertSame((int) $map->links['core-paris']->commentfont, $links['core-paris']['commentfont']);

        // a font number of the map is still taken as it is
        $this->editor->apply([['op' => 'set_node', 'names' => ['core'], 'props' => ['labelfont' => 2]]]);
        $this->assertSame(2, (int) $this->editor->load()->nodes['core']->labelfont);
    }

    public function testUnknownFontsAreRefused(): void
    {
        foreach (['fonts/Missing.ttf@10', '/etc/passwd@10', 'fonts/Inter-Regular.ttf@500', 'Inter', 42] as $value) {
            try {
                $this->editor->apply([['op' => 'set_node', 'names' => ['core'], 'props' => ['labelfont' => $value]]]);
                $this->fail("$value was accepted");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testOneFontForTheWholeMap(): void
    {
        $this->editor->apply([
            ['op' => 'set_node', 'names' => ['core'], 'props' => ['labelfont' => 'fonts/FiraSans-SemiBold.ttf@13']],
            ['op' => 'set_link', 'names' => ['core-paris'], 'props' => ['commentfont' => 'fonts/IBMPlexMono-Regular.ttf@7']],
        ]);
        $this->editor->apply([['op' => 'set_map_font', 'family' => 'Inter']]);

        $map = $this->editor->load();
        $used = [$map->titlefont, $map->timefont, $map->keyfont, $map->nodes['core']->labelfont, $map->nodes['lyon']->labelfont,
            $map->links['core-paris']->bwfont, $map->links['core-paris']->commentfont, $map->zones['area']->labelfont];
        foreach ($used as $n) {
            $this->assertGreaterThan(5, (int) $n, 'GD draws 1 to 5 itself');
            $this->assertSame('truetype', $map->fonts[$n]->type, "font $n");
            $this->assertStringStartsWith('fonts/Inter-', $map->fonts[$n]->file, "font $n");
        }
        $core = $map->fonts[$map->nodes['core']->labelfont];
        $this->assertSame(['fonts/Inter-SemiBold.ttf', 13], [$core->file, (int) $core->size], 'size and bold are kept');
        $comment = $map->fonts[$map->links['core-paris']->commentfont];
        $this->assertSame(['fonts/Inter-Regular.ttf', 7], [$comment->file, (int) $comment->size]);
        // the GD built-in fonts become TrueType at a matching size: 3 (node labels) is bold
        $lyon = $map->fonts[$map->nodes['lyon']->labelfont];
        $this->assertSame(['fonts/Inter-SemiBold.ttf', 8], [$lyon->file, (int) $lyon->size]);
        $this->assertSame($map->nodes['lyon']->labelfont, $map->nodes['DEFAULT']->labelfont, 'through the template');
        $this->assertStringNotContainsString('FONTDEFINE 3 ', (string) file_get_contents($this->file));

        // an item can still get its own font afterwards
        $this->editor->apply([['op' => 'set_node', 'names' => ['lyon'], 'props' => ['labelfont' => 'fonts/FiraSans-Regular.ttf@10']]]);
        $map = $this->editor->load();
        $this->assertSame('fonts/FiraSans-Regular.ttf', $map->fonts[$map->nodes['lyon']->labelfont]->file);
        $this->assertStringStartsWith('fonts/Inter-', $map->fonts[$map->nodes['paris']->labelfont]->file);

        $this->expectException(InvalidArgumentException::class);
        $this->editor->apply([['op' => 'set_map_font', 'family' => '../secret']]);
    }

    public function testTheNightThemeWritesInter(): void
    {
        $this->editor->apply([['op' => 'apply_theme', 'theme' => 'night']]);
        $conf = (string) file_get_contents($this->file);
        $this->assertMatchesRegularExpression('/^FONTDEFINE \d+ fonts\/Inter-Regular\.ttf \d+$/m', $conf);

        $map = $this->editor->load();
        $this->assertStringStartsWith('fonts/Inter-', $map->fonts[$map->nodes['core']->labelfont]->file);

        // a theme without a font leaves the fonts as they are
        $this->editor->apply([['op' => 'apply_theme', 'theme' => 'day']]);
        $this->assertStringStartsWith('fonts/Inter-', $this->editor->load()->fonts[$map->titlefont]->file);
    }
}
