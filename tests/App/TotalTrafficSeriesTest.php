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

    /** F29: a week and a month of demo data, every point within the period */
    public function testDemoWeekAndMonth(): void
    {
        $now = 1_699_999_200;
        $week = TotalTrafficSeries::demo(4e9, 2e9, $now, 'week');
        $this->assertCount(7 * 24 + 1, $week, 'a point every hour');
        $month = TotalTrafficSeries::demo(4e9, 2e9, $now, 'month');
        $this->assertSame(30 * 86400, end($month)[0] - $month[0][0]);
        $this->assertSame([4e9, 2e9], [end($month)[1], end($month)[2]], 'the month ends on the current totals too');
        $this->assertCount(97, TotalTrafficSeries::demo(4e9, 2e9, $now, 'bogus'), 'an unknown period is a day');
    }

    /** F39: every period can be chosen once there is a graph; a short history shows what exists, with its start */
    public function testEveryPeriodOnceThereIsAGraph(): void
    {
        $now = 1_800_000_000;
        $none = ['day' => false, 'week' => false, 'month' => false];
        $all = ['day' => true, 'week' => true, 'month' => true];
        $this->assertSame($none, TotalTrafficSeries::periods([], $now));
        $this->assertSame($none, TotalTrafficSeries::periods([[$now - 60, null, null], [$now, 1.0, 1.0]], $now), 'one point with data');
        $hours = static fn (int $h) => [[$now - $h * 3600, null, null], [$now - $h * 3600 + 60, 1.0, 1.0], [$now, 2.0, 2.0]];
        $this->assertSame($all, TotalTrafficSeries::periods($hours(2), $now), 'two hours of history: a week shows them');
        $this->assertSame($all, TotalTrafficSeries::periods($hours(24 * 7 + 1), $now));
        $this->assertSame($now - 2 * 3600 + 60, TotalTrafficSeries::since($hours(2)), 'the first point with data');
        $this->assertNull(TotalTrafficSeries::since([]));
    }

    /** The 95th percentile and the peak come from every point of the period, before they are averaged down */
    public function testViewAveragesDownAndKeepsTheStats(): void
    {
        $now = 1_800_000_000;
        $rows = [];
        for ($i = 0; $i < 1000; $i++) {
            $rows[] = [$now - 86400 + $i * 86, $i === 500 ? 1e9 : 100.0, 50.0];
        }
        $rows[] = [$now - 2 * 86400, 7e9, 7e9];   // before the day: left out
        usort($rows, static fn ($a, $b) => $a[0] <=> $b[0]);
        $view = TotalTrafficSeries::view($rows, 'day', $now);
        $this->assertTrue($view['aggregated']);
        $this->assertLessThanOrEqual(TotalTrafficSeries::POINTS, count($view['frames']));
        $this->assertSame(1e9, $view['stats']['in']['peak'], 'the peak of one point survives the averaging');
        $this->assertSame(100.0, $view['stats']['in']['p95']);
        $this->assertSame(50.0, $view['stats']['out']['peak']);
        $this->assertLessThan(1e9, max(array_column($view['frames'], 1)), 'the graph draws averages');

        $short = TotalTrafficSeries::view([[$now - 60, 1.0, null], [$now, 3.0, null]], 'day', $now);
        $this->assertFalse($short['aggregated']);
        $this->assertSame(['p95' => null, 'peak' => null], $short['stats']['out']);
    }

    public function testPercentile(): void
    {
        $this->assertNull(TotalTrafficSeries::percentile([], 0.95));
        $this->assertSame(19.0, TotalTrafficSeries::percentile(array_map('floatval', range(20, 1)), 0.95));
        $this->assertSame(5.0, TotalTrafficSeries::percentile([5.0], 0.95));
    }

    /** The zoomed graph gets the points of the period as the link traffic route gives them */
    public function testSeries(): void
    {
        $now = 1_800_000_000;
        $series = TotalTrafficSeries::series([[$now - 90000, 9.0, 9.0], [$now - 60, 1.0, null], [$now, 2.0, 3.0]], 'day', $now);
        $this->assertSame(['from' => $now - 86400, 'to' => $now, 'units' => 'bps',
            'in' => [[$now - 60, 1.0], [$now, 2.0]], 'out' => [[$now, 3.0]]], $series);
    }

    public function testGroupNames(): void
    {
        $this->assertSame('Peering links', TotalTrafficSeries::group("  Peering\t links "));
        $this->assertSame('', TotalTrafficSeries::group(null));
        $this->assertSame(40, mb_strlen(TotalTrafficSeries::group(str_repeat('é', 60))));
        $this->assertSame('day', TotalTrafficSeries::period('year'));
    }
}
