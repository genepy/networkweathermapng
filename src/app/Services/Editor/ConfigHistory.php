<?php
/**
 * Undo and redo for a map's .conf file.
 *
 * Before each change the editor saves the current file here. Undo puts the
 * last saved version back and keeps the replaced one for redo. The history
 * lives on disk, so it survives a page reload.
 *
 * Each step also remembers a fingerprint of the file it left behind. If the
 * file was saved by something else since then (the text editor of the
 * classic editor, a rename, a copy on disk), the step is refused instead of
 * overwriting that work.
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
     * Remember a version of the file. With a content: the file had it before
     * a change just made, and the file as it is now is what the step left.
     * Without one: the file as it is now, before it changes; what the step
     * leaves is then unknown and its undo is not checked.
     */
    public function record(?string $content = null): void
    {
        $after = null;
        if ($content === null) {
            if (!is_file($this->file)) {
                return;
            }
            $content = (string) file_get_contents($this->file);
        } else {
            $after = $this->current();
        }

        $this->push('undo', $content, $after);
        $this->clear('redo');
        $this->trim('undo');
    }

    /** False when there is nothing to undo or the map was saved elsewhere since */
    public function undo(): bool
    {
        return $this->swap('undo', 'redo');
    }

    /** False when there is nothing to redo or the map was saved elsewhere since */
    public function redo(): bool
    {
        return $this->swap('redo', 'undo');
    }

    public function counts(): array
    {
        return ['undo' => count($this->entries('undo')), 'redo' => count($this->entries('redo'))];
    }

    /**
     * Whether the next undo or redo is refused because the file was saved
     * by something else since that step.
     *
     * @return array{undo: bool, redo: bool}
     */
    public function changedOutside(): array
    {
        $current = null;
        $blocked = [];
        foreach (['undo', 'redo'] as $stack) {
            $entries = $this->entries($stack);
            $expected = $entries ? $this->after(end($entries)) : null;
            if ($expected !== null) {
                $current ??= sha1($this->current());
            }
            $blocked[$stack] = $expected !== null && $expected !== $current;
        }
        return $blocked;
    }

    private function swap(string $from, string $to): bool
    {
        $entries = $this->entries($from);
        if (!$entries) {
            return false;
        }

        $last = end($entries);
        $current = $this->current();
        $expected = $this->after($last);
        if ($expected !== null && $expected !== sha1($current)) {
            return false;
        }

        $previous = (string) file_get_contents($last);

        $this->push($to, $current, $previous);
        file_put_contents($this->file, $previous, LOCK_EX);
        $this->remove($last);
        clearstatcache(true, $this->file);

        return true;
    }

    /** The file's content now ('' when it does not exist) */
    private function current(): string
    {
        clearstatcache(true, $this->file);
        return is_file($this->file) ? (string) file_get_contents($this->file) : '';
    }

    /** The fingerprint of the file the step left, or null when unknown */
    private function after(string $entry): ?string
    {
        $file = $this->afterFile($entry);
        $hash = is_file($file) ? trim((string) file_get_contents($file)) : '';
        return $hash !== '' ? $hash : null;
    }

    private function afterFile(string $entry): string
    {
        return substr($entry, 0, -strlen('.conf')) . '.after';
    }

    /** @param ?string $after the content the file has once this step is done, when known */
    private function push(string $stack, string $content, ?string $after = null): void
    {
        $dir = $this->dir . '/' . $stack;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $entries = $this->entries($stack);
        $next = $entries ? ((int) basename(end($entries), '.conf')) + 1 : 1;
        $entry = sprintf('%s/%08d.conf', $dir, $next);
        file_put_contents($entry, $content, LOCK_EX);
        if ($after !== null) {
            file_put_contents($this->afterFile($entry), sha1($after), LOCK_EX);
        }
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
            $this->remove($file);
        }
    }

    private function trim(string $stack): void
    {
        $entries = $this->entries($stack);
        while (count($entries) > self::LIMIT) {
            $this->remove(array_shift($entries));
        }
    }

    private function remove(string $entry): void
    {
        unlink($entry);
        if (is_file($this->afterFile($entry))) {
            unlink($this->afterFile($entry));
        }
    }
}
