<?php
/**
 * NetworkWeathermapNG - reads Observium port RRD files with the rrdtool
 * command. Used for link history (hover graphs) and as a last resort for the
 * current rate when neither the API nor the database gives one.
 *
 * Observium keeps one file per port: <rrd_dir>/<hostname>/port-<ifIndex>.rrd,
 * with the INOCTETS and OUTOCTETS data sources in octets per second.
 */

declare(strict_types=1);

namespace App\Services\Observium;

final class RrdReader
{
    /** @var callable(list<string>): string */
    private $runner;

    public function __construct(
        private readonly string $rrdDir,
        private readonly string $daemon = '',
        private readonly string $binary = 'rrdtool',
        ?callable $runner = null,
    ) {
        $this->runner = $runner ?? $this->run(...);
    }

    public function fileFor(string $hostname, int $ifIndex): string
    {
        if ($hostname === '' || str_contains($hostname, '/') || str_contains($hostname, '..')) {
            throw new \InvalidArgumentException('Invalid hostname for an RRD path: ' . $hostname);
        }

        return rtrim($this->rrdDir, '/') . '/' . $hostname . '/port-' . $ifIndex . '.rrd';
    }

    public function available(string $hostname, int $ifIndex): bool
    {
        // Through rrdcached the file may live on another server
        return $this->daemon !== '' || is_readable($this->fileFor($hostname, $ifIndex));
    }

    /**
     * Last known in/out rate in bit/s, or null when the file has no recent value.
     *
     * @return array{in: float, out: float, time: int}|null
     */
    public function current(string $hostname, int $ifIndex): ?array
    {
        $series = $this->series($hostname, $ifIndex, 30);
        $last = end($series);

        return $last === false ? null : $last;
    }

    /**
     * In/out rates in bit/s over the last $minutes, oldest first, gaps skipped.
     *
     * @return list<array{in: float, out: float, time: int}>
     */
    public function series(string $hostname, int $ifIndex, int $minutes): array
    {
        $command = [$this->binary, 'fetch', $this->fileFor($hostname, $ifIndex), 'AVERAGE', '--start', '-' . ($minutes * 60)];
        if ($this->daemon !== '') {
            $command[] = '--daemon';
            $command[] = $this->daemon;
        }

        return self::parseFetch(($this->runner)($command));
    }

    /**
     * @return list<array{in: float, out: float, time: int}>
     */
    public static function parseFetch(string $output): array
    {
        $lines = preg_split('/\R/', trim($output)) ?: [];
        $header = preg_split('/\s+/', trim((string) array_shift($lines))) ?: [];
        $inColumn = array_search('INOCTETS', $header, true);
        $outColumn = array_search('OUTOCTETS', $header, true);
        if ($inColumn === false || $outColumn === false) {
            return [];
        }

        $points = [];
        foreach ($lines as $line) {
            if (!preg_match('/^\s*(\d+):\s*(.+)$/', $line, $m)) {
                continue;
            }
            $values = preg_split('/\s+/', trim($m[2])) ?: [];
            $in = $values[$inColumn] ?? 'nan';
            $out = $values[$outColumn] ?? 'nan';
            if (!is_numeric($in) || !is_numeric($out)) {
                continue;
            }
            $points[] = ['in' => (float) $in * 8, 'out' => (float) $out * 8, 'time' => (int) $m[1]];
        }

        return $points;
    }

    /** @param list<string> $command */
    private function run(array $command): string
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot run rrdtool');
        }
        $output = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new \RuntimeException('rrdtool failed: ' . trim($error));
        }

        return $output;
    }
}
