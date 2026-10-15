<?php
/**
 * NetworkWeathermapNG - parser for Observium TARGET strings
 *
 *   observium:port:1234                      port by Observium port_id
 *   observium:host:core-rtr1:if:xe-0/0/1     port by device hostname and interface name
 *   observium:host:core-rtr1:status          device status, to colour a node
 *
 * The interface name may itself contain colons (e.g. "Gi1/0/1:2"), so
 * everything after ":if:" is the interface name.
 */

declare(strict_types=1);

namespace App\Services\Observium;

final class ObserviumTarget
{
    public const PORT = 'port';
    public const STATUS = 'status';

    private function __construct(
        public readonly string $kind,
        public readonly ?int $portId = null,
        public readonly ?string $host = null,
        public readonly ?string $interface = null,
    ) {
    }

    public static function parse(string $target): ?self
    {
        $target = trim($target);

        if (preg_match('/^observium:port:(\d+)$/i', $target, $m)) {
            return new self(self::PORT, portId: (int) $m[1]);
        }

        if (preg_match('/^observium:host:([^:]+):if:(.+)$/i', $target, $m)) {
            return new self(self::PORT, host: $m[1], interface: $m[2]);
        }

        if (preg_match('/^observium:host:([^:]+):status$/i', $target, $m)) {
            return new self(self::STATUS, host: $m[1]);
        }

        return null;
    }

    public static function forPort(int $portId): string
    {
        return 'observium:port:' . $portId;
    }
}
