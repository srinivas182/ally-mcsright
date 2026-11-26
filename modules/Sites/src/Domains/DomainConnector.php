<?php

declare(strict_types=1);

namespace Modules\Sites\Domains;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Services\OutsideServices;
use Modules\Core\Tenancy\Enums\DomainKind;
use Modules\Core\Tenancy\Enums\SslStatus;
use Modules\Core\Tenancy\Enums\VerificationStatus;
use Modules\Core\Tenancy\Models\TenantDomain;
use Throwable;

/**
 * DM2: connects a brokerage's or agent's own web address to their website. The domain is registered with the
 * custom-domain service HQ chose under Outside services (Cloudflare for SaaS recommended; Approximated; or our own
 * server, which only needs DNS), then checked until it points to us and its certificate is ready. Until then the
 * website keeps working on its free address (name.rightally.site), which is also where it falls back to if the
 * domain stops pointing to us. Nothing here ever stops the website from loading.
 */
final class DomainConnector
{
    private const CF = 'https://api.cloudflare.com/client/v4/zones/';

    private const APPROXIMATED = 'https://cloud.approximated.app/api/vhosts';

    public function __construct(private readonly OutsideServices $services, private readonly DnsLookup $dns) {}

    /** Registers the domain with the service (when one is on), then checks it once. */
    public function connect(TenantDomain $domain): TenantDomain
    {
        $service = $this->services->active('custom_domains');
        try {
            if ($service !== null && $service['provider'] === 'cloudflare_saas' && $domain->provider_hostname_id === null) {
                $r = $this->cloudflare($service['keys'])->post(self::CF.rawurlencode((string) $service['keys']['zone_id']).'/custom_hostnames',
                    ['hostname' => $domain->host, 'ssl' => ['method' => 'http', 'type' => 'dv', 'settings' => ['min_tls_version' => '1.2']]]);
                $id = $r->json('result.id');
                if (is_string($id)) {
                    $domain->forceFill(['provider_hostname_id' => $id])->save();
                } elseif ($r->json('errors.0.code') !== 1406) { // 1406: already there (added before), checked below
                    Log::warning('DM2: the custom-domain service refused a domain.', ['host' => $domain->host, 'status' => $r->status(), 'error' => $r->json('errors.0.message')]);
                }
            } elseif ($service !== null && $service['provider'] === 'approximated' && $domain->provider_hostname_id === null) {
                $r = Http::timeout(15)->withHeaders(['api-key' => (string) $service['keys']['api_key']])->acceptJson()
                    ->post(self::APPROXIMATED, ['incoming_address' => $domain->host, 'target_address' => self::target()]);
                if ($r->successful()) {
                    $domain->forceFill(['provider_hostname_id' => (string) ($r->json('data.id') ?? $domain->host)])->save();
                }
            }
        } catch (Throwable $e) {
            Log::warning('DM2: could not reach the custom-domain service.', ['host' => $domain->host, 'error' => $e->getMessage()]);
        }

        return $this->check($domain);
    }

    /**
     * Checks where the domain points and whether its certificate is ready, and records the result in plain words.
     */
    public function check(TenantDomain $domain): TenantDomain
    {
        if ($domain->kind !== DomainKind::Custom) {
            return $domain;
        }
        $service = $this->services->active('custom_domains');
        $points = $this->pointsToUs($domain->host);
        [$verified, $ssl, $message] = [$points, $points, $points ? null : $this->notPointing($domain->host)];

        if ($service !== null && $service['provider'] === 'cloudflare_saas' && $domain->provider_hostname_id !== null) {
            try {
                $r = $this->cloudflare($service['keys'])->get(self::CF.rawurlencode((string) $service['keys']['zone_id']).'/custom_hostnames/'.rawurlencode($domain->provider_hostname_id));
                if ($r->successful()) {
                    $status = (string) $r->json('result.status');
                    $sslStatus = (string) $r->json('result.ssl.status');
                    $verified = $status === 'active';
                    $ssl = $sslStatus === 'active';
                    $error = $r->json('result.verification_errors.0') ?? $r->json('result.ssl.validation_errors.0.message');
                    $message = match (true) {
                        $verified && $ssl => null,
                        ! $points => $this->notPointing($domain->host),
                        $verified => 'Your address points to us. The security certificate (https) is being set up, usually within a few minutes.',
                        default => is_string($error) && $error !== '' ? 'Not connected yet: '.mb_substr($error, 0, 200) : 'Your address points to us. Cloudflare is confirming it, usually within a few minutes.',
                    };
                }
            } catch (Throwable $e) {
                Log::warning('DM2: could not check a domain with the custom-domain service.', ['host' => $domain->host, 'error' => $e->getMessage()]);
            }
        }

        $was = $domain->verification_status === VerificationStatus::Verified && $domain->ssl_status === SslStatus::Active;
        $connected = $verified && $ssl;
        $domain->forceFill([
            'verification_status' => $verified ? VerificationStatus::Verified : ($domain->verification_status === VerificationStatus::Verified ? VerificationStatus::Failed : VerificationStatus::Pending),
            'ssl_status' => $ssl ? SslStatus::Active : SslStatus::Pending,
            'verified_at' => $verified ? ($domain->verified_at ?? CarbonImmutable::now()) : $domain->verified_at,
            'checked_at' => CarbonImmutable::now(), 'check_message' => $message,
        ])->save();
        if ($connected !== $was) {
            app(AuditLogger::class)->record($connected ? 'sites.domain_connected' : 'sites.domain_disconnected', metadata: ['host' => $domain->host], severity: 'notice',
                tenantId: (int) $domain->tenant_id);
        }

        return $domain;
    }

    /** Removes the domain from the service (the website stays on its other addresses). */
    public function disconnect(TenantDomain $domain): void
    {
        $service = $this->services->active('custom_domains');
        try {
            if ($service !== null && $service['provider'] === 'cloudflare_saas' && $domain->provider_hostname_id !== null) {
                $this->cloudflare($service['keys'])->delete(self::CF.rawurlencode((string) $service['keys']['zone_id']).'/custom_hostnames/'.rawurlencode($domain->provider_hostname_id));
            } elseif ($service !== null && $service['provider'] === 'approximated') {
                Http::timeout(15)->withHeaders(['api-key' => (string) $service['keys']['api_key']])->acceptJson()
                    ->delete(self::APPROXIMATED.'/by/incoming/'.rawurlencode($domain->host));
            }
        } catch (Throwable $e) {
            Log::warning('DM2: could not remove a domain from the custom-domain service.', ['host' => $domain->host, 'error' => $e->getMessage()]);
        }
    }

    /** Where customers point their www record. */
    public static function target(): string
    {
        return strtolower((string) config('sites.cname_target'));
    }

    private function pointsToUs(string $host): bool
    {
        $target = self::target();
        $cname = $this->dns->cname($host);
        if ($cname !== null) {
            return $cname === $target || str_ends_with($cname, '.'.$target);
        }
        $ours = $this->dns->addresses($target);
        $theirs = $this->dns->addresses($host);

        return $ours !== [] && $theirs !== [] && array_diff($theirs, $ours) === [];
    }

    private function notPointing(string $host): string
    {
        $name = str_starts_with($host, 'www.') ? 'www' : explode('.', $host)[0];

        return "We can't see your address pointing to us yet. Add a CNAME record named {$name} with the value ".self::target().'. DNS changes can take a few hours.';
    }

    /** @param  array<string, string>  $keys */
    private function cloudflare(array $keys): PendingRequest
    {
        return Http::timeout(15)->withToken((string) ($keys['api_token'] ?? ''))->acceptJson();
    }
}
