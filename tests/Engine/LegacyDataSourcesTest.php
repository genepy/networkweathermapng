<?php

declare(strict_types=1);

namespace Tests\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The data sources taken from the Cacti Group's Weathermap (rrd, snmp,
 * snmp2c, snmp3, mrtg, tabfile, fping, time), so that maps from an older
 * Weathermap keep their data without being converted.
 */
final class LegacyDataSourcesTest extends TestCase
{
    public static function targets(): array
    {
        return [
            'rrd file' => ['/opt/observium/rrd/core-rtr1/port-3.rrd', 'WeatherMapDataSource_rrd'],
            'rrd with ds' => ['/var/lib/cacti/rra/rtr_traffic_in_42.rrd:traffic_in:traffic_out', 'WeatherMapDataSource_rrd'],
            'snmp' => ['snmp:public:rtr1:.1.3.6.1.2.1.2.2.1.10.7:.1.3.6.1.2.1.2.2.1.16.7', 'WeatherMapDataSource_snmp'],
            'snmp2c' => ['snmp2c:public:rtr1:1.3.6.1.2.1.31.1.1.1.6.5:1.3.6.1.2.1.31.1.1.1.10.5', 'WeatherMapDataSource_snmp2c'],
            'snmp3' => ['snmp3:CORE:rtr1:1.3.6.1.2.1.31.1.1.1.6.5:-', 'WeatherMapDataSource_snmp3'],
            'mrtg' => ['/var/www/mrtg/rtr1_4.html', 'WeatherMapDataSource_mrtg'],
            'tabfile' => ['/srv/data/traffic.tsv', 'WeatherMapDataSource_tabfile'],
            'fping' => ['fping:192.0.2.1', 'WeatherMapDataSource_fping'],
            'time' => ['time:Europe/Paris', 'WeatherMapDataSource_time'],
        ];
    }

    #[DataProvider('targets')]
    public function testEachTargetHasOneDataSource(string $target, string $class): void
    {
        $map = $this->loadString("NODE a\n\tPOSITION 10 10\n");

        $recognised = [];
        foreach ($map->plugins['data'] as $name => $plugin) {
            if ($plugin->Recognise($target)) {
                $recognised[] = $name;
            }
        }

        $this->assertSame([$class], $recognised);
    }

    public function testTabFileAndMrtgFeedLinks(): void
    {
        $dir = TESTS_ROOT . '/fixtures/datasources';
        $map = $this->loadString(<<<CONF
NODE a
	POSITION 10 10
NODE b
	POSITION 100 10
LINK core
	NODES a b
	BANDWIDTH 10K
	TARGET $dir/traffic.tsv
LINK mrtg
	NODES a b
	BANDWIDTH 100K
	TARGET $dir/mrtg-port.html
LINK mrtgaverage
	NODES a b
	BANDWIDTH 10K
	SET mrtg_value av
	TARGET $dir/mrtg-port.html
CONF);
        $map->ReadData();

        $this->assertEquals(1000, $map->links['core']->bandwidth_in);
        $this->assertEquals(2000, $map->links['core']->bandwidth_out);
        // MRTG pages give bytes per second
        $this->assertEquals(1500 * 8, $map->links['mrtg']->bandwidth_in);
        $this->assertEquals(2500 * 8, $map->links['mrtg']->bandwidth_out);
        $this->assertEquals(100 * 8, $map->links['mrtgaverage']->bandwidth_in);
        $this->assertEquals(200 * 8, $map->links['mrtgaverage']->bandwidth_out);
    }

    public function testMissingToolsDoNotStopTheMap(): void
    {
        $map = $this->loadString(<<<'CONF'
SET snmp_timeout 100
NODE a
	POSITION 10 10
NODE b
	POSITION 100 10
LINK rrd
	NODES a b
	TARGET /nonexistent/port-3.rrd
LINK snmp
	NODES a b
	TARGET snmp2c:public:192.0.2.1:1.3.6.1.2.1.31.1.1.1.6.5:1.3.6.1.2.1.31.1.1.1.10.5
LINK fixed
	NODES a b
	BANDWIDTH 1M
	TARGET static:100K:200K
CONF);
        $map->ReadData();

        $this->assertEquals(100000, $map->links['fixed']->bandwidth_in);
        $this->assertStringStartsWith("\x89PNG", $this->render($map));
    }

    public function testTimeGivesTheClock(): void
    {
        $map = $this->loadString("NODE clock\n\tPOSITION 10 10\n\tTARGET time:UTC\n");
        $map->ReadData();

        $this->assertEquals((int) gmdate('H'), $map->nodes['clock']->bandwidth_in);
        $this->assertEqualsWithDelta((int) gmdate('i'), $map->nodes['clock']->bandwidth_out, 1);
        $this->assertSame('UTC', $map->nodes['clock']->notes['time_timezone']);
    }

    public function testSnmpGetWithoutTheExtension(): void
    {
        if (class_exists('SNMP')) {
            $this->markTestSkipped('The snmp extension is loaded');
        }

        $this->assertFalse(cacti_snmp_get('192.0.2.1', 'public', '1.3.6.1.2.1.1.3.0', 2));
    }

    private function render(\WeatherMap $map): string
    {
        $file = TESTS_OUTPUT . '/legacy-' . uniqid() . '.png';
        $map->DrawMap($file);

        return (string) file_get_contents($file);
    }

    private function loadString(string $config): \WeatherMap
    {
        $file = TESTS_OUTPUT . '/' . md5($config) . '.conf';
        file_put_contents($file, $config);

        $map = new \WeatherMap();
        $map->context = 'cli';
        $map->ReadConfig($file);

        return $map;
    }
}
