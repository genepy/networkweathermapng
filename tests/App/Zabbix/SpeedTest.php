<?php

declare(strict_types=1);

namespace Tests\App\Zabbix;

use App\Services\ZabbixClient;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . "/src/app/Services/DataSourceService.php";

final class SpeedTest extends TestCase
{
    public function testSnmpKeys(): void
    {
        $this->assertSame(
            ['net.if.speed[ifHighSpeed.12]', 'net.if.speed[ifSpeed.12]'],
            ZabbixClient::speedKeysFor('net.if.in[ifHCInOctets.12]')
        );
        $this->assertSame(
            ['net.if.speed[ifHighSpeed.3]', 'net.if.speed[ifSpeed.3]'],
            ZabbixClient::speedKeysFor('net.if.in[ifInOctets.3]')
        );
    }

    public function testAgentKeys(): void
    {
        $this->assertSame(
            ['vfs.file.contents["/sys/class/net/eth0/speed"]', 'net.if.speed[eth0]'],
            ZabbixClient::speedKeysFor('net.if.in["eth0",bytes]')
        );
        $this->assertSame([], ZabbixClient::speedKeysFor('system.cpu.load'));
    }

    public function testUnits(): void
    {
        $this->assertSame(10000000000, ZabbixClient::speedToBps(1.0E10, 'bps', 'net.if.speed[ifHighSpeed.1]'));
        $this->assertSame(1000000000, ZabbixClient::speedToBps(1000, '', 'net.if.speed[ifHighSpeed.1]'));
        $this->assertSame(1000000000, ZabbixClient::speedToBps(1000, '', 'vfs.file.contents["/sys/class/net/eth0/speed"]'));
        $this->assertSame(100000000, ZabbixClient::speedToBps(100000000, '', 'net.if.speed[ifSpeed.1]'));
        $this->assertSame(0, ZabbixClient::speedToBps(-1, 'bps', 'x'));
    }
}
