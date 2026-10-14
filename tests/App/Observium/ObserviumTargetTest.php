<?php

declare(strict_types=1);

namespace Tests\App\Observium;

use App\Services\Observium\ObserviumConfigReader;
use App\Services\Observium\ObserviumTarget;
use App\Services\Observium\RrdReader;
use PHPUnit\Framework\TestCase;

final class ObserviumTargetTest extends TestCase
{
    public function testPortById(): void
    {
        $t = ObserviumTarget::parse('observium:port:1234');
        $this->assertSame(ObserviumTarget::PORT, $t->kind);
        $this->assertSame(1234, $t->portId);
    }

    public function testPortByHostAndInterfaceKeepsColonsInName(): void
    {
        $t = ObserviumTarget::parse('observium:host:core-rtr1:if:Gi1/0/1:2');
        $this->assertSame('core-rtr1', $t->host);
        $this->assertSame('Gi1/0/1:2', $t->interface);
    }

    public function testDeviceStatus(): void
    {
        $t = ObserviumTarget::parse('Observium:host:core-rtr1:status');
        $this->assertSame(ObserviumTarget::STATUS, $t->kind);
        $this->assertSame('core-rtr1', $t->host);
    }

    public function testOtherTargetsAreIgnored(): void
    {
        $this->assertNull(ObserviumTarget::parse('zabbix:itemid:1'));
        $this->assertNull(ObserviumTarget::parse('observium:port:abc'));
        $this->assertNull(ObserviumTarget::parse('observium:host:x'));
    }

    public function testConfigPhpIsParsedNotExecuted(): void
    {
        $php = <<<'PHP'
<?php
$config['db_extension'] = 'mysqli';
$config['db_host']      = 'db.example.org:3307';
$config['db_user']      = "observium";
$config['db_pass']      = 'p@ss\'word';
$config['db_name']      = 'observium';
$config['rrd_dir']      = '/data/rrd';
system('touch /tmp/should-not-run');
PHP;
        $parsed = ObserviumConfigReader::parse($php);

        $this->assertSame('db.example.org', $parsed['db']['host']);
        $this->assertSame(3307, $parsed['db']['port']);
        $this->assertSame('observium', $parsed['db']['user']);
        $this->assertSame("p@ss'word", $parsed['db']['password']);
        $this->assertSame('/data/rrd', $parsed['rrd_dir']);
    }

    public function testConfigDefaultsToTheInstallFolder(): void
    {
        $parsed = ObserviumConfigReader::parse('<?php', '/srv/observium');

        $this->assertSame('localhost', $parsed['db']['host']);
        $this->assertSame('/srv/observium/rrd', $parsed['rrd_dir']);
    }

    public function testRrdFetchOutputIsConvertedToBits(): void
    {
        $output = <<<'TXT'
                         INOCTETS            OUTOCTETS          INERRORS

1760000000: 1.2500000000e+05 2.5000000000e+04 0.0000000000e+00
1760000300: -nan -nan -nan
1760000600: 2.5000000000e+05 5.0000000000e+04 0.0000000000e+00
TXT;
        $points = RrdReader::parseFetch($output);

        $this->assertCount(2, $points);
        $this->assertSame(['in' => 2000000.0, 'out' => 400000.0, 'time' => 1760000600], $points[1]);
    }

    public function testRrdPathRejectsTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RrdReader('/opt/observium/rrd'))->fileFor('../../etc', 1);
    }
}
