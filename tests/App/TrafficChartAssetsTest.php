<?php

declare(strict_types=1);

namespace Tests\App;

use PHPUnit\Framework\TestCase;

/**
 * UI redesign step 10: the traffic graph of the hover card and the node card
 * is drawn with uPlot, which weathermap.js loads from the address in the
 * data-uplot attributes of its script tag.
 */
final class TrafficChartAssetsTest extends TestCase
{
    private static function read(string $path): string
    {
        return (string) file_get_contents(dirname(TESTS_ROOT) . '/' . $path);
    }

    public function testUplotIsVendoredWithItsLicense(): void
    {
        $root = dirname(TESTS_ROOT) . '/src/public/assets/vendor/uplot/';
        $this->assertStringStartsWith('/*! https://github.com/leeoniya/uPlot', self::read('src/public/assets/vendor/uplot/uPlot.iife.min.js'));
        $this->assertFileExists($root . 'uPlot.min.css');
        $this->assertStringContainsString('Permission is hereby granted', self::read('src/public/assets/vendor/uplot/LICENSE.txt'));
        $this->assertStringContainsString('`src/public/assets/vendor/uplot/`', self::read('THIRD-PARTY-NOTICES.md'));
    }

    public function testEveryPageThatLoadsTheHoverCardGivesTheUplotAddress(): void
    {
        foreach (['src/app/Views/layouts/main.php', 'src/app/Views/maps/view.php'] as $view) {
            $html = self::read($view);
            $this->assertMatchesRegularExpression(
                "#assets/js/weathermap\\.js'\\) \\?>\" data-uplot=\"<\\?= asset\\('/assets/vendor/uplot/uPlot\\.iife\\.min\\.js'\\) \\?>\" data-uplot-css=\"<\\?= asset\\('/assets/vendor/uplot/uPlot\\.min\\.css'\\) \\?>\"#",
                $html,
                $view . ' loads weathermap.js without the uPlot address'
            );
        }
        $script = self::read('src/public/assets/js/weathermap.js');
        $this->assertStringContainsString("getAttribute('data-uplot')", $script);
        $this->assertStringContainsString('WM.trafficPanel = wmTrafficPanel', $script);
    }

    public function testTheNodeCardHasItsGraph(): void
    {
        $this->assertStringContainsString('class="wmv-card-traffic" hidden', self::read('src/app/Views/maps/view.php'));
        $this->assertStringContainsString('WM.trafficPanel(', self::read('src/public/assets/js/viewer.js'));
    }
}
