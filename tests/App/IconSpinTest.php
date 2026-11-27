<?php

declare(strict_types=1);

namespace Tests\App;

use App\Services\Editor\MapEditor;
use App\Services\Images\IconLibrary;
use App\Services\Images\SvgSanitizer;
use App\Services\MapService;
use PHPUnit\Framework\TestCase;

/**
 * Animated icons (feedback F43): shipped animated SVG icons, still in the
 * editor, and SET icon_spin, the icon turning with the traffic.
 */
final class IconSpinTest extends TestCase
{
    private const FAN = 'src/public/objects/animated/fan.png';

    private string $file;

    protected function setUp(): void
    {
        $dir = TESTS_OUTPUT . '/spin-' . $this->name();
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $this->file = $dir . '/map.conf';
        file_put_contents($this->file, "WIDTH 400\nHEIGHT 300\n"
            . "NODE a\n\tLABEL A\n\tICON " . self::FAN . "\n\tPOSITION 80 150\n\tSET icon_spin 1\n"
            . "NODE b\n\tLABEL B\n\tICON " . self::FAN . "\n\tPOSITION 320 80\n"
            . "NODE c\n\tLABEL C\n\tPOSITION 320 220\n\tSET icon_spin 1\n"
            . "LINK ab\n\tNODES a b\n\tBANDWIDTH 1G\n\tTARGET static:500M:100M\n"
            . "LINK ac\n\tNODES a c\n\tBANDWIDTH 1G\n\tTARGET static:900M:100M\n");
    }

    private function load(string $context): \WeatherMap
    {
        $map = new \WeatherMap();
        $map->context = $context;
        ob_start();
        $map->ReadConfig($this->file);
        $map->ReadData();
        ob_end_clean();
        return $map;
    }

    public function testTheShippedAnimatedIconsAreClean(): void
    {
        $root = dirname(TESTS_ROOT);
        foreach (['fan', 'wind-turbine'] as $name) {
            $svg = (string) file_get_contents($root . '/src/public/objects/animated/' . $name . '.svg');
            $this->assertStringContainsString('<animateTransform', $svg);
            $this->assertSame(trim($svg), trim((new SvgSanitizer())->sanitize($svg)), "$name passes the sanitizer as it is");
            $this->assertFileExists($root . '/src/public/objects/animated/' . $name . '.png', 'with its still PNG twin');
            $this->assertContains('objects/animated/' . $name . '.png', IconLibrary::shippedIcons());
            $this->assertFileDoesNotExist($root . '/src/public/objects/net/' . $name . '.svg', 'moved out of the Network theme (F59)');
        }
    }

    /** The datacenter icons: animated ones with CSS keyframes, still ones beside them */
    public function testTheShippedDatacenterIconsAreClean(): void
    {
        $root = dirname(TESTS_ROOT) . '/src/public/';
        $icons = [
            'animated' => ['pump', 'chiller', 'cooling-tower', 'crah', 'generator', 'ups'],
            'datacenter' => ['utility-feed', 'transformer', 'switchboard', 'pdu', 'it-room', 'heat-exchanger', 'groundwater-well', 'thermometer'],
        ];
        foreach ($icons as $theme => $names) {
            foreach ($names as $name) {
                $file = $root . 'objects/' . $theme . '/' . $name . '.svg';
                $svg = (string) file_get_contents($file);
                $this->assertSame(trim($svg), trim((new SvgSanitizer())->sanitize($svg)), "$name passes the sanitizer as it is");
                $this->assertSame($theme === 'animated', \WeatherMapSvg::isAnimated($file), "$name moves only in the Animated theme");
                $this->assertFileExists($root . 'objects/' . $theme . '/' . $name . '.png', 'with its PNG twin');
                $this->assertContains('objects/' . $theme . '/' . $name . '.png', IconLibrary::shippedIcons());
            }
        }
    }

    /** The viewer cannot stop an animation inside an image: the SVG carries the still twin */
    public function testAnAnimatedIconCarriesItsStillTwin(): void
    {
        $still = function (string $context): ?string {
            $doc = new \DOMDocument();
            $this->assertTrue($doc->loadXML(\WeatherMapSvg::render($this->load($context))));
            $xpath = new \DOMXPath($doc);
            $xpath->registerNamespace('s', 'http://www.w3.org/2000/svg');
            $icon = $xpath->query('//s:g[@data-name="a"]/s:image[@class="wm-icon"]')->item(0);
            $this->assertInstanceOf(\DOMElement::class, $icon);
            return $icon->hasAttribute('data-still') ? $icon->getAttribute('data-still') : null;
        };
        $this->assertStringStartsWith('data:image/png;base64,', (string) $still('viewer'));
        $this->assertNull($still('editor'), 'the editor already draws the still PNG');

        $js = (string) file_get_contents(dirname(TESTS_ROOT) . '/src/public/assets/js/viewer.js');
        $this->assertStringContainsString('stillIcons(svg)', $js);
    }

