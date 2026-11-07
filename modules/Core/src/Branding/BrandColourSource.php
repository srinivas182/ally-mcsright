<?php

declare(strict_types=1);

namespace Modules\Core\Branding;

/**
 * Supplies the current tenant's brand colour (#RRGGBB) or null when none is set.
 * The settings-backed implementation replaces NullBrandColourSource in Sprint 8 (specs/core/05).
 */
interface BrandColourSource
{
    public function forCurrentTenant(): ?string;
}
