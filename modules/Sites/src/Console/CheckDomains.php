<?php

declare(strict_types=1);

namespace Modules\Sites\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Core\Tenancy\Enums\DomainKind;
use Modules\Core\Tenancy\Enums\SslStatus;
use Modules\Core\Tenancy\Enums\VerificationStatus;
use Modules\Core\Tenancy\Models\TenantDomain;
use Modules\Sites\Domains\DomainConnector;

/**
 * DM2: every few minutes, checks the custom website domains that are not connected yet (added in the last 30 days),
 * and once a day the connected ones, so a website moves to its domain soon after the DNS is set and back to its
 * free address if the domain stops pointing to us.
 */
final class CheckDomains extends Command
{
    protected $signature = 'sites:check-domains';

    protected $description = 'Check custom website domains and connect the ones that now point to us';

    public function handle(DomainConnector $connector): int
    {
        $now = CarbonImmutable::now();
        $checked = 0;
        TenantDomain::query()->where('kind', DomainKind::Custom)->where('purpose', 'website')
            ->where(function ($q) use ($now): void {
                $q->where(fn ($w) => $w->where(fn ($n) => $n->where('verification_status', '!=', VerificationStatus::Verified)->orWhere('ssl_status', '!=', SslStatus::Active))
                    ->where('created_at', '>=', $now->subDays(30))->where(fn ($c) => $c->whereNull('checked_at')->orWhere('checked_at', '<=', $now->subMinutes(5))))
                    ->orWhere(fn ($w) => $w->where('verification_status', VerificationStatus::Verified)->where('ssl_status', SslStatus::Active)
                        ->where(fn ($c) => $c->whereNull('checked_at')->orWhere('checked_at', '<=', $now->subDay())));
            })
            ->orderBy('id')->limit(500)->get()
            ->each(function (TenantDomain $d) use ($connector, &$checked): void {
                $connector->check($d);
                $checked++;
            });
        $this->info("Checked {$checked} domain(s).");

        return self::SUCCESS;
    }
}
