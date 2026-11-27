<?php
/**
 * Zabbix Weathermap - Map Controller
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\ErrorPage;
use App\Services\MapService;
use App\Services\DemoMaps;
use App\Services\GroupService;
use App\Services\DataSourceService;
use App\Services\CommandPalette;
use App\Services\KioskService;
use App\Services\RecordingService;
use App\Services\TimezoneService;
use App\Services\ClockFormat;
use App\Services\TotalTrafficSeries;
use App\Services\TrafficSeries;
use App\Services\TrafficTotalSource;

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
    /**
     * The one Maps page: every map the user can see, as thumbnails or as a
     * list, with filter chips (all, favourites, each group, over 80 %).
     * Editors also see inactive maps and manage them from here.
     */
    public function index(array $params): void
    {
        $this->requireAuth();

        $canEdit = $this->auth->canEdit();
        $favourites = $this->mapService->favouriteMapIds($this->currentUserId());
        $maps = $this->getMapsForUser(0, $canEdit);
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

        // a chip per group that holds at least one of these maps
        $counts = array_count_values(array_map(static fn ($m) => (int) $m['group_id'], $maps));
        $groups = array_values(array_filter($this->getMapGroups(), static fn ($g) => isset($counts[(int) $g['id']])));

        $filter = (string) $this->getInput('filter', '');
        $groupId = (int) $this->getInput('group_id', 0);
        if ($groupId > 0 && isset($counts[$groupId])) {
            $filter = 'group:' . $groupId;
        } elseif (!in_array($filter, ['favourites', 'busy'], true)) {
            $filter = 'all';
        }

        $this->render('maps/index', [
            'maps' => $maps,
            'favourites' => $favourites,
            'statuses' => $statuses,
            'timezones' => $this->timezones()->forMaps(array_column($maps, 'id')),
            // the clock of each map: 24h or 12h (F51)
            'clocks' => (new ClockFormat($this->database))->forMaps(array_map('intval', array_column($maps, 'id'))),
            'groups' => $groups,
            'groupCounts' => $counts,
            'allGroups' => $canEdit ? $this->getMapGroups() : [],
            'filter' => $filter,
            'canEdit' => $canEdit,
            'canImport' => $this->auth->isAdmin(),
            // the demo maps an administrator can still add (F55)
            'missingDemos' => $this->auth->isAdmin() ? $this->missingDemos() : [],
            // maps whose drawn title still differs from their name (before step 5)
            'titleConflicts' => $canEdit ? $this->mapService->titleConflicts($maps) : [],
            'title' => __('Maps'),
        ]);
    }
    
    /**
     * GET /palette: what the Ctrl+K command palette searches, as JSON
     * (maps, their nodes, kiosk groups, Settings pages for admins).
     */
    public function palette(array $params): void
    {
        $this->requireAuth();

        $canEdit = $this->auth->canEdit();
        $maps = $this->getMapsForUser(0, $canEdit);
        $configs = [];
        $statuses = [];
        foreach ($maps as $m) {
            $id = (int) $m['id'];
            $configs[$id] = $this->mapService->getMapConfig($id);
            $status = $this->mapService->mapStatus($m);
            $statuses[$id] = $status === null ? null : ['max' => $status['max'] ?? null, 'level' => $status['level'] ?? ''];
        }
        $user = $this->auth->user();

        header('Cache-Control: private, no-store');
        $this->json(CommandPalette::index(
            $maps,
            $configs,
            $statuses,
            $this->mapService->favouriteMapIds($this->currentUserId()),
            [
                'edit' => $canEdit,
                'admin' => $this->auth->isAdmin(),
                'account' => $this->config->isAuthEnabled() && (string) ($user['username'] ?? '') !== '',
            ]
        ));
    }

    /**
     * The viewer: the map drawn as SVG, with zoom, hover cards, side panel,
     * site focus and playback. ?fullscreen=1 is the same page without the
     * application shell (the kiosk link), ?kiosk=1 the bare map that the
     * kiosk shows (no toolbar, no panel; the kiosk keeps the keys).
     * The old picture views (?view=png, ?mode=preview) open the viewer: the
     * PNG is only made when someone downloads it.
     */
    public function view(array $params): void
    {
        $this->requireAuth();

        $mapId = (int) ($params['id'] ?? 0);
        $fullscreen = (bool) $this->getInput('fullscreen', false);
        $kiosk = (bool) $this->getInput('kiosk', false);

        if (!$this->auth->canViewMap($mapId)) {
            ErrorPage::send(403, $this->auth);
            return;
        }

        $map = $this->getMap($mapId);

        if (!$map) {
            ErrorPage::send(404, $this->auth, __('Map not found'), __('This map does not exist any more, or its address is wrong.'));
            return;
        }

        if ((string) $this->getInput('view', '') === 'png' || (string) $this->getInput('mode', '') === 'preview') {
            $this->redirect(self::viewerUrl($mapId, $fullscreen));
        }

        $autoRefresh = max(0, (int) $this->getInput('autorefresh', 0));

        $timezones = $this->timezones();
        $timezone = $timezones->forMap($mapId);

        $this->render('maps/view', [
            'map' => $map,
            'timezone' => $timezone,
            'fullscreen' => $fullscreen || $kiosk,
            'kiosk' => $kiosk,
            // the kiosk's fixed zoom, in % of the map's own size (0: fit the screen)
            'zoom' => $kiosk ? max(0, min(400, (int) $this->getInput('zoom', 0))) : 0,
            'autoRefresh' => $autoRefresh,
            'history' => !$kiosk && $this->historyAvailable($mapId),
            // where the total traffic graph comes from (TotalTrafficSeries)
            'totalsSource' => $this->totalsSource($mapId),
            'historyHours' => $this->recording()->getRetention(),
            'title' => $map['title_cache'] ?: $map['name'],
        ], !($fullscreen || $kiosk));
    }

    /** The viewer's address for a map, on the page or fullscreen */
    public static function viewerUrl(int $mapId, bool $fullscreen = false): string
    {
        return '/map/' . $mapId . ($fullscreen ? '?fullscreen=1' : '');
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
            // the focus areas of the map (SET focus_*), null when it has none
            'focus' => $summary['focus'] ?? null,
            // the viewer panels and where they go (SET viewer_panels), null for the usual layout
            'panels' => $summary['panels'] ?? null,
            // the kiosk's own panels (SET kiosk_panels), null when it follows the viewer's corners
            'kiosk_panels' => $summary['kiosk_panels'] ?? null,
            'version' => $this->configVersion($mapId),
        ]);
    }

    /** GET: the live map as an SVG file to download (Download as SVG) */
    public function svgDownload(array $params): void
    {
        $mapId = (int) ($params['id'] ?? 0);
        if (!$this->canView($mapId)) {
            http_response_code(403);
            return;
        }
        $map = $this->getMap($mapId);
        $svg = $map ? $this->mapService->svgFile($mapId, true) : null;
        if ($svg === null) {
            http_response_code(404);
            return;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . self::downloadName((string) $map['name']) . '.svg"');
        header('Cache-Control: no-store');
        header('Content-Length: ' . strlen($svg));
        echo $svg;
    }

    /** A file name from a map name: letters, digits, dot, dash and underscore */
    public static function downloadName(string $name): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $name), '-.') ?: 'map';
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

    /**
     * GET: the total traffic graph of a map for ?period= (day, week or
     * month) and ?group= (a link group, SET traffic_group; none for the
     * total): {source, period, periods: {day, week, month: bool}, groups,
     * group, from, to (the period), since (the first recorded point, or
     * null), frames: [[time, in, out]...], aggregated, stats: {in, out:
     * {p95, peak}}}. The frames are the recorded ones, or for a map of
     * static data a demo period that ends on its current totals
     * (TotalTrafficSeries).
     *
     * With ?format=series (the zoomed graph, drawn by WM.trafficPanel):
     * every point of the period as the link traffic route gives them,
     * {from, to, units, tz, in: [[time, value]...], out}; ?range= then
     * names the period.
     */
    public function totals(array $params): void
    {
        $mapId = (int) ($params['id'] ?? 0);
        if (!$this->canView($mapId)) {
            $this->json(['error' => 'Forbidden'], 403);
            return;
        }
        $series = $this->getInput('format', '') === 'series';
        $period = $this->getInput($series ? 'range' : 'period', 'day');
        $period = TotalTrafficSeries::period(is_string($period) ? $period : 'day');
        $group = TotalTrafficSeries::group($this->getInput('group', ''));
        $now = time();
        [$source, $rows, $groups, $group, $periods] = $this->totalRows($mapId, $period, $group, $now);
        header('Cache-Control: no-store');
        if ($series) {
            $this->json(TotalTrafficSeries::series($rows, $period, $now) + ['tz' => $this->timezones()->forMap($mapId)]);
            return;
        }
        // the graph spans the whole period (from, to), and says since when it
        // is recorded when the history is shorter (F39)
        $this->json(['source' => $source, 'period' => $period, 'periods' => $periods, 'groups' => $groups, 'group' => $group,
            'from' => $now - TotalTrafficSeries::PERIODS[$period], 'to' => $now,
            'since' => $source === TotalTrafficSeries::RECORDED ? TotalTrafficSeries::since($rows) : null]
            + TotalTrafficSeries::view($rows, $period, $now));
    }

    /**
     * The rows of the total traffic graph of a map: [source, rows, the link
     * groups, the group shown ('' for the total, or an unknown group),
     * which periods have data]
     *
     * @return array{0: string, 1: list<array{0: int, 1: float|null, 2: float|null}>, 2: list<string>, 3: string, 4: array<string, bool>}
     */
    private function totalRows(int $mapId, string $period, string $group, int $now): array
    {
        // F79: a tab whose total comes from Observium shows Observium's history
        $sources = TrafficTotalSource::fromConfig((string) $this->mapService->getMapConfig($mapId));
        if ($sources === []) {
            return $this->linkTotalRows($mapId, $period, $group, $now);
        }
        $all = array_fill_keys(array_keys(TotalTrafficSeries::PERIODS), true);
        $none = array_fill_keys(array_keys(TotalTrafficSeries::PERIODS), false);
        $wanted = $group;
        [$linkSource, $rows, $groups, $group, $periods] = $this->linkTotalRows($mapId, $period, $group, $now);
        $groups = array_values(array_unique(array_merge($groups, array_values(array_filter(array_map('strval', array_keys($sources)))))));
        natcasesort($groups);
        $groups = array_values($groups);
        $tab = in_array($wanted, $groups, true) ? $wanted : '';
        if (!isset($sources[$tab])) {
            return [$linkSource, $rows, $groups, $group, $periods];
        }
        $rows = $this->sourceRows($mapId, $tab, $sources[$tab], $period, $now);
        return [TotalTrafficSeries::SOURCE, $rows, $groups, $tab, $rows === [] ? $none : $all];
    }

    /**
     * The history of a total counted from Observium (F79), cached five
     * minutes like the link graphs; [] when it cannot be read (the status
     * bar tells why).
     *
     * @param array{kind: string, group?: string, ports?: list<int>} $source
     * @return list<array{0: int, 1: float|null, 2: float|null}>
     */
    private function sourceRows(int $mapId, string $tab, array $source, string $period, int $now): array
    {
        $dir = $this->config->getOutputPath() . '/graphs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = sprintf('%s/map_%d_total_%s_%s.json', $dir, $mapId, md5($tab . "\0" . TrafficTotalSource::format($source)), $period);
        $cached = is_file($file) && $now - (int) filemtime($file) < 300 ? json_decode((string) file_get_contents($file), true) : null;
        if (is_array($cached)) {
            return $cached;
        }
        try {
            $rows = $this->dataSourceService->sourceHistory($source, intdiv(TotalTrafficSeries::PERIODS[$period], 60));
        } catch (\Throwable) {
            $rows = [];
        }
        @file_put_contents($file, json_encode($rows));
        return $rows;
    }

    /**
     * The rows of the total traffic graph from the links of the map, as
     * totalRows() returns them.
     *
     * @return array{0: string, 1: list<array{0: int, 1: float|null, 2: float|null}>, 2: list<string>, 3: string, 4: array<string, bool>}
     */
    private function linkTotalRows(int $mapId, string $period, string $group, int $now): array
    {
        $recording = $this->recording();
        if ($recording->canPlay($mapId)) {
            $groups = $recording->totalGroups($mapId, $now);
            $group = in_array($group, $groups, true) ? $group : '';
            $rows = $recording->totals($mapId, $now, $group === '' ? null : $group);
            return [TotalTrafficSeries::RECORDED, $rows, $groups, $group, TotalTrafficSeries::periods($rows, $now)];
        }
        $none = array_fill_keys(array_keys(TotalTrafficSeries::PERIODS), false);
        $live = $this->linkTotalsSource($mapId, $this->mapService->getMapConfig($mapId)) === TotalTrafficSeries::DEMO ? $this->mapService->liveRender($mapId) : null;
        if ($live === null) {
            return [TotalTrafficSeries::NONE, [], [], '', $none];
        }
        $items = (array) ($live['items'] ?? []);
        $groups = MapService::trafficGroups($items);
        $group = in_array($group, $groups, true) ? $group : '';
        $total = MapService::trafficTotal($items, $group === '' ? null : $group);
        $rows = TotalTrafficSeries::demo($total['in'], $total['out'], $now, $period);
        // a demo has every period
        return [TotalTrafficSeries::DEMO, $rows, $groups, $group, $rows === [] ? $none : array_fill_keys(array_keys($none), true)];
    }

    /** Where the total traffic graph of a map comes from: recorded, demo or none */
    private function totalsSource(int $mapId): string
    {
        $config = $this->mapService->getMapConfig($mapId);
        // F79: some tab counts Observium ports, which have their own history
        if ($config !== null && TrafficTotalSource::fromConfig($config) !== []) {
            return TotalTrafficSeries::SOURCE;
        }
        return $this->linkTotalsSource($mapId, $config);
    }

    /** Where the graph of a total of links comes from: recorded, demo or none */
    private function linkTotalsSource(int $mapId, ?string $config): string
    {
        $recorded = $this->recording()->canPlay($mapId);
        return TotalTrafficSeries::source(
            $recorded,
            $config !== null && TotalTrafficSeries::staticOnly(TotalTrafficSeries::configTargets($config))
        );
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
            'status' => self::publicStatus((array) ($frame['status'] ?? []) + ['items' => (array) ($frame['items'] ?? [])]),
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
            'scale' => $summary['scale'] ?? [],
            // frames recorded before the total existed: work it out from their items
            'total' => $summary['total'] ?? MapService::trafficTotal((array) ($summary['items'] ?? [])),
            // the group tabs counted from elsewhere than their links (F79)
            'group_totals' => $summary['group_totals'] ?? null,
            'basemap' => $summary['basemap'] ?? null,
            // the data sources that failed in this render (F49): name, unreachable, links without data;
            // the error text itself stays in the log (it may name internal hosts)
            'sources' => self::publicSources($summary),
            'nodata' => (int) ($summary['nodata'] ?? 0),
        ];
    }

    /** @return list<array{name: string, down: bool, links: int}> */
    public static function publicSources(array $summary): array
    {
        return array_values(array_map(static fn ($s) => [
            'name' => (string) ($s['name'] ?? ''),
            'down' => !empty($s['down']),
            'links' => (int) ($s['links'] ?? 0),
        ], array_filter((array) ($summary['sources'] ?? []), 'is_array')));
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
        [$selection, $observiumTarget] = $this->linkSource($mapId, $linkId);

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

    /**
     * Where the traffic history of a link comes from: the data source
     * selection set up with the editor's picker, or else an Observium TARGET
     * (read from the first active Observium source). Answers 403 or 404 and
     * stops when the map or the link has none.
     *
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    private function linkSource(int $mapId, int $linkId): array
    {
        if ($this->config->isAuthEnabled() && !$this->auth->canViewMap($mapId)) {
            http_response_code(403);
            exit;
        }

        $map = $this->getMap($mapId);
        $mapFile = $map ? $this->config->getConfigsPath() . '/' . $map['config_file'] : '';
        if (!$map || !file_exists($mapFile)) {
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

        $selection = $targetLink ? json_decode((string) ($targetLink->hints['datasource'] ?? ''), true) : null;
        if (!is_array($selection) || empty($selection['sourceId'])) {
            $selection = null;
        }
        $observiumTarget = $targetLink && !$selection ? MapService::observiumPortTarget($targetLink) : null;
        if (!$selection && $observiumTarget === null) {
            http_response_code(404);
            exit;
        }

        return [$selection, $observiumTarget];
    }

    /**
     * GET ?range=day|week|month: the IN and OUT traffic of a link over that
     * period, for the hover card of the viewer (see TrafficSeries), with the
     * map's time zone. Cached 5 minutes like the graph picture.
     */
    public function linkTraffic(array $params): void
    {
        $mapId = (int) ($params['id'] ?? 0);
        $linkId = (int) ($params['linkId'] ?? 0);
        $range = TrafficSeries::range((string) $this->getInput('range', 'day'));
        [$selection, $observiumTarget] = $this->linkSource($mapId, $linkId);

        $cacheDir = $this->config->getOutputPath() . '/graphs';
        if (!file_exists($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }
        $cacheFile = sprintf('%s/map_%d_link_%d_%s.json', $cacheDir, $mapId, $linkId, $range);
        $cached = is_file($cacheFile) && time() - (int) filemtime($cacheFile) < 300
            ? json_decode((string) file_get_contents($cacheFile), true)
            : null;

        if (!is_array($cached)) {
            $minutes = TrafficSeries::minutes($range);
            try {
                $data = $selection
                    ? $this->dataSourceService->getLinkBandwidthData((int) $selection['sourceId'], $selection, $minutes)
                    : $this->dataSourceService->observiumBandwidthForTarget((string) $observiumTarget, null, $minutes);
            } catch (\Throwable $e) {
                // shown as "no data" in the card rather than an error
                $data = [];
            }
            $cached = TrafficSeries::fromBandwidthData($data, $range, time());
            @file_put_contents($cacheFile, json_encode($cached));
        }

        header('Cache-Control: private, max-age=300');
        $this->json($cached + ['tz' => $this->timezones()->forMap($mapId)]);
    }

    /** @return list<string> the demo maps that are not there yet, [] when they cannot be read */
    private function missingDemos(): array
    {
        try {
            return (new DemoMaps($this->mapService, new GroupService($this->database)))->missing();
        } catch (\Throwable) {
            return [];
        }
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
        return $out;
    }

    /**
     * GET: the map as a picture, in the image format of Settings (PNG,
     * JPG or GIF). With live data it is the last render while it is
     * younger than the refresh interval and newer than the configuration,
     * else one render at a time draws it again (MapService::liveImage()).
     * ?fast=1 draws without reading the data sources (cached until the
     * configuration changes).
     */
    public function image(array $params): void
    {
        $this->sendPicture($params, false);
    }

    /** GET: the thumbnail of the map, as image() */
    public function thumbnail(array $params): void
    {
        $this->sendPicture($params, true);
    }

    private function sendPicture(array $params, bool $thumb): void
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

        // SVG pictures (F63): the live SVG with its fonts; thumbnails and
        // ?raster=1 (the viewer's fallback) stay a raster file
        if (!$thumb && !$fast && $this->mapService->imageFormat() === 'svg' && (string) $this->getInput('raster', '0') !== '1') {
            $svg = $this->mapService->svgFile($mapId);
            if ($svg === null) {
                http_response_code(404);
                exit;
            }
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: image/svg+xml; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Content-Length: ' . strlen($svg));
            echo $svg;
            exit;
        }

        $format = $this->mapService->rasterFormat();
        if ($fast) {
            $this->mapService->renderMap($mapId, false, false);
            $base = $this->config->getOutputPath() . '/' . pathinfo($map['config_file'], PATHINFO_FILENAME) . '.fast';
            $file = $base . ($thumb ? '.thumb.' : '.') . $format;
            if ($thumb && !is_file($file)) {
                $file = $base . '.' . $format;
            }
        } else {
            $file = $this->mapService->liveImage($mapId, $thumb) ?? $this->mapService->liveImage($mapId);
        }

        if ($file === null || !is_file($file)) {
            error_log("Map picture missing for map_id={$mapId}");
            http_response_code(404);
            exit;
        }

        // Nothing else may be appended to the binary response (warnings, whitespace, buffers)
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (function_exists('ini_set')) {
            @ini_set('zlib.output_compression', '0');
        }

        header('Content-Type: ' . (self::IMAGE_TYPES[$format] ?? 'image/png'));
        header($fast ? 'Cache-Control: public, max-age=300' : 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }

    /** Media types of the image formats of Settings > Maps & rendering */
    public const IMAGE_TYPES = ['svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif'];

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
            'title' => __('Kiosk'),
        ], !$fullscreen);
    }
    
    /** Maps the user can see; inactive ones too when $withInactive (editors) */
    private function getMapsForUser(int $groupId = 0, bool $withInactive = false): array
    {
        $sql = "SELECT DISTINCT m.*, g.name as group_name 
                FROM maps m 
                LEFT JOIN map_groups g ON m.group_id = g.id 
                WHERE " . ($withInactive ? '1 = 1' : 'm.active = 1');
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
