<?php
/**
 * Zabbix Weathermap - Authentication Service
 */

declare(strict_types=1);

namespace App\Core;

class Auth
{
    public const DEFAULT_PASSWORD = 'admin';

    /** admin: everything; editor: maps and the editor, no users, data sources or settings; viewer: read only */
    public const ROLES = ['viewer', 'editor', 'admin'];

    private Database $database;
    private Config $config;
    
    public function __construct(Database $database, Config $config)
    {
        $this->database = $database;
        $this->config = $config;
    }
    
    public function attempt(string $username, string $password): bool
    {
        $user = $this->database->queryOne(
            "SELECT * FROM users WHERE username = ? AND active = 1",
            [$username]
        );
        
        if (!$user) {
            return false;
        }
        
        if (!password_verify($password, $user['password_hash'])) {
            return false;
        }
        
        // New session id on login, so a session id planted before login is useless
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        // Set session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['logged_in'] = true;

        // The well-known default password must be replaced before anything else
        if ($password === self::DEFAULT_PASSWORD) {
            $_SESSION['must_change_password'] = true;
        } else {
            unset($_SESSION['must_change_password']);
        }
        
        return true;
    }
    
    public function logout(): void
    {
        $_SESSION = [];
        
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        
        session_destroy();
    }
    
    public function check(): bool
    {
        if (!$this->config->isAuthEnabled()) {
            return true;
        }
        
        return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
    }
    
    public function mustChangePassword(): bool
    {
        return $this->check() && !empty($_SESSION['must_change_password']);
    }

    public function passwordChanged(): void
    {
        unset($_SESSION['must_change_password']);
    }
    
    public function user(): ?array
    {
        if (!$this->check()) {
            return null;
        }
        
        return [
            'id' => $_SESSION['user_id'] ?? null,
            'username' => $_SESSION['username'] ?? null,
            'role' => $_SESSION['role'] ?? null,
        ];
    }
    
    public function isAdmin(): bool
    {
        $user = $this->user();
        return $user && $user['role'] === 'admin';
    }

    /** May create, edit and manage maps: administrators and editors */
    public function canEdit(): bool
    {
        if (!$this->config->isAuthEnabled()) {
            return true;
        }

        $user = $this->user();
        return $user && in_array($user['role'], ['admin', 'editor'], true);
    }
    
    public function canViewMap(int $mapId): bool
    {
        if (!$this->config->isAuthEnabled()) {
            return true;
        }
        
        // Editors manage every map, so they see every map
        if ($this->canEdit()) {
            return true;
        }
        
        $user = $this->user();
        if (!$user) {
            return false;
        }
        
        // Check if map is public (user_id = 0)
        $public = $this->database->queryOne(
            "SELECT 1 FROM user_map_permissions WHERE user_id = 0 AND map_id = ?",
            [$mapId]
        );
        
        if ($public) {
            return true;
        }
        
        // Check user-specific permission
        $permission = $this->database->queryOne(
            "SELECT 1 FROM user_map_permissions WHERE user_id = ? AND map_id = ?",
            [$user['id'], $mapId]
        );
        
        if ($permission) {
            return true;
        }

        // Check group permission
        $groupPermission = $this->database->queryOne(
            "SELECT 1 
             FROM user_group_permissions ugp
             JOIN maps m ON m.group_id = ugp.group_id
             WHERE ugp.user_id = ? AND m.id = ?",
            [$user['id'], $mapId]
        );
        
        return $groupPermission !== null;
    }
    
    public function canEditMap(int $mapId): bool
    {
        return $this->canEdit();
    }
}
