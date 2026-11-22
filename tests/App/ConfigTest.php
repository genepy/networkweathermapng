<?php

declare(strict_types=1);

namespace Tests\App;

use App\Core\Config;
use PHPUnit\Framework\TestCase;

/** F45: values of .env (in $_ENV / $_SERVER only) are read, real variables win */
final class ConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['ADMIN_USER'], $_SERVER['ADMIN_USER']);
        putenv('ADMIN_USER');
    }

    public function testAValueOnlyInEnvIsRead(): void
    {
        putenv('ADMIN_USER');
        $_ENV['ADMIN_USER'] = 'from-dotenv';
        $this->assertSame('from-dotenv', (new Config())->get('ADMIN_USER'));

        unset($_ENV['ADMIN_USER']);
        $_SERVER['ADMIN_USER'] = 'from-server';
        $this->assertSame('from-server', (new Config())->get('ADMIN_USER'));
    }

    public function testARealVariableWins(): void
    {
        $_ENV['ADMIN_USER'] = 'from-dotenv';
        putenv('ADMIN_USER=real');
        $this->assertSame('real', (new Config())->get('ADMIN_USER'));
    }

    public function testDefaultsFindTheLibraryWithoutAppRoot(): void
    {
        if (getenv('APP_ROOT') !== false) {
            $this->markTestSkipped('APP_ROOT is set');
        }
        $config = new Config();
        $this->assertFileExists($config->getLibPath() . '/WeatherMap/bootstrap.php');
        $this->assertSame(dirname(TESTS_ROOT) . '/data', $config->get('DATA_ROOT'));
    }
}
