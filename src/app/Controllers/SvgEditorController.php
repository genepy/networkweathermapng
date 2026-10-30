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
        $this->requireAdmin();

        $map = $this->findMap($params);
        if (!$map) {
            $this->flash('error', __('Map not found'));
            $this->redirect('/editor');
            return;
        }

        $this->loadEngine();
        $publicPath = $this->config->getPublicPath();

        $this->render('editor/svg', [
            'map' => $map,
            'mapId' => (int) $map['id'],
            'icons' => get_imagelist($publicPath, 'objects'),
            'backgrounds' => get_imagelist($publicPath, 'backgrounds'),
            'title' => __('Edit Map: :name', ['name' => $map['name']]),
        ], false);
    }

    /** GET: the map as SVG plus the editable model */
    public function renderMap(array $params): void
    {
        $this->requireAdmin();

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
        $this->requireAdmin();

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

        $this->respond($map, true, $result);
    }

    /** POST {nodes: [...]}: the nodes and the links between them, as .conf text */
    public function copy(array $params): void
    {
        $this->requireAdmin();

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
        $this->requireAdmin();

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
        $this->requireAdmin();

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
        $this->requireAdmin();

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
        $this->requireAdmin();

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
        $this->requireAdmin();

        $map = $this->findMap($params);
        if (!$map) {
            $this->json(['error' => __('Map not found')], 404);
            return;
        }

        $history = $this->history($map);
        $done = $direction === 'undo' ? $history->undo() : $history->redo();

        if (!$done) {
            $this->json(['error' => $direction === 'undo' ? __('Nothing to undo') : __('Nothing to redo')], 409);
            return;
        }

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
            'history' => $this->history($map)->counts(),
            'version' => $this->version($file),
            'result' => $result,
        ]);
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
        require_once $this->config->getLibPath() . '/WeatherMap/editor.inc.php';
    }
}
