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

    /** SET node_style badge: a disc with the initials (or the icon), the label as its title; other nodes keep their box */
    public function testBadgeNodes(): void
    {
        $file = TESTS_OUTPUT . '/badge.conf';
        file_put_contents($file, "WIDTH 400\nHEIGHT 200\nNODE DEFAULT\n\tSET node_style badge\n"
            . "NODE a\n\tLABEL core-rtr1\n\tPOSITION 50 100\nNODE b\n\tLABEL plain\n\tPOSITION 350 100\n\tSET node_style box\n"
            . "LINK ab\n\tNODES a b\n\tBANDWIDTH 1G\n\tTARGET static:300M:100M\n");
        $map = $this->load($file);
        $svg = \WeatherMapSvg::render($map);
        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($svg));
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('s', 'http://www.w3.org/2000/svg');

        $a = '//s:g[@data-kind="node"][@data-name="a"]';
        $this->assertSame('core-rtr1', $xpath->query($a . '/s:title')->item(0)->textContent);
        $circle = $xpath->query($a . '/s:circle[@class="wm-badge"]')->item(0);
        $this->assertNotNull($circle);
        $this->assertSame(['50', '100'], [$circle->getAttribute('cx'), $circle->getAttribute('cy')]);
        $this->assertSame('CR', $xpath->query($a . '/s:text')->item(0)->textContent);
        $this->assertSame(0, $xpath->query($a . '/s:rect')->length, 'no label box');

        $b = '//s:g[@data-kind="node"][@data-name="b"]';
        $this->assertSame(0, $xpath->query($b . '/s:circle')->length);
        $this->assertSame('plain', $xpath->query($b . '/s:text')->item(0)->textContent);

        // the badge is the node's size, centred on its position
        $this->assertEqualsWithDelta($map->nodes['a']->width, 2 * (float) $circle->getAttribute('r') + 6, 3);

        $this->assertSame('CR', \WeatherMapNode::initials('core-rtr1'));
        $this->assertSame('PA', \WeatherMapNode::initials('Paris'));
        $this->assertSame('LS', \WeatherMapNode::initials('lyon sw1 {node:this:bandwidth_in}'));
        $this->assertSame('', \WeatherMapNode::initials(''));
    }

    /** BWSTYLE pill: a rounded box along the link and an arrow toward where the traffic goes */
    public function testPillLinkLabels(): void
    {
        $file = TESTS_OUTPUT . '/pill.conf';
        file_put_contents($file, "WIDTH 400\nHEIGHT 200\nNODE a\n\tPOSITION 50 100\nNODE b\n\tPOSITION 350 100\n"
            . "LINK ab\n\tNODES a b\n\tBANDWIDTH 1G\n\tBWSTYLE pill\n\tTARGET static:300M:100M\n");
        $map = $this->load($file);
        $this->assertSame('pill', $map->links['ab']->labelboxstyle);
        $svg = \WeatherMapSvg::render($map);
        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($svg));
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('s', 'http://www.w3.org/2000/svg');

        foreach (['out' => 1, 'in' => -1] as $dir => $sign) {
            $shapes = $xpath->query('//s:polygon[@class="wm-bwlabel-' . $dir . '"]');
            $this->assertSame(2, $shapes->length, "a pill and an arrow for $dir");
            $pill = self::points($shapes->item(0)->getAttribute('points'));
            $arrow = self::points($shapes->item(1)->getAttribute('points'));
            $this->assertCount(26, $pill, 'two half circles of 13 points');
            $this->assertCount(3, $arrow);
            // OUT goes from a to b (to the right), IN the other way: the tip is the far point
            $xs = array_column($arrow, 0);
            $this->assertSame($sign > 0 ? max($xs) : min($xs), $arrow[0][0], "the $dir arrow points the traffic way");
            $this->assertSame(1, $xpath->query('//s:text[@class="wm-bwlabel-' . $dir . '"]')->length);
        }

        $conf = TESTS_OUTPUT . '/pill-out.conf';
        $map->WriteConfig($conf);
        $this->assertStringContainsString("BWSTYLE pill", (string) file_get_contents($conf));
    }

    /** @return list<array{float, float}> */
    private static function points(string $s): array
    {
        return array_map(static fn ($p) => array_map('floatval', explode(',', $p)), preg_split('/\s+/', trim($s)));
    }

    /** SET link_glow 1: a glow filter on the link arrows under the pointer only, but not on saturated links nor in the plain SVG */
    public function testLinkGlow(): void
    {
        $conf = "WIDTH 400\nHEIGHT 200\nNODE a\n\tPOSITION 50 50\nNODE b\n\tPOSITION 350 50\n"
            . "LINK ab\n\tNODES a b\n\tBANDWIDTH 1G\n\tTARGET static:300M:100M\n";
        $file = TESTS_OUTPUT . '/glow.conf';

        file_put_contents($file, "SET link_glow 1\n" . $conf);
        $map = $this->load($file);
        $svg = \WeatherMapSvg::render($map);
        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($svg));
        $this->assertStringContainsString('<filter id="wm-glow"', $svg);
        $this->assertStringContainsString('.wm-link:not(.wm-hot):hover .wm-arrow{filter:url(#wm-glow)}', $svg);

        file_put_contents($file, $conf);
        $plain = $this->load($file);
        $this->assertStringNotContainsString('wm-glow', \WeatherMapSvg::render($plain));
    }

    /** SET link_flow 1: an invisible path per direction (OUT from A, IN from B, both to the split point), none without the hint */
    public function testLinkFlowPaths(): void
    {
        $conf = "WIDTH 400\nHEIGHT 200\nNODE a\n\tPOSITION 50 50\nNODE b\n\tPOSITION 350 50\nNODE c\n\tPOSITION 50 150\nNODE d\n\tPOSITION 350 150\n"
            . "LINK ab\n\tNODES a b\n\tBANDWIDTH 1G\n\tTARGET static:300M:100M\n"
            . "LINK cd\n\tNODES c d\n\tBANDWIDTH 1G\n\tLINKSTYLE oneway\n\tTARGET static:900M:100M\n";
        $file = TESTS_OUTPUT . '/flow.conf';

        file_put_contents($file, "SET link_flow 1\n" . $conf);
        $svg = \WeatherMapSvg::render($this->load($file));
        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($svg));
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('s', 'http://www.w3.org/2000/svg');

        $paths = [];
        foreach ($xpath->query('//s:g[@data-kind="link"]/s:path[@class="wm-flow"]') as $p) {
            $g = $p->parentNode;
            $this->assertInstanceOf(\DOMElement::class, $g);
            $paths[$g->getAttribute('data-name') . ':' . $p->getAttribute('data-dir')] = $p;
        }
        $this->assertSame(['ab:out', 'ab:in', 'cd:out'], array_keys($paths), 'a one-way link has no IN path');
        $this->assertSame('10', $paths['ab:out']->getAttribute('data-load'));
        $this->assertSame('30', $paths['ab:in']->getAttribute('data-load'));
        $this->assertSame('none', $paths['ab:out']->getAttribute('stroke'));
        $this->assertStringStartsWith('M50 50', $paths['ab:out']->getAttribute('d'));
        $this->assertStringEndsWith('L200 50', $paths['ab:out']->getAttribute('d'), 'OUT stops at the split point');
        $this->assertStringStartsWith('M350 50', $paths['ab:in']->getAttribute('d'));
        $this->assertStringEndsWith('L200 50', $paths['ab:in']->getAttribute('d'));
        $this->assertStringEndsWith('L350 150', $paths['cd:out']->getAttribute('d'), 'a one-way link flows to its end');

        file_put_contents($file, $conf);
        $this->assertStringNotContainsString('wm-flow', \WeatherMapSvg::render($this->load($file)));
    }

    /** Each link arrow carries its spine and sizes, for the viewer to draw it thinner deep in a basemap */
    public function testLinkArrowsCarryTheirShape(): void
    {
        $conf = "WIDTH 400\nHEIGHT 300\nNODE a\n\tPOSITION 50 50\nNODE b\n\tPOSITION 350 50\nNODE c\n\tPOSITION 50 150\nNODE d\n\tPOSITION 350 150\n"
            . "NODE e\n\tPOSITION 50 250\nNODE f\n\tPOSITION 350 250\n"
            . "LINK ab\n\tNODES a b\n\tWIDTH 4\n\tTARGET static:300M:100M\n"
            . "LINK cd\n\tNODES c d\n\tWIDTH 4\n\tLINKSTYLE oneway\n\tTARGET static:900M:100M\n"
            . "LINK ef\n\tNODES e f\n\tWIDTH 4\n\tVIA 200 200\n\tVIASTYLE curved\n\tTARGET static:900M:100M\n";
        $file = TESTS_OUTPUT . '/shape.conf';
        file_put_contents($file, $conf);
        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML(\WeatherMapSvg::render($this->load($file))));
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('s', 'http://www.w3.org/2000/svg');

        $shapes = [];
        foreach ($xpath->query('//s:g[@data-kind="link"]/s:polygon[@class="wm-arrow"]') as $p) {
            $this->assertInstanceOf(\DOMElement::class, $p);
            $g = $p->parentNode;
            $this->assertInstanceOf(\DOMElement::class, $g);
            $this->assertTrue($p->hasAttribute('data-spine'), 'every link arrow has its spine');
            $shapes[$g->getAttribute('data-name')][] = $p;
        }
        $this->assertSame(['ab', 'cd', 'ef'], array_keys($shapes));
        $this->assertCount(2, $shapes['ab']);
        $this->assertCount(1, $shapes['cd'], 'a one-way link has one arrow');

        $out = $shapes['ab'][0];
        $this->assertStringStartsWith('50,50 ', $out->getAttribute('data-spine'));
        $this->assertStringEndsWith(' 200,50', $out->getAttribute('data-spine'), 'OUT runs to the split point');
        $this->assertSame('4', $out->getAttribute('data-w'));
        $this->assertGreaterThan(4, (float) $out->getAttribute('data-aw'));
        $this->assertGreaterThan(0, (float) $out->getAttribute('data-as'));
        $this->assertStringStartsWith('350,50 ', $shapes['ab'][1]->getAttribute('data-spine'));
        $this->assertStringEndsWith(' 350,150', $shapes['cd'][0]->getAttribute('data-spine'), 'a one-way link runs to its end');
        $this->assertGreaterThan(3, count(explode(' ', $shapes['ef'][0]->getAttribute('data-spine'))), 'a curved link keeps its curve');

        // the legend and other polygons have none
        $this->assertSame(0, $xpath->query('//s:polygon[@data-spine][not(ancestor::s:g[@data-kind="link"])]')->length);
    }

    /** SET background_sizing: natural size (image), fit keeping the ratio, or stretch, in the PNG and the SVG */
    public function testBackgroundSizing(): void
    {
        $bg = TESTS_OUTPUT . '/sizing-bg.png';
        $im = imagecreatetruecolor(100, 50);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 0, 0));
        imagepng($im, $bg);
        imagedestroy($im);

        $draw = function (string $sizing) use ($bg): array {
            $file = TESTS_OUTPUT . '/sizing.conf';
            file_put_contents($file, "WIDTH 400\nHEIGHT 400\nBACKGROUND $bg\nBGCOLOR 255 255 255\n"
                . ($sizing !== '' ? "SET background_sizing $sizing\n" : ''));
            $map = $this->load($file);
            $png = TESTS_OUTPUT . '/sizing.png';
            ob_start();
            $map->DrawMap($png);
            ob_end_clean();
            $out = imagecreatefrompng($png);
            $red = function (int $x, int $y) use ($out): bool {
                $c = imagecolorsforindex($out, imagecolorat($out, $x, $y));
                return $c['red'] > 200 && $c['green'] < 50;
            };

            $doc = new \DOMDocument();
            $svgMap = $this->load($file);
            $this->assertTrue($doc->loadXML(\WeatherMapSvg::render($svgMap)));
            $image = $doc->getElementsByTagName('image')->item(0);
            $box = [];
            foreach (['x', 'y', 'width', 'height'] as $a) {
                $box[] = (int) $image->getAttribute($a);
            }

            return [$red, $box];
        };

        // natural size: top left corner only (the default, and the 2.0 "image")
        foreach (['', 'image', 'unknown'] as $sizing) {
            [$red, $box] = $draw($sizing);
            $this->assertTrue($red(50, 25));
            $this->assertFalse($red(150, 25));
            $this->assertFalse($red(50, 100));
            $this->assertSame([0, 0, 100, 50], $box);
        }

        // fit: 4 times larger, centred, the map colour above and below
        [$red, $box] = $draw('fit');
        $this->assertSame([0, 100, 400, 200], $box);
        $this->assertTrue($red(390, 110));
        $this->assertTrue($red(10, 290));
        $this->assertFalse($red(200, 90));
        $this->assertFalse($red(200, 310));

        // stretch: the whole map
        [$red, $box] = $draw('stretch');
        $this->assertSame([0, 0, 400, 400], $box);
        $this->assertTrue($red(5, 5));
        $this->assertTrue($red(395, 395));
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

    /** ARROWSTYLE flat ends a link square; SET bwlabel_offset_* moves a label across it */
    public function testFlatArrowsAndLabelShift(): void
    {
        $base = "WIDTH 400\nHEIGHT 200\n\nLINK DEFAULT\n\tWIDTH 4\n\nNODE a\n\tPOSITION 50 100\n\nNODE b\n\tPOSITION 350 100\n\n"
            . "LINK a-b\n\tNODES a b\n\tTARGET static:10M:20M\n";
        $points = function (string $extra) use ($base): array {
            $file = TESTS_OUTPUT . '/flat-arrows.conf';
            file_put_contents($file, $base . $extra);
            $doc = new \DOMDocument();
            $map = $this->load($file);
            $this->assertTrue($doc->loadXML(\WeatherMapSvg::render($map)));
            $xpath = new \DOMXPath($doc);
            $xpath->registerNamespace('s', 'http://www.w3.org/2000/svg');
            $arrow = $xpath->query('//s:g[@data-kind="link"]/s:polygon[@class="wm-arrow"]')->item(0);
            $this->assertNotNull($arrow);
            $label = $xpath->query('//s:polygon[contains(@class, "wm-bwlabel-out")]')->item(0);
            $this->assertNotNull($label);
            $ys = static function (\DOMElement $el): array {
                preg_match_all('/(-?[\d.]+)[ ,](-?[\d.]+)/', $el->getAttribute('points'), $m);
                return array_map('floatval', $m[2]);
            };
            return [$ys($arrow), $ys($label)];
        };

        [$classic, $label] = $points('');
        [$flat, $shifted] = $points("\tARROWSTYLE flat\n\tSET bwlabel_offset_out 20\n");
        // a classic head is wider than the link; a flat end is not
        $this->assertGreaterThan(max($flat), max($classic));
        $this->assertEqualsWithDelta(4, max($flat) - 100, 1);
        // positive is to the left of A to B: up on a link running right
        $this->assertEqualsWithDelta(-20, min($shifted) - min($label), 1);
    }

    /** Links stop where their square end is still hidden under the node, are drawn under their nodes, and the arrows still meet half way */
    public function testLinkEndsHideUnderTheirNodes(): void
    {
        $file = TESTS_OUTPUT . '/ends.conf';
        file_put_contents($file, "WIDTH 400\nHEIGHT 200\nNODE DEFAULT\n\tSET node_style badge\n"
            . "NODE a\n\tLABEL core\n\tPOSITION 50 100\n\tZORDER 650\nNODE b\n\tLABEL edge\n\tPOSITION 350 100\n"
            . "NODE c\n\tLABEL plain\n\tPOSITION 200 30\n\tSET node_style box\n\tLABELBGCOLOR none\n"
            . "LINK ab\n\tNODES a b\n\tWIDTH 9\n\tZORDER 900\n\tBANDWIDTH 1G\n\tTARGET static:300M:100M\n"
            . "LINK ac\n\tNODES a c\n\tWIDTH 4\n\tLINKSTYLE oneway\n\tBANDWIDTH 1G\n\tTARGET static:300M:100M\n");
        $map = $this->load($file);
        $svg = \WeatherMapSvg::render($map);
        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($svg));
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('s', 'http://www.w3.org/2000/svg');

        $r = (float) $xpath->query('//s:g[@data-name="a"]/s:circle')->item(0)->getAttribute('r');
        $arrows = $xpath->query('//s:g[@data-kind="link"][@data-name="ab"]/s:polygon[@data-spine]');
        $this->assertSame(2, $arrows->length);
        foreach ($arrows as $i => $arrow) {
            $this->assertInstanceOf(\DOMElement::class, $arrow);
            $spine = self::points($arrow->getAttribute('data-spine'));
            $centre = $i === 0 ? 50.0 : 350.0;
            $start = abs($spine[0][0] - $centre);
            // badge radius 15 (ring included), half width 9 + 1 of outline: sqrt(15² - 10²) = 11
            $this->assertGreaterThan(8, $start, 'the link starts away from the centre');
            $this->assertLessThan(15, $start, 'and still under the badge');
            $cut = explode(' ', $arrow->getAttribute('data-cut'));
            $this->assertEqualsWithDelta($start, (float) $cut[0], 0.5, 'the viewer knows how much was cut');
            // every corner of the end is under the badge
            foreach (self::points($arrow->getAttribute('points')) as $p) {
                if (abs($p[0] - $centre) < 20) {
                    $this->assertLessThanOrEqual(15.6, hypot($p[0] - $centre, $p[1] - 100));
                }
            }
            // the arrows still meet at the split point
            $this->assertEqualsWithDelta(200, end($spine)[0], 0.5);
        }
        $this->assertGreaterThan(0, $r);

        // a link with a higher ZORDER than its nodes is still drawn under them
        $order = [];
        foreach ($xpath->query('//s:g[@data-kind="link" or @data-kind="node"]') as $g) {
            $this->assertInstanceOf(\DOMElement::class, $g);
            $order[] = $g->getAttribute('data-name');
        }
        $this->assertLessThan(array_search('a', $order, true), array_search('ab', $order, true));
        $this->assertLessThan(array_search('b', $order, true), array_search('ab', $order, true));

        // a label without a background hides nothing: the one-way arrow reaches the centre of c
        $tip = $xpath->query('//s:g[@data-name="ac"]/s:polygon[@data-spine]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $tip);
        $spine = self::points($tip->getAttribute('data-spine'));
        $this->assertEqualsWithDelta(200, end($spine)[0], 0.5);
        $this->assertEqualsWithDelta(30, end($spine)[1], 0.5);
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
