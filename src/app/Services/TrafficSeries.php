<?php
/**
 * The traffic of a link over a period, for the viewer's hover card: the IN
 * and OUT series a data source gave, cut to the period and averaged into a
 * bounded number of points so a month stays light to send and to draw.
 */

declare(strict_types=1);

namespace App\Services;

final class TrafficSeries
{
    /** The periods the hover card offers, in minutes */
    public const RANGES = [
        'day' => 1440,
        'week' => 10080,
        'month' => 43200,
    ];

    /** Points per series sent to the browser at most */
    public const MAX_POINTS = 360;

    /** The minutes of a range name, the day for an unknown one */
    public static function minutes(string $range): int
    {
        return self::RANGES[$range] ?? self::RANGES['day'];
    }

    /** A known range name, 'day' otherwise */
    public static function range(string $range): string
    {
        return isset(self::RANGES[$range]) ? $range : 'day';
    }

    /**
     * What the hover card draws, from getBandwidthData() of a data source:
     * {range, from, to, units, in: [[time, value]...], out: [...]}.
     *
     * @param array<string, mixed> $data
     * @return array{range: string, from: int, to: int, units: string, in: list<array{0: int, 1: float}>, out: list<array{0: int, 1: float}>}
     */
    public static function fromBandwidthData(array $data, string $range, int $now, int $maxPoints = self::MAX_POINTS): array
    {
        $range = self::range($range);
        $from = $now - self::minutes($range) * 60;
        $units = '';
        foreach (['in', 'out'] as $dir) {
            $u = $data[$dir]['units'] ?? '';
            if ($units === '' && is_string($u)) {
                $units = trim($u);
            }
        }

        return [
            'range' => $range,
            'from' => $from,
            'to' => $now,
            'units' => $units,
            'in' => self::bucket(self::points($data['in']['series'] ?? []), $from, $now, $maxPoints),
            'out' => self::bucket(self::points($data['out']['series'] ?? []), $from, $now, $maxPoints),
        ];
    }

    /**
     * Valid points of a series ({t, v} items), oldest first
     *
     * @return list<array{0: int, 1: float}>
     */
    private static function points(mixed $series): array
    {
        $out = [];
        foreach (is_array($series) ? $series : [] as $p) {
            if (!is_array($p) || !is_numeric($p['t'] ?? null) || !is_numeric($p['v'] ?? null)) {
                continue;
            }
            $v = (float) $p['v'];
            if (!is_finite($v)) {
                continue;
            }
            $out[] = [(int) $p['t'], $v];
        }
        usort($out, static fn (array $a, array $b) => $a[0] <=> $b[0]);
        return $out;
    }

    /**
     * The points inside [from, to], averaged into at most $max buckets of
     * equal time (each placed at the mean time of its points). Fewer points
     * than that are kept as they are.
     *
     * @param list<array{0: int, 1: float}> $points
     * @return list<array{0: int, 1: float}>
     */
    public static function bucket(array $points, int $from, int $to, int $max): array
    {
        $inside = array_values(array_filter($points, static fn (array $p) => $p[0] >= $from && $p[0] <= $to));
        if ($max < 1 || count($inside) <= $max || $to <= $from) {
            return array_map(static fn (array $p) => [$p[0], round($p[1], 3)], $inside);
        }

        $width = ($to - $from) / $max;
        $sums = [];
        foreach ($inside as [$t, $v]) {
            $i = min($max - 1, (int) floor(($t - $from) / $width));
            $sums[$i] ??= [0, 0.0, 0];
            $sums[$i][0] += $t;
            $sums[$i][1] += $v;
            $sums[$i][2]++;
        }
        ksort($sums);

        $out = [];
        foreach ($sums as [$ts, $vs, $n]) {
            $out[] = [(int) round($ts / $n), round($vs / $n, 3)];
        }
        return $out;
    }
}
