<?php

declare(strict_types=1);

namespace Tests\App\Images;

use App\Services\Images\BackgroundLibrary;
use PHPUnit\Framework\TestCase;

final class BackgroundLibraryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TESTS_OUTPUT . '/background-library-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/public/backgrounds/sites', 0775, true);
        mkdir($this->root . '/configs', 0775, true);
        foreach (['uk1024.png', 'Europe.png', 'sites/paris.png'] as $file) {
            file_put_contents($this->root . '/public/backgrounds/' . $file, self::png(8, 5));
        }
        file_put_contents($this->root . '/public/backgrounds/index.php', '<?php');
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->root);
    }

    public function testListsTheImagesWithTheirSizeAndUse(): void
    {
        file_put_contents($this->root . '/configs/core.conf', "WIDTH 800\nBACKGROUND images/backgrounds/uk1024.png\n");
        file_put_contents($this->root . '/configs/edge.conf', "BACKGROUND backgrounds/uk1024.png\n");

        $all = $this->library()->all();

        $this->assertSame(['Europe', 'paris', 'uk1024'], array_column($all, 'name'));
        $this->assertSame('backgrounds/sites/paris.png', $all[1]['path']);
        $this->assertSame([8, 5], [$all[0]['width'], $all[0]['height']]);
        $this->assertGreaterThan(0, $all[0]['bytes']);
        $this->assertSame(['core', 'edge'], $all[2]['used_by']);
        $this->assertSame([], $all[0]['used_by']);
    }

    public function testAddABackground(): void
    {
        $path = $this->library()->add('My Site Plan.PNG', self::png(20, 10));

        $this->assertSame('backgrounds/my-site-plan.png', $path);
        $this->assertFileExists($this->root . '/public/' . $path);
        $this->assertContains($path, $this->library()->paths());
    }

    public function testRefusesWhatIsNotABackground(): void
    {
        $cases = [
            ['a.php', '<?php echo 1;'],
            ['a.png', 'not a png'],
            ['a.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'],
            ['a.jpg', self::png(4, 4)], // a PNG named .jpg
            ['big.png', self::png(BackgroundLibrary::MAX_SIDE + 1, 2)],
            ['uk1024.jpg', self::jpeg()], // the name is taken by uk1024.png
        ];
        foreach ($cases as [$name, $data]) {
            try {
                $this->library()->add($name, $data);
                $this->fail($name . ' was accepted');
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertFileDoesNotExist($this->root . '/public/backgrounds/uk1024.jpg');
    }

    public function testDeleteRemovesAnUnusedBackground(): void
    {
        $this->library()->delete('backgrounds/sites/paris.png');
        $this->assertFileDoesNotExist($this->root . '/public/backgrounds/sites/paris.png');
    }

    public function testABackgroundInUseIsKept(): void
    {
        file_put_contents($this->root . '/configs/core.conf', "BACKGROUND images/backgrounds/Europe.png\n");

        try {
            $this->library()->delete('backgrounds/Europe.png');
            $this->fail('a background in use was removed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('core', $e->getMessage());
        }
        $this->assertFileExists($this->root . '/public/backgrounds/Europe.png');
    }

    public function testAShippedBackgroundIsKept(): void
    {
        $library = new BackgroundLibrary($this->root . '/public', $this->root . '/configs', ['backgrounds/uk1024.png']);

        $this->assertTrue(array_column($library->all(), 'shipped', 'name')['uk1024']);
        $this->expectException(\RuntimeException::class);
        try {
            $library->delete('backgrounds/uk1024.png');
        } finally {
            $this->assertFileExists($this->root . '/public/backgrounds/uk1024.png');
        }
    }

    public function testDeleteStaysInTheLibrary(): void
    {
        file_put_contents($this->root . '/configs/x.conf', 'nothing');
        foreach (['../configs/x.conf', 'backgrounds/../../configs/x.conf', 'backgrounds/index.php', 'objects/a.png', 'backgrounds/missing.png'] as $path) {
            try {
                $this->library()->delete($path);
                $this->fail($path . ' was accepted');
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertFileExists($this->root . '/configs/x.conf');
        $this->assertFileExists($this->root . '/public/backgrounds/index.php');
    }

    public function testTheShippedListIsUpToDate(): void
    {
        $src = dirname(__DIR__, 3) . '/src';
        exec('git -C ' . escapeshellarg($src) . ' ls-files -- public/backgrounds 2>/dev/null', $files, $status);
        if ($status !== 0 || $files === []) {
            $this->markTestSkipped('not a git checkout');
        }
        $backgrounds = [];
        foreach ($files as $file) {
            if (preg_match('#^public/(backgrounds/([^/]+/)?[^/]+\.(png|gif|jpe?g))$#i', $file, $m)) {
                $backgrounds[] = $m[1];
            }
        }
        sort($backgrounds);

        $this->assertSame($backgrounds, BackgroundLibrary::shippedBackgrounds(), 'run php src/bin/icons-manifest.php');
    }

    private function library(): BackgroundLibrary
    {
        return new BackgroundLibrary($this->root . '/public', $this->root . '/configs', []);
    }

    private static function png(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        ob_start();
        imagepng($im);
        return (string) ob_get_clean();
    }

    private static function jpeg(): string
    {
        $im = imagecreatetruecolor(4, 4);
        ob_start();
        imagejpeg($im);
        return (string) ob_get_clean();
    }
}
