<?php

declare(strict_types=1);

namespace Modules\Listings\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\CrossTenant;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\FeedDelayedNotice;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\StaleFeedAlert;
use Modules\Listings\Sync\ListingSync;

/**
 * Daily removal checks for each MLS, expired licences, and (every run) an alert to HQ when a feed is more than 30
 * minutes behind (at most once an hour per MLS). When a feed is more than an hour behind, the owners of each brokerage
 * licensed on it get a plain-words note too (at most once every six hours; specs/websites/16).
 */
#[CrossTenantAccess('Emails RightAlly HQ staff when an MLS feed falls behind')]
final class ReconcileListings extends Command
{
    protected $signature = 'listings:reconcile {--freshness-only}';

    protected $description = 'Remove listings the MLS no longer shows, expire licences, and alert on stale feeds';

    public function handle(ListingSync $sync, MlsAccess $access): int
    {
        if (! $this->option('freshness-only')) {
            foreach (MlsSource::query()->where('status', 'active')->where('full_sync_done', true)->get() as $source) {
                try {
                    $this->info($source->key.': '.$sync->reconcile($source).' removed');
                } catch (\Throwable $e) {
                    report($e);
                    $this->warn($source->key.': '.$e->getMessage());
                }
            }
            $this->info($access->expire().' licence(s) expired.');
        }

        $hq = CrossTenant::query(User::class, $this)->where('kind', UserKind::HqStaff)->where('status', 'active')->get();
        foreach (MlsSource::query()->where('status', '!=', 'paused')->where('full_sync_done', true)->get() as $source) {
            $behind = $source->minutesBehind();
            if (($behind === null || $behind > 30) && Cache::add('mls-stale-alert:'.$source->id, 1, 3600) && $hq->isNotEmpty()) {
                Notification::send($hq, new StaleFeedAlert($source->name, $behind, $source->last_error));
            }
            if (($behind === null || $behind > 60) && Cache::add('mls-stale-owners:'.$source->id, 1, 6 * 3600)) {
                $this->tellOwners($source, $behind);
            }
        }

        return self::SUCCESS;
    }

    private function tellOwners(MlsSource $source, ?int $behind): void
    {
        $tenants = MlsAuthorization::query()->where('mls_source_id', $source->id)->where('status', 'active')->pluck('tenant_id')->unique()->all();
        if ($tenants === []) {
            return;
        }
        $owners = CrossTenant::query(User::class, $this)->whereIn('tenant_id', $tenants)->where('kind', UserKind::Owner)->where('status', 'active')->get();
        if ($owners->isNotEmpty()) {
            Notification::send($owners, new FeedDelayedNotice($source->name, $behind));
        }
    }
}
