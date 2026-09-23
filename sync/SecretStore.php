<?php
declare(strict_types=1);
/**
 * SecretStore - Abstraction luu secret at rest (§4).
 * Hien tai: DpapiSecretStore (Windows DPAPI LocalMachine, key ngoai DB/code).
 * TgSecret giu nguyen ten de tuong thich, delegate sang store.
 */
require_once __DIR__ . '/TgSecret.php';

interface SecretStore
{
    /** @return string|null ciphertext (null = that bai) */
    public function protect(string $raw): ?string;
    /** @return string|null plaintext (null = that bai) */
    public function unprotect(string $cipher): ?string;
    public function available(): bool;
}

class DpapiSecretStore implements SecretStore
{
    public function protect(string $raw): ?string
    {
        return TgSecret::protect($raw);
    }
    public function unprotect(string $cipher): ?string
    {
        return TgSecret::unprotect($cipher);
    }
    public function available(): bool
    {
        return TgSecret::available();
    }
}

class SecretStoreFactory
{
    public static function get(): SecretStore
    {
        return new DpapiSecretStore();
    }
}
