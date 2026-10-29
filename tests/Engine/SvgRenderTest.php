<?php

declare(strict_types=1);

namespace Tests\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SvgRenderTest extends TestCase
{
    public static function maps(): array
    {
        $out = [];
        foreach (glob(TESTS_ROOT . '/fixtures/maps/*.conf') as $file) {
            $out[basename($file, '.conf')] = [$file];
        }
        return $out;
    }

    #[DataProvider('maps')]
    public function testSvgIsWellFormedAndHasEveryItem(string $file): void
    {
        $map = $this->load($file);
        $svg = \WeatherMapSvg::render($map);

        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($svg), 'SVG is not well-formed XML');

        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('s', 'http://www.w3.org/2000/svg');

        $root = $doc->documentElement;
        $this->assertSame((string) $map->width, $root->getAttribute('width'));
        $this->assertSame((string) $map->height, $root->getAttribute('height'));

        foreach ($map->nodes as $name => $node) {
            if ($node->x === null || $name === 'DEFAULT' || $name === ':: DEFAULT ::') {
                continue;
            }
            $this->assertSame(1, $xpath->query('//s:g[@data-kind="node"][@data-name="' . $name . '"]')->length, "node $name");
        }

        foreach ($map->links as $name => $link) {
            if (!isset($link->a)) {
                continue;
            }
            $arrows = $xpath->query('//s:g[@data-kind="link"][@data-name="' . $name . '"]/s:polygon');
            $this->assertGreaterThanOrEqual(1, $arrows->length, "link $name has arrows");
        }

        $this->assertSame(1, $xpath->query('//s:image[@class="wm-furniture"]')->length);
    }

    public function testPngIsUnchangedWhileCollecting(): void
    {
        $file = TESTS_ROOT . '/fixtures/maps/basic.conf';

        $plain = $this->load($file);
        $a = TESTS_OUTPUT . '/svg-plain.png';
        ob_start();
        $plain->DrawMap($a);
        ob_end_clean();

        $collected = $this->load($file);
        $collector = new \WeatherMapSvg($collected);
        \WeatherMapSvg::$active = $collector;
        $b = TESTS_OUTPUT . '/svg-collected.png';
        try {
            ob_start();
            $collected->DrawMap($b);
            ob_end_clean();
        } finally {
            \WeatherMapSvg::$active = null;
        }

        $this->assertSame(md5_file($a), md5_file($b));
    }

    public function testLinkColoursFollowTheData(): void
    {
        $map = $this->load(TESTS_ROOT . '/fixtures/maps/basic.conf');
        $svg = \WeatherMapSvg::render($map);

        // 90% on lyon-sw1 -> core-rtr1 falls in the red band of the scale
        $this->assertStringContainsString('fill="#ff0000"', $svg);
    }

    public function testBwLabelPosMovesTheLabels(): void
    {
        $centre = function (\WeatherMap $map, string $dir): array {
            $doc = new \DOMDocument();
            $doc->loadXML(\WeatherMapSvg::render($map));
            $xpath = new \DOMXPath($doc);
            $xpath->registerNamespace('s', 'http://www.w3.org/2000/svg');
            $poly = $xpath->query('//s:g[@data-name="core-lyon"]/s:polygon[@class="wm-bwlabel-' . $dir . '"]')->item(0);
            $pts = array_map('floatval', preg_split('/[ ,]/', $poly->getAttribute('points')));
            return [array_sum(array_filter($pts, fn ($k) => $k % 2 == 0, ARRAY_FILTER_USE_KEY)) / 4, 0];
        };

        $default = $this->load(TESTS_ROOT . '/fixtures/maps/basic.conf');
        $moved = $this->load(TESTS_ROOT . '/fixtures/maps/basic.conf');
        $moved->links['core-lyon']->labeloffset_in = 90;
        $moved->links['core-lyon']->labeloffset_out = 10;

        // core (400) -> lyon (650): IN moves towards lyon, OUT towards core
        $this->assertGreaterThan($centre($default, 'in')[0] + 20, $centre($moved, 'in')[0]);
        $this->assertLessThan($centre($default, 'out')[0] - 20, $centre($moved, 'out')[0]);
    }

    #[DataProvider('maps')]
    public function testBwLabelsAreDrawnOverEveryLink(string $file): void
    {
        $map = $this->load($file);
        $doc = new \DOMDocument();
        $doc->loadXML(\WeatherMapSvg::render($map));
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('s', 'http://www.w3.org/2000/svg');

        $lastArrow = -1;
        $firstLabel = null;
        foreach ($xpath->query('//s:g[@data-kind="link"]/*') as $i => $el) {
            $class = $el->getAttribute('class');
            if ($class === 'wm-arrow') {
                $lastArrow = $i;
            } elseif ($firstLabel === null && str_starts_with($class, 'wm-bwlabel-')) {
                $firstLabel = $i;
            }
        }

        if ($firstLabel === null) {
            $this->markTestSkipped('no bandwidth label on this map');
        }
        // links all sit in the default layer of these maps
        $this->assertGreaterThan($lastArrow, $firstLabel, 'a link drawn later would hide a label');
    }

    public function testVectorIcons(): void
    {
        $conf = "WIDTH 300\nHEIGHT 200\n"
            . "NODE a\n\tICON objects/net/router.png\n\tPOSITION 50 50\n"
            . "NODE b\n\tICON objects/net/switch-l2.svg\n\tPOSITION 150 50\n"
            . "NODE c\n\tICON objects/Router.png\n\tPOSITION 250 50\n";
        $file = TESTS_OUTPUT . '/vector-icons.conf';
        file_put_contents($file, $conf);

        $cwd = getcwd();
        chdir(dirname(TESTS_ROOT) . '/src/public');
        try {
            $map = $this->load($file);
            $svg = \WeatherMapSvg::render($map);
            $png = TESTS_OUTPUT . '/vector-icons.png';
            $map = $this->load($file);
            $map->DrawMap($png);
        } finally {
            chdir($cwd);
        }

        preg_match_all('/class="wm-icon"[^>]*href="data:image\/([a-z+]+);/', $svg, $m);
        $this->assertSame(['svg+xml', 'svg+xml', 'png'], $m[1], 'SVG twins are used, other icons stay bitmaps');
        $this->assertFileExists($png);
        $this->assertSame(48, (int) $map->nodes['b']->width, 'the .svg icon is drawn from its PNG twin');
    }

    private function load(string $file): \WeatherMap
    {
        $map = new \WeatherMap();
        ob_start();
        $map->ReadConfig($file);
        $map->ReadData();
        ob_end_clean();
        return $map;
    }
}
