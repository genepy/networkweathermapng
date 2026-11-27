<?php

declare(strict_types=1);

namespace Tests\App\Prometheus;

use App\Services\Prometheus\PrometheusClient;
use App\Services\Prometheus\PrometheusTarget;
use PHPUnit\Framework\TestCase;

/**
 * The Prometheus client against recorded answers of the HTTP API (the
 * IF-MIB metrics of snmp_exporter): no live server.
 */
final class PrometheusClientTest extends TestCase
{
    private const NOW = 1_800_000_000;

    /** @var list<array{path: string, params: array<string, string>, headers: list<string>}> */
    private array $requests = [];

    public function testHostsAreTheValuesOfTheHostLabel(): void
    {
        $client = $this->client([], token: 'secret');
        $hosts = $client->getHosts();

        $this->assertSame(['core-rtr1', 'edge-sw2'], array_column($hosts, 'hostid'));
        $this->assertSame('label/instance/values', $this->requests[0]['path']);
        $this->assertSame('ifHCInOctets{job="snmp"}', $this->requests[0]['params']['match[]']);
        $this->assertContains('Authorization: Bearer secret', $this->requests[0]['headers']);
        $this->assertSame(['edge-sw2'], array_column($client->getHosts(['search' => 'EDGE']), 'hostid'));
    }

    public function testBasicAuthWithoutToken(): void
    {
        $this->client([], user: 'wm', password: 'pw')->getHosts();
        $this->assertContains('Authorization: Basic ' . base64_encode('wm:pw'), $this->requests[0]['headers']);
    }

    public function testInterfacesForThePicker(): void
    {
        $options = $this->client()->getInterfaceBandwidthOptions('core-rtr1');

        $this->assertSame(['xe-0/0/1', 'xe-0/0/2'], array_column($options, 'interfaceId'));
        $this->assertSame('prometheus:4:host:core-rtr1:if:xe-0/0/1', $options[0]['target']);
        $this->assertSame('xe-0/0/1 (to paris)', $options[0]['label']);
        $this->assertSame(10_000_000_000, $options[0]['speed']);
        $this->assertSame('10G', $options[0]['bandwidth']);
        $this->assertSame(501, $options[0]['ifIndex']);
        $this->assertStringEndsWith('[down]', $options[1]['label']);
    }

    public function testCurrentRateSpeedAndState(): void
    {
        $client = $this->client();
        $up = ['hostId' => 'core-rtr1', 'interfaceId' => 'xe-0/0/1'];
        $down = ['hostId' => 'core-rtr1', 'interfaceId' => 'xe-0/0/2'];

        $this->assertSame(['in' => 1.5e9, 'out' => 2.5e8], $client->getBandwidthCurrent($up));
        $this->assertSame(10_000_000_000, $client->getInterfaceSpeed($up));
        $this->assertFalse($client->interfaceDown($up));
        $this->assertTrue($client->interfaceDown($down));
        $this->assertSame([], $client->getBandwidthCurrent(['hostId' => 'core-rtr1', 'interfaceId' => 'nope']));

        // the interfaces of a host are read once, for all of them
        $before = count($this->requests);
        $client->getBandwidthCurrent($down);
        $this->assertSame($before, count($this->requests));

        $queries = array_column(array_column($this->requests, 'params'), 'query');
        $this->assertContains('rate(ifHCInOctets{instance="core-rtr1",job="snmp"}[2m])*8', $queries);
    }

    public function testRawQueriesAreSummed(): void
    {
        $current = $this->client()->getBandwidthCurrent(['in' => 'sum_in', 'out' => 'scalar_out']);
        $this->assertSame(['in' => 30.0, 'out' => 7.0], $current);
        $this->assertSame([], $this->client()->getBandwidthCurrent(['in' => 'empty', 'out' => 'empty']));
    }

    public function testHistoryIsSummedPointByPoint(): void
    {
        $data = $this->client()->getBandwidthData(['hostId' => 'core-rtr1', 'interfaceId' => 'xe-0/0/1'], 60);

        $this->assertSame([['t' => 100, 'v' => 3.0], ['t' => 160, 'v' => 5.0]], $data['in']['series']);
        $this->assertSame('5', $data['in']['lastvalue']);
        $this->assertSame('bps', $data['out']['units']);
        $range = array_values(array_filter($this->requests, static fn (array $r) => $r['path'] === 'query_range'))[0]['params'];
        $this->assertSame((string) (self::NOW - 3600), $range['start']);
        $this->assertSame('60', $range['step']);
        $this->assertSame('rate(ifHCInOctets{instance="core-rtr1",ifName="xe-0/0/1",job="snmp"}[2m])*8', $range['query']);
    }

    public function testErrorsCarryTheServerMessage(): void
    {
        $result = $this->client()->testConnection();
        $this->assertTrue($result['success'], (string) ($result['error'] ?? ''));
        $this->assertStringContainsString('2 hosts', $result['message']);

        $this->expectExceptionMessage('Prometheus: parse error');
        $this->client()->getBandwidthCurrent(['in' => 'bad', 'out' => '']);
    }

