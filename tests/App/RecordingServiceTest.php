<?php

declare(strict_types=1);

namespace Tests\App;

use App\Core\Config;
use App\Core\Database;
use App\Services\RecordingService;
use PHPUnit\Framework\TestCase;

/**
 * Map recording: settings, frames on disk, what the recorder does at
 * each pass.
 */
final class RecordingServiceTest extends TestCase
{
    private string $data;
    private RecordingService $recording;

    protected function setUp(): void
    {
        $this->data = TESTS_OUTPUT . '/recording';
        self::wipe($this->data);
        mkdir($this->data, 0775, true);

        $config = new Config();
        $config->set('DATA_ROOT', $this->data);
        $config->set('DB_TYPE', 'sqlite');
        $config->set('DB_PATH', $this->data . '/test.db');
        $this->recording = new RecordingService(new Database($config), $config);
    }

    public function testNothingIsRecordedUnlessAMapIsSwitchedOn(): void
    {
        $this->assertTrue($this->recording->isAllowed(), 'allowed until the admin turns it off');
        $this->assertSame([], $this->recording->recordedMaps());
        $this->assertFalse($this->recording->canPlay(1));

        $drawn = [];
        $this->assertSame(0, $this->recording->recordDue(function (int $id) use (&$drawn) {
            $drawn[] = $id;
            return ['svg' => '<svg/>'];
        }));
        $this->assertSame([], $drawn, 'no map is drawn');
    }

    public function testTheRecorderWritesAFrameEachInterval(): void
    {
        $this->recording->setRecorded(3, true);
        $this->recording->setInterval(5);
        $draw = static fn (int $id) => ['svg' => '<svg id="m' . $id . '"/>', 'items' => [], 'status' => ['max' => 42]];

        $t0 = 1_800_000_000;
        $this->assertSame(1, $this->recording->recordDue($draw, $t0));
        $this->assertSame(0, $this->recording->recordDue($draw, $t0 + 60), 'not due yet');
        $this->assertSame(1, $this->recording->recordDue($draw, $t0 + 300));

        $this->assertSame([$t0, $t0 + 300], $this->recording->frames(3, $t0 + 300));
        $this->assertSame(['svg' => '<svg id="m3"/>', 'items' => [], 'status' => ['max' => 42]], $this->recording->frame(3, $t0));
        $this->assertTrue($this->recording->canPlay(3));
    }

    /** The total traffic of each frame, for the graph of the viewer's side panel; worked out once */
    public function testTotalsOfTheFrames(): void
    {
        $this->recording->setRecorded(4, true);
        $frame = static fn (float $in, float $out) => ['svg' => '<svg/>', 'items' => [
            'L1' => ['kind' => 'link', 'in' => $in, 'out' => $out],
            'L2' => ['kind' => 'link', 'in' => 1.0, 'out' => 1.0, 'total' => false],
        ], 'status' => []];
        $t0 = 1_800_000_000;
        $this->recording->save(4, $frame(10, 20), $t0);
        $this->recording->save(4, $frame(30, 40), $t0 + 300);

        $this->assertSame([[$t0, 10.0, 20.0], [$t0 + 300, 30.0, 40.0]], $this->recording->totals(4, $t0 + 300));
        $this->assertFileExists($this->data . '/recordings/4/totals.json');

        // read from totals.json the next time
        $this->recording->save(4, $frame(99, 99), $t0);
        $this->assertSame([$t0, 10.0, 20.0], $this->recording->totals(4, $t0 + 300)[0]);

        // a frame past the retention leaves the totals too
        $later = $t0 + 300 + 24 * 3600;
        $this->assertSame([], $this->recording->totals(4, $later + 3600));
        $this->assertSame([], $this->recording->totals(9), 'a map without frames');
    }

    public function testOldFramesAreDeleted(): void
    {
        $this->recording->setRecorded(3, true);
        $this->recording->setRetention(1);
        $t0 = 1_800_000_000;
        $this->recording->save(3, ['svg' => 'a'], $t0);
        $this->recording->save(3, ['svg' => 'b'], $t0 + 1800);

        $this->recording->recordDue(static fn () => null, $t0 + 3700);
        $this->assertSame([$t0 + 1800], $this->recording->frames(3, $t0 + 3700));
        $this->assertNull($this->recording->frame(3, $t0));
    }

    public function testSwitchingAMapOffDropsItsFrames(): void
    {
        $this->recording->setRecorded(3, true);
        $this->recording->save(3, ['svg' => 'a'], time());
        $this->recording->setRecorded(3, false);

        $this->assertSame([], $this->recording->frames(3));
        $this->assertSame([], $this->recording->recordedMaps());
        $this->assertDirectoryDoesNotExist($this->data . '/recordings/3');
    }

    public function testTheAdminCanTurnRecordingOff(): void
    {
        $this->recording->setRecorded(3, true);
        $this->recording->setAllowed(false);

        $this->assertFalse($this->recording->canPlay(3));
        $this->assertSame(0, $this->recording->recordDue(static fn () => ['svg' => 'a']));
        $this->assertSame([3], $this->recording->recordedMaps(), 'the choice of maps is kept for later');
    }

    public function testTheRecorderOnlyHasWorkWhileAMapIsRecorded(): void
    {
        $this->assertFalse($this->recording->isActive(), 'no map switched on');
        $this->assertFalse($this->recording->startRecorder(__FILE__), 'nothing is started');

        $this->recording->setRecorded(3, true);
        $this->assertTrue($this->recording->isActive());

        $this->recording->setAllowed(false);
        $this->assertFalse($this->recording->isActive(), 'recording turned off for all');
        $this->recording->setAllowed(true);

        $this->recording->setRecorded(3, false);
        $this->assertFalse($this->recording->isActive());
    }

    public function testOnlyOneRecorderRuns(): void
    {
        $this->assertFalse($this->recording->isRecorderRunning());
        $lock = $this->recording->lockRecorder();
        $this->assertNotNull($lock);
        $this->assertNull($this->recording->lockRecorder(), 'a second recorder does not get the lock');
        $this->assertTrue($this->recording->isRecorderRunning());

        // while one runs, none is started even with a map recorded
        $this->recording->setRecorded(3, true);
        $this->assertFalse($this->recording->startRecorder(__FILE__));

        flock($lock, LOCK_UN);
        fclose($lock);
        $this->assertFalse($this->recording->isRecorderRunning());
    }

    public function testSettingsStayInTheirLimits(): void
    {
        $this->recording->setInterval(0);
        $this->recording->setRetention(10000);
        $this->assertSame(RecordingService::MIN_INTERVAL, $this->recording->getInterval());
        $this->assertSame(RecordingService::MAX_RETENTION, $this->recording->getRetention());
    }

    private static function wipe(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
