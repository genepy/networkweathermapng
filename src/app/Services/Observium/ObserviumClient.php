<?php
/**
 * NetworkWeathermapNG - Observium data source.
 *
 * Chooses how to read Observium from the data source settings:
 * - API, when a URL and a token or user are set (Professional/Enterprise);
 * - otherwise the MySQL database (every edition, Community included), with
 *   the connection taken from the settings or from Observium's config.php;
 * - the RRD files, for link history and when no current rate is available.
 *
 * Maps do not depend on the mode: the same TARGET works with all three.
 */

declare(strict_types=1);

namespace App\Services\Observium;

use App\Services\DataSourceClientInterface;
use App\Services\DataSourceService;

final class ObserviumClient implements DataSourceClientInterface
{
    public const MODE_AUTO = 'auto';
    public const MODE_API = 'api';
    public const MODE_MYSQL = 'mysql';

    private ?ObserviumBackend $backend = null;
    private ?RrdReader $rrd = null;
    private array $settings;
    private array $debug = [];

    public function __construct(
        private readonly array $source,
        private readonly ?DataSourceService $service = null,
        ?ObserviumBackend $backend = null,
        ?RrdReader $rrd = null,
    ) {
        $settings = json_decode((string) ($source['settings'] ?? ''), true);
        $this->settings = is_array($settings) ? $settings : [];
        $this->backend = $backend;
        $this->rrd = $rrd;
    }

    /** Which backend these settings select, without connecting. */
    public function mode(): string
    {
        $mode = (string) ($this->settings['mode'] ?? self::MODE_AUTO);
        if ($mode === self::MODE_API || $mode === self::MODE_MYSQL) {
            return $mode;
        }

        $hasCredentials = trim((string) ($this->source['api_token'] ?? '')) !== ''
            || trim((string) ($this->source['username'] ?? '')) !== '';

        return trim((string) ($this->source['url'] ?? '')) !== '' && $hasCredentials ? self::MODE_API : self::MODE_MYSQL;
    }

