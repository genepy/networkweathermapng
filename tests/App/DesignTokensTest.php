<?php

declare(strict_types=1);

namespace Tests\App;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The colour tokens of src/public/assets/css/tokens.css meet WCAG 2.2
 * contrast in both themes: 4.5:1 for text (1.4.3), 3:1 for the edges of
 * fields and controls and for the focus ring (1.4.11).
 */
final class DesignTokensTest extends TestCase
{
    /** [foreground, background, minimum ratio] */
    private const PAIRS = [
        // Text
        ['--wm-text', '--wm-bg', 4.5],
        ['--wm-text', '--wm-surface', 4.5],
        ['--wm-text', '--wm-surface-2', 4.5],
        ['--wm-text', '--wm-hover', 4.5],
        ['--wm-text', '--wm-input-bg', 4.5],
        ['--wm-text', '--wm-accent-soft', 4.5],
        ['--wm-text-muted', '--wm-bg', 4.5],
        ['--wm-text-muted', '--wm-surface', 4.5],
        ['--wm-text-muted', '--wm-surface-2', 4.5],
        ['--wm-text-muted', '--wm-hover', 4.5],
        ['--wm-on-header', '--wm-header', 4.5],
        // Links and coloured text
        ['--wm-accent', '--wm-bg', 4.5],
        ['--wm-accent', '--wm-surface', 4.5],
        ['--wm-success', '--wm-surface', 4.5],
        ['--wm-danger', '--wm-surface', 4.5],
        ['--wm-warning', '--wm-surface', 4.5],
        ['--wm-info', '--wm-surface', 4.5],
        // Filled buttons
        ['--wm-on-accent', '--wm-accent', 4.5],
        ['--wm-on-accent', '--wm-accent-hover', 4.5],
        ['--wm-on-fill', '--wm-success-fill', 4.5],
        ['--wm-on-fill', '--wm-success-fill-hover', 4.5],
        ['--wm-on-fill', '--wm-danger-fill', 4.5],
        ['--wm-on-fill', '--wm-danger-fill-hover', 4.5],
        // Messages and badges
        ['--wm-success', '--wm-success-soft', 4.5],
        ['--wm-danger-text', '--wm-danger-soft', 4.5],
        ['--wm-warning-text', '--wm-warning-soft', 4.5],
        ['--wm-info', '--wm-info-soft', 4.5],
        ['--wm-accent-hover', '--wm-accent-soft', 4.5],
        // Edges of fields and controls, focus ring
        ['--wm-field-border', '--wm-surface', 3.0],
        ['--wm-field-border', '--wm-bg', 3.0],
        ['--wm-field-border', '--wm-input-bg', 3.0],
        ['--wm-focus', '--wm-surface', 3.0],
        ['--wm-focus', '--wm-bg', 3.0],
    ];

    /** @return array<string, array{string}> */
    public static function themes(): array
    {
        return ['light' => ['light'], 'dark' => ['dark']];
    }

    #[DataProvider('themes')]
    public function testPairsMeetContrast(string $theme): void
    {
        $tokens = self::tokens()[$theme];
        $failures = [];
        foreach (self::PAIRS as [$fg, $bg, $min]) {
            $this->assertArrayHasKey($fg, $tokens, "$fg is missing in the $theme theme");
            $this->assertArrayHasKey($bg, $tokens, "$bg is missing in the $theme theme");
            $ratio = self::ratio($tokens[$fg], $tokens[$bg]);
            if ($ratio < $min) {
                $failures[] = sprintf('%s %s on %s %s: %.2f < %.1f', $fg, $tokens[$fg], $bg, $tokens[$bg], $ratio, $min);
            }
        }
        $this->assertSame([], $failures);
    }

    public function testBothDarkBlocksAreTheSame(): void
    {
        $css = self::css();
        $this->assertSame(1, preg_match('/@media \(prefers-color-scheme: dark\)\s*\{\s*:root:not\(\[data-theme="light"\]\)\s*\{([^}]*)\}/', $css, $system));
        $this->assertSame(1, preg_match('/:root\[data-theme="dark"\]\s*\{([^}]*)\}/', $css, $toggle));
        $this->assertSame(self::declarations($system[1]), self::declarations($toggle[1]));
    }

    public function testEveryPageLoadsTheTokensFirst(): void
    {
        $views = dirname(TESTS_ROOT) . '/src/app/Views';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($views, \FilesystemIterator::SKIP_DOTS));
        $checked = 0;
        foreach ($iterator as $file) {
            $html = (string) file_get_contents((string) $file);
            if (!preg_match('#href="/assets/css/(weathermap|editor-svg)\.css"#', $html, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $checked++;
            $tokens = strpos($html, 'href="/assets/css/tokens.css"');
            $this->assertNotFalse($tokens, $file . ' does not load tokens.css');
            $this->assertLessThan($m[0][1], $tokens, $file . ' loads tokens.css after ' . $m[1][0] . '.css');
        }
        $this->assertGreaterThan(0, $checked);
    }

    /** @return array{light: array<string, string>, dark: array<string, string>} */
    private static function tokens(): array
    {
        $css = self::css();
        preg_match('/^:root\s*\{([^}]*)\}/m', $css, $light);
        preg_match('/:root\[data-theme="dark"\]\s*\{([^}]*)\}/', $css, $dark);
        $lightTokens = self::declarations($light[1] ?? '');
        // The dark theme only lists what changes
        return ['light' => $lightTokens, 'dark' => array_merge($lightTokens, self::declarations($dark[1] ?? ''))];
    }

    /** @return array<string, string> */
    private static function declarations(string $block): array
    {
        $block = (string) preg_replace('#/\*.*?\*/#s', '', $block);
        preg_match_all('/(--wm-[a-z0-9-]+)\s*:\s*([^;]+);/', $block, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $d) {
            $out[$d[1]] = trim($d[2]);
        }
        return $out;
    }

    private static function css(): string
    {
        return (string) file_get_contents(dirname(TESTS_ROOT) . '/src/public/assets/css/tokens.css');
    }

    private static function ratio(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private static function luminance(string $hex): float
    {
        if (!preg_match('/^#([0-9a-f]{6})$/i', $hex, $m)) {
            throw new \InvalidArgumentException("Not a #rrggbb colour: $hex");
        }
        $channels = array_map(static function (string $c): float {
            $v = hexdec($c) / 255;
            return $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, str_split($m[1], 2));
        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
