<?php

namespace App\Services;

use App\Core\Config;
use App\Core\Database;

/**
 * Maps & rendering settings (Settings > Maps & rendering): the image format
 * and the refresh interval of the maps. They are stored in the settings table
 * (map_id 0) and replace the environment values (MAP_OUTPUT_FORMAT,
 * MAP_REFRESH_INTERVAL), which stay the defaults of a new install.
 *
 * apply() runs once the database is open, so every reader of the Config
 * sees the stored value without knowing where it comes from.
 */
class RenderingSettings
{
    public const FORMATS = ['png', 'jpg', 'gif'];
    public const MIN_REFRESH = 10;
    public const MAX_REFRESH = 86400;

    /** Config key => name in the settings table */
    private const NAMES = [
        'MAP_OUTPUT_FORMAT' => 'map_output_format',
        'MAP_REFRESH_INTERVAL' => 'map_refresh_interval',
    ];

    private SettingsStore $store;

    public function __construct(Database $db, private Config $config)
    {
        $this->store = new SettingsStore($db);
    }

    /** Reads the stored values into the Config. A broken value is ignored. */
    public function apply(): void
    {
        foreach (self::NAMES as $key => $name) {
            $value = self::normalize($key, $this->store->get(0, $name));
            if ($value !== null) {
                $this->config->override($key, $value);
            }
        }
    }

    public static function fromDatabase(Database $db, Config $config): self
    {
        $settings = new self($db, $config);
        try {
            $settings->apply();
        } catch (\Throwable $e) {
            error_log('Reading the Maps & rendering settings: ' . $e->getMessage());
        }
        return $settings;
    }

    /**
     * Current value, value from the environment and whether the application
     * stores one, for each setting.
     *
     * @return array<string, array{value: string, default: string, stored: bool}>
     */
    public function describe(): array
    {
        $out = [];
        foreach (self::NAMES as $key => $name) {
            $default = self::normalize($key, (string) $this->config->base($key, ''))
                ?? self::fallback($key);
            $stored = self::normalize($key, $this->store->get(0, $name));
            $out[$key] = ['value' => $stored ?? $default, 'default' => $default, 'stored' => $stored !== null];
        }
        return $out;
    }

    /**
     * Saves the posted values. A value equal to the environment's is not
     * stored, so changing .env still has an effect for that setting.
     *
     * @param array<string, mixed> $values Config key => posted value
     * @return string[] keys of the values that were not valid (not saved)
     */
    public function save(array $values): array
    {
        $invalid = [];
        $current = $this->describe();
        foreach (self::NAMES as $key => $name) {
            if (!array_key_exists($key, $values)) {
                continue;
            }
            $value = self::normalize($key, is_scalar($values[$key]) ? (string) $values[$key] : null);
            if ($value === null) {
                $invalid[] = $key;
                continue;
            }
            if ($value === $current[$key]['default']) {
                $this->store->remove(0, $name);
            } else {
                $this->store->set(0, $name, $value);
            }
            $this->config->override($key, $value);
        }
        return $invalid;
    }

    /** Valid, canonical form of a value, null when it is not valid. */
    public static function normalize(string $key, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = strtolower(trim($value));
        if ($key === 'MAP_OUTPUT_FORMAT') {
            if ($value === 'jpeg') {
                $value = 'jpg';
            }
            return in_array($value, self::FORMATS, true) ? $value : null;
        }
        if ($key === 'MAP_REFRESH_INTERVAL') {
            if (!preg_match('/^\d+$/', $value)) {
                return null;
            }
            $seconds = (int) $value;
            return $seconds >= self::MIN_REFRESH && $seconds <= self::MAX_REFRESH ? (string) $seconds : null;
        }
        return null;
    }

    private static function fallback(string $key): string
    {
        return $key === 'MAP_OUTPUT_FORMAT' ? 'png' : '300';
    }
}
