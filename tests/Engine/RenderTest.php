<?php

declare(strict_types=1);

namespace Tests\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Renders every map in tests/fixtures/maps and compares it with the
 * reference image in tests/fixtures/reference.
 *
 * Small anti-aliasing differences between GD builds are tolerated; anything
 * that moves an object or changes a colour fails. Run with
 * UPDATE_REFERENCE=1 to regenerate the references after an intended change.
 */
final class RenderTest extends TestCase
{
    /** Share of pixels allowed to differ before the render counts as changed. */
    private const MAX_DIFF_RATIO = 0.005;

    /** Per-channel difference under which two pixels count as equal. */
    private const CHANNEL_TOLERANCE = 24;

    public static function mapProvider(): array
    {
        $maps = [];
        foreach (glob(TESTS_ROOT . '/fixtures/maps/*.conf') as $file) {
            $maps[basename($file, '.conf')] = [$file];
        }
        return $maps;
    }

    #[DataProvider('mapProvider')]
    public function testMapRendersLikeReference(string $configFile): void
    {
        $name = basename($configFile, '.conf');
        $output = TESTS_OUTPUT . '/' . $name . '.png';
        $reference = TESTS_ROOT . '/fixtures/reference/' . $name . '.png';

        $map = new \WeatherMap();
        $map->context = 'cli';
        $map->ReadConfig($configFile);
        $map->ReadData();
        $map->DrawMap($output);

        $this->assertFileExists($output, 'The engine did not write an image');

        if (getenv('UPDATE_REFERENCE') === '1') {
            copy($output, $reference);
            $this->markTestSkipped("Reference updated: {$reference}");
        }

        $this->assertFileExists($reference, 'Missing reference image, run with UPDATE_REFERENCE=1');

        $ratio = $this->diffRatio($reference, $output, TESTS_OUTPUT . '/' . $name . '.diff.png');
        $this->assertLessThanOrEqual(
            self::MAX_DIFF_RATIO,
            $ratio,
            sprintf('%s differs from its reference on %.2f%% of pixels, see build/test-output', $name, $ratio * 100)
        );
    }

    /**
     * Returns the share of pixels that differ, and writes a diff image
     * (differing pixels in red) next to the rendered map.
     */
    private function diffRatio(string $expectedFile, string $actualFile, string $diffFile): float
    {
        $expected = imagecreatefrompng($expectedFile);
        $actual = imagecreatefrompng($actualFile);

        $width = imagesx($expected);
        $height = imagesy($expected);
        if ($width !== imagesx($actual) || $height !== imagesy($actual)) {
            return 1.0;
        }

        $diff = imagecreatetruecolor($width, $height);
        $red = imagecolorallocate($diff, 255, 0, 0);
        $different = 0;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $a = imagecolorsforindex($expected, imagecolorat($expected, $x, $y));
                $b = imagecolorsforindex($actual, imagecolorat($actual, $x, $y));
                if (abs($a['red'] - $b['red']) > self::CHANNEL_TOLERANCE
                    || abs($a['green'] - $b['green']) > self::CHANNEL_TOLERANCE
                    || abs($a['blue'] - $b['blue']) > self::CHANNEL_TOLERANCE) {
                    $different++;
                    imagesetpixel($diff, $x, $y, $red);
                }
            }
        }

        if ($different > 0) {
            imagepng($diff, $diffFile);
        }

        return $different / ($width * $height);
    }
}
