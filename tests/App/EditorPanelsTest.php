<?php

declare(strict_types=1);

namespace Tests\App;

use App\Services\Editor\MapThemes;
use PHPUnit\Framework\TestCase;

/**
 * Feedback F9 and F53: the Map panel (map_settings.php) shows in the
 * properties panel of the editor, with the same tabs as the drawer of the
 * viewer and the Maps page; colour inputs open the application's colour
 * picker.
 */
final class EditorPanelsTest extends TestCase
{
    private static function read(string $path): string
    {
        return (string) file_get_contents(dirname(TESTS_ROOT) . '/' . $path);
    }

    public function testTheMapPanelIsTheSameInTheEditor(): void
    {
        $view = self::read('src/app/Views/editor/svg.php');
        // ⋯ › Map… opens the panel in the properties panel; no second "Map settings" entry
        $this->assertStringContainsString('data-cmd="map-properties"', $view);
        $this->assertStringNotContainsString('data-map-settings', $view);
        $this->assertStringContainsString("asset('/assets/js/map-settings.js')", $view);

        $panel = self::read('src/app/Views/editor/map_settings.php');
        foreach (['general', 'look', 'display', 'recording', 'access'] as $tab) {
            $this->assertStringContainsString('id="ms-' . $tab . '"', $panel, $tab);
        }
        foreach (['general', 'look', 'display'] as $slot) {
            $this->assertStringContainsString('data-ms-slot="' . $slot . '"', $panel, 'the editor fills ' . $slot);
        }
        $svg = self::read('src/public/assets/js/editor-svg.js');
        $this->assertStringContainsString('WMMapSettings.load(cfg.mapId)', $svg);
        $this->assertStringContainsString("fillSlot(form, 'look'", $svg);

        $script = self::read('src/public/assets/js/map-settings.js');
        // the editor is not reloaded: it redraws the map and fetches the panel again
        $this->assertStringContainsString("body.append('inline', '1')", $script);
        $this->assertStringContainsString('editor.run(editor.load)', $script);
        $this->assertStringContainsString('editor.mapFormSaved()', $script);

        // the per-map tables of Settings lead to the matching tab
        foreach (['kiosk' => 'display', 'recording' => 'recording', 'timezone' => 'display'] as $page => $tab) {
            $this->assertStringContainsString('data-map-settings data-tab="' . $tab . '"', self::read('src/app/Views/admin/' . $page . '.php'), $page);
        }

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

    public function testBasemapIsOneClickAway(): void
    {
        // F31, F53: the Basemap section of Map › Look, Ctrl+K and one link in the background library
        $view = self::read('src/app/Views/editor/svg.php');
        $this->assertSame(0, substr_count($view, 'data-cmd="basemap"'), 'no duplicate entries in the layers, the ⋯ menu or the Add panel');
        $this->assertStringContainsString('class="bg-basemap-open"', $view);

        $props = self::read('src/public/assets/js/editor-mapprops.js');
        $this->assertStringContainsString("ed.commands.basemap = openBasemap;", $props);
        $this->assertStringContainsString("get('open')", $props);
        $this->assertStringContainsString("cmd('basemap')", self::read('src/public/assets/js/editor-palette.js'));
        $this->assertStringContainsString('.bg-basemap-open', self::read('src/public/assets/js/editor-backgrounds.js'));

        // New map > On a web map, then the editor opens on the Basemap section
        $this->assertStringContainsString('<option value="web">', self::read('src/app/Views/editor/map_form.php'));
        $this->assertStringContainsString("'?open=basemap'", self::read('src/app/Controllers/MapManagementController.php'));
    }

    public function testLongHelpGoesBehindInfoButtons(): void
    {
        // F69: section explanations and long help lines become i buttons (the existing tips)
        $js = self::read('src/public/assets/js/editor-svg.js');
        $this->assertStringContainsString('function tuckHelp(root)', $js);
        $this->assertStringContainsString(".mapprops-explain:not([data-tucked])", $js);
        $this->assertStringContainsString("span.hint:not([data-tucked]), .form-group > small.text-muted:not([data-tucked])", $js);
        $this->assertStringContainsString("observe(box, { childList: true, subtree: true })", $js);
        // the button has a name and the tip is announced
        $this->assertStringContainsString("'aria-label': t('About :name', { name: name }), 'aria-describedby': text.id", $js);
    }

    public function testLinksFollowTheirNodeWhileItIsDragged(): void
    {
        // F71: no dashed preview; the link is drawn again live under the nodes
        $js = self::read('src/public/assets/js/editor-svg.js');
        $this->assertStringContainsString('function liveLinks(moved)', $js);
        $this->assertStringContainsString("g.classList.toggle('drag-hidden', one);", $js);
        $this->assertStringNotContainsString("el('polyline', { 'class': 'ghost', points: curvePoints(linkPath(l, moved)", $js);
        $this->assertStringContainsString('#map g.drag-hidden { visibility: hidden; }', self::read('src/public/assets/css/editor-svg.css'));
    }
}
