<?php

declare(strict_types=1);

namespace Tests\App\Editor;

use App\Services\Editor\MapEditor;
use App\Services\ViewerPanels;
use PHPUnit\Framework\TestCase;

/**
 * The older map items become cards when the editor opens a map
 * (MapEditor::convertLegacy(), F52 and F56).
 */
final class LegacyItemsTest extends TestCase
{
    private string $file;
    private MapEditor $editor;

    protected function setUp(): void
    {
        $dir = TESTS_OUTPUT . '/legacy-' . $this->name();
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $this->file = $dir . '/map.conf';
        $this->editor = new MapEditor($this->file);
    }

    /**
     * basic.conf with $extra after its header, its KEYPOS line replaced by
     * $keypos and its TIMEPOS line by $timepos (hidden by default)
     */
    private function write(string $keypos, string $extra = '', string $timepos = 'TIMEPOS -1 -1'): void
    {
        $conf = (string) file_get_contents(TESTS_ROOT . '/fixtures/maps/basic.conf');
        $conf = str_replace("KEYPOS DEFAULT 680 20 Trafic\n", $keypos === '' ? '' : $keypos . "\n", $conf);
        $conf = str_replace("TIMEPOS 10 490 Rendu de test\n", $timepos === '' ? '' : $timepos . "\n", $conf);
        $conf = str_replace("SET nowarn_clipping 1\n", "SET nowarn_clipping 1\n" . $extra, $conf);
        file_put_contents($this->file, $conf);
    }

    private function conf(): string
    {
        return (string) file_get_contents($this->file);
    }

    public function testAKeyposLegendBecomesAListCard(): void
    {
        $this->write('KEYPOS DEFAULT 680 20 Trafic');
        $this->assertTrue($this->editor->convertLegacy());

        $conf = $this->conf();
        $this->assertStringNotContainsString('KEYPOS', $conf);
        $this->assertStringContainsString('SET box_1 legend 680 20', $conf);
        $this->assertStringContainsString('SET box_1_title Trafic', $conf);
        $this->assertStringContainsString('SET box_1_style list', $conf, 'the classic legend was a list');

        $box = \WeatherMapBoxes::all($this->editor->load())['1'];
        $this->assertSame(['legend', 680, 20, 'Trafic', 'list', 'DEFAULT', false], [$box['type'], $box['x'], $box['y'], $box['title'], $box['style'], $box['scale'], $box['tags']]);
    }

    public function testTheBarAndTagStylesAndTheScaleAreKept(): void
    {
        $this->write(
            "KEYPOS DEFAULT 680 20\nKEYSTYLE DEFAULT horizontal 300\nKEYPOS other 20 300 Other\nKEYSTYLE other tags",
            "SCALE other 0 100 255 0 0 TAG\n"
        );
        $this->assertTrue($this->editor->convertLegacy());

        $boxes = array_values(\WeatherMapBoxes::all($this->editor->load()));
        $this->assertCount(2, $boxes);
        $byScale = array_column($boxes, null, 'scale');
        $this->assertSame('bar', $byScale['DEFAULT']['style']);
        $this->assertSame(300, $byScale['DEFAULT']['width']);
        $this->assertSame('list', $byScale['other']['style']);
        // one list (F62): it shows the step tags where the scale has them
        $this->assertStringContainsString('_style list', $this->conf());
        $this->assertSame('Other', $byScale['other']['title']);
        $this->assertStringNotContainsString('KEYSTYLE', $this->conf());
    }

    public function testAHiddenLegendIsDroppedAndTheWidthLegendBecomesACard(): void
    {
        // hidden by the older editor; the viewer panel was hidden too, so no card takes its place
        $this->write('KEYPOS DEFAULT -681 -21 Trafic', "SET width_legend 30 -40\nSET width_legend_title Capacité\nSET viewer_panels legend:side:hidden\n");
        $this->assertTrue($this->editor->convertLegacy());

        $conf = $this->conf();
        $this->assertStringNotContainsString('KEYPOS', $conf);
        $this->assertStringNotContainsString('width_legend', $conf);
        $this->assertStringContainsString('SET viewer_panels legend:side:hidden', $conf, 'kept: the viewer showed no legend');
        $boxes = array_values(\WeatherMapBoxes::all($this->editor->load()));
        $this->assertCount(1, $boxes);
        $this->assertSame(['speed', 30, -40, 'Capacité'], [$boxes[0]['type'], $boxes[0]['x'], $boxes[0]['y'], $boxes[0]['title']]);
    }

