<?php

declare(strict_types=1);

namespace Tests\App;

use PHPUnit\Framework\TestCase;

/**
 * UI redesign step 9: the contextual bar, the tool rail, the zoom group and
 * the Ctrl+K palette of the SVG editor only use commands the editor runs
 * and icons that exist.
 */
final class EditorContextualBarTest extends TestCase
{
    private static function read(string $path): string
    {
        return (string) file_get_contents(dirname(TESTS_ROOT) . '/' . $path);
    }

    /** @return list<string> the command names of editor-svg.js */
    private static function commands(): array
    {
        $script = self::read('src/public/assets/js/editor-svg.js');
        $start = strpos($script, 'var commands = {');
        self::assertNotFalse($start);
        $end = strpos($script, "\n    };", $start);
        preg_match_all("/^\\s+'([a-z0-9-]+)':/m", substr($script, $start, $end - $start), $m);
        return $m[1];
    }

    public function testButtonsOfTheBarsRunEditorCommands(): void
    {
        $view = self::read('src/app/Views/editor/svg.php');
        $commands = self::commands();
        foreach (['selbar', 'selbar-align', 'tool-rail', 'zoom-group'] as $id) {
            $start = strpos($view, 'id="' . $id . '"');
            $this->assertNotFalse($start, $id . ' is missing');
            $end = strpos($view, "\n        </div>", $start);
            preg_match_all("/\\\$btn\\('([a-z0-9-]+)'|data-cmd=\"([a-z0-9-]+)\"/", substr($view, $start, $end - $start), $m);
            $names = array_filter([...$m[1], ...$m[2]]);
            $this->assertNotEmpty($names, $id);
            foreach ($names as $name) {
                $this->assertContains($name, $commands, $name . ' (' . $id . ') is not an editor command');
            }
        }
    }

    public function testPaletteCommandsAndIconsExist(): void
    {
        $script = self::read('src/public/assets/js/editor-palette.js');
        $editorIcons = self::read('src/app/Views/editor/toolbar-icons.php');
        $uiIcons = self::read('src/public/assets/icons/ui.svg');
        $commands = self::commands();

        preg_match_all("/cmd\\('([a-z0-9-]+)'\\)/", $script, $m);
        $this->assertGreaterThan(20, count(array_unique($m[1])));
        foreach (array_unique($m[1]) as $name) {
            $this->assertContains($name, $commands, $name . ' is not an editor command');
        }

        preg_match_all("/icon: (?:[^']*\\? )?'([^']+)'(?: : '([^']+)')?/", $script, $icons);
        $names = array_unique(array_filter([...$icons[1], ...$icons[2]]));
        $this->assertNotEmpty($names);
        foreach ($names as $icon) {
            if ($icon[0] === '#') {
                $this->assertStringContainsString('id="' . substr($icon, 1) . '"', $editorIcons, $icon . ' is not in toolbar-icons.php');
            } else {
                $this->assertStringContainsString('<symbol id="' . $icon . '"', $uiIcons, $icon . ' is not in ui.svg');
            }
        }
    }

    public function testEditorLoadsThePalette(): void
    {
        $view = self::read('src/app/Views/editor/svg.php');
        $palette = strpos($view, "/assets/js/palette.js");
        $own = strpos($view, "/assets/js/editor-palette.js");
        $this->assertNotFalse($palette);
        $this->assertNotFalse($own);
        $this->assertLessThan($own, $palette, 'palette.js comes before editor-palette.js');
        $this->assertStringContainsString('data-palette-open', $view);
        $this->assertStringContainsString("register: function (source)", self::read('src/public/assets/js/palette.js'));
    }
}
