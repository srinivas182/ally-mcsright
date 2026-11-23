<?php

declare(strict_types=1);

namespace Modules\Core\Branding;

use Modules\Core\Tenancy\TenantContext;

/**
 * Per-tenant back-office colours injected into the page head: the brokerage's whole palette (BR2, "full"), or its
 * primary and focus colours only ("accent").
 * Returns null when there is no tenant or no brand colour, so the default theme applies.
 */
final class TenantTheme
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ThemeBuilder $themes,
        private readonly BrandColourSource $brandColours,
        private readonly OfficeBrandSource $officeBrands,
    ) {}

    public function css(): ?string
    {
        if (! $this->context->check()) {
            return null;
        }

        // BR2: the brokerage's own colours, across the whole back office or on buttons and highlights only.
        $office = $this->context->current()->isHq() ? null : $this->officeBrands->forCurrentTenant();
        if ($office !== null && $office->primary !== null && $office->mode !== 'off') {
            return ($office->mode === 'full' ? $this->themes->backOfficeFull($office->primary) : $this->themes->backOfficeAccent($office->primary))
                ->css('html:root:root', 'html:root:root.dark');
        }
        if ($office !== null && $office->mode === 'off') {
            return null;
        }

        $brand = $this->brandColours->forCurrentTenant();

        return $brand === null ? null : $this->themes->backOfficeAccent($brand)->css('html:root:root', 'html:root:root.dark');
    }
}
