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

        // the furniture is drawn as vectors, one group per piece
        $this->assertSame(0, $xpath->query('//s:image[@class="wm-furniture"]')->length);
        $this->assertSame(1, $xpath->query('//s:g[@data-kind="title"]')->length, 'title');
        $this->assertSame(1, $xpath->query('//s:g[@data-kind="timestamp"][@data-name=""]/s:text')->length, 'timestamp');
    }

    public function testLegendsAreVectorsAtTheirPlace(): void
    {
        $conf = "WIDTH 700\nHEIGHT 500\nTITLE Legends\nTITLEPOS 20 30\n"
            . "KEYPOS DEFAULT 10 40 Load\nKEYSTYLE DEFAULT horizontal 300\n"
            . "KEYPOS other 400 40 Other\nKEYSTYLE other vertical 200\n"
            . "SCALE other 0 50 0 255 0\nSCALE other 50 100 255 0 0\n"
            . "SET width_legend 200 300\nSET link_width_table 1G:2 10G:6\n";
        $file = TESTS_OUTPUT . '/legends.conf';
        file_put_contents($file, $conf);

        $map = $this->load($file);
        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML(\WeatherMapSvg::render($map)));
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('s', 'http://www.w3.org/2000/svg');

        foreach (['DEFAULT' => [10, 40], 'other' => [400, 40]] as $scale => [$x, $y]) {
            $box = $xpath->query('//s:g[@data-kind="legend"][@data-name="' . $scale . '"]/s:rect')->item(0);
            $this->assertNotNull($box, "legend $scale");
            $this->assertSame([(string) $x, (string) $y], [$box->getAttribute('x'), $box->getAttribute('y')], "legend $scale is drawn at its KEYPOS");
        }
        $this->assertGreaterThan(0, $xpath->query('//s:g[@data-kind="legend"][@data-name="DEFAULT"]/s:line')->length, 'tick marks');
        $this->assertSame('Legends', $xpath->query('//s:g[@data-kind="title"]/s:text')->item(0)->textContent);
        $this->assertSame(1, $xpath->query('//s:g[@data-kind="widthlegend"]')->length);
        $this->assertSame(0, $xpath->query('//s:image')->length, 'nothing is left as a bitmap');
    }

    public function testModernLegendAndPillTimestampAreVectors(): void
    {
        $conf = "WIDTH 500\nHEIGHT 300\nKEYPOS DEFAULT 20 40 Traffic load\nKEYSTYLE DEFAULT modern 240\n"
            . "TIMEPOS 300 280 Created: %H:%M\nSET timestamp_style pill\n"
            . "SCALE DEFAULT 0 50 0 255 0\nSCALE DEFAULT 50 100 255 0 0\n";
        $file = TESTS_OUTPUT . '/modern-legend.conf';
        file_put_contents($file, $conf);

        $map = $this->load($file);
        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML(\WeatherMapSvg::render($map)));
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('s', 'http://www.w3.org/2000/svg');

        $legend = '//s:g[@data-kind="legend"][@data-name="DEFAULT"]';
        $this->assertSame(1, $xpath->query($legend . '/s:polygon')->length, 'the rounded card');
        $this->assertSame(2, $xpath->query($legend . '/s:rect')->length, 'one bar piece per colour');
        $this->assertSame(['Traffic load', '0%', '25%', '50%', '75%', '100%'], array_map(fn ($n) => $n->textContent, iterator_to_array($xpath->query($legend . '/s:text'))));
        $this->assertSame(1, $xpath->query('//s:g[@data-kind="timestamp"]/s:polygon')->length, 'the pill');
        $this->assertSame(0, $xpath->query('//s:image')->length, 'nothing is left as a bitmap');
    }

    /** A wide link between close nodes gets shorter arrowheads instead of vanishing */
    public function testAWideShortLinkIsStillDrawn(): void
    {
        $conf = "WIDTH 400\nHEIGHT 200\n"
            . "NODE a\n\tPOSITION 60 60\nNODE b\n\tPOSITION 300 60\nNODE c\n\tPOSITION 60 150\nNODE d\n\tPOSITION 120 150\n"
            . "LINK fast\n\tNODES a b\n\tBANDWIDTH 800G\n\tWIDTH auto\n"
            . "LINK short\n\tNODES c d\n\tWIDTH 15\n\tVIA 90 140\n"
            . "NODE e\n\tPOSITION 200 150\nNODE f\n\tPOSITION 270 150\nLINK tiny\n\tNODES e f\n\tBANDWIDTH 800G\n\tWIDTH auto\n";
        $file = TESTS_OUTPUT . '/wide-links.conf';
        file_put_contents($file, $conf);

        $map = $this->load($file);
        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML(\WeatherMapSvg::render($map)));
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('s', 'http://www.w3.org/2000/svg');
        foreach (['fast', 'short', 'tiny'] as $link) {
            $this->assertSame(2, $xpath->query('//s:g[@data-kind="link"][@data-name="' . $link . '"]/s:polygon[@class="wm-arrow"]')->length, "both arrows of $link");
        }
    }

    /** Map notes: one text element per line, lined up as asked, in the SVG and the PNG */
    public function testMapNotesAreAlignedLines(): void
    {
        $font = dirname(TESTS_ROOT) . '/src/public/fonts/FiraSans-Regular.ttf';
        $conf = "WIDTH 400\nHEIGHT 300\nFONTDEFINE 100 $font 10\n"
            . "NODE left\n\tLABEL Maintenance window\\nSunday 02:00\\nA \\\\ backslash\n\tLABELFONT 100\n\tSET editor_note 1\n\tSET editor_note_escaped 1\n\tPOSITION 120 60\n"
            . "NODE right\n\tLABEL Short\\nA much longer line\n\tLABELFONT 100\n\tSET editor_note 1\n\tSET editor_note_escaped 1\n\tSET editor_note_align right\n\tPOSITION 250 160\n"
            . "NODE centre\n\tLABEL ab\\nabcdef\n\tSET editor_note 1\n\tSET editor_note_escaped 1\n\tSET editor_note_align center\n\tPOSITION 120 240\n"
            . "NODE plain\n\tLABEL not a note\\nkept as is\n\tPOSITION 300 250\n";
        $file = TESTS_OUTPUT . '/notes.conf';
        file_put_contents($file, $conf);

        $map = $this->load($file);
        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML(\WeatherMapSvg::render($map)));
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('s', 'http://www.w3.org/2000/svg');
        $lines = function (string $node) use ($xpath): array {
            $out = [];
            foreach ($xpath->query('//s:g[@data-kind="node"][@data-name="' . $node . '"]/s:text[@class="wm-note"]') as $t) {
                $out[] = ['text' => $t->textContent, 'x' => (float) $t->getAttribute('x'), 'y' => (float) $t->getAttribute('y'), 'w' => (float) $t->getAttribute('textLength')];
            }
            return $out;
        };

        $left = $lines('left');
        $this->assertSame(['Maintenance window', 'Sunday 02:00', 'A \\ backslash'], array_column($left, 'text'));
        $this->assertSame(1, count(array_unique(array_column($left, 'x'))), 'left aligned');
        $this->assertGreaterThan($left[0]['y'], $left[1]['y']);
        $this->assertEqualsWithDelta($left[1]['y'] - $left[0]['y'], $left[2]['y'] - $left[1]['y'], 0.01, 'even line spacing');

        $right = $lines('right');
        $this->assertSame(['Short', 'A much longer line'], array_column($right, 'text'));
        $this->assertEqualsWithDelta($right[0]['x'] + $right[0]['w'], $right[1]['x'] + $right[1]['w'], 1.5, 'right aligned');

        $centre = $lines('centre');
        $this->assertEqualsWithDelta($centre[0]['x'] + $centre[0]['w'] / 2, $centre[1]['x'] + $centre[1]['w'] / 2, 1.5, 'centred');

        $this->assertSame([], $lines('plain'), 'a node label is not a note');

        // the label box holds every line
        $box = $map->nodes['left']->boundingboxes[0];
        $this->assertGreaterThan(3 * 12, $box[3] - $box[1]);

        $png = TESTS_OUTPUT . '/notes.png';
        ob_start();
        $this->load($file)->DrawMap($png);
        ob_end_clean();
        $this->assertFileExists($png);
    }

    public function testCommentsFollowCurvedLinks(): void
    {
        $file = TESTS_ROOT . '/fixtures/maps/curved-comments.conf';
        $conf = (string) file_get_contents($file);
        $paths = function (string $conf): array {
            $tmp = TESTS_OUTPUT . '/curved-comments.conf';
            file_put_contents($tmp, str_replace('src/public/fonts/', dirname(TESTS_ROOT) . '/src/public/fonts/', $conf));
            $doc = new \DOMDocument();
            $map = $this->load($tmp);
            $this->assertTrue($doc->loadXML(\WeatherMapSvg::render($map)), 'SVG is not well-formed XML');
            $xpath = new \DOMXPath($doc);
            $xpath->registerNamespace('s', 'http://www.w3.org/2000/svg');
            $out = [];
            foreach (['arch', 'straight', 'corner'] as $link) {
                $g = '//s:g[@data-kind="link"][@data-name="' . $link . '"]';
                $out[$link] = [
                    'curved' => array_map(fn ($t) => $t->textContent, iterator_to_array($xpath->query($g . '/s:text[@class="wm-comment"]/s:textPath'))),
                    'straight' => array_map(fn ($t) => $t->textContent, iterator_to_array($xpath->query($g . '/s:text[@class="wm-comment"][not(s:textPath)]'))),
                ];
                foreach ($xpath->query($g . '/s:text/s:textPath') as $tp) {
                    $id = substr($tp->getAttribute('href'), 1);
                    $this->assertSame(1, $xpath->query($g . '/s:path[@id="' . $id . '"]')->length, 'the text path exists');
                }
            }
            return $out;
        };

        $drawn = $paths($conf);
        $this->assertSame(['along the curve, near a', 'never upside down, near b'], $drawn['arch']['curved']);
        $this->assertSame([], $drawn['arch']['straight']);
        $this->assertSame([], $drawn['straight']['curved'], 'a straight link keeps straight text');
        $this->assertSame(['straight link, straight text', 'back the other way'], $drawn['straight']['straight']);
        $this->assertSame([], $drawn['corner']['curved'], 'a right angle is too tight');
        $this->assertSame(['too tight at the corner: straight'], $drawn['corner']['straight']);

        // COMMENTCURVE 0 (the default) and the bitmap fonts draw straight text
        foreach ([str_replace('COMMENTCURVE 1', 'COMMENTCURVE 0', $conf), str_replace('COMMENTFONT 100', 'COMMENTFONT 2', $conf)] as $variant) {
            $drawn = $paths($variant);
            $this->assertSame([], $drawn['arch']['curved']);
            $this->assertCount(2, $drawn['arch']['straight']);
        }
    }

    public function testNoteTextEscapes(): void
    {
        $text = "C:\\temp\\new\nsecond line";
        $encoded = \WeatherMapNode::note_encode($text);
        $this->assertSame('C:\\\\temp\\\\new\\nsecond line', $encoded);
        $this->assertStringNotContainsString("\n", $encoded);
        $this->assertSame($text, \WeatherMapNode::note_decode($encoded));
        $this->assertSame("a\nb \\x", \WeatherMapNode::note_decode('a\\nb \\x'), 'an unknown escape is kept');
    }

    /** SET traffic_pulse 1: links at 80 % or more pulse; off for the others and without the hint */
    public function testSaturatedLinksPulse(): void
    {
        $links = "NODE a\n\tPOSITION 50 50\nNODE b\n\tPOSITION 350 50\nNODE c\n\tPOSITION 50 150\nNODE d\n\tPOSITION 350 150\n"
            . "LINK busy\n\tNODES a b\n\tBANDWIDTH 1G\n\tTARGET static:900M:100M\n"
            . "LINK quiet\n\tNODES c d\n\tBANDWIDTH 1G\n\tTARGET static:500M:790M\n";
        $query = function (string $conf): array {
            $file = TESTS_OUTPUT . '/pulse.conf';
            file_put_contents($file, $conf);
            $map = $this->load($file);
            $svg = \WeatherMapSvg::render($map);
            $doc = new \DOMDocument();
            $this->assertTrue($doc->loadXML($svg));
            $xpath = new \DOMXPath($doc);
            $xpath->registerNamespace('s', 'http://www.w3.org/2000/svg');
            $hot = [];
            foreach ($xpath->query('//s:g[contains(concat(" ", @class, " "), " wm-hot ")]') as $g) {
                $hot[] = $g->getAttribute('data-name');
            }
            return [$hot, $xpath->query('//s:style[contains(., "wm-pulse")]')->length, $svg];
        };

        [$hot, $styles, $svg] = $query("WIDTH 400\nHEIGHT 200\nSET traffic_pulse 1\n" . $links);
        $this->assertSame(['busy'], $hot);
        $this->assertSame(1, $styles);
        $this->assertStringContainsString('prefers-reduced-motion', $svg);

        [$hot, $styles] = $query("WIDTH 400\nHEIGHT 200\n" . $links);
        $this->assertSame([], $hot);
        $this->assertSame(0, $styles, 'no style without a saturated link');
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
