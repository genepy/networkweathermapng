<?php

declare(strict_types=1);

namespace Tests\App;

use App\Core\Config;
use App\Core\Database;
use App\Services\KeyboardShortcuts;
use PHPUnit\Framework\TestCase;

/**
 * Keyboard shortcuts of each user (feedback 2026-11-06, F6): the catalogue,
 * the checks before saving, the storage, and the pages that read them.
 */
final class KeyboardShortcutsTest extends TestCase
{
    private Database $db;
    private KeyboardShortcuts $keys;

    protected function setUp(): void
    {
        $data = TESTS_OUTPUT . '/shortcuts';
        @unlink($data . '/test.db');
        @mkdir($data, 0775, true);

        $config = new Config();
        $config->set('APP_ROOT', dirname(TESTS_ROOT) . '/src');
        $config->set('DATA_ROOT', $data);
        $config->set('DB_TYPE', 'sqlite');
        $config->set('DB_PATH', $data . '/test.db');
        $this->db = new Database($config);
        $this->keys = new KeyboardShortcuts($this->db);
    }

    private static function read(string $path): string
    {
        return (string) file_get_contents(dirname(TESTS_ROOT) . '/' . $path);
    }

    public function testTheDefaultsHaveNoProblem(): void
    {
        $this->assertSame([], KeyboardShortcuts::problems([]));
        foreach (KeyboardShortcuts::ACTIONS as $id => [$area, $defaults]) {
            $this->assertContains($area, KeyboardShortcuts::AREAS, $id);
            $this->assertArrayHasKey($id, KeyboardShortcuts::labels(), $id . ' has no label');
            if ($area === 'editor-drag') {
                $this->assertArrayHasKey($id, KeyboardShortcuts::DRAG_KEYS, $id);
            }
        }
    }

    public function testDragOnEmptySpaceMovesTheViewAndAltDuplicates(): void
    {
        $defaults = KeyboardShortcuts::defaults();
        $this->assertSame(['Ctrl'], $defaults['editor.drag-pan']);
        $this->assertSame(['Alt'], $defaults['editor.drag-duplicate']);
        $this->assertSame(['Shift'], $defaults['editor.drag-nosnap']);
    }

    public function testKeyCombinations(): void
    {
        foreach (['Ctrl+Z', 'Ctrl+Shift+Z', 'Ctrl+Alt+Shift+K', '?', '+', 'Ctrl++', 'Ctrl+]', '0', 'F2', 'Shift+F10', 'ArrowLeft', 'Space', 'É'] as $ok) {
            $this->assertTrue(KeyboardShortcuts::validCombo($ok), $ok);
        }
        foreach (['', 'z', 'Ctrl+z', 'Shift+?', 'Shift+0', 'Shift+Ctrl+Z', 'Ctrl+', 'Foo', 'F13', 'Ctrl+AB'] as $bad) {
            $this->assertFalse(KeyboardShortcuts::validCombo($bad), $bad);
        }
    }

    public function testConflictsInAnArea(): void
    {
        $problems = KeyboardShortcuts::problems(['editor.lock' => ['Ctrl+G']]);
        $this->assertArrayHasKey('editor.lock', $problems);
        $this->assertStringContainsString('Group', $problems['editor.lock']);

        // the same key in the editor and in the viewer is fine: they are separate pages
        $this->assertSame([], KeyboardShortcuts::problems(['viewer.panel' => ['L']]));

        // a key of "Everywhere" cannot be used in an area
        $this->assertArrayHasKey('editor.find', KeyboardShortcuts::problems(['editor.find' => ['Ctrl+K']]));
        $this->assertNotEmpty(KeyboardShortcuts::problems(['palette' => ['P']]));
    }

    public function testReservedAndDragKeys(): void
    {
        $this->assertArrayHasKey('editor.undo', KeyboardShortcuts::problems(['editor.undo' => ['Escape']]));
        $this->assertArrayHasKey('editor.undo', KeyboardShortcuts::problems(['editor.undo' => ['Space']]));
        $this->assertSame([], KeyboardShortcuts::problems(['viewer.history' => ['Shift+H']]));
        // Shift+drag on empty space adds an area to the selection
        $this->assertArrayHasKey('editor.drag-pan', KeyboardShortcuts::problems(['editor.drag-pan' => ['Shift']]));
        $this->assertArrayHasKey('editor.drag-nosnap', KeyboardShortcuts::problems(['editor.drag-nosnap' => ['Alt']]));
        $this->assertArrayHasKey('editor.drag-duplicate', KeyboardShortcuts::problems(['editor.drag-duplicate' => ['Ctrl', 'Alt']]));
        $this->assertSame([], KeyboardShortcuts::problems(['editor.drag-duplicate' => ['Ctrl'], 'editor.drag-pan' => ['Alt']]));
        $this->assertSame([], KeyboardShortcuts::problems(['editor.drag-duplicate' => []]));
        $this->assertArrayHasKey('editor.undo', KeyboardShortcuts::problems(['editor.undo' => ['A', 'B', 'C', 'D']]));
    }

