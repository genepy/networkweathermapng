<?php
/*
 * WeatherMap datasource plugin: Prometheus (F84)
 *
 * TARGET prometheus:<source>:host:<host>:if:<interface>
 * TARGET prometheus:<source>:query:<in PromQL>|<out PromQL>
 * TARGET prometheus:<source>:query        (with SET prometheus_in / prometheus_out)
 *
 * <source> is the id or the name of a Prometheus data source of Admin >
 * Data sources. Reads through App\Services\Prometheus\PrometheusClient
 * (see App\Services\Prometheus\PrometheusTarget for the forms).
 */

class WeatherMapDataSource_prometheus extends WeatherMapDataSource
{
    /** @var \App\Services\DataSourceService|object|null */
    private $svc = null;

    /** Source names, by id */
    private array $names = [];

    function Init(&$map)
    {
        if ($this->svc) {
            // given by useService()
            return true;
        }

        try {
            if (defined('APP_ROOT')) {
                $autoload = APP_ROOT . '/vendor/autoload.php';
                if (file_exists($autoload)) {
                    require_once $autoload;
                }
            }

            if (class_exists('App\\Core\\Config') && class_exists('App\\Core\\Database') && class_exists('App\\Services\\DataSourceService')) {
                $cfg = new \App\Core\Config();
                $this->svc = new \App\Services\DataSourceService(new \App\Core\Database($cfg));
                return true;
            }
        } catch (\Throwable $e) {
            wm_warn('Prometheus datasource unavailable: ' . $e->getMessage());
        }

        return false;
    }

    function Recognise($targetstring)
    {
        return preg_match('/^prometheus:/i', (string) $targetstring) === 1;
    }

    /** The service the plugin reads through (a fake one in tests) */
    function useService($svc): void
    {
        $this->svc = $svc;
    }

    private function selectionFor($targetstring, $item): ?array
    {
        $hints = isset($item->hints) && is_array($item->hints) ? $item->hints : array();
        try {
            return $this->svc->prometheusSelection((string) $targetstring, $hints);
        } catch (\Throwable $e) {
            wm_debug("Prometheus: {$targetstring}: " . $e->getMessage());
            return null;
        }
    }

    private function sourceName(int $sourceId): string
    {
        if (!isset($this->names[$sourceId])) {
            $name = '';
            try {
                $source = method_exists($this->svc, 'getSource') ? $this->svc->getSource($sourceId) : null;
                $name = is_array($source) ? (string) ($source['name'] ?? '') : '';
            } catch (\Throwable $e) {
                // the name only labels the warning
            }
            $this->names[$sourceId] = $name !== '' ? $name : 'Prometheus';
        }
        return $this->names[$sourceId];
    }

    /**
     * Interface speed in bit/s, for links with BANDWIDTH auto.
     */
    function ReadSpeed($targetstring, &$map, &$item)
    {
        $sel = $this->svc ? $this->selectionFor($targetstring, $item) : null;
        if (!$sel || !isset($sel['hostId']) || $map->source_down('prometheus:' . (int) $sel['sourceId'])) {
            return 0;
        }

        try {
            return (int) $this->svc->getLinkSpeed((int) $sel['sourceId'], $sel);
        } catch (\Throwable $e) {
            wm_warn("Prometheus ReadSpeed: {$targetstring}: " . $e->getMessage() . " [WMPROM02]");
            return 0;
        }
    }

    /**
     * An error never stops the render: it is recorded for the viewer's
     * warning (F49) and the item gets no data; after a connection failure
     * the other items of the same source are skipped for this render.
     */
    function ReadData($targetstring, &$map, &$item)
    {
        $data_time = time();
        if (!$this->svc) {
            return array(NULL, NULL, $data_time);
        }

        $sel = $this->selectionFor($targetstring, $item);
        if ($sel === null) {
            wm_warn("Prometheus: {$targetstring} names no active Prometheus data source, or no query [WMPROM01]");
            return array(NULL, NULL, $data_time);
        }

        $sourceId = (int) $sel['sourceId'];
        $key = 'prometheus:' . $sourceId;
        if ($map->source_down($key)) {
            $map->source_failed($key, $this->sourceName($sourceId), '', true);
            return array(NULL, NULL, $data_time);
        }

        try {
            $current = $this->svc->getLinkBandwidthCurrent($sourceId, $sel);
            $down = method_exists($this->svc, 'linkDown') && $this->svc->linkDown($sourceId, $sel);
        } catch (\Throwable $e) {
            $map->source_failed($key, $this->sourceName($sourceId), $e->getMessage(), self::isConnectionError($e));
            return array(NULL, NULL, $data_time);
        }

        // an interface that reports itself down gives no data, whatever its
        // counters say: its link shows as down (F72)
        if ($down) {
            if (isset($item->data_reason)) {
                $item->data_reason = 'interface-down';
            }
            return array(NULL, NULL, $data_time);
        }

        if (isset($current['in']) && isset($current['out'])) {
            return array((float) $current['in'], (float) $current['out'], $data_time);
        }

        return array(NULL, NULL, $data_time);
    }
}
