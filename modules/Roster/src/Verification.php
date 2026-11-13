<?php

declare(strict_types=1);

namespace Modules\Roster;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Support\Connections;
use Modules\Roster\Verification\LicenceVerifier;

/**
 * Automatic licence verification (specs/roster/01, addition 2): checks a licence against state records
 * through a verifier (ARELLO once the data agreement is signed), on demand and monthly, so a suspension or
 * lapse is caught. A licence that is not active, not found or does not match is flagged and owners are told.
 */
final class Verification
{
    public const RECHECK_DAYS = 30;

    public function __construct(private readonly LicenceVerifier $verifier) {}

    public function available(): bool
    {
        return $this->verifier->configured();
    }

    public function check(string $credentialUlid, ?User $by = null): string
    {
        if (! $this->verifier->configured()) {
            throw ValidationException::withMessages(['verify' => 'Automatic verification is not switched on yet; verify by hand for now.']);
        }
        $db = DB::connection(Connections::cell());
        $c = $db->table('roster_credentials')->where('ulid', $credentialUlid)->where('kind', 'licence')->where('status', 'current')->first();
        if ($c === null) {
            throw ValidationException::withMessages(['verify' => 'Only a current licence can be checked.']);
        }
        $agent = User::query()->findOrFail((int) $c->user_id);
        $r = $this->verifier->lookup((string) $c->state_code, (string) $c->number, $agent->last_name ?? $agent->name);
        $result = match (true) {
            $r['status'] === 'not_found' => 'not_found',
            $r['status'] !== 'active' => 'inactive',
            $r['last_name'] !== null && mb_strtolower((string) $r['last_name']) !== mb_strtolower((string) $agent->last_name) => 'mismatch',
            default => 'active',
        };
        $update = ['last_checked_at' => now(), 'check_result' => $result, 'updated_at' => now()];
        if ($result === 'active') {
            $update += ['verified_at' => now(), 'verified_by' => $by?->id, 'verification_method' => 'arello',
                'verification_note' => 'Active per state records'.($r['expires_on'] !== null ? ' until '.$r['expires_on'] : '').' (automatic check)'];
            if ($r['expires_on'] !== null && $r['expires_on'] !== $c->expires_on) {
                $update['expires_on'] = $r['expires_on'];
            }
        } else {
            $update += ['verified_at' => null, 'verification_method' => null, 'verification_note' => match ($result) {
                'not_found' => 'Not found in state records (automatic check)', 'mismatch' => 'Licence number belongs to a different name (automatic check)',
                default => 'State records show the licence as '.$r['status'].' (automatic check)'
            }];
            Notification::send(User::query()->where('kind', UserKind::Owner)->where('status', UserStatus::Active)->get(),
                new LicenceProblem($agent->name, (string) $c->state_code, (string) $update['verification_note']));
        }
        $db->table('roster_credentials')->where('id', $c->id)->update($update);
        app(AuditLogger::class)->record('roster.credential.checked', $agent, metadata: ['state' => $c->state_code, 'result' => $result],
            severity: $result === 'active' ? 'info' : 'warning', label: $agent->name);

        return $result;
    }

    /** Monthly re-check of every current licence (catches suspensions and lapses between renewals). */
    public function recheckDue(): int
    {
        if (! $this->verifier->configured()) {
            return 0;
        }
        $checked = 0;
        foreach (DB::connection(Connections::cell())->table('roster_credentials')->where('kind', 'licence')->where('status', 'current')
            ->where(fn ($q) => $q->whereNull('last_checked_at')->orWhere('last_checked_at', '<', CarbonImmutable::now()->subDays(self::RECHECK_DAYS)))->pluck('ulid') as $ulid) {
            $this->check((string) $ulid);
            $checked++;
        }

        return $checked;
    }
}
