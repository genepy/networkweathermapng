<?php

declare(strict_types=1);

namespace Tests\App\Prometheus;

use App\Core\Config;
use App\Core\Database;
use App\Services\DataSourceService;
use App\Services\MapService;
use PHPUnit\Framework\TestCase;

/** A Prometheus TARGET names its source by id or by name (F84) */
final class PrometheusSelectionTest extends TestCase
{
    private DataSourceService $sources;
    private int $id;

    protected function setUp(): void
    {
        $data = TESTS_OUTPUT . '/prometheus';
        @unlink($data . '/test.db');
        @mkdir($data, 0775, true);

        $config = new Config();
        $config->set('APP_ROOT', dirname(TESTS_ROOT) . '/src');
        $config->set('DATA_ROOT', $data);
        $config->set('DB_TYPE', 'sqlite');
        $config->set('DB_PATH', $data . '/test.db');
        $this->sources = new DataSourceService(new Database($config));
        $this->id = $this->sources->createSource(['name' => 'prom', 'type' => 'prometheus', 'url' => 'http://prom:9090', 'password' => '']);
        $this->sources->createSource(['name' => 'zbx', 'type' => 'zabbix', 'url' => 'http://zbx', 'password' => '']);
    }

    public function testBySourceIdOrName(): void
    {
        $byId = $this->sources->prometheusSelection('prometheus:' . $this->id . ':host:core:if:xe-0/0/1');
        $this->assertSame(['sourceId' => $this->id, 'hostId' => 'core', 'interfaceId' => 'xe-0/0/1'], $byId);

        $byName = $this->sources->prometheusSelection('prometheus:prom:query', ['prometheus_in' => 'a', 'prometheus_out' => 'b']);
        $this->assertSame(['sourceId' => $this->id, 'in' => 'a', 'out' => 'b'], $byName);
    }

    public function testOtherSourcesAreRefused(): void
    {
        $this->assertNull($this->sources->prometheusSelection('prometheus:zbx:host:core:if:e0'));
        $this->assertNull($this->sources->prometheusSelection('prometheus:nope:host:core:if:e0'));
        $this->assertNull($this->sources->prometheusSelection('zabbix:1'));
    }

    public function testALinkWithAPrometheusTargetHasAHistory(): void
    {
        $link = (object) ['hints' => [], 'targets' => [['', '', '', 0, '8*prometheus:prom:host:core:if:e0']]];
        $this->assertSame('prometheus:prom:host:core:if:e0', MapService::prometheusTarget($link));
        $this->assertTrue(MapService::hasTrafficHistory($link));
    }
}
