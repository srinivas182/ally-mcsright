<?php

declare(strict_types=1);

namespace Modules\Core\Mail;

use Modules\Core\Branding\OfficeBrandSource;
use Modules\Core\Tenancy\Models\TenantDomain;
use Modules\Core\Tenancy\TenantContext;
use Throwable;

/**
 * EM3: who an email comes from, for its look. Inside a brokerage: its name, logo, colour and website, "sent with
 * RightAlly". Otherwise (HQ, sign-up, platform notices): RightAlly's own logo and colour.
 */
final class MailBrand
{
    public const RIGHTALLY_BLUE = '#155DFC';

    /** EM5: while set, emails use RightAlly's own look (the founder's welcome to a new brokerage, say). */
    private static bool $platform = false;

    /**
     * @template T
     *
     * @param  callable(): T  $send
     * @return T
     */
    public static function asPlatform(callable $send): mixed
    {
        $was = self::$platform;
        self::$platform = true;
        try {
            return $send();
        } finally {
            self::$platform = $was;
        }
    }

    /** @return array{name: string, logo: string|null, color: string, url: string, platform: bool, team: string} */
    public static function current(): array
    {
        $platform = ['name' => 'RightAlly', 'logo' => 'https://'.config('core.hq_host').'/brand/rightally-email.png', 'color' => self::RIGHTALLY_BLUE,
            'url' => 'https://'.config('core.platform_domain'), 'platform' => true, 'team' => 'The RightAlly team'];
        if (self::$platform) {
            return $platform;
        }
        try {
            $tenants = app(TenantContext::class);
            if (! $tenants->check() || $tenants->current()->isHq()) {
                return $platform;
            }
            $tenant = $tenants->current();
            $brand = app()->bound(OfficeBrandSource::class) ? app(OfficeBrandSource::class)->forCurrentTenant() : null;
            $site = TenantDomain::query()->where('tenant_id', $tenant->id)->where('purpose', 'website')->orderByRaw("case kind when 'custom' then 0 else 1 end")->value('host');
            $color = $brand?->primary !== null && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $brand->primary) === 1 ? (string) $brand->primary : self::RIGHTALLY_BLUE;

            return ['name' => (string) $tenant->name, 'logo' => $brand?->logo, 'color' => $color,
                'url' => $site !== null ? 'https://'.$site : 'https://'.($tenant->primaryDomain()->host ?? config('core.platform_domain')),
                'platform' => false, 'team' => 'The '.$tenant->name.' team'];
        } catch (Throwable) {
            return $platform;
        }
    }
}
