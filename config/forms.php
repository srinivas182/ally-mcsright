<?php

declare(strict_types=1);

return [
    // Ed25519 seed for sealing signed documents (32 bytes, base64); derived from APP_KEY when empty.
    'seal_seed' => env('FORMS_SEAL_SEED', ''),

    // After rotating the seal key, list the old public keys (base64, comma-separated) so past seals still verify.
    'seal_previous_public_keys' => env('FORMS_SEAL_PREVIOUS_PUBLIC_KEYS', ''),

    // pdftoppm (poppler) renders pages for the visual field editor at 72 dpi (1 pixel = 1 PDF point).
    'pdftoppm' => env('FORMS_PDFTOPPM', 'pdftoppm'),

    // qpdf binary used to repair PDFs FPDI cannot read as uploaded.
    'qpdf' => env('FORMS_QPDF', 'qpdf'),
];
