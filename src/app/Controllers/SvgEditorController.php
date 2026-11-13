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

    public function __construct(array $context)
    {
        parent::__construct($context);
        $this->mapService = new MapService($this->database, $this->config);
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

        $body = json_decode((string) file_get_contents('php://input'), true);
        $ops = is_array($body) ? ($body['ops'] ?? null) : null;
        if (!is_array($ops) || $ops === []) {
            $this->json(['error' => __('No operations')], 422);
            return;
        }

        $this->loadEngine();
        $file = $this->configFile($map);
        $history = $this->history($map);
        $before = is_file($file) ? (string) file_get_contents($file) : null;

        try {
            $result = (new MapEditor($file))->apply($ops);
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
            $wmap = (new MapEditor($this->configFile($map)))->load();
            $clip = MapClipboard::copy($wmap, array_map('strval', (array) ($body['nodes'] ?? [])));
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

        $this->loadEngine();
        $wmap = (new MapEditor($this->configFile($map)))->load();
        $font = $wmap->fonts[$n] ?? null;

        if (!$font || $font->type !== 'truetype' || !is_readable($font->file)) {
            http_response_code(404);
            return;
        }

        header('Content-Type: font/ttf');
        header('Cache-Control: private, max-age=86400');
        readfile($font->file);
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
        $this->loadEngine();
        $file = $this->configFile($map);
        $mapId = (int) $map['id'];

        $GLOBALS['weathermap_error_suppress'] = array_values(array_unique(array_merge(
            (array) ($GLOBALS['weathermap_error_suppress'] ?? []),
            ['WMWARN41']
        )));

        // a warning printed by the engine would make the answer unreadable JSON
        ob_start();
        try {
            $wmap = (new MapEditor($file))->load();
            if ($withData) {
                $wmap->ReadData();
            }
            $svg = TimezoneService::using(
                TimezoneService::fromConfig($this->database, $this->config)->forMap($mapId),
                static fn () => \WeatherMapSvg::render($wmap, [
                    'font_url' => static fn (int $n) => '/editor/font/' . $mapId . '/' . $n,
                ])
            );
        } finally {
            ob_end_clean();
        }

        clearstatcache(true, $file);

        $this->json([
            'svg' => $svg,
            'model' => MapEditor::model($wmap),
            'history' => $this->history($map)->counts() + ['outside' => $this->history($map)->changedOutside()],
            'version' => $this->version($file),
            'name' => (string) ($this->mapService->getMap($mapId)['name'] ?? $map['name']),
            'result' => $result,
        ]);
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
        $body = json_decode((string) file_get_contents('php://input'), true);
        return is_array($body) ? $body : [];
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

    private function loadEngine(): void
    {
        require_once $this->config->getLibPath() . '/WeatherMap/bootstrap.php';
    }
}
