<?php

declare(strict_types=1);

namespace Modules\Sites\Console;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Core\Credits\CreditSpend;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Sites\Ailly\AillyWeek;
use Modules\Sites\Ailly\BrokerageAnswers;

/** Monday: each brokerage's AIlly week for its owners — chats, leads, calls, cost per lead, questions it couldn't answer. */
#[CrossTenantAccess('Sends each brokerage owner their own website assistant results for the week')]
final class AillyWeekly extends Command
{
    protected $signature = 'ailly:weekly';

    protected $description = 'Email owners their AIlly week: results, cost per lead and unanswered questions';

    public function handle(TenantContext $tenants): int
    {
        $from = CarbonImmutable::now()->subDays(7)->startOfDay();
        $ids = DB::connection(Connections::cell())->table('ailly_conversations')->where('created_at', '>=', $from)->distinct()->pluck('tenant_id');
        foreach ($ids as $id) {
            $tenant = Tenant::query()->whereKey((int) $id)->first();
            if ($tenant === null) {
                continue;
            }
            $tenants->runAs($tenant, function () use ($from): void {
                $db = fn () => DB::connection(Connections::cell())->table('ailly_conversations')->where('created_at', '>=', $from);
                $leads = $db()->where('outcome', '!=', 'none')->count();
                $spent = app(CreditSpend::class)->spent('ai.ailly', $from, CarbonImmutable::now());
                $owners = User::query()->where('kind', UserKind::Owner)->get()->filter(fn (User $u): bool => $u->isActive());
                Notification::send($owners, new AillyWeek($db()->count(), $leads, $db()->where('outcome', 'call')->count(),
                    $leads > 0 ? (int) round($spent['microcents'] / 1_000_000 / $leads) : null, array_column(app(BrokerageAnswers::class)->openQuestions(5), 'question')));
            });
        }
        $this->info($ids->count().' brokerage(s).');

        return self::SUCCESS;
    }
}
