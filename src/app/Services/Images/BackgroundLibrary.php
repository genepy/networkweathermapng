<?php
/**
 * NetworkWeathermapNG - the map backgrounds of public/backgrounds, for the
 * editor's background picker. Backgrounds can be added and removed when no
 * map uses them.
 *
 * A background is a PNG, JPEG or GIF image: the map engine (GD) draws it
 * under the map, so vector images are not accepted. Added backgrounds go to
 * the top of backgrounds/; images in sub-folders are listed too.
 */

declare(strict_types=1);

namespace App\Services\Images;

use RuntimeException;

class BackgroundLibrary
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    /** Longest side accepted: a larger image would use a lot of memory in GD */
    public const MAX_SIDE = 4096;

    private const TYPES = ['png' => IMAGETYPE_PNG, 'gif' => IMAGETYPE_GIF, 'jpg' => IMAGETYPE_JPEG, 'jpeg' => IMAGETYPE_JPEG];

    /** @var array<string, true> paths of the backgrounds shipped with the project */
    private array $shipped;

    /**
     * @param string[]|null $shipped backgrounds that cannot be removed (default: shipped-backgrounds.php)
     */
    public function __construct(private string $publicPath, private string $configsPath = '', ?array $shipped = null)
    {
        $this->shipped = array_fill_keys($shipped ?? self::shippedBackgrounds(), true);
    }

    /** @return string[] */
    public static function shippedBackgrounds(): array
    {
        $list = require __DIR__ . '/shipped-backgrounds.php';
        return is_array($list) ? $list : [];
    }

    /** Whether a background came with the project, so a deployment would bring it back */
    public function isShipped(string $path): bool
    {
        return isset($this->shipped[$path]);
    }

    /**
     * The backgrounds sorted by name, each with its size and the maps that use it:
     * [['path' => 'backgrounds/uk1024.png', 'name' => 'uk1024', 'width' => 1024, 'height' => 768,
     *   'bytes' => 12345, 'shipped' => true, 'used_by' => ['core']], ...]
     */
    public function all(): array
    {
        $uses = $this->uses();
        $out = [];
        foreach ($this->paths() as $path) {
            $file = $this->publicPath . '/' . $path;
            $info = @getimagesize($file);
            $out[] = [
                'path' => $path,
                'name' => pathinfo($path, PATHINFO_FILENAME),
                'width' => $info ? (int) $info[0] : 0,
                'height' => $info ? (int) $info[1] : 0,
                'bytes' => (int) @filesize($file),
                'shipped' => $this->isShipped($path),
                'used_by' => $uses[strtolower($path)] ?? [],
            ];
        }
        usort($out, static fn ($a, $b) => strnatcasecmp($a['name'], $b['name']) ?: strcmp($a['path'], $b['path']));
        return $out;
    }

    /** Paths of the backgrounds the engine can draw (backgrounds/... .png|.gif|.jpg) */
    public function paths(): array
    {
        $dir = $this->publicPath . '/backgrounds';
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (array_merge([''], array_map('basename', glob($dir . '/*', GLOB_ONLYDIR) ?: [])) as $sub) {
            foreach (scandir($sub === '' ? $dir : $dir . '/' . $sub) ?: [] as $file) {
                if (preg_match('/\.(gif|jpe?g|png)$/i', $file)) {
                    $out[] = 'backgrounds/' . ($sub === '' ? '' : $sub . '/') . $file;
                }
            }
        }
        sort($out);
        return $out;
    }

    /** Add a background. Returns its path, as a map's BACKGROUND line uses it. */
    public function add(string $fileName, string $data): string
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $base = self::cleanName(pathinfo($fileName, PATHINFO_FILENAME));

        if (strlen($data) > self::MAX_BYTES) {
            throw new RuntimeException(__('The file is too big (10 MB at most)'));
        }
        if (!isset(self::TYPES[$ext])) {
            throw new RuntimeException(__('Only PNG, JPEG and GIF backgrounds are accepted'));
        }
        $info = @getimagesizefromstring($data);
        if (!$info || $info[2] !== self::TYPES[$ext]) {
            throw new RuntimeException(__('not a valid image'));
        }
        if ($info[0] > self::MAX_SIDE || $info[1] > self::MAX_SIDE) {
            throw new RuntimeException(__('The image is too big (4096 px at most)'));
        }

        $dir = $this->publicPath . '/backgrounds';
        foreach (array_keys(self::TYPES) as $other) {
            if (file_exists($dir . '/' . $base . '.' . $other)) {
                throw new RuntimeException(__('A background named :name already exists', ['name' => $base]));
            }
        }
        $name = $base . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        if ((!is_dir($dir) && !@mkdir($dir, 0775, true)) || file_put_contents($dir . '/' . $name, $data, LOCK_EX) === false) {
            throw new RuntimeException(__('The background could not be saved'));
        }

        return 'backgrounds/' . $name;
    }

    /**
     * Remove a background. Refused while a map uses it, and for the
     * backgrounds shipped with the project.
     */
    public function delete(string $path): void
    {
        $path = $this->checkPath($path);
        $maps = $this->usedBy($path);
        if ($maps) {
            throw new RuntimeException(__('This background is used by: :maps. Change those maps first.', ['maps' => implode(', ', $maps)]));
        }
        if ($this->isShipped($path)) {
            throw new RuntimeException(__('This background comes with NetworkWeathermapNG and cannot be removed'));
        }
        if (!@unlink($this->publicPath . '/' . $path)) {
            throw new RuntimeException(__('The background could not be removed'));
        }
    }

    /** Names of the map files (.conf) that use a background */
    public function usedBy(string $path): array
    {
        return $this->uses()[strtolower($path)] ?? [];
    }

    /**
     * The backgrounds of every map, read in one pass:
     * lower-case path => names of the maps. A BACKGROUND line may carry an
     * older prefix (images/backgrounds/...), which is dropped.
     *
     * @return array<string, string[]>
     */
    private function uses(): array
    {
        if ($this->configsPath === '' || !is_dir($this->configsPath)) {
            return [];
        }
        $out = [];
        foreach (glob($this->configsPath . '/*.conf') ?: [] as $file) {
            if (!preg_match_all('/^\s*BACKGROUND\s+(\S+)\s*$/mi', (string) file_get_contents($file), $m)) {
                continue;
            }
            foreach (array_unique($m[1]) as $bg) {
                $at = strripos($bg, 'backgrounds/');
                if ($at !== false) {
                    $out[strtolower(substr($bg, $at))][] = basename($file, '.conf');
                }
            }
        }
        return $out;
    }

    private function checkPath(string $path): string
    {
        $path = trim($path);
        if (!preg_match('#^backgrounds/([A-Za-z0-9][A-Za-z0-9_-]*/)?[A-Za-z0-9][A-Za-z0-9._ -]*\.(png|gif|jpe?g)$#i', $path) || str_contains($path, '..')) {
            throw new RuntimeException(__('Invalid background: :value', ['value' => $path]));
        }
        if (!is_file($this->publicPath . '/' . $path)) {
            throw new RuntimeException(__('Background not found'));
        }
        return $path;
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
}
