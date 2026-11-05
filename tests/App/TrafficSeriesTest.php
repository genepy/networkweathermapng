<?php

declare(strict_types=1);

namespace Tests\App;

use App\Services\TrafficSeries;
use PHPUnit\Framework\TestCase;

/** The series of the hover card: a day, a week or a month of IN and OUT */
class TrafficSeriesTest extends TestCase
{
    public function testRanges(): void
    {
        $this->assertSame(1440, TrafficSeries::minutes('day'));
        $this->assertSame(10080, TrafficSeries::minutes('week'));
        $this->assertSame(43200, TrafficSeries::minutes('month'));
        $this->assertSame(1440, TrafficSeries::minutes('year'), 'an unknown range is a day');
        $this->assertSame('day', TrafficSeries::range('../etc'));
    }

    public function testFromBandwidthData(): void
    {
        $now = 1_000_000;
        $data = [
            'in' => ['units' => 'bps', 'series' => [
                ['t' => $now - 120, 'v' => '20'],
                ['t' => $now - 60, 'v' => 10],
                ['t' => $now - 90000, 'v' => 99],     // older than a day
                ['t' => $now - 30, 'v' => 'nan'],    // not a number
                ['v' => 5],
            ]],
            'out' => ['units' => 'bps', 'series' => [['t' => $now - 10, 'v' => 7.5]]],
        ];
        $out = TrafficSeries::fromBandwidthData($data, 'day', $now);
        $this->assertSame('day', $out['range']);
        $this->assertSame($now - 86400, $out['from']);
        $this->assertSame($now, $out['to']);
        $this->assertSame('bps', $out['units']);
        $this->assertEquals([[$now - 120, 20.0], [$now - 60, 10.0]], $out['in'], 'sorted, inside the period, numbers only');
        $this->assertEquals([[$now - 10, 7.5]], $out['out']);

        $empty = TrafficSeries::fromBandwidthData([], 'month', $now);
        $this->assertSame([], $empty['in']);
        $this->assertSame($now - 43200 * 60, $empty['from']);
    }

    public function testBucketsAverageLongSeries(): void
    {
        $points = [];
        for ($t = 0; $t < 1000; $t++) {
            $points[] = [$t, (float) ($t % 2 === 0 ? 0 : 10)];
        }
        $out = TrafficSeries::bucket($points, 0, 1000, 100);
        $this->assertCount(100, $out);
        foreach ($out as [$t, $v]) {
            $this->assertEqualsWithDelta(5.0, $v, 0.001, 'each bucket is the average of its points');
        }
        $this->assertSame(5, $out[0][0], 'placed at the mean time of its points');
        $this->assertCount(3, TrafficSeries::bucket([[1, 1.0], [2, 2.0], [3, 3.0]], 0, 10, 100), 'a short series is kept');
    }
}
