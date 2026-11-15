<?php

declare(strict_types=1);

namespace Modules\Ads\Console;

use Illuminate\Console\Command;
use Modules\Ads\Social\SocialPosts;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Throwable;

/** Every minute: send scheduled social posts that are due (specs/connect/19). */
#[CrossTenantAccess('Send scheduled social posts for every brokerage')]
final class SendSocialPosts extends Command
{
    protected $signature = 'ads:social-posts';

    protected $description = 'Send scheduled social posts that are due';

    public function handle(TenantContext $tenants): int
    {
        $sent = 0;
        foreach (Tenant::query()->where('status', 'active')->where('type', '!=', 'hq')->get() as $tenant) {
            try {
                $sent += (int) $tenants->runAs($tenant, fn (): int => app(SocialPosts::class)->runDue());
            } catch (Throwable $e) {
                report($e);
            }
        }
        $this->info("{$sent} post(s) sent.");

        return self::SUCCESS;
    }
}
