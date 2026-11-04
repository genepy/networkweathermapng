<?php

declare(strict_types=1);

namespace Tests\App\Editor;

use App\Services\Editor\ConfigHistory;
use PHPUnit\Framework\TestCase;

/** Undo and redo refuse to overwrite a map saved outside the history */
final class ConfigHistoryTest extends TestCase
{
    private string $dir;
    private string $file;
    private ConfigHistory $history;

    protected function setUp(): void
    {
        $this->dir = TESTS_OUTPUT . '/history-' . $this->name();
        if (is_dir($this->dir)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
        } else {
            mkdir($this->dir, 0777, true);
        }
        $this->file = $this->dir . '/map.conf';
        file_put_contents($this->file, "WIDTH 100\n");
        $this->history = new ConfigHistory($this->dir . '/history', $this->file);
    }

    /** A change made by the editor: write the file, then record what it was */
    private function edit(string $content): void
    {
        $before = (string) file_get_contents($this->file);
        file_put_contents($this->file, $content);
        $this->history->record($before);
    }

    public function testUndoAndRedoWorkWhenNothingElseSaved(): void
    {
        $this->edit("WIDTH 200\n");
        $this->edit("WIDTH 300\n");

        $this->assertSame(['undo' => false, 'redo' => false], $this->history->changedOutside());
        $this->assertTrue($this->history->undo());
        $this->assertTrue($this->history->undo());
        $this->assertSame("WIDTH 100\n", file_get_contents($this->file));

        $this->assertTrue($this->history->redo());
        $this->assertTrue($this->history->redo());
        $this->assertSame("WIDTH 300\n", file_get_contents($this->file));
        $this->assertSame(['undo' => 2, 'redo' => 0], $this->history->counts());
    }

    public function testUndoIsRefusedAfterAnOutsideSave(): void
    {
        $this->edit("WIDTH 200\n");
        file_put_contents($this->file, "WIDTH 250\n");

        $this->assertSame(['undo' => true, 'redo' => false], $this->history->changedOutside());
        $this->assertFalse($this->history->undo());
        $this->assertSame("WIDTH 250\n", file_get_contents($this->file));
        $this->assertSame(['undo' => 1, 'redo' => 0], $this->history->counts());

        // putting the file back as the step left it allows the undo again
        file_put_contents($this->file, "WIDTH 200\n");
        $this->assertTrue($this->history->undo());
        $this->assertSame("WIDTH 100\n", file_get_contents($this->file));
    }

    public function testRedoIsRefusedAfterAnOutsideSave(): void
    {
        $this->edit("WIDTH 200\n");
        $this->assertTrue($this->history->undo());
        file_put_contents($this->file, "WIDTH 150\n");

        $this->assertSame(['undo' => false, 'redo' => true], $this->history->changedOutside());
        $this->assertFalse($this->history->redo());
        $this->assertSame("WIDTH 150\n", file_get_contents($this->file));
    }

    public function testOlderStepsStayRefusedAfterANewOne(): void
    {
        $this->edit("WIDTH 200\n");
        file_put_contents($this->file, "WIDTH 250\n");
        $this->edit("WIDTH 300\n");

        // the new step goes back to the outside version, not further
        $this->assertTrue($this->history->undo());
        $this->assertSame("WIDTH 250\n", file_get_contents($this->file));
        $this->assertSame(['undo' => true, 'redo' => false], $this->history->changedOutside());
        $this->assertFalse($this->history->undo());
        $this->assertSame("WIDTH 250\n", file_get_contents($this->file));
    }

    public function testStepsWithoutAFingerprintAreNotChecked(): void
    {
        // recorded before the change (or by an older version of the app)
        $this->history->record();
        file_put_contents($this->file, "WIDTH 200\n");

        $this->assertSame(['undo' => false, 'redo' => false], $this->history->changedOutside());
        $this->assertTrue($this->history->undo());
        $this->assertSame("WIDTH 100\n", file_get_contents($this->file));
        $this->assertTrue($this->history->redo());
        $this->assertSame("WIDTH 200\n", file_get_contents($this->file));
    }

    public function testTrimmedStepsLeaveNoFingerprintBehind(): void
    {
        for ($i = 1; $i <= ConfigHistory::LIMIT + 3; $i++) {
            $this->edit("WIDTH $i\n");
        }

        $this->assertCount(ConfigHistory::LIMIT, glob($this->dir . '/history/undo/*.conf'));
        $this->assertCount(ConfigHistory::LIMIT, glob($this->dir . '/history/undo/*.after'));
    }
}
