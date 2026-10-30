<?php

namespace App\Services;

use App\Core\Database;

/**
 * Kiosk mode (map cycle) settings, kept in the settings table:
 * the display time shared by all maps (map_id 0), and for each map
 * its zoom and an optional display time of its own.
 */
class KioskService
{
    public const DEFAULT_DURATION = 30;
    public const MIN_DURATION = 5;
    public const MAX_DURATION = 86400;
    /** Zoom 0 fits the map to the screen; otherwise a percentage of its size. */
    public const ZOOM_FIT = 0;
    public const MIN_ZOOM = 50;
    public const MAX_ZOOM = 300;
    /** Zoom choices offered in the admin page, from MIN_ZOOM to MAX_ZOOM. */
    public const ZOOM_STEPS = [50, 75, 100, 125, 150, 175, 200, 250, 300];

    private const DURATION = 'kiosk_duration';
    private const ZOOM = 'kiosk_zoom';

    private SettingsStore $store;

    public function __construct(Database $db)
    {
        $this->store = new SettingsStore($db);
    }

    /** Display time, in seconds, of the maps without one of their own. */
    public function getDefaultDuration(): int
    {
        $value = $this->store->get(0, self::DURATION);
        return $value === null ? self::DEFAULT_DURATION : self::clampDuration((int) $value);
    }

    public function setDefaultDuration(int $seconds): void
    {
        $this->store->set(0, self::DURATION, (string) self::clampDuration($seconds));
    }

    /**
     * Settings of each map, by map id: zoom (0 = fit) and duration
     * (null = the default one).
     *
     * @param int[] $mapIds
     * @return array<int, array{zoom: int, duration: ?int}>
     */
    public function getMapSettings(array $mapIds): array
    {
        $settings = [];
        foreach ($mapIds as $id) {
            $settings[(int) $id] = ['zoom' => self::ZOOM_FIT, 'duration' => null];
        }
        if (!$settings) {
            return [];
        }

        foreach ($this->store->getMany([self::DURATION, self::ZOOM], array_keys($settings)) as $id => $values) {
            if (isset($values[self::ZOOM])) {
                $settings[$id]['zoom'] = self::clampZoom((int) $values[self::ZOOM]);
            }
            if (($values[self::DURATION] ?? '') !== '') {
                $settings[$id]['duration'] = self::clampDuration((int) $values[self::DURATION]);
            }
        }
        return $settings;
    }

    /**
     * @param int $zoom 0 to fit the map to the screen, else a percentage
     * @param ?int $duration seconds, null to use the default display time
     */
    public function setMapSettings(int $mapId, int $zoom, ?int $duration): void
    {
        if ($mapId <= 0) {
            throw new \InvalidArgumentException('Invalid map id');
        }
        $zoom = self::clampZoom($zoom);
        if ($zoom === self::ZOOM_FIT) {
            $this->store->remove($mapId, self::ZOOM);
        } else {
            $this->store->set($mapId, self::ZOOM, (string) $zoom);
        }
        if ($duration === null) {
            $this->store->remove($mapId, self::DURATION);
        } else {
            $this->store->set($mapId, self::DURATION, (string) self::clampDuration($duration));
        }
    }

    /**
     * What the kiosk page needs for each map: its zoom and its display
     * time, the default one when the map has none.
     *
     * @param array<int, array<string, mixed>> $maps rows with an id
     * @return array<int, array{zoom: int, duration: int}>
     */
    public function resolve(array $maps): array
    {
        $default = $this->getDefaultDuration();
        $settings = $this->getMapSettings(array_map(static fn ($m) => (int) $m['id'], $maps));
        $resolved = [];
        foreach ($settings as $id => $s) {
            $resolved[$id] = ['zoom' => $s['zoom'], 'duration' => $s['duration'] ?? $default];
        }
        return $resolved;
    }

    public static function clampDuration(int $seconds): int
    {
        return max(self::MIN_DURATION, min(self::MAX_DURATION, $seconds));
    }

    /** Zoom within bounds; anything below the minimum means fit. */
    public static function clampZoom(int $zoom): int
    {
        return $zoom < self::MIN_ZOOM ? self::ZOOM_FIT : min(self::MAX_ZOOM, $zoom);
    }
}
