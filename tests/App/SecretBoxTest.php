<?php

declare(strict_types=1);

namespace Tests\App;

use App\Core\Config;
use App\Core\Database;
use App\Core\SecretBox;
use App\Services\DataSourceService;
use PHPUnit\Framework\TestCase;

final class SecretBoxTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = TESTS_OUTPUT . '/secrets-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0755, true);
    }

    public function testRoundTripIsEncrypted(): void
    {
        $box = new SecretBox(SecretBox::resolveKey('a passphrase', ''));
        $stored = $box->encrypt('s3cret');

        $this->assertStringStartsWith(SecretBox::PREFIX, $stored);
        $this->assertStringNotContainsString(base64_encode('s3cret'), $stored);
        $this->assertNotSame($stored, $box->encrypt('s3cret'), 'A fresh nonce each time');
        $this->assertSame('s3cret', $box->decrypt($stored));
    }

    public function testOldBase64ValuesAreStillRead(): void
    {
        $box = new SecretBox(SecretBox::resolveKey('k', ''));

        $this->assertSame('legacy', $box->decrypt(base64_encode('legacy')));
    }

    public function testWrongOrMissingKeyGivesNothing(): void
    {
        $stored = (new SecretBox(SecretBox::resolveKey('one', '')))->encrypt('s3cret');

        $this->assertSame('', (new SecretBox(SecretBox::resolveKey('two', '')))->decrypt($stored));
        $this->assertSame('', (new SecretBox(null))->decrypt($stored));
    }

    public function testWithoutKeyFallsBackToBase64(): void
    {
        $box = new SecretBox(null);

        $this->assertFalse($box->canEncrypt());
        $this->assertSame(base64_encode('x'), $box->encrypt('x'));
    }

    public function testAppKeyInBase64IsUsedAsIs(): void
    {
        $raw = random_bytes(32);

        $this->assertSame($raw, SecretBox::resolveKey('base64:' . base64_encode($raw), ''));
    }

    public function testKeyFileIsCreatedOnceAndPrivate(): void
    {
        $first = SecretBox::resolveKey('', $this->dir);
        $file = $this->dir . '/' . SecretBox::KEY_FILE;

        $this->assertNotNull($first);
        $this->assertFileExists($file);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
        $this->assertSame($first, SecretBox::resolveKey('', $this->dir));
    }

    public function testStoredPasswordsAreUpgraded(): void
    {
        putenv('DB_TYPE=sqlite');
        putenv('DB_PATH=' . $this->dir . '/test.db');
        try {
            $db = new Database(new Config());
        } finally {
            putenv('DB_TYPE');
            putenv('DB_PATH');
        }

        $db->insert('data_sources', [
            'name' => 'old', 'type' => 'observium', 'url' => 'http://x',
            'password' => base64_encode('api-pass'),
            'settings' => json_encode(['db_password' => base64_encode('db-pass')]),
        ]);

        $service = new DataSourceService($db, new SecretBox(SecretBox::resolveKey('k', '')));
        $this->assertSame(1, $service->upgradeStoredSecrets());
        $this->assertSame(0, $service->upgradeStoredSecrets(), 'Nothing left to upgrade');

        $row = $db->queryOne('SELECT password, settings FROM data_sources');
        $settings = json_decode((string) $row['settings'], true);
        $this->assertTrue(SecretBox::isEncrypted((string) $row['password']));
        $this->assertSame('api-pass', $service->decryptPassword((string) $row['password']));
        $this->assertSame('db-pass', $service->decryptPassword((string) $settings['db_password']));
    }
}
