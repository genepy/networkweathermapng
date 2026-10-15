<?php

declare(strict_types=1);

namespace Tests\App;

use App\Core\I18n;
use PHPUnit\Framework\TestCase;

final class I18nTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wm-i18n-' . bin2hex(random_bytes(4));
        $this->addLocale('fr', ['name' => 'Français'], ['Maps' => 'Cartes'], ['Loading…' => 'Chargement…']);
        $this->addLocale('pt_BR', ['name' => 'Português (Brasil)'], ['Maps' => 'Mapas']);
        $this->addLocale('de', null, ['Maps' => 'Karten']);           // no _meta.php
        $this->addLocale('Bad-Name', ['name' => 'Ignored'], []);      // invalid code
        $this->addLocale('en', ['name' => 'Overridden'], []);         // English is built in
        I18n::setLangDir($this->dir);
        unset($_COOKIE['lang'], $_SERVER['HTTP_ACCEPT_LANGUAGE']);
        putenv('APP_LOCALE');
        unset($_ENV['APP_LOCALE']);
    }

    protected function tearDown(): void
    {
        I18n::setLangDir(null);
        unset($_COOKIE['lang'], $_SERVER['HTTP_ACCEPT_LANGUAGE']);
        putenv('APP_LOCALE');
        foreach (glob($this->dir . '/*/*.php') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->dir . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
            rmdir($sub);
        }
        @rmdir($this->dir);
    }

    /**
     * @param array<string, string>|null $meta
     * @param array<string, string> $strings
     * @param array<string, string> $script
     */
    private function addLocale(string $code, ?array $meta, array $strings, array $script = []): void
    {
        $path = $this->dir . '/' . $code;
        mkdir($path, 0755, true);
        if ($meta !== null) {
            file_put_contents($path . '/_meta.php', '<?php return ' . var_export($meta, true) . ';');
        }
        file_put_contents($path . '/main.php', '<?php return ' . var_export($strings, true) . ';');
        if ($script) {
            file_put_contents($path . '/viewer-js.php', '<?php return ' . var_export($script, true) . ';');
        }
    }

    public function testLocalesAreDiscoveredFromTheFolders(): void
    {
        $this->assertSame([
            'en' => 'English',
            'de' => 'de',
            'fr' => 'Français',
            'pt_BR' => 'Português (Brasil)',
        ], I18n::locales());
    }

    public function testEnglishIsAvailableWithoutAnyFolder(): void
    {
        I18n::setLangDir($this->dir . '/does-not-exist');

        $this->assertSame(['en' => 'English'], I18n::locales());
        $this->assertSame('en', I18n::locale());
        $this->assertSame('Maps', __('Maps'));
    }

    public function testTheBundledCataloguesAreDiscovered(): void
    {
        I18n::setLangDir(null);

        $locales = I18n::locales();
        $this->assertSame('English', $locales['en']);
        $this->assertSame('Français', $locales['fr']);
        foreach (array_keys($locales) as $code) {
            $this->assertMatchesRegularExpression(I18n::CODE_PATTERN, $code);
        }
    }

    public function testDiscoveryIsCachedForTheRequest(): void
    {
        I18n::locales();
        $this->addLocale('it', ['name' => 'Italiano'], []);

        $this->assertArrayNotHasKey('it', I18n::locales());
    }

    public function testCatalogueIsLoadedButMetaAndScriptFilesAreNot(): void
    {
        I18n::setLocale('fr');

        $this->assertSame('fr', I18n::locale());
        $this->assertSame('Cartes', __('Maps'));
        $this->assertSame('name', __('name'));
        $this->assertSame('Loading…', __('Loading…'));
        $this->assertSame(['Loading…' => 'Chargement…'], I18n::section('viewer-js'));
    }

    public function testUnknownLocaleFallsBackToEnglish(): void
    {
        I18n::setLocale('Bad-Name');
        $this->assertSame('en', I18n::locale());

        I18n::setLocale('xx');
        $this->assertSame('en', I18n::locale());
        $this->assertSame([], I18n::section('viewer-js'));
    }

    public function testCookieComesBeforeEnvironmentAndBrowser(): void
    {
        $_COOKIE['lang'] = 'de';
        putenv('APP_LOCALE=fr');
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'pt-BR';

        $this->assertSame('de', I18n::locale());
    }

    public function testEnvironmentComesBeforeBrowser(): void
    {
        $_COOKIE['lang'] = 'xx';
        putenv('APP_LOCALE=fr');
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de';

        $this->assertSame('fr', I18n::locale());
    }

    public function testBrowserLanguageMatchesRegionThenLanguage(): void
    {
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'pt-BR,pt;q=0.9';
        $this->assertSame('pt_BR', I18n::locale());

        I18n::setLangDir($this->dir);
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'it-IT, fr-CA;q=0.8, en;q=0.5';
        $this->assertSame('fr', I18n::locale());

        I18n::setLangDir($this->dir);
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'ja, it';
        $this->assertSame('en', I18n::locale());
    }
}
