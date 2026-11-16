<?php

declare(strict_types=1);

namespace Modules\Insights;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;

/** Yearly goals for the brokerage (user null) and each agent (specs/insights/01, K1). */
final class Goals
{
    /**
     * @return array{gci_cents: int|null, sides: int|null, volume_cents: int|null}
     */
    public static function for(?User $user, int $year): array
    {
        $row = DB::connection(Connections::cell())->table('insights_goals')->where('year', $year)
            ->when($user === null, fn ($q) => $q->whereNull('user_id'), fn ($q) => $q->where('user_id', $user?->id))->first();

        return ['gci_cents' => $row?->gci_cents !== null ? (int) $row->gci_cents : null, 'sides' => $row?->sides !== null ? (int) $row->sides : null,
            'volume_cents' => $row?->volume_cents !== null ? (int) $row->volume_cents : null];
    }

    public static function save(?User $for, int $year, ?int $gciCents, ?int $sides, ?int $volumeCents, User $by): void
    {
        DB::connection(Connections::cell())->table('insights_goals')->updateOrInsert(['tenant_id' => $by->tenant_id, 'user_id' => $for?->id, 'year' => $year], [
            'gci_cents' => $gciCents !== null ? max(0, $gciCents) : null, 'sides' => $sides !== null ? max(0, $sides) : null,
            'volume_cents' => $volumeCents !== null ? max(0, $volumeCents) : null, 'updated_by' => $by->id, 'updated_at' => CarbonImmutable::now(), 'created_at' => CarbonImmutable::now()]);
        app(AuditLogger::class)->record('insights.goal.saved', $by, metadata: ['for' => $for->name ?? 'brokerage', 'year' => $year, 'gci_cents' => $gciCents, 'sides' => $sides], label: (string) $year);
    }
}
