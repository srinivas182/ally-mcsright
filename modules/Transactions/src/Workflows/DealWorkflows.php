<?php

declare(strict_types=1);

namespace Modules\Transactions\Workflows;

use App\Models\User;
use Modules\Core\Workflows\Subject;
use Modules\Core\Workflows\WorkflowRegistry;
use Modules\Core\Workflows\Workflows;
use Modules\Transactions\Events\DealStatusChanged;
use Modules\Transactions\Models\Deal;

/** Deals in the workflow engine (specs/core/06, W1). */
final class DealWorkflows
{
    private const TRIGGERS = ['pre_contract' => 'deal.created', 'under_contract' => 'deal.under_contract', 'closed' => 'deal.closed', 'fell_through' => 'deal.fell_through'];

    public static function register(WorkflowRegistry $registry): void
    {
        $registry->subject('deal', 'deal', function (int $id): ?Subject {
            $d = Deal::query()->find($id);

            return $d instanceof Deal ? new Subject('deal', $d->id, $d, (string) $d->street, $d->agent_user_id) : null;
        });
        $facts = ['type' => 'Deal type (buyer, listing, lease…)', 'price' => 'Price ($)', 'state' => 'State (e.g. TX)', 'city' => 'City', 'agent' => 'The agent\'s name', 'imported' => 'Synced from another app (yes or no)'];
        $registry->trigger('deal.created', 'A deal is started', 'deal', $facts);
        $registry->trigger('deal.under_contract', 'A deal goes under contract', 'deal', $facts);
        $registry->trigger('deal.closed', 'A deal closes', 'deal', $facts);
        $registry->trigger('deal.fell_through', 'A deal falls through', 'deal', $facts);
        $registry->action(new AddChecklistItem);
    }

    public static function statusChanged(DealStatusChanged $e): void
    {
        $trigger = self::TRIGGERS[$e->to] ?? null;
        $deal = Deal::query()->find($e->dealId);
        if ($trigger === null || ! $deal instanceof Deal || ($e->from === null && $e->to !== 'pre_contract')) {
            return;
        }
        $by = auth()->user();
        app(Workflows::class)->fire($trigger, $deal->id, [
            'type' => (string) $deal->type, 'price' => round($deal->price_cents / 100, 2), 'state' => (string) $deal->state_code,
            'city' => (string) $deal->city, 'agent' => (string) User::query()->whereKey($deal->agent_user_id)->value('first_name'), 'imported' => $deal->imported_from !== null ? 'yes' : 'no',
        ], $by instanceof User ? $by : null);
    }
}
