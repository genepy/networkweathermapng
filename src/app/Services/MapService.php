<?php

namespace App\Services;

use App\Core\Config;
use App\Services\Observium\ObserviumTarget;

/**
 * MapService - Handles all map-related operations
 * 
 * This service provides CRUD operations for weathermaps and integrates
 * with the WeatherMap rendering engine.
 */
class MapService
{
    /** The fonts of new maps, shipped in public/fonts (SIL Open Font License) */
    public const DEFAULT_FONT = 'fonts/Inter-Regular.ttf';
    public const DEFAULT_FONT_BOLD = 'fonts/Inter-SemiBold.ttf';

    private $db;
    private $configPath;
    private $outputPath;
    private ?Config $config;
    
    public function __construct($database, Config $config)
    {
        $this->db = $database;
        $this->config = $config;
        
        // Get paths from config helper methods
        $this->configPath = $this->config->getConfigsPath();
        $this->outputPath = $this->config->getOutputPath();
        
        // Ensure directories exist
        $this->ensureDirectories();
    }
    
    /**
     * Ensure required directories exist
     */
    private function ensureDirectories(): void
    {
        $dirs = [
            $this->config->getConfigsPath(),
            $this->config->getOutputPath(),
            $this->config->getCachePath(),
            $this->config->getLogsPath(),
            $this->config->getLibPath() . '/WeatherMap/lib/pre',
            $this->config->getLibPath() . '/WeatherMap/lib/post',
        ];
        
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
                error_log("MapService: Created directory: $dir");
            }
        }
    }

    
    /**
     * Get all maps
     */
    public function getAllMaps(): array
    {
        return $this->db->query("SELECT * FROM maps ORDER BY sort_order, name");
    }
    
    /**
     * Get maps by group
     */
    public function getMapsByGroup(int $groupId): array
    {
        return $this->db->query("SELECT * FROM maps WHERE group_id = ? ORDER BY sort_order, name", [$groupId]);
    }
    
    /**
     * Get a single map by ID
     */
    public function getMap(int $id): ?array
    {
        $map = $this->db->queryOne("SELECT * FROM maps WHERE id = ?", [$id]);
        return $map ?: null;
    }
    
    public function getMapByName(string $name): ?array
    {
        $map = $this->db->queryOne("SELECT * FROM maps WHERE config_file = ?", [$name]);
        return $map ?: null;
    }
    
    /**
     * Get a map by filename
     */
    public function getMapByFilename(string $filename): ?array
    {
        return $this->db->queryOne("SELECT * FROM maps WHERE config_file = ?", [$filename]);
    }
    
    /**
     * Create a new map
     */
    public function createMap(array $data): int
    {
        $name = self::cleanName((string) ($data['name'] ?? $data['title'] ?? '')) ?: 'New Map';
        $data['name'] = $name;
        
        // Generate a unique filename if not provided
        if (empty($data['config_file'])) {
            $data['config_file'] = $this->generateConfigFilename($name);
        }
        
        // Create default config file
        $configContent = $this->generateDefaultConfig($data);
        $configPath = $this->configPath . '/' . $data['config_file'];
        error_log("MapService::createMap writing config to: $configPath");
        $bytes = file_put_contents($configPath, $configContent);
        error_log("MapService::createMap wrote $bytes bytes to config file");
        
        // Get next sort order
        $result = $this->db->queryOne("SELECT COALESCE(MAX(sort_order), 0) + 1 as next_order FROM maps");
        $sortOrder = $result['next_order'] ?? 1;
        
        // Insert into database
        return $this->db->insert('maps', [
            'name' => $name,
            'config_file' => $data['config_file'],
            'title_cache' => $name,
            'active' => $data['active'] ?? 1,
            'group_id' => $data['group_id'] ?? 1,
            'sort_order' => $sortOrder
        ]);
    }
    
    /**
     * Update an existing map
     */
    public function updateMap(int $id, array $data): bool
    {
        $map = $this->getMap($id);
        if (!$map) {
            return false;
        }
        
        $updateData = [];
        $allowedFields = ['name', 'title_cache', 'active', 'group_id', 'sort_order'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }
        
        // Handle 'title' as alias for 'name'
        if (isset($data['title']) && !isset($data['name'])) {
            $updateData['name'] = $data['title'];
        }
        
        if (empty($updateData)) {
            return true; // Nothing to update
        }
        
        $updateData['updated_at'] = date('Y-m-d H:i:s');
        
        return $this->db->update('maps', $updateData, 'id = ?', [$id]) >= 0;
    }
    
    /**
     * Delete a map and everything kept for it: its configuration and backup,
     * rendered images and pages, link graphs, editor history, permissions and
     * settings. Migrations that created it forget it.
     */
    public function deleteMap(int $id): bool
    {
        $map = $this->getMap($id);
        if (!$map) {
            return false;
        }

        $configPath = $this->configPath . '/' . $map['config_file'];
        foreach ([$configPath, $configPath . '.bak'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->removeOutputs($map);
        foreach (glob($this->outputPath . '/graphs/map_' . $id . '_link_*') ?: [] as $file) {
            @unlink($file);
        }
        self::removeTree($this->config->getDataRoot() . '/history/' . $id);
        (new Migration\MigrationStore($this->config->getDataRoot() . '/migration'))->forgetMap($id);

        $this->db->delete('user_map_permissions', 'map_id = ?', [$id]);
        $this->db->delete('settings', 'map_id = ?', [$id]);
        return $this->db->delete('maps', 'id = ?', [$id]) > 0;
    }

    /**
     * New name for a map. The TITLE in its configuration follows; with
     * $renameFile the configuration file takes a name made from it too.
     */
    public function renameMap(int $id, string $name, bool $renameFile = false): bool
    {
        $map = $this->getMap($id);
        $name = self::cleanName($name);
        if (!$map || $name === '') {
            return false;
        }

        $configPath = $this->configPath . '/' . $map['config_file'];
        if (is_file($configPath)) {
            $content = (string) file_get_contents($configPath);
            $this->saveMapConfig($id, self::withTitle($content, $name));
        }

        $update = ['name' => $name, 'title_cache' => $name];

        if ($renameFile) {
            $wanted = $this->generateConfigFilename($name);
            $current = $map['config_file'];
            if (pathinfo($current, PATHINFO_FILENAME) !== preg_replace('/(_\d+)?\.conf$/', '', $wanted) && $wanted !== $current) {
                $this->removeOutputs($map);
                foreach (['', '.bak'] as $ext) {
                    if (is_file($configPath . $ext)) {
                        rename($configPath . $ext, $this->configPath . '/' . $wanted . $ext);
                    }
                }
                $update['config_file'] = $wanted;
            }
        }

        $update['updated_at'] = date('Y-m-d H:i:s');
        return $this->db->update('maps', $update, 'id = ?', [$id]) >= 0;
    }

    /**
     * The map's name and its drawn title are one thing: when a save changes
     * the TITLE of the configuration (editor, text mode, undo), the name in
     * the list follows. A save that leaves the TITLE alone changes nothing,
     * so a map whose name and title already differ keeps both until its
     * title is edited. Returns the new name, null when it did not change.
     */
    public function followTitle(int $id, ?string $before, string $after): ?string
    {
        $old = $before === null ? null : self::titleOf($before);
        $new = self::titleOf($after);
        if ($new === null || $new === '' || $new === $old) {
            return null;
        }
        $map = $this->getMap($id);
        if (!$map || $map['name'] === $new) {
            return null;
        }
        $this->db->update('maps', ['name' => $new, 'title_cache' => $new, 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
        return $new;
    }

    /**
     * The title drawn on the map when it differs from the map's name, null
     * when they agree or the file has no TITLE. Maps made before name and
     * title were merged can still differ: the Map settings panel asks which
     * one to keep.
     */
    public function titleConflict(array $map): ?string
    {
        $path = $this->configPath . '/' . $map['config_file'];
        if (!is_file($path)) {
            return null;
        }
        $title = self::titleOf((string) file_get_contents($path));
        return ($title === null || $title === '' || $title === self::cleanName((string) $map['name'])) ? null : $title;
    }

    /**
     * The maps among $maps whose drawn title differs from their name.
     *
     * @param array<int, array<string, mixed>> $maps
     * @return array<int, string> drawn title by map id
     */
    public function titleConflicts(array $maps): array
    {
        $conflicts = [];
        foreach ($maps as $map) {
            $title = $this->titleConflict($map);
            if ($title !== null) {
                $conflicts[(int) $map['id']] = $title;
            }
        }
        return $conflicts;
    }

    /**
     * New order of maps, as dragged on the Maps page: the given maps take
     * the order positions they already held between them, in the new
     * sequence. Maps not listed keep theirs, so reordering the maps of one
     * group (the list is sorted by group first) leaves the others alone.
     *
     * @param int[] $ids
     * @return int the number of maps whose position changed
     */
    public function reorder(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($id) => $id > 0)));
        if (count($ids) < 2) {
            return 0;
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->db->query("SELECT id, sort_order FROM maps WHERE id IN ($marks) ORDER BY sort_order, name, id", $ids);
        if (count($rows) !== count($ids)) {
            throw new \InvalidArgumentException('Unknown map in the new order');
        }
        // the positions they held, made distinct so the new order sticks
        // (maps created together may share one)
        $slots = [];
        $last = PHP_INT_MIN;
        foreach ($rows as $row) {
            $last = max((int) $row['sort_order'], $last + 1);
            $slots[] = $last;
        }
        $current = array_column($rows, 'sort_order', 'id');
        $changed = 0;
        foreach ($ids as $i => $id) {
            if ((int) $current[$id] !== $slots[$i]) {
                $this->db->update('maps', ['sort_order' => $slots[$i]], 'id = ?', [$id]);
                $changed++;
            }
        }
        return $changed;
    }

    /** The TITLE of a configuration text (the first one), null when it has none */
    public static function titleOf(string $content): ?string
    {
        return preg_match('/^[ \t]*TITLE[ \t]+(.*)$/mi', $content, $m) ? self::cleanName($m[1]) : null;
    }

    /** One line, no control characters: it goes into the TITLE line of the file */
    public static function cleanName(string $name): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $name));
    }

    /** $content with its TITLE set to $title (added at the top when missing) */
    public static function withTitle(string $content, string $title): string
    {
        $title = self::cleanName($title);
        $count = 0;
        $content = (string) preg_replace_callback('/^([ \t]*)TITLE[ \t]+.*$/mi', function ($m) use ($title, &$count) {
            return $count++ === 0 ? $m[1] . 'TITLE ' . $title : $m[0];
        }, $content, 1);
        return $count ? $content : 'TITLE ' . $title . "\n" . $content;
    }

    /**
     * A zip of the given maps' configurations and the images they use, in
     * the layout Admin > Migration imports.
     *
     * @param list<int> $ids empty for every map
     */
    public function exportZip(array $ids, string $zipFile): int
    {
        $maps = $ids ? array_filter(array_map([$this, 'getMap'], $ids)) : $this->getAllMaps();
        $zip = new \ZipArchive();
        if ($zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot write ' . $zipFile);
        }

        $public = $this->config->getPublicPath();
        $count = 0;
        foreach ($maps as $map) {
            $file = $this->configPath . '/' . $map['config_file'];
            if (!is_file($file)) {
                continue;
            }
            $content = (string) file_get_contents($file);
            $zip->addFromString('configs/' . $map['config_file'], $content);
            $count++;

            if (preg_match_all('/^\s*(?:ICON(?:\s+\d+\s+\d+)?|BACKGROUND)\s+(\S+)\s*$/mi', $content, $m)) {
                foreach ($m[1] as $image) {
                    if (preg_match('#^(objects|backgrounds)/[A-Za-z0-9._ -]+$#', $image) && is_file($public . '/' . $image)) {
                        $zip->addFile($public . '/' . $image, 'images/' . $image);
                    }
                }
            }
        }
        $zip->close();
        return $count;
    }

    /** Rendered images and pages of a map; they are made again when needed */
    private function removeOutputs(array $map): void
    {
        $base = pathinfo($map['config_file'], PATHINFO_FILENAME);
        foreach (glob($this->outputPath . '/' . self::globEscape($base) . '.*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private static function globEscape(string $s): string
    {
        return (string) preg_replace('/([*?\[\]])/', '[$1]', $s);
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    /**
     * Get map configuration content
     */
    public function getMapConfig(int $id): ?string
    {
        $map = $this->getMap($id);
        if (!$map) {
            return null;
        }
        
        $configPath = $this->configPath . '/' . $map['config_file'];
        if (!file_exists($configPath)) {
            // Create default config if it doesn't exist
            $content = $this->generateDefaultConfig($map);
            file_put_contents($configPath, $content);
            return $content;
        }
        
        return file_get_contents($configPath);
    }
    
    /**
     * Save map configuration content
     */
    public function saveMapConfig(int $id, string $content): bool
    {
        $map = $this->getMap($id);
        if (!$map) {
            return false;
        }
        
        $configPath = $this->configPath . '/' . $map['config_file'];
        
        // Backup old config
        if (file_exists($configPath)) {
            $backupPath = $configPath . '.bak';
            copy($configPath, $backupPath);
        }
        
        // Save new config
        $result = file_put_contents($configPath, $content);
        
        if ($result !== false) {
            // updateMap() skips an empty change, so set the time here
            $this->db->update('maps', ['updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
        }
        
        return $result !== false;
    }
    
    /**
     * Render a map to image
     */
    public function renderMap(int $id, bool $force = false, bool $readData = true): ?array
    {
        $map = $this->getMap($id);
        if (!$map) {
            return null;
        }
        
        $configPath = $this->configPath . '/' . $map['config_file'];
        error_log("MapService::renderMap looking for config at: $configPath");
        if (!file_exists($configPath)) {
            error_log("MapService::renderMap config file not found: $configPath");
            return ['error' => 'Config file not found'];
        }
        
        $format = $this->config->get('MAP_OUTPUT_FORMAT', 'png');
        $outputBase = pathinfo($map['config_file'], PATHINFO_FILENAME);
        $suffix = $readData ? '' : '.fast';
        $imagePath = $this->outputPath . '/' . $outputBase . $suffix . '.' . $format;
        $thumbPath = $this->outputPath . '/' . $outputBase . $suffix . '.thumb.' . $format;
        $htmlPath = $this->outputPath . '/' . $outputBase . $suffix . '.html';
        $svgPath = $this->outputPath . '/' . $outputBase . $suffix . '.svg';
        $statusPath = $this->outputPath . '/' . $outputBase . '.json';
        
        // Check if we need to re-render
        // We consider it cached only if BOTH main image and thumbnail exist and are newer than config.
        if (!$force && file_exists($imagePath) && file_exists($thumbPath)) {
            $configTime = filemtime($configPath);
            $imageTime = filemtime($imagePath);
            $thumbTime = filemtime($thumbPath);
            
            // The data render also keeps the SVG and the state summary next to the image
            $extrasFresh = !$readData || (
                is_file($svgPath) && filemtime($svgPath) >= $configTime
                && is_file($statusPath) && filemtime($statusPath) >= $configTime
            );

            if ($imageTime >= $configTime && $thumbTime >= $configTime && $extrasFresh) {
                return [
                    'image' => $imagePath,
                    'html' => file_exists($htmlPath) ? $htmlPath : null,
                    'cached' => true
                ];
            }
        }
        
        return $this->engine(function () use ($id, $readData, $configPath, $imagePath, $thumbPath, $htmlPath, $svgPath, $statusPath): array {
            try {
                $wmap = new \WeatherMap();
                $wmap->context = 'standalone';
            
                // Read the config
                error_log("MapService::renderMap reading config from: $configPath");
                $wmap->ReadConfig($configPath);
                error_log("MapService::renderMap config read successfully");
            
                // Read data from data sources
                if ($readData) {
                    $wmap->ReadData();
                }

                // the graph of the total traffic boxes: the recorded totals of the last day
                if (\WeatherMapBoxes::wantsSeries($wmap)) {
                    $wmap->box_series = $this->recordedTotals($id);
                }
            
                // Write the image
                error_log("MapService::renderMap writing image to: $imagePath");
                error_log("MapService::renderMap writing thumbnail to: $thumbPath");
                // The SVG is collected during the same drawing pass as the PNG
                $collector = new \WeatherMapSvg($wmap, [
                    'font_url' => static fn ($n) => '/map/' . $id . '/font/' . (int) $n,
                ]);
                $previous = \WeatherMapSvg::$active;
                \WeatherMapSvg::$active = $collector;
                try {
                    // The timestamp drawn on the map is in the map's time zone
                    TimezoneService::using(
                        TimezoneService::fromConfig($this->db, $this->config)->forMap($id),
                        static fn () => $wmap->DrawMap($imagePath, $thumbPath)
                    );
                } finally {
                    \WeatherMapSvg::$active = $previous;
                }
                error_log("MapService::renderMap image and thumbnail written successfully");
                self::writeAtomic($svgPath, (string) $collector->toSvg());

                if ($readData) {
                    $summary = self::summarize($wmap, $id);
                    self::writeAtomic($statusPath, (string) json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                }
            
                // Generate HTML imagemap
                if (method_exists($wmap, 'MakeHTML')) {
                    $htmlContent = $wmap->MakeHTML();
                    if ($htmlContent) {
                        file_put_contents($htmlPath, $htmlContent);
                    }
                }
            
                // Update last render time in database
                if ($readData) {
                    $this->db->update('maps', ['last_run' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
                }
            
                return [
                    'image' => $imagePath,
                    'html' => file_exists($htmlPath) ? $htmlPath : null,
                    'cached' => false
                ];
            } catch (\Exception $e) {
                return ['error' => $e->getMessage()];
            }
        });
    }

    /**
     * The total traffic of a map over the last day, [[time, in, out], ...],
     * from its recordings (RecordingService::totals keeps them in a cache,
     * so only new frames are read). Empty without recordings.
     *
     * @return list<array{0: int, 1: float|null, 2: float|null}>
     */
    public function recordedTotals(int $id, ?int $now = null): array
    {
        if (!$this->db instanceof \App\Core\Database) {
            return [];
        }
        $since = ($now ?? time()) - 86400;
        try {
            $rows = (new RecordingService($this->db, $this->config))->totals($id, $now);
        } catch (\Throwable $e) {
            return [];
        }
        return array_values(array_filter($rows, static fn (array $r) => $r[0] >= $since));
    }

    /**
     * Run the map engine: from the web root, where the maps find their
     * icons and fonts (objects/..., fonts/...) whatever the working folder
     * of the PHP process, and with its warnings logged rather than written
     * to the standard error, which PHP-FPM can send to the browser in the
     * middle of a picture.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function engine(callable $fn): mixed
    {
        require_once $this->config->getLibPath() . '/WeatherMap/bootstrap.php';
        $cwd = getcwd();
        $public = $this->config->getPublicPath();
        if (is_dir($public)) {
            chdir($public);
        }
        $previous = $GLOBALS['weathermap_warnings'] ?? null;
        $GLOBALS['weathermap_warnings'] = [];
        try {
            return $fn();
        } finally {
            $warnings = array_unique((array) $GLOBALS['weathermap_warnings']);
            unset($GLOBALS['weathermap_warnings']);
            if ($cwd !== false) {
                chdir($cwd);
            }
            if (is_array($previous)) {
                // an outer caller collects them
                $GLOBALS['weathermap_warnings'] = array_merge($previous, $warnings);
            } elseif ($warnings) {
                error_log('WeatherMap: ' . implode(' | ', array_slice($warnings, 0, 20))
                    . (count($warnings) > 20 ? ' (' . count($warnings) . ' warnings)' : ''));
            }
        }
    }

    // ------------------------------------------------------------------
    // Viewer: SVG output, state summary, fonts, favourites

    /** Outputs of a map: [svg, json summary] */
    public function viewerPaths(array $map): array
    {
        $base = $this->outputPath . '/' . pathinfo($map['config_file'], PATHINFO_FILENAME);
        return ['svg' => $base . '.svg', 'status' => $base . '.json'];
    }

    /**
     * Whether a render made at $outputTime (null: none) can still be shown:
     * it must be newer than the configuration and younger than $maxAge.
     */
    public static function isFresh(?int $outputTime, int $configTime, int $now, int $maxAge): bool
    {
        if ($outputTime === null) {
            return false;
        }
        return $outputTime >= $configTime && ($maxAge <= 0 || $now - $outputTime < $maxAge);
    }

    /**
     * The map with live data as SVG plus its summary, rendered again when
     * the last one is older than the configuration or the refresh interval.
     * One render at a time per map; the others wait and reuse it.
     *
     * @return array{svg: string, summary: array<string, mixed>}|null
     */
    public function liveRender(int $id): ?array
    {
        $map = $this->getMap($id);
        if (!$map) {
            return null;
        }
        $configPath = $this->configPath . '/' . $map['config_file'];
        if (!is_file($configPath)) {
            return null;
        }

        $paths = $this->viewerPaths($map);
        $maxAge = (int) $this->config->get('MAP_REFRESH_INTERVAL', 300);
        $fresh = function () use ($paths, $configPath, $maxAge): bool {
            clearstatcache();
            $svgTime = is_file($paths['svg']) ? (int) filemtime($paths['svg']) : null;
            $jsonTime = is_file($paths['status']) ? (int) filemtime($paths['status']) : null;
            $configTime = (int) filemtime($configPath);
            return self::isFresh($svgTime, $configTime, time(), $maxAge)
                && self::isFresh($jsonTime, $configTime, time(), $maxAge);
        };

        if (!$fresh()) {
            $lock = @fopen($this->config->getCachePath() . '/render-' . $id . '.lock', 'c');
            if ($lock) {
                flock($lock, LOCK_EX);
            }
            try {
                if (!$fresh()) {
                    ob_start();
                    try {
                        $this->renderMap($id, true, true);
                    } finally {
                        ob_end_clean();
                    }
                }
            } finally {
                if ($lock) {
                    flock($lock, LOCK_UN);
                    fclose($lock);
                }
            }
        }

        if (!is_file($paths['svg'])) {
            return null;
        }

        return [
            'svg' => (string) file_get_contents($paths['svg']),
            'summary' => $this->mapStatus($map) ?? [],
        ];
    }

    /**
     * Draw a map with live data now, for the recorder, and return what the
     * viewer shows of it: {svg, items, status}. Null when it cannot be drawn.
     */
    public function recordFrame(int $id): ?array
    {
        $map = $this->getMap($id);
        if (!$map) {
            return null;
        }
        $lock = @fopen($this->config->getCachePath() . '/render-' . $id . '.lock', 'c');
        if ($lock) {
            flock($lock, LOCK_EX);
        }
        try {
            ob_start();
            try {
                $result = $this->renderMap($id, true, true);
            } finally {
                ob_end_clean();
            }
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
        $paths = $this->viewerPaths($map);
        if (!$result || isset($result['error']) || !is_file($paths['svg'])) {
            return null;
        }
        $summary = $this->mapStatus($map) ?? [];
        return [
            'svg' => (string) file_get_contents($paths['svg']),
            'items' => $summary['items'] ?? [],
            'status' => [
                'max' => $summary['max'] ?? null,
                'level' => $summary['level'] ?? 'none',
                'colour' => $summary['colour'] ?? null,
                'link' => $summary['link'] ?? null,
                'time' => $summary['time'] ?? time(),
                'scale' => $summary['scale'] ?? [],
                'total' => self::trafficTotal((array) ($summary['items'] ?? [])),
                'basemap' => $summary['basemap'] ?? null,
            ],
        ];
    }

    /**
     * What the last data render found: the most loaded link, and per item
     * what the viewer shows on hover or opens on click (a link also names
     * the nodes at its ends, A and B). With the map id,
     * links read from a data source get the traffic graph of
     * /map/{id}/link/{linkId}/graph when they have no OVERLIBGRAPH.
     */
    public static function summarize(\WeatherMap $wmap, ?int $mapId = null): array
    {
        $max = null;
        $items = [];

        foreach ($wmap->links as $link) {
            if (!isset($link->a, $link->b) || $link->name === 'DEFAULT') {
                continue;
            }
            $in = (float) ($link->inpercent ?? 0);
            $out = (float) ($link->outpercent ?? 0);
            $dir = $out > $in ? OUT : IN;
            $pct = max($in, $out);

            if ($max === null || $pct > $max['pct']) {
                $colour = $link->colours[$dir] ?? null;
                $max = [
                    'pct' => $pct,
                    'link' => (string) $link->name,
                    'colour' => self::colourHex($colour),
                ];
            }

            $item = [
                'kind' => 'link',
                'name' => (string) $link->name,
                // its ends, for the node card of the viewer
                'a' => (string) $link->a->name,
                'b' => (string) $link->b->name,
                // and how the map shows them, for the title of the hover card
                'labels' => [self::nodeLabel($wmap, $link->a), self::nodeLabel($wmap, $link->b)],
                'in' => is_numeric($link->bandwidth_in ?? null) ? (float) $link->bandwidth_in : null,
                'out' => is_numeric($link->bandwidth_out ?? null) ? (float) $link->bandwidth_out : null,
                'inpct' => round($in, 2),
                'outpct' => round($out, 2),
            ];
            $ds = $link->hints['datasource'] ?? '';
            if (is_string($ds) && $ds !== '') {
                $item['ds'] = $ds;
            }
            if (!self::countsInTotal($link)) {
                // left out of the total traffic of the viewer's side panel
                $item['total'] = false;
            }
            $speed = self::linkSpeed($link);
            if ($speed !== null) {
                // the speed badge of the hover card: [in, out] in bit/s
                $item['speed'] = $speed;
            }
            $urls = self::itemUrls($wmap, $link);
            if (!isset($urls['graph']) && $mapId !== null && self::hasTrafficHistory($link)) {
                $urls['graph'] = '/map/' . $mapId . '/link/' . (int) $link->id . '/graph';
                // the IN/OUT series the hover card draws for a day, a week or a month
                $urls['traffic'] = '/map/' . $mapId . '/link/' . (int) $link->id . '/traffic';
            }
            $items['L' . $link->id] = $item + $urls;
        }

        $geoMap = \WeatherMapGeo::frame($wmap) !== null;
        foreach ($wmap->nodes as $node) {
            if (is_null($node->x) || $node->name === 'DEFAULT') {
                continue;
            }
            $urls = self::itemUrls($wmap, $node);
            $site = self::siteOf($node);
            if ($site !== '') {
                // the group of the node in the viewer's site list
                $urls['site'] = $site;
            }
            $geo = $geoMap ? \WeatherMapGeo::nodePoint($node) : null;
            if ($geo !== null) {
                // its point on the basemap: the viewer merges close sites and shows their city
                $urls['geo'] = $geo;
                $cluster = Basemap::clusterOf($node);
                if ($cluster !== '') {
                    // its group when the map merges only groups (SET cluster_mode groups)
                    $urls['cluster'] = $cluster;
                }
            }
            if ($urls) {
                $items['N' . $node->id] = ['kind' => 'node', 'name' => (string) $node->name] + $urls;
            }
        }

        return [
            'time' => time(),
            'max' => $max === null ? null : round($max['pct'], 1),
            'colour' => $max['colour'] ?? null,
            'link' => $max['link'] ?? null,
            'level' => self::loadLevel($max === null ? null : $max['pct']),
            'scale' => self::scaleStops($wmap),
            'total' => self::trafficTotal($items),
            // the geographic basemap the viewer draws under the map, or null
            'basemap' => Basemap::of($wmap),
            // the parts of the map the viewer and the kiosk can put forward, or null
            'focus' => FocusAreas::forViewer($wmap),
            'items' => $items,
        ];
    }

    /**
     * The BANDWIDTH of a link in bit/s, [in, out], once the data render
     * resolved it (BANDWIDTH auto takes the interface speed). Null when it
     * is not a positive number.
     *
     * @return array{0: float, 1: float}|null
     */
    public static function linkSpeed(\WeatherMapLink $link): ?array
    {
        $in = $link->max_bandwidth_in ?? null;
        $out = $link->max_bandwidth_out ?? null;
        if (!is_numeric($in) || !is_numeric($out) || (float) $in <= 0 || (float) $out <= 0) {
            return null;
        }
        return [(float) $in, (float) $out];
    }

    /** The label a node shows (tokens filled in, on one line), its name when it has none */
    public static function nodeLabel(\WeatherMap $wmap, \WeatherMapNode $node): string
    {
        $label = trim((string) preg_replace('/\s+/u', ' ', str_replace('\\n', ' ', (string) $wmap->ProcessString((string) ($node->label ?? ''), $node))));
        return $label !== '' ? $label : (string) $node->name;
    }

    /** SET site <name> on a node (or its template): the site it belongs to, '' for none */
    public static function siteOf(\WeatherMapNode $node): string
    {
        $site = $node->hints['site'] ?? '';
        return is_scalar($site) ? trim(preg_replace('/\s+/u', ' ', (string) $site) ?? '') : '';
    }

    /** Whether a link counts in the total traffic: always, unless SET traffic_total 0 */
    public static function countsInTotal(\WeatherMapLink $link): bool
    {
        $v = $link->hints['traffic_total'] ?? '1';
        return !is_scalar($v) || trim((string) $v) !== '0';
    }

    /**
     * The total traffic of a map: the sum of the IN and OUT rates (bits per
     * second) of its links that count in it ("total" => false leaves a link
     * out), from summary items. In and out are null when no link has data.
     *
     * @param array<string, mixed> $items
     * @return array{in: float|null, out: float|null, links: int}
     */
    public static function trafficTotal(array $items): array
    {
        $in = null;
        $out = null;
        $links = 0;
        foreach ($items as $item) {
            if (!is_array($item) || ($item['kind'] ?? '') !== 'link' || ($item['total'] ?? true) === false) {
                continue;
            }
            $hasIn = is_numeric($item['in'] ?? null);
            $hasOut = is_numeric($item['out'] ?? null);
            if (!$hasIn && !$hasOut) {
                continue;
            }
            $links++;
            if ($hasIn) {
                $in = ($in ?? 0.0) + (float) $item['in'];
            }
            if ($hasOut) {
                $out = ($out ?? 0.0) + (float) $item['out'];
            }
        }
        return ['in' => $in, 'out' => $out, 'links' => $links];
    }

    /**
     * The DEFAULT colour scale of a map, for the viewer's legend: its bands
     * from the lowest, each {bottom, top, colour} and colour2 when the band
     * is a gradient. Empty when the map has no usable scale.
     *
     * @return list<array{bottom: float, top: float, colour: string, colour2?: string}>
     */
    public static function scaleStops(\WeatherMap $wmap): array
    {
        $stops = [];
        foreach ((array) ($wmap->colours['DEFAULT'] ?? []) as $band) {
            if (!is_array($band) || !empty($band['special']) || !isset($band['bottom'], $band['top'], $band['red1'])) {
                continue;
            }
            if ((int) $band['red1'] < 0) {
                continue;   // a "none" colour draws nothing
            }
            $stop = [
                'bottom' => (float) $band['bottom'],
                'top' => (float) $band['top'],
                'colour' => sprintf('#%02x%02x%02x', (int) $band['red1'], (int) $band['green1'], (int) $band['blue1']),
            ];
            if (isset($band['red2']) && (int) $band['red2'] >= 0) {
                $stop['colour2'] = sprintf('#%02x%02x%02x', (int) $band['red2'], (int) $band['green2'], (int) $band['blue2']);
            }
            $stops[] = $stop;
        }
        usort($stops, static fn (array $a, array $b) => [$a['bottom'], $a['top']] <=> [$b['bottom'], $b['top']]);
        return $stops;
    }

    /** ok / warn / crit / none, for a maximum link load in % */
    public static function loadLevel(?float $pct): string
    {
        if ($pct === null) {
            return 'none';
        }
        if ($pct >= 85) {
            return 'crit';
        }
        return $pct >= 55 ? 'warn' : 'ok';
    }

    /**
     * INFOURL (click) and first OVERLIBGRAPH (hover picture) of an item.
     * Without INFOURL, the click opens the page in the monitoring tool that
     * a data source noted (its port page in Observium, for instance).
     */
    private static function itemUrls(\WeatherMap $wmap, object $item): array
    {
        $out = [];
        $info = (string) ($item->infourl[IN] ?? '');
        if ($info !== '' && ($url = self::safeUrl((string) $wmap->ProcessString($info, $item))) !== null) {
            $out['url'] = $url;
        } elseif (is_string($page = $item->notes['monitoring_url'] ?? null) && ($url = self::safeUrl($page)) !== null) {
            $out['url'] = $url;
        }
        $graphs = (array) ($item->overliburl[IN] ?? []);
        if ($graphs && (string) reset($graphs) !== ''
            && ($graph = self::safeUrl((string) $wmap->ProcessString((string) reset($graphs), $item))) !== null) {
            $out['graph'] = $graph;
        }
        return $out;
    }

    /**
     * Whether linkGraph can draw a link's traffic: it was set up with the
     * editor's picker, or its TARGET names an Observium port.
     */
    public static function hasTrafficHistory(object $link): bool
    {
        $ds = json_decode((string) ($link->hints['datasource'] ?? ''), true);
        if (is_array($ds) && !empty($ds['sourceId'])) {
            return true;
        }

        return self::observiumPortTarget($link) !== null;
    }

    /** The first TARGET of a link that names an Observium port */
    public static function observiumPortTarget(object $link): ?string
    {
        foreach ((array) ($link->targets ?? []) as $target) {
            // without the -/scale* prefix that Weathermap allows before a target
            $string = (string) preg_replace('/^-?(\d+(\.\d+)?\*)?/', '', trim((string) ($target[4] ?? '')));
            $parsed = ObserviumTarget::parse($string);
            if ($parsed && $parsed->kind === ObserviumTarget::PORT) {
                return $string;
            }
        }

        return null;
    }

    /**
     * A link from a map file the viewer may open: http(s) or relative only,
     * so an imported map cannot run script with INFOURL javascript:...
     */
    public static function safeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x1f\x7f\\\\]/', $url)) {
            return null;
        }
        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $m)) {
            return in_array(strtolower($m[1]), ['http', 'https'], true) ? $url : null;
        }
        return $url;
    }

    private static function colourHex(mixed $colour): ?string
    {
        if (is_object($colour) && isset($colour->r, $colour->g, $colour->b) && $colour->r >= 0) {
            return sprintf('#%02x%02x%02x', (int) $colour->r, (int) $colour->g, (int) $colour->b);
        }
        return null;
    }

    /** The summary of the last data render of a map, null when there is none */
    public function mapStatus(array $map): ?array
    {
        $file = $this->viewerPaths($map)['status'];
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            return null;
        }
        $data['time'] = (int) ($data['time'] ?? filemtime($file));
        $configFile = $this->configPath . '/' . $map['config_file'];
        $maxAge = (int) $this->config->get('MAP_REFRESH_INTERVAL', 300);
        $data['stale'] = !self::isFresh((int) filemtime($file), is_file($configFile) ? (int) filemtime($configFile) : 0, time(), $maxAge);
        return $data;
    }

    /**
     * The live SVG of a map as a file that stands on its own (Download as
     * SVG): the map's fonts are put inside it instead of being fetched from
     * this server. Null when the map cannot be drawn.
     */
    public function svgFile(int $id): ?string
    {
        $result = $this->liveRender($id);
        if ($result === null) {
            return null;
        }
        return self::inlineFonts($result['svg'], $id, fn (int $n): ?string => $this->fontFile($id, $n));
    }

    /**
     * Replace the font addresses of a viewer SVG (/map/{id}/font/{n}) by the
     * fonts themselves. $fontFile gives the file of font n (null: none, the
     * address is left and the text falls back to a common font).
     */
    public static function inlineFonts(string $svg, int $id, callable $fontFile): string
    {
        return (string) preg_replace_callback(
            '#url\("/map/' . $id . '/font/(\d+)"\)#',
            static function (array $m) use ($fontFile): string {
                $file = $fontFile((int) $m[1]);
                if ($file === null || !is_readable($file)) {
                    return $m[0];
                }
                return 'url("data:font/ttf;base64,' . base64_encode((string) file_get_contents($file)) . '")';
            },
            $svg
        );
    }

    /** The TrueType file of font $n of a map, null when it has none */
    public function fontFile(int $id, int $n): ?string
    {
        $map = $this->getMap($id);
        if (!$map) {
            return null;
        }
        $configPath = $this->configPath . '/' . $map['config_file'];
        if (!is_file($configPath)) {
            return null;
        }

        return $this->engine(static function () use ($configPath, $n): ?string {
            $wmap = new \WeatherMap();
            $wmap->context = 'viewer';
            ob_start();
            try {
                $wmap->ReadConfig($configPath);
            } finally {
                ob_end_clean();
            }

            $font = $wmap->fonts[$n] ?? null;
            if (!$font || $font->type !== 'truetype' || !is_readable($font->file)) {
                return null;
            }
            // absolute, as it is read once back in the folder of the request
            return (string) (realpath($font->file) ?: $font->file);
        });
    }

    /**
     * Favourite maps of a user. They live in the settings table, one row
     * per map (map_id, name "favourite:<user id>"), so deleting a map drops
     * them with its other settings.
     *
     * @return list<int>
     */
    public function favouriteMapIds(int $userId): array
    {
        $rows = $this->db->query('SELECT map_id FROM settings WHERE name = ? AND map_id > 0', [self::favouriteKey($userId)]);
        return array_values(array_map(static fn ($r) => (int) $r['map_id'], $rows));
    }

    public function setFavourite(int $userId, int $mapId, bool $favourite): void
    {
        $key = self::favouriteKey($userId);
        $this->db->delete('settings', 'map_id = ? AND name = ?', [$mapId, $key]);
        if ($favourite && $mapId > 0) {
            $this->db->insert('settings', ['map_id' => $mapId, 'name' => $key, 'value' => '1']);
        }
    }

    private static function favouriteKey(int $userId): string
    {
        return 'favourite:' . $userId;
    }

    /**
     * Maps with the favourites first, the order of each part kept.
     *
     * @param list<array<string, mixed>> $maps
     * @param list<int> $favourites
     * @return list<array<string, mixed>>
     */
    public static function favouritesFirst(array $maps, array $favourites): array
    {
        $fav = array_flip($favourites);
        $first = $rest = [];
        foreach ($maps as $map) {
            if (isset($fav[(int) $map['id']])) {
                $first[] = $map;
            } else {
                $rest[] = $map;
            }
        }
        return array_merge($first, $rest);
    }

    private static function writeAtomic(string $file, string $content): void
    {
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, $content) !== false) {
            rename($tmp, $file);
        }
    }
    
    /**
     * Generate a unique config filename
     */
    private function generateConfigFilename(string $title): string
    {
        // Accents are folded to ASCII: "Café Core Œuvre" becomes "cafe_core_oeuvre"
        $ascii = strtr($title, ['œ' => 'oe', 'Œ' => 'OE', 'æ' => 'ae', 'Æ' => 'AE', 'ß' => 'ss']);
        if (class_exists('Normalizer')) {
            $ascii = preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($ascii, \Normalizer::FORM_D));
        }
        $base = preg_replace('/[^a-z0-9]+/i', '_', strtolower((string) $ascii));
        $base = trim($base, '_');
        
        if (empty($base)) {
            $base = 'map';
        }
        
        $filename = $base . '.conf';
        $counter = 1;
        
        while (file_exists($this->configPath . '/' . $filename)) {
            $filename = $base . '_' . $counter . '.conf';
            $counter++;
        }
        
        return $filename;
    }
    
    /**
     * Generate default map configuration
     */
    private function generateDefaultConfig(array $data): string
    {
        $title = $data['name'] ?? $data['title'] ?? 'New Map';
        $width = max(100, min(10000, (int) ($data['width'] ?? 800)));
        $height = max(100, min(10000, (int) ($data['height'] ?? 600)));

        // Inter, shipped in public/fonts: a TrueType font, so comments can
        // follow curved links and the SVG draws the same letters as the PNG
        $fontRegular = self::DEFAULT_FONT;
        $fontBold = self::DEFAULT_FONT_BOLD;
        
        return <<<CONFIG
# WeatherMap Configuration
# Generated: {$this->getCurrentTimestamp()}

# Map dimensions and title
WIDTH {$width}
HEIGHT {$height}
TITLE {$title}

# Background color
BGCOLOR 255 255 255

# Default fonts (fonts 1 to 5 are GD's own, drawn without TrueType)
FONTDEFINE 10 {$fontRegular} 8
FONTDEFINE 11 {$fontRegular} 9
FONTDEFINE 12 {$fontBold} 10
FONTDEFINE 13 {$fontBold} 12

TITLEFONT 13
TIMEFONT 11
KEYFONT 10

# Timestamp position
TIMEPOS 10 {$height} Created: %b %d %Y %H:%M:%S

# Default scale
SCALE DEFAULT 0 0     192 192 192
SCALE DEFAULT 0 1     255 255 255
SCALE DEFAULT 1 10    140 0 255
SCALE DEFAULT 10 25   32 32 255
SCALE DEFAULT 25 40   0 192 255
SCALE DEFAULT 40 55   0 240 0
SCALE DEFAULT 55 70   240 240 0
SCALE DEFAULT 70 85   255 192 0
SCALE DEFAULT 85 100  255 0 0

# Legend position
KEYPOS DEFAULT 10 10

# Default node settings
NODE DEFAULT
    LABELFONT 12
    MAXVALUE 100

# Default link settings
LINK DEFAULT
    BWFONT 11
    COMMENTFONT 10
    BANDWIDTH 100M
    BWLABEL bits
    BWLABELPOS 50 50
    WIDTH 4

# Add your nodes and links below
# Example:
# NODE router1
#     LABEL Router 1
#     POSITION 100 100
#     ICON images/router.png
#
# NODE router2
#     LABEL Router 2
#     POSITION 300 100
#
# LINK router1-router2
#     NODES router1 router2
#     TARGET zabbix:host:router1:ifHCInOctets:ifHCOutOctets

CONFIG;
    }
    
    /**
     * Get current timestamp
     */
    private function getCurrentTimestamp(): string
    {
        return date('Y-m-d H:i:s');
    }
    
    /**
     * Copy of a map, its configuration included. It starts inactive unless
     * $active says otherwise.
     */
    public function duplicateMap(int $id, ?string $name = null, ?int $groupId = null, bool $active = false): ?int
    {
        $map = $this->getMap($id);
        if (!$map) {
            return null;
        }

        $config = $this->getMapConfig($id);
        $name = self::cleanName((string) $name) ?: $map['name'] . ' (copy)';

        $newId = $this->createMap([
            'name' => $name,
            'active' => $active ? 1 : 0,
            'group_id' => $groupId ?: $map['group_id'],
        ]);

        if ($config) {
            $this->saveMapConfig($newId, self::withTitle($config, $name));
        }

        return $newId;
    }

    /**
     * Get map statistics
     */
    public function getMapStats(): array
    {
        $stats = [
            'total' => 0,
            'active' => 0,
            'inactive' => 0
        ];
        
        $result = $this->db->queryOne("SELECT COUNT(*) as total, SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) as active FROM maps");
        
        if ($result) {
            $stats['total'] = (int) $result['total'];
            $stats['active'] = (int) ($result['active'] ?? 0);
            $stats['inactive'] = $stats['total'] - $stats['active'];
        }
        
        return $stats;
    }
}
