<?php
/**
 * NetworkWeathermapNG - Application Bootstrap
 */

declare(strict_types=1);

namespace App\Core;

class Application
{
    private Config $config;
    private Router $router;
    private ?Database $database = null;
    
    public function __construct()
    {
        $this->config = new Config();
        $this->router = new Router();
        
        $this->initialize();
    }
    
    private function initialize(): void
    {
        // Set timezone
        date_default_timezone_set($this->config->get('APP_TIMEZONE', 'UTC'));
        
        // Set error reporting based on debug mode
        if ($this->config->get('APP_DEBUG', 'false') === 'true') {
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
        } else {
            error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
            ini_set('display_errors', '0');
        }
        
        // Initialize database
        $this->database = new Database($this->config);
        $this->encryptStoredSecrets();
        
        // Register routes
        $this->registerRoutes();
    }
    
    /**
     * Once per install: passwords saved in base64 by older versions are
     * encrypted in place. A marker file in DATA_ROOT records that it is done.
     */
    private function encryptStoredSecrets(): void
    {
        $marker = rtrim((string) $this->config->getDataRoot(), '/') . '/.secrets-encrypted';
        if (is_file($marker)) {
            return;
        }

        try {
            $secrets = SecretBox::fromConfig($this->config);
            if (!$secrets->canEncrypt()) {
                return;
            }
            (new \App\Services\DataSourceService($this->database, $secrets))->upgradeStoredSecrets();
            @file_put_contents($marker, date('c') . "\n");
        } catch (\Throwable $e) {
            error_log('Encrypting stored secrets: ' . $e->getMessage());
        }
    }

