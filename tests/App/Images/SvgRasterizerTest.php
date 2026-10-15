<?php

declare(strict_types=1);

namespace Tests\App\Images;

use App\Services\Images\SvgRasterizer;
use PHPUnit\Framework\TestCase;

final class SvgRasterizerTest extends TestCase
{
    public function testSmallIconsKeepTheirSize(): void
    {
        $this->assertSame([64, 48], SvgRasterizer::targetSize('<svg xmlns="http://www.w3.org/2000/svg" width="64" height="48px"/>'));
        $this->assertSame([32, 16], SvgRasterizer::targetSize('<svg viewBox="0 0 32 16"/>'));
        $this->assertSame([20, 10], SvgRasterizer::targetSize('<svg width="20" viewBox="0 0 32 16"/>'));
    }

    public function testHugeSizesAreScaledDown(): void
    {
        $this->assertSame([1024, 1], SvgRasterizer::targetSize('<svg width="100000" height="100"/>'));
        $this->assertSame([512, 1024], SvgRasterizer::targetSize('<svg viewBox="0 0 50000 100000"/>'));
        $this->assertSame([1024, 1024], SvgRasterizer::targetSize('<svg width="1e9" height="1e9"/>'));
    }

    public function testUnknownSizeIsLeftToTheBoundedDefault(): void
    {
        $this->assertNull(SvgRasterizer::targetSize('<svg width="100%" height="10cm"/>'));
        $this->assertNull(SvgRasterizer::targetSize('<svg/>'));
    }

    public function testHugeSvgIsDrawnSmall(): void
    {
        $rasterizer = new SvgRasterizer();
        if (!$rasterizer->available()) {
            $this->markTestSkipped('needs rsvg-convert or ImageMagick');
        }

        $png = $rasterizer->toPng('<svg xmlns="http://www.w3.org/2000/svg" width="100000" height="50000"><rect width="100000" height="50000" fill="red"/></svg>');
        if ($png === null) {
            $this->markTestSkipped('the installed converter cannot read SVG');
        }

        $info = getimagesizefromstring($png);
        $this->assertSame([1024, 512], [$info[0], $info[1]]);
    }
}
