<?php

declare(strict_types=1);

namespace Modules\Forms\Console;

use Illuminate\Console\Command;
use Modules\Forms\Sealer;

/** Generates a dedicated key for sealing signed documents (FORMS_SEAL_SEED). */
final class SealKey extends Command
{
    protected $signature = 'forms:seal-key';

    protected $description = 'Generate a dedicated key for sealing signed documents';

    public function handle(Sealer $sealer): int
    {
        $key = Sealer::generate();
        $this->line('Set this in the environment (keep it secret, like APP_KEY):');
        $this->line('FORMS_SEAL_SEED='.$key['seed']);
        $this->newLine();
        $this->line('Public key (safe to publish): '.$key['public_key']);
        if ($sealer->dedicated()) {
            $this->warn('A key is already set. If you replace it, add the current public key ('.$sealer->publicKeyBase64().') to FORMS_SEAL_PREVIOUS_PUBLIC_KEYS so past documents still verify.');
        }

        return self::SUCCESS;
    }
}
