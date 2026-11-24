<?php

declare(strict_types=1);

namespace Modules\Roster\Brokerage;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Roster\Verification\ServiceVerifier;
use Throwable;

/**
 * LV2: the brokerage's own real estate licences, checked against the states' public records (Texas and Florida are
 * downloaded daily; other states are checked by hand by HQ).
 *
 * What a licence's state of play means:
 * - active (or HQ checked it, or "test" while payments are in test mode): MLS boards in that state can be added, and
 *   the website can be public.
 * - expired or inactive: allowed in, but no boards in that state, until it is renewed.
 * - suspended (or revoked): not accepted.
 * - not found: the number is checked again; a licence issued in the last few days can be marked "new" and HQ checks it.
 * - unchecked: the state has no public records here yet; boards are allowed while HQ checks it by hand.
 *
 * Owners are warned 60, 30 and 7 days before a licence expires, and when it lapses or the state's records change.
 * Nothing is switched off automatically when a licence lapses (that is a decision with legal weight): HQ sees it.
 */
final class BrokerageLicences
{
    /** Statuses that let the brokerage add boards in that state and have a public website. */
    public const GOOD = ['active', 'test', 'unchecked'];

    public const WARN_DAYS = [60, 30, 7, 0];

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * What the state's records say about a licence (no saving).
     *
     * @return array{status: string, expires_on: string|null, message: string|null, blocking: bool}
     */
    public static function assess(string $state, string $stateName, string $number, string $holder, bool $isNew = false, bool $testMode = false): array
    {
        $state = strtoupper($state);
        $number = mb_substr(trim($number), 0, 40);
        if ($testMode && mb_strtolower($number) === 'test') {
            return ['status' => 'test', 'expires_on' => null, 'message' => null, 'blocking' => false];
        }
        if (preg_match('/^[A-Za-z0-9 .\-]{3,40}$/', $number) !== 1) {
            return ['status' => 'invalid', 'expires_on' => null, 'message' => "Type your {$stateName} licence number as it appears on your licence.", 'blocking' => true];
        }
        try {
            $last = (string) collect(preg_split('/\s+/', trim($holder)) ?: [])->last();
            $found = app(ServiceVerifier::class)->lookup($state, $number, $last);
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'unchecked', 'expires_on' => null, 'message' => null, 'blocking' => false];
        }
        $expires = $found['expires_on'];

