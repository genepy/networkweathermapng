<?php
/*
 * WeatherMap datasource plugin: Zabbix (DB-backed)
 *
 * Recognises TARGET strings beginning with "zabbix:".
 * Data is fetched using the application's DataSourceService (data_sources table).
 *
 * This is a compatibility wrapper for the classic WeatherMap plugin loader
 * (expects class WeatherMapDataSource_* with methods Recognise/ReadData).
 */

class WeatherMapDataSource_zabbix extends WeatherMapDataSource
{
    private ?\App\Core\Database $db = null;
    /** @var \App\Services\DataSourceService|object|null */
    private $svc = null;

    /** Items registered for this render, by source id: [item key => selection] */
    private array $wanted = [];

    /** Values read by Prefetch(), by source id and item key */
    private array $prefetched = [];

    /** Sources Prefetch() could not reach: the error, by source id */
    private array $unreachable = [];

    /** Source names, by id */
    private array $names = [];

    function Init(&$map)
    {
        if ($this->svc) {
            // given by useService()
            return true;
        }

        // Prefer application DB-backed service.
        // We avoid ENV-based Zabbix config entirely.
        try {
            if (defined('APP_ROOT')) {
                $autoload = APP_ROOT . '/vendor/autoload.php';
                if (file_exists($autoload)) {
                    require_once $autoload;
                }
            }

            if (class_exists('App\\Core\\Config') && class_exists('App\\Core\\Database') && class_exists('App\\Services\\DataSourceService')) {
                $cfg = new \App\Core\Config();
                $this->db = new \App\Core\Database($cfg);
                $this->svc = new \App\Services\DataSourceService($this->db);
                return true;
            }
        } catch (\Throwable $e) {
            // fall through
        }

        return false;
    }

    function Recognise($targetstring)
    {
        return (preg_match('/^zabbix:/i', (string) $targetstring) === 1);
    }

    /** The service the plugin reads through (a fake one in tests) */
    function useService($svc): void
    {
        $this->svc = $svc;
    }

    /** Collect the items of each source, so Prefetch() reads them in one call per source */
    function Register($targetstring, &$map, &$item)
    {
        $sel = $this->selectionFor($item);
        if ($sel !== null) {
            $this->wanted[(int) $sel['sourceId']][self::itemKey($item)] = $sel;
        }
    }

    /**
     * One API call per source for every registered item (F50). A source
     * that fails here is read item by item in ReadData(), which records
     * the error (or skips the source when it cannot be reached).
     */
    function Prefetch()
    {
        if (!$this->svc || !method_exists($this->svc, 'getLinksBandwidthCurrent')) {
            return;
        }
        foreach ($this->wanted as $sourceId => $selections) {
            try {
                $this->prefetched[$sourceId] = $this->svc->getLinksBandwidthCurrent((int) $sourceId, $selections);
            } catch (\Throwable $e) {
                if (self::isConnectionError($e)) {
                    $this->unreachable[$sourceId] = $e->getMessage();
                }
                wm_debug("Zabbix Prefetch: source {$sourceId}: " . $e->getMessage());
            }
        }
    }

    private static function itemKey($item): string
    {
        return (method_exists($item, 'my_type') ? $item->my_type() : '') . ':' . (string) ($item->name ?? '');
    }

    private function sourceName(int $sourceId): string
    {
        if (!isset($this->names[$sourceId])) {
            $name = '';
            try {
                $source = $this->svc && method_exists($this->svc, 'getSource') ? $this->svc->getSource($sourceId) : null;
                $name = is_array($source) ? (string) ($source['name'] ?? '') : '';
            } catch (\Throwable $e) {
                // the name only labels the warning
            }
            $this->names[$sourceId] = $name !== '' ? $name : 'Zabbix';
        }
        return $this->names[$sourceId];
    }

    /**
     * Interface speed in bit/s, for links with BANDWIDTH auto.
     */
    function ReadSpeed($targetstring, &$map, &$item)
    {
        $sel = $this->selectionFor($item);
        if (!$this->svc || !$sel || isset($this->unreachable[(int) $sel['sourceId']]) || $map->source_down('zabbix:' . (int) $sel['sourceId'])) {
            return 0;
        }

        try {
            return (int) $this->svc->getLinkSpeed((int) $sel['sourceId'], $sel);
        } catch (\Throwable $e) {
            wm_warn("Zabbix ReadSpeed: {$targetstring}: " . $e->getMessage() . " [WMZBX02]");
            return 0;
        }
    }

    private function selectionFor($item): ?array
    {
        if (isset($item->hints) && is_array($item->hints) && isset($item->hints['datasource'])) {
            $sel = json_decode((string) $item->hints['datasource'], true);
            if (is_array($sel) && !empty($sel['sourceId'])) {
                return $sel;
            }
        }

        return null;
    }

    /**
     * The host's latest data in Zabbix, as the note monitoring_url: the
     * viewer opens it on click when the link has no INFOURL.
     */
    private function notePage(int $sourceId, array $sel, $item): void
    {
        try {
            $url = $this->svc->linkPageUrl($sourceId, $sel);
            if ($url !== null && method_exists($item, 'add_note')) {
                $item->add_note('monitoring_url', $url);
            }
        } catch (\Throwable $e) {
            wm_debug("Zabbix page: " . $e->getMessage());
        }
    }

    /**
     * The current [in, out] of the item: from Prefetch() when it read it,
     * else asked on its own. An error never stops the render: it is logged
     * once per source, recorded for the viewer's warning (F49), and the
     * link gets no data; after a connection failure the other items of
     * the same source are skipped for this render.
     */
    function ReadData($targetstring, &$map, &$item)
    {
        $data_time = time();
        $sel = $this->selectionFor($item);

        if (!$this->svc || $sel === null) {
            // Fallback: return no data (so map can still render).
            return array(NULL, NULL, $data_time);
        }

        $sourceId = (int) $sel['sourceId'];
        $key = 'zabbix:' . $sourceId;
        if (isset($this->unreachable[$sourceId]) || $map->source_down($key)) {
            $map->source_failed($key, $this->sourceName($sourceId), (string) ($this->unreachable[$sourceId] ?? ''), true);
            return array(NULL, NULL, $data_time);
        }

        $this->notePage($sourceId, $sel, $item);

        $itemKey = self::itemKey($item);
        if (isset($this->prefetched[$sourceId])) {
            $current = $this->prefetched[$sourceId][$itemKey] ?? [];
        } else {
            try {
                $current = $this->svc->getLinkBandwidthCurrent($sourceId, $sel);
            } catch (\Throwable $e) {
                $map->source_failed($key, $this->sourceName($sourceId), $e->getMessage(), self::isConnectionError($e));
                return array(NULL, NULL, $data_time);
            }
        }

        if (isset($current['in']) && isset($current['out'])) {
            return array((float) $current['in'], (float) $current['out'], $data_time);
        }

        return array(NULL, NULL, $data_time);
    }
}
