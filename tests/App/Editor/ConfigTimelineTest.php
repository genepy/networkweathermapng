<?php

declare(strict_types=1);

namespace Tests\App\Editor;

use App\Services\Editor\ConfigHistory;
use PHPUnit\Framework\TestCase;

/** The timeline of a map: saved versions with their time, savepoints, restore */
final class ConfigTimelineTest extends TestCase
{
    private string $dir;
    private string $file;
    private int $now = 1_800_000_000;
    private ConfigHistory $history;

    protected function setUp(): void
    {
        $this->dir = TESTS_OUTPUT . '/timeline-' . $this->name();
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
        $this->history = new ConfigHistory($this->dir . '/history', $this->file, fn (): int => $this->now);
    }

    /** A change made by the editor, $seconds after the last one */
    private function edit(string $content, int $seconds = 600): void
    {
        $this->now += $seconds;
        $before = (string) file_get_contents($this->file);
        file_put_contents($this->file, $content);
        $this->history->record($before);
    }

    /** @return list<string> kind:content of each version, newest first */
    private function summary(): array
    {
        return array_map(
            fn (array $v) => $v['kind'] . ':' . trim((string) ($v['id'] !== null ? $this->history->version($v['id']) : file_get_contents($this->file))) . ($v['current'] ? ' *' : ''),
            $this->history->versions()
        );
    }

    public function testANewMapShowsItsFileAsTheCurrentVersion(): void
    {
        $versions = $this->history->versions();
        $this->assertCount(1, $versions);
        $this->assertNull($versions[0]['id']);
        $this->assertTrue($versions[0]['current']);
        $this->assertSame('start', $versions[0]['kind']);
    }

    public function testEachChangeIsAVersionAndTheFirstKeepsTheMapBeforeIt(): void
    {
        $this->edit("WIDTH 200\n");
        $this->edit("WIDTH 300\n");

        $this->assertSame(['edit:WIDTH 300 *', 'edit:WIDTH 200', 'start:WIDTH 100'], $this->summary());
        $times = array_column($this->history->versions(), 'time');
        $this->assertSame([$this->now, $this->now - 600, $this->now - 600], $times);
    }

    public function testEditsWithinAMinuteShareOneVersion(): void
    {
        $this->edit("WIDTH 200\n");
        $this->edit("WIDTH 210\n", 10);
        $this->edit("WIDTH 220\n", ConfigHistory::MERGE_SECONDS - 1);

        $this->assertSame(['edit:WIDTH 220 *', 'start:WIDTH 100'], $this->summary());
        $this->assertSame($this->now, $this->history->versions()[0]['time']);
        // undo still goes back one step at a time
        $this->assertSame(3, $this->history->counts()['undo']);
    }

    public function testUndoAndRedoAreVersionsToo(): void
    {
        $this->edit("WIDTH 200\n");
        $this->now += 600;
        $this->assertTrue($this->history->undo());

        $this->assertSame(['edit:WIDTH 100 *', 'edit:WIDTH 200', 'start:WIDTH 100'], $this->summary());
    }

    public function testASaveMadeElsewhereIsKeptBeforeTheNextChange(): void
    {
        $this->edit("WIDTH 200\n");
        file_put_contents($this->file, "WIDTH 250\n");

        $versions = $this->history->versions();
        $this->assertNull($versions[0]['id']);
        $this->assertSame('outside', $versions[0]['kind']);
        $this->assertFalse($versions[1]['current']);

        $this->edit("WIDTH 300\n");
        $this->assertSame(['edit:WIDTH 300 *', 'outside:WIDTH 250', 'edit:WIDTH 200', 'start:WIDTH 100'], $this->summary());
    }

    public function testSavepointsAreNamedAndNotMergedIntoTheNextEdit(): void
    {
        $this->edit("WIDTH 200\n");
        $id = $this->history->savepoint("  Before\n the   test  ");
        $this->assertNotNull($id);
        $this->edit("WIDTH 300\n", 5);

        $versions = $this->history->versions();
        $this->assertSame(['edit', 'savepoint', 'edit', 'start'], array_column($versions, 'kind'));
        $this->assertSame('Before the test', $versions[1]['name']);
        $this->assertSame("WIDTH 200\n", $this->history->version($id));
    }

    public function testASavepointOfANeverEditedMapKeepsItsContent(): void
    {
        $id = $this->history->savepoint('Original');
        $this->assertSame("WIDTH 100\n", $this->history->version((string) $id));
        $this->assertSame(['savepoint:WIDTH 100 *', 'start:WIDTH 100'], $this->summary());
    }

    public function testAVersionCanBeNamedRenamedAndUnnamed(): void
    {
        $this->edit("WIDTH 200\n");
        $id = $this->history->versions()[0]['id'];

        $this->assertSame($id, $this->history->savepoint('Good state', $id));
        $this->assertSame('Good state', $this->history->versionInfo($id)['name']);
        $this->history->savepoint('Better name', $id);
        $this->assertSame('Better name', $this->history->versionInfo($id)['name']);

        $this->assertTrue($this->history->unname($id));
        $this->assertSame('', $this->history->versionInfo($id)['name']);
        $this->assertNull($this->history->savepoint('x', '99999999'));
        $this->assertNull($this->history->savepoint('x', '../../etc'));
        $this->assertFalse($this->history->unname('nope'));
    }

    public function testRestoringIsANewVersionAndCanBeUndone(): void
    {
        $this->edit("WIDTH 200\n");
        $id = (string) $this->history->savepoint('Before the test');
        $this->edit("WIDTH 999\n");

        $this->assertTrue($this->history->restore($id));
        $this->assertSame("WIDTH 200\n", file_get_contents($this->file));
        $latest = $this->history->versions()[0];
        $this->assertSame('restore', $latest['kind']);
        $this->assertTrue($latest['current']);
        $this->assertSame('Before the test', $latest['from']['name']);

        // restoring what the map already is does nothing
        $this->assertFalse($this->history->restore($id));
        $this->assertFalse($this->history->restore('00000999'));

        $this->assertTrue($this->history->undo());
        $this->assertSame("WIDTH 999\n", file_get_contents($this->file));
        $this->assertSame(['undo' => false, 'redo' => false], $this->history->changedOutside());
    }

    public function testOldVersionsAreDroppedButSavepointsStay(): void
    {
        $this->edit("WIDTH 1\n");
        $this->history->savepoint('Keep me');
        for ($i = 2; $i <= ConfigHistory::VERSION_LIMIT + 5; $i++) {
            $this->edit("WIDTH $i\n");
        }

        $versions = $this->history->versions();
        $named = array_filter($versions, static fn (array $v) => $v['name'] !== '');
        $this->assertCount(ConfigHistory::VERSION_LIMIT, array_filter($versions, static fn (array $v) => $v['name'] === ''));
        $this->assertCount(1, $named);
        $this->assertCount(ConfigHistory::VERSION_LIMIT + 1, glob($this->dir . '/history/versions/*.json'));
    }

    public function testImportsAreMarked(): void
    {
        $before = (string) file_get_contents($this->file);
        file_put_contents($this->file, "WIDTH 500\n");
        $this->history->record($before, 'import');

        $this->assertSame(['import:WIDTH 500 *', 'start:WIDTH 100'], $this->summary());
    }
}
