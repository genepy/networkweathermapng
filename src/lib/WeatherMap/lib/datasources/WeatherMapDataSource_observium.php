<?php
/*
 * WeatherMap datasource plugin: Observium
 *
 * TARGET observium:port:1234
 * TARGET observium:host:core-rtr1:if:xe-0/0/1
 * TARGET observium:host:core-rtr1:status
 *
 * Reads through the Observium data source configured in Admin > Data Sources
 * (API, MySQL or RRD, see App\Services\Observium\ObserviumClient). When a
 * link was set up with the editor's picker, its "datasource" hint names the
 * source to use; otherwise the first active Observium source is used.
 *
 * With a URL in the source settings, each item also gets the note
 * monitoring_url: its port or device page in Observium.
 */

class WeatherMapDataSource_observium extends WeatherMapDataSource
{
    /** @var \App\Services\DataSourceService|object|null */
    private $svc = null;

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
            wm_warn('Observium datasource unavailable: ' . $e->getMessage());
        }

        return false;
    }

    function Recognise($targetstring)
    {
        return preg_match('/^observium:/i', (string) $targetstring) === 1;
    }

    /**
     * Interface speed in bit/s, for links with BANDWIDTH auto.
     */
    function ReadSpeed($targetstring, &$map, &$item)
    {
        if (!$this->svc || $map->source_down('observium:' . ($this->sourceIdFor($item) ?? 0))) {
            return 0;
        }

        try {
            return (int) $this->svc->observiumSpeedForTarget((string) $targetstring, $this->sourceIdFor($item));
        } catch (\Throwable $e) {
            wm_warn("Observium ReadSpeed: {$targetstring}: " . $e->getMessage() . " [WMOBS02]");
            return 0;
        }
    }

    private function sourceIdFor($item): ?int
    {
        if (isset($item->hints) && is_array($item->hints) && isset($item->hints['datasource'])) {
            $sel = json_decode((string) $item->hints['datasource'], true);
            if (is_array($sel) && !empty($sel['sourceId'])) {
                return (int) $sel['sourceId'];
            }
        }

        return null;
    }

    /**
     * The port (or device) page in Observium, as the note monitoring_url:
     * the viewer opens it on click when the item has no INFOURL, and an
     * INFOURL can use it as {link:this:monitoring_url}.
     */
    private function notePage(string $target, $item): void
    {
        try {
            $url = $this->svc->observiumPageForTarget($target, $this->sourceIdFor($item));
            if ($url !== null && method_exists($item, 'add_note')) {
                $item->add_note('monitoring_url', $url);
            }
        } catch (\Throwable $e) {
            wm_debug("Observium page: {$target}: " . $e->getMessage());
        }
    }

    /** The service the plugin reads through (a fake one in tests) */
    function useService($svc): void
    {
        $this->svc = $svc;
    }

    private function sourceName(?int $sourceId): string
    {
        if ($sourceId !== null) {
            try {
                $source = method_exists($this->svc, 'getSource') ? $this->svc->getSource($sourceId) : null;
                if (is_array($source) && (string) ($source['name'] ?? '') !== '') {
                    return (string) $source['name'];
                }
            } catch (\Throwable $e) {
                // the name only labels the warning
            }
        }
        return 'Observium';
    }

    /**
     * An error never stops the render: it is logged once per source,
     * recorded for the viewer's warning (F49) and the item gets no data;
     * after a connection failure the other items of the same source are
     * skipped for this render.
     */
    function ReadData($targetstring, &$map, &$item)
    {
        $data_time = time();

        if (!$this->svc) {
            return array(NULL, NULL, $data_time);
        }

        $sourceId = $this->sourceIdFor($item);
        $key = 'observium:' . ($sourceId ?? 0);
        if ($map->source_down($key)) {
            $map->source_failed($key, $this->sourceName($sourceId), '', true);
            return array(NULL, NULL, $data_time);
        }

        try {
            $values = $this->svc->readObserviumTarget((string) $targetstring, $sourceId);
        } catch (\Throwable $e) {
            $map->source_failed($key, $this->sourceName($sourceId), $e->getMessage(), self::isConnectionError($e));
            return array(NULL, NULL, $data_time);
        }

        $this->notePage((string) $targetstring, $item);

        if ($values === null) {
            return array(NULL, NULL, $data_time);
        }

        wm_debug("Observium ReadData: Returning ({$values[0]}, {$values[1]}, $data_time)");

        return array($values[0], $values[1], $data_time);
    }
}
