<?php

declare(strict_types=1);

namespace Modules\Transactions\Reports;

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Transactions\Models\Deal;

/**
 * The listing inventory board (LM1, specs/transactions/30): every listing the brokerage holds, in four columns,
 * Getting ready (no MLS number yet), On the market, Under contract and Sold (last 30 days), with days on the market and
 * a warning when the listing agreement ends within 30 days. Agents see their own; reviewers see the whole brokerage.
 */
final class ListingBoard
{
    public const COLUMNS = ['getting_ready' => 'Getting ready', 'active' => 'On the market', 'under_contract' => 'Under contract', 'sold' => 'Sold (last 30 days)'];

    /**
     * @return array{columns: array<string, list<array{ulid: string, street: string, city: string|null, price_cents: int, agent: string, days: int|null, expires_on: string|null, expires_in: int|null, closing_date: string|null, warning: string|null}>>, totals: array<string, array{count: int, volume_cents: int}>, expiring: int}
     */
    public function build(?User $only = null, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $deals = Deal::query()->whereIn('type', ['listing', 'dual'])
            ->where(fn ($q) => $q->whereIn('status', ['pre_contract', 'under_contract'])->orWhere(fn ($c) => $c->where('status', 'closed')->where('closed_on', '>=', $today->subDays(30)->toDateString())))
            ->when($only !== null, fn ($q) => $q->where('agent_user_id', $only?->id))
            ->orderBy('closing_date')->orderByDesc('id')->limit(1000)->get();
        $names = User::query()->whereIn('id', $deals->pluck('agent_user_id')->unique()->all() ?: [0])->get()->keyBy('id');
        $columns = array_fill_keys(array_keys(self::COLUMNS), []);
        $expiring = 0;
        foreach ($deals as $d) {
            $column = match (true) {
                $d->status === 'closed' => 'sold',
                $d->status === 'under_contract' => 'under_contract',
                ($d->mls_number ?? '') === '' => 'getting_ready',
                default => 'active',
            };
            $expires = $d->getAttribute('listing_expires_on');
            $expiresOn = $expires !== null ? CarbonImmutable::parse((string) $expires) : null;
            $expiresIn = $expiresOn !== null ? (int) $today->diffInDays($expiresOn, false) : null;
            $warning = null;
            if ($column === 'active' || $column === 'getting_ready') {
                if ($expiresIn !== null && $expiresIn < 0) {
                    $warning = 'Listing agreement ended '.abs($expiresIn).' days ago';
                } elseif ($expiresIn !== null && $expiresIn <= 30) {
                    $warning = 'Listing agreement ends in '.$expiresIn.' '.($expiresIn === 1 ? 'day' : 'days');
                }
                if ($warning !== null) {
                    $expiring++;
                }
            }
            $start = $column === 'under_contract' ? $d->contract_date : ($d->getAttribute('created_at') instanceof \DateTimeInterface ? CarbonImmutable::instance($d->getAttribute('created_at')) : null);
            $columns[$column][] = ['ulid' => $d->ulid, 'street' => $d->street, 'city' => $d->city, 'price_cents' => $d->price_cents,
                'agent' => (string) ($names->get($d->agent_user_id)->name ?? 'Agent'), 'days' => $start !== null && $column !== 'sold' ? (int) $start->diffInDays($today) : null,
                'expires_on' => $expiresOn?->toDateString(), 'expires_in' => $expiresIn, 'closing_date' => $d->closing_date?->toDateString(), 'warning' => $warning];
        }
        $totals = [];
        foreach ($columns as $k => $rows) {
            $totals[$k] = ['count' => count($rows), 'volume_cents' => array_sum(array_column($rows, 'price_cents'))];
        }

        return ['columns' => $columns, 'totals' => $totals, 'expiring' => $expiring];
    }
}
