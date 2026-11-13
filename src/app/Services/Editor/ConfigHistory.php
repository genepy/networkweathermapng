<?php
/**
 * Undo and redo for a map's .conf file.
 *
 * Before each change the editor saves the current file here. Undo puts the
 * last saved version back and keeps the replaced one for redo. The history
 * lives on disk, so it survives a page reload.
 *
 * Each step also remembers a fingerprint of the file it left behind. If the
 * file was saved by something else since then (a rename, a copy on disk),
 * the step is refused instead of overwriting that work.
 *
 * Next to the undo and redo stacks, a timeline keeps the map's saved
 * versions with their time (versions/NNNNNNNN.conf and .json), in the
 * style of the VS Code Timeline: edits made within a minute of each other
 * share one version, the author names savepoints, and restoring a version
 * is itself a new version and an undo step, so it can be undone. Unnamed
 * versions past VERSION_LIMIT are dropped, savepoints are kept.
 */

declare(strict_types=1);

namespace App\Services\Editor;

class ConfigHistory
{
    public const LIMIT = 100;

    /** Unnamed versions kept in the timeline (savepoints are not counted) */
    public const VERSION_LIMIT = 200;

    /** Edits closer than this many seconds to the last version replace it */
    public const MERGE_SECONDS = 60;

    /** Longest savepoint name */
    public const NAME_LENGTH = 80;

    /** Kinds of versions: what made them */
    public const KINDS = ['start', 'outside', 'edit', 'import', 'restore', 'savepoint'];

    private string $dir;
    private string $file;
    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param ?\Closure(): int $clock the time now, for tests */
    public function __construct(string $historyDir, string $configFile, ?\Closure $clock = null)
    {
        $this->dir = rtrim($historyDir, '/');
        $this->file = $configFile;
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Remember a version of the file. With a content: the file had it before
     * a change just made, and the file as it is now is what the step left.
     * Without one: the file as it is now, before it changes; what the step
     * leaves is then unknown and its undo is not checked.
     */
    public function record(?string $content = null, string $kind = 'edit'): void
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

        if ($after !== null) {
            $this->track($content, $after, $kind);
        }
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
        $this->track($current, $previous, 'edit');

        return true;
    }

    // ------------------------------------------------------------------
    // Timeline

    /**
     * The saved versions, newest first. The newest is marked current when
     * the file still has its content; when the file changed since (saved
     * outside the editor, or never edited here), the list starts with an
     * entry without id for the file as it is now.
     *
     * @return list<array{id: ?string, kind: string, time: int, name: string, from: ?array, current: bool, size: int}>
     */
    public function versions(): array
    {
        $current = $this->current();
        $list = [];
        foreach (array_reverse($this->versionFiles()) as $file) {
            $meta = $this->meta($file);
            $list[] = [
                'id' => basename($file, '.conf'),
                'kind' => $meta['kind'],
                'time' => $meta['time'],
                'name' => $meta['name'],
                'from' => $meta['from'],
                'current' => false,
                'size' => (int) filesize($file),
            ];
        }

        if ($list && sha1((string) file_get_contents($this->versionFile($list[0]['id']))) === sha1($current)) {
            $list[0]['current'] = true;
        } elseif ($current !== '') {
            clearstatcache(true, $this->file);
            array_unshift($list, [
                'id' => null,
                'kind' => $list ? 'outside' : 'start',
                'time' => (int) filemtime($this->file),
                'name' => '',
                'from' => null,
                'current' => true,
                'size' => strlen($current),
            ]);
        }

        return $list;
    }

    /** The content of a version, or null when there is no such version */
    public function version(string $id): ?string
    {
        $file = $this->versionFile($id);
        return $file !== null && is_file($file) ? (string) file_get_contents($file) : null;
    }

    /** The details of one version (see versions()), or null */
    public function versionInfo(string $id): ?array
    {
        foreach ($this->versions() as $v) {
            if ($v['id'] === $id) {
                return $v;
            }
        }
        return null;
    }

    /**
     * Name the map as it is now. With $id, name that version instead (an
     * unnamed one becomes a savepoint, a savepoint is renamed). Returns the
     * id of the savepoint, or null when the version does not exist.
     */
    public function savepoint(string $name, ?string $id = null): ?string
    {
        $name = self::cleanName($name);
        if ($id !== null) {
            $file = $this->versionFile($id);
            if ($file === null || !is_file($file)) {
                return null;
            }
            $meta = $this->meta($file);
            $meta['name'] = $name;
            $this->writeMeta($file, $meta);
            return $id;
        }

        $current = $this->current();
        $versions = $this->versionFiles();
        if (!$versions || (string) file_get_contents((string) end($versions)) !== $current) {
            // the file changed since the last version: keep what it was too
            $this->addVersion($current, $versions ? 'outside' : 'start', ($this->clock)());
        }
        return $this->addVersion($current, 'savepoint', ($this->clock)(), $name);
    }

