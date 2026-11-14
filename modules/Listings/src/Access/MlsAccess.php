<?php

declare(strict_types=1);

namespace Modules\Listings\Access;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;

/**
 * MLS licences (ADR 0012): a brokerage requests access to an MLS; HQ approves with scopes and an expiry; every
 * listing query checks it (licensedSources). Revoked or expired licences take effect at once.
 */
final class MlsAccess
{
    /**
     * @return list<int> MLS source ids this brokerage may show in this scope right now
     */
    public function licensedSources(int $tenantId, string $scope = 'idx'): array
    {
        $today = CarbonImmutable::today()->toDateString();
        // Short cache: approvals and revocations apply within a minute.
        $rows = Cache::remember("mls-licences:{$tenantId}", 60, fn (): array => MlsAuthorization::query()->where('tenant_id', $tenantId)->where('status', 'active')
            ->get(['mls_source_id', 'scopes', 'expires_on'])->map(fn (MlsAuthorization $a): array => ['source' => $a->mls_source_id, 'scopes' => $a->scopes,
                'expires' => $a->expires_on?->toDateString()])->all());

        return array_values(array_map(fn (array $r): int => (int) $r['source'], array_filter($rows,
            fn (array $r): bool => in_array($scope, $r['scopes'], true) && ($r['expires'] === null || $r['expires'] >= $today))));
    }

    /**
     * @param  list<string>  $officeIds
     */
    public function request(int $tenantId, MlsSource $source, string $brokerMlsId, array $officeIds, ?string $agreementRef, User $by): MlsAuthorization
    {
        $brokerMlsId = trim($brokerMlsId);
        if ($brokerMlsId === '' || mb_strlen($brokerMlsId) > 30) {
            throw ValidationException::withMessages(['broker_mls_id' => 'Enter your broker MLS ID.']);
        }
        $existing = MlsAuthorization::query()->where('tenant_id', $tenantId)->where('mls_source_id', $source->id)->first();
        if ($existing !== null && $existing->status === 'active') {
            throw ValidationException::withMessages(['mls' => 'You already have access to this MLS.']);
        }
        $auth = $existing ?? new MlsAuthorization(['tenant_id' => $tenantId, 'mls_source_id' => $source->id]);
        $auth->fill(['broker_mls_id' => $brokerMlsId, 'office_mls_ids' => array_values(array_filter(array_map(fn ($v): string => mb_substr(trim((string) $v), 0, 30), $officeIds))),
            'scopes' => ['idx'], 'agreement_ref' => $agreementRef !== null ? mb_substr(trim($agreementRef), 0, 120) : null, 'status' => 'pending', 'requested_by' => $by->id,
            'decided_by' => null, 'decision_note' => null])->save();
        app(AuditLogger::class)->record('listings.mls.requested', metadata: ['mls' => $source->key], severity: 'notice');

        return $auth;
    }

    /**
     * @param  list<string>  $scopes
     */
    public function approve(MlsAuthorization $auth, array $scopes, ?string $expiresOn, ?string $agreementRef, User $by): void
    {
        $scopes = array_values(array_intersect($scopes, MlsAuthorization::SCOPES));
        if ($scopes === []) {
            throw ValidationException::withMessages(['scopes' => 'Choose what the licence covers.']);
        }
        if ($expiresOn !== null && $expiresOn !== '' && CarbonImmutable::parse($expiresOn)->isPast()) {
            throw ValidationException::withMessages(['expires_on' => 'The expiry date is in the past.']);
        }
        $auth->fill(['status' => 'active', 'scopes' => $scopes, 'expires_on' => $expiresOn ?: null, 'agreement_ref' => $agreementRef ?: $auth->agreement_ref,
            'decided_by' => $by->id, 'activated_at' => CarbonImmutable::now(), 'revoked_at' => null])->save();
        Cache::forget("mls-licences:{$auth->tenant_id}");
        app(AuditLogger::class)->record('listings.mls.approved', metadata: ['authorization' => $auth->ulid, 'scopes' => $scopes, 'expires_on' => $expiresOn], severity: 'critical', tenantId: $auth->tenant_id);
    }

    public function revoke(MlsAuthorization $auth, string $note, User $by): void
    {
        $auth->fill(['status' => 'revoked', 'revoked_at' => CarbonImmutable::now(), 'decided_by' => $by->id, 'decision_note' => mb_substr(trim($note), 0, 300) ?: null])->save();
        Cache::forget("mls-licences:{$auth->tenant_id}");
        app(AuditLogger::class)->record('listings.mls.revoked', metadata: ['authorization' => $auth->ulid], severity: 'critical', tenantId: $auth->tenant_id);
    }

    /** Daily: licences past their expiry stop. */
    public function expire(): int
    {
        $n = 0;
        foreach (MlsAuthorization::query()->where('status', 'active')->whereNotNull('expires_on')->whereDate('expires_on', '<', CarbonImmutable::today()->toDateString())->get() as $a) {
            $a->forceFill(['status' => 'expired'])->save();
            Cache::forget("mls-licences:{$a->tenant_id}");
            $n++;
        }

        return $n;
    }
}
