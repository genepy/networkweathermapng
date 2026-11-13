<?php

declare(strict_types=1);

namespace Tests\App;

use App\Services\Editor\MapThemes;
use PHPUnit\Framework\TestCase;

/**
 * Feedback F9: Map settings open in a drawer over the editor instead of
 * another page, and colour inputs open the application's colour picker.
 */
final class EditorPanelsTest extends TestCase
{
    private static function read(string $path): string
    {
        return (string) file_get_contents(dirname(TESTS_ROOT) . '/' . $path);
    }

    public function testMapSettingsOpenInADrawerOverTheEditor(): void
    {
        $view = self::read('src/app/Views/editor/svg.php');
        $this->assertMatchesRegularExpression('#<a role="menuitem" href="/editor/maps/edit/[^"]+"[^>]* data-map-settings#', $view);
        $this->assertStringContainsString("asset('/assets/js/map-settings.js')", $view);

        $script = self::read('src/public/assets/js/map-settings.js');
        // the editor is not reloaded: it closes the drawer and redraws the map
        $this->assertStringContainsString("body.append('inline', '1')", $script);
        $this->assertStringContainsString('editor.run(editor.load)', $script);

        // no saved message waiting for the next page when the editor stays open
        $controller = self::read('src/app/Controllers/MapManagementController.php');
        $this->assertStringContainsString("empty(\$_POST['inline'])", $controller);
    }

    public function testEditorLoadsTheColourPicker(): void
    {
        $view = self::read('src/app/Views/editor/svg.php');
        $picker = strpos($view, "asset('/assets/js/colour-picker.js')");
        $this->assertNotFalse($picker);
        // after WM_T and WM_EDITOR, which it reads
        $this->assertGreaterThan(strpos($view, 'window.WM_EDITOR = {'), $picker);
        $this->assertStringContainsString('themeColours:', $view);

        $script = self::read('src/public/assets/js/colour-picker.js');
        $this->assertStringContainsString('window.WMColour', $script);
        $this->assertStringContainsString("'wm-colour-none'", $script);
        $this->assertStringContainsString('wm-colour-recent', $script);

        // every colour field that may be "none" answers the picker's None button
        foreach (['src/public/assets/js/editor-svg.js', 'src/public/assets/js/editor-mapprops.js'] as $path) {
            $this->assertStringContainsString("addEventListener('wm-colour-none'", self::read($path), $path);
        }
    }

    public function testThemeColoursAreDistinctHexValues(): void
    {
        $colours = MapThemes::colours();
        $this->assertSame(MapThemes::names(), array_keys($colours));
        foreach ($colours as $name => $list) {
            $this->assertNotEmpty($list, $name);
            $this->assertSame(array_values(array_unique($list)), $list, $name);
            foreach ($list as $hex) {
                $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $hex);
            }
        }
        $this->assertContains('#11141a', $colours['night']);
    }
}
