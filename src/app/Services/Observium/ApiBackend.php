<?php
/**
 * NetworkWeathermapNG - Observium REST API (Professional/Enterprise editions).
 *
 * Authenticates with an API token (Authorization: Bearer) when one is set,
 * otherwise with HTTP basic auth. Responses are parsed leniently: entity
 * lists may come keyed by id or as plain lists.
 */

declare(strict_types=1);

namespace App\Services\Observium;

final class ApiBackend implements ObserviumBackend
{
    /** @var callable(string, list<string>): array{status: int, body: string} */
    private $transport;

    /** @var array<int, array>|null devices by id, loaded once per request */
    private ?array $devices = null;

    /** @var array<int, list<array>> ports by device id */
    private array $portsByDevice = [];

    /** @var array<int, array> */
    private array $portsById = [];

    public array $lastRequest = [];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token = '',
        private readonly string $username = '',
        private readonly string $password = '',
        private readonly bool $verifyTls = true,
        ?callable $transport = null,
    ) {
        $this->transport = $transport ?? $this->curlTransport(...);
    }

    public function name(): string
    {
        return 'API';
    }

    public function test(): string
    {
        $data = $this->get('devices/');
        $count = $data['count'] ?? count($this->entities($data, 'devices'));

        return sprintf('%d devices visible through the API', (int) $count);
    }

    public function devices(string $search = ''): array
    {
        $devices = array_values($this->allDevices());
        if ($search !== '') {
            $needle = strtolower($search);
            $devices = array_values(array_filter($devices, static fn(array $d): bool =>
                str_contains(strtolower($d['hostname']), $needle) || str_contains(strtolower($d['sysName']), $needle)));
        }
        usort($devices, static fn(array $a, array $b): int => strcasecmp($a['hostname'], $b['hostname']));

        return $devices;
    }

    public function device(string $hostname): ?array
    {
        foreach ($this->allDevices() as $device) {
            if (strcasecmp($device['hostname'], $hostname) === 0 || strcasecmp($device['sysName'], $hostname) === 0) {
                return $device;
            }
        }

        return null;
    }

    public function ports(int $deviceId): array
    {
        if (!isset($this->portsByDevice[$deviceId])) {
            $hostname = $this->allDevices()[$deviceId]['hostname'] ?? '';
            $ports = [];
            foreach ($this->entities($this->get('ports/', ['device_id' => $deviceId]), 'ports') as $row) {
                // Older API versions ignore the filter: keep only this device's ports
                if ((int) ($row['device_id'] ?? $deviceId) !== $deviceId || !empty($row['deleted'])) {
                    continue;
                }
                $port = PortNormalizer::port($row, $hostname);
                $ports[] = $port;
                $this->portsById[$port['port_id']] = $port;
            }
            $this->portsByDevice[$deviceId] = $ports;
        }

        return $this->portsByDevice[$deviceId];
    }

    public function port(int $portId): ?array
    {
        if (!isset($this->portsById[$portId])) {
            $data = $this->get('ports/' . $portId . '/');
            $row = $data['port'] ?? null;
            if (!is_array($row)) {
                $rows = $this->entities($data, 'ports');
                $row = $rows[0] ?? null;
            }
            if (!is_array($row)) {
                return null;
            }
            $hostname = $this->allDevices()[(int) ($row['device_id'] ?? 0)]['hostname'] ?? '';
            $this->portsById[$portId] = PortNormalizer::port($row, $hostname);
        }

        return $this->portsById[$portId];
    }

    public function portByName(string $hostname, string $interface): ?array
    {
        $device = $this->device($hostname);
        if (!$device) {
            return null;
        }
        foreach ($this->ports($device['device_id']) as $port) {
            if (PortNormalizer::matches($port, $interface)) {
                return $port;
            }
        }

        return null;
    }

    /** @return array<int, array> */
    private function allDevices(): array
    {
        if ($this->devices === null) {
            $this->devices = [];
            foreach ($this->entities($this->get('devices/'), 'devices') as $row) {
                $device = PortNormalizer::device($row);
                $this->devices[$device['device_id']] = $device;
            }
        }

        return $this->devices;
    }

    /** @return list<array> */
    private function entities(array $data, string $key): array
    {
        $list = $data[$key] ?? [];

        return is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
    }

    private function get(string $path, array $query = []): array
    {
        $url = rtrim($this->baseUrl, '/');
        if (!str_contains($url, '/api/')) {
            $url .= '/api/v0';
        }
        $url .= '/' . ltrim($path, '/');
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $headers = ['Accept: application/json'];
        if ($this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        } elseif ($this->username !== '') {
            $headers[] = 'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password);
        }

        $this->lastRequest = ['url' => $url];
        $response = ($this->transport)($url, $headers);
        $this->lastRequest['status'] = $response['status'];

        if ($response['status'] === 401 || $response['status'] === 403) {
            throw new \RuntimeException('Observium API refused the credentials (HTTP ' . $response['status'] . ')');
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException('Observium API answered HTTP ' . $response['status'] . ' for ' . $url);
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            throw new \RuntimeException('Observium API did not return JSON for ' . $url);
        }
        if (isset($data['status']) && $data['status'] !== 'ok') {
            throw new \RuntimeException('Observium API error: ' . ($data['message'] ?? $data['status']));
        }

        return $data;
    }

    /** @return array{status: int, body: string} */
    private function curlTransport(string $url, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException('Cannot reach Observium at ' . $url . ': ' . $error);
        }

        return ['status' => $status, 'body' => (string) $body];
    }
}
