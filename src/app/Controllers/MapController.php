<?php
/**
 * Zabbix Weathermap - Map Controller
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Services\MapService;
use App\Services\DataSourceService;
use App\Services\KioskService;
use App\Services\RecordingService;
use App\Services\TimezoneService;

class MapController extends BaseController
{
    private MapService $mapService;
    private DataSourceService $dataSourceService;

    public function __construct(array $context)
    {
        parent::__construct($context);
        $this->mapService = new MapService($this->database, $this->config);
        $this->dataSourceService = new DataSourceService($this->database);
    }
    public function index(array $params): void
    {
        $this->requireAuth();
        
        $groupId = (int) $this->getInput('group_id', 0);
        
        // Get all maps the user can view to determine visible groups
        // We fetch ALL accessible maps first, then filter for the current view
        $allUserMaps = $this->getMapsForUser(0);
        
        // Determine which groups should be visible (groups containing at least one accessible map)
        $visibleGroupIds = array_unique(array_column($allUserMaps, 'group_id'));
        
        $allGroups = $this->getMapGroups();
        $visibleGroups = [];
        
        foreach ($allGroups as $group) {
            // Editors and admins see all groups (even empty ones)
            // Users see groups they have accessible maps in
            // Users also see groups they are explicitly assigned to (even if empty)
            if ($this->auth->canEdit()) {
                $visibleGroups[] = $group;
                continue;
            }
            
            // Check if user has maps in this group
            if (in_array($group['id'], $visibleGroupIds)) {
                $visibleGroups[] = $group;
                continue;
            }
            
            // Check explicit group permission (for empty groups)
            $user = $this->auth->user();
            if ($user) {
                $permission = $this->database->queryOne(
                    "SELECT 1 FROM user_group_permissions WHERE user_id = ? AND group_id = ?",
                    [$user['id'], $group['id']]
                );
                if ($permission) {
                    $visibleGroups[] = $group;
                }
            }
        }
        
        // Filter maps for current view if a group is selected
        $maps = $allUserMaps;
        if ($groupId > 0) {
            $maps = array_filter($allUserMaps, function($map) use ($groupId) {
                return (int)$map['group_id'] === $groupId;
            });
        }
        
        $favourites = $this->mapService->favouriteMapIds($this->currentUserId());
        $maps = array_values($maps);
        foreach ($maps as $k => $m) {
            $maps[$k]['list_order'] = $k;
        }
        $maps = MapService::favouritesFirst($maps, $favourites);
        $statuses = [];
        foreach ($maps as $m) {
            $status = $this->mapService->mapStatus($m);
            if ($status !== null) {
                unset($status['items']);
                $statuses[(int) $m['id']] = $status;
            }
        }

        $this->render('maps/index', [
            'maps' => $maps,
            'favourites' => $favourites,
            'statuses' => $statuses,
            'timezones' => $this->timezones()->forMaps(array_column($maps, 'id')),
            'groups' => $visibleGroups,
            'currentGroup' => $groupId,
            'title' => __('Network Weathermaps'),
        ]);
    }
    
    public function view(array $params): void
    {
        $this->requireAuth();
        
        $mapId = (int) ($params['id'] ?? 0);
        $fullscreen = (bool) $this->getInput('fullscreen', false);
        $fit = (bool) $this->getInput('fit', false);
        $mode = (string) $this->getInput('mode', '');
        
        if (!$this->auth->canViewMap($mapId)) {
            http_response_code(403);
            echo '<h1>' . e__('Access denied') . '</h1>';
            return;
        }
        
        $map = $this->getMap($mapId);
        
        if (!$map) {
            http_response_code(404);
            echo '<h1>' . e__('Map not found') . '</h1>';
            return;
        }
        
        $outputBase = pathinfo($map['config_file'], PATHINFO_FILENAME);
        // Check if HTML output exists
        $htmlFile = $this->config->getOutputPath() . '/' . $outputBase . '.html';
        $mapHtml = '';

        // Only preview mode is interactive (imagemap). All other views are plain PNG.
        // Preview mode requires the HTML imagemap. Generate it on demand.
        if ($mode === 'preview') {
            // Suppress library warnings during render
            ob_start();
            $this->mapService->renderMap($mapId, true);
            ob_end_clean();
        }

        // Fallback: if preview mode is requested but no HTML imagemap was generated, build it in-memory.
        if ($mode === 'preview' && trim($mapHtml) === '') {
            $mapFile = $this->config->getConfigsPath() . '/' . $map['config_file'];
            if (file_exists($mapFile)) {
                // Suppress library warnings during dynamic generation
                ob_start();
                require_once $this->config->getLibPath() . '/WeatherMap/bootstrap.php';

                $wmap = new \WeatherMap();
                // context='editor' forces SortedImagemap to include ALL areas, even those with no href/infourl.
                // This is required for preview tooltips to work on links that are monitored but have no click action.
                $wmap->context = 'editor';
                $wmap->ReadConfig($mapFile);

                // We want Hover URL (OVERLIBGRAPH) to be available in the generated <area> tags for preview.
                // WeatherMap only emits overlib-related attributes (e.g. data-hover) when HTMLSTYLE is 'overlib'.
                // Force it for preview generation without changing the user's config file.
                $wmap->htmlstyle = 'overlib';

                // DrawMap('null') populates imagemap regions without writing an image file.
                TimezoneService::using($this->timezones()->forMap($mapId), static fn () => $wmap->DrawMap('null'));

                // MakeHTML uses imageuri when provided.
                $wmap->imageuri = '/map/' . $mapId . '/image?t=' . time();
                $mapHtml = $wmap->MakeHTML('weathermap_imap');
                ob_end_clean();

                // Ensure hover works consistently.
                $mapHtml = preg_replace(
                    '/(<area\b[^>]*?)\bnohref\b([^>]*?\/?>)/i',
                    '$1href="#" $2',
                    $mapHtml
                ) ?? $mapHtml;
            }
        }

        // When the image is scaled to fit the browser, imagemap <area> coords would no longer match.
        // In fit mode, render a plain <img> instead.
        // Fullscreen is allowed for preview, as long as fit is disabled.
        if ($fit || $mode !== 'preview') {
            $mapHtml = '';
        }
        if ($fullscreen && $mode === 'preview' && $fit) {
            $mapHtml = '';
        }
        
        $hours = (int) $this->getInput('hours', 4);
        if ($hours <= 0) {
            $hours = 4;
        }
        $defaultRefresh = $fullscreen ? 120 : 0;
        $autoRefresh = (int) $this->getInput('autorefresh', $defaultRefresh);
        if ($autoRefresh < 0) {
            $autoRefresh = 0;
        }

        // The zoomable SVG is the default view; ?view=png keeps the picture
        $viewKind = ($mode === 'preview' || (string) $this->getInput('view', '') === 'png') ? 'png' : 'svg';

        $timezones = $this->timezones();
        $timezone = $timezones->forMap($mapId);

        $this->render('maps/view', [
            'map' => $map,
            'timezone' => $timezone,
            'lastRun' => $map['last_run'] ? $timezones->formatStored((string) $map['last_run'], $timezone) : null,
            'mapHtml' => $mapHtml,
            'viewKind' => $viewKind,
            'fullscreen' => $fullscreen,
            'fit' => $fit,
            'mode' => $mode,
            'hours' => $hours,
            'autoRefresh' => $autoRefresh,
            'history' => $this->historyAvailable($mapId),
            'title' => $map['title_cache'] ?: $map['name'],
        ], !$fullscreen);
    }

    /**
     * GET: the map with live data as SVG, with what the viewer needs to know
     * about its items (hover data, click URLs) and its state. The SVG is
     * cached like the image and drawn again after the refresh interval.
     */
    public function svg(array $params): void
    {
        $mapId = (int) ($params['id'] ?? 0);
        if (!$this->canView($mapId)) {
            $this->json(['error' => 'Forbidden'], 403);
            return;
        }

        $result = $this->mapService->liveRender($mapId);
        if ($result === null) {
            $this->json(['error' => __('Map not found')], 404);
            return;
        }

        $summary = $result['summary'];
        header('Cache-Control: no-store');
        $this->json([
            'svg' => $result['svg'],
            'items' => (object) ($summary['items'] ?? []),
            'status' => self::publicStatus($summary),
            'version' => $this->configVersion($mapId),
        ]);
    }

    /** GET: the recorded frames of a map, to play them back: {frames: [unix times], interval} */
    public function history(array $params): void
    {
        $mapId = (int) ($params['id'] ?? 0);
        $recording = $this->recording();
        if (!$this->canView($mapId) || !$recording->canPlay($mapId)) {
            $this->json(['error' => __('This map is not recorded')], 404);
            return;
        }
        header('Cache-Control: no-store');
        $this->json(['frames' => $recording->frames($mapId), 'interval' => $recording->getInterval() * 60]);
    }

    /** GET: one recorded frame, as /map/{id}/svg gives the live map */
    public function historyFrame(array $params): void
    {
        $mapId = (int) ($params['id'] ?? 0);
        $time = (int) ($params['time'] ?? 0);
        $recording = $this->recording();
        $frame = $this->canView($mapId) && $recording->canPlay($mapId) && in_array($time, $recording->frames($mapId), true)
            ? $recording->frame($mapId, $time)
            : null;
        if ($frame === null) {
            $this->json(['error' => __('This frame is not recorded')], 404);
            return;
        }
        // a frame never changes once written
        header('Cache-Control: private, max-age=86400');
        $this->json([
            'svg' => (string) ($frame['svg'] ?? ''),
            'items' => (object) ($frame['items'] ?? []),
            'status' => self::publicStatus((array) ($frame['status'] ?? [])),
            'time' => $time,
        ]);
    }

    /**
     * Whether the viewer offers the playback. For a recorded map, also make
     * sure the recorder runs (after a restart or a crash).
     */
    private function historyAvailable(int $mapId): bool
    {
        $recording = $this->recording();
        if (!$recording->canPlay($mapId)) {
            return false;
        }
        $recording->ensureRecorder();
        return true;
    }

    private function recording(): RecordingService
    {
        return new RecordingService($this->database, $this->config);
    }

    /** GET: the state of a map (most loaded link) for the map list */
    public function status(array $params): void
    {
        $mapId = (int) ($params['id'] ?? 0);
        if (!$this->canView($mapId)) {
            $this->json(['error' => 'Forbidden'], 403);
            return;
        }

        $result = $this->mapService->liveRender($mapId);
        if ($result === null) {
            $this->json(['error' => __('Map not found')], 404);
            return;
        }

        header('Cache-Control: no-store');
        $this->json(self::publicStatus($result['summary']));
    }

    /** GET: a TrueType font of the map, for the text of the SVG view */
    public function font(array $params): void
    {
        $mapId = (int) ($params['id'] ?? 0);
        if (!$this->canView($mapId)) {
            http_response_code(403);
            return;
        }

        $file = $this->mapService->fontFile($mapId, (int) ($params['n'] ?? 0));
        if ($file === null) {
            http_response_code(404);
            return;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: font/ttf');
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: private, max-age=86400');
        readfile($file);
    }

    /** POST {favourite: bool}: star or unstar a map for the current user */
    public function favourite(array $params): void
    {
        $mapId = (int) ($params['id'] ?? 0);
        if (!$this->auth->check() || !$this->canView($mapId) || !$this->getMap($mapId)) {
            $this->json(['error' => 'Forbidden'], 403);
            return;
        }

        $body = $this->getJsonInput();
        $on = (bool) ($body['favourite'] ?? $this->getInput('favourite', false));
        $this->mapService->setFavourite($this->currentUserId(), $mapId, $on);
        $this->json(['success' => true, 'favourite' => $on]);
    }

    private function canView(int $mapId): bool
    {
        return !$this->config->isAuthEnabled() || $this->auth->canViewMap($mapId);
    }

    private function currentUserId(): int
    {
        $user = $this->auth->user();
        return (int) ($user['id'] ?? 0);
    }

    /** The summary without the per-item details */
    private static function publicStatus(array $summary): array
    {
        return [
            'max' => $summary['max'] ?? null,
            'level' => $summary['level'] ?? 'none',
            'colour' => $summary['colour'] ?? null,
            'link' => $summary['link'] ?? null,
            'time' => $summary['time'] ?? null,
        ];
    }

    private function configVersion(int $mapId): string
    {
        $map = $this->getMap($mapId);
        if (!$map) {
            return '0';
        }
        $configFile = $this->config->getConfigsPath() . '/' . $map['config_file'];
        clearstatcache(true, $configFile);
        return file_exists($configFile) ? filemtime($configFile) . '-' . filesize($configFile) : '0';
    }

    /**
     * Fingerprint of the map's configuration. Open map pages poll it so an
     * edit shows up right away instead of at the next refresh cycle.
     */
    public function version(array $params): void
    {
        $this->requireAuth();

        $mapId = (int) ($params['id'] ?? 0);
        if (!$this->auth->canViewMap($mapId)) {
            $this->json(['error' => 'Forbidden'], 403);
            return;
        }

        $map = $this->getMap($mapId);
        if (!$map) {
            $this->json(['error' => 'Not found'], 404);
            return;
        }

        $configFile = $this->config->getConfigsPath() . '/' . $map['config_file'];
        clearstatcache(true, $configFile);
        $version = file_exists($configFile)
            ? filemtime($configFile) . '-' . filesize($configFile)
            : '0';

        header('Cache-Control: no-store');
        $this->json(['version' => $version]);
    }

    public function linkGraph(array $params): void
    {
        $mapId = (int) ($params['id'] ?? 0);
        $linkId = (int) ($params['linkId'] ?? 0);

        if ($this->config->isAuthEnabled() && !$this->auth->canViewMap($mapId)) {
            http_response_code(403);
            exit;
        }

        $map = $this->getMap($mapId);
        if (!$map) {
            http_response_code(404);
            exit;
        }

        $mapFile = $this->config->getConfigsPath() . '/' . $map['config_file'];
        if (!file_exists($mapFile)) {
            http_response_code(404);
            exit;
        }

        require_once $this->config->getLibPath() . '/WeatherMap/bootstrap.php';

        $wmap = new \WeatherMap();
        $wmap->context = 'viewer';
        $wmap->ReadConfig($mapFile);

        $targetLink = null;
        foreach ($wmap->links as $link) {
            if ((int) ($link->id ?? 0) === $linkId) {
                $targetLink = $link;
                break;
            }
        }

        // A link set up with the editor's picker names its source; otherwise
        // an Observium TARGET is read from the first active Observium source
        $selection = $targetLink ? json_decode((string) ($targetLink->hints['datasource'] ?? ''), true) : null;
        if (!is_array($selection) || empty($selection['sourceId'])) {
            $selection = null;
        }
        $observiumTarget = $targetLink && !$selection ? MapService::observiumPortTarget($targetLink) : null;
        if (!$selection && $observiumTarget === null) {
            http_response_code(404);
            exit;
        }

        $minutes = (int) $this->getInput('minutes', 1440);
        if ($minutes <= 0) {
            $minutes = 1440;
        }
        $scale = max(1, min(3, (int) $this->getInput('scale', 1)));

        // Cache implementation
        $cacheDir = $this->config->getOutputPath() . '/graphs';
        if (!file_exists($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }
        $cacheKey = sprintf('map_%d_link_%d_min_%d', $mapId, $linkId, $minutes) . ($scale > 1 ? '_x' . $scale : '');
        $cacheFile = $cacheDir . '/' . $cacheKey . '.png';

        // Check cache validity (5 minutes)
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 300)) {
            $this->serveImage($cacheFile, 'image/png');
            return;
        }

        try {
            $data = $selection
                ? $this->dataSourceService->getLinkBandwidthData((int) $selection['sourceId'], $selection, $minutes)
                : $this->dataSourceService->observiumBandwidthForTarget((string) $observiumTarget, null, $minutes);
        } catch (\Throwable $e) {
            // drawn as "No Data" rather than a broken picture in the hover panel
            $data = [];
        }
        $png = $this->renderBandwidthGraphPng($data, $minutes, $this->timezones()->forMap($mapId), $scale);

        // Save to cache
        file_put_contents($cacheFile, $png);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (function_exists('ini_set')) {
            @ini_set('zlib.output_compression', '0');
        }

        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=300');
        echo $png;
        exit;
    }

    private function timezones(): TimezoneService
    {
        return TimezoneService::fromConfig($this->database, $this->config);
    }

    private function serveImage(string $path, string $contentType): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (function_exists('ini_set')) {
            @ini_set('zlib.output_compression', '0');
        }
        
        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: public, max-age=300');
        readfile($path);
        exit;
    }

    /**
     * The traffic graph of a link as a PNG. `scale` (1 to 3) draws it larger
     * with the same layout, so the hover panel stays sharp on large or
     * high-density screens.
     */
    private function renderBandwidthGraphPng(array $data, int $minutes, string $timezone, int $scale = 1): string
    {
        $k = max(1, min(3, $scale));
        $w = 600 * $k;
        $h = 300 * $k;
        $padL = 75 * $k;
        $padR = 20 * $k;
        $padT = 35 * $k;
        $padB = 50 * $k;

        $im = imagecreatetruecolor($w, $h);
        imagesavealpha($im, true);
        imageantialias($im, true);

        $bg = imagecolorallocate($im, 255, 255, 255);
        $grid = imagecolorallocate($im, 230, 230, 230);
        $axis = imagecolorallocate($im, 100, 100, 100);
        $inCol = imagecolorallocate($im, 0, 160, 0);     // Green
        $outCol = imagecolorallocate($im, 0, 0, 220);    // Blue
        $txt = imagecolorallocate($im, 50, 50, 50);

        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, $bg);

        // TrueType text scales with the picture; GD's own fonts are the fallback
        $font = $this->config->getPublicPath() . '/fonts/DejaVuSansMono.ttf';
        $ttf = function_exists('imagettftext') && is_readable($font);
        $gdSize = [2 => 8.0, 3 => 9.0, 4 => 10.0];
        $textWidth = function (int $size, string $s) use ($ttf, $font, $gdSize, $k): int {
            if ($ttf) {
                $box = imagettfbbox($gdSize[$size] * $k, 0, $font, $s);
                return $box ? (int) abs($box[2] - $box[0]) : 0;
            }
            return imagefontwidth($size) * strlen($s);
        };
        // (x, y) is the top left corner of the text, as for imagestring()
        $text = function (int $size, int $x, int $y, string $s) use ($im, $txt, $ttf, $font, $gdSize, $k): void {
            if ($ttf) {
                imagettftext($im, $gdSize[$size] * $k, 0, $x, $y + (int) round($gdSize[$size] * $k * 1.2), $txt, $font, $s);
            } else {
                imagestring($im, $size, $x, $y, $s, $txt);
            }
        };

        $inSeries = (array) (($data['in']['series'] ?? []));
        $outSeries = (array) (($data['out']['series'] ?? []));

        $minT = null;
        $maxT = null;
        $maxV = 0.0;

        foreach ([$inSeries, $outSeries] as $series) {
            foreach ($series as $p) {
                $t = (int) ($p['t'] ?? 0);
                $v = (float) ($p['v'] ?? 0);
                if ($minT === null || $t < $minT) {
                    $minT = $t;
                }
                if ($maxT === null || $t > $maxT) {
                    $maxT = $t;
                }
                if ($v > $maxV) {
                    $maxV = $v;
                }
            }
        }

        if ($minT === null || $maxT === null || $maxT <= $minT) {
            $text(4, (int) ($w / 2 - $textWidth(4, 'No Data') / 2), (int) ($h / 2), 'No Data');
            ob_start();
            imagepng($im);
            $out = ob_get_clean();
            imagedestroy($im);
            return $out;
        }

        $maxV = $maxV * 1.1; // Add headroom
        if ($maxV <= 0) $maxV = 1.0;

        $plotW = $w - $padL - $padR;
        $plotH = $h - $padT - $padB;

        // Helper for units
        $formatVal = function($val) use ($data) {
            $unit = $data['in']['units'] ?? '';
            $prefixes = ['', 'K', 'M', 'G', 'T', 'P'];
            $pow = 0;
            while ($val >= 1000 && $pow < count($prefixes) - 1) {
                $val /= 1000;
                $pow++;
            }
            return round($val, 1) . ' ' . $prefixes[$pow] . $unit;
        };

        imagesetthickness($im, $k);

        // grid horizontal (values)
        for ($i = 0; $i <= 4; $i++) {
            $y = (int) ($padT + ($plotH * $i / 4));
            imageline($im, $padL, $y, $padL + $plotW, $y, $grid);

            $val = $maxV * (1 - ($i / 4));
            $label = $formatVal($val);
            $text(2, $padL - 5 * $k - $textWidth(2, $label), $y - 7 * $k, $label);
        }

        // grid vertical (time)
        for ($i = 0; $i <= 5; $i++) {
            $x = (int) ($padL + ($plotW * $i / 5));
            imageline($im, $x, $padT, $x, $padT + $plotH, $grid);

            $tVal = $minT + (($maxT - $minT) * $i / 5);
            $tLabel = TimezoneService::formatTimestamp((int) $tVal, $timezone, 'H:i');
            $text(2, $x - (int) ($textWidth(2, $tLabel) / 2), $padT + $plotH + 5 * $k, $tLabel);
        }

        // axis box
        imagerectangle($im, $padL, $padT, $padL + $plotW, $padT + $plotH, $axis);

        imagesetthickness($im, 2 * $k);

        $drawSeries = function(array $series, int $color) use ($im, $padL, $padT, $plotW, $plotH, $minT, $maxT, $maxV): void {
            $prev = null;
            // Sort by time
            usort($series, fn($a, $b) => ((int)$a['t']) <=> ((int)$b['t']));

            foreach ($series as $p) {
                $t = (int) ($p['t'] ?? 0);
                $v = (float) ($p['v'] ?? 0);
                $x = (int) round($padL + (($t - $minT) / max(1, ($maxT - $minT))) * $plotW);
                $y = (int) round($padT + (1 - ($v / $maxV)) * $plotH);

                if ($prev !== null) {
                    imageline($im, $prev[0], $prev[1], $x, $y, $color);
                }
                $prev = [$x, $y];
            }
        };

        $drawSeries($outSeries, $outCol);
        $drawSeries($inSeries, $inCol);

        imagesetthickness($im, 1);

        // Title
        $hostName = $data['hostname'] ?? '';
        $itemName = $data['in']['name'] ?? '';

        // Clean up interface name
        if (preg_match('/^Interface\s+(.+?):/i', $itemName, $m)) {
            $itemName = $m[1];
        }

        $titlePart = $itemName ?: 'Traffic';
        if ($hostName !== '') {
            $titlePart = $hostName . ' - ' . $titlePart;
        }

        $title = $titlePart;
        $text(4, (int) (($w - $textWidth(4, $title)) / 2), 8 * $k, $title);

        // Legend
        $lx = $padL + (int)($plotW/2) - 80 * $k;
        $ly = $h - 25 * $k;

        // In
        imagefilledrectangle($im, $lx, $ly, $lx + 10 * $k, $ly + 10 * $k, $inCol);
        $text(3, $lx + 16 * $k, $ly - 2 * $k, 'Inbound');

        // Out
        $lx += 100 * $k;
        imagefilledrectangle($im, $lx, $ly, $lx + 10 * $k, $ly + 10 * $k, $outCol);
        $text(3, $lx + 16 * $k, $ly - 2 * $k, 'Outbound');

        ob_start();
        imagepng($im);
        $out = ob_get_clean();
        imagedestroy($im);
        return $out;
    }

    public function image(array $params): void
    {
        $mapId = (int) ($params['id'] ?? 0);
        $fast = ((string) $this->getInput('fast', '0') === '1');
        
        if ($this->config->isAuthEnabled() && !$this->auth->canViewMap($mapId)) {
            http_response_code(403);
            exit;
        }
        
        $map = $this->getMap($mapId);
        
        if (!$map) {
            http_response_code(404);
            exit;
        }
        
        $format = $this->config->get('MAP_OUTPUT_FORMAT', 'png');
        $outputBase = pathinfo($map['config_file'], PATHINFO_FILENAME);
        $suffix = $fast ? '.fast' : '';
        $imageFile = $this->config->getOutputPath() . '/' . $outputBase . $suffix . '.' . $format;

        // In fast mode we want a quick, cached render WITHOUT datasource queries.
        // In full mode keep current behaviour (render on demand).
        $this->mapService->renderMap($mapId, $fast ? false : true, !$fast);
        
        if (!file_exists($imageFile)) {
            error_log("Map image missing, attempting render for map_id={$mapId}, expected={$imageFile}");
            $this->mapService->renderMap($mapId, $fast ? false : true, !$fast);
            error_log("After render: file exists=" . (file_exists($imageFile) ? 'YES' : 'NO'));
            if (!file_exists($imageFile)) {
                http_response_code(404);
                exit;
            }
        }
        
        $mimeTypes = [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'gif' => 'image/gif',
        ];
        
        // Ensure nothing else is appended to the binary response (warnings/whitespace/buffers)
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (function_exists('ini_set')) {
            @ini_set('zlib.output_compression', '0');
        }

        header('Content-Type: ' . ($mimeTypes[$format] ?? 'image/png'));
        if ($fast) {
            header('Cache-Control: public, max-age=300');
        } else {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        }
        header('Content-Length: ' . filesize($imageFile));
        readfile($imageFile);
        exit;
    }
    
    public function thumbnail(array $params): void
    {
        $mapId = (int) ($params['id'] ?? 0);
        $fast = ((string) $this->getInput('fast', '0') === '1');
        
        if ($this->config->isAuthEnabled() && !$this->auth->canViewMap($mapId)) {
            http_response_code(403);
            exit;
        }
        
        $map = $this->getMap($mapId);
        
        if (!$map) {
            http_response_code(404);
            exit;
        }
        
        $format = $this->config->get('MAP_OUTPUT_FORMAT', 'png');
        $outputBase = pathinfo($map['config_file'], PATHINFO_FILENAME);
        $suffix = $fast ? '.fast' : '';
        $thumbFile = $this->config->getOutputPath() . '/' . $outputBase . $suffix . '.thumb.' . $format;
        $imageFile = $this->config->getOutputPath() . '/' . $outputBase . $suffix . '.' . $format;

        // In fast mode we want a quick, cached render WITHOUT datasource queries.
        // In full mode keep current behaviour (render on demand).
        $this->mapService->renderMap($mapId, $fast ? false : true, !$fast);
        
        if (!file_exists($thumbFile)) {
            // Ensure thumbnail exists (render on demand). If thumb still missing, fall back to main image.
            $this->mapService->renderMap($mapId, $fast ? false : true, !$fast);

            if (!file_exists($thumbFile)) {
                // Some older caches might have a main image but no thumbnail. Force a re-render once.
                if (file_exists($imageFile)) {
                    $this->mapService->renderMap($mapId, $fast ? false : true, !$fast);
                }
            }

            if (!file_exists($thumbFile)) {
                $this->image($params);
                return;
            }
        }
        
        $mimeTypes = [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'gif' => 'image/gif',
        ];
        
        // Ensure nothing else is appended to the binary response (warnings/whitespace/buffers)
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (function_exists('ini_set')) {
            @ini_set('zlib.output_compression', '0');
        }

        header('Content-Type: ' . ($mimeTypes[$format] ?? 'image/png'));
        if ($fast) {
            header('Cache-Control: public, max-age=300');
        } else {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        }
        header('Content-Length: ' . filesize($thumbFile));
        readfile($thumbFile);
        exit;
    }
    
    public function cycle(array $params): void
    {
        $this->requireAuth();
        
        $groupId = (int) ($params['group'] ?? $this->getInput('group', 0));
        $fullscreen = (bool) $this->getInput('fullscreen', false);
        
        $maps = $this->getMapsForUser($groupId);
        
        $this->render('maps/cycle', [
            'maps' => $maps,
            'kiosk' => (new KioskService($this->database))->resolve($maps),
            'groupId' => $groupId,
            'fullscreen' => $fullscreen,
            'refreshInterval' => (int) $this->config->get('MAP_REFRESH_INTERVAL', 300),
            'title' => __('Map Cycle'),
        ], !$fullscreen);
    }
    
    private function getMapsForUser(int $groupId = 0): array
    {
        $sql = "SELECT DISTINCT m.*, g.name as group_name 
                FROM maps m 
                LEFT JOIN map_groups g ON m.group_id = g.id 
                WHERE m.active = 1";
        $params = [];
        
        if ($groupId > 0) {
            $sql .= " AND m.group_id = ?";
            $params[] = $groupId;
        }
        
        // Apply permission filters in SQL for non-admins
        if ($this->config->isAuthEnabled() && !$this->auth->canEdit()) {
            $user = $this->auth->user();
            $userId = $user['id'] ?? 0;
            
            $sql .= " AND (
                -- Public maps
                EXISTS (SELECT 1 FROM user_map_permissions ump WHERE ump.map_id = m.id AND ump.user_id = 0)
                OR 
                -- User specific permissions
                EXISTS (SELECT 1 FROM user_map_permissions ump WHERE ump.map_id = m.id AND ump.user_id = ?)
                OR
                -- Group permissions
                EXISTS (SELECT 1 FROM user_group_permissions ugp WHERE ugp.group_id = m.group_id AND ugp.user_id = ?)
            )";
            $params[] = $userId;
            $params[] = $userId;
        }
        
        $sql .= " ORDER BY g.sort_order, m.sort_order, m.name";
        
        return $this->database->query($sql, $params);
    }
    
    private function getMapGroups(): array
    {
        return $this->database->query(
            "SELECT * FROM map_groups ORDER BY sort_order, name"
        );
    }
    
    private function getMap(int $id): ?array
    {
        return $this->database->queryOne(
            "SELECT m.*, g.name as group_name 
             FROM maps m 
             LEFT JOIN map_groups g ON m.group_id = g.id 
             WHERE m.id = ?",
            [$id]
        );
    }
}
