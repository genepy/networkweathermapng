<?php

declare(strict_types=1);

namespace App\Services\Migration;

use App\Services\DataSourceService;

/**
 * The devices and interfaces of one configured data source.
 */
class DataSourceDirectory implements InterfaceDirectory
{
    public function __construct(private DataSourceService $service, private int $sourceId)
    {
    }

    public function hosts(string $search): array
    {
        $out = [];
        foreach ($this->service->getHosts($this->sourceId, ['search' => $search]) as $h) {
            $out[] = [
                'id' => (string) ($h['hostid'] ?? ''),
                'name' => (string) ($h['host'] ?? $h['name'] ?? ''),
                'alias' => (string) ($h['name'] ?? ''),
            ];
        }
        return $out;
    }

    public function interfaces(string $hostId): array
    {
        $out = [];
        foreach ($this->service->getInterfaceBandwidthOptions($this->sourceId, $hostId) as $o) {
            $selection = [
                'sourceId' => $this->sourceId,
                'hostId' => $hostId,
                'interfaceId' => (string) $o['interfaceId'],
                'inKey' => (string) ($o['rxKey'] ?? ''),
                'outKey' => (string) ($o['txKey'] ?? ''),
            ];
            $out[] = [
                'id' => (string) $o['interfaceId'],
                'label' => (string) $o['label'],
                'target' => (string) ($o['target'] ?? '') !== ''
                    ? (string) $o['target']
                    : 'zabbix:hostid:' . $hostId . ':key:' . $selection['inKey'] . ':' . $selection['outKey'],
                'bandwidth' => (string) ($o['bandwidth'] ?? ''),
                'ifIndex' => isset($o['ifIndex']) ? (int) $o['ifIndex'] : (ctype_digit((string) $o['interfaceId']) && empty($o['target']) ? (int) $o['interfaceId'] : null),
                'names' => array_values(array_filter(array_map('strval', $o['names'] ?? [preg_replace('/\s*\[down\]$/', '', (string) $o['label'])]))),
                'datasource' => $selection,
            ];
        }
        return $out;
    }
}
