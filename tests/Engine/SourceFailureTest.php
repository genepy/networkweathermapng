<?php

declare(strict_types=1);

namespace Tests\Engine;

use App\Services\MapService;
use PHPUnit\Framework\TestCase;

/**
 * F49, F50: a data source that fails never stops the render; the map
 * knows which source failed and how many links got no data.
 */
final class SourceFailureTest extends TestCase
{
    private const CONF = "WIDTH 400\nHEIGHT 300\n"
        . "NODE a\n\tPOSITION 50 50\nNODE b\n\tPOSITION 350 50\nNODE c\n\tPOSITION 200 250\n";

    /** Three links of Zabbix source 1, read through $svc */
    private function zabbixMap(object $svc): \WeatherMap
    {
        $conf = self::CONF;
        foreach (['ab' => ['a', 'b'], 'bc' => ['b', 'c'], 'ca' => ['c', 'a']] as $name => [$x, $y]) {
            $conf .= "LINK {$name}\n\tNODES {$x} {$y}\n\tBANDWIDTH 1G\n\tTARGET zabbix:{$name}\n"
                . "\tSET datasource {\"sourceId\":1,\"hostId\":\"h-{$name}\",\"inKey\":\"in\",\"outKey\":\"out\"}\n";
        }
        return $this->read($conf, 'WeatherMapDataSource_zabbix', $svc);
    }

    private function read(string $conf, string $plugin, object $svc): \WeatherMap
    {
        $file = TESTS_OUTPUT . '/source-failure.conf';
        file_put_contents($file, $conf);
        $map = new \WeatherMap();
        $map->plugins['data'][$plugin]->useService($svc);
        ob_start();
        try {
            $map->ReadConfig($file);
            $map->ReadData();
        } finally {
            ob_end_clean();
        }
        return $map;
    }

    public function testItemsOfOneSourceAreReadInOneCall(): void
    {
        $svc = new FakeZabbix();
        $map = $this->zabbixMap($svc);

        $this->assertSame(1, $svc->batches, 'one call for the source');
        $this->assertSame(0, $svc->single, 'no call per link');
        $this->assertEqualsWithDelta(100e6, (float) $map->links['bc']->bandwidth_in, 1);
        $this->assertSame([], $map->source_errors);
        $this->assertSame(0, $map->nodata_links);
    }

    public function testAnUnreachableServerIsTriedOnce(): void
    {
        $svc = new FakeZabbix();
        $svc->batchError = 'CURL error: Failed to connect to zabbix.example port 443 after 5001 ms: Timeout was reached';
        $map = $this->zabbixMap($svc);

        $this->assertSame(0, $svc->single, 'the other links are skipped for this render');
        $this->assertSame(3, $map->nodata_links);
        $failed = MapService::failedSources($map);
        $this->assertCount(1, $failed);
        $this->assertSame('Lab Zabbix', $failed[0]['name']);
        $this->assertTrue($failed[0]['down']);
        $this->assertSame(3, $failed[0]['links']);
    }

    public function testOneFailingItemDoesNotStopTheOthers(): void
    {
        $svc = new FakeZabbix();
        $svc->batchError = 'Invalid params.';
        $svc->failing = 'h-bc';
        $map = $this->zabbixMap($svc);

        $this->assertSame(3, $svc->single, 'read link by link after the batch failed');
        $this->assertEqualsWithDelta(100e6, (float) $map->links['ab']->bandwidth_in, 1);
        $this->assertEqualsWithDelta(100e6, (float) $map->links['ca']->bandwidth_in, 1);
        $this->assertSame(1, $map->nodata_links);
        $failed = MapService::failedSources($map);
        $this->assertCount(1, $failed);
        $this->assertFalse($failed[0]['down']);
        $this->assertSame(1, $failed[0]['links']);
        $this->assertSame('Item not found', $failed[0]['error']);
    }

    public function testAnObserviumSourceThatCannotConnectIsSkipped(): void
    {
        $svc = new FakeObservium();
        $conf = self::CONF;
        foreach (['ab' => ['a', 'b'], 'bc' => ['b', 'c'], 'ca' => ['c', 'a']] as $name => [$x, $y]) {
            $conf .= "LINK {$name}\n\tNODES {$x} {$y}\n\tBANDWIDTH 1G\n\tTARGET observium:port:{$name}\n";
        }
        $map = $this->read($conf, 'WeatherMapDataSource_observium', $svc);

        $this->assertSame(1, $svc->calls);
        $this->assertSame(3, $map->nodata_links);
        $failed = MapService::failedSources($map);
        $this->assertSame([['name' => 'Observium', 'error' => 'SQLSTATE[HY000] [2002] Connection refused', 'down' => true, 'links' => 3]], $failed);
    }
}

/** A Zabbix source answering 100 Mbit/s in and 50 out, or failing as told */
final class FakeZabbix
{
    public int $batches = 0;
    public int $single = 0;
    public ?string $batchError = null;
    public string $failing = '';

    public function getSource(int $id): array
    {
        return ['id' => $id, 'name' => 'Lab Zabbix'];
    }

    public function linkPageUrl(int $sourceId, array $selection): ?string
    {
        return null;
    }

    public function getLinkSpeed(int $sourceId, array $selection): ?int
    {
        return null;
    }

    public function getLinksBandwidthCurrent(int $sourceId, array $selections): array
    {
        $this->batches++;
        if ($this->batchError !== null) {
            throw new \RuntimeException($this->batchError);
        }
        return array_map(fn () => ['in' => 100e6, 'out' => 50e6], $selections);
    }

    public function getLinkBandwidthCurrent(int $sourceId, array $selection): array
    {
        $this->single++;
        if ($this->batchError !== null && str_starts_with($this->batchError, 'CURL')) {
            throw new \RuntimeException($this->batchError);
        }
        if (($selection['hostId'] ?? '') === $this->failing) {
            throw new \RuntimeException('Item not found');
        }
        return ['in' => 100e6, 'out' => 50e6];
    }
}

/** An Observium source whose database refuses the connection */
final class FakeObservium
{
    public int $calls = 0;

    public function getSource(int $id): ?array
    {
        return null;
    }

    public function readObserviumTarget(string $target, ?int $sourceId = null): ?array
    {
        $this->calls++;
        throw new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused');
    }

    public function observiumPageForTarget(string $target, ?int $sourceId = null): ?string
    {
        return null;
    }

    public function observiumSpeedForTarget(string $target, ?int $sourceId = null): ?int
    {
        return null;
    }
}
