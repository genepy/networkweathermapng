<?php
/**
 * NetworkWeathermapNG - reads the database settings and RRD folder from an
 * Observium installation's config.php, so a Weathermap installed on the
 * Observium server needs no extra configuration.
 *
 * The file is parsed, never executed.
 */

declare(strict_types=1);

namespace App\Services\Observium;

final class ObserviumConfigReader
{
    public const DEFAULT_PATH = '/opt/observium';

    /**
     * @return array{db: array{host: string, port: int, name: string, user: string, password: string, socket: string}, rrd_dir: string}|null
     */
    public static function read(string $installPath): ?array
    {
        $file = rtrim($installPath, '/') . '/config.php';
        if (!is_readable($file)) {
            return null;
        }

        return self::parse((string) file_get_contents($file), $installPath);
    }

    public static function parse(string $php, string $installPath = self::DEFAULT_PATH): array
    {
        $values = [];
        if (preg_match_all('/\$config\[\s*[\'"]([a-z_]+)[\'"]\s*\]\s*=\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"|(\d+))\s*;/i', $php, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $value = $m[2] !== '' ? $m[2] : (($m[3] ?? '') !== '' ? $m[3] : ($m[4] ?? ''));
                $values[$m[1]] = stripslashes($value);
            }
        }

        $host = $values['db_host'] ?? 'localhost';
        $port = (int) ($values['db_port'] ?? 3306);
        // "host:port" is accepted by Observium
        if (preg_match('/^(.+):(\d+)$/', $host, $m)) {
            [$host, $port] = [$m[1], (int) $m[2]];
        }

        return [
            'db' => [
                'host' => $host,
                'port' => $port,
                'name' => $values['db_name'] ?? 'observium',
                'user' => $values['db_user'] ?? '',
                'password' => $values['db_pass'] ?? '',
                'socket' => $values['db_socket'] ?? '',
            ],
            'rrd_dir' => $values['rrd_dir'] ?? rtrim($installPath, '/') . '/rrd',
        ];
    }
}
