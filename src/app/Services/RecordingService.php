<?php

namespace App\Services;

use App\Core\Config;
use App\Core\Database;

/**
 * Map recording: for the maps where it is switched on, the recorder
 * (bin/record.php) draws the map with live data every few minutes and keeps
 * each drawing for a while, so the viewer can play the last hours back.
 *
 * Settings (settings table): whether recording is allowed at all, the
 * interval and how long frames are kept (map_id 0), and for each map
 * whether it is recorded (off unless switched on).
 *
 * A frame is what the viewer gets from /map/{id}/svg - the SVG, the items
 * shown on hover and the state summary - as gzipped JSON in
 * DATA_ROOT/recordings/<map id>/<unix time>.json.gz.
 */
class RecordingService
{
    public const DEFAULT_INTERVAL = 5;
    public const MIN_INTERVAL = 1;
    public const MAX_INTERVAL = 60;
    public const DEFAULT_RETENTION = 24;
    public const MIN_RETENTION = 1;
    public const MAX_RETENTION = 168;

    private const ALLOWED = 'recording_allowed';
    private const INTERVAL = 'recording_interval';
    private const RETENTION = 'recording_retention';
    private const RECORDED = 'recording';

    private SettingsStore $store;
    private string $dir;

    public function __construct(private Database $db, private Config $config)
    {
        $this->store = new SettingsStore($db);
        $this->dir = rtrim($config->getDataRoot(), '/') . '/recordings';
    }

    /** Whether the admin lets maps be recorded at all */
    public function isAllowed(): bool
    {
        return $this->store->get(0, self::ALLOWED) !== '0';
    }

    public function setAllowed(bool $allowed): void
    {
        $this->store->set(0, self::ALLOWED, $allowed ? '1' : '0');
    }

    /** Minutes between two frames */
    public function getInterval(): int
    {
        $v = $this->store->get(0, self::INTERVAL);
        return $v === null ? self::DEFAULT_INTERVAL : self::clamp((int) $v, self::MIN_INTERVAL, self::MAX_INTERVAL);
    }

    public function setInterval(int $minutes): void
    {
        $this->store->set(0, self::INTERVAL, (string) self::clamp($minutes, self::MIN_INTERVAL, self::MAX_INTERVAL));
    }

    /** Hours a frame is kept */
    public function getRetention(): int
    {
        $v = $this->store->get(0, self::RETENTION);
        return $v === null ? self::DEFAULT_RETENTION : self::clamp((int) $v, self::MIN_RETENTION, self::MAX_RETENTION);
    }

    public function setRetention(int $hours): void
    {
        $this->store->set(0, self::RETENTION, (string) self::clamp($hours, self::MIN_RETENTION, self::MAX_RETENTION));
    }

    /** @return int[] ids of the maps switched on, whether recording is allowed or not */
    public function recordedMaps(): array
    {
        $rows = $this->db->query('SELECT map_id FROM settings WHERE name = ? AND value = ? AND map_id > 0', [self::RECORDED, '1']);
        return array_map(static fn ($r) => (int) $r['map_id'], $rows);
    }

    public function isRecorded(int $mapId): bool
    {
        return $this->store->get($mapId, self::RECORDED) === '1';
    }

    /** Switching a map off also drops its frames */
    public function setRecorded(int $mapId, bool $on): void
    {
        if ($mapId <= 0) {
            return;
        }
        if ($on) {
            $this->store->set($mapId, self::RECORDED, '1');
            return;
        }
        $this->store->remove($mapId, self::RECORDED);
        $this->deleteFrames($mapId);
    }

    /**
     * Whether the recorder has work to do: recording is allowed and at
     * least one map is switched on. Otherwise it does not run at all.
     */
    public function isActive(): bool
    {
        return $this->isAllowed() && $this->recordedMaps() !== [];
    }

    /** Whether the viewer offers to play this map back */
    public function canPlay(int $mapId): bool
    {
        return $this->isAllowed() && $this->isRecorded($mapId);
    }

    // ------------------------------------------------------------------
    // Frames

    /** @return int[] times of the frames kept for a map, oldest first */
    public function frames(int $mapId, ?int $now = null): array
    {
        $since = ($now ?? time()) - $this->getRetention() * 3600;
        $times = [];
        foreach (glob($this->mapDir($mapId) . '/*.json.gz') ?: [] as $file) {
            $t = (int) basename($file, '.json.gz');
            if ($t >= $since) {
                $times[] = $t;
            }
        }
        sort($times);
        return $times;
    }

