<?php
/**
 * Reads an old Weathermap TARGET and says where its data came from, and
 * which device and interface it was about when that can be told.
 */

declare(strict_types=1);

namespace App\Services\Migration;

class TargetParser
{
    /** ifInOctets, ifOutOctets, ifHCInOctets, ifHCOutOctets: the last number is the ifIndex */
    private const IF_OIDS = [
        '1.3.6.1.2.1.2.2.1.10.', '1.3.6.1.2.1.2.2.1.16.',
        '1.3.6.1.2.1.31.1.1.1.6.', '1.3.6.1.2.1.31.1.1.1.10.',
    ];

    /**
     * @return array{kind: string, host: string, ifIndex: ?int, ifName: string, convert: bool, raw: string}
     *   kind: static, observium, zabbix, rrd, snmp, mrtg, cacti, external, other
     *   convert: whether the target should be replaced by a data source one
     */
    public static function parse(string $target): array
    {
        $raw = $target;
        // "-target" negates, "8*target" multiplies: the data still comes from target
        $target = preg_replace('/^-/', '', $target);
        $target = preg_replace('/^\d+(\.\d+)?\*/', '', (string) $target);

        $out = ['kind' => 'other', 'host' => '', 'ifIndex' => null, 'ifName' => '', 'convert' => true, 'raw' => $raw];

        if (preg_match('/^(static|observium|zabbix):/i', $target, $m)) {
            return array_merge($out, ['kind' => strtolower($m[1]), 'convert' => false]);
        }

        if (preg_match('/^snmp(?:2c|3)?:[^:]*:([^:]+):([\d.]+):([\d.]+)/i', $target, $m)) {
            return array_merge($out, ['kind' => 'snmp', 'host' => $m[1], 'ifIndex' => self::ifIndexFromOid($m[2])]);
        }

        if (preg_match('/^(cacti\w*|dsstats):/i', $target)) {
            return array_merge($out, ['kind' => 'cacti']);
        }

        if ($target !== '' && ($target[0] === '!' || stripos($target, 'external:') === 0)) {
            // a command run on the old server: never carried over
            return array_merge($out, ['kind' => 'external']);
        }

        if (preg_match('/^(?:mrtg:)?(.*?)([^\/]+)_(\d+)\.(?:html|log)$/i', $target, $m)) {
            return array_merge($out, ['kind' => 'mrtg', 'host' => $m[2], 'ifIndex' => (int) $m[3]]);
        }

        if (preg_match('/^(?:rrd:)?(.+\.rrd)(?::[\w\-]+:[\w\-]+)?$/i', $target, $m)) {
            return array_merge($out, ['kind' => 'rrd'], self::fromRrdPath($m[1]));
        }

        return $out;
    }

    private static function fromRrdPath(string $path): array
    {
        $parts = explode('/', str_replace('\\', '/', $path));
        $file = array_pop($parts);
        $dir = (string) end($parts);

        // Observium: rrd/<hostname>/port-<ifIndex>.rrd
        if (preg_match('/^port-(\d+)\.rrd$/i', $file, $m) && $dir !== '') {
            return ['host' => $dir, 'ifIndex' => (int) $m[1]];
        }

        // LibreNMS: rrd/<hostname>/port-id<port_id>.rrd (that id means nothing elsewhere)
        if (preg_match('/^port-id\d+\.rrd$/i', $file) && $dir !== '') {
            return ['host' => $dir];
        }

        // Cacti: <host>_traffic_in_<id>.rrd
        if (preg_match('/^(.+?)_(?:traffic|bits|ifhc)\w*_\d+\.rrd$/i', $file, $m)) {
            return ['host' => $m[1]];
        }

        return [];
    }

    private static function ifIndexFromOid(string $oid): ?int
    {
        $oid = ltrim($oid, '.');
        foreach (self::IF_OIDS as $prefix) {
            if (str_starts_with($oid, $prefix) && ctype_digit(substr($oid, strlen($prefix)))) {
                return (int) substr($oid, strlen($prefix));
            }
        }
        return null;
    }
}
