<?php

declare(strict_types=1);

namespace Tests\App;

use PHPUnit\Framework\TestCase;

/**
 * Every file THIRD-PARTY-NOTICES.md lists as taken from another project
 * exists and keeps its authors' MIT notice.
 */
final class LicenseNoticesTest extends TestCase
{
    public function testListedFilesKeepTheirNotice(): void
    {
        $root = dirname(TESTS_ROOT);
        $notices = (string) file_get_contents($root . '/THIRD-PARTY-NOTICES.md');
        preg_match_all('/^\| `(src\/lib\/[^`]+\.php)` \|/m', $notices, $matches);

        $this->assertNotEmpty($matches[1]);
        foreach ($matches[1] as $file) {
            $this->assertFileExists($root . '/' . $file);
            $this->assertStringContainsString(
                'Permission is hereby granted',
                (string) file_get_contents($root . '/' . $file),
                $file . ' has lost its license notice'
            );
        }
    }

    public function testEveryDataSourcePluginIsAccountedFor(): void
    {
        $root = dirname(TESTS_ROOT);
        $notices = (string) file_get_contents($root . '/THIRD-PARTY-NOTICES.md');
        $ownCode = ['WeatherMapDataSource_observium.php', 'WeatherMapDataSource_zabbix.php'];

        foreach (glob($root . '/src/lib/WeatherMap/lib/datasources/*.php') as $path) {
            if (in_array(basename($path), $ownCode, true)) {
                continue;
            }
            $this->assertStringContainsString(
                '`src/lib/WeatherMap/lib/datasources/' . basename($path) . '`',
                $notices,
                basename($path) . ' is missing from THIRD-PARTY-NOTICES.md'
            );
        }
    }
}
