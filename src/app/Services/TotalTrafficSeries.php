<?php
/**
 * Where the total traffic graph of a map comes from (task F28).
 *
 * One rule for the viewer's side panel, the total traffic box of the PNG
 * and SVG, and the kiosk:
 *
 * - recorded: the map is recorded, the graph shows the recorded frames;
 * - demo: the map is not recorded and all its links read the static data
 *   source (TARGET static:<in>:<out>, the demo maps), the graph shows a
 *   generated 24 h curve that ends on the current totals, marked as demo
 *   data;
 * - none: no graph, and the viewer says that switching on recording in the
 *   map settings shows it.
 *
 * Task F29 adds the periods (a day, a week or a month, offered where the
 * history is long enough), the link groups (SET traffic_group <name> on a
 * link, the Total tab always first) and the 95th percentile and peak of
 * each direction, worked out on the points of the shown period before they
 * are averaged down to what the graph draws.
 */

declare(strict_types=1);

namespace App\Services;

final class TotalTrafficSeries
{
    public const RECORDED = 'recorded';
    public const DEMO = 'demo';
    public const NONE = 'none';
    /** F79: the total, or a group tab, counts Observium ports (TrafficTotalSource), with their history */
    public const SOURCE = 'source';

    /** Hours shown by the demo curve */
    public const DEMO_HOURS = 24;
    /** Seconds between two points of the demo curve of a day */
    public const DEMO_STEP = 900;

    /** The periods the graph offers, in seconds, shortest first */
    public const PERIODS = ['day' => 86400, 'week' => 604800, 'month' => 2592000];
    /** Seconds between two points of the demo curve of each period */
    private const DEMO_STEPS = ['day' => self::DEMO_STEP, 'week' => 3600, 'month' => 10800];
    /** The most points the graph draws: longer series are averaged down */
    public const POINTS = 240;
    /** The longest name of a link group */
    private const GROUP_LENGTH = 40;

    /**
     * The source of the graph for a map.
     *
     * @param bool $recorded the map is recorded (RecordingService::canPlay)
     * @param bool $staticOnly all its links read the static data source
     */
    public static function source(bool $recorded, bool $staticOnly): string
    {
        if ($recorded) {
            return self::RECORDED;
        }
        return $staticOnly ? self::DEMO : self::NONE;
    }

    /**
     * Whether a list of TARGET strings reads only the static data source:
     * at least one target, and every one is static:... (after the optional
     * -/scale* prefix Weathermap allows before a target).
     *
     * @param iterable<string> $targets
     */
    public static function staticOnly(iterable $targets): bool
    {
        $any = false;
        foreach ($targets as $target) {
            $string = (string) preg_replace('/^-?(\d+(\.\d+)?\*)?/', '', trim((string) $target));
            if ($string === '') {
                continue;
            }
            if (stripos($string, 'static:') !== 0) {
                return false;
            }
            $any = true;
        }
        return $any;
    }

    /**
     * The TARGET strings of the links of a drawn map
     *
     * @return list<string>
     */
    public static function mapTargets(\WeatherMap $wmap): array
    {
        $targets = [];
        foreach ($wmap->links as $link) {
            if ($link->name === 'DEFAULT' || !isset($link->a, $link->b)) {
                continue;
            }
            foreach ((array) $link->targets as $target) {
                $targets[] = (string) ($target[4] ?? '');
            }
        }
        return $targets;
    }

    /**
     * The TARGET strings of a map configuration, read from its text (the
     * viewer page decides without drawing the map).
     *
     * @return list<string>
     */
    public static function configTargets(string $config): array
    {
        $targets = [];
        if (preg_match_all('/^[ \t]*TARGET[ \t]+(.+)$/mi', $config, $m)) {
            foreach ($m[1] as $line) {
                preg_match_all('/"([^"]*)"|(\S+)/', $line, $parts, PREG_SET_ORDER);
                foreach ($parts as $p) {
                    $targets[] = ($p[2] ?? '') !== '' ? $p[2] : $p[1];
                }
            }
        }
        return $targets;
    }

