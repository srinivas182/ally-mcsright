<?php

declare(strict_types=1);

namespace Modules\Recruiting;

use Illuminate\Support\Facades\DB;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Recruiting\Models\Enrollment;
use Modules\Recruiting\Models\Recruit;
use Modules\Transactions\Models\Deal;

/**
 * Recruiting results (RC3, specs/grow/17): for each source (MLS, referral, careers page, met them), how many
 * recruits came in during the year, how many reached a meeting and joined, the commission the people who joined
 * have closed since joining, and the spend the broker entered, with cost per hire.
 */
final class Results
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @return array{year: int, rows: list<array{source: string, label: string, added: int, met: int, joined: int, campaign_helped: int, gci_cents: int, spend_cents: int, cost_per_hire_cents: int|null}>}
     */
    public function forYear(int $year): array
    {
        $from = "{$year}-01-01";
        $to = "{$year}-12-31";
        $recruits = Recruit::query()->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])->get();
        $helped = Enrollment::query()->whereIn('recruit_id', $recruits->pluck('id')->all() ?: [0])->pluck('recruit_id')->unique()->flip();
        $spend = DB::connection(Connections::cell())->table('rec_spend')->where('tenant_id', $this->tenants->id())->where('year', $year)->pluck('amount_cents', 'source');
        $rows = [];
        foreach (Recruit::SOURCES as $source => $label) {
            $mine = $recruits->where('source', $source);
            $joined = $mine->whereNotNull('user_id');
            $gci = 0;
            foreach ($joined as $r) {
                $gci += (int) Deal::query()->where('agent_user_id', $r->user_id)->where('status', 'closed')->where('closed_on', '>=', $r->joined_on?->toDateString() ?? $from)->sum('gci_cents');
            }
            $cost = (int) ($spend[$source] ?? 0);
            $rows[] = ['source' => $source, 'label' => $label, 'added' => $mine->count(),
                'met' => $mine->filter(fn (Recruit $r): bool => in_array($r->stage, ['meeting', 'offer', 'joined'], true) || $r->user_id !== null)->count(),
                'joined' => $joined->count(), 'campaign_helped' => $mine->filter(fn (Recruit $r): bool => $helped->has($r->id))->count(), 'gci_cents' => $gci,
                'spend_cents' => $cost, 'cost_per_hire_cents' => $joined->count() > 0 && $cost > 0 ? intdiv($cost, $joined->count()) : null];
        }

        return ['year' => $year, 'rows' => $rows];
    }

    public function saveSpend(int $year, string $source, int $cents): void
    {
        if (! array_key_exists($source, Recruit::SOURCES) || $year < 2020 || $year > 2100 || $cents < 0) {
            return;
        }
        DB::connection(Connections::cell())->table('rec_spend')->updateOrInsert(['tenant_id' => $this->tenants->id(), 'year' => $year, 'source' => $source],
            ['amount_cents' => $cents, 'updated_at' => now(), 'created_at' => now()]);
        app(AuditLogger::class)->record('recruiting.spend.saved', metadata: ['year' => $year, 'source' => $source, 'cents' => $cents]);
    }
}
