<?php

declare(strict_types=1);

namespace Modules\Listings\Idx;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Throwable;

/**
 * SU2: the MLS disclosure every website shows in its footer, in every design: one block per MLS board that is live
 * for this brokerage (the board's name, its logo and its disclaimer from HQ's display rules). It is not part of the
 * website's own content, so the brokerage cannot change or remove it. Before any board is live, the footer says the
 * listings shown are samples.
 */
final class FooterDisclosures
{
    /**
     * @return array{boards: list<array{name: string, logo: string|null, disclaimer: string}>, sample: bool}
     */
    public static function for(int $tenantId): array
    {
        return Cache::remember("idx-footer:{$tenantId}", 600, function () use ($tenantId): array {
            try {
                $control = DB::connection(Connections::control());
                $live = $control->table('idx_subscriptions')->join('idx_boards', 'idx_boards.id', '=', 'idx_subscriptions.board_id')
                    ->where('idx_subscriptions.tenant_id', $tenantId)->where('idx_subscriptions.status', 'live')
                    ->orderBy('idx_boards.name')->get(['idx_boards.name', 'idx_boards.rules_profile_id']);
                $profiles = DB::connection(Connections::listings())->table('mls_rules_profiles')->whereIn('id', $live->pluck('rules_profile_id')->filter()->all())->get()->keyBy('id');
                $boards = [];
                foreach ($live as $b) {
                    $p = $profiles[$b->rules_profile_id] ?? null;
                    $text = $p !== null ? trim((string) $p->disclaimer) : '';
                    $boards[] = ['name' => (string) $b->name, 'logo' => $p !== null && is_string($p->logo_url) && str_starts_with($p->logo_url, 'https://') ? $p->logo_url : null,
                        'disclaimer' => $text !== '' ? $text
                            : 'Listing information is provided by '.$b->name.' for consumers\' personal, non-commercial use and may not be used for any purpose other than to identify prospective properties consumers may be interested in purchasing. Information is deemed reliable but not guaranteed.'];
                }

                return ['boards' => $boards, 'sample' => $boards === []];
            } catch (Throwable) {
                return ['boards' => [], 'sample' => true]; // before the IDX tables exist
            }
        });
    }

    /** Called when a board goes live or stops for a brokerage, so its footer changes at once. */
    public static function forget(int $tenantId): void
    {
        Cache::forget("idx-footer:{$tenantId}");
    }
}
