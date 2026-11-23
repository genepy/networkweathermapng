<?php
/**
 * Zabbix Weathermap - Configuration Manager
 */

declare(strict_types=1);

namespace App\Core;

class Config
{
    private array $config = [];

    /** values from the environment (or the defaults) that a setting stored in the app replaced */
    private array $base = [];
    
    public function __construct()
    {
        $this->loadDefaults();
        $this->loadFromEnvironment();
    }
    
    private function loadDefaults(): void
    {
        // APP_ROOT holds app/, lib/ and public/: /app in Docker (src/ is
        // copied there), src/ in a checkout, so `composer start` works
        // without setting it. Data goes next to src/ in a checkout.
        $appRoot = dirname(__DIR__, 2);
        $dataRoot = basename($appRoot) === 'src' && is_file(dirname($appRoot) . '/composer.json')
            ? dirname($appRoot) . '/data'
            : $appRoot . '/data';
        
        $this->config = [
            // Application
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_TIMEZONE' => 'UTC',
            'APP_SECRET_KEY' => '',
            // Content-Security-Policy; empty = the default policy, 'off' = none
            'APP_CSP' => '',
            // Tile servers a map's basemap may use (space separated URL prefixes)
            'BASEMAP_SERVERS' => 'https://tiles.openfreemap.org',
            // Key that encrypts data source passwords; empty = DATA_ROOT/app.key
            'APP_KEY' => '',
            'APP_ROOT' => $appRoot,
            'DATA_ROOT' => $dataRoot,
            
            // Database
            'DB_TYPE' => 'sqlite',
            'DB_PATH' => $dataRoot . '/weathermap.db',
            'DB_HOST' => 'localhost',
            'DB_PORT' => '3306',
            'DB_NAME' => 'weathermap',
            'DB_USER' => '',
            'DB_PASSWORD' => '',
            
            // Authentication
            'AUTH_ENABLED' => 'true',
            'AUTH_TYPE' => 'local',
            'ADMIN_USER' => 'admin',
            'ADMIN_PASSWORD' => 'admin',
            
            // Map settings
            'MAP_OUTPUT_FORMAT' => 'svg',
            'MAP_THUMB_SIZE' => '250',
            'MAP_REFRESH_INTERVAL' => '300',
        ];
    }
    
    /**
     * Real environment variables, then the values of .env: Dotenv puts
     * those in $_ENV and $_SERVER only (no putenv), and never over a real
     * variable, so a real variable still wins.
     */
    private function loadFromEnvironment(): void
    {
        foreach ($this->config as $key => $default) {
            $envValue = self::env($key);
            if ($envValue !== null) {
                $this->config[$key] = $envValue;
            }
        }
    }

    /** A variable of the environment or of .env, null when neither sets it */
    public static function env(string $key): ?string
    {
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }
        foreach ([$_ENV, $_SERVER] as $source) {
            if (isset($source[$key]) && is_scalar($source[$key])) {
                return (string) $source[$key];
            }
        }
        return null;
    }
    
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }
    
    public function set(string $key, mixed $value): void
    {
        $this->config[$key] = $value;
    }
    
    /**
     * Replaces a value with one stored in the application (Settings), and
     * keeps the environment's value as the default shown next to it.
     */
    public function override(string $key, mixed $value): void
    {
        if (!array_key_exists($key, $this->base)) {
            $this->base[$key] = $this->config[$key] ?? null;
        }
        $this->config[$key] = $value;
    }

    /** The value from the environment (or the built-in default), before any override. */
    public function base(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->base)) {
            return $this->base[$key] ?? $default;
        }
        return $this->config[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->config;
    }
    
    public function isDebug(): bool
    {
        return $this->get('APP_DEBUG') === 'true';
    }
    
    public function isAuthEnabled(): bool
    {
        return $this->get('AUTH_ENABLED') === 'true';
    }
    
    public function getDatabaseConfig(): array
    {
        return [
            'type' => $this->get('DB_TYPE'),
            'path' => $this->get('DB_PATH'),
            'host' => $this->get('DB_HOST'),
            'port' => $this->get('DB_PORT'),
            'name' => $this->get('DB_NAME'),
            'user' => $this->get('DB_USER'),
            'password' => $this->get('DB_PASSWORD'),
        ];
    }
    
    // Path helpers - build paths from APP_ROOT and DATA_ROOT
    public function getAppRoot(): string
    {
        return $this->get('APP_ROOT');
    }
    
    public function getDataRoot(): string
    {
        return $this->get('DATA_ROOT');
    }
    
    public function getConfigsPath(): string
    {
        return $this->getDataRoot() . '/configs';
    }
    
    public function getOutputPath(): string
    {
        return $this->getDataRoot() . '/output';
    }
    
    public function getCachePath(): string
    {
        return $this->getDataRoot() . '/cache';
    }
    
    public function getLogsPath(): string
    {
        return $this->getAppRoot() . '/logs';
    }
    
    public function getLibPath(): string
    {
        return $this->getAppRoot() . '/lib';
    }
    
    public function getPublicPath(): string
    {
        return $this->getAppRoot() . '/public';
    }
    
    public function getObjectsPath(): string
    {
        return $this->getPublicPath() . '/objects';
    }
    
    public function getBackgroundsPath(): string
    {
        return $this->getPublicPath() . '/backgrounds';
    }
}