    /** @return array<string, mixed>|null */
    public function frame(int $mapId, int $time): ?array
    {
        $file = $this->mapDir($mapId) . '/' . $time . '.json.gz';
        if (!is_file($file)) {
            return null;
        }
        $json = @gzdecode((string) file_get_contents($file));
        $data = $json === false ? null : json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    /** @param array<string, mixed> $frame */
    public function save(int $mapId, array $frame, ?int $time = null): int
    {
        $time ??= time();
        $dir = $this->mapDir($mapId);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $file = $dir . '/' . $time . '.json.gz';
        $tmp = $file . '.tmp';
        file_put_contents($tmp, gzencode((string) json_encode($frame, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 6));
        rename($tmp, $file);
        return $time;
    }

    /** Delete the frames older than the retention */
    public function prune(int $mapId, ?int $now = null): int
    {
        $since = ($now ?? time()) - $this->getRetention() * 3600;
        $removed = 0;
        foreach (glob($this->mapDir($mapId) . '/*.json.gz') ?: [] as $file) {
            if ((int) basename($file, '.json.gz') < $since && @unlink($file)) {
                $removed++;
            }
        }
        return $removed;
    }

    /** Whether a map's last frame is old enough for a new one */
    public function isDue(int $mapId, ?int $now = null): bool
    {
        $frames = $this->frames($mapId, $now);
        // a little early is fine: the recorder wakes up every few seconds
        return !$frames || ($now ?? time()) - end($frames) >= $this->getInterval() * 60 - 15;
    }

    /**
     * One pass of the recorder: a new frame for each recorded map that is
     * due, and old frames deleted. Maps switched off lose their frames.
     *
     * @param callable(int): ?array $draw draws a map with live data and
     *        returns its frame (svg, items, status), or null
     * @return int frames written
     */
    public function recordDue(callable $draw, ?int $now = null): int
    {
        $written = 0;
        $recorded = $this->recordedMaps();
        foreach ($this->storedMaps() as $id) {
            if (!in_array($id, $recorded, true)) {
                $this->deleteFrames($id);
            } elseif (!$this->isAllowed()) {
                $this->prune($id, $now);
            }
        }
        if (!$this->isAllowed()) {
            return 0;
        }
        foreach ($recorded as $id) {
            $this->prune($id, $now);
            if (!$this->isDue($id, $now)) {
                continue;
            }
            $frame = $draw($id);
            if ($frame !== null) {
                $this->save($id, $frame, $now);
                $written++;
            }
        }
        return $written;
    }

    /** Delete the frames past the retention, for every map */
    public function pruneAll(?int $now = null): void
    {
        foreach ($this->storedMaps() as $id) {
            $this->prune($id, $now);
        }
    }

    // ------------------------------------------------------------------
    // The recorder process: one at most, and only while there is work

    /**
     * Take the recorder lock, held as long as the process lives. Null when
     * another recorder already holds it.
     *
     * @return resource|null
     */
    public function lockRecorder()
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
        $lock = @fopen($this->dir . '/recorder.lock', 'c');
        if ($lock === false) {
            return null;
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return null;
        }
        return $lock;
    }

    /** Whether a recorder process is running now */
    public function isRecorderRunning(): bool
    {
        $lock = $this->lockRecorder();
        if ($lock === null) {
            return true;
        }
        flock($lock, LOCK_UN);
        fclose($lock);
        return false;
    }

    /**
     * Start the recorder in the background when there is something to
     * record and it is not running yet. It stops by itself once nothing is
     * recorded any more. Returns whether a process was started.
     */
    public function startRecorder(string $script, string $log = '/dev/null'): bool
    {
        if (!$this->isActive() || $this->isRecorderRunning() || !is_file($script) || !function_exists('exec')) {
            return false;
        }
        // PHP_BINARY is php-fpm under the web server: use the CLI next to it
        $php = is_executable(PHP_BINDIR . '/php') ? PHP_BINDIR . '/php' : 'php';
        if ($log !== '/dev/null' && !is_writable(is_file($log) ? $log : dirname($log))) {
            $log = '/dev/null';
        }
        $command = sprintf(
            'nohup %s %s < /dev/null >> %s 2>&1 &',
            escapeshellarg($php),
            escapeshellarg($script),
            escapeshellarg($log)
        );
        @exec($command);
        return true;
    }

    /** Start bin/record.php if there is something to record and it is not running */
    public function ensureRecorder(): bool
    {
        $root = rtrim((string) $this->config->getAppRoot(), '/');
        return $this->startRecorder($root . '/bin/record.php', $this->config->getLogsPath() . '/recorder.log');
    }

    /** Space used by the frames of all maps, in bytes */
    public function diskUsage(): int
    {
        $total = 0;
        foreach (glob($this->dir . '/*/*.json.gz') ?: [] as $file) {
            $total += (int) filesize($file);
        }
        return $total;
    }

    private function deleteFrames(int $mapId): void
    {
        foreach (glob($this->mapDir($mapId) . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->mapDir($mapId));
    }

    /** @return int[] maps that have a folder of frames */
    private function storedMaps(): array
    {
        $ids = [];
        foreach (glob($this->dir . '/*', GLOB_ONLYDIR) ?: [] as $d) {
            if (ctype_digit(basename($d))) {
                $ids[] = (int) basename($d);
            }
        }
        return $ids;
    }

    private function mapDir(int $mapId): string
    {
        return $this->dir . '/' . $mapId;
    }

    private static function clamp(int $v, int $min, int $max): int
    {
        return max($min, min($max, $v));
    }
}
