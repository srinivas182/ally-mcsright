<?php

declare(strict_types=1);

namespace Modules\Core\Branding;

/**
 * No tenant brand colours until branding settings exist; the default theme applies.
 */
final class NullBrandColourSource implements BrandColourSource
{
    public function forCurrentTenant(): ?string
    {
        return null;
    }
}
