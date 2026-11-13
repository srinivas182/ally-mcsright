<?php

declare(strict_types=1);

namespace Modules\Forms;

/**
 * Tamper-evident sealing (specs/forms/01, addition 3): every completed document's SHA-256 is signed with an
 * Ed25519 key, so anyone can check that a copy is exactly what was signed. The key comes from FORMS_SEAL_SEED
 * (32 bytes, base64) or, if unset, is derived from the application key.
 */
final class Sealer
{
    public function sign(string $sha256): string
    {
        return base64_encode(sodium_crypto_sign_detached($sha256, sodium_crypto_sign_secretkey($this->keypair())));
    }

    /**
     * Checks a seal against the current key and any previous public keys (forms.seal_previous_public_keys), so
     * documents sealed before a key rotation still verify.
     */
    public function valid(string $sha256, string $signature): bool
    {
        $raw = base64_decode($signature, true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }
        foreach ([$this->publicKey(), ...$this->previousPublicKeys()] as $key) {
            if (sodium_crypto_sign_verify_detached($raw, $sha256, $key)) {
                return true;
            }
        }

        return false;
    }

    /** Whether a dedicated sealing key is set (recommended; required for safe app-key rotation). */
    public function dedicated(): bool
    {
        $seed = base64_decode((string) config('forms.seal_seed', ''), true);

        return $seed !== false && strlen($seed) === SODIUM_CRYPTO_SIGN_SEEDBYTES;
    }

    /**
     * A new dedicated key: the seed for FORMS_SEAL_SEED and its public key.
     *
     * @return array{seed: string, public_key: string}
     */
    public static function generate(): array
    {
        $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);

        return ['seed' => base64_encode($seed), 'public_key' => base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed)))];
    }

    /**
     * @return list<non-empty-string>
     */
    private function previousPublicKeys(): array
    {
        $keys = [];
        foreach (array_filter(array_map('trim', explode(',', (string) config('forms.seal_previous_public_keys', '')))) as $b64) {
            $key = base64_decode($b64, true);
            if ($key !== false && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    public function publicKeyBase64(): string
    {
        return base64_encode($this->publicKey());
    }

    /**
     * @return non-empty-string
     */
    private function publicKey(): string
    {
        return sodium_crypto_sign_publickey($this->keypair());
    }

    /**
     * @return non-empty-string
     */
    private function keypair(): string
    {
        $seed = base64_decode((string) config('forms.seal_seed', ''), true);
        if ($seed === false || strlen($seed) !== SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            if (app()->isProduction()) {
                logger()->critical('FORMS_SEAL_SEED is not set: document seals depend on the app key. Run `php artisan forms:seal-key` and set it.');
            }
            $seed = hash('sha256', 'rightally-forms-seal|'.(string) config('app.key'), true);
        }

        return sodium_crypto_sign_seed_keypair($seed);
    }
}
