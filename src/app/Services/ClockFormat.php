<?php
/**
 * NetworkWeathermapNG - how times are written (F51): 24-hour or 12-hour
 * clock. A default for every map (Settings > Maps & rendering, map_id 0 of
 * the settings table) and an optional value per map (Map settings >
 * Display & kiosk). "auto" follows the language of the interface: the
 * clock of its _meta.php, 12-hour for English.
 *
 * format() is the one PHP formatter of an update time; the browser has its
 * twin, WMUI.time() in ui.js, fed by the data-clock attributes this
 * service resolves.
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\I18n;

final class ClockFormat
{
    public const CHOICES = ['auto', '24h', '12h'];

    private const NAME = 'clock';

    private SettingsStore $store;

    public function __construct(Database $db)
    {
        $this->store = new SettingsStore($db);
    }

    /** The setting of every map without its own: auto, 24h or 12h */
    public function getDefault(): string
    {
        return self::valid($this->store->get(0, self::NAME)) ?? 'auto';
    }

    public function setDefault(string $clock): void
    {
        $clock = self::valid($clock) ?? throw new \InvalidArgumentException('Unknown clock: ' . $clock);
        $clock === 'auto' ? $this->store->remove(0, self::NAME) : $this->store->set(0, self::NAME, $clock);
    }

    /** The map's own setting, null when it follows the default */
    public function getOverride(int $mapId): ?string
    {
        return $mapId > 0 ? self::valid($this->store->get($mapId, self::NAME)) : null;
    }

    /** @param ?string $clock null or '' to follow the default */
    public function setOverride(int $mapId, ?string $clock): void
    {
        if ($mapId <= 0) {
            throw new \InvalidArgumentException('Invalid map id');
        }
        $clock = trim((string) $clock);
        if ($clock === '') {
            $this->store->remove($mapId, self::NAME);
            return;
        }
        $this->store->set($mapId, self::NAME, self::valid($clock) ?? throw new \InvalidArgumentException('Unknown clock: ' . $clock));
    }

    /** 24h or 12h for a map (0: no map), in the language of the interface */
    public function forMap(int $mapId, ?string $locale = null): string
    {
        return self::resolve($this->getOverride($mapId) ?? $this->getDefault(), $locale);
    }

    /**
     * forMap() for many maps at once.
     *
     * @param int[] $mapIds
     * @return array<int, string>
     */
    public function forMaps(array $mapIds, ?string $locale = null): array
    {
        $default = $this->getDefault();
        $out = array_fill_keys($mapIds, self::resolve($default, $locale));
        foreach ($this->store->getMany([self::NAME], $mapIds) as $id => $values) {
            $own = self::valid($values[self::NAME] ?? null);
            if ($own !== null && array_key_exists($id, $out)) {
                $out[$id] = self::resolve($own, $locale);
            }
        }
        return $out;
    }

    /** auto becomes the clock of the language (_meta.php), 12h for English */
    public static function resolve(string $clock, ?string $locale = null): string
    {
        if ($clock === '24h' || $clock === '12h') {
            return $clock;
        }
        $locale ??= I18n::locale();
        if ($locale === I18n::DEFAULT_LOCALE) {
            return '12h';
        }
        $own = I18n::meta($locale)['clock'] ?? '24h';
        return $own === '12h' ? '12h' : '24h';
    }

    /** The date() format of a time: H:i (14:05) or g:i A (2:05 PM), seconds and date on demand */
    public static function phpFormat(string $clock, bool $seconds = false, bool $date = false): string
    {
        $time = $clock === '12h' ? ($seconds ? 'g:i:s A' : 'g:i A') : ($seconds ? 'H:i:s' : 'H:i');
        return $date ? 'Y-m-d ' . $time : $time;
    }

    /** A Unix time written in a zone with a clock (24h or 12h) */
    public static function format(int $timestamp, string $zone, string $clock, bool $seconds = false, bool $date = false): string
    {
        return TimezoneService::formatTimestamp($timestamp, $zone, self::phpFormat(self::resolve($clock), $seconds, $date));
    }

    private static function valid(?string $clock): ?string
    {
        $clock = strtolower(trim((string) $clock));
        return in_array($clock, self::CHOICES, true) ? $clock : null;
    }
}