    /**
     * A generated period of total traffic (a day by default) that ends on
     * the current totals: low at night and at the weekend, high in the
     * evening, with a little noise that depends only on the time, so the
     * curve does not change between two refreshes.
     *
     * @return list<array{0: int, 1: float|null, 2: float|null}>
     */
    public static function demo(?float $in, ?float $out, ?int $now = null, string $period = 'day'): array
    {
        if ($in === null && $out === null) {
            return [];
        }
        $period = self::period($period);
        $step = self::DEMO_STEPS[$period];
        $now ??= time();
        $end = intdiv($now, $step) * $step;
        $start = $end - self::PERIODS[$period];
        $last = self::shape($end);
        $rows = [];
        for ($t = $start; $t <= $end; $t += $step) {
            $f = self::shape($t) / $last;
            $rows[] = [
                $t,
                $in === null ? null : round($in * $f * self::noise($t, 1, $step)),
                $out === null ? null : round($out * $f * self::noise($t, 2, $step)),
            ];
        }
        // the last point is the traffic the map shows now
        $rows[count($rows) - 1] = [$end, $in, $out];
        return $rows;
    }

    /** A period name the graph knows, the day otherwise */
    public static function period(string $period): string
    {
        return isset(self::PERIODS[$period]) ? $period : 'day';
    }

    /**
     * The group of a link in the tabs of the graph (SET traffic_group
     * <name>), spaces tidied, '' for none.
     */
    public static function groupOf(\WeatherMapLink $link): string
    {
        return self::group($link->hints['traffic_group'] ?? '');
    }

