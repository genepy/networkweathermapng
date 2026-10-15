<?php
/**
 * NetworkWeathermapNG - encryption of the secrets kept in the database
 * (data source passwords).
 *
 * Values are encrypted with libsodium (XSalsa20-Poly1305) and stored as
 * "enc:v1:<base64 of nonce + ciphertext>". The key comes from APP_KEY
 * ("base64:<32 bytes>" or any passphrase), or from DATA_ROOT/app.key, which
 * is created on first use. Without sodium or a key, values fall back to the
 * old base64 encoding. Values written before encryption existed (plain
 * base64) are still read.
 */

declare(strict_types=1);

namespace App\Core;

class SecretBox
{
    public const PREFIX = 'enc:v1:';
    public const KEY_FILE = 'app.key';

    private ?string $key;

    public function __construct(?string $key)
    {
        $this->key = $key;
    }

    public static function fromConfig(Config $config): self
    {
        return new self(self::resolveKey(
            (string) $config->get('APP_KEY', ''),
            (string) $config->getDataRoot()
        ));
    }

    /** The 32-byte key, or null when encryption is not possible here. */
    public static function resolveKey(string $appKey, string $dataRoot): ?string
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            return null;
        }

        $appKey = trim($appKey);
        if ($appKey !== '') {
            if (str_starts_with($appKey, 'base64:')) {
                $raw = base64_decode(substr($appKey, 7), true);
                if ($raw !== false && strlen($raw) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                    return $raw;
                }
            }
            return sodium_crypto_generichash($appKey, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        }

        if ($dataRoot === '' || !is_dir($dataRoot)) {
            return null;
        }

        $file = rtrim($dataRoot, '/') . '/' . self::KEY_FILE;
        if (is_readable($file)) {
            $raw = base64_decode(trim((string) file_get_contents($file)), true);
            return $raw !== false && strlen($raw) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES ? $raw : null;
        }

        // First use: create the key, readable by this user only. "x" mode
        // fails if another request created it meanwhile; read that one.
        $raw = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $old = umask(0077);
        $handle = @fopen($file, 'x');
        umask($old);
        if ($handle === false) {
            return is_readable($file) ? self::resolveKey('', $dataRoot) : null;
        }
        fwrite($handle, base64_encode($raw) . "\n");
        fclose($handle);
        @chmod($file, 0600);

        return $raw;
    }

    public function canEncrypt(): bool
    {
        return $this->key !== null;
    }

    public function encrypt(string $plain): string
    {
        if ($this->key === null) {
            return base64_encode($plain);
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    /** The plain value; '' when an encrypted value cannot be opened (wrong or missing key). */
    public function decrypt(string $stored): string
    {
        if (!self::isEncrypted($stored)) {
            $plain = base64_decode($stored, true);
            return $plain === false ? '' : $plain;
        }

        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($this->key === null || $raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }

        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key
        );

        return $plain === false ? '' : $plain;
    }

    public static function isEncrypted(string $stored): bool
    {
        return str_starts_with($stored, self::PREFIX);
    }
}