    public function testLabelValuesAreEscaped(): void
    {
        $this->assertSame('{instance="a\\"b",job="snmp"}', $this->client()->matchers(['instance' => 'a"b']));
    }

    public function testTargets(): void
    {
        $this->assertSame(['source' => '4', 'host' => '10.0.0.1:161', 'interface' => 'Gi1/0/1'],
            PrometheusTarget::parse('prometheus:4:host:10.0.0.1:161:if:Gi1/0/1'));
        $this->assertSame(['source' => 'prom', 'in' => 'rate(a{x=~"p|q"}[5m])*8', 'out' => 'rate(b[5m])*8'],
            PrometheusTarget::parse('prometheus:prom:query:rate(a{x=~"p|q"}[5m])*8|rate(b[5m])*8'));
        $this->assertSame(['source' => '4', 'in' => 'sum(rate(a[5m])) * 8', 'out' => ''],
            PrometheusTarget::parse('prometheus:4:query', ['prometheus_in' => 'sum(rate(a[5m])) * 8']));
        $this->assertNull(PrometheusTarget::parse('prometheus:4:query'));
        $this->assertNull(PrometheusTarget::parse('observium:port:1'));
        $this->assertSame('prometheus:4:host:h:if:e0', PrometheusTarget::forInterface(4, 'h', 'e0'));
    }

    private function client(array $settings = [], string $token = '', string $user = '', string $password = ''): PrometheusClient
    {
        $source = [
            'id' => 4,
            'type' => 'prometheus',
            'url' => 'http://prom.example:9090/',
            'api_token' => $token,
            'username' => $user,
            'password' => $password,
            'settings' => json_encode($settings + ['filter' => 'job="snmp"', 'rate_window' => '2m']),
        ];
        return new PrometheusClient($source, null, $this->transport(...), self::NOW);
    }

    /** @return array{status: int, body: string} */
    private function transport(string $url, array $headers): array
    {
        $this->assertStringStartsWith('http://prom.example:9090/api/v1/', $url);
        $parts = parse_url($url);
        $path = substr((string) $parts['path'], strlen('/api/v1/'));
        $params = [];
        foreach (explode('&', (string) ($parts['query'] ?? '')) as $pair) {
            if ($pair !== '') {
                [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
                $params[rawurldecode($k)] = rawurldecode($v);
            }
        }
        $this->requests[] = ['path' => $path, 'params' => $params, 'headers' => $headers];

        $vector = static fn (array $rows): array => ['resultType' => 'vector', 'result' => array_map(
            static fn (array $r): array => ['metric' => $r[0], 'value' => [self::NOW, (string) $r[1]]], $rows)];
        $x1 = ['instance' => 'core-rtr1', 'ifName' => 'xe-0/0/1', 'ifIndex' => '501'];
        $x2 = ['instance' => 'core-rtr1', 'ifName' => 'xe-0/0/2', 'ifIndex' => '502'];

        $data = match (true) {
            $path === 'label/instance/values' => ['edge-sw2', 'core-rtr1', ''],
            $path === 'status/buildinfo' => ['version' => '3.1.0'],
            $path === 'series' => [$x1 + ['ifAlias' => 'to paris', 'ifDescr' => 'xe-0/0/1'], $x2],
            $path === 'query_range' => ['resultType' => 'matrix', 'result' => [
                ['metric' => [], 'values' => [[160, '2'], [100, '1']]],
                ['metric' => [], 'values' => [[100, '2'], [160, '3'], [220, 'NaN']]],
            ]],
            $path !== 'query' => null,
            $params['query'] === '1' => ['resultType' => 'scalar', 'result' => [self::NOW, '1']],
            $params['query'] === 'bad' => 'error',
            $params['query'] === 'sum_in' => $vector([[[], 10], [[], 20]]),
            $params['query'] === 'scalar_out' => ['resultType' => 'scalar', 'result' => [self::NOW, '7']],
            $params['query'] === 'empty' => $vector([]),
            str_starts_with($params['query'], 'rate(ifHCInOctets') => $vector([[$x1, 1.5e9], [$x2, 0]]),
            str_starts_with($params['query'], 'rate(ifHCOutOctets') => $vector([[$x1, 2.5e8], [$x2, 0]]),
            // a metric without the interface label is matched by ifIndex
            str_starts_with($params['query'], 'ifHighSpeed') => $vector([[['ifIndex' => '501'], 10000], [$x2, 1000]]),
            str_starts_with($params['query'], 'ifSpeed') => $vector([]),
            str_starts_with($params['query'], 'ifOperStatus') => $vector([[$x1, 1], [$x2, 2]]),
            default => null,
        };
        if ($data === 'error') {
            return ['status' => 400, 'body' => json_encode(['status' => 'error', 'errorType' => 'bad_data', 'error' => 'parse error'])];
        }
        if ($data === null) {
            return ['status' => 404, 'body' => '404 page not found'];
        }
        return ['status' => 200, 'body' => (string) json_encode(['status' => 'success', 'data' => $data])];
    }
}