    private function registerRoutes(): void
    {
        // Health check
        $this->router->get('/health', function() {
            header('Content-Type: text/plain');
            echo 'OK';
        });
        
        // Home / Map list
        $this->router->get('/', 'MapController@index');
        $this->router->get('/maps', 'MapController@index');
        
        // View single map
        $this->router->get('/map/{id}', 'MapController@view');
        $this->router->get('/map/{id}/image', 'MapController@image');
        $this->router->get('/map/{id}/thumb', 'MapController@thumbnail');
        $this->router->get('/map/{id}/version', 'MapController@version');
        $this->router->get('/map/{id}/link/{linkId}/graph', 'MapController@linkGraph');
        $this->router->get('/map/{id}/svg', 'MapController@svg');
        $this->router->get('/map/{id}/status', 'MapController@status');
        $this->router->get('/map/{id}/font/{n}', 'MapController@font');
        $this->router->post('/map/{id}/favourite', 'MapController@favourite');
        
        // Map cycling
        $this->router->get('/cycle', 'MapController@cycle');
        $this->router->get('/cycle/{group}', 'MapController@cycle');
        
        // Editor tab: the map list and the map properties (admins and editors)
        $this->router->get('/editor', 'MapManagementController@index');
        foreach (['/editor/maps', '/admin/maps'] as $prefix) {
            // /admin/maps/... are the addresses of older versions, kept for bookmarks
            $this->router->get($prefix . '/create', 'MapManagementController@create');
            $this->router->post($prefix . '/create', 'MapManagementController@create');
            $this->router->get($prefix . '/edit/{id}', 'MapManagementController@edit');
            $this->router->post($prefix . '/edit/{id}', 'MapManagementController@edit');
            $this->router->post($prefix . '/delete/{id}', 'MapManagementController@delete');
            $this->router->get($prefix . '/duplicate/{id}', 'MapManagementController@duplicate');
            $this->router->post($prefix . '/duplicate/{id}', 'MapManagementController@duplicate');
            $this->router->post($prefix . '/toggle/{id}', 'MapManagementController@toggle');
            $this->router->post($prefix . '/bulk', 'MapManagementController@bulk');
            $this->router->get($prefix . '/export', 'MapManagementController@exportAll');
            $this->router->get($prefix . '/export/{id}', 'MapManagementController@export');
            $this->router->post($prefix . '/move/{id}', 'MapManagementController@move');
        }
        $this->router->get('/editor/maps', 'MapManagementController@legacyList');
        $this->router->get('/admin/maps', 'MapManagementController@legacyList');

        // Visual Map Editor
        $this->router->get('/editor/edit/{id}', 'SvgEditorController@edit');
        $this->router->get('/editor/render/{id}', 'SvgEditorController@renderMap');
        $this->router->post('/editor/ops/{id}', 'SvgEditorController@ops');
        $this->router->post('/editor/undo/{id}', 'SvgEditorController@undo');
        $this->router->post('/editor/redo/{id}', 'SvgEditorController@redo');
        $this->router->post('/editor/copy/{id}', 'SvgEditorController@copy');
        $this->router->get('/editor/source/{id}', 'SvgEditorController@source');
        $this->router->post('/editor/source/{id}', 'SvgEditorController@saveSource');
        $this->router->post('/editor/validate/{id}', 'SvgEditorController@validate');
        $this->router->get('/editor/font/{id}/{n}', 'SvgEditorController@font');
        $this->router->get('/editor/icons', 'SvgEditorController@iconList');
        $this->router->post('/editor/icons', 'SvgEditorController@addIcons');
        $this->router->post('/editor/icons/delete', 'SvgEditorController@deleteIcon');
        $this->router->get('/editor/classic/{id}', 'EditorController@edit');
        $this->router->get('/editor/draw/{id}', 'EditorController@draw');
        $this->router->get('/editor/area/{id}', 'EditorController@getAreaData');
        $this->router->get('/editor/mapjs/{id}', 'EditorController@getMapJS');
        $this->router->get('/editor/mapdata/{id}', 'EditorController@getMapData');
        $this->router->get('/editor/load_area_data', 'EditorController@loadAreaData');
        $this->router->get('/editor/load_map_javascript', 'EditorController@loadMapJavascript');
        $this->router->get('/editor/config/{id}', 'EditorController@config');
        $this->router->post('/editor/action/{id}', 'EditorController@action');
        $this->router->post('/editor/save/{id}', 'EditorController@save');
        $this->router->get('/editor/preview/{id}', 'EditorController@preview');
        
        // API endpoints
        $this->router->get('/api/maps', 'ApiController@maps');
        $this->router->get('/api/map/{id}', 'ApiController@map');
        $this->router->get('/api/data-sources', 'ApiController@dataSources');
        $this->router->get('/api/data-sources/{sourceId}/hosts', 'ApiController@dataSourceHosts');
        $this->router->get('/api/data-sources/{sourceId}/hosts/{hostId}/interfaces', 'ApiController@dataSourceInterfaces');
        $this->router->get('/api/data-sources/{sourceId}/hosts/{hostId}/items', 'ApiController@dataSourceItems');
        $this->router->get('/api/data-sources/{sourceId}/hosts/{hostId}/bandwidths', 'ApiController@dataSourceBandwidths');
        $this->router->get('/api/data-sources/{sourceId}/link-bandwidth', 'ApiController@dataSourceLinkBandwidth');
        
        // Authentication
        $this->router->get('/login', 'AuthController@loginForm');
        $this->router->post('/login', 'AuthController@login');
        // Logging out changes state: POST with the CSRF token, so another
        // site cannot log users out. A GET (old bookmark) just goes home.
        $this->router->post('/logout', 'AuthController@logout');
        $this->router->get('/logout', function () {
            header('Location: /');
        });

        // Account
        $this->router->get('/account/password', 'AccountController@changePasswordForm');
        $this->router->post('/account/password', 'AccountController@changePassword');
        
        // Admin
        $this->router->get('/admin', 'AdminController@index');
        
        $this->router->get('/admin/groups', 'AdminController@groups');
        $this->router->get('/admin/groups/create', 'AdminController@createGroup');
        $this->router->post('/admin/groups/create', 'AdminController@createGroup');
        $this->router->get('/admin/groups/edit/{id}', 'AdminController@editGroup');
        $this->router->post('/admin/groups/edit/{id}', 'AdminController@editGroup');
        $this->router->post('/admin/groups/delete/{id}', 'AdminController@deleteGroup');
        
        $this->router->get('/admin/users', 'AdminController@users');
        $this->router->get('/admin/users/create', 'AdminController@createUser');
        $this->router->post('/admin/users/create', 'AdminController@createUser');
        $this->router->get('/admin/users/edit/{id}', 'AdminController@editUser');
        $this->router->post('/admin/users/edit/{id}', 'AdminController@editUser');
        $this->router->post('/admin/users/delete/{id}', 'AdminController@deleteUser');
        $this->router->get('/admin/settings', 'AdminController@settings');
        $this->router->get('/admin/kiosk', 'AdminController@kiosk');
        $this->router->post('/admin/kiosk', 'AdminController@kiosk');
        $this->router->get('/admin/timezone', 'AdminController@timezone');
        $this->router->post('/admin/timezone', 'AdminController@timezone');
        $this->router->get('/admin/check-db', 'AdminController@checkDatabase');
        $this->router->post('/admin/check-db', 'AdminController@checkDatabase');
        
        // Data Sources
        // Migration of existing Weathermap maps
        $this->router->get('/admin/migration', 'MigrationController@index');
        $this->router->post('/admin/migration/upload', 'MigrationController@upload');
        $this->router->get('/admin/migration/{id}', 'MigrationController@review');
        $this->router->post('/admin/migration/{id}', 'MigrationController@save');
        $this->router->get('/admin/migration/{id}/compare', 'MigrationController@compare');
        $this->router->get('/admin/migration/{id}/image/{kind}', 'MigrationController@image');
        $this->router->post('/admin/migration/{id}/rollback', 'MigrationController@rollback');
        $this->router->post('/admin/migration/{id}/delete', 'MigrationController@delete');

        $this->router->get('/admin/data-sources', 'AdminController@dataSources');
        $this->router->get('/admin/data-sources/create', 'AdminController@createDataSource');
        $this->router->post('/admin/data-sources/create', 'AdminController@createDataSource');
        $this->router->get('/admin/data-sources/edit/{id}', 'AdminController@editDataSource');
        $this->router->post('/admin/data-sources/edit/{id}', 'AdminController@editDataSource');
        $this->router->post('/admin/data-sources/delete/{id}', 'AdminController@deleteDataSource');
        $this->router->get('/admin/data-sources/test/{id}', 'AdminController@testDataSource');
    }
    
    /**
     * Pages only load scripts, styles, images and fonts from this site.
     * Inline scripts and styles are still allowed: the views use them.
     * APP_CSP replaces the policy, APP_CSP=off sends none.
     */
    public const DEFAULT_CSP = "default-src 'self'; img-src 'self' data: blob:; "
        . "style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; font-src 'self' data:; "
        . "connect-src 'self'; frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self'";

    private function sendSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        $csp = trim((string) $this->config->get('APP_CSP', ''));
        if ($csp === '') {
            $csp = self::DEFAULT_CSP;
        }
        if (strtolower($csp) !== 'off') {
            header('Content-Security-Policy: ' . $csp);
        }
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
    }

    public function run(): void
    {
        $this->sendSecurityHeaders();

        // Start session
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => $this->isHttps(),
            ]);
            session_start();
        }
        
        // Get request info
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        
        // Dispatch the request
        $this->router->dispatch($method, $uri, [
            'config' => $this->config,
            'database' => $this->database,
        ]);
    }
    
    private function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    public function getConfig(): Config
    {
        return $this->config;
    }
    
    public function getDatabase(): Database
    {
        return $this->database;
    }
}
