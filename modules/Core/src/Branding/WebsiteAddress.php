<?php

declare(strict_types=1);

namespace Modules\Core\Branding;

use Modules\Core\Tenancy\TenantContext;

/**
 * WS1: the current brokerage's public website address, for the welcome email and the back office's "Visit website"
 * button. Websites belong to the Sites module, which answers through the 'sites.address' binding; without it (or
 * outside a brokerage, or with no website yet) there is none.
 */
final class WebsiteAddress
{
    /** @return array{host: string, live: bool}|null */
    public static function current(): ?array
    {
        if (! app()->bound('sites.address') || ! app(TenantContext::class)->check() || app(TenantContext::class)->current()->isHq()) {
            return null;
        }
        $address = app('sites.address')();

        return is_array($address) && is_string($address['host'] ?? null) && $address['host'] !== ''
            ? ['host' => $address['host'], 'live' => (bool) ($address['live'] ?? false)] : null;
    }
}
