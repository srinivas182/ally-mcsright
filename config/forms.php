<?php

declare(strict_types=1);

return [
    // Ed25519 seed for sealing signed documents (32 bytes, base64); derived from APP_KEY when empty.
    'seal_seed' => env('FORMS_SEAL_SEED', ''),

    // qpdf binary used to repair PDFs FPDI cannot read as uploaded.
    'qpdf' => env('FORMS_QPDF', 'qpdf'),
];
