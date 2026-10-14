<?php

declare(strict_types=1);

namespace Tests\App\Observium;

use App\Services\Observium\DatabaseBackend;
use App\Services\Observium\ObserviumClient;
use App\Services\Observium\RrdReader;
use PDO;
use PHPUnit\Framework\TestCase;

final class ObserviumClientTest extends TestCase
{
    public function testModeFollowsTheSettings(): void
    {
        $this->assertSame('mysql', (new ObserviumClient(['url' => '', 'settings' => null]))->mode());
        $this->assertSame('mysql', (new ObserviumClient(['url' => 'https://obs', 'settings' => null]))->mode());
        $this->assertSame('api', (new ObserviumClient(['url' => 'https://obs', 'api_token' => 't']))->mode());
        $this->assertSame('api', (new ObserviumClient(['url' => 'https://obs', 'username' => 'u']))->mode());
        $this->assertSame('mysql', (new ObserviumClient(['url' => 'https://obs', 'api_token' => 't', 'settings' => '{"mode":"mysql"}']))->mode());
    }

    public function testTargetsReturnBitsPerSecond(): void
    {
        $client = $this->client();

        $this->assertSame([800.0, 1600.0], $client->readTarget('observium:port:10'));
        $this->assertSame([800.0, 1600.0], $client->readTarget('observium:host:core-rtr1:if:xe-0/0/1'));
        $this->assertNull($client->readTarget('observium:port:404'));
    }

    public function testRrdIsUsedWhenTheDatabaseHasNoRate(): void
    {
        $rrd = new RrdReader('/rrd', 'rrdcached:42217', runner: function (array $command): string {
            $this->assertContains('/rrd/core-rtr1/port-2.rrd', $command);

            return "INOCTETS OUTOCTETS\n\n1760000000: 1.0e+03 2.0e+03\n";
        });

        $this->assertSame([8000.0, 16000.0], $this->client($rrd)->readTarget('observium:port:11'));
    }

    public function testDeviceStatusColoursDownDevicesRed(): void
    {
        $client = $this->client();

        $this->assertSame([0.0, 0.0], $client->readTarget('observium:host:core-rtr1:status'));
        $this->assertSame([100.0, 100.0], $client->readTarget('observium:host:paris-sw1:status'));
    }

    public function testPickerOptionsCarryTargetAndBandwidth(): void
    {
        $options = $this->client()->getInterfaceBandwidthOptions('1');

        $this->assertSame('observium:port:10', $options[0]['target']);
        $this->assertSame('10G', $options[0]['bandwidth']);
        $this->assertSame('xe-0/0/2 (uplink paris) [down]', $options[1]['label']);
        $this->assertSame(10000000000, $this->client()->speedForTarget('observium:host:core-rtr1:if:xe-0/0/1'));
    }

    public function testSpeedFormatting(): void
    {
        $this->assertSame('100G', ObserviumClient::formatSpeed(100000000000));
        $this->assertSame('2.5G', ObserviumClient::formatSpeed(2500000000));
        $this->assertSame('100M', ObserviumClient::formatSpeed(100000000));
    }

    private function client(?RrdReader $rrd = null): ObserviumClient
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE devices (device_id INTEGER PRIMARY KEY, hostname TEXT, sysName TEXT, status INTEGER, disabled INTEGER)');
        $pdo->exec('CREATE TABLE ports (port_id INTEGER PRIMARY KEY, device_id INTEGER, ifIndex INTEGER, ifName TEXT, ifDescr TEXT,
            ifAlias TEXT, ifSpeed INTEGER, ifHighSpeed INTEGER, ifOperStatus TEXT, deleted INTEGER, ifInOctets_rate INTEGER, ifOutOctets_rate INTEGER)');
        $pdo->exec("INSERT INTO devices VALUES (1, 'core-rtr1', '', 1, 0), (2, 'paris-sw1', '', 0, 0)");
        $pdo->exec("INSERT INTO ports VALUES (10, 1, 1, 'xe-0/0/1', 'xe-0/0/1', '', 0, 10000, 'up', 0, 100, 200),
            (11, 1, 2, 'xe-0/0/2', 'xe-0/0/2', 'uplink paris', 1000000000, 0, 'down', 0, NULL, NULL)");

        return new ObserviumClient(['id' => 1, 'type' => 'observium', 'url' => ''], null, new DatabaseBackend($pdo), $rrd);
    }
}
