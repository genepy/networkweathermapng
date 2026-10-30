<?php
/**
 * NetworkWeathermapNG - Map management: the map list of the Editor tab and
 * the map properties, for administrators and editors
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Services\MapService;
use App\Services\GroupService;

class MapManagementController extends BaseController
{
    private const DEFAULT_RETURN = '/editor';

    private MapService $mapService;
    private GroupService $groupService;

    public function __construct(array $context)
    {
        parent::__construct($context);
        $this->mapService = new MapService($this->database, $this->config);
        $this->groupService = new GroupService($this->database);
    }

    public function index(array $params): void
    {
        $this->requireEditor();
        
        $maps = $this->database->query(
            "SELECT m.*, g.name as group_name 
             FROM maps m 
             LEFT JOIN map_groups g ON m.group_id = g.id 
             ORDER BY m.sort_order, m.name"
        );
        
        $groups = $this->groupService->getAllGroups();
        
        $this->render('editor/index', [
            'maps' => $maps,
            'groups' => $groups,
            'canImport' => $this->auth->isAdmin(),
            'title' => __('Maps'),
        ]);
    }
    
    /**
     * Create a new map
     */
    public function create(array $params): void
    {
        $this->requireEditor();
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $name = MapService::cleanName((string) ($_POST['name'] ?? ''));
            $groupId = (int) ($_POST['group_id'] ?? 1);
            $template = (int) ($_POST['template'] ?? 0);

            if ($name === '') {
                $this->flash('error', __('Map name is required'));
                $this->redirect('/editor/maps/create' . $this->returnQuery());
                return;
            }

            try {
                if ($template > 0) {
                    $mapId = $this->mapService->duplicateMap($template, $name, $groupId, isset($_POST['active']));
                    if (!$mapId) {
                        throw new \RuntimeException(__('Map not found'));
                    }
                } else {
                    $mapId = $this->mapService->createMap([
                        'name' => $name,
                        'group_id' => $groupId,
                        'active' => isset($_POST['active']) ? 1 : 0,
                        'width' => (int) ($_POST['width'] ?? 800),
                        'height' => (int) ($_POST['height'] ?? 600),
                    ]);
                }

                $this->flash('success', __('Map ":name" created', ['name' => $name]));
                $this->redirect('/editor/edit/' . $mapId);
            } catch (\Exception $e) {
                $this->flash('error', __('Failed to create map: :error', ['error' => $e->getMessage()]));
                $this->redirect($this->returnUrl());
            }
            return;
        }

        // Show create form
        $groups = $this->groupService->getAllGroups();
        
        $this->render('editor/map_form', [
            'map' => null,
            'groups' => $groups,
            'maps' => $this->mapService->getAllMaps(),
            'title' => __('New map'),
            'action' => 'create',
            'return' => $this->returnUrl(),
        ]);
    }
    
    /**
     * Edit an existing map
     */
    public function edit(array $params): void
    {
        $this->requireEditor();
        
        $id = (int) ($params['id'] ?? 0);
        $map = $this->mapService->getMap($id);
        
        if (!$map) {
            $this->flash('error', __('Map not found'));
            $this->redirect($this->returnUrl());
            return;
        }
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $name = MapService::cleanName((string) ($_POST['name'] ?? ''));

            if ($name === '') {
                $this->flash('error', __('Map name is required'));
                $this->redirect('/editor/maps/edit/' . $id . $this->returnQuery());
                return;
            }

            try {
                if ($name !== $map['name'] || isset($_POST['rename_file'])) {
                    $this->mapService->renameMap($id, $name, isset($_POST['rename_file']));
                }
                $this->mapService->updateMap($id, [
                    'group_id' => (int) ($_POST['group_id'] ?? 1),
                    'active' => isset($_POST['active']) ? 1 : 0,
                ]);

                $this->flash('success', __('Map ":name" saved', ['name' => $name]));
                $this->redirect($this->returnUrl());
            } catch (\Exception $e) {
                $this->flash('error', __('Failed to update map: :error', ['error' => $e->getMessage()]));
                $this->redirect('/editor/maps/edit/' . $id . $this->returnQuery());
            }
            return;
        }

        $groups = $this->groupService->getAllGroups();
        
        $this->render('editor/map_form', [
            'map' => $map,
            'groups' => $groups,
            'title' => __('Map properties') . ': ' . $map['name'],
            'action' => 'edit',
            'return' => $this->returnUrl(),
        ]);
    }
    
    /**
     * Delete a map
     */
    public function delete(array $params): void
    {
        $this->requireEditor();
        
        $id = (int) ($params['id'] ?? 0);
        $map = $this->mapService->getMap($id);
        
        if (!$map) {
            $this->flash('error', __('Map not found'));
            $this->redirect(self::DEFAULT_RETURN);
            return;
        }
        
        try {
            $this->mapService->deleteMap($id);
            $this->flash('success', __('Map ":name" and all its data deleted', ['name' => $map['name']]));
        } catch (\Exception $e) {
            $this->flash('error', __('Failed to delete map: :error', ['error' => $e->getMessage()]));
        }
        
        $this->redirect(self::DEFAULT_RETURN);
    }
    
    /**
     * Duplicate a map
     */
    public function duplicate(array $params): void
    {
        $this->requireEditor();
        
        $id = (int) ($params['id'] ?? 0);
        $map = $this->mapService->getMap($id);
        
        if (!$map) {
            $this->flash('error', __('Map not found'));
            $this->redirect($this->returnUrl());
            return;
        }
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->render('editor/map_form', [
                'map' => $map,
                'groups' => $this->groupService->getAllGroups(),
                'title' => __('Duplicate') . ': ' . $map['name'],
                'action' => 'duplicate',
                'return' => $this->returnUrl(),
            ]);
            return;
        }

        try {
            $newId = $this->mapService->duplicateMap(
                $id,
                (string) ($_POST['name'] ?? ''),
                (int) ($_POST['group_id'] ?? 0) ?: null,
                isset($_POST['active'])
            );
            $this->flash('success', __('Map duplicated'));
            $this->redirect('/editor/edit/' . $newId);
        } catch (\Exception $e) {
            $this->flash('error', __('Failed to duplicate map: :error', ['error' => $e->getMessage()]));
            $this->redirect($this->returnUrl());
        }
    }

    /** POST ids[] and action: activate, deactivate, move (group_id), delete, export */
    public function bulk(array $params): void
    {
        $this->requireEditor();

        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
        $action = (string) ($_POST['action'] ?? '');
        if (!$ids) {
            $this->flash('error', __('Select at least one map.'));
            $this->redirect($this->returnUrl());
            return;
        }

        if ($action === 'export') {
            $this->sendZip($ids);
            return;
        }

        $done = 0;
        foreach ($ids as $id) {
            $ok = match ($action) {
                'activate' => $this->mapService->updateMap($id, ['active' => 1]),
                'deactivate' => $this->mapService->updateMap($id, ['active' => 0]),
                'move' => (int) ($_POST['group_id'] ?? 0) > 0 && $this->groupService->getGroup((int) $_POST['group_id'])
                    && $this->mapService->updateMap($id, ['group_id' => (int) $_POST['group_id']]),
                'delete' => $this->mapService->deleteMap($id),
                default => false,
            };
            $done += $ok ? 1 : 0;
        }

        $message = $action === 'delete' ? __(':n map(s) deleted with all their data', ['n' => $done]) : __(':n map(s) updated', ['n' => $done]);
        $this->flash($done ? 'success' : 'error', $message);
        $this->redirect($this->returnUrl());
    }

    /** GET: the map's .conf file */
    public function export(array $params): void
    {
        $this->requireEditor();

        $map = $this->mapService->getMap((int) ($params['id'] ?? 0));
        $content = $map ? $this->mapService->getMapConfig((int) $map['id']) : null;
        if ($content === null) {
            http_response_code(404);
            return;
        }

        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . basename($map['config_file']) . '"');
        echo $content;
    }

    /** GET: every map with its images, as a zip Admin > Migration can import */
    public function exportAll(array $params): void
    {
        $this->requireEditor();
        $this->sendZip([]);
    }

    private function sendZip(array $ids): void
    {
        $file = tempnam(sys_get_temp_dir(), 'wm_export_');
        try {
            $this->mapService->exportZip($ids, $file);
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="weathermap-maps-' . date('Ymd-His') . '.zip"');
            header('Content-Length: ' . filesize($file));
            readfile($file);
        } finally {
            @unlink($file);
        }
    }

    /**
     * Move a map to a different group (AJAX)
     */
    public function move(array $params): void
    {
        $this->requireEditor();
        
        $id = (int) ($params['id'] ?? 0);
        $map = $this->mapService->getMap($id);
        
        if (!$map) {
            $this->json(['success' => false, 'error' => __('Map not found')], 404);
            return;
        }
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Get JSON input
            $input = json_decode(file_get_contents('php://input'), true);
            $groupId = (int) ($input['group_id'] ?? 0);
            
            if ($groupId <= 0) {
                $this->json(['success' => false, 'error' => __('Invalid group ID')], 400);
                return;
            }
            
            // Verify group exists
            $group = $this->groupService->getGroup($groupId);
            if (!$group) {
                $this->json(['success' => false, 'error' => __('Group not found')], 404);
                return;
            }
            
            try {
                $this->mapService->updateMap($id, ['group_id' => $groupId]);
                $this->json(['success' => true]);
            } catch (\Exception $e) {
                $this->json(['success' => false, 'error' => $e->getMessage()], 500);
            }
            return;
        }
        
        $this->json(['success' => false, 'error' => __('Invalid request method')], 405);
    }

    /** POST: activate or deactivate one map from the list */
    public function toggle(array $params): void
    {
        $this->requireEditor();

        $map = $this->mapService->getMap((int) ($params['id'] ?? 0));
        if (!$map) {
            $this->flash('error', __('Map not found'));
            $this->redirect($this->returnUrl());
            return;
        }

        $active = $map['active'] ? 0 : 1;
        $this->mapService->updateMap((int) $map['id'], ['active' => $active]);
        $this->flash('success', $active
            ? __('Map ":name" activated', ['name' => $map['name']])
            : __('Map ":name" deactivated', ['name' => $map['name']]));
        $this->redirect($this->returnUrl());
    }

    /** /admin/maps of older versions: the list now lives in the Editor tab */
    public function legacyList(array $params): void
    {
        $this->redirect(self::DEFAULT_RETURN);
    }

    /**
     * The page the user came from, given as ?return= (or a hidden field):
     * cancelling or saving a form goes back there. Only local paths.
     */
    private function returnUrl(): string
    {
        return self::safeReturn((string) ($_POST['return'] ?? $_GET['return'] ?? ''));
    }

    private function returnQuery(): string
    {
        $url = $this->returnUrl();
        return $url === self::DEFAULT_RETURN ? '' : '?return=' . rawurlencode($url);
    }

    public static function safeReturn(string $url): string
    {
        if ($url === '' || $url[0] !== '/' || str_starts_with($url, '//')
            || str_contains($url, '\\') || preg_match('/[\x00-\x1f]/', $url)) {
            return self::DEFAULT_RETURN;
        }
        return $url;
    }
}
