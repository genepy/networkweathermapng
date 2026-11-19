<?php
/**
 * NetworkWeathermapNG - SVG map editor
 *
 * The page draws the map as SVG and edits it through batches of operations
 * (see App\Services\Editor\MapEditor). Every batch is one undo step.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Editor\ConfigHistory;
use App\Services\Editor\ConfigSource;
use App\Services\Editor\MapClipboard;
use App\Services\Editor\MapEditor;
use App\Services\Editor\MapThemes;
use App\Services\Images\BackgroundLibrary;
use App\Services\Images\IconLibrary;
use App\Services\MapService;
use App\Services\TimezoneService;
use InvalidArgumentException;

class SvgEditorController extends BaseController
{
    private MapService $mapService;

    /** Output buffer level when the request reached this controller */
    private int $bufferLevel;

    public function __construct(array $context)
    {
        parent::__construct($context);
        $this->mapService = new MapService($this->database, $this->config);

        // Anything printed while the request runs (an engine warning, a PHP
        // notice with display_errors on) would break the JSON answer: it is
        // caught here and dropped by json(), so the answer stays readable.
        $this->bufferLevel = ob_get_level();
        ob_start();
    }

    /** The JSON answer, without the stray output printed before it */
    protected function json(mixed $data, int $status = 200): void
    {
        $this->dropStrayOutput();
        parent::json($data, $status);
    }

    private function dropStrayOutput(): void
    {
        $stray = '';
        while (ob_get_level() > $this->bufferLevel) {
            $stray = (string) ob_get_clean() . $stray;
        }
        if (trim($stray) !== '') {
            error_log('SVG editor: output dropped from the answer: ' . substr(trim($stray), 0, 500));
        }
    }

    public function edit(array $params): void
    {
        $this->requireEditor();

        $map = $this->findMap($params);
        if (!$map) {
            $this->flash('error', __('Map not found'));
            $this->redirect('/');
            return;
        }

        $this->loadEngine();
        $library = $this->icons();

        $this->render('editor/svg', [
            'map' => $map,
            'mapId' => (int) $map['id'],
            'icons' => $library->paths(),
            'iconThemes' => $library->themes(),
            'backgrounds' => $this->backgrounds()->all(),
            'fonts' => MapEditor::shippedFonts(),
            'fontFamilies' => MapEditor::fontFamilies(),
            'themeColours' => MapThemes::colours(),
            'themes' => MapThemes::previews(),
            'title' => __('Edit Map: :name', ['name' => $map['name']]),
        ], false);
    }

    /** GET: the icon library, by theme */
    public function iconList(): void
    {
        $this->requireEditor();
        $this->json($this->iconLibrary());
    }

    /** POST multipart: theme, icons[] (PNG, GIF, JPEG or SVG files) */
    public function addIcons(): void
    {
        $this->requireEditor();

        $theme = (string) ($_POST['theme'] ?? '');
        $files = $_FILES['icons'] ?? null;
        if (!is_array($files) || !is_array($files['name'] ?? null) || $files['name'] === []) {
            $this->json(['error' => __('No file')], 422);
            return;
        }

        $added = [];
        $errors = [];
        foreach ($files['name'] as $i => $name) {
            $name = (string) $name;
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $files['tmp_name'][$i])) {
                $errors[] = $name . ': ' . __('the upload failed');
                continue;
            }
            try {
                $added[] = $this->icons()->add($theme, $name, (string) file_get_contents((string) $files['tmp_name'][$i]));
            } catch (\RuntimeException $e) {
                $errors[] = $name . ': ' . $e->getMessage();
            }
        }

        $this->json($this->iconLibrary() + ['added' => $added, 'errors' => $errors], $added ? 200 : 422);
    }

    /** POST {path}: remove an icon no map uses */
    public function deleteIcon(): void
    {
        $this->requireEditor();

        try {
            $this->icons()->delete((string) ($this->body()['path'] ?? ''));
        } catch (\RuntimeException $e) {
            $this->json(['error' => $e->getMessage()], 409);
            return;
        }

        $this->json($this->iconLibrary());
    }

    /** GET: the background library */
    public function backgroundList(): void
    {
        $this->requireEditor();
        $this->json(['backgrounds' => $this->backgrounds()->all()]);
    }

    /** POST multipart: backgrounds[] (PNG, JPEG or GIF files) */
    public function addBackgrounds(): void
    {
        $this->requireEditor();

        $files = $_FILES['backgrounds'] ?? null;
        if (!is_array($files) || !is_array($files['name'] ?? null) || $files['name'] === []) {
            $this->json(['error' => __('No file')], 422);
            return;
        }

        $added = [];
        $errors = [];
        foreach ($files['name'] as $i => $name) {
            $name = (string) $name;
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $files['tmp_name'][$i])) {
                $errors[] = $name . ': ' . __('the upload failed');
                continue;
            }
            try {
                $added[] = $this->backgrounds()->add($name, (string) file_get_contents((string) $files['tmp_name'][$i]));
            } catch (\RuntimeException $e) {
                $errors[] = $name . ': ' . $e->getMessage();
            }
        }

        $this->json(['backgrounds' => $this->backgrounds()->all(), 'added' => $added, 'errors' => $errors], $added ? 200 : 422);
    }

    /** POST {path}: remove a background no map uses */
    public function deleteBackground(): void
    {
        $this->requireEditor();

        try {
            $this->backgrounds()->delete((string) ($this->body()['path'] ?? ''));
        } catch (\RuntimeException $e) {
            $this->json(['error' => $e->getMessage()], 409);
            return;
        }

        $this->json(['backgrounds' => $this->backgrounds()->all()]);
    }

    /** GET: the map as SVG plus the editable model */
    public function renderMap(array $params): void
    {
        $this->requireEditor();

        $map = $this->findMap($params);
        if (!$map) {
            $this->json(['error' => __('Map not found')], 404);
            return;
        }

        $this->respond($map, ($_GET['data'] ?? '1') !== '0');
    }

    /** POST {ops: [...]} */
    public function ops(array $params): void
    {
        $this->requireEditor();

        $map = $this->findMap($params);
        if (!$map) {
            $this->json(['error' => __('Map not found')], 404);
            return;
        }

        $ops = $this->body()['ops'] ?? null;
        if (!is_array($ops) || $ops === []) {
            $this->json(['error' => __('No operations')], 422);
            return;
        }

        $this->loadEngine();
        $file = $this->configFile($map);
        $history = $this->history($map);
        $before = is_file($file) ? (string) file_get_contents($file) : null;

        try {
            $result = $this->engine(static fn () => (new MapEditor($file))->apply($ops));
        } catch (InvalidArgumentException $e) {
            $this->json(['error' => $e->getMessage()], 422);
            return;
        } catch (\Throwable $e) {
            error_log('SVG editor: ' . $e->getMessage());
            $this->json(['error' => __('The map could not be saved')], 500);
            return;
        }

        if ($before !== null) {
            $history->record($before);
        }
        $this->followTitle($map, $before);

        $this->respond($map, true, $result);
    }

    /** POST {nodes: [...]}: the nodes and the links between them, as .conf text */
    public function copy(array $params): void
    {
        $this->requireEditor();

        $map = $this->findMap($params);
        if (!$map) {
            $this->json(['error' => __('Map not found')], 404);
            return;
        }

        $body = $this->body();
        $this->loadEngine();

        try {
            $file = $this->configFile($map);
            $clip = $this->engine(static fn () => MapClipboard::copy(
                (new MapEditor($file))->load(),
                array_map('strval', (array) ($body['nodes'] ?? []))
            ));
        } catch (InvalidArgumentException $e) {
            $this->json(['error' => $e->getMessage()], 422);
            return;
        }

        $clip['mapId'] = (int) $map['id'];
        $this->json($clip);
    }

    /** GET: the .conf text, for the text mode */
    public function source(array $params): void
    {
        $this->requireEditor();

        $map = $this->findMap($params);
        if (!$map) {
            $this->json(['error' => __('Map not found')], 404);
            return;
        }

        $file = $this->configFile($map);
        clearstatcache(true, $file);
        $this->json([
            'text' => is_file($file) ? (string) file_get_contents($file) : '',
            'version' => $this->version($file),
        ]);
    }

    /** POST {text}: the engine's warnings about a text, with their lines */
    public function validate(array $params): void
    {
        $this->requireEditor();

        $map = $this->findMap($params);
        if (!$map) {
            $this->json(['error' => __('Map not found')], 404);
            return;
        }

        $body = $this->body();
        if (!is_string($body['text'] ?? null)) {
            $this->json(['error' => __('No text')], 422);
            return;
        }

        $this->loadEngine();
        $file = $this->configFile($map);
        $current = is_file($file) ? (string) file_get_contents($file) : null;

        $this->json((new ConfigSource($this->config->getPublicPath()))->validate($body['text'], $current));
    }

    /** POST {text, version?}: save the text as the map's .conf (one undo step) */
    public function saveSource(array $params): void
    {
        $this->requireEditor();

        $map = $this->findMap($params);
        if (!$map) {
            $this->json(['error' => __('Map not found')], 404);
            return;
        }

        $body = $this->body();
        if (!is_string($body['text'] ?? null) || trim($body['text']) === '') {
            $this->json(['error' => __('No text')], 422);
            return;
        }

        $this->loadEngine();
        $file = $this->configFile($map);
        clearstatcache(true, $file);
        $before = is_file($file) ? (string) file_get_contents($file) : null;

        if (isset($body['version']) && $body['version'] !== '' && (string) $body['version'] !== $this->version($file)) {
            $this->json(['error' => __('The map was changed by someone else since the text was loaded. Copy your text, reload and try again.')], 409);
            return;
        }

        $text = ConfigSource::normalise($body['text']);
        try {
            (new ConfigSource())->check($text, $before);
        } catch (InvalidArgumentException $e) {
            $this->json(['error' => $e->getMessage()], 422);
            return;
        }

        if ($before !== $text) {
            if (file_put_contents($file, $text, LOCK_EX) === false) {
                $this->json(['error' => __('The map could not be saved')], 500);
                return;
            }
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($file, true);
            }
            if ($before !== null) {
                $this->history($map)->record($before);
            }
            $this->followTitle($map, $before);
        }

        $this->respond($map, true);
    }

    public function undo(array $params): void
    {
        $this->step($params, 'undo');
    }

    public function redo(array $params): void
    {
        $this->step($params, 'redo');
    }

    /** GET: a TrueType font used by the map, for the SVG text */
    public function font(array $params): void
    {
        $this->requireEditor();

        $map = $this->findMap($params);
        $n = (int) ($params['n'] ?? 0);
        if (!$map) {
            http_response_code(404);
            return;
        }

        $file = $this->configFile($map);
        // font paths are relative to the web root
        $path = $this->engine(static function () use ($file, $n): ?string {
            $font = (new MapEditor($file))->load()->fonts[$n] ?? null;
            if (!$font || $font->type !== 'truetype' || !is_readable($font->file)) {
                return null;
            }
            return realpath($font->file) ?: null;
        });

        $this->dropStrayOutput();
        if ($path === null) {
            http_response_code(404);
            return;
        }

        header('Content-Type: font/ttf');
        header('Cache-Control: private, max-age=86400');
        readfile($path);
    }

    /** GET: the saved versions of the map, newest first (the timeline) */
    public function timeline(array $params): void
    {
        $this->requireEditor();

        $map = $this->findMap($params);
        if (!$map) {
            $this->json(['error' => __('Map not found')], 404);
            return;
        }

        $this->json($this->timelineData($map));
    }

    /** GET: one version drawn as SVG, without live data, to look at before restoring it */
    public function previewVersion(array $params): void
    {
        $this->requireEditor();

        $map = $this->findMap($params);
        $id = (string) ($params['version'] ?? '');
        $history = $map ? $this->history($map) : null;
        $content = $history?->version($id);
        if (!$map || $history === null || $content === null) {
            $this->json(['error' => __('This version no longer exists')], 404);
            return;
        }

        $file = tempnam(sys_get_temp_dir(), 'wm_ver_');
        if ($file === false) {
            $this->json(['error' => __('The map could not be drawn')], 500);
            return;
        }
        try {
            file_put_contents($file, $content);
            [$svg, $wmap] = $this->draw($map, $file, false);
        } catch (\Throwable $e) {
            error_log('SVG editor preview: ' . $e->getMessage());
            $this->json(['error' => __('The map could not be drawn')], 500);
            return;
        } finally {
            @unlink($file);
        }

        $this->json([
            'svg' => $svg,
            'width' => (int) $wmap->width,
            'height' => (int) $wmap->height,
            'version' => $history->versionInfo($id),
        ]);
    }

    /**
     * POST {name, version?}: save the map as it is now as a named savepoint,
     * or with version, name (or rename) that version.
     */
    public function savepoint(array $params): void
    {
        $this->requireEditor();

        $map = $this->findMap($params);
        if (!$map) {
            $this->json(['error' => __('Map not found')], 404);
            return;
        }

        $body = $this->body();
        $name = ConfigHistory::cleanName((string) ($body['name'] ?? ''));
        if ($name === '') {
            $this->json(['error' => __('Give the savepoint a name')], 422);
            return;
        }
        $version = isset($body['version']) && $body['version'] !== '' ? (string) $body['version'] : null;

        if ($this->history($map)->savepoint($name, $version) === null) {
            $this->json(['error' => __('This version no longer exists')], 404);
            return;
        }

        $this->json($this->timelineData($map));
    }

    /** POST {version}: take the name off a savepoint (the version stays) */
    public function unnameVersion(array $params): void
    {
        $this->requireEditor();

        $map = $this->findMap($params);
        if (!$map) {
            $this->json(['error' => __('Map not found')], 404);
            return;
        }

        if (!$this->history($map)->unname((string) ($this->body()['version'] ?? ''))) {
            $this->json(['error' => __('This version no longer exists')], 404);
            return;
        }

        $this->json($this->timelineData($map));
    }

    /**
     * POST {version, mapVersion?}: put a saved version back as the map. It
     * is one undo step, and a new version of the timeline.
     */
    public function restoreVersion(array $params): void
    {
        $this->requireEditor();

        $map = $this->findMap($params);
        if (!$map) {
            $this->json(['error' => __('Map not found')], 404);
            return;
        }

        $file = $this->configFile($map);
        $body = $this->body();
        if (isset($body['mapVersion']) && $body['mapVersion'] !== '' && (string) $body['mapVersion'] !== $this->version($file)) {
            $this->json(['error' => __('The map was changed by someone else since you loaded it. It has been reloaded: look at it before restoring a version.')], 409);
            return;
        }

        $history = $this->history($map);
        $id = (string) ($body['version'] ?? '');
        if ($history->version($id) === null) {
            $this->json(['error' => __('This version no longer exists')], 404);
            return;
        }

        clearstatcache(true, $file);
        $before = is_file($file) ? (string) file_get_contents($file) : null;
        if (!$history->restore($id)) {
            $this->json(['error' => __('The map is already this version')], 409);
            return;
        }
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($file, true);
        }
        $this->followTitle($map, $before);

        $this->respond($map, true);
    }

    /** @return array{versions: list<array>, limit: int, merge: int} */
    private function timelineData(array $map): array
    {
        return [
            'versions' => $this->history($map)->versions(),
            'limit' => ConfigHistory::VERSION_LIMIT,
            'merge' => ConfigHistory::MERGE_SECONDS,
        ];
    }

    // ------------------------------------------------------------------

    private function step(array $params, string $direction): void
    {
        $this->requireEditor();

        $map = $this->findMap($params);
        if (!$map) {
            $this->json(['error' => __('Map not found')], 404);
            return;
        }

        $file = $this->configFile($map);
        $body = $this->body();
        if (isset($body['version']) && $body['version'] !== '' && (string) $body['version'] !== $this->version($file)) {
            $this->json(['error' => __('The map was changed by someone else since you loaded it. It has been reloaded: look at it before undoing.')], 409);
            return;
        }

        $history = $this->history($map);
        clearstatcache(true, $file);
        $before = is_file($file) ? (string) file_get_contents($file) : null;
        if ($history->changedOutside()[$direction]) {
            $this->json(['error' => $direction === 'undo'
                ? __('The map was saved outside this editor since this step. Undo is refused so that change is not lost.')
                : __('The map was saved outside this editor since this step. Redo is refused so that change is not lost.')], 409);
            return;
        }

        $done = $direction === 'undo' ? $history->undo() : $history->redo();

        if (!$done) {
            $this->json(['error' => $direction === 'undo' ? __('Nothing to undo') : __('Nothing to redo')], 409);
            return;
        }
        $this->followTitle($map, $before);

        $this->loadEngine();
        $this->respond($map, true);
    }

    private function respond(array $map, bool $withData, array $result = []): void
    {
        $file = $this->configFile($map);
        [$svg, $model] = $this->engine(function () use ($map, $file, $withData): array {
            [$svg, $wmap] = $this->draw($map, $file, $withData);
            return [$svg, MapEditor::model($wmap)];
        });

        clearstatcache(true, $file);

        $this->json([
            'svg' => $svg,
            'model' => $model,
            'history' => $this->history($map)->counts() + ['outside' => $this->history($map)->changedOutside()],
            'version' => $this->version($file),
            'name' => (string) ($this->mapService->getMap((int) $map['id'])['name'] ?? $map['name']),
            'result' => $result,
        ]);
    }

    /**
     * The SVG of a .conf file drawn as the editor draws the map.
     *
     * @return array{0: string, 1: \WeatherMap}
     */
    private function draw(array $map, string $file, bool $withData): array
    {
        $this->loadEngine();
        $mapId = (int) $map['id'];

        $GLOBALS['weathermap_error_suppress'] = array_values(array_unique(array_merge(
            (array) ($GLOBALS['weathermap_error_suppress'] ?? []),
            ['WMWARN41']
        )));

        $timezone = TimezoneService::fromConfig($this->database, $this->config)->forMap($mapId);

        // from the web root (font and icon paths are relative to it), with
        // the engine warnings logged instead of printed
        return $this->engine(static function () use ($file, $withData, $mapId, $timezone): array {
            $wmap = (new MapEditor($file))->load();
            if ($withData) {
                $wmap->ReadData();
            }
            $svg = TimezoneService::using($timezone, static fn () => \WeatherMapSvg::render($wmap, [
                'font_url' => static fn (int $n) => '/editor/font/' . $mapId . '/' . $n,
            ]));
            return [$svg, $wmap];
        });
    }

    /** The map's name follows its TITLE when this save changed it (name and title are one) */
    private function followTitle(array $map, ?string $before): void
    {
        $file = $this->configFile($map);
        clearstatcache(true, $file);
        if (is_file($file)) {
            $this->mapService->followTitle((int) $map['id'], $before, (string) file_get_contents($file));
        }
    }

    private function icons(): IconLibrary
    {
        return new IconLibrary($this->config->getPublicPath(), $this->config->getConfigsPath());
    }

    private function backgrounds(): BackgroundLibrary
    {
        return new BackgroundLibrary($this->config->getPublicPath(), $this->config->getConfigsPath());
    }

    private function iconLibrary(): array
    {
        $library = $this->icons();
        return ['icons' => $library->paths(), 'themes' => $library->themes()];
    }

    private function version(string $file): string
    {
        clearstatcache(true, $file);
        return is_file($file) ? filemtime($file) . '-' . filesize($file) : '0';
    }

    private function body(): array
    {
        $body = json_decode($this->rawBody(), true);
        return is_array($body) ? $body : [];
    }

    /** The request body (a test gives its own) */
    protected function rawBody(): string
    {
        return (string) file_get_contents('php://input');
    }

    private function findMap(array $params): ?array
    {
        $id = (int) ($params['id'] ?? 0);
        return $id > 0 ? $this->mapService->getMap($id) : null;
    }

    private function configFile(array $map): string
    {
        return $this->config->getConfigsPath() . '/' . $map['config_file'];
    }

    private function history(array $map): ConfigHistory
    {
        return new ConfigHistory($this->config->getDataRoot() . '/history/' . (int) $map['id'], $this->configFile($map));
    }

    /**
     * Runs engine work as the viewer does (MapService::engine()): from the
     * web root, where the font and icon paths of a map are relative to, and
     * with the engine warnings logged rather than printed into the answer.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function engine(callable $fn): mixed
    {
        return $this->mapService->engine($fn);
    }

    private function loadEngine(): void
    {
        require_once $this->config->getLibPath() . '/WeatherMap/bootstrap.php';
    }
}
