<?php

declare(strict_types=1);

namespace Modules\Movewise\Growth;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Modules\Ads\Boost;
use Modules\Ads\Google\GoogleAdsApi;
use Modules\Ads\Meta\MetaApi;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Connect\ConnectorKit;
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
            'reason' => $row->reason ?? null, 'checked_at' => $row->checked_at ?? null, 'synced_at' => Cache::get('movewise:ads:synced_at'),
            'marker' => (string) config('movewise.ad_campaign_marker', 'Movewise')];
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

    /**
     * Pulls Movewise's spend from RightAlly's own ad accounts (the Boost accounts, specs/movewise/09): every campaign
     * whose name contains the marker ("Movewise" by default), by day, for the last few days. A two-letter state in the
     * name ("Movewise TX buyers") files the spend under that state. Synced rows replace hand-entered ones for the same
     * day and channel; days the ad accounts don't cover can still be entered by hand.
     *
     * @return array{meta: int|null, google: int|null} rows synced per channel (null: that account isn't connected)
     */
    public function sync(int $days = 3): array
    {
        $boost = app(Boost::class);
        $until = CarbonImmutable::now()->toDateString();
        $since = CarbonImmutable::now()->subDays(max(1, $days) - 1)->toDateString();
        $marker = (string) config('movewise.ad_campaign_marker', 'Movewise');
        $rows = ['meta' => null, 'google' => null];
        if ($boost->ready()) {
            $acc = $boost->account();
            $spend = [];
            $url = MetaApi::GRAPH.'act_'.preg_replace('/\D/', '', $acc['ad_account_id']).'/insights';
            $q = ['level' => 'campaign', 'fields' => 'campaign_name,spend', 'time_increment' => 1, 'limit' => 500,
                'time_range' => json_encode(['since' => $since, 'until' => $until])];
            for ($page = 0; $url !== null && $page < 20; $page++) {
                $r = Http::withToken($acc['access_token'])->acceptJson()->timeout(30)->get($url, $page === 0 ? $q : []);
                if (! $r->successful()) {
                    report(new \RuntimeException('Movewise ads: Meta spend sync failed ('.$r->status().')'));
                    break;
                }
                foreach ((array) $r->json('data', []) as $d) {
                    $this->add($spend, (string) ($d['date_start'] ?? ''), (string) ($d['campaign_name'] ?? ''), (int) round(((float) ($d['spend'] ?? 0)) * 100), $marker);
                }
                $next = $r->json('paging.next');
                $url = is_string($next) ? $next : null;
            }
            $rows['meta'] = $this->store('meta', $spend);
        }
        if ($boost->googleReady()) {
            $acc = $boost->googleAccount();
            $headers = ['developer-token' => (string) (app(ConnectorKit::class)->secrets('google_ads')['developer_token'] ?? '')];
            if ($acc['login_customer_id'] !== '') {
                $headers['login-customer-id'] = $acc['login_customer_id'];
            }
            $r = Http::withToken($boost->googleToken())->acceptJson()->asJson()->timeout(60)->withHeaders($headers)->baseUrl(GoogleAdsApi::base())
                ->post('customers/'.$acc['customer_id'].'/googleAds:search', ['query' => "SELECT segments.date, campaign.name, metrics.cost_micros FROM campaign WHERE segments.date BETWEEN '"
                    .$since."' AND '".$until."' AND campaign.name LIKE '%".str_replace(["'", '%'], '', $marker)."%'"]);
            if ($r->successful()) {
                $spend = [];
                foreach ((array) $r->json('results', []) as $row) {
                    $this->add($spend, (string) ($row['segments']['date'] ?? ''), (string) ($row['campaign']['name'] ?? ''), intdiv((int) ($row['metrics']['costMicros'] ?? 0), 10_000), $marker);
                }
                $rows['google'] = $this->store('google', $spend);
            } else {
                report(new \RuntimeException('Movewise ads: Google spend sync failed ('.$r->status().')'));
            }
        }
        Cache::put('movewise:ads:synced_at', CarbonImmutable::now()->toIso8601String(), CarbonImmutable::now()->addDays(30));

        return $rows;
    }

    /** @param  array<string, int>  $spend */
    private function add(array &$spend, string $day, string $campaign, int $cents, string $marker): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1 || $cents <= 0 || mb_stripos($campaign, $marker) === false) {
            return;
        }
        $state = preg_match('/\b(TX|LA|MS|AL)\b/', $campaign, $m) === 1 ? $m[1] : '';
        $spend[$day.'|'.$state] = ($spend[$day.'|'.$state] ?? 0) + $cents;
    }

    /** @param  array<string, int>  $spend */
    private function store(string $channel, array $spend): int
    {
        foreach ($spend as $key => $cents) {
            [$day, $state] = explode('|', $key);
            DB::connection(ControlDb::control())->table('movewise_ad_spend')->updateOrInsert(['day' => $day, 'channel' => $channel, 'state' => $state !== '' ? $state : null],
                ['spend_cents' => $cents, 'note' => 'Synced from '.($channel === 'meta' ? 'Meta' : 'Google').' Ads', 'by_user_id' => null, 'updated_at' => now(), 'created_at' => now()]);
        }

        return count($spend);
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
