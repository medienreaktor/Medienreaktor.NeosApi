<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Security\OAuth;

use Defuse\Crypto\Key;
use Neos\Flow\Annotations as Flow;
use Neos\Utility\Files;

/**
 * Manages the RSA key pair used for signing access tokens and the symmetric
 * encryption key used for authorization codes. Generate via:
 *
 *   ./flow neosapi:generatekeys
 */
#[Flow\Scope('singleton')]
class KeyManager
{
    /**
     * @var array{privateKeyPath: string, publicKeyPath: string, encryptionKeyPath: string}
     */
    #[Flow\InjectConfiguration(package: 'Medienreaktor.NeosApi', path: 'oauth')]
    protected array $settings;

    public function getPrivateKeyPath(): string
    {
        return $this->requireKeyFile($this->settings['privateKeyPath']);
    }

    public function getPublicKeyPath(): string
    {
        return $this->requireKeyFile($this->settings['publicKeyPath']);
    }

    public function getEncryptionKey(): Key
    {
        return Key::loadFromAsciiSafeString(trim(file_get_contents($this->requireKeyFile($this->settings['encryptionKeyPath'])) ?: ''));
    }

    public function keysExist(): bool
    {
        return file_exists($this->settings['privateKeyPath'])
            && file_exists($this->settings['publicKeyPath'])
            && file_exists($this->settings['encryptionKeyPath']);
    }

    public function generateKeys(bool $force = false): void
    {
        if ($this->keysExist() && !$force) {
            throw new \RuntimeException('OAuth keys already exist. Use --force to overwrite (this invalidates all issued tokens).', 1751980001);
        }

        $keyResource = openssl_pkey_new([
            'private_key_bits' => 4096,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($keyResource === false) {
            throw new \RuntimeException('Failed to generate RSA key pair: ' . openssl_error_string(), 1751980002);
        }
        openssl_pkey_export($keyResource, $privateKey);
        $publicKey = openssl_pkey_get_details($keyResource)['key'];

        $this->writeKeyFile($this->settings['privateKeyPath'], $privateKey);
        $this->writeKeyFile($this->settings['publicKeyPath'], $publicKey);
        $this->writeKeyFile($this->settings['encryptionKeyPath'], Key::createNewRandomKey()->saveToAsciiSafeString());
    }

    private function writeKeyFile(string $path, string $contents): void
    {
        Files::createDirectoryRecursively(dirname($path));
        file_put_contents($path, $contents);
        chmod($path, 0600);
    }

    private function requireKeyFile(string $path): string
    {
        if (!file_exists($path)) {
            throw new \RuntimeException(sprintf('OAuth key file "%s" is missing. Run: ./flow neosapi:generatekeys', $path), 1751980003);
        }

        return $path;
    }
}
