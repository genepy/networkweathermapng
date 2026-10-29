<?php
/**
 * NetworkWeathermapNG - the node icons of public/objects, sorted by theme,
 * for the editor palette. Icons can be added (into a theme folder) and
 * removed when no map uses them.
 *
 * A theme is a sub-folder of objects/. Icons at the top of objects/ get a
 * theme from their name (cisco-router.png is in "cisco"), so the old flat
 * library reads like the folders. An SVG icon always has a PNG twin next to
 * it, because the map engine (GD) draws the PNG.
 */

declare(strict_types=1);

namespace App\Services\Images;

use RuntimeException;

class IconLibrary
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    /** Longest side of the PNG drawn from an added SVG: the size of the node icon */
    public const SVG_ICON_SIDE = 64;

    private const BITMAPS = ['png' => IMAGETYPE_PNG, 'gif' => IMAGETYPE_GIF, 'jpg' => IMAGETYPE_JPEG, 'jpeg' => IMAGETYPE_JPEG];

    /** Name prefixes of the icons at the top of objects/, and their theme */
    private const PREFIXES = [
        'arista' => 'arista', 'cisco' => 'cisco', 'cumulus' => 'cumulus', 'f5' => 'f5',
        'juniper' => 'juniper', 'viptela' => 'viptela', 'vmware' => 'vmware', 'generic' => 'generic',
        'cloud' => 'clouds', 'blue-ball' => 'status', 'green-ball' => 'status', 'grey-ball' => 'status',
        'red-ball' => 'status', 'yellow-ball' => 'status', 'updown' => 'status', 'hollow' => 'status',
    ];

    private SvgRasterizer $rasterizer;

    public function __construct(private string $publicPath, private string $configsPath = '', ?SvgRasterizer $rasterizer = null)
    {
        $this->rasterizer = $rasterizer ?? new SvgRasterizer();
    }

    /** The themes drawn for the project, shown before the vendor sets */
    private const FIRST_THEMES = ['net', 'classic', 'status', 'clouds', 'generic'];

    /**
     * Themes, the project's own first and then by name, each with its icons:
     * [['theme' => 'cisco', 'icons' => [['path' => 'objects/cisco-router.png', 'name' => 'cisco-router', 'preview' => 'objects/cisco-router.svg'], ...]], ...]
     */
    public function themes(): array
    {
        $themes = [];
        foreach ($this->paths() as $path) {
            $themes[self::themeOf($path)][] = [
                'path' => $path,
                'name' => pathinfo($path, PATHINFO_FILENAME),
                'preview' => is_file($this->publicPath . '/' . self::svgTwin($path)) ? self::svgTwin($path) : $path,
            ];
        }
        uksort($themes, static function ($a, $b): int {
            $ia = array_search($a, self::FIRST_THEMES, true);
            $ib = array_search($b, self::FIRST_THEMES, true);
            if ($ia !== false || $ib !== false) {
                return ($ia === false ? PHP_INT_MAX : $ia) <=> ($ib === false ? PHP_INT_MAX : $ib);
            }
            return strnatcasecmp((string) $a, (string) $b);
        });

        $out = [];
        foreach ($themes as $theme => $icons) {
            usort($icons, static fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
            $out[] = ['theme' => (string) $theme, 'icons' => $icons];
        }
        return $out;
    }

    /** Paths of the icons the engine can draw (objects/... .png|.gif|.jpg) */
    public function paths(): array
    {
        $dir = $this->publicPath . '/objects';
        $out = [];
        foreach (array_merge([''], array_map('basename', glob($dir . '/*', GLOB_ONLYDIR) ?: [])) as $sub) {
            foreach (scandir($sub === '' ? $dir : $dir . '/' . $sub) ?: [] as $file) {
                if (preg_match('/\.(gif|jpe?g|png)$/i', $file)) {
                    $out[] = 'objects/' . ($sub === '' ? '' : $sub . '/') . $file;
                }
            }
        }
        sort($out);
        return $out;
    }

    public static function themeOf(string $path): string
    {
        $rel = substr($path, strlen('objects/'));
        if (str_contains($rel, '/')) {
            return strtolower(explode('/', $rel)[0]);
        }
        $name = strtolower(pathinfo($rel, PATHINFO_FILENAME));
        foreach (self::PREFIXES as $prefix => $theme) {
            if (str_starts_with($name, $prefix)) {
                return $theme;
            }
        }
        return 'classic';
    }

    /**
     * Add an icon to a theme. Returns the path of the icon the maps use
     * (the PNG twin for an SVG).
     */
    public function add(string $theme, string $fileName, string $data): string
    {
        $theme = self::cleanTheme($theme);
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $base = self::cleanName(pathinfo($fileName, PATHINFO_FILENAME));

        if (strlen($data) > self::MAX_BYTES) {
            throw new RuntimeException(__('The file is too big (2 MB at most)'));
        }
        if ($ext !== 'svg' && !isset(self::BITMAPS[$ext])) {
            throw new RuntimeException(__('Only PNG, GIF, JPEG and SVG icons are accepted'));
        }

        $dir = $this->publicPath . '/objects/' . $theme;
        $files = [];
        if ($ext === 'svg') {
            $clean = (new SvgSanitizer())->sanitize(self::withoutSvgDoctype($data));
            $png = $this->rasterizer->toPng($clean, self::SVG_ICON_SIDE);
            if ($png === null) {
                throw new RuntimeException($this->rasterizer->available()
                    ? __('the SVG could not be converted to PNG')
                    : __('SVG needs rsvg-convert or ImageMagick on the server to make its PNG copy'));
            }
            $files[$base . '.svg'] = $clean;
            $files[$base . '.png'] = $png;
            $icon = $base . '.png';
        } else {
            $info = @getimagesizefromstring($data);
            if (!$info || $info[2] !== self::BITMAPS[$ext]) {
                throw new RuntimeException(__('not a valid image'));
            }
            if ($info[0] > SvgRasterizer::MAX_SIDE || $info[1] > SvgRasterizer::MAX_SIDE) {
                throw new RuntimeException(__('The image is too big (1024 px at most)'));
            }
            $ext = $ext === 'jpeg' ? 'jpg' : $ext;
            $files[$base . '.' . $ext] = $data;
            $icon = $base . '.' . $ext;
        }

        if ($this->sameName($dir, $base)) {
            throw new RuntimeException(__('An icon named :name is already in this theme', ['name' => $base]));
        }
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            throw new RuntimeException(__('The icon could not be saved'));
        }
        foreach ($files as $name => $content) {
            if (file_put_contents($dir . '/' . $name, $content, LOCK_EX) === false) {
                throw new RuntimeException(__('The icon could not be saved'));
            }
        }

        return 'objects/' . $theme . '/' . $icon;
    }

    /**
     * Remove an icon and its SVG twin. Refused while a map uses it.
     */
    public function delete(string $path): void
    {
        $path = $this->checkPath($path);
        $maps = $this->usedBy($path);
        if ($maps) {
            throw new RuntimeException(__('This icon is used by: :maps. Change those nodes first.', ['maps' => implode(', ', $maps)]));
        }

        foreach ([$path, self::svgTwin($path)] as $rel) {
            $file = $this->publicPath . '/' . $rel;
            if (is_file($file) && !@unlink($file)) {
                throw new RuntimeException(__('The icon could not be removed'));
            }
        }

        // an emptied theme folder goes too
        $dir = dirname($this->publicPath . '/' . $path);
        if ($dir !== $this->publicPath . '/objects' && count(scandir($dir) ?: []) <= 2) {
            @rmdir($dir);
        }
    }

    /** Names of the map files (.conf) that use an icon */
    public function usedBy(string $path): array
    {
        if ($this->configsPath === '' || !is_dir($this->configsPath)) {
            return [];
        }
        $pattern = '/^\s*ICON\s+(?:\d+\s+\d+\s+)?(?:\S*\/)?' . preg_quote($path, '/') . '\s*$/mi';
        $out = [];
        foreach (glob($this->configsPath . '/*.conf') ?: [] as $file) {
            if (preg_match($pattern, (string) file_get_contents($file))) {
                $out[] = basename($file, '.conf');
            }
        }
        return $out;
    }

    public static function svgTwin(string $path): string
    {
        return (string) preg_replace('/\.(png|gif|jpe?g)$/i', '.svg', $path);
    }

    private function checkPath(string $path): string
    {
        $path = trim($path);
        if (!preg_match('#^objects/([a-z0-9][a-z0-9_-]*/)?[A-Za-z0-9][A-Za-z0-9._ -]*\.(png|gif|jpe?g)$#i', $path) || str_contains($path, '..')) {
            throw new RuntimeException(__('Invalid icon: :value', ['value' => $path]));
        }
        if (!is_file($this->publicPath . '/' . $path)) {
            throw new RuntimeException(__('Icon not found'));
        }
        return $path;
    }

    private function sameName(string $dir, string $base): bool
    {
        foreach (['png', 'gif', 'jpg', 'jpeg', 'svg'] as $ext) {
            if (file_exists($dir . '/' . $base . '.' . $ext)) {
                return true;
            }
        }
        return false;
    }

    public static function cleanTheme(string $theme): string
    {
        $theme = strtolower(trim($theme));
        $theme = (string) preg_replace('/[^a-z0-9_-]+/', '-', $theme);
        $theme = trim($theme, '-_');
        if ($theme === '' || strlen($theme) > 32) {
            throw new RuntimeException(__('A theme name is required (letters, digits, - and _, 32 at most)'));
        }
        return $theme;
    }

    private static function cleanName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = (string) preg_replace('/[^a-z0-9._-]+/', '-', $name);
        $name = trim($name, '-_.');
        if ($name === '' || strlen($name) > 64) {
            throw new RuntimeException(__('unsupported file name'));
        }
        return $name;
    }

    /**
     * Drawing tools often add the public SVG 1.0 or 1.1 DOCTYPE. It declares
     * nothing the icon needs, so it is dropped; any other DOCTYPE, or one
     * with declarations of its own, is still refused by the sanitizer.
     */
    private static function withoutSvgDoctype(string $svg): string
    {
        return (string) preg_replace(
            '~<!DOCTYPE\s+svg\s+PUBLIC\s+"-//W3C//DTD SVG 1\.[01]//EN"\s+"http://www\.w3\.org/(Graphics/SVG/1\.1/DTD/svg11\.dtd|TR/2001/REC-SVG-20010904/DTD/svg10\.dtd)"\s*>~',
            '',
            $svg,
            1
        );
    }
}
