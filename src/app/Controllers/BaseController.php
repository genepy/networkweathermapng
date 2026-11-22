<?php
/**
 * Zabbix Weathermap - Base Controller
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\Auth;
use App\Core\ErrorPage;

abstract class BaseController
{
    protected Config $config;
    protected Database $database;
    protected Auth $auth;
    
    public function __construct(array $context)
    {
        $this->config = $context['config'];
        $this->database = $context['database'];
        $this->auth = new Auth($this->database, $this->config);
    }
    
    protected function render(string $view, array $data = [], bool $useLayout = true): void
    {
        $viewPath = APP_ROOT . '/app/Views/' . $view . '.php';
        
        if (!file_exists($viewPath)) {
            throw new \RuntimeException("View not found: {$view}");
        }
        
        // Make data available to view
        extract($data);
        
        // Add common variables
        $auth = $this->auth;
        $config = $this->config;
        $user = $this->auth->user();
        // the user's keyboard shortcuts, for assets/js/shortcuts.js
        $keysScript = (new \App\Services\KeyboardShortcuts($this->database))->scriptFor((int) ($user['id'] ?? 0));
        // how the page writes times (F51): the clock of its map, or the default one, as 24h or 12h
        $pageClock = $data['pageClock'] ?? self::clockFor($this->database, (int) ($data['map']['id'] ?? 0));
        
        // Start output buffering
        ob_start();
        include $viewPath;
        $content = ob_get_clean();
        
        // Include layout if not API request and layout is enabled
        if (!$this->isApiRequest() && $useLayout) {
            $layoutPath = APP_ROOT . '/app/Views/layouts/main.php';
            if (file_exists($layoutPath)) {
                include $layoutPath;
            } else {
                echo $content;
            }
        } else {
            echo $content;
        }
    }
    
    /**
     * A page of the Settings space (UI redesign step 6): the view drawn as
     * a panel next to the side navigation. With ?panel=1 only the view,
     * without the shell, for a drawer. The view gets $panel.
     */
    /** 24h or 12h for a map (0: the default), '' when the settings cannot be read */
    protected static function clockFor(\App\Core\Database $db, int $mapId): string
    {
        try {
            return (new \App\Services\ClockFormat($db))->forMap($mapId);
        } catch (\Throwable) {
            return '';
        }
    }

    protected function renderSettings(string $view, array $data = []): void
    {
        $panel = !empty($_GET['panel']);
        if ($panel) {
            $this->render($view, $data + ['panel' => true], false);
            return;
        }
        ob_start();
        $this->render($view, $data + ['panel' => false], false);
        $body = (string) ob_get_clean();
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $this->render('admin/_settings', $data + [
            'settingsBody' => $body,
            'settingsPage' => \App\Services\SettingsNav::pageFor($path),
            'settingsCounts' => \App\Services\SettingsNav::counts($this->database),
        ]);
    }

    protected function json(mixed $data, int $status = 200): void
    {
        // invalid UTF-8 must not turn the whole answer into an empty body
        $body = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($body === false) {
            // a NAN or INF number, too deep data: an empty answer the browser
            // cannot read; say what failed instead
            error_log('JSON answer of ' . ($_SERVER['REQUEST_URI'] ?? '?') . ' could not be encoded: ' . json_last_error_msg());
            $status = 500;
            $body = (string) json_encode(['error' => __('The server could not write its answer. The details are in the server log.')]);
        }
        http_response_code($status);
        header('Content-Type: application/json');
        echo $body;
    }
    
    protected function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }
    
    protected function requireAuth(): void
    {
        if (!$this->auth->check()) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Unauthorized'], 401);
                exit;
            }
            $this->redirect('/login');
        }

        if ($this->auth->mustChangePassword() && !str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/account/password')) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Password change required'], 403);
                exit;
            }
            $this->flash('error', 'Please replace the default password before continuing');
            $this->redirect('/account/password');
        }
    }
    
    protected function requireAdmin(): void
    {
        $this->requireAuth();
        
        if (!$this->auth->isAdmin()) {
            $this->forbidden();
        }
    }

    /** Administrators and editors: maps and the editor */
    protected function requireEditor(): void
    {
        $this->requireAuth();

        if (!$this->auth->canEdit()) {
            $this->forbidden();
        }
    }

    private function forbidden(): never
    {
        if ($this->isApiRequest()) {
            $this->json(['error' => 'Forbidden'], 403);
            exit;
        }
        ErrorPage::send(403, $this->auth);
        exit;
    }
    
    protected function isApiRequest(): bool
    {
        return str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/');
    }
    
    protected function getInput(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }
    
    protected function getJsonInput(): array
    {
        $input = file_get_contents('php://input');
        return json_decode($input, true) ?? [];
    }
    
    protected function flash(string $type, string $message): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['flash'][$type] = $message;
    }
    
    protected function getFlash(): array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $flash = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $flash;
    }
}
