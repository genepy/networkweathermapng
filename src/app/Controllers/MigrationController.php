<?php
/**
 * NetworkWeathermapNG - Migration of existing Weathermap maps
 *
 * Upload .conf files (or a zip with their images), review what needs care,
 * pick the interfaces for the old targets, compare the old and new drawings,
 * then create the map. The original stays in the map's history.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Services\DataSourceService;
use App\Services\GroupService;
use App\Services\MapService;
use App\Services\Migration\MigrationImporter;
use App\Services\Migration\MigrationService;
use App\Services\Migration\MigrationStore;

class MigrationController extends BaseController
{
    private MapService $mapService;
    private DataSourceService $dataSources;
    private MigrationStore $store;
    private MigrationService $migration;

    public function __construct(array $context)
    {
        parent::__construct($context);
        $this->mapService = new MapService($this->database, $this->config);
        $this->dataSources = new DataSourceService($this->database);
        $this->store = new MigrationStore($this->config->getDataRoot() . '/migration');
        $this->migration = new MigrationService($this->config, $this->mapService, $this->dataSources, $this->store);
    }

    public function index(array $params): void
    {
        $this->requireAdmin();

        $this->render('admin/migration/index', [
            'migrations' => $this->store->all(),
            'title' => __('Migration'),
        ]);
    }

    public function upload(array $params): void
    {
        $this->requireAdmin();

        $files = [];
        $upload = $_FILES['files'] ?? null;
        if (is_array($upload) && is_array($upload['name'] ?? null)) {
            foreach ($upload['name'] as $i => $name) {
                if (($upload['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file($upload['tmp_name'][$i])) {
                    $files[(string) $name] = $upload['tmp_name'][$i];
                }
            }
        }

        if (!$files) {
            $this->flash('error', __('Choose one or more .conf files or a .zip file.'));
            $this->redirect('/admin/migration');
            return;
        }

        $result = (new MigrationImporter($this->store, $this->config->getPublicPath()))->import($files);

        $message = __(':maps map(s) imported, :images image(s) added.', [
            'maps' => count($result['migrations']),
            'images' => count($result['images']),
        ]);
        if ($result['skipped']) {
            $message .= ' ' . __('Skipped: :list', ['list' => implode('; ', array_slice($result['skipped'], 0, 10))]);
        }
        $this->flash($result['migrations'] ? 'success' : 'error', $message);

        $this->redirect(count($result['migrations']) === 1
            ? '/admin/migration/' . $result['migrations'][0]
            : '/admin/migration');
    }

    /** GET: the analysis and the target choices */
    public function review(array $params): void
    {
        $this->requireAdmin();
        $meta = $this->find($params);
        if (!$meta) {
            return;
        }

        if (isset($_GET['source'])) {
            $meta = $this->store->update($meta['id'], ['sourceId' => ((int) $_GET['source']) ?: null]);
        }

        $this->loadEngine();
        $report = $this->migration->analyze($meta);

        $this->render('admin/migration/review', [
            'meta' => $meta,
            'report' => $report,
            'sources' => $this->dataSources->getActiveSources(),
            'groups' => (new GroupService($this->database))->getAllGroups(),
            'map' => $meta['mapId'] ? $this->mapService->getMap((int) $meta['mapId']) : null,
            'title' => __('Migration') . ': ' . $meta['name'],
        ]);
    }

    /** POST: keep the choices, then compare or create the map */
    public function save(array $params): void
    {
        $this->requireAdmin();
        $meta = $this->find($params);
        if (!$meta) {
            return;
        }

        $choices = [];
        foreach ((array) ($_POST['target'] ?? []) as $key => $value) {
            $key = (string) $key;
            $manual = trim((string) ($_POST['manual'][$key] ?? ''));
            if ($manual !== '') {
                $choices[$key] = ['target' => $manual];
            } elseif ($value === 'keep' || $value === '') {
                $choices[$key] = 'keep';
            } else {
                $decoded = json_decode((string) $value, true);
                if (is_array($decoded) && !empty($decoded['target']) && is_string($decoded['target'])) {
                    $choices[$key] = array_intersect_key($decoded, ['target' => 1, 'datasource' => 1]);
                }
            }
        }

        $meta = $this->store->update($meta['id'], [
            'name' => trim((string) ($_POST['name'] ?? '')) ?: $meta['name'],
            'fonts' => isset($_POST['fonts']),
            'choices' => $choices,
            'groupId' => (int) ($_POST['group_id'] ?? 1),
        ]);

        if (($_POST['action'] ?? '') === 'apply') {
            $this->apply($meta);
            return;
        }
        $this->redirect('/admin/migration/' . $meta['id'] . '/compare');
    }

    public function compare(array $params): void
    {
        $this->requireAdmin();
        $meta = $this->find($params);
        if (!$meta) {
            return;
        }

        $this->render('admin/migration/compare', [
            'meta' => $meta,
            'title' => __('Compare') . ': ' . $meta['name'],
        ]);
    }

    /** GET: the old drawing (PNG) or the new one (SVG) */
    public function image(array $params): void
    {
        $this->requireAdmin();
        $meta = $this->find($params, false);
        if (!$meta) {
            http_response_code(404);
            return;
        }

        $this->loadEngine();
        $migrator = $this->migration->migrator($meta, false);

        header('Cache-Control: no-store');
        if (($params['kind'] ?? '') === 'old.png') {
            header('Content-Type: image/png');
            echo $migrator->renderPng($this->store->original($meta['id']));
            return;
        }

        header('Content-Type: image/svg+xml');
        echo $migrator->renderSvg($this->migration->converted($meta, $migrator), [self::class, 'fontUrl']);
    }

    /** POST: put the uploaded file back in place of the converted map */
    public function rollback(array $params): void
    {
        $this->requireAdmin();
        $meta = $this->find($params);
        if (!$meta) {
            return;
        }

        $this->loadEngine();
        if (!$this->migration->rollback($meta)) {
            $this->flash('error', __('This migration has not created a map yet.'));
            $this->redirect('/admin/migration/' . $meta['id']);
            return;
        }

        $this->flash('success', __('The original map is back. Undo in the editor returns to the converted one.'));
        $this->redirect('/admin/migration/' . $meta['id']);
    }

    public function delete(array $params): void
    {
        $this->requireAdmin();
        $meta = $this->find($params);
        if ($meta) {
            $this->store->delete($meta['id']);
            $this->flash('success', __('Migration removed. Maps already created are kept.'));
        }
        $this->redirect('/admin/migration');
    }

    /** Fonts of the converted map: the bundled ones are served from /fonts */
    public static function fontUrl(int $n, $font): string
    {
        $file = (string) $font->file;
        if (preg_match('#^fonts/[A-Za-z0-9._-]+\.ttf$#', $file)) {
            return '/' . $file;
        }
        return is_readable($file) ? 'data:font/ttf;base64,' . base64_encode((string) file_get_contents($file)) : '';
    }

    // ------------------------------------------------------------------

    private function apply(array $meta): void
    {
        $this->loadEngine();
        try {
            $map = $this->migration->apply($meta);
        } catch (\Throwable $e) {
            error_log('Migration: ' . $e->getMessage());
            $this->flash('error', __('The map could not be created: :error', ['error' => $e->getMessage()]));
            $this->redirect('/admin/migration/' . $meta['id']);
            return;
        }

        $this->flash('success', __('Map ":name" created. Undo in the editor goes back to the original.', ['name' => $meta['name']]));
        $this->redirect('/editor/edit/' . $map['id']);
    }

    private function find(array $params, bool $redirect = true): ?array
    {
        $meta = $this->store->get((string) ($params['id'] ?? ''));
        if (!$meta && $redirect) {
            $this->flash('error', __('Migration not found'));
            $this->redirect('/admin/migration');
        }
        return $meta;
    }

    private function loadEngine(): void
    {
        require_once $this->config->getLibPath() . '/WeatherMap/bootstrap.php';
        $GLOBALS['weathermap_error_suppress'] = array_values(array_unique(array_merge(
            (array) ($GLOBALS['weathermap_error_suppress'] ?? []),
            ['WMWARN41']
        )));
    }
}
