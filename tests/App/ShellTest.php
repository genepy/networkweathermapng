<?php

declare(strict_types=1);

namespace Tests\App;

use App\Core\ErrorPage;
use PHPUnit\Framework\TestCase;

/**
 * The application shell (UI redesign step 2): versioned asset addresses,
 * error pages drawn in the shell, no native alert() or confirm() left in
 * the scripts of the application.
 */
final class ShellTest extends TestCase
{
    public function testAssetAddressesCarryAVersion(): void
    {
        $this->assertMatchesRegularExpression('#^/assets/css/ui\.css\?v=[0-9a-f]{10}$#', asset('/assets/css/ui.css'));
        // a missing file keeps its plain address
        $this->assertSame('/assets/css/missing.css', asset('/assets/css/missing.css'));
    }

    public function testViewsLoadAssetsThroughTheHelper(): void
    {
        $views = dirname(TESTS_ROOT) . '/src/app/Views';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($views, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $html = (string) file_get_contents((string) $file);
            $this->assertDoesNotMatchRegularExpression('#(src|href)="/assets/#', $html,
                $file . ' loads an asset without asset(): browsers keep /assets for 30 days');
        }
    }

    public function testErrorPageIsDrawnInTheShell(): void
    {
        $_SERVER['REQUEST_URI'] = '/nowhere';
        $html = ErrorPage::html(404, 'Page not found', 'This page does not exist or has moved.');

        $this->assertStringContainsString('class="app-bar"', $html);
        $this->assertStringContainsString('<h1>Page not found</h1>', $html);
        $this->assertStringContainsString('Error 404', $html);
        $this->assertStringContainsString('href="/"', $html);
        $this->assertStringNotContainsString('main-footer', $html);
        // no user: no sections, a way to sign in
        $this->assertStringNotContainsString('class="bottom-nav"', $html);
        $this->assertStringContainsString('href="/login"', $html);
    }

    public function testEveryStatusHasAnExplanation(): void
    {
        foreach ([403, 404, 419, 500] as $status) {
            [$title, $message] = ErrorPage::texts($status);
            $this->assertNotSame('', $title);
            $this->assertNotSame('', $message);
        }
    }

    public function testScriptsUseTheShellDialogs(): void
    {
        $js = dirname(TESTS_ROOT) . '/src/public/assets/js';
        foreach (glob($js . '/*.js') ?: [] as $file) {
            if (basename($file) === 'ui.js') {
                continue; // the dialog itself, with its own fallback
            }
            $code = (string) file_get_contents($file);
            // window.confirm()/alert() are only the fallback when ui.js is missing
            preg_match_all('#(?<![\w.])(alert|confirm)\(#', $code, $bare);
            $this->assertSame([], $bare[0], basename($file) . ' calls alert() or confirm() directly');
            $this->assertSame(
                substr_count($code, 'window.confirm('),
                preg_match_all('#WMUI[\s\S]{0,400}?window\.confirm\(#', $code),
                basename($file) . ' uses window.confirm() without trying WMUI.confirm() first'
            );
        }
    }
}
