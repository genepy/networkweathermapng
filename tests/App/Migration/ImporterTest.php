<?php

declare(strict_types=1);

namespace Tests\App\Migration;

use App\Services\Migration\MigrationImporter;
use App\Services\Migration\MigrationStore;
use PHPUnit\Framework\TestCase;

final class ImporterTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TESTS_OUTPUT . '/importer';
        $this->wipe($this->root);
        mkdir($this->root . '/public/objects', 0775, true);
        mkdir($this->root . '/public/backgrounds', 0775, true);
    }

    public function testZipWithMapsAndImages(): void
    {
        $png = $this->png();
        file_put_contents($this->root . '/public/objects/taken.png', $this->png(2));

        $zip = new \ZipArchive();
        $zipFile = $this->root . '/maps.zip';
        $zip->open($zipFile, \ZipArchive::CREATE);
        $zip->addFromString('weathermap/configs/core.conf', "TITLE Core\nBACKGROUND images/bg.png\nNODE a\n\tPOSITION 10 10\n");
        $zip->addFromString('weathermap/configs/sub/core.conf', "NODE b\n\tPOSITION 10 10\n");
        $zip->addFromString('weathermap/images/router.png', $png);
        $zip->addFromString('weathermap/images/bg.png', $png);
        $zip->addFromString('weathermap/images/taken.png', $png);
        $zip->addFromString('weathermap/images/fake.png', '<?php echo 1;');
        $zip->addFromString('weathermap/run.php', '<?php echo 1;');
        $zip->addFromString('__MACOSX/._core.conf', 'x');
        $zip->close();

        $store = new MigrationStore($this->root . '/migration');
        $result = (new MigrationImporter($store, $this->root . '/public'))->import(['maps.zip' => $zipFile]);

        $this->assertCount(2, $result['migrations']);
        $this->assertEqualsCanonicalizing(['objects/router.png', 'backgrounds/bg.png'], $result['images']);
        $this->assertFileExists($this->root . '/public/backgrounds/bg.png');
        $this->assertFileDoesNotExist($this->root . '/public/objects/fake.png');
        $this->assertFileDoesNotExist($this->root . '/public/objects/run.php');
        $this->assertSame($this->png(2), file_get_contents($this->root . '/public/objects/taken.png'), 'an existing file is never replaced');
        $this->assertCount(2, $result['skipped']);

        $names = array_column($store->all(), 'name');
        $this->assertContains('Core', $names);
        $this->assertContains('core-2', $names);
    }

    public function testStore(): void
    {
        $store = new MigrationStore($this->root . '/migration');
        $id = $store->create('Map', 'map.conf', "NODE a\n");

        $this->assertSame("NODE a\n", $store->original($id));
        $this->assertSame(['x' => 1], $store->update($id, ['choices' => ['x' => 1]])['choices']);
        $this->assertNull($store->get('../../etc'));
        $this->assertSame('', $store->original('../x'));

        $store->delete($id);
        $this->assertNull($store->get($id));
    }

    public function testPlainConfAndRejects(): void
    {
        file_put_contents($this->root . '/a.conf', "NODE a\n\tPOSITION 1 1\n");
        file_put_contents($this->root . '/notes.txt', "hello\n");

        $store = new MigrationStore($this->root . '/migration');
        $result = (new MigrationImporter($store, $this->root . '/public'))->import([
            'a.conf' => $this->root . '/a.conf',
            'notes.txt' => $this->root . '/notes.txt',
        ]);

        $this->assertCount(1, $result['migrations']);
        $this->assertSame(['notes.txt: not a Weathermap file'], $result['skipped']);
    }

    private function png(int $size = 1): string
    {
        $im = imagecreatetruecolor($size, $size);
        ob_start();
        imagepng($im);
        return (string) ob_get_clean();
    }

    private function wipe(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
