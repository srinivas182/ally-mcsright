<?php

declare(strict_types=1);

namespace Modules\Listings\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\CrossTenant;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Listings\Idx\Mail\IdxUpdateMail;
use Modules\Listings\Idx\Subscriptions;

/**
 * IDX4: reminds brokerages (and their managing broker) to sign an MLS agreement, 1 and 3 days after it is ready.
 * IDX13: also tells RightAlly HQ, once a week, about boards the MLS has had for longer than its usual approval time,
 * since neither the MLS nor the feed provider reports an approval back on its own.
 */
#[CrossTenantAccess('Emails RightAlly HQ staff about MLS boards waiting longer than usual for approval')]
final class RemindIdxSignatures extends Command
{
    protected $signature = 'idx:remind';

    protected $description = 'Remind brokerages to sign their MLS agreements, and HQ about MLS approvals taking longer than usual';

    public function handle(Subscriptions $subscriptions): int
    {
        $this->info($subscriptions->remind().' reminder(s) sent.');

        $due = array_values(array_filter($subscriptions->overdue(), fn (array $o): bool => Cache::add('idx-overdue:'.$o['ulid'], 1, now()->addDays(7))));
        $hq = CrossTenant::query(User::class, $this)->where('kind', UserKind::HqStaff)->where('status', 'active')->pluck('email')->filter()->values()->all();
        if ($due !== [] && $hq !== []) {
            $names = Tenant::query()->whereIn('id', array_column($due, 'tenant_id'))->pluck('name', 'id');
            $lines = array_map(fn (array $o): string => ($names[$o['tenant_id']] ?? 'A brokerage').' · '.$o['board'].': with the MLS for '.$o['days'].' business days.', $due);
            Mail::to($hq)->queue(new IdxUpdateMail('MLS approvals taking longer than usual', [...$lines,
                'Check with the MLS (or Repliers) for each one. When it is approved, open the board in HQ › IDX coverage and press "Go live".'],
                'Open IDX coverage', 'https://'.config('core.hq_host').'/hq/idx'));
        }
        $this->info(count($due).' slow approval(s) flagged to HQ.');

        return self::SUCCESS;
    }
}
