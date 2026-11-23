<?php

declare(strict_types=1);

namespace Tests\App;

use App\Core\Version;
use PHPUnit\Framework\TestCase;

/** F61: the version shown in the interface */
final class VersionTest extends TestCase
{
    public function testTheVersionFileGivesTheNumber(): void
    {
        $file = dirname(TESTS_ROOT) . '/VERSION';
        $this->assertFileExists($file);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', trim((string) file_get_contents($file)));
        $this->assertSame(trim((string) file_get_contents($file)), Version::number());
    }

    public function testTheLabelNamesTheCommitWhenKnown(): void
    {
        $this->assertSame('0.9.0 (3abc40a)', Version::describe('0.9.0', '3abc40a'));
        $this->assertSame('0.9.0', Version::describe('0.9.0', ''));
        $this->assertSame('dev', Version::describe('', ''));
    }

    public function testTheCommitComesFromTheBuild(): void
    {
        putenv('APP_COMMIT=3ABC40AE9EAD23A514020AE73FC33693BC847102');
        try {
            $this->assertSame('3abc40a', Version::commit());
            putenv('APP_COMMIT=not a sha');
            $this->assertSame('', Version::commit());
        } finally {
            putenv('APP_COMMIT');
        }
    }

    public function testTheMenuShowsTheVersion(): void
    {
        $layout = (string) file_get_contents(dirname(TESTS_ROOT) . '/src/app/Views/layouts/main.php');
        $this->assertStringContainsString("Version::label()", $layout);
        $docker = (string) file_get_contents(dirname(TESTS_ROOT) . '/docker/Dockerfile');
        $this->assertStringContainsString('COPY VERSION /app/VERSION', $docker);
        $this->assertStringContainsString('ARG APP_COMMIT', $docker);
    }
}
