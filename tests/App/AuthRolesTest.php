<?php

declare(strict_types=1);

namespace Tests\App;

use App\Controllers\MapManagementController;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use PHPUnit\Framework\TestCase;

final class AuthRolesTest extends TestCase
{
    private Auth $auth;
    private Database $db;

    protected function setUp(): void
    {
        $data = TESTS_OUTPUT . '/auth-roles';
        @unlink($data . '/test.db');
        @mkdir($data, 0775, true);

        $config = new Config();
        $config->set('APP_ROOT', dirname(TESTS_ROOT) . '/src');
        $config->set('DATA_ROOT', $data);
        $config->set('DB_TYPE', 'sqlite');
        $config->set('DB_PATH', $data . '/test.db');
        $config->set('AUTH_ENABLED', 'true');
        $this->db = new Database($config);
        $this->auth = new Auth($this->db, $config);
        $this->db->execute("INSERT INTO maps (name, config_file) VALUES ('Core', 'core.conf')");
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function logIn(string $role): void
    {
        $_SESSION = ['logged_in' => true, 'user_id' => 42, 'username' => 'u', 'role' => $role];
    }

    public function testRoles(): void
    {
        $mapId = (int) $this->db->queryOne('SELECT id FROM maps')['id'];

        $this->logIn('admin');
        $this->assertTrue($this->auth->isAdmin());
        $this->assertTrue($this->auth->canEdit());

        // An editor edits and sees every map, but is no administrator
        $this->logIn('editor');
        $this->assertFalse($this->auth->isAdmin());
        $this->assertTrue($this->auth->canEdit());
        $this->assertTrue($this->auth->canEditMap($mapId));
        $this->assertTrue($this->auth->canViewMap($mapId));

        $this->logIn('viewer');
        $this->assertFalse($this->auth->isAdmin());
        $this->assertFalse($this->auth->canEdit());
        $this->assertFalse($this->auth->canEditMap($mapId));
        $this->assertFalse($this->auth->canViewMap($mapId));

        $_SESSION = [];
        $this->assertFalse($this->auth->canEdit());
    }

    public function testReturnUrlStaysOnThisSite(): void
    {
        $this->assertSame('/', MapManagementController::safeReturn('/'));
        $this->assertSame('/editor/edit/3', MapManagementController::safeReturn('/editor/edit/3'));
        $this->assertSame('/maps?group_id=2', MapManagementController::safeReturn('/maps?group_id=2'));
        foreach (['', 'https://evil.example', '//evil.example', '/\\evil.example', "/x\r\nLocation: y", 'editor'] as $bad) {
            $this->assertSame('/editor', MapManagementController::safeReturn($bad), $bad);
        }
    }
}
