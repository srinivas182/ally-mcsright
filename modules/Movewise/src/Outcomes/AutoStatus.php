<?php

declare(strict_types=1);

namespace Modules\Movewise\Outcomes;

use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections as ControlDb;
use Modules\Crm\Events\ActivityLogged;
use Modules\Crm\Events\ContactStageChanged;
use Modules\Crm\Events\TaskCompleted;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Stage;
use Modules\Crm\Models\Task;
use Modules\Movewise\Models\Connection;
use Modules\Transactions\Events\DealStatusChanged;
use Modules\Transactions\Models\Deal;

/**
 * The status of a Movewise introduction follows what the agent already does in RightAlly (specs/movewise/09), so they
 * don't report it twice:
 *
 * - a two-way conversation (their reply, or a call that connected) → talked with them;
 * - a showing completed, or a meeting logged → appointment held;
 * - a deal for them → agreement signed; under contract → under contract; closed → closed, with the deal's price,
 *   commission and date (this also raises the referral invoice for share at close);
 * - moved to a "lost" stage in a pipeline → not going ahead.
 *
 * Deals synced from dotloop, SkySlope, Lone Wolf and the other connected apps count the same. Status only moves
 * forward; the agent can still update it by hand.
 */
final class AutoStatus
{
    public function __construct(private readonly Outcomes $outcomes) {}

    public function deal(DealStatusChanged $e): void
    {
        $deal = Deal::query()->find($e->dealId);
        if ($deal === null) {
            return;
        }
        $contacts = array_values(array_unique(array_filter([$deal->contact_id, ...DB::connection(ControlDb::cell())->table('tx_parties')
            ->where('deal_id', $deal->id)->whereNotNull('contact_id')->pluck('contact_id')->map(fn ($i): int => (int) $i)->all()])));
        $stage = match ($e->to) {
            'pre_contract' => 'agreement',
            'under_contract' => 'contract',
            'closed' => 'closed',
            default => null, // a deal falling through doesn't end the client relationship
        };
        if ($stage === null || $contacts === []) {
            return;
        }
        foreach ($this->live($e->tenantId, $contacts) as $c) {
            $this->outcomes->advance($c, $stage, 'the deal at '.$deal->street, $stage === 'closed' ? ['close_price_cents' => $deal->price_cents,
                'commission_cents' => $deal->gci_cents, 'close_date' => $deal->closed_on?->toDateString() ?? now()->toDateString()] : []);
        }
    }

    public function activity(ActivityLogged $e): void
    {
        $a = Activity::query()->find($e->activityId);
        if ($a === null) {
            return;
        }
        $stage = match (true) {
            $a->type === 'meeting' => 'met',
            in_array($a->type, ['text', 'email'], true) && $a->direction === 'in' => 'contacted',
            $a->type === 'call' && $a->outcome === 'connected' => 'contacted',
            default => null,
        };
        if ($stage !== null) {
            foreach ($this->live($e->tenantId, [$e->contactId]) as $c) {
                // Their first reply only counts once the agent has reached out (their message before acceptance doesn't).
                $this->outcomes->advance($c, $stage, $stage === 'met' ? 'a meeting logged' : 'a conversation with them', []);
            }
        }
    }

    public function task(TaskCompleted $e): void
    {
        $t = Task::query()->find($e->taskId);
        if ($t !== null && $t->contact_id !== null && $t->type === 'showing') {
            foreach ($this->live($e->tenantId, [$t->contact_id]) as $c) {
                $this->outcomes->advance($c, 'met', 'a showing completed', []);
            }
        }
    }

    public function stage(ContactStageChanged $e): void
    {
        if (Stage::query()->whereKey($e->stageId)->value('outcome') === 'lost') {
            foreach ($this->live($e->tenantId, [$e->contactId]) as $c) {
                $this->outcomes->advance($c, 'lost', 'moved to a lost stage', []);
            }
        }
    }

    /**
     * @param  list<int>  $contacts
     * @return iterable<Connection>
     */
    private function live(int $tenantId, array $contacts): iterable
    {
        return Connection::query()->where('tenant_id', $tenantId)->whereIn('contact_id', $contacts)->where('status', 'accepted')->get();
    }
}
