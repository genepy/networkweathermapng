<?php

declare(strict_types=1);

namespace Tests\Engine;

use PHPUnit\Framework\TestCase;

final class ZoneTest extends TestCase
{
    private function load(string $conf): \WeatherMap
    {
        $map = new \WeatherMap();
        ob_start();
        $map->ReadConfig($conf);
        ob_end_clean();
        return $map;
    }

    private function writeAndRead(\WeatherMap $map): array
    {
        $file = TESTS_OUTPUT . '/zones-' . uniqid() . '.conf';
        $map->configfile = $file;
        foreach (array_merge($map->zones, $map->nodes, $map->links) as $item) {
            $item->defined_in = $file;
        }
        ob_start();
        $map->WriteConfig($file);
        ob_end_clean();
        $text = (string) file_get_contents($file);
        $again = $this->load($file);
        unlink($file);
        return [$text, $again];
    }

    public function testZoneBlockIsRead(): void
    {
        $map = $this->load(TESTS_ROOT . '/fixtures/maps/zones.conf');

        $this->assertSame(['paris', 'lyon'], array_keys($map->zones));
        $paris = $map->zones['paris'];
        $this->assertSame([20, 30, 220, 200], [$paris->x, $paris->y, $paris->width, $paris->height]);
        $this->assertSame('Paris DC', $paris->label);
        $this->assertSame('dashed', $paris->outlinestyle);
        $this->assertSame(2, $paris->outlinewidth);

        $lyon = $map->zones['lyon'];
        $this->assertSame('SE', $lyon->labelpos);
        $this->assertSame([255, 240, 220], $lyon->fillcolour);
        $this->assertSame(60, $lyon->opacity);

        // the blocks after the zones are read as usual
        $this->assertSame(100, (int) $map->nodes['a']->x);
        $this->assertSame('b', $map->links['ab']->b->name);
    }

    public function testZonesSurviveWriteConfig(): void
    {
        $map = $this->load("WIDTH 300\nHEIGHT 200\nZONE z1\n\tPOSITION 5 6\n\tSIZE 70 80\n\tLABEL Site one\n\tFILLCOLOR none\n\tOUTLINECOLOR 1 2 3\n\tLABELFONTCOLOR none\nNODE n\n\tPOSITION 10 10\n");

        [$text, $again] = $this->writeAndRead($map);

        $this->assertStringContainsString("ZONE z1\n\tPOSITION 5 6\n\tSIZE 70 80\n\tLABEL Site one\n\tFILLCOLOR none\n\tOUTLINECOLOR 1 2 3\n\tLABELFONTCOLOR none\n", $text);
        $z = $again->zones['z1'];
        $this->assertNull($z->fillcolour);
        $this->assertSame([1, 2, 3], $z->outlinecolour);
        $this->assertNull($z->labelfontcolour);
        $this->assertArrayHasKey('n', $again->nodes);
    }

    public function testZoneHintsSurviveWriteConfig(): void
    {
        $map = $this->load("WIDTH 300\nHEIGHT 200\nZONE z1\n\tPOSITION 5 6\n\tSIZE 70 80\n\tSET editor_group g1\nNODE n\n\tPOSITION 10 10\n");
        $this->assertSame(['editor_group' => 'g1'], $map->zones['z1']->hints);

        [$text, $again] = $this->writeAndRead($map);

        $this->assertStringContainsString("ZONE z1\n\tPOSITION 5 6\n\tSIZE 70 80\n\tSET editor_group g1\n", $text);
        $this->assertSame('g1', $again->zones['z1']->hints['editor_group']);
        $this->assertArrayNotHasKey('editor_group', $again->hints);
    }

    public function testUnknownZoneKeywordIsReported(): void
    {
        $map = new \WeatherMap();
        ob_start();
        $map->ReadConfig("ZONE z\n\tPOSITION 1 2\n\tICON foo.png\n");
        $output = ob_get_clean();

        $this->assertStringContainsString('Unrecognised config on line 3', $output);
        $this->assertSame([1, 2], [$map->zones['z']->x, $map->zones['z']->y]);
    }

    public function testZoneIsDrawnUnderNodesInPngAndSvg(): void
    {
        $map = $this->load("WIDTH 200\nHEIGHT 100\nZONE z\n\tPOSITION 10 10\n\tSIZE 100 60\n\tFILLCOLOR 255 0 0\n\tOUTLINECOLOR none\n\tLABEL Zed\n");
        $png = TESTS_OUTPUT . '/zone.png';
        ob_start();
        $map->DrawMap($png);
        ob_end_clean();

        $im = imagecreatefrompng($png);
        $inside = imagecolorsforindex($im, imagecolorat($im, 60, 60));
        $outside = imagecolorsforindex($im, imagecolorat($im, 150, 90));
        $this->assertSame([255, 0, 0], [$inside['red'], $inside['green'], $inside['blue']]);
        $this->assertNotSame([255, 0, 0], [$outside['red'], $outside['green'], $outside['blue']]);

        $svg = \WeatherMapSvg::render($map);
        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($svg));
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('s', 'http://www.w3.org/2000/svg');
        $box = $xpath->query('//s:g[@data-kind="zone"][@data-name="z"]/s:rect[@class="wm-zone-box"]')->item(0);
        $this->assertNotNull($box);
        $this->assertSame('#ff0000', $box->getAttribute('fill'));
        $this->assertSame(1, $xpath->query('//s:g[@data-kind="zone"]/s:text[@class="wm-zone-label"]')->length);
    }

    public function testScalingTheMapScalesZones(): void
    {
        $map = $this->load("WIDTH 200\nHEIGHT 100\nZONE z\n\tPOSITION 10 20\n\tSIZE 100 40\n");
        \WeatherMapScaler::scale($map, 400, 200);

        $z = $map->zones['z'];
        $this->assertSame([20, 40, 200, 80], [$z->x, $z->y, $z->width, $z->height]);
    }
}
