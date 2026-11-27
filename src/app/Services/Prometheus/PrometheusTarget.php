<?php
/**
 * NetworkWeathermapNG - TARGET strings of the Prometheus data source (F84).
 *
 *   prometheus:<source>:host:<host>:if:<interface>   an interface of a host
 *   prometheus:<source>:query:<in>|<out>             two PromQL expressions
 *   prometheus:<source>:query                        the expressions of the
 *                                                    item's SET prometheus_in
 *                                                    and SET prometheus_out
 *
 * <source> is the id or the name of the data source. <host> is the value
 * of the source's host label (instance by default), <interface> the value
 * of its interface label (ifName by default). In the inline form the "|"
 * between the two expressions is the first one outside quotes, so regex
 * matchers such as ifName=~"a|b" keep theirs. A query with spaces is
 * easier to write in the SET hints.
 */

declare(strict_types=1);

namespace App\Services\Prometheus;

final class PrometheusTarget
{
    public const IN_HINT = 'prometheus_in';
    public const OUT_HINT = 'prometheus_out';

    /**
     * The parts of a target: ['source' => string, 'host' => string,
     * 'interface' => string] or ['source' => string, 'in' => string,
     * 'out' => string]. $hints are the item's SET hints, for the bare
     * query form. Null when the string is not a Prometheus target, or
     * names no query.
     *
     * @param array<string, mixed> $hints
     * @return array{source: string, host?: string, interface?: string, in?: string, out?: string}|null
     */
    public static function parse(string $target, array $hints = []): ?array
    {
        $target = trim($target);
        if (!preg_match('/^prometheus:([^:]+):(.*)$/is', $target, $m)) {
            return null;
        }
        $source = trim($m[1]);
        $rest = $m[2];
        if (preg_match('/^host:(.+):if:(.+)$/s', $rest, $p)) {
            return ['source' => $source, 'host' => $p[1], 'interface' => $p[2]];
        }
        if (preg_match('/^query(?::(.*))?$/is', $rest, $p)) {
            if (isset($p[1]) && trim($p[1]) !== '') {
                [$in, $out] = self::splitQueries($p[1]);
            } else {
                $in = trim((string) ($hints[self::IN_HINT] ?? ''));
                $out = trim((string) ($hints[self::OUT_HINT] ?? ''));
            }
            if ($in === '' && $out === '') {
                return null;
            }
            return ['source' => $source, 'in' => $in, 'out' => $out];
        }
        return null;
    }

    /** The target the editor's picker writes for an interface */
    public static function forInterface(int|string $source, string $host, string $interface): string
    {
        return 'prometheus:' . $source . ':host:' . $host . ':if:' . $interface;
    }

    /**
     * Splits "<in>|<out>" at the first "|" outside quotes; one expression
     * without "|" is the IN one (OUT stays empty).
     *
     * @return array{0: string, 1: string}
     */
    public static function splitQueries(string $text): array
    {
        $quote = null;
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $c = $text[$i];
            if ($quote !== null) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === $quote) {
                    $quote = null;
                }
            } elseif ($c === '"' || $c === "'" || $c === '`') {
                $quote = $c;
            } elseif ($c === '|') {
                return [trim(substr($text, 0, $i)), trim(substr($text, $i + 1))];
            }
        }
        return [trim($text), ''];
    }
}
