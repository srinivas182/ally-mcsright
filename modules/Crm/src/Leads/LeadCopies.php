<?php

declare(strict_types=1);

namespace Modules\Crm\Leads;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Models\Lead;

/**
 * FX6: nobody misses a new lead.
 * - A lead that goes to the lead pond is emailed to the broker (the fallback person in lead settings, else the
 *   owners), because no agent was told about it.
 * - Owners and staff who ask for it ("Email me a copy of every new lead") get one email per new lead saying where it
 *   went; never a second email when they were already told as the agent, as one of the agents offered it, or as the
 *   broker for the pond.
 */
final class LeadCopies
{
    public function __construct(private readonly TenantContext $tenants) {}

    /** Called once the new lead has been routed. */
    public function send(Lead $lead): void
    {
        $lead->refresh();
        $told = array_map('intval', array_filter([$lead->assigned_user_id, ...($lead->offered_to ?? [])]));
        if ($lead->status === 'pond') {
            $brokers = $this->pondBrokers();
            foreach ($brokers as $broker) {
                $broker->notify($this->alert($lead, 'New lead in the lead pond', 'No agent has it yet. Claim it or give it to an agent.', 'Open the lead'));
            }
            $told = [...$told, ...array_map(fn (User $u): int => $u->id, $brokers)];
        }
        foreach ($this->copyAll() as $user) {
            if (in_array($user->id, $told, true)) {
                continue;
            }
            $where = match (true) {
                $lead->status === 'pond' => 'It is in the lead pond: no agent has it yet.',
                $lead->status === 'offered' => 'It is offered to '.count($lead->offered_to ?? []).' agents: the first to claim it gets it.',
                $lead->assigned_user_id !== null => 'It went to '.(User::query()->find($lead->assigned_user_id)->name ?? 'an agent').'.',
                default => 'It is waiting to be given to an agent.',
            };
            $user->notify($this->alert($lead, 'Copy: new lead', $where, 'See the lead'));
        }
    }

    /** @return list<User> who is told about pond leads */
    public function pondBrokers(): array
    {
        $fallback = app(LeadRouting::class)->settings()['fallback_user_id'];
        $users = $fallback !== null ? User::query()->whereKey($fallback)->where('status', UserStatus::Active)->get() : collect();
        if ($users->isEmpty()) {
            $users = User::query()->where('kind', UserKind::Owner)->where('status', UserStatus::Active)->orderBy('id')->get();
        }

        return array_values($users->all());
    }

    /** @return list<User> owners and staff who asked for a copy of every new lead */
    private function copyAll(): array
    {
        $ids = DB::connection(Connections::cell())->table('crm_lead_alert_prefs')->where('tenant_id', $this->tenants->id())->where('copy_all', true)->pluck('user_id')->all();

        return $ids === [] ? [] : array_values(User::query()->whereIn('id', $ids)->whereIn('kind', [UserKind::Owner, UserKind::Staff])
            ->where('status', UserStatus::Active)->get()->all());
    }

    private function alert(Lead $lead, string $prefix, string $line, string $action): LeadAlert
    {
        $contact = $lead->contact;
        $name = $contact === null ? 'someone' : trim($contact->first_name.' '.mb_substr($contact->last_name, 0, 1).($contact->last_name !== '' ? '.' : ''));

        return new LeadAlert("{$prefix}: {$name}", SpeedToLead::summary($lead).'. '.$line, app(SpeedToLead::class)->link('/leads?lead='.$lead->ulid), $action);
    }
}
