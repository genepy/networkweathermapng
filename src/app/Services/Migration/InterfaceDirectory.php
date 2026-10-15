<?php

declare(strict_types=1);

namespace App\Services\Migration;

/**
 * Where the migration looks up devices and interfaces (Observium, Zabbix).
 */
interface InterfaceDirectory
{
    /** @return list<array{id: string, name: string}> devices whose name contains $search */
    public function hosts(string $search): array;

    /**
     * @return list<array{id: string, label: string, target: string, bandwidth: string, ifIndex: ?int, names: list<string>}>
     */
    public function interfaces(string $hostId): array;
}
