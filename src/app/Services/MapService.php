<?php

namespace App\Services;

use App\Core\Config;

/**
 * MapService - Handles all map-related operations
 * 
 * This service provides CRUD operations for weathermaps and integrates
 * with the WeatherMap rendering engine.
 */
class MapService
{
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

    private function findFirstExistingPath(array $paths): string
    {
        foreach ($paths as $path) {
            if ($path !== '' && file_exists($path)) {
                return $path;
            }
        }

        return $paths[0] ?? '';
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
            // Update the map's updated_at timestamp
            $this->updateMap($id, []);
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
        
        // Load WeatherMap library
        require_once $this->config->getLibPath() . '/WeatherMap/bootstrap.php';
        
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
                $wmap->DrawMap($imagePath, $thumbPath);
            } finally {
                \WeatherMapSvg::$active = $previous;
            }
            error_log("MapService::renderMap image and thumbnail written successfully");
            self::writeAtomic($svgPath, (string) $collector->toSvg());

            if ($readData) {
                $summary = self::summarize($wmap);
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
     * What the last data render found: the most loaded link, and per item
     * what the viewer shows on hover or opens on click.
     */
    public static function summarize(\WeatherMap $wmap): array
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
                'in' => is_numeric($link->bandwidth_in ?? null) ? (float) $link->bandwidth_in : null,
                'out' => is_numeric($link->bandwidth_out ?? null) ? (float) $link->bandwidth_out : null,
                'inpct' => round($in, 2),
                'outpct' => round($out, 2),
            ];
            $ds = $link->hints['datasource'] ?? '';
            if (is_string($ds) && $ds !== '') {
                $item['ds'] = $ds;
            }
            $items['L' . $link->id] = $item + self::itemUrls($wmap, $link);
        }

        foreach ($wmap->nodes as $node) {
            if (is_null($node->x) || $node->name === 'DEFAULT') {
                continue;
            }
            $urls = self::itemUrls($wmap, $node);
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
            'items' => $items,
        ];
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

    /** INFOURL (click) and first OVERLIBGRAPH (hover picture) of an item */
    private static function itemUrls(\WeatherMap $wmap, object $item): array
    {
        $out = [];
        $info = (string) ($item->infourl[IN] ?? '');
        if ($info !== '') {
            $out['url'] = (string) $wmap->ProcessString($info, $item);
        }
        $graphs = (array) ($item->overliburl[IN] ?? []);
        if ($graphs && (string) reset($graphs) !== '') {
            $out['graph'] = (string) $wmap->ProcessString((string) reset($graphs), $item);
        }
        return $out;
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

        require_once $this->config->getLibPath() . '/WeatherMap/bootstrap.php';
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
        return (string) $font->file;
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
        // "Réseau cœur" becomes "reseau_coeur"
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

        $fontRegular = $this->findFirstExistingPath([
            '/usr/share/fonts/ttf-dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans.ttf',
        ]);
        $fontBold = $this->findFirstExistingPath([
            '/usr/share/fonts/ttf-dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        ]);
        
        return <<<CONFIG
# WeatherMap Configuration
# Generated: {$this->getCurrentTimestamp()}

# Map dimensions and title
WIDTH {$width}
HEIGHT {$height}
TITLE {$title}

# Background color
BGCOLOR 255 255 255

# Default fonts
FONTDEFINE 1 {$fontRegular} 8
FONTDEFINE 2 {$fontRegular} 10
FONTDEFINE 3 {$fontBold} 12

TITLEFONT 3
TIMEFONT 2
KEYFONT 1

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
    MAXVALUE 100

# Default link settings
LINK DEFAULT
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