    public function testSaveKeepsOnlyTheChanges(): void
    {
        $this->assertSame(KeyboardShortcuts::defaults(), $this->keys->forUser(7));

        $problems = $this->keys->save(7, [
            'editor.undo' => ['Ctrl+Z', 'Alt+Backspace'],
            'editor.redo' => ['Ctrl+Shift+Z', 'Ctrl+Y'],
            'viewer.edit' => [],
            'unknown.action' => ['X'],
        ]);
        $this->assertSame([], $problems);
        $this->assertSame(['editor.undo' => ['Ctrl+Z', 'Alt+Backspace'], 'viewer.edit' => []], $this->keys->overrides(7));
        $mine = $this->keys->forUser(7);
        $this->assertSame([], $mine['viewer.edit']);
        $this->assertSame(['Ctrl+Shift+Z', 'Ctrl+Y'], $mine['editor.redo']);
        // other users keep the defaults
        $this->assertSame(KeyboardShortcuts::defaults(), $this->keys->forUser(8));

        // a save replaces the earlier changes
        $this->keys->save(7, ['editor.undo' => ['Ctrl+Z']]);
        $this->assertSame([], $this->keys->overrides(7));
    }

    public function testASaveWithAConflictChangesNothing(): void
    {
        $this->keys->save(3, ['viewer.edit' => ['M']]);
        $problems = $this->keys->save(3, ['viewer.edit' => ['P']]);
        $this->assertArrayHasKey('viewer.edit', $problems);
        $this->assertSame(['viewer.edit' => ['M']], $this->keys->overrides(3));
    }

    public function testTheScriptGivesTheKeysToTheBrowser(): void
    {
        $this->keys->save(5, ['palette' => ['Ctrl+Shift+P']]);
        $script = $this->keys->scriptFor(5);
        $this->assertStringContainsString('window.WM_KEYS = ', $script);
        $this->assertStringContainsString('"palette":["Ctrl+Shift+P"]', $script);
        $this->assertStringNotContainsString('</script><', substr($script, 0, -9));
    }

    public function testPagesLoadTheShortcutsScript(): void
    {
        $layout = self::read('src/app/Views/layouts/main.php');
        $editor = self::read('src/app/Views/editor/svg.php');
        foreach (['layout' => $layout, 'editor' => $editor] as $name => $view) {
            $this->assertStringContainsString('$keysScript', $view, $name);
            $this->assertStringContainsString("/assets/js/shortcuts.js", $view, $name);
        }
        // shortcuts.js comes before the scripts that ask it
        $this->assertLessThan(strpos($editor, 'editor-svg.js'), strpos($editor, 'shortcuts.js'));
        $this->assertLessThan(strpos($layout, 'palette.js'), strpos($layout, 'shortcuts.js'));
        $this->assertStringContainsString('href="/account/shortcuts"', $layout);
        $this->assertStringContainsString('href="/account/shortcuts"', $editor);
    }

    public function testScriptsAskForTheUsersKeys(): void
    {
        $editor = self::read('src/public/assets/js/editor-svg.js');
        $viewer = self::read('src/public/assets/js/viewer.js');
        $palette = self::read('src/public/assets/js/palette.js');
        $actions = array_keys(KeyboardShortcuts::ACTIONS);
        foreach ($actions as $id) {
            $where = match (true) {
                str_starts_with($id, 'editor.') => $editor,
                str_starts_with($id, 'viewer.') => $viewer,
                default => $palette,
            };
            $this->assertStringContainsString("'" . $id . "'", $where, $id . ' is never read');
        }
        // no key tested by hand any more in the editor's shortcuts
        $this->assertDoesNotMatchRegularExpression("/ctrl && \\(k === 'z'/", $editor);
    }

    public function testEveryDataKeysAttributeNamesAnAction(): void
    {
        foreach (['src/app/Views/editor/svg.php', 'src/app/Views/maps/view.php', 'src/app/Views/layouts/main.php'] as $view) {
            preg_match_all('/data-keys="([^"]+)"/', self::read($view), $m);
            $this->assertNotEmpty($m[1], $view);
            foreach ($m[1] as $id) {
                $this->assertArrayHasKey($id, KeyboardShortcuts::ACTIONS, $view . ': ' . $id);
            }
        }
    }
}
