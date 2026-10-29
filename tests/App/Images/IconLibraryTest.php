<?php

declare(strict_types=1);

namespace Tests\App\Images;

use App\Services\Images\IconLibrary;
use App\Services\Images\SvgRasterizer;
use PHPUnit\Framework\TestCase;

final class IconLibraryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TESTS_OUTPUT . '/icon-library-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/public/objects/net', 0775, true);
        mkdir($this->root . '/configs', 0775, true);
        foreach (['cisco-router.png', 'generic-switch-l2-v1-mono.png', 'Host.png', 'red-ball-64.png', 'net/router.png'] as $file) {
            file_put_contents($this->root . '/public/objects/' . $file, self::png());
        }
        file_put_contents($this->root . '/public/objects/net/router.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="4" height="4"/>');
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->root);
    }

    public function testIconsAreSortedByTheme(): void
    {
        $themes = [];
        foreach ($this->library()->themes() as $t) {
            $themes[$t['theme']] = array_column($t['icons'], 'name');
        }

        $this->assertSame(['cisco', 'classic', 'generic', 'net', 'status'], array_keys($themes));
        $this->assertSame(['cisco-router'], $themes['cisco']);
        $this->assertSame(['Host'], $themes['classic']);
        $this->assertSame(['red-ball-64'], $themes['status']);
    }

    public function testTheSvgTwinIsThePreview(): void
    {
        $net = array_values(array_filter($this->library()->themes(), fn ($t) => $t['theme'] === 'net'))[0];
        $this->assertSame('objects/net/router.png', $net['icons'][0]['path']);
        $this->assertSame('objects/net/router.svg', $net['icons'][0]['preview']);
    }

    public function testAddABitmapToANewTheme(): void
    {
        $path = $this->library()->add('My Site', 'Core Router.PNG', self::png());

        $this->assertSame('objects/my-site/core-router.png', $path);
        $this->assertFileExists($this->root . '/public/' . $path);
        $this->assertContains($path, $this->library()->paths());
    }

    public function testAddAnSvgMakesItsPngTwin(): void
    {
        $png = TESTS_OUTPUT . '/icon-twin.png';
        file_put_contents($png, self::png());
        // a converter that copies a ready PNG, so the test needs no rsvg-convert
        $rasterizer = new SvgRasterizer(['cp', $png, '{out}']);
        $library = new IconLibrary($this->root . '/public', $this->root . '/configs', $rasterizer);

        $path = $library->add('net', 'switch.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" onload="alert(1)"><rect width="9" height="9"/></svg>');

        $this->assertSame('objects/net/switch.png', $path);
        $svg = (string) file_get_contents($this->root . '/public/objects/net/switch.svg');
        $this->assertStringNotContainsString('onload', $svg, 'the SVG is cleaned');
    }

    public function testRefusesWhatIsNotAnIcon(): void
    {
        foreach ([['a.php', '<?php echo 1;'], ['a.png', 'not a png'], ['b.svg', '<html/>']] as [$name, $data]) {
            try {
                $this->library()->add('net', $name, $data);
                $this->fail($name . ' was accepted');
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(\RuntimeException::class);
        $this->library()->add('net', 'router.png', self::png());
    }

    public function testDeleteRemovesBothTwins(): void
    {
        $this->library()->delete('objects/net/router.png');

        $this->assertFileDoesNotExist($this->root . '/public/objects/net/router.png');
        $this->assertFileDoesNotExist($this->root . '/public/objects/net/router.svg');
        $this->assertDirectoryDoesNotExist($this->root . '/public/objects/net', 'an empty theme folder goes too');
    }

    public function testAnIconInUseIsKept(): void
    {
        file_put_contents($this->root . '/configs/core.conf', "NODE a\n\tICON 32 32 images/objects/cisco-router.png\n\tPOSITION 1 1\n");

        try {
            $this->library()->delete('objects/cisco-router.png');
            $this->fail('an icon in use was removed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('core', $e->getMessage());
        }
        $this->assertFileExists($this->root . '/public/objects/cisco-router.png');
    }

    public function testDeleteStaysInTheLibrary(): void
    {
        file_put_contents($this->root . '/configs/x.conf', 'nothing');
        foreach (['../configs/x.conf', 'objects/../../configs/x.conf', 'backgrounds/a.png', 'objects/missing.png'] as $path) {
            try {
                $this->library()->delete($path);
                $this->fail($path . ' was accepted');
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertFileExists($this->root . '/configs/x.conf');
    }

    private function library(): IconLibrary
    {
        return new IconLibrary($this->root . '/public', $this->root . '/configs', new SvgRasterizer(['false']));
    }

    private static function png(): string
    {
        $im = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($im);
        return (string) ob_get_clean();
    }
}
