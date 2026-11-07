<?php

declare(strict_types=1);

namespace Modules\Core\Branding;

use Modules\Core\Tenancy\TenantContext;

/**
 * Per-tenant back-office accent injected into the page head (primary and focus colours only).
 * Returns null when there is no tenant or no brand colour, so the default theme applies.
 */
final class TenantTheme
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ThemeBuilder $themes,
        private readonly BrandColourSource $brandColours,
    ) {}

    public function css(): ?string
    {
        if (! $this->context->check()) {
            return null;
        }

        $brand = $this->brandColours->forCurrentTenant();

        return $brand === null ? null : $this->themes->backOfficeAccent($brand)->css(':root', ':root.dark');
    }
}