    /** A group name as the graph keeps it: spaces tidied, at most 40 characters */
    public static function group(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) $value));
        return mb_substr($name, 0, self::GROUP_LENGTH);
    }

    /**
     * Which periods can be shown: every one once there are two points with
     * data (F39). A period longer than the history shows what exists, over
     * the whole period, with the date the recording starts (since()).
     *
     * @param list<array{0: int, 1: float|null, 2: float|null}> $rows oldest first
     * @return array<string, bool>
     */
    public static function periods(array $rows, int $now): array
    {
        return array_fill_keys(array_keys(self::PERIODS), count(self::withData($rows)) >= 2);
    }

    /**
     * When the history starts: the time of the oldest point with data, or
     * null without any.
     *
     * @param list<array{0: int, 1: float|null, 2: float|null}> $rows oldest first
     */
    public static function since(array $rows): ?int
    {
        $rows = self::withData($rows);
        return $rows === [] ? null : (int) $rows[0][0];
    }

    /**
     * What the graph shows for a period: the points of the period, averaged
     * down to at most POINTS, and the 95th percentile and peak of each
     * direction over every point of the period.
     *
     * @param list<array{0: int, 1: float|null, 2: float|null}> $rows oldest first
     * @return array{frames: list<array{0: int, 1: float|null, 2: float|null}>, aggregated: bool,
     *     stats: array{in: array{p95: float|null, peak: float|null}, out: array{p95: float|null, peak: float|null}}}
     */
    public static function view(array $rows, string $period, int $now): array
    {
        $since = $now - self::PERIODS[self::period($period)];
        $rows = array_values(array_filter(self::withData($rows), static fn (array $r) => $r[0] >= $since && $r[0] <= $now + 60));
        $frames = self::aggregate($rows, self::POINTS);
        return [
            'frames' => $frames,
            'aggregated' => count($frames) < count($rows),
            'stats' => self::stats($rows),
        ];
    }

    /**
     * Every point of a period as the link traffic route gives a series
     * (MapController::linkTraffic, drawn by WM.trafficPanel): {from, to,
     * units, in: [[time, value]...], out}
     *
     * @param list<array{0: int, 1: float|null, 2: float|null}> $rows oldest first
     * @return array{from: int, to: int, units: string, in: list<array{0: int, 1: float}>, out: list<array{0: int, 1: float}>}
     */
    public static function series(array $rows, string $period, int $now): array
    {
        $from = $now - self::PERIODS[self::period($period)];
        $out = ['from' => $from, 'to' => $now, 'units' => 'bps', 'in' => [], 'out' => []];
        foreach ($rows as $r) {
            if ($r[0] < $from || $r[0] > $now + 60) {
                continue;
            }
            foreach (['in' => 1, 'out' => 2] as $dir => $k) {
                if (is_numeric($r[$k])) {
                    $out[$dir][] = [$r[0], (float) $r[$k]];
                }
            }
        }
        return $out;
    }

    /**
     * Rows averaged down to at most $points buckets of the same length of
     * time; each bucket keeps the time of its last row.
     *
     * @param list<array{0: int, 1: float|null, 2: float|null}> $rows oldest first
     * @return list<array{0: int, 1: float|null, 2: float|null}>
     */
    public static function aggregate(array $rows, int $points = self::POINTS): array
    {
        $count = count($rows);
        if ($count <= $points || $points < 2) {
            return $rows;
        }
        $t0 = $rows[0][0];
        $span = max(1, $rows[$count - 1][0] - $t0);
        $buckets = [];
        foreach ($rows as $r) {
            $b = min($points - 1, intdiv(($r[0] - $t0) * $points, $span + 1));
            $buckets[$b][] = $r;
        }
        $out = [];
        foreach ($buckets as $list) {
            $out[] = [$list[count($list) - 1][0], self::mean(array_column($list, 1)), self::mean(array_column($list, 2))];
        }
        return $out;
    }

    /**
     * The 95th percentile and the peak of each direction (null without data)
     *
     * @param list<array{0: int, 1: float|null, 2: float|null}> $rows
     * @return array{in: array{p95: float|null, peak: float|null}, out: array{p95: float|null, peak: float|null}}
     */
    public static function stats(array $rows): array
    {
        $out = [];
        foreach (['in' => 1, 'out' => 2] as $dir => $k) {
            $values = array_values(array_filter(array_column($rows, $k), 'is_numeric'));
            $out[$dir] = [
                'p95' => self::percentile(array_map('floatval', $values), 0.95),
                'peak' => $values === [] ? null : (float) max($values),
            ];
        }
        return $out;
    }

    /**
     * The value under which a share $p of the values lie (nearest rank)
     *
     * @param list<float> $values
     */
    public static function percentile(array $values, float $p): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $rank = (int) ceil($p * count($values)) - 1;
        return $values[max(0, min(count($values) - 1, $rank))];
    }

    /**
     * @param list<mixed> $values
     */
    private static function mean(array $values): ?float
    {
        $values = array_filter($values, 'is_numeric');
        return $values === [] ? null : round(array_sum($values) / count($values));
    }

    /**
     * The rows with data in at least one direction
     *
     * @param list<array{0: int, 1: float|null, 2: float|null}> $rows
     * @return list<array{0: int, 1: float|null, 2: float|null}>
     */
    private static function withData(array $rows): array
    {
        return array_values(array_filter($rows, static fn (array $r) => $r[1] !== null || $r[2] !== null));
    }

    /**
     * The daily curve, between 0.3 (4 am) and 1 (evening peak), a quarter
     * lower on Saturdays and Sundays
     */
    private static function shape(int $time): float
    {
        $weekend = (int) gmdate('N', $time) >= 6 ? 0.75 : 1.0;
        $h = ($time % 86400) / 3600;
        // a slow climb from 4 am and a quicker fall after 9 pm
        $x = fmod($h - 4 + 24, 24) / 24;
        $rise = 17 / 24;
        $v = $x <= $rise ? 0.5 - 0.5 * cos(M_PI * $x / $rise) : 0.5 + 0.5 * cos(M_PI * ($x - $rise) / (1 - $rise));
        return (0.3 + 0.7 * $v) * $weekend;
    }

    /** A factor around 1 (±6 %) that depends only on the time and the direction */
    private static function noise(int $time, int $seed, int $step = self::DEMO_STEP): float
    {
        $n = crc32($seed . ':' . intdiv($time, $step));
        return 0.94 + 0.12 * (($n % 1000) / 999);
    }
}
