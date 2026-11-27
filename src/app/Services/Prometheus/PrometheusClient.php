<?php
/**
 * NetworkWeathermapNG - Prometheus data source (F84).
 *
 * Reads interface counters from the HTTP API of Prometheus, or of a server
 * that speaks it (VictoriaMetrics, Thanos, Mimir): /api/v1/query,
 * /api/v1/query_range, /api/v1/series and /api/v1/label/<name>/values.
 * The counters are the IF-MIB ones of snmp_exporter (module if_mib):
 * ifHCInOctets and ifHCOutOctets for the traffic, ifHighSpeed for the
 * speed and ifOperStatus for a down interface (F72).
 *
 * Settings (data source form): the host label (instance), the interface
 * label (ifName), an extra label filter (job="snmp"), the rate window (5m)
 * and whether to verify the HTTPS certificate. Authentication: an API
 * token is sent as a Bearer token, else a user and password as basic auth.
 *
 * A selection (the editor's picker, or a TARGET, see PrometheusTarget) is
 * either {hostId, interfaceId} or {in, out} with two PromQL expressions.
 * The interfaces of a host are read once per request, all together.
 */

declare(strict_types=1);

namespace App\Services\Prometheus;

use App\Services\DataSourceClientInterface;
use App\Services\DataSourceService;
use App\Services\Observium\ObserviumClient;

final class PrometheusClient implements DataSourceClientInterface
{
    public const DEFAULT_HOST_LABEL = 'instance';
    public const DEFAULT_INTERFACE_LABEL = 'ifName';
    public const DEFAULT_RATE_WINDOW = '5m';

    /** ifOperStatus values of an interface that is down: down, notPresent, lowerLayerDown */
    private const DOWN_STATES = [2, 6, 7];

    /** Most points a history asks for */
    private const MAX_POINTS = 300;

    /** @var callable(string, list<string>): array{status: int, body: string} */
    private $transport;
    private array $settings;
    private array $debug = [];

    /** @var array<string, array<string, array>> interfaces by host, see interfaces() */
    private array $interfaces = [];
    /** @var array<string, array<string, array{0: float, 1: float}>> */
    private array $rates = [];
    /** @var array<string, array<string, int>> */
    private array $speeds = [];
    /** @var array<string, array<string, int>> */
    private array $states = [];

    public function __construct(
        private readonly array $source,
        private readonly ?DataSourceService $service = null,
        ?callable $transport = null,
        private readonly ?int $now = null,
    ) {
        $settings = json_decode((string) ($source['settings'] ?? ''), true);
        $this->settings = is_array($settings) ? $settings : [];
        $this->transport = $transport ?? $this->curlTransport(...);
    }

    public function hostLabel(): string
    {
        return self::labelName((string) ($this->settings['host_label'] ?? ''), self::DEFAULT_HOST_LABEL);
    }

    public function interfaceLabel(): string
    {
        return self::labelName((string) ($this->settings['interface_label'] ?? ''), self::DEFAULT_INTERFACE_LABEL);
    }

    /** The rate window of the queries, a PromQL duration */
    public function rateWindow(): string
    {
        $window = trim((string) ($this->settings['rate_window'] ?? ''));
        return preg_match('/^\d+[smhd]$/', $window) ? $window : self::DEFAULT_RATE_WINDOW;
    }

    /** The extra label filter of the settings, as matchers without braces */
    public function filter(): string
    {
        return trim(trim((string) ($this->settings['filter'] ?? '')), '{}, ');
    }

