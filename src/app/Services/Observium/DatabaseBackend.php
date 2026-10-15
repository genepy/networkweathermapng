<?php
/**
 * NetworkWeathermapNG - read-only access to Observium's MySQL database.
 *
 * Works with every edition, Community included. All the SQL that depends on
 * Observium's schema lives here, so a schema change after an Observium
 * upgrade is fixed in one place. Traffic rates are read from the ports table,
 * or from the older "ports-state" table when the ports table has no rate
 * columns.
 */

declare(strict_types=1);

namespace App\Services\Observium;

use PDO;

final class DatabaseBackend implements ObserviumBackend
{
    private ?bool $ratesInPortsTable = null;

    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    /**
     * @param array{host?: string, port?: int|string, name?: string, user?: string, password?: string, socket?: string} $db
     */
    public static function connect(array $db): self
    {
        $dsn = !empty($db['socket'])
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $db['socket'], $db['name'] ?? 'observium')
            : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'] ?? 'localhost', (int) ($db['port'] ?? 3306), $db['name'] ?? 'observium');

        $pdo = new PDO($dsn, $db['user'] ?? '', $db['password'] ?? '', [
            PDO::ATTR_TIMEOUT => 5,
        ]);

        return new self($pdo);
    }

    public function name(): string
    {
        return 'MySQL';
    }

    public function test(): string
    {
        $devices = (int) $this->pdo->query('SELECT COUNT(*) FROM devices')->fetchColumn();
        $ports = (int) $this->pdo->query('SELECT COUNT(*) FROM ports WHERE deleted = 0')->fetchColumn();

        return sprintf('%d devices and %d ports in the Observium database', $devices, $ports);
    }

    public function devices(string $search = ''): array
    {
        $sql = 'SELECT device_id, hostname, sysName, status FROM devices WHERE disabled = 0';
        $params = [];
        if ($search !== '') {
            $sql .= ' AND (hostname LIKE ? OR sysName LIKE ?)';
            $params = ['%' . $search . '%', '%' . $search . '%'];
        }
        $sql .= ' ORDER BY hostname';

        return array_map([PortNormalizer::class, 'device'], $this->rows($sql, $params));
    }

    public function device(string $hostname): ?array
    {
        $rows = $this->rows(
            'SELECT device_id, hostname, sysName, status FROM devices
             WHERE LOWER(hostname) = LOWER(?) OR LOWER(sysName) = LOWER(?)
             ORDER BY LOWER(hostname) = LOWER(?) DESC LIMIT 1',
            [$hostname, $hostname, $hostname]
        );

        return $rows ? PortNormalizer::device($rows[0]) : null;
    }

    public function ports(int $deviceId): array
    {
        return $this->portRows('p.device_id = ?', [$deviceId]);
    }

    public function port(int $portId): ?array
    {
        return $this->portRows('p.port_id = ?', [$portId])[0] ?? null;
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

    private function portRows(string $where, array $params): array
    {
        $columns = 'p.port_id, p.device_id, p.ifIndex, p.ifName, p.ifDescr, p.ifAlias, p.ifSpeed, p.ifHighSpeed, p.ifOperStatus, d.hostname';
        if ($this->ratesInPortsTable()) {
            $sql = "SELECT {$columns}, p.ifInOctets_rate, p.ifOutOctets_rate
                    FROM ports p JOIN devices d ON d.device_id = p.device_id";
        } else {
            $sql = "SELECT {$columns}, s.ifInOctets_rate, s.ifOutOctets_rate
                    FROM ports p JOIN devices d ON d.device_id = p.device_id
                    LEFT JOIN `ports-state` s ON s.port_id = p.port_id";
        }
        $sql .= " WHERE p.deleted = 0 AND {$where} ORDER BY p.ifIndex";

        return array_map(static fn(array $row): array => PortNormalizer::port($row), $this->rows($sql, $params));
    }

    private function ratesInPortsTable(): bool
    {
        if ($this->ratesInPortsTable === null) {
            try {
                $this->pdo->query('SELECT ifInOctets_rate FROM ports WHERE 1 = 0');
                $this->ratesInPortsTable = true;
            } catch (\PDOException) {
                $this->ratesInPortsTable = false;
            }
        }

        return $this->ratesInPortsTable;
    }

    private function rows(string $sql, array $params): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
