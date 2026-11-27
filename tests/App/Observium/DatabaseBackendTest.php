<?php

declare(strict_types=1);

namespace Tests\App\Observium;

use App\Services\Observium\DatabaseBackend;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Runs the MySQL backend against an SQLite copy of the parts of Observium's
 * schema it reads.
 */
final class DatabaseBackendTest extends TestCase
{
    public function testDevicesAndSearch(): void
    {
        $backend = new DatabaseBackend($this->observium(true));

        $this->assertSame(['core-rtr1', 'paris-sw1'], array_column($backend->devices(), 'hostname'));
        $this->assertSame(['paris-sw1'], array_column($backend->devices('paris'), 'hostname'));
        $this->assertFalse($backend->device('paris-sw1')['up']);
        $this->assertSame(1, $backend->device('CORE-RTR1.example.net')['device_id']);
    }

    public function testPortRatesAndSpeedFromPortsTable(): void
    {
        $port = (new DatabaseBackend($this->observium(true)))->port(10);

        $this->assertSame('core-rtr1', $port['hostname']);
        $this->assertSame(10000000000, $port['speed']);
        $this->assertSame(800.0, $port['in_rate']);
        $this->assertSame(1600.0, $port['out_rate']);
    }

    public function testPortRatesFromPortsStateTable(): void
    {
        $port = (new DatabaseBackend($this->observium(false)))->port(10);

        $this->assertSame(800.0, $port['in_rate']);
        $this->assertSame(1600.0, $port['out_rate']);
    }

    public function testPortByNameMatchesNameDescrOrAlias(): void
    {
        $backend = new DatabaseBackend($this->observium(true));

        $this->assertSame(10, $backend->portByName('core-rtr1', 'xe-0/0/1')['port_id']);
        $this->assertSame(10, $backend->portByName('core-rtr1', 'TenGigabitEthernet0/1')['port_id']);
        $this->assertSame(11, $backend->portByName('core-rtr1', 'uplink paris')['port_id']);
        $this->assertNull($backend->portByName('core-rtr1', 'xe-9/9/9'));
        $this->assertNull($backend->portByName('unknown', 'xe-0/0/1'));
    }

    public function testDeletedPortsAreHidden(): void
    {
        $ports = (new DatabaseBackend($this->observium(true)))->ports(1);

        $this->assertSame([10, 11], array_column($ports, 'port_id'));
    }

    public function testPortGroupsOfObserviumProfessional(): void
    {
        // Community has no groups tables: no groups, not an error
        $this->assertNull((new DatabaseBackend($this->observium(true)))->portGroups());
        $this->assertNull((new DatabaseBackend($this->observium(true)))->groupPortIds(1));

        $pdo = $this->observium(true);
        $pdo->exec('CREATE TABLE `groups` (group_id INTEGER PRIMARY KEY, entity_type TEXT, group_name TEXT)');
        $pdo->exec('CREATE TABLE group_table (group_id INTEGER, entity_type TEXT, entity_id INTEGER)');
        $pdo->exec("INSERT INTO `groups` VALUES (1, 'port', 'Peering'), (2, 'device', 'Routers'), (3, 'port', 'Empty')");
        // port 12 is deleted
        $pdo->exec("INSERT INTO group_table VALUES (1, 'port', 11), (1, 'port', 10), (1, 'port', 12), (2, 'device', 1)");
        $backend = new DatabaseBackend($pdo);

        $this->assertSame([
            ['group_id' => 3, 'name' => 'Empty', 'ports' => 0],
            ['group_id' => 1, 'name' => 'Peering', 'ports' => 3],
        ], $backend->portGroups());
        $this->assertSame([10, 11], $backend->groupPortIds(1));
        $this->assertSame([], $backend->groupPortIds(3));
    }

    private function observium(bool $ratesInPorts): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $rateColumns = $ratesInPorts ? ', ifInOctets_rate INTEGER, ifOutOctets_rate INTEGER' : '';
        $pdo->exec('CREATE TABLE devices (device_id INTEGER PRIMARY KEY, hostname TEXT, sysName TEXT, status INTEGER, disabled INTEGER)');
        $pdo->exec("CREATE TABLE ports (port_id INTEGER PRIMARY KEY, device_id INTEGER, ifIndex INTEGER, ifName TEXT, ifDescr TEXT,
            ifAlias TEXT, ifSpeed INTEGER, ifHighSpeed INTEGER, ifOperStatus TEXT, deleted INTEGER {$rateColumns})");
        $pdo->exec("INSERT INTO devices VALUES (1, 'core-rtr1', 'core-rtr1.example.net', 1, 0), (2, 'paris-sw1', '', 0, 0), (3, 'old', '', 1, 1)");

        $ports = [
            [10, 1, 1, 'xe-0/0/1', 'TenGigabitEthernet0/1', '', 4294967295, 10000, 'up', 0, 100, 200],
            [11, 1, 2, 'xe-0/0/2', 'xe-0/0/2', 'uplink paris', 1000000000, 0, 'down', 0, null, null],
            [12, 1, 3, 'xe-0/0/3', 'xe-0/0/3', '', 0, 0, 'up', 1, 0, 0],
        ];
        foreach ($ports as $p) {
            $values = $ratesInPorts ? $p : array_slice($p, 0, 10);
            $pdo->prepare('INSERT INTO ports VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')')->execute($values);
        }

        if (!$ratesInPorts) {
            $pdo->exec('CREATE TABLE "ports-state" (port_id INTEGER PRIMARY KEY, ifInOctets_rate INTEGER, ifOutOctets_rate INTEGER)');
            $pdo->exec('INSERT INTO "ports-state" VALUES (10, 100, 200)');
        }

        return $pdo;
    }
}
