<?php
/**
 * NetworkWeathermapNG - one way of reading Observium (REST API or database).
 *
 * Devices and ports are returned as normalised arrays:
 *   device: ['device_id' => int, 'hostname' => string, 'sysName' => string, 'up' => bool]
 *   port:   ['port_id' => int, 'device_id' => int, 'hostname' => string, 'ifIndex' => int,
 *            'ifName' => string, 'ifDescr' => string, 'ifAlias' => string,
 *            'speed' => ?int (bit/s), 'in_rate' => ?float, 'out_rate' => ?float (bit/s),
 *            'up' => bool]
 */

declare(strict_types=1);

namespace App\Services\Observium;

interface ObserviumBackend
{
    /** Short name shown in "Test connection": "API" or "MySQL". */
    public function name(): string;

    /** Throws when Observium cannot be reached. Returns a human readable detail. */
    public function test(): string;

    /** @return list<array<string, mixed>> */
    public function devices(string $search = ''): array;

    public function device(string $hostname): ?array;

    /** @return list<array<string, mixed>> */
    public function ports(int $deviceId): array;

    public function port(int $portId): ?array;

    public function portByName(string $hostname, string $interface): ?array;
}
