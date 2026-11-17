<?php

declare(strict_types=1);

namespace Modules\Engage\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Engage\CheckInsReadyMail;
use Modules\Engage\DatabaseAssistant;
use Modules\Engage\RetentionAlertMail;
use Modules\Recruiting\Retention;

/** Mondays (EN1): drafts each agent's check-in emails and tells them; sends owners the agents to check in with. */
final class EngageWeekly extends Command
{
    protected $signature = 'engage:weekly';

    protected $description = 'Draft database assistant check-ins and send retention alerts to owners';

    public function handle(TenantContext $tenants): int
    {
        foreach (Tenant::query()->where('status', 'active')->get() as $tenant) {
            $tenants->runAs($tenant, function (): void {
                $assistant = app(DatabaseAssistant::class);
                foreach (User::query()->where('status', UserStatus::Active->value)->whereIn('kind', [UserKind::Agent->value, UserKind::Owner->value])->get() as $u) {
                    if ($assistant->settings($u)['active'] && ($n = $assistant->draftWeek($u)) > 0) {
                        $u->notify(new CheckInsReadyMail($n));
                    }
                }
                $risks = app(Retention::class)->risks();
                if ($risks !== []) {
                    foreach (User::query()->where('kind', UserKind::Owner->value)->where('status', UserStatus::Active->value)->get() as $owner) {
                        $owner->notify(new RetentionAlertMail(array_map(fn (array $r): array => ['name' => (string) $r['name'], 'reasons' => $r['reasons'],
                            'suggestion' => $r['suggestion']], $risks)));
                    }
                }
            });
        }

        return self::SUCCESS;
    }
}