    public function testTheViewerTurnsTheIconWithTheBusiestLink(): void
    {
        $map = $this->load('viewer');
        $items = MapService::summarize($map)['items'];
        $this->assertSame(90.0, $items['N' . $map->nodes['a']->id]['spin'], 'the load of its busiest link');
        $this->assertArrayNotHasKey('spin', $items['N' . $map->nodes['b']->id] ?? [], 'no hint, no spin');
        $this->assertArrayNotHasKey('spin', $items['N' . $map->nodes['c']->id] ?? [], 'no icon, no spin');

        $js = (string) file_get_contents(dirname(TESTS_ROOT) . '/src/public/assets/js/viewer.js');
        $this->assertStringContainsString('spinIcons(svg)', $js);
        $css = (string) file_get_contents(dirname(TESTS_ROOT) . '/src/public/assets/css/viewer.css');
        $this->assertMatchesRegularExpression('/prefers-reduced-motion: reduce\)\s*\{\s*\.wmv-svg \.wm-icon\.wmv-spin \{ animation: none; \}/', $css);
    }

    public function testAnAnimatedIconMovesInTheViewerAndStaysStillInTheEditor(): void
    {
        $href = function (string $context): string {
            $doc = new \DOMDocument();
            $map = $this->load($context);
            $this->assertTrue($doc->loadXML(\WeatherMapSvg::render($map)));
            $xpath = new \DOMXPath($doc);
            $xpath->registerNamespace('s', 'http://www.w3.org/2000/svg');
            $icon = $xpath->query('//s:g[@data-name="a"]/s:image[@class="wm-icon"]')->item(0);
            $this->assertInstanceOf(\DOMElement::class, $icon);
            return $icon->getAttribute('href');
        };
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $href('viewer'), 'the animated SVG');
        $this->assertStringStartsWith('data:image/png;base64,', $href('editor'), 'its still PNG twin');
        $this->assertTrue(\WeatherMapSvg::isAnimated(dirname(TESTS_ROOT) . '/src/public/objects/animated/fan.svg'));
        $this->assertFalse(\WeatherMapSvg::isAnimated(dirname(TESTS_ROOT) . '/src/public/objects/net/router.svg'));
    }

    public function testTheEditorSetsTheSpin(): void
    {
        $editor = new MapEditor($this->file);
        $nodes = array_column(MapEditor::model($editor->load())['nodes'], null, 'name');
        $this->assertTrue($nodes['a']['icon_spin']);
        $this->assertFalse($nodes['b']['icon_spin']);

        $editor->apply([['op' => 'set_node', 'names' => ['b'], 'props' => ['icon_spin' => true]]]);
        $editor->apply([['op' => 'set_node', 'names' => ['a'], 'props' => ['icon_spin' => false]]]);
        $nodes = array_column(MapEditor::model($editor->load())['nodes'], null, 'name');
        $this->assertTrue($nodes['b']['icon_spin']);
        $this->assertFalse($nodes['a']['icon_spin']);
        $this->assertSame(2, substr_count((string) file_get_contents($this->file), 'SET icon_spin 1'), 'b, and c that was not touched');
    }

    /** F59: a map that still names objects/net/fan draws it, and the editor rewrites the path */
    public function testTheOldPathOfAMovedIconStillWorks(): void
    {
        $this->assertSame('src/public/objects/animated/fan.png', \WeatherMapNode::movedIcon('src/public/objects/net/fan.png'));
        $this->assertSame('objects/animated/wind-turbine.svg', \WeatherMapNode::movedIcon('objects/net/wind-turbine.svg'));
        $this->assertSame('objects/net/router.png', \WeatherMapNode::movedIcon('objects/net/router.png'));
        $this->assertSame('objects/mynet/fan.png', \WeatherMapNode::movedIcon('objects/mynet/fan.png'));

        $old = 'src/public/objects/net/fan.png';
        file_put_contents($this->file, str_replace(self::FAN, $old, (string) file_get_contents($this->file)));
        $map = $this->load('viewer');
        $this->assertStringContainsString('data:image/svg+xml;base64,', \WeatherMapSvg::render($map), 'the animated icon is drawn from its new place');

        $editor = new MapEditor($this->file);
        $this->assertTrue($editor->convertLegacy());
        $conf = (string) file_get_contents($this->file);
        $this->assertStringNotContainsString('objects/net/fan', $conf);
        $this->assertSame(2, substr_count($conf, 'ICON ' . self::FAN));
        $this->assertFalse($editor->convertLegacy());
    }

    public function testTheAnimatedThemeComesAfterTheProjectThemes(): void
    {
        $themes = array_column((new IconLibrary(dirname(TESTS_ROOT) . '/src/public'))->themes(), 'theme');
        $this->assertSame(['net', 'classic', 'status', 'clouds', 'generic', 'animated', 'datacenter'], array_slice($themes, 0, 7));
        $js = (string) file_get_contents(dirname(TESTS_ROOT) . '/src/public/assets/js/editor-icons.js');
        $this->assertStringContainsString("animated: t('Animated')", $js);
    }
}
