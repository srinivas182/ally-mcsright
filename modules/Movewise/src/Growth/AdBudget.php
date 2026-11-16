<?php

declare(strict_types=1);

namespace Modules\Movewise\Growth;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections as ControlDb;
use Modules\Core\Tenancy\TenantContext;
use Modules\Movewise\Models\Connection;
use Modules\Movewise\Movewise;
use Modules\Movewise\Notifications\IntroductionClosed;

/**
 * Movewise's own ads (specs/movewise/00 §11, 07): HQ sets a monthly budget, a weekly top-up, a target cost per accepted
 * introduction and a stop-loss. Spend is recorded per day and channel. Each day the last 7 days are checked: above
 * the stop-loss (or over the month's budget) the weekly top-up is held and HQ is told, until the numbers come back under. Movewise
 * doesn't move ad money itself; the hold tells HQ not to top up the ad accounts.
 */
final class AdBudget
{
    /** @return array<string, mixed> */
    public function settings(): array
    {
        $row = DB::connection(ControlDb::control())->table('movewise_ad_settings')->orderBy('id')->first();

        return ['monthly_budget_cents' => (int) ($row->monthly_budget_cents ?? 0), 'weekly_topup_cents' => (int) ($row->weekly_topup_cents ?? 0),
            'target_cents' => (int) ($row->target_cents ?? 0), 'stop_loss_cents' => (int) ($row->stop_loss_cents ?? 0), 'status' => (string) ($row->status ?? 'ok'),
            'reason' => $row->reason ?? null, 'checked_at' => $row->checked_at ?? null];
    }

    /** @param  array<string, mixed>  $in */
    public function save(array $in, User $by): void
    {
        $cents = fn (string $k): int => max(0, (int) round(((float) preg_replace('/[^\d.]/', '', (string) ($in[$k] ?? '0'))) * 100));
        $values = ['monthly_budget_cents' => $cents('monthly_budget'), 'weekly_topup_cents' => $cents('weekly_topup'), 'target_cents' => $cents('target'),
            'stop_loss_cents' => $cents('stop_loss'), 'updated_at' => now()];
        if ($values['stop_loss_cents'] > 0 && $values['stop_loss_cents'] < $values['target_cents']) {
            throw ValidationException::withMessages(['stop_loss' => 'The stop-loss must be at or above the target.']);
        }
        $db = DB::connection(ControlDb::control())->table('movewise_ad_settings');
        $db->exists() ? $db->update($values) : $db->insert($values + ['created_at' => now()]);
        app(AuditLogger::class)->record('movewise.ads.settings', metadata: $values + ['by' => $by->id]);
    }

    /** @param  array<string, mixed>  $in */
    public function record(array $in, User $by): void
    {
        $day = (string) ($in['day'] ?? '');
        $channel = in_array($in['channel'] ?? null, ['meta', 'google', 'other'], true) ? (string) $in['channel'] : null;
        $state = strtoupper((string) ($in['state'] ?? '')) ?: null;
        $spend = (int) round(((float) preg_replace('/[^\d.]/', '', (string) ($in['spend'] ?? '0'))) * 100);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1 || $channel === null || $spend <= 0 || ($state !== null && ! array_key_exists($state, (array) config('movewise.launch_states')))) {
            throw ValidationException::withMessages(['spend' => 'Give the day, channel and amount spent.']);
        }
        DB::connection(ControlDb::control())->table('movewise_ad_spend')->updateOrInsert(['day' => $day, 'channel' => $channel, 'state' => $state],
            ['spend_cents' => $spend, 'note' => mb_substr(trim((string) ($in['note'] ?? '')), 0, 200) ?: null, 'by_user_id' => $by->id, 'updated_at' => now(), 'created_at' => now()]);
    }

    /** @return array{spend_7d: int, accepted_7d: int, cost_7d: int|null, spend_month: int, accepted_month: int, cost_month: int|null} */
    public function summary(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $spend = fn (CarbonImmutable $from): int => (int) DB::connection(ControlDb::control())->table('movewise_ad_spend')->where('day', '>=', $from->toDateString())->sum('spend_cents');
        $accepted = fn (CarbonImmutable $from): int => Connection::query()->where('accepted_at', '>=', $from)->count();
        $week = $now->subDays(7);
        $month = $now->startOfMonth();

        return ['spend_7d' => $spend($week), 'accepted_7d' => $accepted($week), 'cost_7d' => $accepted($week) > 0 ? intdiv($spend($week), $accepted($week)) : null,
            'spend_month' => $spend($month), 'accepted_month' => $accepted($month), 'cost_month' => $accepted($month) > 0 ? intdiv($spend($month), $accepted($month)) : null];
    }

    /** Daily: hold the top-up when the last 7 days cost more per accepted introduction than the stop-loss, or the month is spent. */
    public function check(): string
    {
        $s = $this->settings();
        $sum = $this->summary();
        $reason = null;
        if ($s['stop_loss_cents'] > 0 && $sum['spend_7d'] > 0 && ($sum['cost_7d'] === null || $sum['cost_7d'] > $s['stop_loss_cents'])) {
            $reason = 'The last 7 days cost '.($sum['cost_7d'] === null ? 'money with no accepted introductions' : '$'.number_format($sum['cost_7d'] / 100, 2).' per accepted introduction')
                .', above the $'.number_format($s['stop_loss_cents'] / 100, 2).' stop-loss.';
        } elseif ($s['monthly_budget_cents'] > 0 && $sum['spend_month'] >= $s['monthly_budget_cents']) {
            $reason = 'This month\'s $'.number_format($s['monthly_budget_cents'] / 100).' budget is spent.';
        }
        $status = $reason === null ? 'ok' : 'held';
        $db = DB::connection(ControlDb::control())->table('movewise_ad_settings');
        if (! $db->exists()) {
            return 'ok';
        }
        if ($status === 'held' && $s['status'] !== 'held') {
            $hq = app(Movewise::class)->feedTenant();
            if ($hq !== null) {
                app(TenantContext::class)->runAs($hq, fn () => Notification::send(User::query()->where('kind', UserKind::HqStaff)->get(),
                    new IntroductionClosed('Movewise ads: top-up held', 'Don\'t top up the Movewise ad accounts this week. '.$reason, url('/hq/movewise/money'))));
            }
            app(AuditLogger::class)->record('movewise.ads.held', metadata: ['reason' => $reason]);
        }
        DB::connection(ControlDb::control())->table('movewise_ad_settings')->update(['status' => $status, 'reason' => $reason, 'checked_at' => now()]);

        return $status;
    }
}
