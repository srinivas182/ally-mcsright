<?php

declare(strict_types=1);

namespace Modules\Connect;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\AI\Exceptions\InsufficientCredits;
use Modules\Billing\Credits\CreditLedger;
use Modules\Billing\Credits\RateCard;
use Modules\Connect\Models\AppAddon;
use Modules\Connect\Models\Connection;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Connect\App;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\TenantContext;

/**
 * Paid apps (specs/connect/14, Sprint C6b). HQ sets each app free for everyone, or a monthly add-on for the tenant,
 * with the packages that include it free. Apps RightAlly pays a vendor for (Xero, for example) never cost
 * RightAlly money: no add-on, no connection.
 *
 * - Who pays: whoever the connection belongs to. A brokerage connection is paid by the brokerage (from its credit
 *   pool, bought by the owner); an agent's own connection by that agent (from their credits).
 * - Charged in RightAlly credits, a month at a time, at the price in force when each month starts (HQ price changes
 *   apply from the next month). Credits are the dollar price at the credit value of the day.
 * - Not paid at renewal (no credits): the add-on lapses and the app's connections for that payer pause; nothing
 *   already synced is touched. Adding it again resumes them. Cancelling keeps it to the end of the paid month.
 */
final class PaidApps
{
    public function __construct(private readonly AppRegistry $registry, private readonly TenantContext $tenants) {}

    /**
     * What this person sees about the app's price.
     *
     * @return array{free: bool, cents: int|null, included: bool, active: bool, payer: 'brokerage'|'you'|null, period_end: string|null, cancel_at: string|null, can_buy: bool}
     */
    public function state(App $app, User $user, int|false|null $owner): array
    {
        $price = $this->registry->price($app);
        if ($price === null || $owner === false) {
            return ['free' => $price === null, 'cents' => $price['cents'] ?? null, 'included' => false, 'active' => $price === null, 'payer' => null,
                'period_end' => null, 'cancel_at' => null, 'can_buy' => false];
        }
        $addon = $this->addon($app, $owner);
        $included = $this->included($price);

        return ['free' => false, 'cents' => $price['cents'], 'included' => $included, 'active' => $included || ($addon?->paidUp() ?? false),
            'payer' => $owner === null ? 'brokerage' : 'you', 'period_end' => $addon?->period_end->toIso8601String(), 'cancel_at' => $addon?->cancel_at?->toIso8601String(),
            'can_buy' => $owner !== null || $user->kind === UserKind::Owner];
    }

    /** May this payer connect the app now? Free, included in the package, or a paid-up add-on. */
    public function allowed(App $app, ?int $owner): bool
    {
        $price = $this->registry->price($app);

        return $price === null || $this->included($price) || ($this->addon($app, $owner)?->paidUp() ?? false);
    }

    /** Stops a connection that isn't paid for, with what to do. */
    public function require(App $app, ?int $owner): void
    {
        $price = $this->registry->price($app);
        if ($price !== null && ! $this->allowed($app, $owner)) {
            throw ValidationException::withMessages(['app' => $app->name.' is an add-on: $'.number_format($price['cents'] / 100, 2).' a month. Add it first, then connect.']);
        }
    }

    /** Buys (or resumes) the add-on: charges the first month now. */
    public function buy(App $app, User $by, ?int $owner): void
    {
        $price = $this->registry->price($app) ?? throw ValidationException::withMessages(['addon' => $app->name.' is free: just connect it.']);
        if ($owner === null && $by->kind !== UserKind::Owner) {
            throw ValidationException::withMessages(['addon' => 'Only the brokerage owner can add '.$app->name.' for the brokerage.']);
        }
        $addon = $this->addon($app, $owner);
        if ($addon !== null && $addon->paidUp()) {
            if ($addon->cancel_at !== null) { // cancelled but still in its month: simply keep it
                $addon->update(['cancel_at' => null]);
            }

            return;
        }
        $start = CarbonImmutable::now();
        $credits = $this->credits($price['cents']);
        $addon ??= new AppAddon(['app_key' => $app->key, 'payer_user_id' => $owner, 'created_by' => $by->id]);
        if (! $addon->exists) {
            $addon->forceFill(['ulid' => (string) Str::ulid()]);
        }
        try {
            $this->charge($owner, $credits, 'app-addon:'.$addon->ulid.':'.$start->format('Y-m-d-His'));
        } catch (InsufficientCredits) {
            throw ValidationException::withMessages(['addon' => 'Not enough RightAlly credits for the '.$app->name.' add-on. Buy credits under Billing, then add it.']);
        }
        $addon->fill(['status' => 'active', 'price_cents' => $price['cents'], 'credits' => $credits, 'period_start' => $start, 'period_end' => $start->addMonthNoOverflow(),
            'cancel_at' => null])->save();
        $this->resume($app, $owner);
        app(AuditLogger::class)->record('connect.addon.bought', metadata: ['app' => $app->key, 'for' => $owner === null ? 'brokerage' : 'agent', 'price_cents' => $price['cents'],
            'credits' => $credits], severity: 'notice');
    }

