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

    public function valid(string $sha256, string $signature): bool
    {
        $raw = base64_decode($signature, true);

        return $raw !== false && strlen($raw) === SODIUM_CRYPTO_SIGN_BYTES && sodium_crypto_sign_verify_detached($raw, $sha256, $this->publicKey());
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
            $seed = hash('sha256', 'rightally-forms-seal|'.(string) config('app.key'), true);
        }

        return sodium_crypto_sign_seed_keypair($seed);
    }
}
