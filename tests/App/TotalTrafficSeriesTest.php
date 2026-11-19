<?php

declare(strict_types=1);

namespace Tests\App;

use App\Services\TotalTrafficSeries;
use PHPUnit\Framework\TestCase;

/** F28: one rule for the total traffic graph of every map */
class TotalTrafficSeriesTest extends TestCase
{
    public function testSource(): void
    {
        $this->assertSame('recorded', TotalTrafficSeries::source(true, true), 'recordings win over demo data');
        $this->assertSame('recorded', TotalTrafficSeries::source(true, false));
        $this->assertSame('demo', TotalTrafficSeries::source(false, true));
        $this->assertSame('none', TotalTrafficSeries::source(false, false));
    }

    public function testStaticOnly(): void
    {
        $this->assertTrue(TotalTrafficSeries::staticOnly(['static:1G:2G', '-static:5M:5M', '2*static:1:1']));
        $this->assertFalse(TotalTrafficSeries::staticOnly(['static:1G:2G', 'observium:port:12']));
        $this->assertFalse(TotalTrafficSeries::staticOnly([]), 'a map without targets has no data at all');
        $this->assertFalse(TotalTrafficSeries::staticOnly(['', ' ']));
    }

    public function testConfigTargets(): void
    {
        $config = implode("\n", [
            'LINK a-b',
            "\tTARGET static:9G:14G",
            'LINK c-d',
            '    TARGET "static:1G:1G" static:2G:2G',
            '# TARGET zabbix:12 in a comment',
        ]);
        $this->assertSame(['static:9G:14G', 'static:1G:1G', 'static:2G:2G'], TotalTrafficSeries::configTargets($config));
    }

    public function testEveryDemoMapGetsADemoGraph(): void
    {
        foreach (glob(dirname(__DIR__, 2) . '/src/examples/maps/*.conf') ?: [] as $file) {
            $targets = TotalTrafficSeries::configTargets((string) file_get_contents($file));
            $this->assertTrue(TotalTrafficSeries::staticOnly($targets), basename($file) . ' reads only static data');
        }
    }

    public function testDemoDayEndsOnTheCurrentTotals(): void
    {
        $now = 1_699_999_200;
        $rows = TotalTrafficSeries::demo(4e9, 2e9, $now);
        $this->assertCount(24 * 4 + 1, $rows, 'a point every 15 minutes over 24 h');
        $last = end($rows);
        $this->assertSame([4e9, 2e9], [$last[1], $last[2]]);
        $this->assertSame(24 * 3600, $last[0] - $rows[0][0]);
        foreach ($rows as $r) {
            $this->assertGreaterThan(0, $r[1]);
            $this->assertGreaterThan(0, $r[2]);
        }
        $this->assertGreaterThan(1.5, max(array_column($rows, 1)) / min(array_column($rows, 1)), 'the curve has a day and a night');
        $this->assertSame($rows, TotalTrafficSeries::demo(4e9, 2e9, $now + 60), 'the same curve between two refreshes');
    }

    public function testDemoKeepsAMissingDirection(): void
    {
        $rows = TotalTrafficSeries::demo(1e9, null, 1_700_000_000);
        $this->assertNull($rows[0][2]);
        $this->assertSame([], TotalTrafficSeries::demo(null, null));
    }
}
