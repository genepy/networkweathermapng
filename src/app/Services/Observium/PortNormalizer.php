<?php
/**
 * NetworkWeathermapNG - turns raw Observium port and device rows (from the
 * API or the database) into the arrays described in ObserviumBackend.
 */

declare(strict_types=1);

namespace App\Services\Observium;

final class PortNormalizer
{
    public static function device(array $row): array
    {
        return [
            'device_id' => (int) ($row['device_id'] ?? 0),
            'hostname' => (string) ($row['hostname'] ?? ''),
            'sysName' => (string) ($row['sysName'] ?? ''),
            'up' => (int) ($row['status'] ?? 0) === 1,
        ];
    }

    public static function port(array $row, string $hostname = ''): array
    {
        return [
            'port_id' => (int) ($row['port_id'] ?? 0),
            'device_id' => (int) ($row['device_id'] ?? 0),
            'hostname' => (string) ($row['hostname'] ?? $hostname),
            'ifIndex' => (int) ($row['ifIndex'] ?? 0),
            'ifName' => (string) ($row['ifName'] ?? ''),
            'ifDescr' => (string) ($row['ifDescr'] ?? ''),
            'ifAlias' => (string) ($row['ifAlias'] ?? ''),
            'speed' => self::speed($row),
            'in_rate' => self::rate($row['ifInOctets_rate'] ?? null),
            'out_rate' => self::rate($row['ifOutOctets_rate'] ?? null),
            'up' => strtolower((string) ($row['ifOperStatus'] ?? '')) === 'up',
        ];
    }

    /**
     * Interface speed in bit/s. ifHighSpeed is in Mbit/s and is the only
     * correct value above 4 Gbit/s, where ifSpeed saturates.
     */
    public static function speed(array $row): ?int
    {
        $high = (int) ($row['ifHighSpeed'] ?? 0);
        if ($high > 0) {
            return $high * 1000000;
        }

        $speed = (int) ($row['ifSpeed'] ?? 0);

        return $speed > 0 ? $speed : null;
    }

    /** Observium stores octets per second; Weathermap works in bits per second. */
    public static function rate(mixed $octetsPerSecond): ?float
    {
        if ($octetsPerSecond === null || $octetsPerSecond === '' || !is_numeric($octetsPerSecond)) {
            return null;
        }

        return (float) $octetsPerSecond * 8;
    }

    /** True when $interface names this port (ifName, ifDescr or ifAlias, case-insensitive). */
    public static function matches(array $port, string $interface): bool
    {
        $wanted = strtolower(trim($interface));
        foreach (['ifName', 'ifDescr', 'ifAlias'] as $field) {
            if ($wanted !== '' && strtolower(trim((string) ($port[$field] ?? ''))) === $wanted) {
                return true;
            }
        }

        return false;
    }

    public static function label(array $port): string
    {
        $name = $port['ifName'] !== '' ? $port['ifName'] : $port['ifDescr'];
        if ($port['ifAlias'] !== '' && $port['ifAlias'] !== $name) {
            $name .= ' (' . $port['ifAlias'] . ')';
        }

        return $name !== '' ? $name : 'ifIndex ' . $port['ifIndex'];
    }
}
