<?php
/**
 * Undo and redo for a map's .conf file.
 *
 * Before each change the editor saves the current file here. Undo puts the
 * last saved version back and keeps the replaced one for redo. The history
 * lives on disk, so it survives a page reload.
 */

declare(strict_types=1);

namespace App\Services\Editor;

class ConfigHistory
{
    public const LIMIT = 100;

    private string $dir;
    private string $file;

    public function __construct(string $historyDir, string $configFile)
    {
        $this->dir = rtrim($historyDir, '/');
        $this->file = $configFile;
    }

    /**
     * Remember a version of the file: by default the file as it is now,
     * before it changes, or the content it had before a change just made.
     */
    public function record(?string $content = null): void
    {
        if ($content === null) {
            if (!is_file($this->file)) {
                return;
            }
            $content = (string) file_get_contents($this->file);
        }

        $this->push('undo', $content);
        $this->clear('redo');
        $this->trim('undo');
    }

    public function undo(): bool
    {
        return $this->swap('undo', 'redo');
    }

    public function redo(): bool
    {
        return $this->swap('redo', 'undo');
    }

    public function counts(): array
    {
        return ['undo' => count($this->entries('undo')), 'redo' => count($this->entries('redo'))];
    }

    private function swap(string $from, string $to): bool
    {
        $entries = $this->entries($from);
        if (!$entries) {
            return false;
        }

        $last = end($entries);
        $previous = (string) file_get_contents($last);

        $this->push($to, is_file($this->file) ? (string) file_get_contents($this->file) : '');
        file_put_contents($this->file, $previous, LOCK_EX);
        unlink($last);
        clearstatcache(true, $this->file);

        return true;
    }

    private function push(string $stack, string $content): void
    {
        $dir = $this->dir . '/' . $stack;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $entries = $this->entries($stack);
        $next = $entries ? ((int) basename(end($entries), '.conf')) + 1 : 1;
        file_put_contents(sprintf('%s/%08d.conf', $dir, $next), $content, LOCK_EX);
    }

    private function entries(string $stack): array
    {
        $files = glob($this->dir . '/' . $stack . '/*.conf') ?: [];
        sort($files);
        return $files;
    }

    private function clear(string $stack): void
    {
        foreach ($this->entries($stack) as $file) {
            unlink($file);
        }
    }

    private function trim(string $stack): void
    {
        $entries = $this->entries($stack);
        while (count($entries) > self::LIMIT) {
            unlink(array_shift($entries));
        }
    }
}