    public function testConnection(): array
    {
        try {
            $backend = $this->backend();
            $detail = $backend->test();
            $message = sprintf('Connected through the %s (%s).', $backend->name() === 'API' ? 'API' : 'database', $detail);

            $rrd = $this->rrd();
            $message .= $rrd ? ' RRD history: ' . $this->rrdDir() . '.' : ' RRD history: not available.';

            return ['success' => true, 'message' => $message, 'mode' => $backend->name()];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'mode' => $this->mode() === self::MODE_API ? 'API' : 'MySQL'];
        }
    }

    public function getHosts(array $filters = []): array
    {
        return array_map(static fn(array $d): array => [
            'hostid' => (string) $d['device_id'],
            'host' => $d['hostname'],
            'name' => $d['hostname'] . ($d['sysName'] !== '' && strcasecmp($d['sysName'], $d['hostname']) !== 0 ? ' (' . $d['sysName'] . ')' : ''),
            'status' => $d['up'] ? 'up' : 'down',
        ], $this->backend()->devices((string) ($filters['search'] ?? '')));
    }

    public function getHostInterfaces(string $hostId): array
    {
        return $this->backend()->ports((int) $hostId);
    }

    public function getItems(string $hostId, array $filters = []): array
    {
        return [];
    }

    public function getItemValue(string $itemId): ?array
    {
        return null;
    }

    /**
     * Ports of a device for the editor's interface picker, with the TARGET
     * to write and the interface speed to pre-fill BANDWIDTH.
     */
    public function getInterfaceBandwidthOptions(string $hostId): array
    {
        $options = [];
        foreach ($this->backend()->ports((int) $hostId) as $port) {
            $options[] = [
                'interfaceId' => (string) $port['port_id'],
                'label' => PortNormalizer::label($port) . ($port['up'] ? '' : ' [down]'),
                'target' => ObserviumTarget::forPort($port['port_id']),
                'speed' => $port['speed'],
                'bandwidth' => $port['speed'] ? self::formatSpeed($port['speed']) : '',
                'rxKey' => '',
                'txKey' => '',
            ];
        }

        return $options;
    }

    public function getBandwidthCurrent(array $selection): array
    {
        $port = $this->backend()->port((int) ($selection['interfaceId'] ?? 0));
        $rates = $port ? $this->rates($port) : null;

        return $rates ? ['in' => $rates[0], 'out' => $rates[1]] : [];
    }

    public function getBandwidthData(array $selection, int $minutes = 1440): array
    {
        $port = $this->backend()->port((int) ($selection['interfaceId'] ?? 0));
        $rrd = $this->rrd();
        if (!$port || !$rrd || !$rrd->available($port['hostname'], $port['ifIndex'])) {
            return [];
        }

        $points = $rrd->series($port['hostname'], $port['ifIndex'], $minutes);
        $label = PortNormalizer::label($port);
        $last = end($points) ?: ['in' => 0, 'out' => 0];

        return [
            'hostname' => $port['hostname'],
            'in' => [
                'itemid' => (string) $port['port_id'],
                'name' => $label . ' in',
                'units' => 'bps',
                'lastvalue' => (string) $last['in'],
                'series' => array_map(static fn(array $p): array => ['t' => $p['time'], 'v' => $p['in']], $points),
            ],
            'out' => [
                'itemid' => (string) $port['port_id'],
                'name' => $label . ' out',
                'units' => 'bps',
                'lastvalue' => (string) $last['out'],
                'series' => array_map(static fn(array $p): array => ['t' => $p['time'], 'v' => $p['out']], $points),
            ],
        ];
    }

    /**
     * Values for a TARGET string: [in, out] in bit/s for a port, or
     * [0, 0] / [100, 100] for a device that is up / down (so the default
     * scale shows a down device in red). Null when there is nothing to show.
     *
     * @return array{0: float, 1: float}|null
     */
    public function readTarget(string $target): ?array
    {
        $parsed = ObserviumTarget::parse($target);
        if (!$parsed) {
            return null;
        }

        if ($parsed->kind === ObserviumTarget::STATUS) {
            $device = $this->backend()->device((string) $parsed->host);

            return $device ? ($device['up'] ? [0.0, 0.0] : [100.0, 100.0]) : null;
        }

        $port = $parsed->portId !== null
            ? $this->backend()->port($parsed->portId)
            : $this->backend()->portByName((string) $parsed->host, (string) $parsed->interface);

        return $port ? $this->rates($port) : null;
    }

    /** Interface speed in bit/s for a TARGET, to size the link automatically. */
    public function speedForTarget(string $target): ?int
    {
        $parsed = ObserviumTarget::parse($target);
        if (!$parsed || $parsed->kind !== ObserviumTarget::PORT) {
            return null;
        }
        $port = $parsed->portId !== null
            ? $this->backend()->port($parsed->portId)
            : $this->backend()->portByName((string) $parsed->host, (string) $parsed->interface);

        return $port['speed'] ?? null;
    }

    public function getDebugInfo(): array
    {
        return $this->debug;
    }

    public static function formatSpeed(int $bitsPerSecond): string
    {
        foreach (['T' => 1e12, 'G' => 1e9, 'M' => 1e6, 'K' => 1e3] as $suffix => $factor) {
            if ($bitsPerSecond >= $factor) {
                $value = $bitsPerSecond / $factor;

                return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . $suffix;
            }
        }

        return (string) $bitsPerSecond;
    }

    /** @return array{0: float, 1: float}|null */
    private function rates(array $port): ?array
    {
        if ($port['in_rate'] !== null && $port['out_rate'] !== null) {
            return [$port['in_rate'], $port['out_rate']];
        }

        $rrd = $this->rrd();
        if ($rrd && $port['hostname'] !== '' && $rrd->available($port['hostname'], $port['ifIndex'])) {
            try {
                $current = $rrd->current($port['hostname'], $port['ifIndex']);
                if ($current) {
                    return [$current['in'], $current['out']];
                }
            } catch (\Throwable $e) {
                $this->debug['rrd'] = $e->getMessage();
            }
        }

        return null;
    }

    private function backend(): ObserviumBackend
    {
        if ($this->backend === null) {
            if ($this->mode() === self::MODE_API) {
                $this->backend = new ApiBackend(
                    (string) $this->source['url'],
                    trim((string) ($this->source['api_token'] ?? '')),
                    trim((string) ($this->source['username'] ?? '')),
                    $this->secret((string) ($this->source['password'] ?? '')),
                    (bool) ($this->settings['verify_tls'] ?? true),
                );
            } else {
                $db = $this->databaseSettings();
                if ($db === null) {
                    throw new \RuntimeException(sprintf(
                        'No Observium database settings: fill them in, or make %s/config.php readable by Weathermap',
                        $this->installPath()
                    ));
                }
                $this->backend = DatabaseBackend::connect($db);
            }
        }

        return $this->backend;
    }

    private function rrd(): ?RrdReader
    {
        if ($this->rrd === null) {
            $dir = $this->rrdDir();
            $daemon = trim((string) ($this->settings['rrdcached'] ?? ''));
            if ($dir === '' || ($daemon === '' && !is_dir($dir))) {
                return null;
            }
            $this->rrd = new RrdReader($dir, $daemon);
        }

        return $this->rrd;
    }

    private function databaseSettings(): ?array
    {
        if (trim((string) ($this->settings['db_user'] ?? '')) !== '') {
            return [
                'host' => (string) ($this->settings['db_host'] ?? 'localhost'),
                'port' => (int) ($this->settings['db_port'] ?? 3306),
                'name' => (string) ($this->settings['db_name'] ?? 'observium'),
                'user' => (string) $this->settings['db_user'],
                'password' => $this->secret((string) ($this->settings['db_password'] ?? '')),
                'socket' => (string) ($this->settings['db_socket'] ?? ''),
            ];
        }

        return ObserviumConfigReader::read($this->installPath())['db'] ?? null;
    }

    private function rrdDir(): string
    {
        $dir = trim((string) ($this->settings['rrd_dir'] ?? ''));
        if ($dir !== '') {
            return $dir;
        }

        return ObserviumConfigReader::read($this->installPath())['rrd_dir'] ?? $this->installPath() . '/rrd';
    }

    private function installPath(): string
    {
        $path = trim((string) ($this->settings['install_path'] ?? ''));

        return $path !== '' ? rtrim($path, '/') : ObserviumConfigReader::DEFAULT_PATH;
    }

    private function secret(string $stored): string
    {
        return $stored !== '' && $this->service ? $this->service->decryptPassword($stored) : $stored;
    }
}
