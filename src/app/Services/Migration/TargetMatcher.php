<?php
/**
 * Finds, for an old target, the interface that now carries the same data.
 */

declare(strict_types=1);

namespace App\Services\Migration;

class TargetMatcher
{
    private array $hostCache = [];
    private array $ifCache = [];

    public function __construct(private InterfaceDirectory $directory)
    {
    }

    /**
     * @return array{status: string, host: ?array, choice: ?array, candidates: list<array>, note: string}
     *   status: matched (one answer), ambiguous (pick from candidates), unmatched
     */
    public function match(array $parsed): array
    {
        $result = ['status' => 'unmatched', 'host' => null, 'choice' => null, 'candidates' => [], 'note' => ''];

        if ($parsed['host'] === '') {
            $result['note'] = 'The old target does not name a device.';
            return $result;
        }

        $hosts = $this->findHost($parsed['host']);
        if (!$hosts) {
            $result['note'] = 'No device called ' . $parsed['host'] . '.';
            return $result;
        }
        if (count($hosts) > 1) {
            $result['status'] = 'ambiguous';
            $result['note'] = count($hosts) . ' devices could be ' . $parsed['host'] . '.';
            foreach ($hosts as $h) {
                foreach ($this->interfacesOf($h['id']) as $i) {
                    if ($this->sameInterface($i, $parsed)) {
                        $result['candidates'][] = $i + ['host' => $h['name']];
                    }
                }
            }
            return $result;
        }

        $host = $hosts[0];
        $result['host'] = $host;
        $interfaces = $this->interfacesOf($host['id']);

        if ($parsed['ifIndex'] === null && $parsed['ifName'] === '') {
            $result['status'] = 'ambiguous';
            $result['note'] = 'The old target does not say which interface of ' . $host['name'] . '.';
            $result['candidates'] = array_map(fn ($i) => $i + ['host' => $host['name']], $interfaces);
            return $result;
        }

        $found = array_values(array_filter($interfaces, fn ($i) => $this->sameInterface($i, $parsed)));
        if (count($found) === 1) {
            $result['status'] = 'matched';
            $result['choice'] = $found[0] + ['host' => $host['name']];
            return $result;
        }

        $result['status'] = $found ? 'ambiguous' : 'unmatched';
        $result['note'] = $found
            ? 'Several interfaces of ' . $host['name'] . ' fit.'
            : 'No interface ' . ($parsed['ifName'] ?: '#' . $parsed['ifIndex']) . ' on ' . $host['name'] . '.';
        $result['candidates'] = array_map(fn ($i) => $i + ['host' => $host['name']], $found ?: $interfaces);

        return $result;
    }

    /** Device names as Cacti, MRTG and SNMP targets write them */
    public static function normalise(string $name): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', $name));
    }

    private function findHost(string $name): array
    {
        $key = strtolower($name);
        if (isset($this->hostCache[$key])) {
            return $this->hostCache[$key];
        }

        $short = explode('.', $name)[0];
        $candidates = $this->directory->hosts($short);
        // Cacti file names turn dots into underscores
        if (!$candidates && str_contains($short, '_')) {
            $candidates = $this->directory->hosts(explode('_', $short)[0]);
        }

        $want = self::normalise($name);
        $wantShort = self::normalise($short);
        $exact = [];
        $loose = [];
        foreach ($candidates as $h) {
            foreach ([$h['name'], $h['alias'] ?? ''] as $n) {
                if ($n === '') {
                    continue;
                }
                if (strcasecmp($n, $name) === 0) {
                    $exact[$h['id']] = $h;
                } elseif (self::normalise($n) === $want || self::normalise(explode('.', $n)[0]) === $wantShort) {
                    $loose[$h['id']] = $h;
                }
            }
        }

        return $this->hostCache[$key] = array_values($exact ?: $loose);
    }

    private function interfacesOf(string $hostId): array
    {
        return $this->ifCache[$hostId] ??= $this->directory->interfaces($hostId);
    }

    private function sameInterface(array $interface, array $parsed): bool
    {
        if ($parsed['ifIndex'] !== null && $interface['ifIndex'] !== null) {
            return $interface['ifIndex'] === $parsed['ifIndex'];
        }
        if ($parsed['ifName'] !== '') {
            foreach ($interface['names'] as $n) {
                if (strcasecmp($n, $parsed['ifName']) === 0) {
                    return true;
                }
            }
        }
        return false;
    }
}
