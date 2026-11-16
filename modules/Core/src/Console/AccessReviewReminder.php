<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Core\Access\AccessReviewDueMail;
use Modules\Core\Access\AccessReviews;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/** Mondays: owners of brokerages whose quarterly access review isn't signed off yet get a reminder (specs/core/03, R1). */
#[CrossTenantAccess('Remind owners of every brokerage about the quarterly access review')]
final class AccessReviewReminder extends Command
{
    protected $signature = 'core:access-review-reminder';

    protected $description = 'Remind owners to sign off this quarter\'s access review';

    public function handle(TenantContext $tenants): int
    {
        $now = CarbonImmutable::now();
        if ($now->firstOfQuarter()->diffInDays($now) < 14) {
            $this->info('Too early in the quarter.');

            return self::SUCCESS;
        }
        $n = 0;
        foreach (Tenant::query()->where('status', 'active')->where('type', '!=', 'hq')->get() as $tenant) {
            try {
                $n += $tenants->runAs($tenant, function (): int {
                    if (! app(AccessReviews::class)->due()) {
                        return 0;
                    }
                    $owners = User::query()->where('kind', UserKind::Owner)->where('status', 'active')->get();
                    foreach ($owners as $owner) {
                        $owner->notify(new AccessReviewDueMail(AccessReviews::quarter()));
                    }

                    return $owners->count();
                });
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $this->info("{$n} reminder(s) sent.");

        return self::SUCCESS;
    }
}
