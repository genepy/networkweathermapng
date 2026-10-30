<?php

declare(strict_types=1);

namespace Tests\App;

use App\Core\Config;
use App\Core\Database;
use App\Services\KioskService;
use PHPUnit\Framework\TestCase;

final class KioskServiceTest extends TestCase
{
    private Database $db;
    private KioskService $kiosk;

    protected function setUp(): void
    {
        $data = TESTS_OUTPUT . '/kiosk';
        @unlink($data . '/test.db');
        @mkdir($data, 0775, true);

        $config = new Config();
        $config->set('APP_ROOT', dirname(TESTS_ROOT) . '/src');
        $config->set('DATA_ROOT', $data);
        $config->set('DB_TYPE', 'sqlite');
        $config->set('DB_PATH', $data . '/test.db');
        $this->db = new Database($config);
        $this->kiosk = new KioskService($this->db);
    }

    public function testDefaults(): void
    {
        $this->assertSame(KioskService::DEFAULT_DURATION, $this->kiosk->getDefaultDuration());
        $this->assertSame(
            [3 => ['zoom' => 0, 'duration' => KioskService::DEFAULT_DURATION]],
            $this->kiosk->resolve([['id' => 3]])
        );
    }

    public function testMapsOverrideTheDefaultDuration(): void
    {
        $this->kiosk->setDefaultDuration(45);
        $this->kiosk->setMapSettings(1, 150, 120);
        $this->kiosk->setMapSettings(2, 0, null);

        $this->assertSame([
            1 => ['zoom' => 150, 'duration' => 120],
            2 => ['zoom' => 0, 'duration' => 45],
        ], $this->kiosk->resolve([['id' => 1], ['id' => 2]]));

        // back to the defaults: nothing left stored for the map
        $this->kiosk->setMapSettings(1, 0, null);
        $this->assertSame(['zoom' => 0, 'duration' => 45], $this->kiosk->resolve([['id' => 1]])[1]);
        $this->assertNull($this->db->queryOne('SELECT * FROM settings WHERE map_id = 1'));
    }

    public function testSavingTwiceUpdates(): void
    {
        $this->kiosk->setDefaultDuration(60);
        $this->kiosk->setDefaultDuration(60);
        $this->kiosk->setDefaultDuration(90);
        $this->assertSame(90, $this->kiosk->getDefaultDuration());
        $this->assertCount(1, $this->db->query("SELECT * FROM settings WHERE map_id = 0 AND name = 'kiosk_duration'"));
    }

    public function testValuesAreKeptWithinBounds(): void
    {
        $this->kiosk->setDefaultDuration(1);
        $this->assertSame(KioskService::MIN_DURATION, $this->kiosk->getDefaultDuration());

        $this->kiosk->setMapSettings(1, 5000, 999999);
        $this->kiosk->setMapSettings(2, 5, null);
        $settings = $this->kiosk->getMapSettings([1, 2]);
        $this->assertSame(['zoom' => KioskService::MAX_ZOOM, 'duration' => KioskService::MAX_DURATION], $settings[1]);
        $this->assertSame(KioskService::ZOOM_FIT, $settings[2]['zoom']);
    }

    public function testZoomChoicesMatchTheBounds(): void
    {
        $this->assertSame(KioskService::MIN_ZOOM, KioskService::ZOOM_STEPS[0]);
        $this->assertSame(KioskService::MAX_ZOOM, KioskService::ZOOM_STEPS[count(KioskService::ZOOM_STEPS) - 1]);
    }
}
