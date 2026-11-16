<?php

declare(strict_types=1);

namespace Modules\Movewise\Outcomes;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Credits\CreditLedger;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections as ControlDb;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Movewise\Models\Agent;
use Modules\Movewise\Models\Connection;
use Modules\Movewise\Models\Package;
use Modules\Movewise\Models\Partner;
use Modules\Movewise\Models\Person;
use Modules\Movewise\Models\State;
use Modules\Movewise\Notifications\IntroductionClosed;
use Modules\Movewise\Program\Pricing;

/**
 * After the introduction (specs/movewise/00 §6.3, §9, 06).
 *
 * - The agent keeps the status up to date: contacted, appointment set, met, agreement signed, under contract, closed,
 *   or lost. Fourteen days without an update brings a reminder; seven more pause the agent's packages.
 * - Per appointment: charged when the agent says the appointment was held and the person confirms it.
 * - Share at close: the referral agreement is signed at accept; closing raises a referral invoice from the state's
 *   managing broker partner to the agent's brokerage. Thirty days without an update falls back to the
 *   per-connection price, as the terms say.
 * - The person rates the agent a week in, and again after closing.
 */
final class Outcomes
{
    public const STAGES = ['contacted' => 'Talked with them', 'appointment' => 'Appointment set', 'met' => 'Appointment held', 'agreement' => 'Agreement signed',
        'contract' => 'Under contract', 'closed' => 'Closed', 'lost' => 'Not going ahead'];

    public const REMIND_DAYS = 14;

    public const PAUSE_AFTER_REMINDER_DAYS = 7;

    public const FALLBACK_DAYS = 30;

    public function __construct(private readonly TenantContext $tenants) {}

    /** The referral agreement the agent signs at accept, word for word. */
    public static function referralAgreement(Agent $agent, Partner $partner, float $pct): string
    {
        return 'Referral agreement. '.$agent->brokerage_name.' (for '.((string) ($agent->profile['display_name'] ?? 'the agent')).', licence '.$agent->licence_number.') agrees to pay '
            .$partner->name.' (licence '.$partner->licence_number.') a referral fee of '.rtrim(rtrim(number_format($pct, 2), '0'), '.').'% of the gross commission earned on the side '
            .'this introduction represents, if a transaction closes within 12 months of this introduction. Payable broker to broker within 30 days of closing. '
            .'If no status update is given for '.self::FALLBACK_DAYS.' days, the per-connection price applies instead.';
    }

