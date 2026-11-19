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
 */

declare(strict_types=1);

namespace App\Services;

final class TotalTrafficSeries
{
    public const RECORDED = 'recorded';
    public const DEMO = 'demo';
    public const NONE = 'none';

    /** Hours shown by the demo curve */
    public const DEMO_HOURS = 24;
    /** Seconds between two points of the demo curve */
    public const DEMO_STEP = 900;

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
     * A generated day of total traffic that ends on the current totals:
     * low at night, high in the evening, with a little noise that depends
     * only on the time, so the curve does not change between two refreshes.
     *
     * @return list<array{0: int, 1: float|null, 2: float|null}>
     */
    public static function demo(?float $in, ?float $out, ?int $now = null): array
    {
        if ($in === null && $out === null) {
            return [];
        }
        $now ??= time();
        $end = intdiv($now, self::DEMO_STEP) * self::DEMO_STEP;
        $start = $end - self::DEMO_HOURS * 3600;
        $last = self::shape($end);
        $rows = [];
        for ($t = $start; $t <= $end; $t += self::DEMO_STEP) {
            $f = self::shape($t) / $last;
            $rows[] = [
                $t,
                $in === null ? null : round($in * $f * self::noise($t, 1)),
                $out === null ? null : round($out * $f * self::noise($t, 2)),
            ];
        }
        // the last point is the traffic the map shows now
        $rows[count($rows) - 1] = [$end, $in, $out];
        return $rows;
    }

    /** The daily curve, between 0.3 (4 am) and 1 (evening peak) */
    private static function shape(int $time): float
    {
        $h = ($time % 86400) / 3600;
        // a slow climb from 4 am and a quicker fall after 9 pm
        $x = fmod($h - 4 + 24, 24) / 24;
        $rise = 17 / 24;
        $v = $x <= $rise ? 0.5 - 0.5 * cos(M_PI * $x / $rise) : 0.5 + 0.5 * cos(M_PI * ($x - $rise) / (1 - $rise));
        return 0.3 + 0.7 * $v;
    }

    /** A factor around 1 (±6 %) that depends only on the time and the direction */
    private static function noise(int $time, int $seed): float
    {
        $n = crc32($seed . ':' . intdiv($time, self::DEMO_STEP));
        return 0.94 + 0.12 * (($n % 1000) / 999);
    }
}
