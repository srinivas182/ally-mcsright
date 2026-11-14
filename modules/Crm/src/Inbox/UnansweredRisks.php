<?php

declare(strict_types=1);

namespace Modules\Crm\Inbox;

use App\Models\User;
use Modules\Core\Risk\RiskSource;
use Modules\Crm\Models\Conversation;

/** Broker risk register (protect/01): people who have waited over an hour (waking hours) for a reply. */
final class UnansweredRisks implements RiskSource
{
    public function risks(): array
    {
        $rows = Conversation::query()->whereNotNull('escalated_at')->whereNotNull('needs_reply_since')->with('contact')->get();
        $names = User::query()->whereIn('id', $rows->pluck('assigned_user_id')->filter()->unique())->get()->keyBy('id');
        $out = [];
        foreach ($rows as $c) {
            $out[] = ['level' => 'medium', 'category' => 'Leads', 'title' => "{$c->contact->name} has waited since ".$c->needs_reply_since?->format('m/d g:i a').' for a reply.',
                'deal' => null, 'street' => null, 'owner' => $c->assigned_user_id !== null ? ($names->get($c->assigned_user_id)->name ?? 'Unassigned') : 'Unassigned',
                'due' => $c->needs_reply_since?->format('Y-m-d'), 'link' => '/inbox?c='.$c->ulid];
        }

        return $out;
    }
}
