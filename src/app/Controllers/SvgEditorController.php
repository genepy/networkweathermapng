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
use App\Services\Editor\MapEditor;
use App\Services\MapService;
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
            $this->flash('error', 'Map not found');
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
            'title' => 'Edit Map: ' . $map['name'],
        ], false);
    }

    /** GET: the map as SVG plus the editable model */
    public function renderMap(array $params): void
    {
        $this->requireAdmin();

        $map = $this->findMap($params);
        if (!$map) {
            $this->json(['error' => 'Map not found'], 404);
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
            $this->json(['error' => 'Map not found'], 404);
            return;
        }

        $body = json_decode((string) file_get_contents('php://input'), true);
        $ops = is_array($body) ? ($body['ops'] ?? null) : null;
        if (!is_array($ops) || $ops === []) {
            $this->json(['error' => 'No operations'], 422);
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
            $this->json(['error' => 'The map could not be saved'], 500);
            return;
        }

        if ($before !== null) {
            $history->record($before);
        }

        $this->respond($map, true, $result);
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
            $this->json(['error' => 'Map not found'], 404);
            return;
        }

        $history = $this->history($map);
        $done = $direction === 'undo' ? $history->undo() : $history->redo();

        if (!$done) {
            $this->json(['error' => $direction === 'undo' ? 'Nothing to undo' : 'Nothing to redo'], 409);
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

        $wmap = (new MapEditor($file))->load();

        if ($withData) {
            ob_start();
            $wmap->ReadData();
            ob_end_clean();
        }

        $svg = \WeatherMapSvg::render($wmap, [
            'font_url' => static fn (int $n) => '/editor/font/' . $mapId . '/' . $n,
        ]);

        clearstatcache(true, $file);

        $this->json([
            'svg' => $svg,
            'model' => MapEditor::model($wmap),
            'history' => $this->history($map)->counts(),
            'version' => is_file($file) ? filemtime($file) . '-' . filesize($file) : '0',
            'result' => $result,
        ]);
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
