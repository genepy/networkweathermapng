<?php

declare(strict_types=1);

namespace Tests\App\Observium;

use App\Services\Observium\ApiBackend;
use PHPUnit\Framework\TestCase;

final class ApiBackendTest extends TestCase
{
    private array $requests = [];

    public function testDevicesKeyedByIdAndBearerToken(): void
    {
        $api = $this->api(['devices/' => ['status' => 'ok', 'count' => 2, 'devices' => [
            '2' => ['device_id' => '2', 'hostname' => 'paris-sw1', 'sysName' => '', 'status' => '0'],
            '1' => ['device_id' => '1', 'hostname' => 'core-rtr1', 'sysName' => '', 'status' => '1'],
        ]]], token: 'secret');

        $this->assertSame(['core-rtr1', 'paris-sw1'], array_column($api->devices(), 'hostname'));
        $this->assertSame('https://obs.example/api/v0/devices/', $this->requests[0]['url']);
        $this->assertContains('Authorization: Bearer secret', $this->requests[0]['headers']);
    }

    public function testBasicAuthWithoutToken(): void
    {
        $api = $this->api(['devices/' => ['status' => 'ok', 'devices' => []]], user: 'wm', password: 'pw');
        $api->devices();

        $this->assertContains('Authorization: Basic ' . base64_encode('wm:pw'), $this->requests[0]['headers']);
    }

    public function testPortsAreFilteredToTheDevice(): void
    {
        $api = $this->api([
            'devices/' => ['status' => 'ok', 'devices' => [['device_id' => 1, 'hostname' => 'core-rtr1', 'status' => 1]]],
            'ports/?device_id=1' => ['status' => 'ok', 'ports' => [
                ['port_id' => 10, 'device_id' => 1, 'ifName' => 'xe-0/0/1', 'ifHighSpeed' => 100000, 'ifInOctets_rate' => 125, 'ifOutOctets_rate' => 250, 'ifOperStatus' => 'up'],
                ['port_id' => 99, 'device_id' => 7, 'ifName' => 'other'],
            ]],
        ]);

        $ports = $api->ports(1);
        $this->assertSame([10], array_column($ports, 'port_id'));
        $this->assertSame(100000000000, $ports[0]['speed']);
        $this->assertSame(1000.0, $ports[0]['in_rate']);
        $this->assertSame('core-rtr1', $ports[0]['hostname']);
        $this->assertSame(10, $api->portByName('core-rtr1', 'XE-0/0/1')['port_id']);
    }

    public function testSinglePort(): void
    {
        $api = $this->api([
            'devices/' => ['status' => 'ok', 'devices' => [['device_id' => 1, 'hostname' => 'core-rtr1', 'status' => 1]]],
            'ports/10/' => ['status' => 'ok', 'port' => ['port_id' => 10, 'device_id' => 1, 'ifName' => 'xe-0/0/1']],
        ]);

        $this->assertSame('core-rtr1', $api->port(10)['hostname']);
    }

    public function testRefusedCredentialsAreReported(): void
    {
        $api = new ApiBackend('https://obs.example', 'bad', transport: fn() => ['status' => 401, 'body' => '']);

        $this->expectExceptionMessage('refused the credentials');
        $api->test();
    }

    public function testApiErrorStatusIsReported(): void
    {
        $api = $this->api(['devices/' => ['status' => 'failed', 'message' => 'Not licensed']]);

        $this->expectExceptionMessage('Not licensed');
        $api->devices();
    }

    private function api(array $responses, string $token = '', string $user = '', string $password = ''): ApiBackend
    {
        return new ApiBackend('https://obs.example/', $token, $user, $password, true, function (string $url, array $headers) use ($responses): array {
            $this->requests[] = ['url' => $url, 'headers' => $headers];
            $path = substr($url, strlen('https://obs.example/api/v0/'));
            if (!array_key_exists($path, $responses)) {
                return ['status' => 404, 'body' => ''];
            }

            return ['status' => 200, 'body' => json_encode($responses[$path])];
        });
    }
}
