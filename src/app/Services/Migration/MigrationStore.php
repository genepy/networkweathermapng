<?php
/**
 * Imported maps waiting to be reviewed, one folder each:
 *   <dir>/<id>/original.conf   the file as it was uploaded
 *   <dir>/<id>/meta.json       name, choices, the map it became
 */

declare(strict_types=1);

namespace App\Services\Migration;

class MigrationStore
{
    public function __construct(private string $dir)
    {
    }

    public function create(string $name, string $file, string $conf): string
    {
        $id = bin2hex(random_bytes(6));
        $path = $this->path($id);
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new \RuntimeException('Cannot create ' . $path);
        }
        file_put_contents($path . '/original.conf', $conf);
        $this->write($id, [
            'id' => $id,
            'name' => $name,
            'file' => $file,
            'created' => date('Y-m-d H:i:s'),
            'sourceId' => null,
            'fonts' => true,
            'choices' => [],
            'mapId' => null,
            'applied' => null,
        ]);
        return $id;
    }

    /** @return list<array> newest first */
    public function all(): array
    {
        $out = [];
        foreach (glob($this->dir . '/*/meta.json') ?: [] as $file) {
            $meta = json_decode((string) file_get_contents($file), true);
            if (is_array($meta) && isset($meta['id'])) {
                $out[] = $meta;
            }
        }
        usort($out, fn ($a, $b) => strcmp($b['created'], $a['created']));
        return $out;
    }

    public function get(string $id): ?array
    {
        if (!self::validId($id) || !is_file($this->path($id) . '/meta.json')) {
            return null;
        }
        $meta = json_decode((string) file_get_contents($this->path($id) . '/meta.json'), true);
        return is_array($meta) ? $meta : null;
    }

    public function original(string $id): string
    {
        return self::validId($id) ? (string) @file_get_contents($this->path($id) . '/original.conf') : '';
    }

    public function update(string $id, array $changes): array
    {
        $meta = $this->get($id);
        if (!$meta) {
            throw new \InvalidArgumentException('Unknown migration');
        }
        $meta = array_merge($meta, $changes);
        $this->write($id, $meta);
        return $meta;
    }

    public function delete(string $id): void
    {
        if (!self::validId($id)) {
            return;
        }
        foreach (glob($this->path($id) . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->path($id));
    }

    /** The map was deleted: the migrations that created it can create it again */
    public function forgetMap(int $mapId): void
    {
        foreach ($this->all() as $meta) {
            if ((int) ($meta['mapId'] ?? 0) === $mapId) {
                $this->update($meta['id'], ['mapId' => null, 'applied' => null]);
            }
        }
    }

    public static function validId(string $id): bool
    {
        return (bool) preg_match('/^[a-f0-9]{12}$/', $id);
    }

    private function path(string $id): string
    {
        return $this->dir . '/' . $id;
    }

    private function write(string $id, array $meta): void
    {
        file_put_contents($this->path($id) . '/meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