    /** Take the name off a version: a savepoint becomes an ordinary version */
    public function unname(string $id): bool
    {
        $file = $this->versionFile($id);
        if ($file === null || !is_file($file)) {
            return false;
        }
        $meta = $this->meta($file);
        $meta['name'] = '';
        if ($meta['kind'] === 'savepoint') {
            $meta['kind'] = 'edit';
        }
        $this->writeMeta($file, $meta);
        $this->trimVersions();
        return true;
    }

    /**
     * Put a version back as the map. The map as it was is kept as an undo
     * step and the restored content is a new version. Returns false when
     * there is no such version, or the map already has its content.
     */
    public function restore(string $id): bool
    {
        $file = $this->versionFile($id);
        if ($file === null || !is_file($file)) {
            return false;
        }
        $content = (string) file_get_contents($file);
        $current = $this->current();
        if ($content === $current) {
            return false;
        }
        $meta = $this->meta($file);

        file_put_contents($this->file, $content, LOCK_EX);
        clearstatcache(true, $this->file);
        $this->push('undo', $current, $content);
        $this->clear('redo');
        $this->trim('undo');
        $this->track($current, $content, 'restore', ['id' => $id, 'time' => $meta['time'], 'name' => $meta['name']]);

        return true;
    }

    /** A name as it is stored: one line, trimmed, NAME_LENGTH characters at most */
    public static function cleanName(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        return mb_substr($name, 0, self::NAME_LENGTH);
    }

    /**
     * Add the change from $before to $after to the timeline. When the last
     * version is not $before (first change, or a save made elsewhere), it
     * is added first so it can be found again.
     */
    private function track(string $before, string $after, string $kind, ?array $from = null): void
    {
        if (!in_array($kind, self::KINDS, true)) {
            $kind = 'edit';
        }
        $now = ($this->clock)();
        $versions = $this->versionFiles();
        $last = $versions ? (string) end($versions) : null;
        $lastContent = $last !== null ? (string) file_get_contents($last) : null;

        if ($lastContent !== $before) {
            $this->addVersion($before, $last === null ? 'start' : 'outside', $now);
            $last = null;
        }
        if ($before === $after) {
            return;
        }

        if ($kind === 'edit' && $last !== null) {
            $meta = $this->meta($last);
            if ($meta['kind'] === 'edit' && $meta['name'] === '' && $now - $meta['time'] < self::MERGE_SECONDS) {
                file_put_contents($last, $after, LOCK_EX);
                $meta['time'] = $now;
                $this->writeMeta($last, $meta);
                return;
            }
        }

        $this->addVersion($after, $kind, $now, '', $from);
        $this->trimVersions();
    }

    private function addVersion(string $content, string $kind, int $time, string $name = '', ?array $from = null): string
    {
        $dir = $this->dir . '/versions';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $versions = $this->versionFiles();
        $next = $versions ? ((int) basename((string) end($versions), '.conf')) + 1 : 1;
        $file = sprintf('%s/%08d.conf', $dir, $next);
        file_put_contents($file, $content, LOCK_EX);
        $this->writeMeta($file, ['kind' => $kind, 'time' => $time, 'name' => $name, 'from' => $from]);
        return basename($file, '.conf');
    }

    /** @return array{kind: string, time: int, name: string, from: ?array} */
    private function meta(string $file): array
    {
        $raw = is_file($this->metaFile($file)) ? json_decode((string) file_get_contents($this->metaFile($file)), true) : null;
        $raw = is_array($raw) ? $raw : [];
        $kind = in_array($raw['kind'] ?? '', self::KINDS, true) ? $raw['kind'] : 'edit';
        return [
            'kind' => $kind,
            'time' => (int) ($raw['time'] ?? filemtime($file)),
            'name' => is_string($raw['name'] ?? null) ? $raw['name'] : '',
            'from' => is_array($raw['from'] ?? null) ? $raw['from'] : null,
        ];
    }

    private function writeMeta(string $file, array $meta): void
    {
        file_put_contents($this->metaFile($file), json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private function metaFile(string $file): string
    {
        return substr($file, 0, -strlen('.conf')) . '.json';
    }

    /** The file of a version id (8 digits), or null for anything else */
    private function versionFile(string $id): ?string
    {
        return preg_match('/^\d{8}$/', $id) ? $this->dir . '/versions/' . $id . '.conf' : null;
    }

    /** @return list<string> oldest first */
    private function versionFiles(): array
    {
        $files = glob($this->dir . '/versions/*.conf') ?: [];
        sort($files);
        return $files;
    }

    /** Drop the oldest unnamed versions past VERSION_LIMIT */
    private function trimVersions(): void
    {
        $unnamed = array_values(array_filter($this->versionFiles(), fn (string $f) => $this->meta($f)['name'] === ''));
        while (count($unnamed) > self::VERSION_LIMIT) {
            $file = array_shift($unnamed);
            unlink($file);
            if (is_file($this->metaFile($file))) {
                unlink($this->metaFile($file));
            }
        }
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
