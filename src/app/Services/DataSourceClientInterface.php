<?php
/**
 * NetworkWeathermapNG - contract every data source client fulfils
 * (Zabbix, Observium, ...). The editor's host and interface pickers and the
 * map renderer only talk to this interface.
 */

declare(strict_types=1);

namespace App\Services;

interface DataSourceClientInterface
{
    public function testConnection(): array;
    public function getHosts(array $filters = []): array;
    public function getHostInterfaces(string $hostId): array;
    public function getItems(string $hostId, array $filters = []): array;
    public function getItemValue(string $itemId): ?array;
    public function getInterfaceBandwidthOptions(string $hostId): array;
    public function getBandwidthCurrent(array $selection): array;
    public function getBandwidthData(array $selection, int $minutes = 1440): array;
    public function getDebugInfo(): array;
}
