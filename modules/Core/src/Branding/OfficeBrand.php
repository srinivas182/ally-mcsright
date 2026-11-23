<?php

declare(strict_types=1);

namespace Modules\Core\Branding;

/**
 * BR2: how a brokerage's back office looks: its logo, its brand colour, a photo for the sign-in page, and how far the
 * colour goes ("full": the whole back office in its colours; "accent": buttons and highlights only; "off": RightAlly's).
 */
final readonly class OfficeBrand
{
    public const MODES = ['full', 'accent', 'off'];

    public function __construct(
        public ?string $primary,
        public ?string $logo,
        public ?string $photo,
        public string $mode = 'full',
    ) {}
}