    public function testConnection(): array
    {
        try {
            $this->query('1');
            $version = '';
            try {
                $info = $this->get('status/buildinfo');
                $version = (string) ($info['version'] ?? '');
            } catch (\Throwable) {
                // VictoriaMetrics and others may not answer it
            }
            $hosts = count($this->hostNames());
            return [
                'success' => true,
                'message' => sprintf('Connected%s. %d hosts with %s (label %s).', $version !== '' ? ' (version ' . $version . ')' : '', $hosts, 'ifHCInOctets', $this->hostLabel()),
                'version' => $version,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function getHosts(array $filters = []): array
    {
        $search = strtolower(trim((string) ($filters['search'] ?? '')));
        $out = [];
        foreach ($this->hostNames() as $host) {
            if ($search === '' || str_contains(strtolower($host), $search)) {
                $out[] = ['hostid' => $host, 'host' => $host, 'name' => $host, 'status' => 'up'];
            }
        }
        return $out;
    }

    public function getHostInterfaces(string $hostId): array
    {
        return array_values(array_map(static fn (array $i): array => [
            'interfaceid' => $i['name'],
            'name' => $i['name'],
            'ifIndex' => $i['ifIndex'],
        ], $this->interfaces($hostId)));
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
     * Interfaces of a host for the editor's picker, with the TARGET to
     * write and the speed to pre-fill BANDWIDTH.
     */
    public function getInterfaceBandwidthOptions(string $hostId): array
    {
        $speeds = $this->speeds($hostId);
        $states = $this->states($hostId);
        $options = [];
        foreach ($this->interfaces($hostId) as $key => $i) {
            $speed = $speeds[$key] ?? null;
            $down = in_array($states[$key] ?? 1, self::DOWN_STATES, true);
            $alias = $i['ifAlias'] !== '' && $i['ifAlias'] !== $i['name'] ? ' (' . $i['ifAlias'] . ')' : '';
            $options[] = [
                'interfaceId' => $i['name'],
                'label' => $i['name'] . $alias . ($down ? ' [down]' : ''),
                'target' => PrometheusTarget::forInterface((int) ($this->source['id'] ?? 0), $hostId, $i['name']),
                'speed' => $speed,
                'bandwidth' => $speed ? ObserviumClient::formatSpeed($speed) : '',
                'rxKey' => '',
                'txKey' => '',
                'ifIndex' => $i['ifIndex'],
                'names' => array_values(array_unique(array_filter([$i['name'], $i['ifDescr'], $i['ifAlias']]))),
            ];
        }
        usort($options, static fn (array $a, array $b): int => strnatcasecmp($a['interfaceId'], $b['interfaceId']));
        return $options;
    }

    /** @return array{in?: float, out?: float} */
    public function getBandwidthCurrent(array $selection): array
    {
        if (isset($selection['in']) || isset($selection['out'])) {
            $in = trim((string) ($selection['in'] ?? ''));
            $out = trim((string) ($selection['out'] ?? ''));
            $values = [$in === '' ? 0.0 : $this->instantSum($in), $out === '' ? 0.0 : $this->instantSum($out)];
            return $values[0] === null && $values[1] === null ? [] : ['in' => (float) $values[0], 'out' => (float) $values[1]];
        }
        $host = (string) ($selection['hostId'] ?? '');
        $key = $this->interfaceKey($host, (string) ($selection['interfaceId'] ?? ''));
        $rates = $key !== null ? ($this->ratesOf($host)[$key] ?? null) : null;
        return $rates ? ['in' => $rates[0], 'out' => $rates[1]] : [];
    }

    /** Interface speed in bit/s (ifHighSpeed, else ifSpeed), null when unknown */
    public function getInterfaceSpeed(array $selection): ?int
    {
        $host = (string) ($selection['hostId'] ?? '');
        $key = $this->interfaceKey($host, (string) ($selection['interfaceId'] ?? ''));
        return $key !== null ? ($this->speeds($host)[$key] ?? null) : null;
    }

    /** Whether the interface of a selection reports itself down (F72) */
    public function interfaceDown(array $selection): bool
    {
        $host = (string) ($selection['hostId'] ?? '');
        if ($host === '' || isset($selection['in']) || isset($selection['out'])) {
            return false;
        }
        $key = $this->interfaceKey($host, (string) ($selection['interfaceId'] ?? ''));
        return $key !== null && in_array($this->states($host)[$key] ?? 1, self::DOWN_STATES, true);
    }

    /**
     * Traffic history in the shape of ObserviumClient::getBandwidthData()
     * (the hover card, TrafficSeries), from query_range.
     */
    public function getBandwidthData(array $selection, int $minutes = 1440): array
    {
        [$in, $out, $label] = $this->expressions($selection);
        if ($in === '' && $out === '') {
            return [];
        }
        $end = $this->now ?? time();
        $start = $end - max(1, $minutes) * 60;
        $step = max(60, (int) ceil(($end - $start) / self::MAX_POINTS / 60) * 60);
        $series = [];
        foreach (['in' => $in, 'out' => $out] as $dir => $expr) {
            $points = $expr === '' ? [] : $this->rangeSum($expr, $start, $end, $step);
            $last = $points === [] ? 0 : end($points)['v'];
            $series[$dir] = [
                'itemid' => $label,
                'name' => $label . ' ' . $dir,
                'units' => 'bps',
                'lastvalue' => (string) $last,
                'series' => $points,
            ];
        }
        if ($series['in']['series'] === [] && $series['out']['series'] === []) {
            return [];
        }
        return ['hostname' => (string) ($selection['hostId'] ?? ''), 'in' => $series['in'], 'out' => $series['out']];
    }

    public function getDebugInfo(): array
    {
        return $this->debug;
    }

    /**
     * The IN and OUT expressions of a selection, in bit/s, and a label
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public function expressions(array $selection): array
    {
        if (isset($selection['in']) || isset($selection['out'])) {
            return [trim((string) ($selection['in'] ?? '')), trim((string) ($selection['out'] ?? '')), 'query'];
        }
        $host = (string) ($selection['hostId'] ?? '');
        $interface = (string) ($selection['interfaceId'] ?? '');
        if ($host === '' || $interface === '') {
            return ['', '', ''];
        }
        $m = $this->matchers([$this->hostLabel() => $host, $this->interfaceLabel() => $interface]);
        $w = $this->rateWindow();
        return ["rate(ifHCInOctets{$m}[$w])*8", "rate(ifHCOutOctets{$m}[$w])*8", $interface];
    }

    /** "{a="b",c="d",<filter>}" with the values escaped */
    public function matchers(array $labels): string
    {
        $parts = [];
        foreach ($labels as $name => $value) {
            $parts[] = $name . '="' . addcslashes((string) $value, "\\\"\n") . '"';
        }
        if ($this->filter() !== '') {
            $parts[] = $this->filter();
        }
        return '{' . implode(',', $parts) . '}';
    }

    /** @return list<string> the values of the host label on interface counters */
    private function hostNames(): array
    {
        $values = $this->get('label/' . rawurlencode($this->hostLabel()) . '/values', [['match[]', 'ifHCInOctets' . $this->matchers([])]]);
        $hosts = array_values(array_filter(array_map('strval', is_array($values) ? $values : []), static fn (string $h) => $h !== ''));
        natcasesort($hosts);
        return array_values($hosts);
    }

    /**
     * The interfaces of a host, by key (the interface label, or the
     * ifIndex when a series has no such label)
     *
     * @return array<string, array{name: string, ifIndex: int|null, ifDescr: string, ifAlias: string}>
     */
    private function interfaces(string $host): array
    {
        if (!isset($this->interfaces[$host])) {
            $series = $this->get('series', [['match[]', 'ifHCInOctets' . $this->matchers([$this->hostLabel() => $host])]]);
            $out = [];
            foreach (is_array($series) ? $series : [] as $labels) {
                $key = $this->keyOf((array) $labels);
                if ($key === null) {
                    continue;
                }
                $out[$key] = [
                    'name' => (string) ($labels[$this->interfaceLabel()] ?? $labels['ifDescr'] ?? $key),
                    'ifIndex' => isset($labels['ifIndex']) && ctype_digit((string) $labels['ifIndex']) ? (int) $labels['ifIndex'] : null,
                    'ifDescr' => (string) ($labels['ifDescr'] ?? ''),
                    'ifAlias' => (string) ($labels['ifAlias'] ?? ''),
                ];
            }
            $this->interfaces[$host] = $out;
        }
        return $this->interfaces[$host];
    }

    /** The key of an interface named by its label value (or its ifIndex) */
    private function interfaceKey(string $host, string $interface): ?string
    {
        if ($host === '' || $interface === '') {
            return null;
        }
        foreach ($this->interfaces($host) as $key => $i) {
            if ($i['name'] === $interface || (string) $key === $interface) {
                return (string) $key;
            }
        }
        // a series the listing missed: its label value is its key
        return $interface;
    }

    /** The key of a series' interface: its interface label, else its ifIndex */
    private function keyOf(array $labels): ?string
    {
        $name = (string) ($labels[$this->interfaceLabel()] ?? '');
        if ($name !== '') {
            return $name;
        }
        $index = (string) ($labels['ifIndex'] ?? '');
        return $index !== '' ? $index : null;
    }

    /** @return array<string, array{0: float, 1: float}> [in, out] bit/s by interface key */
    private function ratesOf(string $host): array
    {
        if (!isset($this->rates[$host])) {
            $m = $this->matchers([$this->hostLabel() => $host]);
            $w = $this->rateWindow();
            $in = $this->byInterface("rate(ifHCInOctets{$m}[$w])*8", $host);
            $out = $this->byInterface("rate(ifHCOutOctets{$m}[$w])*8", $host);
            $rates = [];
            foreach (array_unique(array_merge(array_keys($in), array_keys($out))) as $key) {
                $rates[(string) $key] = [(float) ($in[$key] ?? 0), (float) ($out[$key] ?? 0)];
            }
            $this->rates[$host] = $rates;
        }
        return $this->rates[$host];
    }

    /** @return array<string, int> speed in bit/s by interface key */
    private function speeds(string $host): array
    {
        if (!isset($this->speeds[$host])) {
            $m = $this->matchers([$this->hostLabel() => $host]);
            $speeds = array_map(static fn (float $v): int => (int) round($v * 1e6), $this->byInterface("ifHighSpeed$m", $host));
            foreach ($this->byInterface("ifSpeed$m", $host) as $key => $v) {
                if (empty($speeds[$key]) && $v > 0) {
                    $speeds[$key] = (int) round($v);
                }
            }
            $this->speeds[$host] = array_filter($speeds, static fn (int $v) => $v > 0);
        }
        return $this->speeds[$host];
    }

    /** @return array<string, int> ifOperStatus by interface key */
    private function states(string $host): array
    {
        if (!isset($this->states[$host])) {
            $m = $this->matchers([$this->hostLabel() => $host]);
            try {
                $this->states[$host] = array_map('intval', $this->byInterface("ifOperStatus$m", $host));
            } catch (\Throwable) {
                // no status: the interface counts as up
                $this->states[$host] = [];
            }
        }
        return $this->states[$host];
    }

    /**
     * An instant vector by interface key; a metric without the interface
     * label is matched by ifIndex to the interfaces of the listing.
     *
     * @return array<string, float>
     */
    private function byInterface(string $expr, string $host): array
    {
        $byIndex = [];
        foreach ($this->interfaces($host) as $key => $i) {
            if ($i['ifIndex'] !== null) {
                $byIndex[(string) $i['ifIndex']] = (string) $key;
            }
        }
        $data = $this->query($expr);
        $out = [];
        foreach ((array) ($data['result'] ?? []) as $row) {
            $labels = (array) ($row['metric'] ?? []);
            $key = (string) ($labels[$this->interfaceLabel()] ?? '');
            if ($key === '' && isset($labels['ifIndex'])) {
                $key = $byIndex[(string) $labels['ifIndex']] ?? (string) $labels['ifIndex'];
            }
            $value = self::number($row['value'][1] ?? null);
            if ($key !== '' && $value !== null) {
                $out[$key] = ($out[$key] ?? 0) + $value;
            }
        }
        return $out;
    }

    /** The sum of an instant query (vector or scalar), null when it has no value */
    private function instantSum(string $expr): ?float
    {
        $data = $this->query($expr);
        $type = (string) ($data['resultType'] ?? '');
        if ($type === 'scalar') {
            return self::number($data['result'][1] ?? null);
        }
        $sum = null;
        foreach ((array) ($data['result'] ?? []) as $row) {
            $value = self::number($row['value'][1] ?? null);
            if ($value !== null) {
                $sum = ($sum ?? 0) + $value;
            }
        }
        return $sum;
    }

    /**
     * A range query summed over its series, point by point
     *
     * @return list<array{t: int, v: float}>
     */
    private function rangeSum(string $expr, int $start, int $end, int $step): array
    {
        $data = $this->get('query_range', [['query', $expr], ['start', (string) $start], ['end', (string) $end], ['step', (string) $step]]);
        $sums = [];
        foreach ((array) ($data['result'] ?? []) as $row) {
            foreach ((array) ($row['values'] ?? []) as $point) {
                $value = self::number($point[1] ?? null);
                if ($value !== null) {
                    $t = (int) $point[0];
                    $sums[$t] = ($sums[$t] ?? 0) + $value;
                }
            }
        }
        ksort($sums);
        $out = [];
        foreach ($sums as $t => $v) {
            $out[] = ['t' => (int) $t, 'v' => (float) $v];
        }
        return $out;
    }

    private function query(string $expr): array
    {
        $data = $this->get('query', [['query', $expr], ['time', (string) ($this->now ?? time())]]);
        return is_array($data) ? $data : [];
    }

    /**
     * GET /api/v1/<path>: the "data" of the answer. Throws with the
     * server's message on an error.
     *
     * @param list<array{0: string, 1: string}> $params
     */
    private function get(string $path, array $params = []): mixed
    {
        $base = rtrim(trim((string) ($this->source['url'] ?? '')), '/');
        if ($base === '') {
            throw new \RuntimeException('The Prometheus URL is empty');
        }
        $base = (string) preg_replace('#/api/v1$#', '', $base);
        $query = implode('&', array_map(static fn (array $p): string => rawurlencode($p[0]) . '=' . rawurlencode($p[1]), $params));
        $url = $base . '/api/v1/' . $path . ($query !== '' ? '?' . $query : '');

        $headers = ['Accept: application/json'];
        $token = trim((string) ($this->source['api_token'] ?? ''));
        $user = trim((string) ($this->source['username'] ?? ''));
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        } elseif ($user !== '') {
            $headers[] = 'Authorization: Basic ' . base64_encode($user . ':' . $this->password());
        }

        $this->debug = ['request' => $url];
        $answer = ($this->transport)($url, $headers);
        $status = (int) ($answer['status'] ?? 0);
        $body = (string) ($answer['body'] ?? '');
        $this->debug['status'] = $status;
        $this->debug['response'] = mb_substr($body, 0, 2000);

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException(sprintf('Prometheus answered HTTP %d without JSON', $status));
        }
        if (($data['status'] ?? '') !== 'success') {
            throw new \RuntimeException('Prometheus: ' . (string) ($data['error'] ?? ('HTTP ' . $status)));
        }
        return $data['data'] ?? null;
    }

    private function password(): string
    {
        $stored = (string) ($this->source['password'] ?? '');
        return $stored !== '' && $this->service ? $this->service->decryptPassword($stored) : $stored;
    }

    private function curlTransport(string $url, array $headers): array
    {
        $verify = ($this->settings['verify_tls'] ?? true) !== false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
            // an unreachable server must not hold a render (F50)
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException('Cannot reach Prometheus at ' . $url . ' (curl: ' . $error . ')');
        }
        return ['status' => $status, 'body' => (string) $body];
    }

    /** A sample value ("123.4", "NaN", "+Inf") as a number, null when it is not one */
    private static function number(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }
        $n = (float) $value;
        return is_finite($n) ? $n : null;
    }

    private static function labelName(string $name, string $default): string
    {
        $name = trim($name);
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) ? $name : $default;
    }
}