    /** Cancels at the end of the paid month. */
    public function cancel(App $app, User $by, ?int $owner): void
    {
        if ($owner === null && $by->kind !== UserKind::Owner) {
            throw ValidationException::withMessages(['addon' => 'Only the brokerage owner can cancel '.$app->name.'.']);
        }
        $addon = $this->addon($app, $owner);
        if ($addon !== null && $addon->status === 'active') {
            $addon->update(['cancel_at' => $addon->period_end]);
            app(AuditLogger::class)->record('connect.addon.cancelled', metadata: ['app' => $app->key, 'until' => $addon->period_end->toDateString()], severity: 'notice');
        }
    }

    /**
     * Daily, for the current tenant: renews add-ons whose month is over, ends cancelled ones, and pauses the
     * connections of add-ons that can't be paid.
     *
     * @return array{renewed: int, ended: int, lapsed: int}
     */
    public function renewDue(callable $findApp): array
    {
        $out = ['renewed' => 0, 'ended' => 0, 'lapsed' => 0];
        foreach (AppAddon::query()->where('status', 'active')->where('period_end', '<=', now())->orderBy('id')->get() as $addon) {
            $app = $findApp($addon->app_key);
            $owner = $addon->payer_user_id;
            $price = $app instanceof App ? $this->registry->price($app) : null;
            if (! $app instanceof App || $price === null || $addon->cancel_at !== null || $this->included($price)) {
                // Cancelled, made free, or now included in the package: the add-on simply ends.
                $addon->update(['status' => 'cancelled']);
                if ($app instanceof App && $price !== null && ! $this->included($price)) {
                    $this->pause($app, $owner, 'The '.$app->name.' add-on was cancelled.');
                }
                $out['ended']++;

                continue;
            }
            $start = $addon->period_end;
            $credits = $this->credits($price['cents']);
            try {
                $this->charge($owner, $credits, 'app-addon:'.$addon->ulid.':'.$start->format('Y-m-d-His'));
            } catch (InsufficientCredits) {
                $addon->update(['status' => 'lapsed']);
                $this->pause($app, $owner, 'Not enough RightAlly credits to renew the '.$app->name.' add-on. Top up and add it again; nothing already synced is lost.');
                app(AuditLogger::class)->record('connect.addon.lapsed', metadata: ['app' => $app->key], severity: 'warning');
                $out['lapsed']++;

                continue;
            }
            $addon->update(['price_cents' => $price['cents'], 'credits' => $credits, 'period_start' => $start, 'period_end' => $start->addMonthNoOverflow()]);
            $out['renewed']++;
        }

        return $out;
    }

    /** @param  array{cents: int, packages: list<int>}  $price */
    private function included(array $price): bool
    {
        $package = $this->tenants->check() ? $this->tenants->current()->getAttribute('billing_package_id') : null;

        return $package !== null && in_array((int) $package, $price['packages'], true);
    }

    private function credits(int $cents): int
    {
        return (int) ceil($cents * 1_000_000 / max(1, app(RateCard::class)->creditValueMicrocents()));
    }

    /** @throws InsufficientCredits */
    private function charge(?int $owner, int $credits, string $key): void
    {
        app(CreditLedger::class)->charge('apps.addon', $owner, $key, $credits);
    }

    private function addon(App $app, ?int $owner): ?AppAddon
    {
        return AppAddon::query()->where('app_key', $app->key)
            ->where(fn ($q) => $owner === null ? $q->whereNull('payer_user_id') : $q->where('payer_user_id', $owner))->first();
    }

    private function pause(App $app, ?int $owner, string $why): void
    {
        foreach (Connection::query()->where('app_key', $app->key)->where(fn ($q) => $owner === null ? $q->whereNull('owner_user_id') : $q->where('owner_user_id', $owner))
            ->whereIn('status', ['connected', 'error'])->get() as $c) {
            $c->forceFill(['status' => 'paused', 'last_error' => $why, 'settings' => array_merge($c->settings ?? [], ['paused_for_payment' => true])])->save();
        }
    }

    private function resume(App $app, ?int $owner): void
    {
        foreach (Connection::query()->where('app_key', $app->key)->where(fn ($q) => $owner === null ? $q->whereNull('owner_user_id') : $q->where('owner_user_id', $owner))
            ->where('status', 'paused')->get() as $c) {
            if (($c->settings['paused_for_payment'] ?? false) === true) {
                $settings = $c->settings ?? [];
                unset($settings['paused_for_payment']);
                $c->forceFill(['status' => 'connected', 'last_error' => null, 'settings' => $settings])->save();
            }
        }
    }
}
