<?php

declare(strict_types=1);

namespace Tests\App;

use App\Services\TrafficTotalSource;
use PHPUnit\Framework\TestCase;

/** F79: what the total traffic of a map counts (SET traffic_total_source) */
final class TrafficTotalSourceTest extends TestCase
{
    public function testParseAndFormat(): void
    {
        $this->assertNull(TrafficTotalSource::parse(''));
        $this->assertNull(TrafficTotalSource::parse('links'));
        $this->assertSame(['kind' => 'observium', 'group' => 'IX peering'], TrafficTotalSource::parse('observium:group:IX peering'));
        $this->assertSame(['kind' => 'observium', 'group' => '12'], TrafficTotalSource::parse('OBSERVIUM:GROUP:12'));
        $this->assertSame(['kind' => 'observium', 'ports' => [1201, 1202, 1340]], TrafficTotalSource::parse('observium:ports:1201, 1202,1340,1202'));
        $this->assertSame('observium:ports:1201,1202', TrafficTotalSource::format(['kind' => 'observium', 'ports' => [1201, 1202]]));
        $this->assertSame('observium:group:Transit', TrafficTotalSource::format(['kind' => 'observium', 'group' => 'Transit']));
        $this->assertSame('', TrafficTotalSource::format(null));
    }

    public function testUnreadableValuesAreRefused(): void
    {
        foreach (['observium:ports:', 'observium:ports:abc', 'observium:group:', 'zabbix:group:1', 'sum'] as $value) {
            try {
                TrafficTotalSource::parse($value);
                $this->fail($value . ' was accepted');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testHintNamesOfGroupTabs(): void
    {
        $this->assertSame('traffic_total_source', TrafficTotalSource::hintName(''));
        $this->assertSame('traffic_group_source_ix_peering', TrafficTotalSource::hintName('IX  peering'));
        $this->assertSame('traffic_group_source_transit_1', TrafficTotalSource::hintName('Transit #1'));
    }

    public function testSourcesFromTheConfiguration(): void
    {
        $config = "WIDTH 800\nSET traffic_total_source observium:group:Interconnect\n"
            . "SET traffic_group_source_ix_peering observium:ports:5,6\nSET traffic_group_source_broken nonsense\n"
            . "LINK a-b\n\tSET traffic_group IX peering\n\tSET traffic_total_source observium:ports:9\n";
        $this->assertSame([
            '' => ['kind' => 'observium', 'group' => 'Interconnect'],
            'IX peering' => ['kind' => 'observium', 'ports' => [5, 6]],
        ], TrafficTotalSource::fromConfig($config));
        $this->assertSame([], TrafficTotalSource::fromConfig("WIDTH 800\nSET traffic_total_source links\n"));
    }
}