    /** @param  array<string, mixed>  $in */
    public function update(Connection $c, User $by, array $in): Connection
    {
        $stage = (string) ($in['stage'] ?? '');
        if (! array_key_exists($stage, self::STAGES)) {
            throw ValidationException::withMessages(['stage' => 'Choose where things stand.']);
        }
        if ($c->status !== 'accepted' || in_array($c->stage, ['closed', 'lost'], true)) {
            throw ValidationException::withMessages(['stage' => 'This introduction is no longer open.']);
        }
        $order = array_keys(self::STAGES);
        if ($c->stage !== null && $stage !== 'lost' && array_search($stage, $order, true) < array_search($c->stage, $order, true)) {
            throw ValidationException::withMessages(['stage' => 'Status moves forward only. Choose "Not going ahead" if it ended.']);
        }
        $price = $commission = null;
        $date = null;
        if ($stage === 'closed') {
            $price = (int) round(((float) preg_replace('/[^\d.]/', '', (string) ($in['close_price'] ?? '0'))) * 100);
            $commission = (int) round(((float) preg_replace('/[^\d.]/', '', (string) ($in['commission'] ?? '0'))) * 100);
            $date = is_string($in['close_date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['close_date']) === 1 ? (string) $in['close_date'] : null;
            if ($price <= 0 || $commission <= 0 || $date === null) {
                throw ValidationException::withMessages(['close_price' => 'Give the sale price, your side\'s gross commission and the closing date.']);
            }
        }
        $now = CarbonImmutable::now();
        DB::connection(ControlDb::control())->table('movewise_status_events')->insert(['connection_id' => $c->id, 'stage' => $stage,
            'note' => mb_substr(trim(strip_tags((string) ($in['note'] ?? ''))), 0, 500) ?: null, 'close_price_cents' => $price, 'commission_cents' => $commission, 'close_date' => $date,
            'by_user_id' => $by->id, 'created_at' => $now]);
        $c->forceFill(['stage' => $stage, 'stage_at' => $now, 'reminded_at' => null] + ($stage === 'met' ? ['met_agent_at' => $now] : []))->save();
        if ($stage === 'met') {
            $this->chargeAppointment($c);
        }
        if ($stage === 'closed' && $c->model === 'share') {
            app(Invoices::class)->raise($c, $commission);
        }
        app(AuditLogger::class)->record('movewise.connection.stage', $c, metadata: ['stage' => $stage], tenantId: $c->tenant_id);

        return $c;
    }

    /**
     * The status from what the agent did in RightAlly (AutoStatus): forward only, never over a closed or lost one, and
     * recorded with where it came from. Closing uses the deal's own figures.
     *
     * @param  array{close_price_cents?: int, commission_cents?: int, close_date?: string}  $close
     */
    public function advance(Connection $c, string $stage, string $from, array $close): void
    {
        $order = array_keys(self::STAGES);
        if ($c->status !== 'accepted' || in_array($c->stage, ['closed', 'lost'], true)
            || ($c->stage !== null && $stage !== 'lost' && array_search($stage, $order, true) <= array_search($c->stage, $order, true))) {
            return;
        }
        if ($stage === 'closed' && (($close['commission_cents'] ?? 0) <= 0 || ($close['close_price_cents'] ?? 0) <= 0)) {
            $stage = 'contract'; // closed without figures: the agent confirms them by hand
            if ($c->stage === 'contract') {
                return;
            }
        }
        $now = CarbonImmutable::now();
        DB::connection(ControlDb::control())->table('movewise_status_events')->insert(['connection_id' => $c->id, 'stage' => $stage,
            'note' => 'Automatically, from '.$from, 'close_price_cents' => $stage === 'closed' ? $close['close_price_cents'] : null,
            'commission_cents' => $stage === 'closed' ? $close['commission_cents'] : null, 'close_date' => $stage === 'closed' ? $close['close_date'] ?? null : null,
            'by_user_id' => null, 'created_at' => $now]);
        $c->forceFill(['stage' => $stage, 'stage_at' => $now, 'reminded_at' => null] + ($stage === 'met' ? ['met_agent_at' => $now] : []))->save();
        if ($stage === 'met') {
            $this->chargeAppointment($c);
        }
        if ($stage === 'closed' && $c->model === 'share') {
            app(Invoices::class)->raise($c, (int) $close['commission_cents']);
        }
        app(AuditLogger::class)->record('movewise.connection.stage', $c, metadata: ['stage' => $stage, 'from' => $from], tenantId: $c->tenant_id);
    }

    /** The person confirms (or not) that they met the agent. */
    public function confirmMet(Connection $c, Person $p, bool $yes): Connection
    {
        abort_unless($c->person_id === $p->id, 404);
        if ($c->status !== 'accepted') {
            throw ValidationException::withMessages(['met' => 'This introduction has ended.']);
        }
        if ($yes) {
            $c->forceFill(['met_person_at' => CarbonImmutable::now()])->save();
            $this->chargeAppointment($c);
        }

        return $c;
    }

    public function rate(Connection $c, Person $p, int $stars, string $note, bool $public = false): Connection
    {
        abort_unless($c->person_id === $p->id, 404);
        if ($c->accepted_at === null || ($c->accepted_at->gt(CarbonImmutable::now()->subDays(7)) && $c->stage !== 'closed')) {
            throw ValidationException::withMessages(['rating' => 'You can rate your agent a week after you were introduced.']);
        }
        if ($stars < 1 || $stars > 5) {
            throw ValidationException::withMessages(['rating' => 'Choose 1 to 5 stars.']);
        }
        // Phone numbers and emails are kept out of what might be published.
        $note = (string) preg_replace(['/(?<!\d)(\+?1[\s.-]?)?\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}/', '/[^\s@]+@[^\s@]+\.[^\s@]+/'], '[removed]', trim(strip_tags($note)));
        $c->forceFill(['rating' => $stars, 'rating_note' => mb_substr($note, 0, 500) ?: null, 'review_public' => $public, 'reviewed_at' => CarbonImmutable::now()])->save();

        return $c;
    }

    /**
     * Daily: reminders, then a pause, for introductions with no status update; the share-at-close fallback.
     *
     * @return array{reminded: int, paused: int, fallback: int}
     */
    public function sweep(): array
    {
        $now = CarbonImmutable::now();
        $out = ['reminded' => 0, 'paused' => 0, 'fallback' => 0];
        foreach (Connection::query()->where('status', 'accepted')->where(fn ($q) => $q->whereNull('stage')->orWhereNotIn('stage', ['closed', 'lost']))->orderBy('id')->limit(2000)->get() as $c) {
            $last = $c->stage_at ?? $c->accepted_at ?? $c->offered_at;
            if ($c->model === 'share' && $last->lt($now->subDays(self::FALLBACK_DAYS))) {
                $this->fallBack($c);
                $out['fallback']++;
            }
            if ($last->gt($now->subDays(self::REMIND_DAYS))) {
                continue;
            }
            if ($c->reminded_at === null) {
                $c->forceFill(['reminded_at' => $now])->save();
                $this->tell($c, 'Update your Movewise client\'s status', 'It\'s been '.self::REMIND_DAYS.' days since the last update on a Movewise introduction. '
                    .'Please say where things stand. Without an update in '.self::PAUSE_AFTER_REMINDER_DAYS.' days your Movewise packages pause.');
                $out['reminded']++;
            } elseif ($c->reminded_at->lt($now->subDays(self::PAUSE_AFTER_REMINDER_DAYS))) {
                $n = Package::query()->whereIn('id', [$c->package_id])->where('status', 'active')->update(['status' => 'paused', 'pause_reason' => 'No status update on a Movewise introduction']);
                if ($n > 0) {
                    $this->tell($c, 'Your Movewise package is paused', 'A Movewise introduction has had no status update. Update it, then switch your package back on.');
                    app(AuditLogger::class)->record('movewise.package.paused_no_status', $c, tenantId: $c->tenant_id);
                    $out['paused']++;
                }
            }
        }

        return $out;
    }

    private function chargeAppointment(Connection $c): void
    {
        if ($c->model !== 'appointment' || $c->met_agent_at === null || $c->met_person_at === null || $c->price_credits > 0) {
            return;
        }
        $agent = Agent::query()->findOrFail($c->agent_id);
        $price = Pricing::upfront(State::query()->findOrFail($agent->state), 'appointment', $c->lead_type);
        $package = Package::query()->findOrFail($c->package_id);
        $this->inTenant($c, fn () => app(CreditLedger::class)->charge('movewise.connection', $package->payer === 'agent' ? $package->payer_user_id : null, 'movewise-appointment:'.$c->ulid,
            $price, true));
        $c->forceFill(['price_credits' => $price])->save();
        app(AuditLogger::class)->record('movewise.connection.appointment_charged', $c, metadata: ['credits' => $price], tenantId: $c->tenant_id);
    }

    /** Share at close with no update for 30 days: the per-connection price applies, as the agreement says. */
    private function fallBack(Connection $c): void
    {
        $agent = Agent::query()->findOrFail($c->agent_id);
        $price = Pricing::upfront(State::query()->findOrFail($agent->state), 'connection', $c->lead_type);
        $package = Package::query()->findOrFail($c->package_id);
        if ($price > 0) {
            $this->inTenant($c, fn () => app(CreditLedger::class)->charge('movewise.connection', $package->payer === 'agent' ? $package->payer_user_id : null, $c->chargeKey(), $price, true));
        }
        $c->forceFill(['model' => 'connection', 'price_credits' => $price])->save();
        $this->tell($c, 'A Movewise introduction moved to the per-connection price', 'There was no status update for '.self::FALLBACK_DAYS
            .' days, so the per-connection price ('.$price.' credits) applies instead of the referral share, as the agreement says.');
        app(AuditLogger::class)->record('movewise.connection.share_fallback', $c, metadata: ['credits' => $price], tenantId: $c->tenant_id);
    }

    /** Tells the agent (and, for a broker package, the brokerage's owners). */
    private function tell(Connection $c, string $subject, string $body): void
    {
        $tenant = Tenant::query()->find($c->tenant_id);
        $agent = Agent::query()->find($c->agent_id);
        if ($tenant === null || $agent === null) {
            return;
        }
        $host = $tenant->primaryDomain()?->host;
        $link = ($host !== null ? 'https://'.$host : rtrim((string) config('app.url'), '/')).'/movewise/introductions/'.$c->ulid;
        $this->inTenant($c, function () use ($agent, $subject, $body, $link, $c): void {
            $to = User::query()->whereKey($agent->user_id)->get();
            if (Package::query()->whereKey($c->package_id)->value('payer') === 'brokerage') {
                $to = $to->merge(User::query()->where('kind', UserKind::Owner)->get());
            }
            foreach ($to->unique('id') as $u) {
                $u->notify(new IntroductionClosed($subject, $body, $link));
            }
        });
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $fn
     * @return T
     */
    private function inTenant(Connection $c, \Closure $fn): mixed
    {
        if ($this->tenants->check() && $this->tenants->id() === $c->tenant_id) {
            return $fn();
        }

        return $this->tenants->runAs(Tenant::query()->findOrFail($c->tenant_id), $fn);
    }
}
