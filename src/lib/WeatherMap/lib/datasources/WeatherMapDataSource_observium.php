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
 */

class WeatherMapDataSource_observium extends WeatherMapDataSource
{
    private ?\App\Services\DataSourceService $svc = null;

    function Init(&$map)
    {
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
        if (!$this->svc) {
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

    function ReadData($targetstring, &$map, &$item)
    {
        $data_time = time();

        if (!$this->svc) {
            return array(NULL, NULL, $data_time);
        }

        try {
            $values = $this->svc->readObserviumTarget((string) $targetstring, $this->sourceIdFor($item));
        } catch (\Throwable $e) {
            wm_warn("Observium ReadData: {$targetstring}: " . $e->getMessage() . " [WMOBS01]");
            $values = null;
        }

        if ($values === null) {
            return array(NULL, NULL, $data_time);
        }

        wm_debug("Observium ReadData: Returning ({$values[0]}, {$values[1]}, $data_time)");

        return array($values[0], $values[1], $data_time);
    }
}
