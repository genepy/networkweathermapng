<?php

namespace App\Services;

use App\Core\Config;
use App\Core\Database;

/**
 * Time zone of the dates shown on maps: a default shared by all maps
 * (map_id 0 of the settings table, else APP_TIMEZONE), and an optional
 * override for each map.
 *
 * Dates kept in the database (last_run...) are written with date() in
 * APP_TIMEZONE, the application's own zone, and converted only for display.
 */
class TimezoneService
{
    private const NAME = 'timezone';

    private SettingsStore $store;
    private string $appZone;

    /** @param string $appZone APP_TIMEZONE: the zone stored dates are written in */
    public function __construct(Database $db, string $appZone)
    {
        $this->store = new SettingsStore($db);
        $this->appZone = self::isValid($appZone) ? $appZone : 'UTC';
    }

    public static function fromConfig(Database $db, Config $config): self
    {
        return new self($db, (string) $config->get('APP_TIMEZONE', 'UTC'));
    }

    /**
     * Zones offered in the admin page.
     *
     * @return string[]
     */
    public static function identifiers(): array
    {
        return \DateTimeZone::listIdentifiers();
    }

    /** Whether PHP knows the zone, older names included. */
    public static function isValid(string $zone): bool
    {
        return in_array($zone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true);
    }

    /** The application's zone, used when no default is set. */
    public function getAppZone(): string
    {
        return $this->appZone;
    }

    /** Zone of the maps without one of their own. */
    public function getDefault(): string
    {
        $zone = $this->store->get(0, self::NAME);
        return $zone !== null && self::isValid($zone) ? $zone : $this->appZone;
    }

    /** @param ?string $zone null or empty to fall back on APP_TIMEZONE */
    public function setDefault(?string $zone): void
    {
        $this->save(0, $zone);
    }

    /**
     * Zone of each map that has one of its own, null for the others.
     *
     * @param int[] $mapIds
     * @return array<int, ?string>
     */
    public function getOverrides(array $mapIds): array
    {
        $overrides = array_fill_keys(array_map('intval', $mapIds), null);
        foreach ($this->store->getMany([self::NAME], array_keys($overrides)) as $id => $values) {
            $zone = $values[self::NAME] ?? '';
            if (array_key_exists($id, $overrides) && self::isValid($zone)) {
                $overrides[$id] = $zone;
            }
        }
        return $overrides;
    }

    /** @param ?string $zone null or empty to use the default zone */
    public function setOverride(int $mapId, ?string $zone): void
    {
        if ($mapId <= 0) {
            throw new \InvalidArgumentException('Invalid map id');
        }
        $this->save($mapId, $zone);
    }

    /** Zone the dates of a map are shown in. */
    public function forMap(int $mapId): string
    {
        return $this->forMaps([$mapId])[$mapId];
    }

    /**
     * @param int[] $mapIds
     * @return array<int, string>
     */
    public function forMaps(array $mapIds): array
    {
        $default = $this->getDefault();
        return array_map(static fn (?string $zone) => $zone ?? $default, $this->getOverrides($mapIds));
    }

    /** A date stored in the application's zone (Y-m-d H:i:s), shown in $zone. */
    public function formatStored(string $stored, string $zone, string $format = 'Y-m-d H:i:s'): string
    {
        try {
            $date = new \DateTimeImmutable($stored, new \DateTimeZone($this->appZone));
        } catch (\Exception) {
            return $stored;
        }
        return $date->setTimezone(new \DateTimeZone($zone))->format($format);
    }

    public static function formatTimestamp(int $timestamp, string $zone, string $format = 'Y-m-d H:i:s'): string
    {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone($zone))->format($format);
    }

    /**
     * Runs $fn with PHP's default zone set to $zone: the map engine draws
     * its timestamp with date().
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function using(string $zone, callable $fn): mixed
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set($zone);
        try {
            return $fn();
        } finally {
            date_default_timezone_set($previous);
        }
    }

    private function save(int $mapId, ?string $zone): void
    {
        $zone = trim((string) $zone);
        if ($zone === '') {
            $this->store->remove($mapId, self::NAME);
            return;
        }
        if (!self::isValid($zone)) {
            throw new \InvalidArgumentException('Unknown time zone: ' . $zone);
        }
        $this->store->set($mapId, self::NAME, $zone);
    }
}