    public function testTheViewerLegendPanelGetsACard(): void
    {
        // no KEYPOS legend, and the viewer showed its legend panel
        $this->write('', "SET viewer_panels sites:side legend:top-left\n");
        $this->assertTrue($this->editor->convertLegacy());

        $conf = $this->conf();
        $this->assertStringContainsString('SET box_1 legend ' . implode(' ', MapEditor::LEGEND_PLACE), $conf);
        $this->assertStringNotContainsString('viewer_panels', $conf);
    }

    public function testASecondOpeningChangesNothing(): void
    {
        $this->write('KEYPOS DEFAULT 680 20 Trafic', "SET width_legend 30 -40\nSET kiosk_panels total:side legend:bottom-right\n");
        $this->assertTrue($this->editor->convertLegacy());
        $after = $this->conf();
        $this->assertStringContainsString('SET kiosk_panels total:side', $after);
        $this->assertStringNotContainsString('legend:', $after);

        $this->assertFalse($this->editor->convertLegacy());
        $this->assertSame($after, $this->conf());

        // an edit afterwards writes no older line back
        $this->editor->apply([['op' => 'move', 'nodes' => ['core' => [410, 130]]]]);
        $this->assertStringNotContainsString('KEYPOS', $this->conf());
        $this->assertFalse($this->editor->convertLegacy());
    }

    public function testAMapWithoutOlderItemsIsNotTouched(): void
    {
        $this->write('', "SET box_1 legend 10 40\n");
        $before = $this->conf();
        $this->assertFalse($this->editor->convertLegacy());
        $this->assertSame($before, $this->conf(), 'the file is not even rewritten');

        // the legend panel hidden: no card is added, the map is left as it is
        $this->write('', "SET viewer_panels legend:side:hidden total:top-right\n");
        $before = $this->conf();
        $this->assertFalse($this->editor->convertLegacy());
        $this->assertSame($before, $this->conf());
        $this->assertFalse(ViewerPanels::showedLegend($this->editor->load()));

        // a map with cards but no legend: its legend was removed in the editor
        $this->write('', "SET box_1 total 10 40\n");
        $this->assertFalse($this->editor->convertLegacy());
    }

    public function testATimestampBecomesTheStatusBarAtItsPlace(): void
    {
        $this->write('', '', 'TIMEPOS 10 490 Rendu de test');
        $this->assertTrue($this->editor->convertLegacy());

        $map = $this->editor->load();
        $this->assertSame([2, 472], ViewerPanels::statusAt($map), 'the card starts just above and left of the text');
        $this->assertSame([-1, -1], [(int) $map->timex, (int) $map->timey]);
        $this->assertStringContainsString('updated:map:2,472', $this->conf());
        $this->assertStringNotContainsString('Rendu de test', $this->conf());

        $after = $this->conf();
        $this->assertFalse($this->editor->convertLegacy(), 'a second opening changes nothing');
        $this->assertSame($after, $this->conf());
    }

    public function testNoTimeposMeansTheTopRightCorner(): void
    {
        $this->write('', '', '');
        $this->assertTrue($this->editor->convertLegacy());
        $this->assertSame([800 - 260, 10], ViewerPanels::statusAt($this->editor->load()));
    }

    public function testAnUpdateTimeCardBecomesTheStatusBar(): void
    {
        $this->write('', "SET box_2 updated -10 -10\nSET box_2_title Mise a jour\nSET box_1 total 10 40\n");
        $this->assertTrue($this->editor->convertLegacy());

        $conf = $this->conf();
        $this->assertStringNotContainsString('box_2', $conf);
        $this->assertStringContainsString('SET box_1 total 10 40', $conf);
        // a card placed from the bottom right corner: 800 - 10 - 260, 500 - 10 - 40
        $this->assertSame([530, 450], ViewerPanels::statusAt($this->editor->load()));
    }
}
