<?php

declare(strict_types=1);

namespace Tests\App;

use App\Core\Config;
use App\Core\Database;
use App\Core\I18n;
use App\Services\ClockFormat;
use PHPUnit\Framework\TestCase;

/** F51: the clock follows the language, with a global and a per-map setting */
final class ClockFormatTest extends TestCase
{
    private ClockFormat $clocks;

    protected function setUp(): void
    {
        $dir = TESTS_OUTPUT . '/clock';
        @mkdir($dir, 0775, true);
        @unlink($dir . '/test.db');
        $config = new Config();
        $config->set('DB_TYPE', 'sqlite');
        $config->set('DB_PATH', $dir . '/test.db');
        $this->clocks = new ClockFormat(new Database($config));
    }

    public function testAutoFollowsTheLanguage(): void
    {
        $this->assertSame('12h', ClockFormat::resolve('auto', 'en'));
        $this->assertSame('24h', ClockFormat::resolve('auto', 'fr'), 'from fr/_meta.php');
        $this->assertSame('24h', I18n::meta('fr')['clock']);
        $this->assertSame('12h', ClockFormat::resolve('12h', 'fr'), 'a setting wins');
        $this->assertSame('24h', ClockFormat::resolve('24h', 'en'));
    }

    public function testFormat(): void
    {
        $t = 1795000000; // 2026-11-18 11:06:40 UTC
        $this->assertSame('23:06', ClockFormat::format($t + 12 * 3600, 'UTC', '24h'));
        $this->assertSame('11:06 PM', ClockFormat::format($t + 12 * 3600, 'UTC', '12h'));
        $this->assertSame('12:06', ClockFormat::format($t, 'Europe/Paris', '24h'), 'in the zone of the map');
        $this->assertSame('2026-11-18 11:06:40 AM', ClockFormat::format($t, 'UTC', '12h', true, true));
    }

    public function testDefaultAndMapSettings(): void
    {
        $this->assertSame('auto', $this->clocks->getDefault());
        $this->assertSame('24h', $this->clocks->forMap(3, 'fr'));
        $this->assertSame('12h', $this->clocks->forMap(3, 'en'));

        $this->clocks->setDefault('24h');
        $this->assertSame('24h', $this->clocks->forMap(3, 'en'));

        $this->clocks->setOverride(3, '12h');
        $this->assertSame('12h', $this->clocks->forMap(3, 'fr'));
        $this->assertSame([3 => '12h', 4 => '24h'], $this->clocks->forMaps([3, 4], 'fr'));
        $this->assertSame('12h', $this->clocks->getOverride(3));

        $this->clocks->setOverride(3, '');
        $this->assertNull($this->clocks->getOverride(3));
        $this->clocks->setDefault('auto');
        $this->assertSame('auto', $this->clocks->getDefault());

        $this->expectException(\InvalidArgumentException::class);
        $this->clocks->setOverride(3, '13h');
    }
}
