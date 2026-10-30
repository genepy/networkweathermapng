<?php

declare(strict_types=1);

namespace Tests\App;

use App\Core\Config;
use App\Core\Database;
use App\Services\TimezoneService;
use PHPUnit\Framework\TestCase;

final class TimezoneServiceTest extends TestCase
{
    private Database $db;
    private TimezoneService $timezones;

    protected function setUp(): void
    {
        $data = TESTS_OUTPUT . '/timezone';
        @unlink($data . '/test.db');
        @mkdir($data, 0775, true);

        $config = new Config();
        $config->set('APP_ROOT', dirname(TESTS_ROOT) . '/src');
        $config->set('DATA_ROOT', $data);
        $config->set('DB_TYPE', 'sqlite');
        $config->set('DB_PATH', $data . '/test.db');
        $this->db = new Database($config);
        $this->timezones = new TimezoneService($this->db, 'UTC');
    }

    public function testDefaultsToTheApplicationZone(): void
    {
        $this->assertSame('UTC', $this->timezones->getDefault());
        $this->assertSame([3 => null], $this->timezones->getOverrides([3]));
        $this->assertSame([3 => 'UTC'], $this->timezones->forMaps([3]));
    }

    public function testInvalidApplicationZoneFallsBackOnUtc(): void
    {
        $this->assertSame('UTC', (new TimezoneService($this->db, 'Mars/Olympus'))->getAppZone());
    }

    public function testDefaultAndOverride(): void
    {
        $this->timezones->setDefault('Europe/Paris');
        $this->timezones->setOverride(3, 'America/New_York');

        $this->assertSame('Europe/Paris', $this->timezones->getDefault());
        $this->assertSame([3 => 'America/New_York', 4 => null], $this->timezones->getOverrides([3, 4]));
        $this->assertSame([3 => 'America/New_York', 4 => 'Europe/Paris'], $this->timezones->forMaps([3, 4]));

        // an empty zone goes back to the default
        $this->timezones->setOverride(3, '');
        $this->timezones->setDefault(null);
        $this->assertSame('UTC', $this->timezones->forMap(3));
    }

    public function testUnknownZoneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->timezones->setOverride(3, 'Mars/Olympus');
    }

    public function testOlderZoneNamesAreAccepted(): void
    {
        $older = array_diff(
            \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC),
            \DateTimeZone::listIdentifiers()
        );
        foreach (array_slice($older, 0, 3) as $zone) {
            $this->assertTrue(TimezoneService::isValid($zone), $zone);
        }
        $this->assertTrue(TimezoneService::isValid('Europe/Paris'));
        $this->assertFalse(TimezoneService::isValid('europe/paris'));
    }

    public function testFormatting(): void
    {
        // 2026-07-01 12:00:00 UTC, summer time in Paris
        $this->assertSame('14:00', TimezoneService::formatTimestamp(1782907200, 'Europe/Paris', 'H:i'));
        $this->assertSame('2026-07-01 14:00:00', $this->timezones->formatStored('2026-07-01 12:00:00', 'Europe/Paris'));
        $this->assertSame('not a date', $this->timezones->formatStored('not a date', 'Europe/Paris'));
    }

    public function testUsingRestoresTheZone(): void
    {
        $before = date_default_timezone_get();
        $hour = TimezoneService::using('Asia/Tokyo', static fn () => date('H', 1782907200));
        $this->assertSame('21', $hour);
        $this->assertSame($before, date_default_timezone_get());
    }
}