        return match ($found['status']) {
            'active' => ['status' => 'active', 'expires_on' => $expires, 'message' => null, 'blocking' => false],
            'unknown' => ['status' => 'unchecked', 'expires_on' => null, 'message' => null, 'blocking' => false],
            'not_found' => $isNew
                ? ['status' => 'new', 'expires_on' => null, 'message' => null, 'blocking' => false]
                : ['status' => 'not_found', 'expires_on' => null, 'blocking' => true,
                    'message' => "We could not find {$stateName} licence {$number} in the state's records. Check the number on your licence. If it was issued in the last few days, tick \"My licence is new\"."],
            'suspended' => ['status' => 'suspended', 'expires_on' => $expires, 'blocking' => true,
                'message' => "The state's records show {$stateName} licence {$number} as suspended, so we can't accept it. If this is wrong, please contact us."],
            default => ['status' => (string) $found['status'] === 'expired' ? 'expired' : 'inactive', 'expires_on' => $expires, 'blocking' => false,
                'message' => "Your {$stateName} licence shows as ".((string) $found['status'] === 'expired' ? 'expired' : 'inactive')." in the state's records. You can still sign up: your website starts as a landing page without listings in {$stateName}. Renew it, then add your board in Settings › MLS."],
        };
    }

    /** @return list<array{id: int, state: string, number: string, holder: string|null, status: string, expires_on: string|null, checked_at: string|null, hq_checked: bool, good: bool}> */
    public function all(): array
    {
        return array_values($this->q()->orderBy('state')->get()->map(fn (object $r): array => [
            'id' => (int) $r->id, 'state' => (string) $r->state, 'number' => (string) $r->number, 'holder' => $r->holder !== null ? (string) $r->holder : null,
            'status' => (string) $r->status, 'expires_on' => $r->expires_on !== null ? substr((string) $r->expires_on, 0, 10) : null,
            'checked_at' => $r->checked_at !== null ? (string) $r->checked_at : null, 'hq_checked' => $r->hq_verified_at !== null, 'good' => self::isGood($r),
        ])->all());
    }

    /** Whether this brokerage keeps its licences here (brokerages from before sign-up asked for them do not, yet). */
    public function kept(): bool
    {
        return $this->q()->exists();
    }

    /** @return list<string> states where the brokerage has a good licence */
    public function goodStates(): array
    {
        return array_values(array_unique($this->q()->get()->filter(fn (object $r): bool => self::isGood($r))->pluck('state')->map(fn ($s): string => (string) $s)->all()));
    }

    /** Whether the website may be public: a brokerage that keeps licences here needs one good licence. */
    public function mayPublish(): bool
    {
        return ! $this->kept() || $this->goodStates() !== [];
    }

    /** Adds (or replaces) a licence after checking it. */
    public function add(string $state, string $stateName, string $number, string $holder, bool $isNew, bool $testMode, ?User $by): string
    {
        $found = self::assess($state, $stateName, $number, $holder, $isNew, $testMode);
        if ($found['blocking']) {
            throw ValidationException::withMessages(['number' => (string) $found['message']]);
        }
        $this->save(strtoupper($state), trim($number), $holder, $found['status'], $found['expires_on']);
        app(AuditLogger::class)->record('roster.brokerage_licence.added', metadata: ['state' => strtoupper($state), 'status' => $found['status'], 'by' => $by?->id], severity: 'notice');

        return $found['status'];
    }

    /** Saves a licence (sign-up uses this with what it already checked). */
    public function save(string $state, string $number, ?string $holder, string $status, ?string $expires): void
    {
        DB::connection(Connections::cell())->table('brokerage_licences')->updateOrInsert(['tenant_id' => $this->tenants->id(), 'state' => strtoupper($state), 'number' => mb_substr($number, 0, 40)],
            ['holder' => $holder !== null ? mb_substr($holder, 0, 160) : null, 'status' => $status, 'expires_on' => $expires, 'checked_at' => now(), 'warned_days' => null,
                'updated_at' => now(), 'created_at' => now()]);
    }

    public function remove(int $id, User $by): void
    {
        $row = $this->q()->where('id', $id)->first() ?? throw ValidationException::withMessages(['licence' => 'That licence is not here any more.']);
        $this->q()->where('id', $id)->delete();
        app(AuditLogger::class)->record('roster.brokerage_licence.removed', metadata: ['state' => $row->state, 'by' => $by->id], severity: 'warning');
    }

    /** HQ checked a licence by hand with the state (states without public records, new licences, name differences). */
    public function hqVerify(int $id, User $by): void
    {
        $this->q()->where('id', $id)->update(['hq_verified_at' => now(), 'hq_verified_by' => $by->id, 'updated_at' => now()]);
        app(AuditLogger::class)->record('roster.brokerage_licence.hq_checked', metadata: ['licence' => $id, 'by' => $by->id], severity: 'notice');
    }

    /**
     * Daily, for the current brokerage: checks each licence against the latest state records again, and warns the
     * owners before one expires or when the records change. Returns how many owners' messages were sent.
     */
    public function recheck(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $sent = 0;
        foreach ($this->q()->get() as $r) {
            $status = (string) $r->status;
            $expires = $r->expires_on !== null ? substr((string) $r->expires_on, 0, 10) : null;
            if (in_array($status, ['active', 'expired', 'inactive', 'suspended', 'unchecked', 'new'], true)) {
                $found = self::assess((string) $r->state, (string) $r->state, (string) $r->number, (string) ($r->holder ?? ''), $status === 'new');
                $now = in_array($found['status'], ['invalid', 'not_found'], true) && $status === 'new' ? 'new' : $found['status'];
                if ($now !== $status && $now !== 'invalid') {
                    $this->q()->where('id', $r->id)->update(['status' => $now, 'expires_on' => $found['expires_on'] ?? $expires, 'checked_at' => now(), 'updated_at' => now()]);
                    if (in_array($now, ['expired', 'inactive', 'suspended', 'not_found'], true)) {
                        $sent += $this->tell($r->state.' licence '.$r->number." now shows as {$now} in the state's records.", 'Renew it or contact the state. Listings from MLS boards in that state may need to stop until it is active again.');
                        app(AuditLogger::class)->record('roster.brokerage_licence.lapsed', metadata: ['state' => $r->state, 'status' => $now], severity: 'warning');
                    }
                    $status = $now;
                    $expires = $found['expires_on'] ?? $expires;
                } else {
                    $this->q()->where('id', $r->id)->update(['checked_at' => now(), 'expires_on' => $found['expires_on'] ?? $expires]);
                    $expires = $found['expires_on'] ?? $expires;
                }
            }
            if ($expires === null || $status !== 'active') {
                continue;
            }
            $days = (int) $today->diffInDays(CarbonImmutable::parse($expires), false);
            $step = null;
            foreach (self::WARN_DAYS as $s) {
                if ($days <= $s) {
                    $step = $s;
                }
            }
            if ($step !== null && ($r->warned_days === null || (int) $r->warned_days > $step)) {
                $sent += $this->tell($step === 0 ? "{$r->state} licence {$r->number} expires today." : "{$r->state} licence {$r->number} expires in {$days} days, on {$expires}.",
                    'Renew it with the state in good time: listings from MLS boards in that state depend on it.');
                $this->q()->where('id', $r->id)->update(['warned_days' => $step]);
            }
        }

        return $sent;
    }

    private function tell(string $what, string $next): int
    {
        $owners = User::query()->where('kind', UserKind::Owner)->where('status', UserStatus::Active)->get();
        if ($owners->isNotEmpty()) {
            Notification::send($owners, new LicenceNotice($what, $next));
        }

        return $owners->count();
    }

    private static function isGood(object $r): bool
    {
        $status = (string) ($r->status ?? '');

        return in_array($status, self::GOOD, true) || (($r->hq_verified_at ?? null) !== null && ! in_array($status, ['suspended', 'expired', 'inactive'], true));
    }

    /** This brokerage's licences only. */
    private function q(): Builder
    {
        return DB::connection(Connections::cell())->table('brokerage_licences')->where('tenant_id', $this->tenants->id());
    }
}
