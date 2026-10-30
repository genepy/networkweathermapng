<?php
/**
 * NetworkWeathermapNG - Admin Controller
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Services\GroupService;
use App\Services\KioskService;
use App\Services\RecordingService;
use App\Services\TimezoneService;
use App\Services\DataSourceService;

class AdminController extends BaseController
{
    private const DATA_SOURCE_TYPES = [
        'zabbix' => 'Zabbix API',
        'observium' => 'Observium (API, MySQL or RRD)',
    ];

    private const OBSERVIUM_SETTINGS = [
        'mode', 'install_path', 'db_host', 'db_port', 'db_name', 'db_user', 'db_password', 'db_socket',
        'rrd_dir', 'rrdcached', 'verify_tls',
    ];

    private GroupService $groupService;
    private DataSourceService $dataSourceService;
    
    public function __construct(array $context)
    {
        parent::__construct($context);
        $this->groupService = new GroupService($this->database);
        $this->dataSourceService = new DataSourceService($this->database);
    }
    
    public function index(array $params): void
    {
        $this->requireAdmin();
        
        // Dashboard stats
        $stats = [
            'maps' => $this->database->queryOne("SELECT COUNT(*) as count FROM maps")['count'] ?? 0,
            'groups' => $this->database->queryOne("SELECT COUNT(*) as count FROM map_groups")['count'] ?? 0,
            'users' => $this->database->queryOne("SELECT COUNT(*) as count FROM users")['count'] ?? 0,
        ];
        
        $this->render('admin/index', [
            'stats' => $stats,
            'title' => __('Administration'),
        ]);
    }
    
    
    public function groups(array $params): void
    {
        $this->requireAdmin();
        
        $groups = $this->groupService->getGroupsWithMapCount();
        
        $this->render('admin/groups', [
            'groups' => $groups,
            'title' => __('Manage Groups'),
        ]);
    }
    
    /**
     * Create a new group
     */
    public function createGroup(array $params): void
    {
        $this->requireAdmin();
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $name = trim($_POST['name'] ?? '');
            
            if (empty($name)) {
                $this->flash('error', __('Group name is required'));
                $this->redirect('/admin/groups');
                return;
            }
            
            try {
                $groupId = $this->groupService->createGroup(['name' => $name]);
                
                // Handle user assignments
                if (isset($_POST['users']) && is_array($_POST['users'])) {
                    $userIds = array_map('intval', $_POST['users']);
                    $this->groupService->updateGroupUsers($groupId, $userIds);
                }
                
                $this->flash('success', __('Group ":name" created successfully', ['name' => $name]));
            } catch (\Exception $e) {
                $this->flash('error', __('Failed to create group: :error', ['error' => $e->getMessage()]));
            }
            
            $this->redirect('/admin/groups');
            return;
        }
        
        // Get all users for assignment
        $users = $this->database->query("SELECT id, username, role FROM users ORDER BY username");
        
        $this->render('admin/group_form', [
            'group' => null,
            'users' => $users,
            'groupUsers' => [],
            'title' => __('Create New Group'),
            'action' => 'create'
        ]);
    }
    
    /**
     * Edit a group
     */
    public function editGroup(array $params): void
    {
        $this->requireAdmin();
        
        $id = (int) ($params['id'] ?? 0);
        $group = $this->groupService->getGroup($id);
        
        if (!$group) {
            $this->flash('error', __('Group not found'));
            $this->redirect('/admin/groups');
            return;
        }
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $name = trim($_POST['name'] ?? '');
            
            if (empty($name)) {
                $this->flash('error', __('Group name is required'));
                $this->redirect('/admin/groups/edit/' . $id);
                return;
            }
            
            try {
                $this->groupService->updateGroup($id, ['name' => $name]);
                
                // Handle user assignments
                $userIds = [];
                if (isset($_POST['users']) && is_array($_POST['users'])) {
                    $userIds = array_map('intval', $_POST['users']);
                }
                $this->groupService->updateGroupUsers($id, $userIds);
                
                $this->flash('success', __('Group ":name" updated successfully', ['name' => $name]));
            } catch (\Exception $e) {
                $this->flash('error', __('Failed to update group: :error', ['error' => $e->getMessage()]));
            }
            
            $this->redirect('/admin/groups');
            return;
        }
        
        // Get all users and currently assigned users
        $users = $this->database->query("SELECT id, username, role FROM users ORDER BY username");
        $groupUsers = $this->groupService->getGroupUsers($id);
        
        $this->render('admin/group_form', [
            'group' => $group,
            'users' => $users,
            'groupUsers' => $groupUsers,
            'title' => __('Edit Group: :name', ['name' => $group['name']]),
            'action' => 'edit'
        ]);
    }
    
    /**
     * Delete a group
     */
    public function deleteGroup(array $params): void
    {
        $this->requireAdmin();
        
        $id = (int) ($params['id'] ?? 0);
        
        if ($id === 1) {
            $this->flash('error', __('Cannot delete the default group'));
            $this->redirect('/admin/groups');
            return;
        }
        
        $group = $this->groupService->getGroup($id);
        
        if (!$group) {
            $this->flash('error', __('Group not found'));
            $this->redirect('/admin/groups');
            return;
        }
        
        try {
            $this->groupService->deleteGroup($id);
            $this->flash('success', __('Group ":name" deleted successfully', ['name' => $group['name']]));
        } catch (\Exception $e) {
            $this->flash('error', __('Failed to delete group: :error', ['error' => $e->getMessage()]));
        }
        
        $this->redirect('/admin/groups');
    }
    
    public function users(array $params): void
    {
        $this->requireAdmin();
        
        $users = $this->database->query(
            "SELECT id, username, email, role, active, created_at FROM users ORDER BY username"
        );
        
        $this->render('admin/users', [
            'users' => $users,
            'title' => __('Manage Users'),
        ]);
    }

    public function createUser(array $params): void
    {
        $this->requireAdmin();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $username = trim((string) ($_POST['username'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $role = (string) ($_POST['role'] ?? 'viewer');
            $active = isset($_POST['active']) ? 1 : 0;
            $password = (string) ($_POST['password'] ?? '');
            $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

            if ($username === '') {
                $this->flash('error', __('Username is required'));
                $this->redirect('/admin/users/create');
                return;
            }

            if (!in_array($role, \App\Core\Auth::ROLES, true)) {
                $this->flash('error', __('Invalid role'));
                $this->redirect('/admin/users/create');
                return;
            }

            if ($password === '' || $confirmPassword === '') {
                $this->flash('error', __('Password and confirmation are required'));
                $this->redirect('/admin/users/create');
                return;
            }

            if ($password !== $confirmPassword) {
                $this->flash('error', __('Password and confirmation do not match'));
                $this->redirect('/admin/users/create');
                return;
            }

            if (strlen($password) < 8) {
                $this->flash('error', __('Password must be at least 8 characters'));
                $this->redirect('/admin/users/create');
                return;
            }

            $existing = $this->database->queryOne('SELECT id FROM users WHERE username = ?', [$username]);
            if ($existing) {
                $this->flash('error', __('Username already exists'));
                $this->redirect('/admin/users/create');
                return;
            }

            try {
                $userId = $this->database->insert('users', [
                    'username' => $username,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'email' => $email !== '' ? $email : null,
                    'role' => $role,
                    'active' => $active,
                ]);

                // Handle group assignments
                if (isset($_POST['groups']) && is_array($_POST['groups'])) {
                    $groupIds = array_map('intval', $_POST['groups']);
                    $this->groupService->updateUserGroups($userId, $groupIds);
                }

                $this->flash('success', __('User ":name" created successfully', ['name' => $username]));
                $this->redirect('/admin/users');
            } catch (\Exception $e) {
                $this->flash('error', __('Failed to create user: :error', ['error' => $e->getMessage()]));
                $this->redirect('/admin/users/create');
            }
            return;
        }

        $groups = $this->groupService->getAllGroups();

        $this->render('admin/user_form', [
            'userData' => null,
            'userGroups' => [],
            'groups' => $groups,
            'title' => __('Add User'),
            'action' => 'create',
        ]);
    }

    public function editUser(array $params): void
    {
        $this->requireAdmin();

        $id = (int) ($params['id'] ?? 0);
        $userData = $this->database->queryOne(
            'SELECT id, username, email, role, active, created_at FROM users WHERE id = ?',
            [$id]
        );

        if (!$userData) {
            $this->flash('error', __('User not found'));
            $this->redirect('/admin/users');
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $email = trim((string) ($_POST['email'] ?? ''));
            $role = (string) ($_POST['role'] ?? $userData['role']);
            $active = isset($_POST['active']) ? 1 : 0;
            $password = (string) ($_POST['password'] ?? '');
            $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

            if (!in_array($role, \App\Core\Auth::ROLES, true)) {
                $this->flash('error', __('Invalid role'));
                $this->redirect('/admin/users/edit/' . $id);
                return;
            }

            if ($userData['username'] === 'admin') {
                $role = 'admin';
                $active = 1;
            }

            if (($password !== '') || ($confirmPassword !== '')) {
                if ($password === '' || $confirmPassword === '') {
                    $this->flash('error', __('Password and confirmation are required'));
                    $this->redirect('/admin/users/edit/' . $id);
                    return;
                }

                if ($password !== $confirmPassword) {
                    $this->flash('error', __('Password and confirmation do not match'));
                    $this->redirect('/admin/users/edit/' . $id);
                    return;
                }

                if (strlen($password) < 8) {
                    $this->flash('error', __('Password must be at least 8 characters'));
                    $this->redirect('/admin/users/edit/' . $id);
                    return;
                }
            }

            $updateData = [
                'email' => $email !== '' ? $email : null,
                'role' => $role,
                'active' => $active,
            ];

            if ($password !== '') {
                $updateData['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }

            try {
                $this->database->update('users', $updateData, 'id = ?', [$id]);

                // Handle group assignments
                $groupIds = [];
                if (isset($_POST['groups']) && is_array($_POST['groups'])) {
                    $groupIds = array_map('intval', $_POST['groups']);
                }
                $this->groupService->updateUserGroups($id, $groupIds);

                $this->flash('success', __('User ":name" updated successfully', ['name' => $userData['username']]));
                $this->redirect('/admin/users');
            } catch (\Exception $e) {
                $this->flash('error', __('Failed to update user: :error', ['error' => $e->getMessage()]));
                $this->redirect('/admin/users/edit/' . $id);
            }
            return;
        }

        $groups = $this->groupService->getAllGroups();
        $userGroups = $this->groupService->getUserGroups($id);

        $this->render('admin/user_form', [
            'userData' => $userData,
            'userGroups' => $userGroups,
            'groups' => $groups,
            'title' => __('Edit User: :name', ['name' => $userData['username']]),
            'action' => 'edit',
        ]);
    }

    public function deleteUser(array $params): void
    {
        $this->requireAdmin();

        $id = (int) ($params['id'] ?? 0);
        $userData = $this->database->queryOne('SELECT id, username FROM users WHERE id = ?', [$id]);

        if (!$userData) {
            $this->flash('error', __('User not found'));
            $this->redirect('/admin/users');
            return;
        }

        if ($userData['username'] === 'admin') {
            $this->flash('error', __('Cannot delete the admin user'));
            $this->redirect('/admin/users');
            return;
        }

        try {
            $this->database->delete('user_map_permissions', 'user_id = ?', [$id]);
            $this->database->delete('user_group_permissions', 'user_id = ?', [$id]);
            $this->database->delete('users', 'id = ?', [$id]);
            $this->flash('success', __('User ":name" deleted successfully', ['name' => $userData['username']]));
        } catch (\Exception $e) {
            $this->flash('error', __('Failed to delete user: :error', ['error' => $e->getMessage()]));
        }

        $this->redirect('/admin/users');
    }
    
    public function settings(array $params): void
    {
        $this->requireAdmin();
        
        $settings = $this->database->query("SELECT * FROM settings WHERE map_id = 0");
        
        $this->render('admin/settings', [
            'settings' => $settings,
            'title' => __('Settings'),
        ]);
    }
    
    /**
     * Map recording: whether it is allowed at all, the interval and how
     * long frames are kept, and which maps are recorded (none by default).
     */
    public function recording(array $params): void
    {
        $this->requireAdmin();

        $recording = new RecordingService($this->database, $this->config);
        $maps = $this->database->query(
            "SELECT m.id, m.name, m.active, g.name as group_name
             FROM maps m
             LEFT JOIN map_groups g ON m.group_id = g.id
             ORDER BY g.sort_order, g.name, m.sort_order, m.name"
        );

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $recording->setAllowed(!empty($_POST['allowed']));
            $recording->setInterval((int) ($_POST['interval'] ?? RecordingService::DEFAULT_INTERVAL));
            $recording->setRetention((int) ($_POST['retention'] ?? RecordingService::DEFAULT_RETENTION));
            $posted = is_array($_POST['maps'] ?? null) ? $_POST['maps'] : [];
            foreach ($maps as $map) {
                $recording->setRecorded((int) $map['id'], !empty($posted[$map['id']]));
            }
            $this->flash('success', __('Recording settings saved'));
            $this->redirect('/admin/recording');
            return;
        }

        $recorded = $recording->recordedMaps();
        $frames = [];
        foreach ($recorded as $id) {
            $frames[$id] = count($recording->frames($id));
        }

        $this->render('admin/recording', [
            'maps' => $maps,
            'allowed' => $recording->isAllowed(),
            'interval' => $recording->getInterval(),
            'retention' => $recording->getRetention(),
            'recorded' => $recorded,
            'frames' => $frames,
            'diskUsage' => $recording->diskUsage(),
            'title' => __('Map recording'),
        ]);
    }

    /**
     * Kiosk mode: display time shared by all maps, and for each map its
     * zoom and an optional display time of its own.
     */
    public function kiosk(array $params): void
    {
        $this->requireAdmin();

        $kiosk = new KioskService($this->database);
        $maps = $this->database->query(
            "SELECT m.id, m.name, m.active, g.name as group_name
             FROM maps m
             LEFT JOIN map_groups g ON m.group_id = g.id
             ORDER BY g.sort_order, g.name, m.sort_order, m.name"
        );

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $kiosk->setDefaultDuration((int) ($_POST['duration'] ?? KioskService::DEFAULT_DURATION));
            $posted = is_array($_POST['maps'] ?? null) ? $_POST['maps'] : [];
            foreach ($maps as $map) {
                $row = is_array($posted[$map['id']] ?? null) ? $posted[$map['id']] : [];
                $duration = trim((string) ($row['duration'] ?? ''));
                $kiosk->setMapSettings(
                    (int) $map['id'],
                    (int) ($row['zoom'] ?? KioskService::ZOOM_FIT),
                    $duration === '' ? null : (int) $duration
                );
            }
            $this->flash('success', __('Kiosk settings saved'));
            $this->redirect('/admin/kiosk');
            return;
        }

        $this->render('admin/kiosk', [
            'maps' => $maps,
            'duration' => $kiosk->getDefaultDuration(),
            'settings' => $kiosk->getMapSettings(array_column($maps, 'id')),
            'title' => __('Kiosk mode'),
        ]);
    }

    /**
     * Time zone of the dates shown on maps: a default for all maps, and for
     * each map an optional zone of its own.
     */
    public function timezone(array $params): void
    {
        $this->requireAdmin();

        $timezones = TimezoneService::fromConfig($this->database, $this->config);
        $maps = $this->database->query(
            "SELECT m.id, m.name, m.active, g.name as group_name
             FROM maps m
             LEFT JOIN map_groups g ON m.group_id = g.id
             ORDER BY g.sort_order, g.name, m.sort_order, m.name"
        );

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $invalid = [];
            $default = trim((string) ($_POST['timezone'] ?? ''));
            if ($default === '' || TimezoneService::isValid($default)) {
                $timezones->setDefault($default);
            } else {
                $invalid[] = $default;
            }
            $posted = is_array($_POST['maps'] ?? null) ? $_POST['maps'] : [];
            foreach ($maps as $map) {
                $zone = trim((string) ($posted[$map['id']] ?? ''));
                if ($zone === '' || TimezoneService::isValid($zone)) {
                    $timezones->setOverride((int) $map['id'], $zone);
                } else {
                    $invalid[] = $zone;
                }
            }
            if ($invalid) {
                $this->flash('error', __('Unknown time zone, not saved: :zones', ['zones' => implode(', ', array_unique($invalid))]));
            } else {
                $this->flash('success', __('Time zone settings saved'));
            }
            $this->redirect('/admin/timezone');
            return;
        }

        $this->render('admin/timezone', [
            'maps' => $maps,
            'appZone' => $timezones->getAppZone(),
            'default' => $timezones->getDefault(),
            'overrides' => $timezones->getOverrides(array_column($maps, 'id')),
            'zones' => TimezoneService::identifiers(),
            'title' => __('Time zone'),
        ]);
    }

    // Data Sources Management
    
    public function dataSources(array $params): void
    {
        $this->requireAdmin();
        
        $sources = $this->dataSourceService->getAllSources();
        
        $this->render('admin/data_sources', [
            'sources' => $sources,
            'title' => __('Data Sources'),
        ]);
    }
    
    /**
     * Settings posted with the data source form, limited to the known keys.
     * An empty password field keeps the stored one.
     */
    private function dataSourceSettings(string $type, array $current): array
    {
        if ($type !== 'observium') {
            return $current;
        }

        $posted = is_array($_POST['settings'] ?? null) ? $_POST['settings'] : [];
        $settings = [];
        foreach (self::OBSERVIUM_SETTINGS as $key) {
            $settings[$key] = trim((string) ($posted[$key] ?? ''));
        }
        $settings['verify_tls'] = ($settings['verify_tls'] ?? '1') !== '0';

        $settings['db_password'] = $settings['db_password'] !== ''
            ? $this->dataSourceService->encodeSecret($settings['db_password'])
            : (string) ($current['db_password'] ?? '');

        return $settings;
    }

    public function createDataSource(array $params): void
    {
        $this->requireAdmin();
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $name = trim($_POST['name'] ?? '');
            $type = $_POST['type'] ?? 'zabbix';
            $url = trim($_POST['url'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $apiToken = trim($_POST['api_token'] ?? '');
            
            if (empty($name) || ($type === 'zabbix' && empty($url))) {
                $this->flash('error', $type === 'zabbix' ? __('Name and URL are required') : __('Name is required'));
                $this->redirect('/admin/data-sources/create');
                return;
            }
            if (!isset(self::DATA_SOURCE_TYPES[$type])) {
                $this->flash('error', __('Unknown data source type'));
                $this->redirect('/admin/data-sources/create');
                return;
            }
            
            try {
                $sourceId = $this->dataSourceService->createSource([
                    'name' => $name,
                    'type' => $type,
                    'url' => $url,
                    'username' => $username,
                    'password' => $password,
                    'api_token' => $apiToken,
                    'active' => 1,
                    'settings' => $this->dataSourceSettings($type, []),
                ]);
                
                $this->flash('success', __('Data source ":name" created successfully', ['name' => $name]));
                $this->redirect('/admin/data-sources');
            } catch (\Exception $e) {
                $this->flash('error', __('Failed to create data source: :error', ['error' => $e->getMessage()]));
                $this->redirect('/admin/data-sources/create');
            }
            return;
        }
        
        $this->render('admin/data_source_form', [
            'source' => null,
            'title' => __('Add Data Source'),
            'action' => 'create',
            'types' => self::DATA_SOURCE_TYPES,
        ]);
    }
    
    public function editDataSource(array $params): void
    {
        $this->requireAdmin();
        
        $id = (int) ($params['id'] ?? 0);
        $source = $this->dataSourceService->getSource($id);
        
        if (!$source) {
            $this->flash('error', __('Data source not found'));
            $this->redirect('/admin/data-sources');
            return;
        }
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $name = trim($_POST['name'] ?? '');
            $type = $_POST['type'] ?? 'zabbix';
            $url = trim($_POST['url'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $apiToken = trim($_POST['api_token'] ?? '');
            $active = isset($_POST['active']) ? 1 : 0;
            
            if (empty($name) || ($type === 'zabbix' && empty($url))) {
                $this->flash('error', $type === 'zabbix' ? __('Name and URL are required') : __('Name is required'));
                $this->redirect('/admin/data-sources/edit/' . $id);
                return;
            }
            if (!isset(self::DATA_SOURCE_TYPES[$type])) {
                $this->flash('error', __('Unknown data source type'));
                $this->redirect('/admin/data-sources/edit/' . $id);
                return;
            }
            
            try {
                $this->dataSourceService->updateSource($id, [
                    'name' => $name,
                    'type' => $type,
                    'url' => $url,
                    'username' => $username,
                    'password' => $password,
                    'api_token' => $apiToken,
                    'active' => $active,
                    'settings' => $this->dataSourceSettings($type, json_decode((string) ($source['settings'] ?? ''), true) ?: []),
                ]);
                
                $this->flash('success', __('Data source ":name" updated successfully', ['name' => $name]));
                $this->redirect('/admin/data-sources');
            } catch (\Exception $e) {
                $this->flash('error', __('Failed to update data source: :error', ['error' => $e->getMessage()]));
                $this->redirect('/admin/data-sources/edit/' . $id);
            }
            return;
        }
        
        $this->render('admin/data_source_form', [
            'source' => $source,
            'title' => __('Edit Data Source: :name', ['name' => $source['name']]),
            'action' => 'edit',
            'types' => self::DATA_SOURCE_TYPES,
        ]);
    }
    
    public function deleteDataSource(array $params): void
    {
        $this->requireAdmin();
        
        $id = (int) ($params['id'] ?? 0);
        $source = $this->dataSourceService->getSource($id);
        
        if (!$source) {
            $this->flash('error', __('Data source not found'));
            $this->redirect('/admin/data-sources');
            return;
        }
        
        try {
            $this->dataSourceService->deleteSource($id);
            $this->flash('success', __('Data source ":name" deleted successfully', ['name' => $source['name']]));
        } catch (\Exception $e) {
            $this->flash('error', __('Failed to delete data source: :error', ['error' => $e->getMessage()]));
        }
        
        $this->redirect('/admin/data-sources');
    }
    
    public function testDataSource(array $params): void
    {
        $this->requireAdmin();
        
        $id = (int) ($params['id'] ?? 0);
        
        $result = $this->dataSourceService->testConnection($id);
        
        $this->json($result);
    }

    public function checkDatabase(array $params): void
    {
        $this->requireAdmin();
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // If posted with fix=1 (future implementation), we could attempt auto-repair
            // For now just re-run initialization
            if (isset($_POST['fix']) && $_POST['fix'] === '1') {
                try {
                    // Force schema initialization check
                    // This is a bit of a hack since we can't easily access the private method
                    // But we can re-instantiate the database connection which triggers it
                    $db = new \App\Core\Database($this->config);
                    $this->flash('success', __('Database schema update attempted.'));
                } catch (\Exception $e) {
                    $this->flash('error', __('Database update failed: :error', ['error' => $e->getMessage()]));
                }
                $this->redirect('/admin/check-db');
                return;
            }
        }
        
        $results = $this->database->checkDatabaseIntegrity();
        
        $this->render('admin/check_db', [
            'results' => $results,
            'title' => __('Database Integrity Check'),
        ]);
    }
}
