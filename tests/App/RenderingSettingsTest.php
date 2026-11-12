<?php

declare(strict_types=1);

namespace Tests\App;

use App\Core\Config;
use App\Core\Database;
use App\Services\RenderingSettings;
use App\Services\SettingsStore;
use PHPUnit\Framework\TestCase;

/**
 * Maps & rendering settings stored in the app, the environment as default.
 */
final class RenderingSettingsTest extends TestCase
{
    private Database $db;
    private Config $config;

    protected function setUp(): void
    {
        $data = TESTS_OUTPUT . '/rendering-settings';
        @unlink($data . '/test.db');
        @mkdir($data, 0775, true);

        $this->config = new Config();
        $this->config->set('APP_ROOT', dirname(TESTS_ROOT) . '/src');
        $this->config->set('DATA_ROOT', $data);
        $this->config->set('DB_TYPE', 'sqlite');
        $this->config->set('DB_PATH', $data . '/test.db');
        $this->config->set('MAP_OUTPUT_FORMAT', 'png');
        $this->config->set('MAP_REFRESH_INTERVAL', '300');
        $this->db = new Database($this->config);
    }

    public function testEnvironmentGivesTheDefaults(): void
    {
        $settings = RenderingSettings::fromDatabase($this->db, $this->config);

        $this->assertSame(
            ['value' => 'png', 'default' => 'png', 'stored' => false],
            $settings->describe()['MAP_OUTPUT_FORMAT']
        );
        $this->assertSame('300', $this->config->get('MAP_REFRESH_INTERVAL'));
    }

    public function testSavedValuesReplaceTheEnvironment(): void
    {
        $settings = new RenderingSettings($this->db, $this->config);
        $this->assertSame([], $settings->save(['MAP_OUTPUT_FORMAT' => 'JPEG', 'MAP_REFRESH_INTERVAL' => '60']));
        $this->assertSame('jpg', $this->config->get('MAP_OUTPUT_FORMAT'));

        // a new request reads them from the database
        $config = new Config();
        $config->set('MAP_OUTPUT_FORMAT', 'png');
        $config->set('MAP_REFRESH_INTERVAL', '300');
        $described = RenderingSettings::fromDatabase($this->db, $config)->describe();

        $this->assertSame('jpg', $config->get('MAP_OUTPUT_FORMAT'));
        $this->assertSame('60', $config->get('MAP_REFRESH_INTERVAL'));
        $this->assertSame('300', $config->base('MAP_REFRESH_INTERVAL'));
        $this->assertSame(['value' => '60', 'default' => '300', 'stored' => true], $described['MAP_REFRESH_INTERVAL']);
    }

    public function testValueEqualToTheEnvironmentIsNotStored(): void
    {
        $settings = new RenderingSettings($this->db, $this->config);
        $settings->save(['MAP_REFRESH_INTERVAL' => '120']);
        $settings->save(['MAP_REFRESH_INTERVAL' => '300']);

        $this->assertNull((new SettingsStore($this->db))->get(0, 'map_refresh_interval'));
        $this->assertFalse($settings->describe()['MAP_REFRESH_INTERVAL']['stored']);
    }

    public function testInvalidValuesAreRefused(): void
    {
        $settings = new RenderingSettings($this->db, $this->config);
        $invalid = $settings->save(['MAP_OUTPUT_FORMAT' => 'bmp', 'MAP_REFRESH_INTERVAL' => '5']);

        $this->assertSame(['MAP_OUTPUT_FORMAT', 'MAP_REFRESH_INTERVAL'], $invalid);
        $this->assertSame('png', $this->config->get('MAP_OUTPUT_FORMAT'));
        $this->assertSame('300', $this->config->get('MAP_REFRESH_INTERVAL'));
    }

    public function testBrokenStoredValueIsIgnored(): void
    {
        (new SettingsStore($this->db))->set(0, 'map_output_format', 'tiff');
        RenderingSettings::fromDatabase($this->db, $this->config);

        $this->assertSame('png', $this->config->get('MAP_OUTPUT_FORMAT'));
    }
}
