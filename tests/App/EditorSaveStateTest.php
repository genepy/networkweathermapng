<?php

declare(strict_types=1);

namespace Tests\App;

use PHPUnit\Framework\TestCase;

/**
 * The save state of the SVG editor's top bar keeps one width (feedback
 * 2026-11-06, P5): every label the script can show is also laid out, hidden,
 * in the same cell, so the widest one sets the width in any language.
 */
final class EditorSaveStateTest extends TestCase
{
    private static function read(string $path): string
    {
        return (string) file_get_contents(dirname(TESTS_ROOT) . '/' . $path);
    }

    public function testEveryLabelOfTheSaveStateReservesItsWidth(): void
    {
        $script = self::read('src/public/assets/js/editor-svg.js');
        $this->assertSame(1, preg_match("/querySelector\\('\\.save-text'\\)\\.textContent = \\{([^}]*)\\}/", $script, $m));
        preg_match_all("/t\\('([^']+)'\\)/", $m[1], $labels);
        $this->assertCount(4, $labels[1]);

        $view = self::read('src/app/Views/editor/svg.php');
        $this->assertStringContainsString('<span class="save-text">', $view);
        foreach ($labels[1] as $label) {
            $this->assertStringContainsString(
                '<span class="save-ghost" aria-hidden="true"><?= e__(\'' . $label . '\') ?></span>',
                $view,
                $label . ' has no hidden copy, so showing it can move the top bar'
            );
        }
    }
}
