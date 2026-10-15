<?php
/**
 * Takes uploaded files (.conf, or a .zip of .conf files and images) apart:
 * maps go to the MigrationStore, images to the icon and background folders.
 * SVG images are cleaned (SvgSanitizer) and get the PNG copy the map
 * engine draws from.
 */

declare(strict_types=1);

namespace App\Services\Migration;

use App\Services\Images\SvgRasterizer;
use App\Services\Images\SvgSanitizer;

class MigrationImporter
{
    public const MAX_CONF = 2 * 1024 * 1024;
    public const MAX_IMAGE = 5 * 1024 * 1024;
    public const MAX_ZIP_TOTAL = 100 * 1024 * 1024;
    public const MAX_ZIP_ENTRIES = 5000;
    private const IMAGE_TYPES = ['png' => IMAGETYPE_PNG, 'jpg' => IMAGETYPE_JPEG, 'jpeg' => IMAGETYPE_JPEG, 'gif' => IMAGETYPE_GIF];

    private SvgRasterizer $rasterizer;

    public function __construct(private MigrationStore $store, private string $publicPath, ?SvgRasterizer $rasterizer = null)
    {
        $this->rasterizer = $rasterizer ?? new SvgRasterizer();
    }

    /**
     * @param array<string, string> $files original file name => path on disk
     * @return array{migrations: list<string>, images: list<string>, skipped: list<string>}
     */
    public function import(array $files): array
    {
        $confs = [];
        $images = [];
        $skipped = [];

        foreach ($files as $name => $path) {
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($ext === 'zip') {
                $this->readZip($path, $confs, $images, $skipped);
            } elseif ($this->isImage($ext)) {
                $images[basename($name)] = (string) file_get_contents($path);
            } elseif (filesize($path) <= self::MAX_CONF && $this->looksLikeConf((string) file_get_contents($path))) {
                $confs[basename($name)] = (string) file_get_contents($path);
            } else {
                $skipped[] = __(':file: not a Weathermap file', ['file' => basename($name)]);
            }
        }

        // an image named as a BACKGROUND goes with the backgrounds
        $backgrounds = [];
        foreach ($confs as $conf) {
            if (preg_match_all('/^\s*BACKGROUND\s+(\S+)/mi', $conf, $m)) {
                foreach ($m[1] as $bg) {
                    $backgrounds[strtolower(basename($bg))] = true;
                }
            }
        }

        $placed = [];
        foreach ($images as $name => $data) {
            $folder = isset($backgrounds[strtolower($name)]) ? 'backgrounds' : 'objects';
            $result = $this->placeImage($folder, $name, $data);
            if ($result === true) {
                $placed[] = $folder . '/' . $name;
            } elseif ($result !== null) {
                $skipped[] = $name . ': ' . $result;
            }
        }

        $ids = [];
        foreach ($confs as $file => $conf) {
            $title = preg_match('/^\s*TITLE\s+(.+)$/mi', $conf, $m) ? trim($m[1]) : '';
            $ids[] = $this->store->create($title !== '' ? $title : pathinfo($file, PATHINFO_FILENAME), $file, $conf);
        }

        return ['migrations' => $ids, 'images' => $placed, 'skipped' => $skipped];
    }

    private function readZip(string $path, array &$confs, array &$images, array &$skipped): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            $skipped[] = __('The zip file could not be opened');
            return;
        }
        if ($zip->numFiles > self::MAX_ZIP_ENTRIES) {
            $skipped[] = __('The zip file holds too many files');
            $zip->close();
            return;
        }

        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!$stat || str_ends_with($stat['name'], '/')) {
                continue;
            }
            $name = basename($stat['name']);
            if ($name === '' || $name[0] === '.' || str_contains($stat['name'], '__MACOSX')) {
                continue;
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $isImage = $this->isImage($ext);
            $limit = $isImage ? self::MAX_IMAGE : self::MAX_CONF;

            if ($stat['size'] > $limit || ($total += $stat['size']) > self::MAX_ZIP_TOTAL) {
                $skipped[] = __(':file: too large', ['file' => $name]);
                continue;
            }
            if (!$isImage && !in_array($ext, ['conf', 'cfg', 'txt', ''], true)) {
                continue;
            }

            $data = $zip->getFromIndex($i, $limit + 1);
            if ($data === false || strlen($data) > $limit) {
                $skipped[] = __(':file: could not be read', ['file' => $name]);
                continue;
            }

            if ($isImage) {
                $images[$name] = $data;
            } elseif ($this->looksLikeConf($data)) {
                $confs[$this->uniqueName($confs, $name)] = $data;
            }
        }
        $zip->close();
    }

    /** @return true|string|null true when written, null when the same file is there, else why not */
    private function placeImage(string $folder, string $name, string $data): bool|string|null
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._ -]*$/', $name)) {
            return __('unsupported file name');
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === 'svg') {
            return $this->placeSvg($folder, $name, $data);
        }
        $info = @getimagesizefromstring($data);
        if (!$info || $info[2] !== self::IMAGE_TYPES[$ext]) {
            return __('not a valid image');
        }

        return $this->writeFiles([$this->publicPath . '/' . $folder . '/' . $name => $data]);
    }

    /** The cleaned SVG and its PNG twin, written together or not at all */
    private function placeSvg(string $folder, string $name, string $data): bool|string|null
    {
        try {
            $clean = (new SvgSanitizer())->sanitize($data);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        $base = $this->publicPath . '/' . $folder . '/' . substr($name, 0, -4);
        $svgFile = $base . '.svg';
        if (is_file($svgFile) && hash_file('sha256', $svgFile) === hash('sha256', $clean)) {
            return null;
        }

        $png = $this->rasterizer->toPng($clean);
        if ($png === null) {
            return $this->rasterizer->available()
                ? __('the SVG could not be converted to PNG')
                : __('SVG needs rsvg-convert or ImageMagick on the server to make its PNG copy');
        }

        return $this->writeFiles([$svgFile => $clean, $base . '.png' => $png]);
    }

    /**
     * @param array<string, string> $files path => data
     * @return true|string|null true when written, null when the same files are there, else why not
     */
    private function writeFiles(array $files): bool|string|null
    {
        $same = true;
        foreach ($files as $target => $data) {
            if (is_file($target)) {
                if (hash_file('sha256', $target) !== hash('sha256', $data)) {
                    return __('a different file with this name is already there');
                }
            } else {
                $same = false;
            }
        }
        if ($same) {
            return null;
        }

        foreach ($files as $target => $data) {
            if (is_file($target)) {
                continue;
            }
            if (!is_dir(dirname($target))) {
                @mkdir(dirname($target), 0775, true);
            }
            if (file_put_contents($target, $data) === false) {
                return __('could not be written');
            }
        }
        return true;
    }

    private function isImage(string $ext): bool
    {
        return isset(self::IMAGE_TYPES[$ext]) || $ext === 'svg';
    }

    private function looksLikeConf(string $text): bool
    {
        return !str_contains($text, "\0") && preg_match('/^\s*(NODE|LINK)\s+\S+/mi', $text) === 1;
    }

    private function uniqueName(array $existing, string $name): string
    {
        $base = $name;
        for ($n = 2; isset($existing[$name]); $n++) {
            $name = pathinfo($base, PATHINFO_FILENAME) . '-' . $n . '.' . pathinfo($base, PATHINFO_EXTENSION);
        }
        return $name;
    }
}
